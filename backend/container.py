"""Wires the engine's interfaces to concrete implementations chosen by settings."""

from __future__ import annotations

import secrets
from dataclasses import dataclass
from functools import cached_property

from sqlalchemy import create_engine, insert, select
from sqlalchemy.engine import Engine
from sqlalchemy.pool import StaticPool

from core.chat.engine import ChatEngine
from core.competitor.analysis import CompetitorAnalyzer
from core.crawler.crawler import CrawlConfig, HttpFetcher, PlaywrightFetcher
from core.embeddings.providers import Embedder, NoEmbedder, OllamaEmbedder
from core.evaluation.runner import Evaluator
from core.knowledge import schema as t
from core.knowledge.ingest import Ingestor
from core.knowledge.store import Corpus, Scope, SqlKnowledgeStore
from core.llm.providers import LlamaCppProvider, LLMProvider, NoLLM, OllamaProvider
from core.retrieval.hybrid import Retriever
from core.retrieval.vector import QdrantVectorIndex, SqlVectorIndex, VectorIndex
from core.security.url_guard import URLGuard

from .settings import Settings


def make_engine(url: str) -> Engine:
    if url.startswith("sqlite"):
        if url in ("sqlite://", "sqlite:///:memory:"):
            return create_engine(url, poolclass=StaticPool, connect_args={"check_same_thread": False})
        return create_engine(url, connect_args={"check_same_thread": False, "timeout": 30})
    return create_engine(url.replace("postgresql://", "postgresql+psycopg://", 1), pool_pre_ping=True, pool_size=5, max_overflow=5)


def kv_get(engine: Engine, key: str, default=None):
    with engine.connect() as c:
        row = c.execute(select(t.kv.c.value).where(t.kv.c.key == key)).first()
    return row.value if row else default


def kv_set(engine: Engine, key: str, value) -> None:
    with engine.begin() as c:
        c.execute(t.kv.delete().where(t.kv.c.key == key))
        c.execute(insert(t.kv).values(key=key, value=value))


@dataclass
class Container:
    settings: Settings

    @cached_property
    def engine(self) -> Engine:
        engine = make_engine(self.settings.database_url)
        t.metadata.create_all(engine)
        return engine

    @cached_property
    def secret_key(self) -> str:
        """Use WIA_SECRET_KEY if set. Otherwise generate one and keep it in this install's own database."""
        if self.settings.secret_key:
            return self.settings.secret_key
        stored = kv_get(self.engine, "secret_key")
        if not stored:
            stored = secrets.token_urlsafe(48)
            kv_set(self.engine, "secret_key", stored)
        return stored

    @cached_property
    def store(self) -> SqlKnowledgeStore:
        return SqlKnowledgeStore(self.engine)

    @cached_property
    def guard(self) -> URLGuard:
        return URLGuard(allow_hosts=self.settings.crawler_allow_hosts)

    @cached_property
    def embedder(self) -> Embedder:
        if self.settings.embed_backend == "ollama":
            return OllamaEmbedder(self.settings.embed_url, self.settings.embed_model)
        return NoEmbedder()

    @cached_property
    def vectors(self) -> VectorIndex | None:
        if self.embedder.name == "none":
            return None
        if self.settings.vector_backend == "qdrant":
            return QdrantVectorIndex(self.settings.qdrant_url, api_key=self.settings.qdrant_api_key)
        return SqlVectorIndex(self.engine)

    @property
    def llm(self) -> LLMProvider:
        """Read for each request, so a model selected in the dashboard applies without a restart."""
        backend = kv_get(self.engine, "llm_backend") or self.settings.llm_backend
        model = kv_get(self.engine, "llm_model") or self.settings.llm_model
        if backend == "ollama" and model:
            return OllamaProvider(self.settings.llm_url, model, timeout=self.settings.llm_timeout)
        if backend == "llamacpp":
            return LlamaCppProvider(self.settings.llm_url, model or "local", timeout=self.settings.llm_timeout)
        return NoLLM()

    @cached_property
    def retriever(self) -> Retriever:
        return Retriever(self.store, self.embedder, self.vectors)

    @cached_property
    def ingestor(self) -> Ingestor:
        return Ingestor(self.store, self.embedder, self.vectors)

    def fetcher(self):
        if self.settings.crawler_js:
            return PlaywrightFetcher(self.guard)
        return HttpFetcher(self.guard)

    def refresh_url(self, scope: Scope, url: str) -> bool:
        """Re-read one page right now, for a volatile answer. Own-site only: competitor data is never live-fetched here."""
        from core.extractor.html import extract

        if scope.corpus is not Corpus.OWN:
            return False
        fetcher = HttpFetcher(self.guard, timeout=6.0)
        result = fetcher.fetch(url)
        if result.status in (404, 410):
            doc = self.store.find_doc(scope, url)
            if doc:
                self.ingestor.remove_doc(scope, doc)
            return False
        if result.status != 200:
            return False
        self.ingestor.ingest_page(scope, extract(result.body, url), etag=result.etag, http_last_modified=result.last_modified)
        doc = self.store.find_doc(scope, url)
        if doc:
            from core.knowledge.store import utcnow

            self.store.touch_doc(doc["id"], crawl_date=utcnow())
        return True

    def chat(self, not_found_text: dict[str, str] | None = None) -> ChatEngine:
        return ChatEngine(self.store, self.retriever, self.llm, refresh=self.refresh_url,
                          volatile_ttl_hours=self.settings.volatile_ttl_hours, not_found_text=not_found_text)

    def evaluator(self) -> Evaluator:
        return Evaluator(self.store, self.retriever, self.chat())

    @cached_property
    def analyzer(self) -> CompetitorAnalyzer:
        return CompetitorAnalyzer(self.store)

    def crawl_config(self, *, competitor: bool = False, overrides: dict | None = None) -> CrawlConfig:
        overrides = overrides or {}
        delay = self.settings.competitor_delay if competitor else self.settings.crawler_delay
        return CrawlConfig(
            max_pages=int(overrides.get("max_pages") or self.settings.crawler_max_pages),
            delay_seconds=max(float(overrides.get("delay_seconds") or delay), delay if competitor else 0.0),
            include_paths=tuple(overrides.get("include_paths") or ()),
            exclude_paths=tuple(overrides.get("exclude_paths") or CrawlConfig().exclude_paths),
        )
