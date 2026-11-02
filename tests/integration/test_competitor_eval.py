import pytest
from sqlalchemy import create_engine, insert
from sqlalchemy.pool import StaticPool

from core.chat.engine import ChatEngine
from core.competitor.analysis import CompetitorAnalyzer
from core.crawler.crawler import CrawlConfig, HttpFetcher
from core.embeddings.providers import NoEmbedder
from core.evaluation.runner import EvalItem, Evaluator, generate_questions, language_ok
from core.knowledge import schema as t
from core.knowledge.ingest import Ingestor
from core.knowledge.store import Corpus, Scope, SqlKnowledgeStore, utcnow
from core.retrieval.hybrid import Retriever
from core.security.url_guard import URLGuard


@pytest.fixture
def setup(sites):
    engine = create_engine("sqlite://", poolclass=StaticPool, connect_args={"check_same_thread": False})
    t.metadata.create_all(engine)
    store = SqlKnowledgeStore(engine)
    ingestor = Ingestor(store, NoEmbedder())
    fetcher = HttpFetcher(URLGuard(allow_hosts=frozenset({"127.0.0.1"})))
    return engine, store, ingestor, fetcher


def add_site(engine, ingestor, fetcher, site, lang="en"):
    with engine.begin() as c:
        site_id = c.execute(insert(t.sites).values(name=lang, base_url=site.url, public_key=lang, default_language=lang, settings={}, created_at=utcnow())).inserted_primary_key[0]
    ingestor.crawl_site(Scope(site_id), site.url, fetcher, CrawlConfig(delay_seconds=0))
    return site_id


def add_rival(engine, ingestor, fetcher, site_id, site):
    with engine.begin() as c:
        comp = c.execute(insert(t.competitors).values(site_id=site_id, name="rival", base_url=site.url, enabled=True, created_at=utcnow())).inserted_primary_key[0]
    ingestor.crawl_site(Scope(site_id, Corpus.COMPETITOR, comp), site.url, fetcher, CrawlConfig(delay_seconds=0))
    return comp


def test_competitor_report_compares_and_cites(setup, sites):
    engine, store, ingestor, fetcher = setup
    site_id = add_site(engine, ingestor, fetcher, sites["en"])
    comp = add_rival(engine, ingestor, fetcher, site_id, sites["competitor"])
    report = CompetitorAnalyzer(store).full_report(site_id, comp)

    matched = report["products"]["matched"]
    assert len(matched) == 1 and matched[0]["name"] == "Laptop A"
    assert matched[0]["own"]["price"] == "999.00" and matched[0]["competitor"]["price"] == "899.00"
    assert matched[0]["price_difference"] == -100.0
    assert matched[0]["competitor"]["url"].startswith(sites["competitor"].url) and matched[0]["competitor"]["collected_at"]

    assert [p["name"] for p in report["products"]["only_competitor"]] == ["Drone Q"]
    assert "Headphones B" in [p["name"] for p in report["products"]["only_own"]]

    topics = {g["topic"] for g in report["content_gaps"]["gaps"]}
    assert "drone" in topics
    assert all(e["url"] and e["collected_at"] for g in report["content_gaps"]["gaps"] for e in g["examples"])
    assert any("Drone photography" in i["competitor_title"] for i in report["blog_ideas"]["ideas"])
    assert report["structure"]["competitor"]["product"] == 2


def test_competitor_robots_rules_are_enforced(setup, sites):
    engine, store, ingestor, fetcher = setup
    site_id = add_site(engine, ingestor, fetcher, sites["en"])
    rival = sites["competitor"]
    rival.extra_responses["/robots.txt"] = (200, {}, b"User-agent: *\nDisallow: /\n")
    comp = add_rival(engine, ingestor, fetcher, site_id, rival)
    assert store.list_docs(Scope(site_id, Corpus.COMPETITOR, comp)) == []
    assert "/products/drone-q" not in rival.hits


def test_price_difference_not_computed_across_currencies(setup, sites):
    engine, store, ingestor, fetcher = setup
    site_id = add_site(engine, ingestor, fetcher, sites["en"])
    rival = sites["competitor"]
    rival.pages["/products/laptop-a"] = rival.pages["/products/laptop-a"].replace('"USD"', '"EUR"')
    comp = add_rival(engine, ingestor, fetcher, site_id, rival)
    assert CompetitorAnalyzer(store).compare_products(site_id, comp)["matched"][0]["price_difference"] is None


def test_question_generation_has_evidence_for_each_question(setup, sites):
    engine, store, ingestor, fetcher = setup
    site_id = add_site(engine, ingestor, fetcher, sites["fa"], "fa")
    items = generate_questions(store, site_id, default_language="fa")
    kinds = {i.kind for i in items}
    assert {"price", "stock", "section", "out_of_scope"} <= kinds
    assert all(i.source_url for i in items if i.kind != "out_of_scope")
    assert any("گوشی ایکس" in i.question and i.reference == "25000000" for i in items)


@pytest.mark.parametrize("lang", ["fa", "ar", "en"])
def test_evaluation_produces_real_metrics(setup, sites, lang):
    engine, store, ingestor, fetcher = setup
    site_id = add_site(engine, ingestor, fetcher, sites[lang], lang)
    retriever = Retriever(store, NoEmbedder())
    result = Evaluator(store, retriever, ChatEngine(store, retriever)).run(site_id, default_language=lang)
    m = result["metrics"]
    assert m["questions"] >= 6
    assert m["no_answer_precision"] == 1.0, [i for i in result["items"] if i["kind"] == "out_of_scope" and not i["passed"]]
    assert m["retrieval_accuracy"] >= 0.8, [i for i in result["items"] if i["retrieved"] is False]
    assert m["groundedness"] == 1.0, [i for i in result["items"] if i["grounded"] is False]
    assert m["unsupported_claims"] == 0
    assert m["language_quality"] >= 0.9, [(i["question"], i["answer"]) for i in result["items"] if not i["language_ok"]]
    assert m["answered_rate"] >= 0.9, [(i["question"], i["why"]) for i in result["items"] if i["mode"] == "not_found" and i["kind"] != "out_of_scope"]
    assert m["latency_p95_ms"] >= 0 and m["source_coverage"] > 0.5


def test_evaluation_catches_a_broken_assistant(setup, sites):
    """A metric that can only ever say 'good' is worthless. This assistant makes things up and must score badly."""
    engine, store, ingestor, fetcher = setup
    site_id = add_site(engine, ingestor, fetcher, sites["en"])
    retriever = Retriever(store, NoEmbedder())

    class Liar(ChatEngine):
        def _answer(self, scope, question, history, context_doc_id, default_language):
            from core.chat.engine import Answer, Source

            doc = store.list_docs(scope)[0]
            return Answer("We guarantee delivery in 99 minutes for 12345 dollars to the moon.", "en", "llm",
                          sources=[Source(doc["url"], doc["title"], "", doc["content_type"])])

    m = Evaluator(store, retriever, Liar(store, retriever)).run(site_id, default_language="en")["metrics"]
    assert m["no_answer_precision"] == 0.0
    assert m["groundedness"] < 0.2
    assert m["unsupported_claims"] > 5
    assert m["failed"] > m["passed"]


def test_findings_report_contradictions_and_unanswered(setup, sites):
    engine, store, ingestor, fetcher = setup
    site = sites["en"]
    site.pages["/products/laptop-a-copy"] = site.pages["/products/laptop-a"].replace("999.00", "1099.00")
    site.pages["/"] += '<a href="/products/laptop-a-copy">copy</a>'
    site_id = add_site(engine, ingestor, fetcher, site)
    retriever = Retriever(store, NoEmbedder())
    extra = [EvalItem("What is the warranty on a hovercraft?", "en", "reference", site.url + "/about", "hovercraft warranty")]
    findings = Evaluator(store, retriever, ChatEngine(store, retriever)).run(site_id, default_language="en", extra_items=extra)["findings"]
    assert findings["contradictions"][0]["name"] == "Laptop A"
    assert {v["value"] for v in findings["contradictions"][0]["values"]} == {"999.00", "1099.00"}
    assert "What is the warranty on a hovercraft?" in findings["unanswered_questions"]


def test_language_check():
    assert language_ok("قیمت این گوشی بیست میلیون تومان است", "fa")
    assert not language_ok("The price of this phone is twenty dollars today", "fa")
    assert not language_ok("قیمت این گوشی بیست میلیون تومان است", "en")
    assert language_ok("Laptop A is in stock.", "en")
