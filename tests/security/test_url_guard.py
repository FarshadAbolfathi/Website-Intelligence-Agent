import pytest

from core.crawler.crawler import HttpFetcher
from core.security.url_guard import UnsafeURLError, URLGuard, is_public_ip

PUBLIC = "93.184.216.34"


def guard(addr=PUBLIC):
    return URLGuard(resolver=lambda host: [addr])


@pytest.mark.parametrize(
    "url",
    [
        "file:///etc/passwd", "gopher://example.com", "ftp://example.com/", "javascript:alert(1)", "//example.com",
        "http://user:pw@example.com/", "http://example.com:22/", "http://example.com:6379/", "http://localhost/",
        "http://foo.localhost/", "http://service.internal/", "http://printer.local/", "http://exa mple.com/",
        "http://example.com\\@evil.test/", "http://example.com/\n", "", "http://" + "a" * 3000,
    ],
)
def test_rejected_before_any_dns(url):
    with pytest.raises(UnsafeURLError):
        guard().check(url)


@pytest.mark.parametrize(
    "addr",
    [
        "127.0.0.1", "127.1.2.3", "10.0.0.1", "172.16.0.1", "192.168.1.1", "169.254.169.254", "0.0.0.0", "100.64.0.1",
        "224.0.0.1", "240.0.0.1", "::1", "fe80::1", "fc00::1", "::ffff:127.0.0.1", "::ffff:169.254.169.254",
        "64:ff9b::7f00:1", "2002:7f00:1::", "::",
    ],
)
def test_private_addresses_blocked_whatever_the_host_is_called(addr):
    assert not is_public_ip(addr)
    with pytest.raises(UnsafeURLError):
        guard(addr).check("https://innocent-looking.example.com/")


def test_one_private_answer_among_public_ones_is_rejected():
    g = URLGuard(resolver=lambda host: [PUBLIC, "10.0.0.5"])
    with pytest.raises(UnsafeURLError):
        g.check("https://example.com/")


@pytest.mark.parametrize("url", ["http://2130706433/", "http://0x7f.0.0.1/", "http://127.1/", "http://[::1]/", "http://[::ffff:7f00:1]/"])
def test_alternate_ip_spellings_blocked_with_real_resolver(url):
    with pytest.raises(UnsafeURLError):
        URLGuard().check(url)


def test_public_url_allowed():
    assert guard().check("https://example.com/page?x=1").addresses == (PUBLIC,)
    assert is_public_ip(PUBLIC)


def test_redirect_to_internal_address_is_refused(sites):
    site = sites["en"]
    site.extra_responses["/go"] = (302, {"Location": "http://169.254.169.254/latest/meta-data/"}, b"")
    fetcher = HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"})))
    result = fetcher.fetch(site.url + "/go")
    assert result.status == 0 and "blocked" in result.error


def test_real_loopback_server_is_unreachable_without_explicit_allowance(sites):
    result = HttpFetcher(URLGuard(allowed_ports=frozenset(range(1, 65536)))).fetch(sites["en"].url + "/")
    assert result.status == 0 and "blocked" in result.error
    assert "/" not in sites["en"].hits


def test_oversized_response_is_cut_off(sites):
    site = sites["en"]
    site.pages["/big"] = "x" * 2_000_000
    fetcher = HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"})), max_bytes=1000)
    result = fetcher.fetch(site.url + "/big")
    assert result.error == "response too large" and not result.body


def test_dns_rebinding_connects_only_to_the_validated_address(monkeypatch, sites):
    """The name resolves to a safe address at check time and the connection must use exactly that address."""
    site = sites["en"]
    port = site.server.server_port
    calls = []
    real_resolver = lambda host: calls.append(host) or ["127.0.0.1"]
    g = URLGuard(allow_hosts=frozenset({"rebind.example"}), resolver=real_resolver)
    result = HttpFetcher(g).fetch(f"http://rebind.example:{port}/")
    assert result.status == 200, result.error  # reached the pinned 127.0.0.1 without any DNS lookup of its own
    assert calls == ["rebind.example"]
