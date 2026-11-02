"""Real Qdrant checks. Skipped unless WIA_TEST_QDRANT points at a running server."""

import os

import pytest
from sqlalchemy import create_engine, insert
from sqlalchemy.pool import StaticPool

from core.crawler.crawler import CrawlConfig, HttpFetcher
from core.embeddings.providers import HashingEmbedder
from core.knowledge import schema as t
from core.knowledge.ingest import Ingestor
from core.knowledge.store import Corpus, Scope, SqlKnowledgeStore, utcnow
from core.retrieval.hybrid import Retriever
from core.retrieval.vector import QdrantVectorIndex
from core.security.url_guard import URLGuard

URL = os.environ.get("WIA_TEST_QDRANT", "")
pytestmark = pytest.mark.skipif(not URL, reason="set WIA_TEST_QDRANT to a running Qdrant")


@pytest.fixture
def stack(sites):
    engine = create_engine("sqlite://", poolclass=StaticPool, connect_args={"check_same_thread": False})
    t.metadata.create_all(engine)
    store = SqlKnowledgeStore(engine)
    embedder = HashingEmbedder()
    vectors = QdrantVectorIndex(URL, prefix=f"wiatest{os.getpid()}")
    ingestor = Ingestor(store, embedder, vectors)
    fetcher = HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"})))
    yield engine, store, embedder, vectors, ingestor, fetcher
    for corpus in ("own", "competitor"):
        vectors.client.delete(f"/collections/{vectors.prefix}_{corpus}")


def site(engine, name):
    with engine.begin() as c:
        return c.execute(insert(t.sites).values(name=name, base_url="http://x", public_key=name, default_language="en", settings={}, created_at=utcnow())).inserted_primary_key[0]


def test_vectors_stored_searched_and_scoped(stack, sites):
    engine, store, embedder, vectors, ingestor, fetcher = stack
    a, b = Scope(site(engine, "a")), Scope(site(engine, "b"))
    ingestor.crawl_site(a, sites["en"].url, fetcher, CrawlConfig(delay_seconds=0))
    ingestor.crawl_site(b, sites["fa"].url, fetcher, CrawlConfig(delay_seconds=0))
    assert vectors.healthy()
    query = embedder.embed(["return unused item refund"])[0]
    found_a = vectors.search(a, query, 5)
    assert found_a and all(chunk_id in store.hits_for_chunks(a, [i for i, _ in found_a]) for chunk_id, _ in found_a)
    assert not {i for i, _ in found_a} & {i for i, _ in vectors.search(b, query, 5)}


def test_competitor_vectors_live_in_their_own_collection(stack, sites):
    engine, store, embedder, vectors, ingestor, fetcher = stack
    site_id = site(engine, "a")
    with engine.begin() as c:
        comp = c.execute(insert(t.competitors).values(site_id=site_id, name="r", base_url=sites["competitor"].url, enabled=True, created_at=utcnow())).inserted_primary_key[0]
    own, rival = Scope(site_id), Scope(site_id, Corpus.COMPETITOR, comp)
    ingestor.crawl_site(own, sites["en"].url, fetcher, CrawlConfig(delay_seconds=0))
    ingestor.crawl_site(rival, sites["competitor"].url, fetcher, CrawlConfig(delay_seconds=0))
    assert vectors._collection(own) != vectors._collection(rival)
    query = embedder.embed(["free overnight shipping on every order"])[0]
    assert not set(i for i, _ in vectors.search(own, query, 20)) & set(i for i, _ in vectors.search(rival, query, 20))


def test_removed_page_leaves_qdrant_and_scope_delete_works(stack, sites):
    engine, store, embedder, vectors, ingestor, fetcher = stack
    scope = Scope(site(engine, "a"))
    source = sites["en"]
    ingestor.crawl_site(scope, source.url, fetcher, CrawlConfig(delay_seconds=0))
    doc = store.find_doc(scope, source.url + "/old-page")
    ids = {c["id"] for c in store.doc_chunks(doc["id"])}
    query = embedder.embed(["seasonal winter bundle promotion discontinued"])[0]
    assert ids & {i for i, _ in vectors.search(scope, query, 5)}
    del source.pages["/old-page"]
    source.pages["/"] = source.pages["/"].replace('<a href="/old-page">old</a>', "")
    ingestor.crawl_site(scope, source.url, fetcher, CrawlConfig(delay_seconds=0))
    assert not ids & {i for i, _ in vectors.search(scope, query, 20)}
    vectors.delete_scope(scope)
    assert vectors.search(scope, query, 5) == []


def test_qdrant_outage_degrades_to_lexical(stack, sites):
    engine, store, embedder, _, ingestor, fetcher = stack
    scope = Scope(site(engine, "a"))
    ingestor.crawl_site(scope, sites["en"].url, fetcher, CrawlConfig(delay_seconds=0))

    class Semantic(HashingEmbedder):
        semantic = True

    down = QdrantVectorIndex("http://127.0.0.1:9", timeout=1)
    result = Retriever(store, Semantic(), down).search(scope, "return unused item refund")
    assert result.mode == "lexical" and result.hits and "30 days" in result.hits[0].text
