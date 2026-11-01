"""Polite, incremental website crawler.

Robots rules are always honoured. URLs come from robots.txt sitemaps, /sitemap.xml and internal links. Conditional
requests and content hashes (handled by the ingest layer) mean only changed pages are reprocessed.
Fetching sits behind a `Fetcher` protocol: `HttpFetcher` is the default and `PlaywrightFetcher` handles
JavaScript-heavy sites on the self-hosted edition.
"""

from __future__ import annotations

import gzip
import time
from collections import deque
from collections.abc import Callable, Iterable
from dataclasses import dataclass, field
from typing import Protocol
from urllib.parse import urljoin, urlsplit
from urllib.robotparser import RobotFileParser

import httpcore
import httpx
from lxml import etree

from core.extractor.html import ExtractedPage, canonicalize_url, extract
from core.security.url_guard import SafeTarget, UnsafeURLError, URLGuard

USER_AGENT = "WebsiteIntelligenceAgent/0.1 (+self-hosted site assistant)"
HTML_TYPES = ("text/html", "application/xhtml+xml")
SKIP_EXTENSIONS = (
    ".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg", ".ico", ".pdf", ".zip", ".rar", ".gz", ".tar", ".mp3", ".mp4",
    ".avi", ".mov", ".webm", ".css", ".js", ".json", ".xml", ".woff", ".woff2", ".ttf", ".doc", ".docx", ".xls", ".xlsx",
)


@dataclass
class FetchResult:
    url: str
    status: int
    body: bytes = b""
    content_type: str = ""
    etag: str = ""
    last_modified: str = ""
    error: str = ""


class Fetcher(Protocol):
    def fetch(self, url: str, *, etag: str = "", last_modified: str = "") -> FetchResult: ...


class _PinnedBackend(httpcore.NetworkBackend):
    """Connects only to the address that was validated, which closes the DNS-rebinding window."""

    def __init__(self, target: SafeTarget) -> None:
        self._target = target
        self._backend = httpcore.SyncBackend()

    def connect_tcp(self, host, port, timeout=None, local_address=None, socket_options=None):
        if isinstance(host, bytes):
            host = host.decode()
        address = self._target.addresses[0] if host.lower().rstrip(".") == self._target.host else None
        if address is None:
            raise httpcore.ConnectError(f"unvalidated host: {host}")
        return self._backend.connect_tcp(address, port, timeout, local_address, socket_options)


class HttpFetcher:
    """httpx fetcher with SSRF re-validation on every redirect hop and a hard response size limit."""

    def __init__(
        self, guard: URLGuard, *, timeout: float = 15.0, max_bytes: int = 5_000_000, max_redirects: int = 5,
        user_agent: str = USER_AGENT,
    ) -> None:
        self.guard = guard
        self.timeout = timeout
        self.max_bytes = max_bytes
        self.max_redirects = max_redirects
        self.user_agent = user_agent

    def _transport(self, target: SafeTarget) -> httpx.HTTPTransport:
        transport = httpx.HTTPTransport(retries=0)
        transport._pool = httpcore.ConnectionPool(  # the public API has no custom network backend
            network_backend=_PinnedBackend(target), ssl_context=httpx.create_ssl_context(),
        )
        return transport

    def fetch(self, url: str, *, etag: str = "", last_modified: str = "") -> FetchResult:
        headers = {"User-Agent": self.user_agent, "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5"}
        if etag:
            headers["If-None-Match"] = etag
        if last_modified:
            headers["If-Modified-Since"] = last_modified
        current = url
        for _ in range(self.max_redirects + 1):
            try:
                target = self.guard.check(current)
            except UnsafeURLError as exc:
                return FetchResult(url=current, status=0, error=f"blocked: {exc}")
            try:
                with (
                    httpx.Client(transport=self._transport(target), timeout=self.timeout, follow_redirects=False) as client,
                    client.stream("GET", current, headers=headers) as response,
                ):
                    if response.status_code in (301, 302, 303, 307, 308):
                        location = response.headers.get("location", "")
                        if not location:
                            return FetchResult(url=current, status=response.status_code, error="redirect without location")
                        current = urljoin(current, location)
                        continue
                    declared = response.headers.get("content-length", "0")
                    if declared.isdigit() and int(declared) > self.max_bytes:
                        return FetchResult(url=current, status=response.status_code, error="response too large")
                    chunks: list[bytes] = []
                    size = 0
                    if response.status_code != 304:
                        for chunk in response.iter_bytes():
                            size += len(chunk)
                            if size > self.max_bytes:
                                return FetchResult(url=current, status=response.status_code, error="response too large")
                            chunks.append(chunk)
                    return FetchResult(
                        url=current, status=response.status_code, body=b"".join(chunks),
                        content_type=response.headers.get("content-type", "").split(";")[0].strip().lower(),
                        etag=response.headers.get("etag", ""), last_modified=response.headers.get("last-modified", ""),
                    )
            except httpx.HTTPError as exc:
                return FetchResult(url=current, status=0, error=f"{type(exc).__name__}: {exc}")
        return FetchResult(url=current, status=0, error="too many redirects")


class PlaywrightFetcher:
    """Renders JavaScript-heavy pages. Optional: install with `pip install .[js]` and `playwright install chromium`.

    Every browser request, including subresources, passes through the URL guard, because rendered pages can
    otherwise be used to reach internal addresses.
    """

    def __init__(self, guard: URLGuard, *, timeout_ms: int = 20_000, user_agent: str = USER_AGENT) -> None:
        from playwright.sync_api import sync_playwright

        self.guard = guard
        self.timeout_ms = timeout_ms
        self._pw = sync_playwright().start()
        self._browser = self._pw.chromium.launch(headless=True)
        self._context = self._browser.new_context(user_agent=user_agent)

    def _route(self, route) -> None:
        try:
            self.guard.check(route.request.url)
        except UnsafeURLError:
            route.abort()
            return
        if route.request.resource_type in ("image", "media", "font"):
            route.abort()
            return
        route.continue_()

    def fetch(self, url: str, *, etag: str = "", last_modified: str = "") -> FetchResult:
        try:
            self.guard.check(url)
        except UnsafeURLError as exc:
            return FetchResult(url=url, status=0, error=f"blocked: {exc}")
        page = self._context.new_page()
        try:
            page.route("**/*", self._route)
            response = page.goto(url, wait_until="networkidle", timeout=self.timeout_ms)
            if response is None:
                return FetchResult(url=url, status=0, error="no response")
            return FetchResult(url=page.url, status=response.status, body=page.content().encode(), content_type="text/html")
        except Exception as exc:  # noqa: BLE001 - Playwright raises many error types; each is one failed page
            return FetchResult(url=url, status=0, error=f"{type(exc).__name__}: {exc}")
        finally:
            page.close()

    def close(self) -> None:
        self._browser.close()
        self._pw.stop()


@dataclass
class CrawlConfig:
    max_pages: int = 500
    max_depth: int = 8
    delay_seconds: float = 1.0
    include_paths: tuple[str, ...] = ()
    exclude_paths: tuple[str, ...] = ("/wp-admin", "/cart", "/checkout", "/my-account", "/login", "/account", "/search")
    use_sitemap: bool = True
    time_budget_seconds: float = 3600.0


@dataclass
class PageOutcome:
    url: str
    status: str  # fetched | not_modified | gone | error | robots_blocked | noindex | skipped
    page: ExtractedPage | None = None
    http_status: int = 0
    etag: str = ""
    last_modified: str = ""
    error: str = ""


@dataclass
class CrawlReport:
    discovered: int = 0
    fetched: int = 0
    not_modified: int = 0
    gone: int = 0
    errors: int = 0
    robots_blocked: int = 0
    sitemap_urls: int = 0
    stopped_early: str = ""
    links: dict[str, list[str]] = field(default_factory=dict)

    def as_dict(self) -> dict:
        d = {k: v for k, v in self.__dict__.items() if k != "links"}
        d["link_edges"] = sum(len(v) for v in self.links.values())
        return d


def _parse_sitemap(body: bytes) -> tuple[list[str], list[str]]:
    """Return (page URLs, nested sitemap URLs). The parser is hardened against XXE and entity expansion."""
    if body[:2] == b"\x1f\x8b":
        try:
            body = gzip.decompress(body)[:20_000_000]
        except OSError:
            return [], []
    parser = etree.XMLParser(resolve_entities=False, no_network=True, huge_tree=False, recover=True)
    try:
        root = etree.fromstring(body, parser=parser)
    except etree.XMLSyntaxError:
        return [], []
    if root is None:
        return [], []
    pages, nested = [], []
    for el in root.iter():
        if not isinstance(el.tag, str) or etree.QName(el).localname != "loc" or not el.text:
            continue
        parent = etree.QName(el.getparent()).localname if el.getparent() is not None else ""
        (nested if parent == "sitemap" else pages).append(el.text.strip())
    return pages, nested


class Crawler:
    def __init__(self, fetcher: Fetcher, config: CrawlConfig | None = None, *, sleep: Callable[[float], None] = time.sleep) -> None:
        self.fetcher = fetcher
        self.config = config or CrawlConfig()
        self._sleep = sleep
        self._last_request = 0.0
        self._delay = self.config.delay_seconds

    def _polite_fetch(self, url: str, **kw) -> FetchResult:
        wait = self._delay - (time.monotonic() - self._last_request)
        if wait > 0:
            self._sleep(wait)
        result = self.fetcher.fetch(url, **kw)
        self._last_request = time.monotonic()
        if result.status in (429, 503):  # the site asked us to slow down
            self._delay = min(max(self._delay * 2, 1.0), 60.0)
        return result

    def _robots(self, base: str) -> RobotFileParser:
        parser = RobotFileParser()
        result = self._polite_fetch(urljoin(base, "/robots.txt"))
        if result.status == 200:
            parser.parse(result.body.decode("utf-8", "ignore").splitlines())
        elif result.status in (401, 403):
            parser.disallow_all = True  # RFC 9309: an access-restricted robots.txt means do not crawl
        elif 500 <= result.status < 600 or (result.status == 0 and "blocked" not in result.error):
            parser.disallow_all = True  # robots state unknown: fail closed
        else:
            parser.allow_all = True
        delay = None
        try:
            delay = parser.crawl_delay(USER_AGENT)
        except (AttributeError, TypeError):
            pass
        if delay:
            self._delay = max(self._delay, float(delay))
        return parser

    def _same_site(self, base: str, url: str) -> bool:
        def host(u: str) -> str:
            netloc = urlsplit(u).netloc.lower()
            return netloc[4:] if netloc.startswith("www.") else netloc

        return host(base) == host(url)

    def _wanted(self, url: str) -> bool:
        parts = urlsplit(url)
        path = parts.path.lower()
        if path.endswith(SKIP_EXTENSIONS):
            return False
        if any(path.startswith(p) for p in self.config.exclude_paths):
            return False
        if self.config.include_paths and not any(path.startswith(p) for p in self.config.include_paths):
            return False
        return parts.scheme in ("http", "https")

    def sitemap_urls(self, base: str, robots: RobotFileParser, limit: int) -> list[str]:
        queue = list(robots.site_maps() or []) or [urljoin(base, "/sitemap.xml"), urljoin(base, "/sitemap_index.xml"), urljoin(base, "/wp-sitemap.xml")]
        seen: set[str] = set()
        pages: list[str] = []
        fetched = 0
        while queue and fetched < 50 and len(pages) < limit:
            sitemap = queue.pop(0)
            if sitemap in seen or not self._same_site(base, sitemap):
                continue
            seen.add(sitemap)
            result = self._polite_fetch(sitemap)
            fetched += 1
            if result.status != 200:
                continue
            found, nested = _parse_sitemap(result.body)
            queue.extend(nested)
            pages.extend(found)
            if found and sitemap.endswith(("sitemap.xml", "sitemap_index.xml", "wp-sitemap.xml")):
                queue = [q for q in queue if not q.endswith(("/sitemap.xml", "/sitemap_index.xml", "/wp-sitemap.xml"))]
        return pages[:limit]

    def crawl(
        self, base_url: str, *, known: Callable[[str], tuple[str, str]] | None = None, seeds: Iterable[str] = (),
    ) -> Iterable[PageOutcome | CrawlReport]:
        """Yield one outcome per page, then the final report. `known(url)` returns stored (etag, last_modified)."""
        started = time.monotonic()
        base = canonicalize_url(base_url)
        report = CrawlReport()
        robots = self._robots(base)
        if getattr(robots, "disallow_all", False):
            report.stopped_early = "robots.txt disallows or is unavailable"
            yield report
            return

        queue: deque[tuple[str, int]] = deque([(base, 0)])
        queued = {base}
        for url in seeds:
            url = canonicalize_url(url)
            if url not in queued and self._same_site(base, url):
                queue.append((url, 1))
                queued.add(url)
        if self.config.use_sitemap:
            urls = self.sitemap_urls(base, robots, self.config.max_pages * 2)
            report.sitemap_urls = len(urls)
            for url in urls:
                url = canonicalize_url(url)
                if url not in queued and self._same_site(base, url):
                    queue.append((url, 1))
                    queued.add(url)

        seen_final: set[str] = set()
        count = 0
        while queue:
            if count >= self.config.max_pages:
                report.stopped_early = "max_pages reached"
                break
            if time.monotonic() - started > self.config.time_budget_seconds:
                report.stopped_early = "time budget reached"
                break
            url, depth = queue.popleft()
            if not self._wanted(url):
                continue
            if not robots.can_fetch(USER_AGENT, url):
                report.robots_blocked += 1
                yield PageOutcome(url=url, status="robots_blocked")
                continue
            count += 1
            etag, modified = known(url) if known else ("", "")
            result = self._polite_fetch(url, etag=etag, last_modified=modified)
            if result.error and result.status == 0:
                report.errors += 1
                yield PageOutcome(url=url, status="error", error=result.error)
                continue
            if result.status == 304:
                report.not_modified += 1
                yield PageOutcome(url=url, status="not_modified", http_status=304)
                continue
            if result.status in (404, 410):
                report.gone += 1
                yield PageOutcome(url=url, status="gone", http_status=result.status)
                continue
            if result.status != 200:
                report.errors += 1
                yield PageOutcome(url=url, status="error", http_status=result.status, error=result.error or f"HTTP {result.status}")
                continue
            if result.content_type and not result.content_type.startswith(HTML_TYPES):
                yield PageOutcome(url=url, status="skipped", http_status=200, error=f"content type {result.content_type}")
                continue
            final_url = canonicalize_url(result.url)
            if not self._same_site(base, final_url):
                yield PageOutcome(url=url, status="skipped", error="redirected off-site")
                continue
            page = extract(result.body, final_url)
            page_id = page.canonical_url if self._same_site(base, page.canonical_url) else final_url
            page.canonical_url = page_id
            if page_id in seen_final:
                yield PageOutcome(url=url, status="skipped", error=f"same page as {page_id}")
                continue
            seen_final.add(page_id)
            internal = [u for u in page.links if self._same_site(base, u)]
            report.links[page_id] = internal
            if depth < self.config.max_depth:
                for link in internal:
                    if link not in queued:
                        queued.add(link)
                        queue.append((link, depth + 1))
            report.fetched += 1
            outcome = PageOutcome(url=page_id, status="noindex" if page.noindex else "fetched", page=page, http_status=200, etag=result.etag, last_modified=result.last_modified)
            yield outcome
        report.discovered = len(queued)
        yield report
