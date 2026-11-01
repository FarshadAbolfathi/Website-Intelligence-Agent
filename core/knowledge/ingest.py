"""Turn crawl outcomes into knowledge: chunking, change detection, duplicates, removal and embeddings."""

from __future__ import annotations

from dataclasses import dataclass, field

from core.crawler.crawler import CrawlConfig, Crawler, CrawlReport, Fetcher, PageOutcome
from core.embeddings.providers import Embedder
from core.extractor.html import BoilerplateFilter, ExtractedPage, Section
from core.knowledge.store import ChunkDraft, Scope, SqlKnowledgeStore, hamming, simhash, url_key, utcnow
from core.retrieval.vector import VectorIndex
from core.text.normalize import detect_language, sentences

TARGET_CHARS = 900
MIN_CHARS = 40
NEAR_DUP_HAMMING = 3


def chunk_sections(sections: list[Section], *, target: int = TARGET_CHARS) -> list[tuple[str, str]]:
    """Split on headings, then paragraphs and sentences. Oversized chunks carry one sentence of overlap."""
    out: list[tuple[str, str]] = []
    for section in sections:
        if not section.text.strip():
            continue
        buf = ""
        for para in section.text.split("\n"):
            parts = [para] if len(para) <= target else sentences(para)
            for part in parts:
                if buf and len(buf) + len(part) + 1 > target:
                    out.append((section.heading, buf))
                    tail = sentences(buf)[-1] if len(buf) > target * 0.6 else ""
                    buf = tail if len(tail) < target * 0.3 else ""
                buf = f"{buf}\n{part}".strip() if buf else part
        if buf:
            if len(buf) < MIN_CHARS and out and out[-1][0] == section.heading:
                heading, prev = out[-1]
                out[-1] = (heading, f"{prev}\n{buf}")
            else:
                out.append((section.heading, buf))
    return out


def page_drafts(page: ExtractedPage) -> list[ChunkDraft]:
    pairs = chunk_sections(page.sections)
    boost = page.title
    if page.product and page.product.name:
        boost = f"{page.title} {page.product.name} {page.product.brand} {page.product.sku}"
    drafts = [ChunkDraft(heading, text, detect_language(text, page.language), boost) for heading, text in pairs]
    if page.product and page.product.description and not any(page.product.description[:60] in d.text for d in drafts):
        drafts.insert(0, ChunkDraft(page.product.name, page.product.description, page.language, boost))
    if not drafts and page.product and page.product.name:
        drafts.append(ChunkDraft(page.product.name, page.product.name, page.language, boost))
    if not drafts and page.description and len(page.description) >= MIN_CHARS:
        drafts.append(ChunkDraft(page.title, page.description, page.language, boost))
    return drafts


@dataclass
class IngestStats:
    new: int = 0
    updated: int = 0
    unchanged: int = 0
    removed: int = 0
    duplicates: int = 0
    empty: int = 0
    errors: int = 0
    robots_blocked: int = 0
    failures: list[dict] = field(default_factory=list)
    crawl: dict = field(default_factory=dict)

    def as_dict(self) -> dict:
        return {k: v for k, v in self.__dict__.items() if k != "failures"}


class Ingestor:
    def __init__(self, store: SqlKnowledgeStore, embedder: Embedder, vectors: VectorIndex | None = None) -> None:
        self.store = store
        self.embedder = embedder
        self.vectors = vectors

    def _embed(self, scope: Scope, chunk_ids: list[int], drafts: list[ChunkDraft]) -> None:
        if not self.vectors or not chunk_ids or self.embedder.name == "none":
            return
        vectors = self.embedder.embed([f"{d.heading}\n{d.text}".strip() for d in drafts])
        self.vectors.upsert(scope, self.embedder.name, list(zip(chunk_ids, vectors, strict=True)))

    def _find_duplicate(self, scope: Scope, doc_id: int, fingerprint: str) -> int | None:
        if not fingerprint:
            return None
        for other in self.store.list_docs(scope):
            if other["id"] != doc_id and other["simhash"] and other["content_type"] != "product" and hamming(other["simhash"], fingerprint) <= NEAR_DUP_HAMMING:
                return other["id"]
        return None

    def ingest_page(self, scope: Scope, page: ExtractedPage, *, etag: str = "", http_last_modified: str = "", source: str = "crawler") -> str:
        """Learn one page. Returns new | updated | unchanged | duplicate | empty."""
        url = page.canonical_url
        existing = self.store.find_doc(scope, url)
        content_hash = page.content_hash
        if existing and existing["content_hash"] == content_hash and existing["status"] != "removed":
            self.store.touch_doc(existing["id"], etag=etag, http_last_modified=http_last_modified)
            return "unchanged"

        drafts = page_drafts(page)
        if not drafts:
            if existing and existing["status"] != "removed":
                self.remove_doc(scope, existing)
            return "empty"

        structured = {"product": page.product.as_dict()} if page.product else {}
        fingerprint = "" if page.product else simhash(page.text)
        doc_id, chunk_ids = self.store.save_doc(scope, url, {
            "title": page.title, "description": page.description, "content_type": page.content_type, "language": page.language,
            "source": source, "content_hash": content_hash, "simhash": fingerprint, "status": "active", "dup_of": None,
            "structured": structured, "etag": etag, "http_last_modified": http_last_modified, "last_modified": page.last_modified,
        }, drafts)
        if existing:
            self._log_changes(doc_id, existing, structured, page)
        self._embed(scope, chunk_ids, drafts)

        original = self._find_duplicate(scope, doc_id, fingerprint)
        if original:
            self.store.set_status(doc_id, "duplicate", original)
            if self.vectors:
                self.vectors.delete(scope, chunk_ids)
            return "duplicate"
        return "updated" if existing else "new"

    def _log_changes(self, doc_id: int, existing: dict, structured: dict, page: ExtractedPage) -> None:
        old = (existing.get("structured") or {}).get("product", {})
        new = structured.get("product", {})
        for key in ("price", "availability", "currency", "name"):
            if str(old.get(key, "")) != str(new.get(key, "")):
                self.store.record_change(doc_id, key, str(old.get(key, "")), str(new.get(key, "")))
        if existing["title"] != page.title:
            self.store.record_change(doc_id, "title", existing["title"], page.title)
        self.store.record_change(doc_id, "content", existing["content_hash"][:12], page.content_hash[:12])

    def remove_doc(self, scope: Scope, doc: dict) -> None:
        """Retire a document. It stays for history but can no longer be retrieved."""
        self.store.set_status(doc["id"], "removed")
        if self.vectors:
            self.vectors.delete(scope, [c["id"] for c in self.store.doc_chunks(doc["id"])])

    def crawl_site(self, scope: Scope, base_url: str, fetcher: Fetcher, config: CrawlConfig | None = None, *, sleep=None) -> IngestStats:
        """Incremental crawl. Every known URL is re-seeded, so pages that vanished get an explicit 404 and are removed."""
        kwargs = {"sleep": sleep} if sleep else {}
        crawler = Crawler(fetcher, config, **kwargs)
        known_docs = {d["url"]: d for d in self.store.list_docs(scope, status=None)}
        active_urls = [u for u, d in known_docs.items() if d["status"] != "removed"]
        stats = IngestStats()
        filter_ = BoilerplateFilter()
        pages: list[PageOutcome] = []
        seen: set[str] = set()
        report: CrawlReport | None = None

        def known(url: str) -> tuple[str, str]:
            doc = known_docs.get(url)
            return (doc["etag"], doc["http_last_modified"]) if doc and doc["status"] == "active" else ("", "")

        for item in crawler.crawl(base_url, known=known, seeds=active_urls):
            if isinstance(item, CrawlReport):
                report = item
                continue
            seen.add(item.url)
            if item.status == "fetched":
                filter_.observe(item.page)
                pages.append(item)
            elif item.status == "not_modified":
                doc = known_docs.get(item.url)
                if doc:
                    self.store.touch_doc(doc["id"])
                stats.unchanged += 1
            elif item.status in ("gone", "noindex", "robots_blocked"):
                if item.status == "robots_blocked":
                    stats.robots_blocked += 1
                doc = known_docs.get(item.url)
                if doc and doc["status"] != "removed":
                    self.remove_doc(scope, doc)
                    stats.removed += 1
            elif item.status == "error":
                stats.errors += 1
                stats.failures.append({"url": item.url, "error": item.error})

        repeated = filter_.repeated()
        for item in pages:
            page = filter_.apply(item.page, repeated)
            result = self.ingest_page(scope, page, etag=item.etag, http_last_modified=item.last_modified)
            setattr(stats, {"new": "new", "updated": "updated", "unchanged": "unchanged", "duplicate": "duplicates", "empty": "empty"}[result],
                    getattr(stats, {"new": "new", "updated": "updated", "unchanged": "unchanged", "duplicate": "duplicates", "empty": "empty"}[result]) + 1)

        if report:
            stats.crawl = report.as_dict()
            self._save_links(scope, report)
        return stats

    def _save_links(self, scope: Scope, report: CrawlReport) -> None:
        from sqlalchemy import delete, insert

        from core.knowledge import schema as t

        rows = [
            {"site_id": scope.site_id, "corpus": scope.corpus.value, "from_key": url_key(src), "to_key": url_key(dst)}
            for src, targets in report.links.items() for dst in targets[:200]
        ]
        with self.store.engine.begin() as c:
            c.execute(delete(t.links).where(t.links.c.site_id == scope.site_id, t.links.c.corpus == scope.corpus.value))
            if rows:
                c.execute(insert(t.links), rows)


def staleness_hours(doc_date) -> float:
    return (utcnow() - doc_date.replace(tzinfo=utcnow().tzinfo)).total_seconds() / 3600
