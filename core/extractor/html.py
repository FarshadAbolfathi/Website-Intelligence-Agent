"""HTML -> structured document: metadata, main content, sections, links and product facts.

Boilerplate is removed twice. Per page, by element role, name and link density. Across a site, by
`BoilerplateFilter`, which learns blocks repeated on many pages such as footers that use plain <div>s.
"""

from __future__ import annotations

import hashlib
import json
import re
from collections import Counter
from dataclasses import dataclass, field
from urllib.parse import urldefrag, urljoin, urlsplit

from lxml import html as lxml_html
from lxml.etree import ParserError

from core.security.injection import strip_injection
from core.text.normalize import detect_language, normalize

DROP_TAGS = {"script", "style", "noscript", "iframe", "svg", "template", "canvas", "form", "button", "select", "input", "textarea", "object", "embed"}
BOILERPLATE_TAGS = {"nav", "footer", "aside", "header"}
BOILERPLATE_ROLES = {"navigation", "banner", "contentinfo", "complementary", "search"}
_BOILER_NAME = re.compile(
    r"(?:^|[-_\s])(?:nav|navbar|menu|footer|sidebar|breadcrumbs?|cookie|consent|newsletter|share|social|related|"
    r"comments?|widget|popup|modal|advert|ads?|banner|pagination|site-header|topbar)(?:$|[-_\s])",
    re.IGNORECASE,
)
_MAIN_NAME = re.compile(r"(?:^|[-_\s])(?:content|article|post|entry|main|product|page-body)(?:$|[-_\s])", re.IGNORECASE)
BLOCK_TAGS = {"p", "li", "td", "th", "blockquote", "pre", "dd", "dt", "figcaption", "summary", "div", "section"}
HEADINGS = {"h1", "h2", "h3", "h4", "h5", "h6"}
_WS = re.compile(r"[ \t\r\f\v ]+")

PAGE_TYPE_RULES: list[tuple[str, re.Pattern[str]]] = [
    ("contact", re.compile(r"contact|تماس|اتصل|اتصال", re.I)),
    ("about", re.compile(r"about|درباره|من نحن|معرفی", re.I)),
    ("faq", re.compile(r"\bfaqs?\b|سوالات|پرسش|الاسئله|الأسئلة", re.I)),
    ("policy", re.compile(r"terms|privacy|policy|refund|shipping|return|warranty|قوانین|حریم|ارسال|بازگشت|مرجوع|ضمانت|گارانتی|شروط|الخصوصية|سياسة|الشحن|الإرجاع", re.I)),
    ("service", re.compile(r"services?|خدمات", re.I)),
    ("category", re.compile(r"categor|collections?|دسته|تصنيف|فئة", re.I)),
    ("article", re.compile(r"\b(?:blog|news|article|articles|post)\b|وبلاگ|مقاله|اخبار|مدونة|مقال", re.I)),
]


@dataclass
class Section:
    heading: str
    text: str


@dataclass
class ProductFacts:
    name: str = ""
    sku: str = ""
    brand: str = ""
    description: str = ""
    price: str = ""
    currency: str = ""
    availability: str = ""  # in_stock | out_of_stock | preorder | unknown
    categories: list[str] = field(default_factory=list)
    attributes: dict[str, str] = field(default_factory=dict)

    def as_dict(self) -> dict:
        return {k: v for k, v in self.__dict__.items() if v}


@dataclass
class ExtractedPage:
    url: str
    canonical_url: str
    title: str
    description: str
    language: str
    content_type: str
    sections: list[Section]
    text: str
    links: list[str]
    last_modified: str = ""
    product: ProductFacts | None = None
    noindex: bool = False
    injection_dropped: int = 0

    @property
    def content_hash(self) -> str:
        volatile = json.dumps(self.product.as_dict(), sort_keys=True, ensure_ascii=False) if self.product else ""
        return hashlib.sha256((self.title + "\n" + self.text + "\n" + volatile).encode()).hexdigest()


def _clean(text: str) -> str:
    return _WS.sub(" ", text or "").strip()


def canonicalize_url(url: str) -> str:
    """Drop fragments and tracking parameters so one page is not crawled under many URLs."""
    url, _ = urldefrag(url)
    parts = urlsplit(url)
    query = "&".join(
        p for p in sorted(parts.query.split("&"))
        if p and not re.match(r"(?:utm_[a-z]+|gclid|fbclid|ref|replytocom|sessionid|sid)=", p, re.I)
    )
    path = parts.path or "/"
    if len(path) > 1 and path.endswith("/"):
        path = path.rstrip("/")
    netloc = parts.hostname.lower() if parts.hostname else ""
    if parts.port and not (parts.scheme == "http" and parts.port == 80 or parts.scheme == "https" and parts.port == 443):
        netloc += f":{parts.port}"
    return f"{parts.scheme.lower()}://{netloc}{path}" + (f"?{query}" if query else "")


def _is_boilerplate(el) -> bool:
    tag = el.tag if isinstance(el.tag, str) else ""
    if tag in BOILERPLATE_TAGS:
        return True
    if (el.get("role") or "").lower() in BOILERPLATE_ROLES:
        return True
    if el.get("hidden") is not None or (el.get("aria-hidden") or "") == "true":
        return True
    if re.search(r"display\s*:\s*none|visibility\s*:\s*hidden", el.get("style") or "", re.I):
        return True
    name = f"{el.get('class') or ''} {el.get('id') or ''}"
    return bool(_BOILER_NAME.search(name)) and not _MAIN_NAME.search(el.get("id") or "")


def _link_density(el) -> float:
    total = len(_clean(el.text_content()))
    if not total:
        return 0.0
    linked = sum(len(_clean(a.text_content())) for a in el.iter("a"))
    return linked / total


def _main_node(root):
    for query in ("//main", "//article", "//*[@role='main']", "//*[@id='content' or @id='main' or @id='primary']"):
        nodes = root.xpath(query)
        if nodes:
            return max(nodes, key=lambda n: len(n.text_content()))
    body = root.find("body")
    return body if body is not None else root


def _walk_sections(node) -> list[Section]:
    sections: list[Section] = []
    heading, buf = "", []

    def flush() -> None:
        nonlocal buf
        text = "\n".join(t for t in buf if t)
        if text or heading:
            sections.append(Section(heading, text))
        buf = []

    def visit(el) -> None:
        nonlocal heading
        for child in el:
            tag = child.tag if isinstance(child.tag, str) else ""
            if not tag:
                continue
            if tag in HEADINGS:
                flush()
                heading = _clean(child.text_content())
            elif tag == "tr":
                cells = [_clean(c.text_content()) for c in child.iter("td", "th")]
                if any(cells):
                    buf.append(" | ".join(c for c in cells if c))
            elif tag in ("p", "li", "blockquote", "pre", "dd", "dt", "figcaption", "summary"):
                text = _clean(child.text_content())
                if text and not (tag == "li" and _link_density(child) > 0.8):
                    buf.append(text)
            else:
                if child.text and _clean(child.text):
                    buf.append(_clean(child.text))
                visit(child)
                if child.tail and _clean(child.tail):
                    buf.append(_clean(child.tail))

    if node.text and _clean(node.text):
        buf.append(_clean(node.text))
    visit(node)
    flush()
    return [s for s in sections if s.text or s.heading]


_AVAIL = {
    "instock": "in_stock", "limitedavailability": "in_stock", "onlineonly": "in_stock", "instoreonly": "in_stock",
    "outofstock": "out_of_stock", "soldout": "out_of_stock", "discontinued": "out_of_stock",
    "preorder": "preorder", "backorder": "preorder",
}


def _json_ld_nodes(root) -> list[dict]:
    nodes: list[dict] = []

    def collect(data) -> None:
        if isinstance(data, list):
            for item in data:
                collect(item)
        elif isinstance(data, dict):
            nodes.append(data)
            for key in ("@graph", "mainEntity"):
                if key in data:
                    collect(data[key])

    for script in root.xpath("//script[@type='application/ld+json']"):
        try:
            collect(json.loads(script.text_content()))
        except (json.JSONDecodeError, TypeError):
            continue
    return nodes


def _type_is(node: dict, name: str) -> bool:
    t = node.get("@type")
    return name in t if isinstance(t, list) else t == name


def _product_from_json_ld(nodes: list[dict]) -> ProductFacts | None:
    node = next((n for n in nodes if _type_is(n, "Product")), None)
    if not node:
        return None
    facts = ProductFacts(name=_clean(str(node.get("name", ""))), sku=str(node.get("sku", "") or ""))
    facts.description = _clean(re.sub(r"<[^>]+>", " ", str(node.get("description", "") or "")))
    brand = node.get("brand")
    facts.brand = _clean(brand.get("name", "") if isinstance(brand, dict) else str(brand or ""))
    category = node.get("category")
    if isinstance(category, str):
        facts.categories = [c.strip() for c in re.split(r"[>/,]", category) if c.strip()]
    offers = node.get("offers")
    if isinstance(offers, list) and offers:
        offers = offers[0]
    if isinstance(offers, dict):
        if str(offers.get("@type", "")) == "AggregateOffer":
            facts.price = str(offers.get("lowPrice") or offers.get("price") or "")
        else:
            spec = offers.get("priceSpecification")
            if isinstance(spec, list) and spec:
                spec = spec[0]
            facts.price = str(offers.get("price") or (spec or {}).get("price", "") if isinstance(spec, dict) or spec is None else offers.get("price", ""))
        facts.currency = str(offers.get("priceCurrency", "") or "")
        availability = str(offers.get("availability", "")).rsplit("/", 1)[-1].lower()
        facts.availability = _AVAIL.get(availability, "unknown" if availability else "")
    for prop in node.get("additionalProperty", []) or []:
        if isinstance(prop, dict) and prop.get("name"):
            facts.attributes[str(prop["name"])] = str(prop.get("value", ""))
    return facts if facts.name else None


def _product_from_meta(root) -> ProductFacts | None:
    def meta(*names: str) -> str:
        for name in names:
            found = root.xpath(f"//meta[@property='{name}' or @name='{name}']/@content")
            if found:
                return _clean(found[0])
        return ""

    price = meta("product:price:amount", "og:price:amount")
    og_type = meta("og:type").lower()
    if not price and og_type != "product":
        return None
    availability = meta("product:availability", "og:availability").lower().replace(" ", "")
    return ProductFacts(
        name=meta("og:title"),
        price=price,
        currency=meta("product:price:currency", "og:price:currency"),
        availability=_AVAIL.get(availability, "unknown" if availability else ""),
        description=meta("og:description"),
    )


def classify_page(url: str, title: str, has_product: bool, json_ld: list[dict]) -> str:
    if has_product:
        return "product"
    if any(_type_is(n, "FAQPage") for n in json_ld):
        return "faq"
    if any(_type_is(n, t) for n in json_ld for t in ("Article", "BlogPosting", "NewsArticle")):
        return "article"
    path = urlsplit(url).path
    if path in ("", "/"):
        return "home"
    haystack = f"{path} {title}"
    for name, pattern in PAGE_TYPE_RULES:
        if pattern.search(haystack):
            return name
    return "page"


def extract(raw_html: str | bytes, url: str) -> ExtractedPage:
    try:
        root = lxml_html.fromstring(raw_html if raw_html else "<html></html>")
    except (ParserError, ValueError):
        root = lxml_html.fromstring("<html></html>")

    def first(xpath: str) -> str:
        found = root.xpath(xpath)
        return _clean(found[0] if isinstance(found[0], str) else found[0].text_content()) if found else ""

    json_ld = _json_ld_nodes(root)
    title = first("//meta[@property='og:title']/@content") or first("//title") or first("//h1")
    description = first("//meta[@name='description']/@content") or first("//meta[@property='og:description']/@content")
    robots = first("//meta[@name='robots']/@content").lower()
    lang_attr = first("/html/@lang").lower()[:2]
    modified = (
        first("//meta[@property='article:modified_time']/@content")
        or first("//meta[@property='og:updated_time']/@content")
        or next((str(n["dateModified"]) for n in json_ld if n.get("dateModified")), "")
    )
    canonical = first("//link[@rel='canonical']/@href")
    canonical_url = canonicalize_url(urljoin(url, canonical)) if canonical else canonicalize_url(url)
    product = _product_from_json_ld(json_ld) or _product_from_meta(root)

    links: list[str] = []
    for href in root.xpath("//a/@href"):
        href = href.strip()
        if not href or href.startswith(("#", "mailto:", "tel:", "javascript:", "data:")):
            continue
        links.append(canonicalize_url(urljoin(url, href)))
    links = list(dict.fromkeys(links))

    for el in list(root.iter()):
        tag = el.tag if isinstance(el.tag, str) else None
        if tag is None or tag in DROP_TAGS:
            el.drop_tree() if el.getparent() is not None else None
    main = _main_node(root)
    for el in list(main.iter()):
        if el is main or not isinstance(el.tag, str) or el.getparent() is None:
            continue
        if _is_boilerplate(el):
            el.drop_tree()
        elif el.tag in ("ul", "ol", "div") and len(_clean(el.text_content())) < 400 and _link_density(el) > 0.7:
            el.drop_tree()

    sections = _walk_sections(main)
    dropped = 0
    cleaned: list[Section] = []
    for section in sections:
        text, n = strip_injection(section.text)
        dropped += n
        if text or section.heading:
            cleaned.append(Section(section.heading, text))
    text = "\n\n".join(s.text for s in cleaned if s.text)
    language = lang_attr if lang_attr in ("fa", "ar", "en") else detect_language(f"{title}\n{text[:2000]}")

    return ExtractedPage(
        url=url, canonical_url=canonical_url, title=title, description=description, language=language,
        content_type=classify_page(url, title, product is not None, json_ld), sections=cleaned, text=text,
        links=links, last_modified=modified, product=product, noindex="noindex" in robots, injection_dropped=dropped,
    )


class BoilerplateFilter:
    """Learns text blocks repeated across many pages of a site and removes them from the indexed text."""

    def __init__(self, min_pages: int = 4, ratio: float = 0.5) -> None:
        self.min_pages = min_pages
        self.ratio = ratio
        self._seen: Counter[str] = Counter()
        self._pages = 0

    @staticmethod
    def _key(block: str) -> str:
        return hashlib.md5(normalize(block).encode(), usedforsecurity=False).hexdigest()

    def observe(self, page: ExtractedPage) -> None:
        self._pages += 1
        blocks = {self._key(line) for s in page.sections for line in s.text.split("\n") if len(line) >= 15}
        self._seen.update(blocks)

    def repeated(self) -> set[str]:
        if self._pages < self.min_pages:
            return set()
        floor = max(self.min_pages, self._pages * self.ratio)
        return {k for k, n in self._seen.items() if n >= floor}

    def apply(self, page: ExtractedPage, repeated: set[str] | None = None) -> ExtractedPage:
        repeated = self.repeated() if repeated is None else repeated
        if not repeated:
            return page
        sections = []
        for section in page.sections:
            lines = [ln for ln in section.text.split("\n") if self._key(ln) not in repeated]
            if lines or section.heading:
                sections.append(Section(section.heading, "\n".join(lines)))
        page.sections = [s for s in sections if s.text]
        page.text = "\n\n".join(s.text for s in page.sections)
        return page
