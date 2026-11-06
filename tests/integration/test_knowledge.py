"""Spec section 16 scenarios against live local sites: crawl per language, price change, deletion, isolation."""

import pytest
from sqlalchemy import create_engine, insert, select
from sqlalchemy.pool import StaticPool

from core.crawler.crawler import CrawlConfig, HttpFetcher
from core.embeddings.providers import HashingEmbedder, NoEmbedder
from core.knowledge import schema as t
from core.knowledge.ingest import Ingestor, chunk_sections
from core.knowledge.store import Corpus, Scope, SqlKnowledgeStore, utcnow
from core.extractor.html import Section
from core.retrieval.hybrid import Retriever
from core.retrieval.vector import SqlVectorIndex
from core.security.url_guard import URLGuard


@pytest.fixture
def engine():
    e = create_engine("sqlite://", poolclass=StaticPool, connect_args={"check_same_thread": False})
    t.metadata.create_all(e)
    return e


def make_site(engine, name="s", url="http://x"):
    with engine.begin() as c:
        return c.execute(insert(t.sites).values(name=name, base_url=url, public_key=name, default_language="fa", settings={}, created_at=utcnow())).inserted_primary_key[0]


@pytest.fixture
def kit(engine):
    store = SqlKnowledgeStore(engine)
    return store, Ingestor(store, NoEmbedder()), HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"})))


def learn(ingestor, fetcher, scope, url):
    return ingestor.crawl_site(scope, url, fetcher, CrawlConfig(delay_seconds=0))


@pytest.mark.parametrize(
    "lang,question,expected_fragment",
    [
        ("fa", "شرایط ارسال سفارش چیست", "پست پیشتاز"),
        ("fa", "گارانتي گوشي چند ماه است", "۱۸ ماه"),  # Arabic letters in the query
        ("fa", "باتری گوشی را چطور نگه دارم", "هشتاد درصد"),
        ("ar", "ما هو ضمان الأجهزة", "سنتين"),
        ("ar", "كم يوما يستغرق توصيل الطلبات", "ثلاثة أيام"),
        ("en", "what is the return policy for refund", "30 days"),
        ("en", "where was the company founded", "Berlin"),
    ],
)
def test_crawl_and_retrieve_each_language(engine, kit, sites, lang, question, expected_fragment):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    stats = learn(ingestor, fetcher, scope, sites[lang].url)
    assert stats.new >= 4 and stats.errors == 0
    hits = store.search_lexical(scope, question)
    assert hits and any(expected_fragment in h.text for h in hits[:3])


def test_document_metadata_is_complete(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    learn(ingestor, fetcher, scope, sites["fa"].url)
    doc = store.find_doc(scope, sites["fa"].url + "/products/phone-x")
    assert doc["title"] == "گوشی ایکس" and doc["content_type"] == "product" and doc["language"] == "fa"
    assert doc["source"] == "crawler" and doc["status"] == "active" and len(doc["content_hash"]) == 64 and doc["crawl_date"]
    assert doc["structured"]["product"]["price"] == "25000000"


def test_second_crawl_processes_nothing_when_nothing_changed(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    learn(ingestor, fetcher, scope, sites["en"].url)
    before = {d["id"]: d["version"] for d in store.list_docs(scope)}
    stats = learn(ingestor, fetcher, scope, sites["en"].url)
    assert stats.new == 0 and stats.updated == 0 and stats.unchanged >= len(before)
    assert {d["id"]: d["version"] for d in store.list_docs(scope)} == before


def test_price_change_detected_and_logged(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    site = sites["fa"]
    learn(ingestor, fetcher, scope, site.url)
    site.pages["/products/phone-x"] = site.pages["/products/phone-x"].replace("25000000", "27500000")
    stats = learn(ingestor, fetcher, scope, site.url)
    assert stats.updated == 1
    doc = store.find_doc(scope, site.url + "/products/phone-x")
    assert doc["structured"]["product"]["price"] == "27500000" and doc["version"] == 2
    with engine.connect() as c:
        log = c.execute(select(t.changes.c.old, t.changes.c.new).where(t.changes.c.doc_id == doc["id"], t.changes.c.field == "price")).all()
    assert log == [("25000000", "27500000")]


def test_edited_content_replaces_old_chunks(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    site = sites["en"]
    learn(ingestor, fetcher, scope, site.url)
    site.pages["/shipping"] = site.pages["/shipping"].replace("within 30 days", "within 14 days")
    learn(ingestor, fetcher, scope, site.url)
    texts = " ".join(h.text for h in store.search_lexical(scope, "return unused item refund"))
    assert "14 days" in texts and "30 days" not in texts


def test_deleted_page_leaves_knowledge_base(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    site = sites["en"]
    learn(ingestor, fetcher, scope, site.url)
    assert store.search_lexical(scope, "seasonal winter bundle promotion")
    del site.pages["/old-page"]
    site.pages["/"] = site.pages["/"].replace('<a href="/old-page">old</a>', "")
    stats = learn(ingestor, fetcher, scope, site.url)
    assert stats.removed == 1
    assert not store.search_lexical(scope, "seasonal winter bundle promotion")
    assert store.find_doc(scope, site.url + "/old-page")["status"] == "removed"


def test_page_returning_comes_back(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    site = sites["en"]
    learn(ingestor, fetcher, scope, site.url)
    saved = site.pages.pop("/old-page")
    learn(ingestor, fetcher, scope, site.url)
    site.pages["/old-page"] = saved
    learn(ingestor, fetcher, scope, site.url)
    assert store.search_lexical(scope, "seasonal winter bundle promotion")


def test_transient_error_keeps_existing_knowledge(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    site = sites["en"]
    learn(ingestor, fetcher, scope, site.url)
    site.extra_responses["/about"] = (500, {}, b"boom")
    stats = learn(ingestor, fetcher, scope, site.url)
    assert stats.errors == 1 and stats.failures and stats.removed == 0
    assert store.search_lexical(scope, "founded Berlin")


def test_near_duplicate_pages_are_not_answered_twice(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    site = sites["en"]
    site.pages["/"] += '<a href="/dup-a">a</a><a href="/dup-b">b</a>'
    stats = learn(ingestor, fetcher, scope, site.url)
    assert stats.duplicates == 1
    assert len(store.search_lexical(scope, "same paragraph duplicate detection urls")) == 1


def test_sites_are_isolated(engine, kit, sites):
    store, ingestor, fetcher = kit
    a, b = Scope(make_site(engine, "a")), Scope(make_site(engine, "b"))
    learn(ingestor, fetcher, a, sites["en"].url)
    learn(ingestor, fetcher, b, sites["fa"].url)
    # Synonyms may match a site's own pages across languages («laptop» = «لپتاپ»); text of the other site never appears.
    assert not any("Berlin" in h.text for h in store.search_lexical(b, "Berlin founded laptop repair"))
    assert not any("پیشتاز" in h.text for h in store.search_lexical(a, "پست پیشتاز گارانتی"))
    assert all(h.coverage < 0.6 for h in store.search_lexical(b, "Berlin founded laptop repair"))


def test_competitor_content_invisible_from_own_scope(engine, kit, sites):
    store, ingestor, fetcher = kit
    site_id = make_site(engine)
    with engine.begin() as c:
        comp = c.execute(insert(t.competitors).values(site_id=site_id, name="r", base_url=sites["competitor"].url, enabled=True, created_at=utcnow())).inserted_primary_key[0]
    own, rival = Scope(site_id), Scope(site_id, Corpus.COMPETITOR, comp)
    learn(ingestor, fetcher, own, sites["en"].url)
    learn(ingestor, fetcher, rival, sites["competitor"].url)
    own_hits = store.search_lexical(own, "free overnight shipping drone")
    assert all(h.corpus == "own" and "overnight" not in h.text and sites["competitor"].url not in h.url for h in own_hits)
    assert not store.search_lexical(own, "overnight drone")
    assert "overnight" in store.search_lexical(rival, "free overnight shipping")[0].text
    with pytest.raises(ValueError):
        Scope(site_id, Corpus.OWN, comp)


def test_spacing_and_typos_are_tolerated(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    learn(ingestor, fetcher, scope, sites["fa"].url)
    assert store.search_lexical(scope, "لپتاپ زد")  # the page writes «لپ تاپ»
    assert store.search_lexical(scope, "مگاپیکسل")
    assert store.search_lexical(scope, "مگاپیسکل"[:3] + "اپیکسل")  # one wrong letter
    assert store.search_lexical(scope, "ارسال ۲ تا ۴ روز")


def test_unknown_subject_is_low_confidence(engine, kit, sites):
    store, ingestor, fetcher = kit
    scope = Scope(make_site(engine))
    learn(ingestor, fetcher, scope, sites["en"].url)
    retriever = Retriever(store, NoEmbedder())
    assert retriever.search(scope, "battery life of Headphones B").confident
    assert not retriever.search(scope, "battery life of Zanzibar Quantum").confident


def test_hybrid_merges_vectors_and_respects_scope(engine, sites):
    store = SqlKnowledgeStore(engine)
    embedder = HashingEmbedder()
    vectors = SqlVectorIndex(engine)
    ingestor = Ingestor(store, embedder, vectors)
    fetcher = HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"})))
    a, b = Scope(make_site(engine, "a")), Scope(make_site(engine, "b"))
    learn(ingestor, fetcher, a, sites["en"].url)
    learn(ingestor, fetcher, b, sites["fa"].url)
    query = embedder.embed(["return unused item refund"])[0]
    ids_a = [i for i, _ in vectors.search(a, query)]
    assert ids_a and not set(ids_a) & {i for i, _ in vectors.search(b, query)}
    assert not store.hits_for_chunks(b, ids_a)  # a foreign chunk id resolves to nothing


def test_removed_page_drops_its_vectors(engine, sites):
    store = SqlKnowledgeStore(engine)
    embedder = HashingEmbedder()
    vectors = SqlVectorIndex(engine)
    ingestor = Ingestor(store, embedder, vectors)
    fetcher = HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"})))
    scope = Scope(make_site(engine))
    learn(ingestor, fetcher, scope, sites["en"].url)
    doc = store.find_doc(scope, sites["en"].url + "/old-page")
    ids = [c["id"] for c in store.doc_chunks(doc["id"])]
    del sites["en"].pages["/old-page"]
    learn(ingestor, fetcher, scope, sites["en"].url)
    with engine.connect() as c:
        assert not c.execute(select(t.embeddings.c.chunk_id).where(t.embeddings.c.chunk_id.in_(ids))).all()


def test_long_text_is_split_with_overlap():
    long_para = " ".join(f"This is sentence number {i} about the product." for i in range(60))
    chunks = chunk_sections([Section("H", long_para), Section("Empty", "   ")])
    assert len(chunks) >= 2 and all(h == "H" for h, _ in chunks) and all(len(text) < 1200 for _, text in chunks)
