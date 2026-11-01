from core.crawler.crawler import CrawlConfig, CrawlReport, Crawler, HttpFetcher, _parse_sitemap
from core.extractor.html import BoilerplateFilter, canonicalize_url, extract
from core.security.url_guard import URLGuard


def crawler(**cfg):
    config = CrawlConfig(delay_seconds=0, **cfg)
    return Crawler(HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"}))), config)


def run(c, url, **kw):
    pages, report = [], None
    for item in c.crawl(url, **kw):
        if isinstance(item, CrawlReport):
            report = item
        else:
            pages.append(item)
    return pages, report


def test_extract_removes_chrome_and_keeps_main_content(sites):
    page = extract(sites["fa"].pages["/shipping"], "https://x.test/shipping")
    assert "پست پیشتاز" in page.text and "۱۸ ماه" in page.text
    assert "footer text" not in page.text and "never index" not in page.text and "HOME" not in page.text
    assert [s.heading for s in page.sections][:2] == ["شرایط ارسال", "گارانتی"]
    assert page.language == "fa" and page.content_type == "policy"


def test_extract_product_facts_from_json_ld(sites):
    page = extract(sites["en"].pages["/products/headphones-b"], "https://x.test/products/headphones-b")
    assert page.content_type == "product"
    assert page.product.price == "149.00" and page.product.currency == "USD" and page.product.availability == "out_of_stock"


def test_extract_survives_broken_input():
    for raw in ("", "<<<<", b"\xff\xfe\x00", "<html><body>" + "<div>" * 3000):
        assert extract(raw, "https://x.test/").url == "https://x.test/"


def test_injection_sentence_dropped_at_extract(sites):
    page = extract(sites["fa"].pages["/injected"], "https://x.test/injected")
    assert "بادوام" in page.text and "رایگان" not in page.text and page.injection_dropped == 1


def test_hidden_and_script_content_never_extracted():
    raw = '<html><body><main><p>visible paragraph here</p><div style="display:none">hidden prompt text</div><p hidden>also hidden</p><script>evil()</script></main></body></html>'
    text = extract(raw, "https://x.test/").text
    assert "visible" in text and "hidden" not in text and "evil" not in text


def test_canonicalize_removes_tracking_and_fragments():
    assert canonicalize_url("HTTP://Example.com/a/?utm_source=x&b=1#top") == "http://example.com/a?b=1"
    assert canonicalize_url("https://example.com:443/") == "https://example.com/"


def test_boilerplate_filter_learns_repeated_blocks(sites):
    f = BoilerplateFilter(min_pages=3)
    pages = [extract(html, "https://x.test" + path) for path, html in sites["fa"].pages.items()]
    for p in pages:
        f.observe(p)
    cleaned = f.apply(pages[1])
    assert "تمام حقوق" not in cleaned.text and "۱۳۹۵" in cleaned.text


def test_sitemap_parser_refuses_entity_expansion():
    evil = b'<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><urlset><url><loc>https://x.test/&b;</loc></url></urlset>'
    pages, _ = _parse_sitemap(evil)
    assert all("aaaa" not in p for p in pages)
    xxe = b'<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset><url><loc>&x;</loc></url></urlset>'
    pages, _ = _parse_sitemap(xxe)
    assert all("root:" not in p for p in pages)


def test_crawl_uses_sitemap_links_and_robots(sites):
    site = sites["fa"]
    pages, report = run(crawler(), site.url)
    urls = {p.url.replace(site.url, "") for p in pages if p.status == "fetched"}
    assert {"/about", "/shipping", "/products/phone-x", "/blog/battery", "/how-to-order"} <= urls
    assert report.robots_blocked >= 1 and "/hidden/secret" not in site.hits
    assert report.sitemap_urls >= 6


def test_crawl_without_sitemap_follows_links(sites):
    site = sites["en"]
    site.sitemap = False
    pages, _ = run(crawler(), site.url)
    assert any(p.url.endswith("/products/laptop-a") for p in pages)


def test_robots_disallow_all_stops_everything(sites):
    site = sites["en"]
    site.extra_responses["/robots.txt"] = (200, {"Content-Type": "text/plain"}, b"User-agent: *\nDisallow: /\n")
    pages, report = run(crawler(), site.url)
    assert not [p for p in pages if p.status == "fetched"]
    assert "/about" not in site.hits and "/" not in site.hits


def test_robots_server_error_fails_closed(sites):
    site = sites["en"]
    site.extra_responses["/robots.txt"] = (503, {}, b"")
    _, report = run(crawler(), site.url)
    assert report.stopped_early and "/about" not in site.hits


def test_conditional_request_returns_not_modified(sites):
    site = sites["en"]
    pages, _ = run(crawler(), site.url)
    etags = {p.url: (p.etag, p.last_modified) for p in pages if p.status == "fetched"}
    second, report = run(crawler(), site.url, known=lambda u: etags.get(u, ("", "")))
    assert report.not_modified >= 5 and report.fetched == 0


def test_max_pages_is_a_hard_limit(sites):
    pages, report = run(crawler(max_pages=2), sites["fa"].url)
    assert len([p for p in pages if p.status == "fetched"]) == 2 and report.stopped_early == "max_pages reached"


def test_crawl_delay_is_honoured(sites):
    site = sites["en"]
    slept = []
    site.extra_responses["/robots.txt"] = (200, {}, b"User-agent: *\nCrawl-delay: 2\n")
    c = Crawler(HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"}))), CrawlConfig(delay_seconds=0, max_pages=2, use_sitemap=False), sleep=slept.append)
    run(c, site.url)
    assert slept and max(slept) > 1.5
