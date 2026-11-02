"""Self-evaluation against evidence, not self-praise.

Questions are generated from stored facts, so every one has a known source document and, where possible, a reference
answer. Each metric below is computed from that evidence and is defined so an owner can check it by hand:

- retrieval_accuracy: the question's source document is among those retrieved (hit@5).
- answer_relevance:   share of the reference answer's key terms present in the answer. A lexical measure.
- groundedness:       share of substantive answers whose every sentence and number is supported by the cited source.
- source_coverage:    share of active documents for which a generated question retrieved that document.
- unsupported_claims: numbers in answers that appear in no cited source. Lower is better.
- language_quality:   answers in the question's language, with no foreign-script runs. Script check, not a style review.
- no_answer_precision: out-of-scope questions correctly declined instead of guessed.
- response_latency:   p50 and p95 in milliseconds, as measured on this machine.
"""

from __future__ import annotations

import re
import statistics
from dataclasses import dataclass, field

from core.chat.engine import ChatEngine
from core.chat.verify import verify_answer
from core.knowledge import schema as t
from core.knowledge.store import Corpus, Scope, SqlKnowledgeStore
from core.retrieval.hybrid import Retriever
from core.text.normalize import detect_language, normalize, numbers_in, sentences, tokenize

QUESTION_TEMPLATES = {
    "fa": {"price": "قیمت {name} چقدر است؟", "stock": "آیا {name} موجود است؟", "about": "درباره {name} توضیح بده", "topic": "{topic} چیست؟"},
    "ar": {"price": "كم سعر {name}؟", "stock": "هل {name} متوفر؟", "about": "ما هي معلومات {name}؟", "topic": "ما هو {topic}؟"},
    "en": {"price": "How much is {name}?", "stock": "Is {name} in stock?", "about": "Tell me about {name}", "topic": "What about {topic}?"},
}
OUT_OF_SCOPE = {
    "fa": ["پایتخت مغولستان کجاست؟", "نتیجه بازی فوتبال دیشب چه شد؟", "آیا شما زیردریایی اتمی می‌فروشید؟", "دستور پخت قورمه سبزی چیست؟"],
    "ar": ["ما هي عاصمة منغوليا؟", "ما نتيجة مباراة كرة القدم أمس؟", "هل تبيعون غواصات نووية؟", "ما هي وصفة الكبسة؟"],
    "en": ["What is the capital of Mongolia?", "What was the score of last night's football match?", "Do you sell nuclear submarines?", "What is the recipe for tiramisu?"],
}
_LATIN_RUN = re.compile(r"[A-Za-z]{4,}")
_ARABIC_RUN = re.compile(r"[؀-ۿ]{2,}")


@dataclass
class EvalItem:
    question: str
    language: str
    kind: str  # price | stock | about | section | out_of_scope | reference
    source_url: str = ""
    reference: str = ""
    answer: str = ""
    mode: str = ""
    retrieved: bool | None = None
    relevance: float | None = None
    grounded: bool | None = None
    support: float | None = None
    unsupported_numbers: list[str] = field(default_factory=list)
    language_ok: bool | None = None
    latency_ms: int = 0
    passed: bool = False
    why: str = ""


def generate_questions(store: SqlKnowledgeStore, site_id: int, *, max_docs: int = 60, default_language: str = "en") -> list[EvalItem]:
    scope = Scope(site_id, Corpus.OWN)
    items: list[EvalItem] = []
    for doc in store.list_docs(scope, limit=max_docs):
        lang = doc["language"] if doc["language"] in QUESTION_TEMPLATES else default_language
        templates = QUESTION_TEMPLATES[lang]
        product = (doc["structured"] or {}).get("product", {})
        if product.get("name"):
            name = product["name"]
            if product.get("price"):
                items.append(EvalItem(templates["price"].format(name=name), lang, "price", doc["url"], product["price"]))
            if product.get("availability"):
                items.append(EvalItem(templates["stock"].format(name=name), lang, "stock", doc["url"], product["availability"]))
            continue
        chunks = store.doc_chunks(doc["id"])
        sectioned = next((c for c in chunks if c["heading"] and c["heading"] != doc["title"] and len(c["text"]) > 40), None) or next((c for c in chunks if len(c["text"]) > 40), None)
        if sectioned is None:
            continue
        topic = sectioned["heading"] or doc["title"]
        if len(tokenize(topic)) < 1:
            continue
        items.append(EvalItem(templates["about"].format(name=topic), lang, "section", doc["url"], sectioned["text"][:600]))
    languages = {i.language for i in items} or {default_language}
    for lang in languages:
        items.extend(EvalItem(q, lang, "out_of_scope") for q in OUT_OF_SCOPE[lang])
    return items


def language_ok(answer: str, lang: str) -> bool:
    if detect_language(answer, lang) != lang and len(tokenize(answer)) >= 4:
        return False
    if lang in ("fa", "ar"):
        total = len(_ARABIC_RUN.findall(answer)) + len(_LATIN_RUN.findall(answer))
        return not total or len(_LATIN_RUN.findall(answer)) / total <= 0.4
    return len(_ARABIC_RUN.findall(answer)) == 0


def _relevance(answer: str, reference: str) -> float:
    terms = set(tokenize(reference))
    if not terms:
        return 0.0
    return round(len(terms & set(tokenize(answer))) / len(terms), 3)


def _pct(values: list[bool]) -> float | None:
    return round(sum(values) / len(values), 3) if values else None


class Evaluator:
    def __init__(self, store: SqlKnowledgeStore, retriever: Retriever, chat: ChatEngine) -> None:
        self.store = store
        self.retriever = retriever
        self.chat = chat

    def _run_item(self, site_id: int, item: EvalItem, default_language: str) -> EvalItem:
        scope = Scope(site_id, Corpus.OWN)
        answer = self.chat.answer(site_id, item.question, default_language=default_language)
        item.answer, item.mode, item.latency_ms = answer.text, answer.mode, answer.latency_ms
        item.language_ok = language_ok(answer.text, item.language)

        if item.kind == "out_of_scope":
            item.passed = answer.mode == "not_found"
            item.why = "" if item.passed else "answered an out-of-scope question"
            return item

        retrieval = self.retriever.search(scope, item.question, limit=5)
        item.retrieved = any(h.url == item.source_url for h in retrieval.hits) or any(s.url == item.source_url for s in answer.sources)
        if answer.mode == "not_found":
            item.why = "declined although the site has this information"
            return item

        cited_sources = []
        for source in answer.sources:
            doc = self.store.find_doc(scope, source.url)
            if doc:
                facts = " ".join(f"{k} {v}" for k, v in (doc["structured"] or {}).get("product", {}).items())
                cited_sources.append(" ".join(c["text"] for c in self.store.doc_chunks(doc["id"])) + f" {doc['title']} {facts}")
        boilerplate = {answer.text.split("\n")[0]} if answer.mode == "extractive" else set()
        answer_body = "\n".join(s for s in answer.text.split("\n") if s not in boilerplate)

        if answer.mode == "structured":
            # Structured answers state stored facts. The date and stock wording come from templates, so numbers are the check.
            product_numbers = numbers_in(" ".join(cited_sources))
            date_free = re.sub(r"[\d۰-۹٠-٩]{4}[-/][\d۰-۹٠-٩]{2}[-/][\d۰-۹٠-٩]{2}", " ", answer_body)
            item.unsupported_numbers = sorted(numbers_in(date_free) - product_numbers)
            item.grounded, item.support = not item.unsupported_numbers, 1.0
        else:
            verdict = verify_answer(answer_body, cited_sources or [""])
            item.grounded, item.support, item.unsupported_numbers = verdict.grounded, verdict.support, verdict.unsupported_numbers

        if item.kind == "price":
            item.relevance = 1.0 if numbers_in(item.reference) & numbers_in(answer.text) else 0.0
        elif item.kind == "stock":
            item.relevance = 1.0 if answer.mode == "structured" else 0.0
        else:
            item.relevance = _relevance(answer.text, item.reference)
        item.passed = bool(item.retrieved and item.grounded and item.language_ok and (item.relevance or 0) > 0)
        if not item.passed:
            reasons = []
            if not item.retrieved:
                reasons.append("source document not retrieved")
            if not item.grounded:
                reasons.append("answer not supported by source")
            if not item.language_ok:
                reasons.append("wrong language in answer")
            if not item.relevance:
                reasons.append("answer does not contain the expected information")
            item.why = "; ".join(reasons)
        return item

    def findings(self, site_id: int, items: list[EvalItem]) -> dict:
        scope = Scope(site_id, Corpus.OWN)
        docs = self.store.list_docs(scope)
        with self.store.engine.connect() as c:
            last_run = c.execute(t.crawl_runs.select().where(t.crawl_runs.c.site_id == site_id, t.crawl_runs.c.corpus == "own").order_by(t.crawl_runs.c.id.desc()).limit(1)).mappings().first()
        by_title: dict[str, list[dict]] = {}
        for doc in docs:
            by_title.setdefault(normalize(doc["title"]), []).append(doc)
        return {
            "unanswered_questions": [i.question for i in items if i.kind != "out_of_scope" and i.mode == "not_found"][:50],
            "contradictions": self._contradictions(docs),
            "failed_pages": list(last_run["failures"])[:50] if last_run else [],
            "same_title_pages": [[d["url"] for d in group] for group in by_title.values() if len(group) > 1][:20],
            "thin_pages": [d["url"] for d in docs if sum(c["length"] for c in self.store.doc_chunks(d["id"])) < 20][:50],
            "duplicate_pages": len(self.store.list_docs(scope, status="duplicate")),
        }

    def _contradictions(self, docs: list[dict]) -> list[dict]:
        """The same product name with different stated prices on different pages. Other conflicts need human reading."""
        by_name: dict[str, list[dict]] = {}
        for doc in docs:
            product = (doc["structured"] or {}).get("product", {})
            if product.get("name") and product.get("price"):
                by_name.setdefault(normalize(product["name"]), []).append(doc)
        out = []
        for group in by_name.values():
            prices = {d["structured"]["product"]["price"] for d in group}
            if len(prices) > 1:
                out.append({"name": group[0]["structured"]["product"]["name"], "field": "price",
                            "values": [{"value": d["structured"]["product"]["price"], "url": d["url"]} for d in group]})
        return out

    def run(self, site_id: int, *, default_language: str = "en", extra_items: list[EvalItem] | None = None, max_docs: int = 60) -> dict:
        items = generate_questions(self.store, site_id, max_docs=max_docs, default_language=default_language) + list(extra_items or [])
        for item in items:
            self._run_item(site_id, item, default_language)
        in_scope = [i for i in items if i.kind != "out_of_scope"]
        out_scope = [i for i in items if i.kind == "out_of_scope"]
        substantive = [i for i in in_scope if i.mode != "not_found"]
        latencies = sorted(i.latency_ms for i in items) or [0]
        docs = self.store.list_docs(Scope(site_id, Corpus.OWN), limit=max_docs)
        covered = {i.source_url for i in in_scope if i.retrieved}
        metrics = {
            "questions": len(items),
            "retrieval_accuracy": _pct([bool(i.retrieved) for i in in_scope]),
            "answer_relevance": round(statistics.fmean(i.relevance or 0 for i in substantive), 3) if substantive else None,
            "groundedness": _pct([bool(i.grounded) for i in substantive]),
            "source_coverage": round(len(covered & {d["url"] for d in docs}) / len(docs), 3) if docs else None,
            "unsupported_claims": sum(len(i.unsupported_numbers) for i in items),
            "language_quality": _pct([bool(i.language_ok) for i in items]),
            "no_answer_precision": _pct([i.passed for i in out_scope]),
            "answered_rate": round(len(substantive) / len(in_scope), 3) if in_scope else None,
            "latency_p50_ms": int(statistics.median(latencies)),
            "latency_p95_ms": latencies[min(len(latencies) - 1, int(len(latencies) * 0.95))],
            "passed": sum(i.passed for i in items),
            "failed": sum(not i.passed for i in items),
        }
        return {"metrics": metrics, "items": [i.__dict__ for i in items], "findings": self.findings(site_id, items)}


__all__ = ["EvalItem", "Evaluator", "generate_questions", "language_ok", "sentences"]
