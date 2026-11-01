"""The answer pipeline. It runs with or without a model.

question -> language -> guards -> FAQ -> follow-up resolution -> retrieval and confidence gate -> structured facts
(price and stock never pass through a model) -> generation -> verification -> extractive fallback.
"""

from __future__ import annotations

import re
import time
from collections.abc import Callable
from dataclasses import asdict, dataclass, field
from datetime import UTC, datetime

from core.chat.messages import CURRENCY, LANGUAGE_NAMES, NOT_FOUND_TOKEN, SYSTEM_PROMPT, USER_PROMPT, t
from core.chat.verify import strip_citations, verify_answer
from core.knowledge.store import ChunkHit, Corpus, Scope, SqlKnowledgeStore
from core.llm.providers import LLMError, LLMProvider, Message, NoLLM
from core.retrieval.hybrid import Retriever
from core.security.injection import looks_like_injection, probes_for_secrets, wrap_untrusted
from core.text.normalize import LANGUAGE, detect_language, normalize, sentences, tokenize

MAX_QUESTION_CHARS = 1000
MAX_SOURCE_CHARS = 1200
VOLATILE_TTL_HOURS = 24

_INTENTS = {name: re.compile(pattern, re.IGNORECASE) for name, pattern in LANGUAGE["intents"].items()}
_PRICE, _STOCK, _COMPARE, _CATALOG = (_INTENTS[k] for k in ("price", "stock", "compare", "catalog"))
_FOLLOWUP, _GREETING, _THANKS = (_INTENTS[k] for k in ("followup", "greeting", "thanks"))


@dataclass
class Source:
    url: str
    title: str
    crawled_at: str
    content_type: str


@dataclass
class ChatTurn:
    role: str
    text: str


@dataclass
class Answer:
    text: str
    language: str
    mode: str  # faq | structured | llm | extractive | not_found | smalltalk | guard
    sources: list[Source] = field(default_factory=list)
    grounded: bool = True
    support: float = 1.0
    latency_ms: int = 0
    stale: bool = False
    retrieval_mode: str = ""
    context_doc_id: int | None = None
    notes: list[str] = field(default_factory=list)

    def as_dict(self) -> dict:
        return asdict(self)


def fmt_number(value: str, lang: str) -> str:
    try:
        number = float(value)
    except (TypeError, ValueError):
        return str(value)
    text = f"{number:,.0f}" if number == int(number) else f"{number:,.2f}"
    if lang == "fa":
        return text.translate(str.maketrans("0123456789,", "۰۱۲۳۴۵۶۷۸۹٬"))
    if lang == "ar":
        return text
    return text


def fmt_date(value: datetime, lang: str) -> str:
    text = value.strftime("%Y-%m-%d")
    return text.translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹")) if lang == "fa" else text


def as_utc(value: datetime) -> datetime:
    return value.replace(tzinfo=UTC) if value.tzinfo is None else value


class ChatEngine:
    def __init__(
        self, store: SqlKnowledgeStore, retriever: Retriever, llm: LLMProvider | None = None, *,
        refresh: Callable[[Scope, str], bool] | None = None, volatile_ttl_hours: float = VOLATILE_TTL_HOURS,
        not_found_text: dict[str, str] | None = None,
    ) -> None:
        self.store = store
        self.retriever = retriever
        self.llm = llm or NoLLM()
        self.refresh = refresh
        self.volatile_ttl_hours = volatile_ttl_hours
        self.not_found_text = not_found_text or {}

    # The public answer path can only ever see the site's own corpus. This is the single place a chat scope is made.
    @staticmethod
    def _scope(site_id: int) -> Scope:
        return Scope(site_id=site_id, corpus=Corpus.OWN)

    def answer(self, site_id: int, question: str, *, history: list[ChatTurn] | None = None, context_doc_id: int | None = None, default_language: str = "fa") -> Answer:
        started = time.monotonic()
        result = self._answer(self._scope(site_id), question[:MAX_QUESTION_CHARS], history or [], context_doc_id, default_language)
        result.latency_ms = int((time.monotonic() - started) * 1000)
        return result

    def _not_found(self, lang: str, retrieval_mode: str = "", context_doc_id: int | None = None) -> Answer:
        return Answer(self.not_found_text.get(lang) or t(lang, "not_found"), lang, "not_found", retrieval_mode=retrieval_mode, context_doc_id=context_doc_id)

    def _answer(self, scope: Scope, question: str, history: list[ChatTurn], context_doc_id: int | None, default_language: str) -> Answer:
        question = question.strip()
        lang = detect_language(question, default_language)
        if not question:
            return Answer(t(lang, "greeting"), lang, "smalltalk")
        if _GREETING.match(question):
            return Answer(t(lang, "greeting"), lang, "smalltalk", context_doc_id=context_doc_id)
        if _THANKS.match(question):
            return Answer(t(lang, "thanks"), lang, "smalltalk", context_doc_id=context_doc_id)
        if looks_like_injection(question) or probes_for_secrets(question):
            return Answer(t(lang, "guard"), lang, "guard", context_doc_id=context_doc_id)

        faq = self._match_faq(scope.site_id, question, lang)
        if faq:
            return Answer(faq["answer"], lang, "faq", notes=[f"faq:{faq['id']}"], context_doc_id=context_doc_id)

        normalized = normalize(question)
        if _CATALOG.search(normalized) or _CATALOG.search(question):
            catalog = self._catalog_answer(scope, lang)
            if catalog:
                return catalog

        wants_price = bool(_PRICE.search(normalized))
        wants_stock = bool(_STOCK.search(normalized))
        query = self._resolve_followup(question, history, context_doc_id, scope)
        retrieval = self.retriever.search(scope, query, limit=5)
        hits = [h for h in retrieval.hits if h.corpus == Corpus.OWN.value]  # defence in depth
        # Each product holds only part of a comparison's words, so the gate would refuse it. Requiring two
        # products that are really named in the question is the stricter check here.
        if hits and _COMPARE.search(normalized):
            compared = self._compare(scope, hits, query, lang)
            if compared:
                compared.retrieval_mode = retrieval.mode
                return compared

        if not hits or not retrieval.confident:
            return self._not_found(lang, retrieval.mode, context_doc_id)

        if wants_price or wants_stock:
            structured = self._product_fact(scope, hits, query, lang, wants_price, wants_stock, context_doc_id)
            if structured:
                structured.retrieval_mode = retrieval.mode
                return structured

        answer = self._generate(question, query, hits, lang, history)
        answer.retrieval_mode = retrieval.mode
        answer.context_doc_id = hits[0].doc_id
        if wants_price or wants_stock or hits[0].content_type == "policy":
            age = (datetime.now(UTC) - as_utc(hits[0].crawl_date)).total_seconds() / 3600
            if age > self.volatile_ttl_hours:
                answer.stale = True
                answer.text = f"{answer.text}\n{t(lang, 'as_of', date=fmt_date(hits[0].crawl_date, lang))}"
        return answer

    # ---- FAQ -------------------------------------------------------------------
    def _match_faq(self, site_id: int, question: str, lang: str) -> dict | None:
        tokens = set(tokenize(question))
        normalized = f" {normalize(question)} "
        if not tokens:
            return None
        best, best_score = None, 0.0
        for faq in self.store.faqs(site_id):
            if faq["language"] and faq["language"] != lang:
                continue
            keywords = [k.strip() for k in re.split(r"[،,\n]", faq["keywords"] or "") if k.strip()]
            if any(f" {normalize(k)} " in normalized for k in keywords):
                return faq
            candidate = set(tokenize(faq["question"]))
            if not candidate:
                continue
            overlap = len(tokens & candidate) / len(tokens | candidate)
            if overlap > best_score:
                best, best_score = faq, overlap
        return best if best_score >= 0.6 else None

    # ---- follow-ups ------------------------------------------------------------
    def _resolve_followup(self, question: str, history: list[ChatTurn], context_doc_id: int | None, scope: Scope) -> str:
        """A question that refers back («قیمتش چنده؟») is expanded with the previous subject, never rewritten by a model."""
        if not history and context_doc_id is None:
            return question
        tokens = tokenize(question)
        if not _FOLLOWUP.search(normalize(question)) and len(tokens) > 2:
            return question
        subject = ""
        if context_doc_id:
            doc = self.store.get_doc(scope, context_doc_id)
            if doc:
                product = (doc.get("structured") or {}).get("product", {})
                subject = product.get("name") or doc["title"]
        if not subject:
            for turn in reversed(history):
                if turn.role == "user":
                    subject = turn.text
                    break
        return f"{subject} {question}".strip()

    # ---- structured facts ------------------------------------------------------
    def _catalog_answer(self, scope: Scope, lang: str) -> Answer | None:
        docs = self.store.list_docs(scope, content_type="product", limit=8)
        if not docs:
            return None
        names = [(d["structured"].get("product", {}).get("name") or d["title"]) for d in docs]
        text = t(lang, "products_head") + "\n" + "\n".join(f"• {n}" for n in names)
        sources = [Source(d["url"], d["title"], as_utc(d["crawl_date"]).isoformat(), d["content_type"]) for d in docs]
        return Answer(text, lang, "structured", sources=sources)

    def _pick_product(self, hits: list[ChunkHit], query: str) -> ChunkHit | None:
        """Choose only a product whose name is really in the question. A nearby product must not answer for another."""
        q_tokens = set(tokenize(query))
        best, best_ratio = None, 0.0
        for hit in hits:
            product = hit.structured.get("product") if hit.content_type == "product" else None
            if not product:
                continue
            name_tokens = set(tokenize(product.get("name") or hit.title))
            if not name_tokens:
                continue
            ratio = len(name_tokens & q_tokens) / len(name_tokens)
            if ratio > best_ratio:
                best, best_ratio = hit, ratio
        return best if best_ratio >= 0.5 else None

    def _source(self, hit: ChunkHit) -> Source:
        return Source(hit.url, hit.title, as_utc(hit.crawl_date).isoformat(), hit.content_type)

    def _refreshed(self, scope: Scope, hit: ChunkHit) -> tuple[dict, datetime, bool]:
        """Volatile data is re-read from the live page when old. If that fails, the answer says how old it is."""
        age = (datetime.now(UTC) - as_utc(hit.crawl_date)).total_seconds() / 3600
        if age > self.volatile_ttl_hours and self.refresh:
            try:
                if self.refresh(scope, hit.url):
                    doc = self.store.get_doc(scope, hit.doc_id)
                    if doc and doc["status"] == "active":
                        return (doc["structured"] or {}).get("product", {}), doc["crawl_date"], False
            except Exception:  # noqa: BLE001 - a failed refresh falls back to the dated answer
                pass
        return hit.structured.get("product", {}), hit.crawl_date, age > self.volatile_ttl_hours

    def _product_fact(self, scope: Scope, hits: list[ChunkHit], query: str, lang: str, wants_price: bool, wants_stock: bool, context_doc_id: int | None) -> Answer | None:
        hit = self._pick_product(hits, query)
        if not hit and context_doc_id:
            hit = next((h for h in hits if h.doc_id == context_doc_id and h.structured.get("product")), None)
        if not hit:
            return None
        product, checked_at, stale = self._refreshed(scope, hit)
        name = product.get("name") or hit.title
        lines: list[str] = []
        if wants_price:
            if product.get("price"):
                currency = CURRENCY.get(product.get("currency", ""), {}).get(lang, product.get("currency", ""))
                lines.append(t(lang, "price", name=name, price=f"{fmt_number(product['price'], lang)} {currency}".strip()))
            else:
                lines.append(t(lang, "no_price", name=name))
        if wants_stock:
            key = {"in_stock": "stock_in", "out_of_stock": "stock_out", "preorder": "stock_pre"}.get(product.get("availability", ""), "stock_unknown")
            lines.append(t(lang, key, name=name))
        text = "\n".join(lines) + f"\n{t(lang, 'as_of', date=fmt_date(checked_at, lang))}"
        if stale:
            text += f"\n{t(lang, 'stale')}"
        return Answer(text, lang, "structured", sources=[self._source(hit)], stale=stale, context_doc_id=hit.doc_id)

    def _compare(self, scope: Scope, hits: list[ChunkHit], query: str, lang: str) -> Answer | None:
        q_tokens = set(tokenize(query))
        products: dict[int, ChunkHit] = {}
        for hit in hits:
            product = hit.structured.get("product") if hit.content_type == "product" else None
            name = set(tokenize(product.get("name") or hit.title)) if product else set()
            if name and len(name & q_tokens) / len(name) >= 0.5:
                products.setdefault(hit.doc_id, hit)
        if len(products) < 2:
            return None
        a, b = list(products.values())[:2]
        lines = [t(lang, "compare_head", a=a.structured["product"].get("name", a.title), b=b.structured["product"].get("name", b.title))]
        for hit in (a, b):
            product = hit.structured["product"]
            currency = CURRENCY.get(product.get("currency", ""), {}).get(lang, product.get("currency", ""))
            price = f"{fmt_number(product['price'], lang)} {currency}".strip() if product.get("price") else "-"
            lines.append(f"• {product.get('name', hit.title)}: {price} - {sentences(hit.text)[0] if hit.text else ''}")
        lines.append(t(lang, "as_of", date=fmt_date(a.crawl_date, lang)))
        return Answer("\n".join(lines), lang, "structured", sources=[self._source(a), self._source(b)])

    # ---- generation ------------------------------------------------------------
    def _extractive(self, query: str, hits: list[ChunkHit], lang: str) -> Answer:
        """No model, or the model failed verification: quote the best sentences from the site, labelled with their source."""
        q_tokens = set(tokenize(query))
        best = hits[0]
        scored = []
        for index, sentence in enumerate(sentences(best.text)):
            overlap = len(q_tokens & set(tokenize(sentence)))
            scored.append((overlap, -index, sentence))
        scored.sort(reverse=True)
        picked = sorted(scored[:2], key=lambda s: -s[1]) if scored else []
        quote = " ".join(s for _, _, s in picked if s) or best.text[:300]
        head = t(lang, "from_source", title=best.title)
        if best.language and best.language != lang:
            head = t(lang, "other_lang") + "\n" + head
        return Answer(f"{head}\n{quote}", lang, "extractive", sources=[self._source(best)], grounded=True, support=1.0)

    def _generate(self, question: str, query: str, hits: list[ChunkHit], lang: str, history: list[ChatTurn]) -> Answer:
        if isinstance(self.llm, NoLLM):
            return self._extractive(query, hits, lang)
        used = hits[:4]
        block, fence = wrap_untrusted([
            f"Title: {h.title}\nSection: {h.heading}\nText: {h.text[:MAX_SOURCE_CHARS]}" for h in used
        ])
        recent = "\n".join(f"{turn.role}: {turn.text[:200]}" for turn in history[-4:]) or "(none)"
        messages = [
            Message("system", SYSTEM_PROMPT.format(fence=fence, language=LANGUAGE_NAMES.get(lang, "English"), not_found_token=NOT_FOUND_TOKEN)),
            Message("user", USER_PROMPT.format(sources=block, history=recent, question=question)),
        ]
        try:
            raw = self.llm.generate(messages)
        except LLMError as exc:
            fallback = self._extractive(query, hits, lang)
            fallback.notes.append(f"llm_error:{exc}")
            return fallback
        if NOT_FOUND_TOKEN in raw or not raw.strip():
            return self._not_found(lang, context_doc_id=None)

        # Cite only what the model actually cited; fall back to the top source if it cited nothing.
        cited = sorted({int(n) for n in re.findall(r"\[\s*(\d+)\s*\]", raw) if 0 < int(n) <= len(used)})
        cited_hits = [used[i - 1] for i in cited] or used[:1]
        source_texts = [f"{h.title} {h.heading} {h.text}" for h in used]
        verdict = verify_answer(raw, source_texts + [question])
        if not verdict.grounded:
            fallback = self._extractive(query, hits, lang)
            fallback.notes.append("llm_rejected:" + ",".join(verdict.unsupported_numbers or ["low_support"]))
            return fallback
        return Answer(strip_citations(raw).strip(), lang, "llm", sources=[self._source(h) for h in cited_hits], grounded=True, support=verdict.support)
