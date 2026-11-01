"""SSRF protection for every outbound request the crawler makes.

A URL is allowed only if its scheme, port and *every* address its host resolves to are public. Redirects are
validated hop by hop by the fetcher, and the resolved address is pinned for the connection, so a DNS answer that
changes between the check and the request (DNS rebinding) cannot reach an internal service.
"""

from __future__ import annotations

import ipaddress
import socket
from dataclasses import dataclass, field
from urllib.parse import urlsplit

ALLOWED_SCHEMES = frozenset({"http", "https"})
ALLOWED_PORTS = frozenset({80, 443, 8080, 8443})
BLOCKED_SUFFIXES = (".local", ".localhost", ".internal", ".intranet", ".lan", ".home", ".corp", ".consul")
BLOCKED_HOSTS = frozenset({"localhost", "metadata.google.internal", "metadata", "instance-data"})
MAX_URL_LENGTH = 2048


class UnsafeURLError(ValueError):
    """The URL points somewhere the crawler must never go."""


def is_public_ip(raw: str) -> bool:
    ip = ipaddress.ip_address(raw.split("%", 1)[0])
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped:
        ip = ip.ipv4_mapped
    if isinstance(ip, ipaddress.IPv6Address) and ip.sixtofour:
        ip = ip.sixtofour
    if isinstance(ip, ipaddress.IPv6Address) and ip.teredo:
        ip = ip.teredo[1]
    # NAT64 embeds an IPv4 address in the last 32 bits
    if isinstance(ip, ipaddress.IPv6Address) and ip in ipaddress.ip_network("64:ff9b::/96"):
        ip = ipaddress.IPv4Address(int(ip) & 0xFFFFFFFF)
    if isinstance(ip, ipaddress.IPv4Address) and ip in ipaddress.ip_network("100.64.0.0/10"):
        return False  # carrier-grade NAT
    return not (
        ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_multicast
        or ip.is_reserved or ip.is_unspecified or not ip.is_global
    )


def _resolve(host: str) -> list[str]:
    try:
        infos = socket.getaddrinfo(host, None, type=socket.SOCK_STREAM)
    except (socket.gaierror, UnicodeError) as exc:
        raise UnsafeURLError(f"host does not resolve: {host}") from exc
    return sorted({str(i[4][0]) for i in infos})


@dataclass(frozen=True)
class SafeTarget:
    url: str
    scheme: str
    host: str
    port: int
    addresses: tuple[str, ...]


@dataclass
class URLGuard:
    """`allow_hosts` exists for local development and tests. It is empty in production unless the owner sets it."""

    allow_hosts: frozenset[str] = field(default_factory=frozenset)
    allowed_ports: frozenset[int] = ALLOWED_PORTS
    resolver: object = None

    def check(self, url: str) -> SafeTarget:
        if not url or len(url) > MAX_URL_LENGTH:
            raise UnsafeURLError("empty or oversized URL")
        if any(ord(c) < 0x20 or c in "\\ " for c in url):
            raise UnsafeURLError("URL contains control characters, spaces or backslashes")
        try:
            parts = urlsplit(url)
            port = parts.port
        except ValueError as exc:
            raise UnsafeURLError(f"malformed URL: {exc}") from exc
        scheme = parts.scheme.lower()
        if scheme not in ALLOWED_SCHEMES:
            raise UnsafeURLError(f"scheme not allowed: {scheme or '(none)'}")
        if parts.username or parts.password:
            raise UnsafeURLError("credentials in URLs are not allowed")
        host = (parts.hostname or "").rstrip(".").lower()
        if not host:
            raise UnsafeURLError("URL has no host")
        port = port or (443 if scheme == "https" else 80)

        if host in self.allow_hosts:
            resolver = self.resolver or _resolve
            return SafeTarget(url, scheme, host, port, tuple(resolver(host)))

        if port not in self.allowed_ports:
            raise UnsafeURLError(f"port not allowed: {port}")
        if host in BLOCKED_HOSTS or host.endswith(BLOCKED_SUFFIXES):
            raise UnsafeURLError(f"internal host blocked: {host}")

        # Decimal (2130706433), hex (0x7f.1) and short (127.1) IPv4 spellings are normalised by the resolver,
        # so they reach the same address check below instead of slipping past a literal comparison.
        resolver = self.resolver or _resolve
        addresses = tuple(resolver(host))
        if not addresses:
            raise UnsafeURLError(f"host does not resolve: {host}")
        for addr in addresses:
            if not is_public_ip(addr):
                raise UnsafeURLError(f"{host} resolves to a non-public address")
        return SafeTarget(url, scheme, host, port, addresses)
