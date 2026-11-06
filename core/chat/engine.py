"""The answer pipeline. It runs with or without a model.

question -> language -> small talk and guards -> FAQ -> contact -> catalog -> two-part questions -> follow-up resolution
-> retrieval and confidence gate (or an honest partial answer that names what is missing) -> structured facts (price and
stock never pass through a model) -> generation -> verification -> extractive fallback.

The dialogue rules match the WordPress edition (WIA_Chat::answer), and both are scored on shared/eval.
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
from core.text.normalize import LANGUAGE, detect_language, is_soft, match_group, normalize, sentences, soft_stems_for, subject_tokens, suffix_variants, tokenize

MAX_QUESTION_CHARS = 1000
MAX_SOURCE_CHARS = 1200
VOLATILE_TTL_HOURS = 24

_INTENTS = {name: re.compile(pattern, re.IGNORECASE) for name, pattern in LANGUAGE["intents"].items()}
_PRICE, _STOCK, _COMPARE, _CATALOG = (_INTENTS[k] for k in ("price", "stock", "compare", "catalog"))
_FOLLOWUP, _GREETING, _THANKS = (_INTENTS[k] for k in ("followup", "greeting", "thanks"))
_CONTACT = _INTENTS["contact"]
_SMALLTALK = ("farewell", "howareyou", "identity")
_CONTACT_TITLE = re.compile(r"تماس|ارتباط با|contact|اتصل|تواصل", re.IGNORECASE)
# «هزینه ارسال چقدره و گارانتی چقدره؟»: two questions in one message. Arabic «و» is attached to the next word and
# stays unsplit; a second question mark also separates.
_PARTS = re.compile(r"\s+(?:و|and|also|plus|همچنین|ضمنا)\s+|[؟?]+\s+(?=\S)", re.IGNORECASE)
SUBSTANTIVE = frozenset({"faq", "structured", "llm", "extractive"})


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
    mode: str  # faq | structured | llm | extractive | partial | clarify | not_found | smalltalk | guard
    sources: list[Source] = field(default_factory=list)
    grounded: bool = True
    support: float = 1.0
    latency_ms: int = 0
    stale: bool = False
    retrieval_mode: str = ""
    context_doc_id: int | None = None
    notes: list[str] = field(default_factory=list)
    suggestions: list[str] = field(default_factory=list)  # follow-up questions or page titles to offer as buttons

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


def to_jalali(year: int, month: int, day: int) -> tuple[int, int, int]:
    """Gregorian to the Persian calendar, using the standard arithmetic conversion."""
    days_before = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]
    y = year + 1 if month > 2 else year
    days = 355666 + 365 * year + (y + 3) // 4 - (y + 99) // 100 + (y + 399) // 400 + day + days_before[month - 1]
    jy = -1595 + 33 * (days // 12053)
    days %= 12053
    jy += 4 * (days // 1461)
    days %= 1461
    if days > 365:
        jy += (days - 1) // 365
        days = (days - 1) % 365
    if days < 186:
        return jy, 1 + days // 31, 1 + days % 31
    return jy, 7 + (days - 186) // 30, 1 + (days - 186) % 30


def fmt_date(value: datetime, lang: str) -> str:
    if lang == "fa":
        jy, jm, jd = to_jalali(value.year, value.month, value.day)
        return f"{jy}/{jm:02d}/{jd:02d}".translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹"))
    return value.strftime("%Y-%m-%d")


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

    def _answer(self, scope: Scope, question: str, history: list[ChatTurn], context_doc_id: int | None, default_language: str, *, split: bool = True) -> Answer:
        question = question.strip()
        lang = detect_language(question, default_language)
        normalized = normalize(question)
        if not question:
            return Answer(t(lang, "greeting"), lang, "smalltalk")
        if _GREETING.match(question):
            return Answer(t(lang, "greeting"), lang, "smalltalk", context_doc_id=context_doc_id)
        if _THANKS.match(question):
            return Answer(t(lang, "thanks"), lang, "smalltalk", context_doc_id=context_doc_id)
        for intent in _SMALLTALK:
            if _INTENTS[intent].search(normalized):
                return Answer(t(lang, intent), lang, "smalltalk", context_doc_id=context_doc_id)
        if looks_like_injection(question) or probes_for_secrets(question):
            return Answer(t(lang, "guard"), lang, "guard", context_doc_id=context_doc_id)

        faq = self._match_faq(scope.site_id, question, lang)
        if faq:
            return Answer(faq["answer"], lang, "faq", notes=[f"faq:{faq['id']}"], context_doc_id=context_doc_id)

        if _CONTACT.search(normalized):
            contact = self._contact_answer(scope, question, lang)
            if contact:
                return contact

        if _CATALOG.search(normalized) or _CATALOG.search(question):
            catalog = self._catalog_answer(scope, lang)
            if catalog:
                return catalog

        if split and not _COMPARE.search(normalized):
            combined = self._multi_part(scope, question, history, context_doc_id, lang)
            if combined:
                return combined

        wants_price = bool(_PRICE.search(normalized))
        wants_stock = bool(_STOCK.search(normalized))
        query = self._resolve_followup(question, history, context_doc_id, scope)
        retrieval = self.retriever.search(scope, query, limit=5)
        # Not answerable alone, so it may rely on the conversation («چند مگاپیکسل دوربینش هست؟»). A word the site
        # has never seen cannot be supplied by the conversation: adding the previous subject would only dilute it.
        if not retrieval.confident and query == question and (history or context_doc_id is not None) and not self.store.unknown_terms(scope, question):
            expanded = f"{self._context_subject(history, context_doc_id, scope)} {question}".strip()
            retry = self.retriever.search(scope, expanded, limit=5)
            if retry.confident:
                query, retrieval = expanded, retry
        # «گارانتی داره؟» after a product looks like a follow-up, but it names its own subject. When the question alone
        # is answered confidently and the product page says nothing about that subject, the question stands alone.
        if query != question and retrieval.hits:
            alone = self.retriever.search(scope, question, limit=5)
            soft = soft_stems_for(question)
            own = {w for w in subject_tokens(question) if not is_soft(w, soft)}  # «قیمتش» refers back like «قیمت»
            if alone.confident and own and not self.store.unknown_terms(scope, question) and own - set(retrieval.hits[0].matched):
                query, retrieval = question, alone
        hits = self._prefer_language([h for h in retrieval.hits if h.corpus == Corpus.OWN.value], lang)  # defence in depth
        # Each product holds only part of a comparison's words, so the gate would refuse it. Requiring two
        # products that are really named in the question is the stricter check here.
        if hits and _COMPARE.search(normalized):
            compared = self._compare(scope, hits, query, lang)
            if compared:
                compared.retrieval_mode = retrieval.mode
                return compared

        if not hits or not retrieval.confident:
            if hits and (wants_price or wants_stock):
                # «Is the Cold Brew kit in stock?»: «kit» is unknown, but the product is named without doubt.
                named = self._pick_product(scope, hits, query)
                if named:
                    fact = self._product_fact(scope, [named], query, lang, wants_price, wants_stock, context_doc_id)
                    if fact:
                        missing = self._display_words(question, self.store.unknown_terms(scope, question))
                        if missing:
                            fact.text = t(lang, "partial_quote", missing=self._join(missing, lang)) + "\n" + fact.text
                            fact.mode = "partial"
                        fact.retrieval_mode = retrieval.mode
                        return fact
            return self._miss(scope, question, lang, hits, retrieval.mode, context_doc_id)

        if wants_price or wants_stock:
            structured = self._product_fact(scope, hits, query, lang, wants_price, wants_stock, context_doc_id)
            if structured:
                structured.retrieval_mode = retrieval.mode
                return structured
            choice = self._product_choice(scope, hits, question, lang, wants_price)
            if choice:
                choice.retrieval_mode = retrieval.mode
                return choice

        # Stock, and a product's price, come only from structured data. When that could not be resolved, the model is
        # not asked: a model shown a product page will happily say «موجود است» about a product that is not.
        if wants_stock or (wants_price and hits[0].content_type == "product"):
            answer = self._extractive(query, hits, lang)
            answer.notes.append("model_skipped:volatile")
        else:
            answer = self._generate(question, query, hits, lang, history)
        answer.retrieval_mode = retrieval.mode
        answer.context_doc_id = hits[0].doc_id if answer.mode != "not_found" else context_doc_id
        answer.suggestions = self._related_titles(hits, hits[0].doc_id)
        if answer.mode != "not_found" and (wants_price or wants_stock or hits[0].content_type == "policy"):
            age = (datetime.now(UTC) - as_utc(hits[0].crawl_date)).total_seconds() / 3600
            if age > self.volatile_ttl_hours:
                answer.stale = True
                answer.text = f"{answer.text}\n{t(lang, 'as_of', date=fmt_date(hits[0].crawl_date, lang))}"
        return answer

    # ---- dialogue --------------------------------------------------------------
    @staticmethod
    def _join(words: list[str], lang: str) -> str:
        return (", " if lang == "en" else "، ").join(words)

    @staticmethod
    def _display_words(question: str, stems: list[str]) -> list[str]:
        """The visitor's own words whose stems are listed: «آدرستون», not the normalised «ادرس»."""
        want = set(stems)
        out: list[str] = []
        for raw in re.split(r"[\s،,؛;:!?؟.«»\"()]+", question):
            if raw and any(tok in want for tok in tokenize(raw)) and raw not in out:
                out.append(raw)
        return out

    def _related_titles(self, hits: list[ChunkHit], skip_doc: int | None, limit: int = 3) -> list[str]:
        titles: list[str] = []
        for hit in hits:
            if hit.doc_id != skip_doc and hit.coverage >= self.retriever.min_coverage and hit.title not in titles:
                titles.append(hit.title)
        return titles[:limit]

    @staticmethod
    def _prefer_language(hits: list[ChunkHit], lang: str) -> list[ChunkHit]:
        """An Arabic question answered from the Arabic page when one covers it as well as the top page does."""
        if not hits or not hits[0].language or hits[0].language == lang:
            return hits
        for i, hit in enumerate(hits[1:], 1):
            if hit.language == lang and hit.coverage >= hits[0].coverage - 0.05:
                return [hit, *hits[:i], *hits[i + 1:]]
        return hits

    def _miss(self, scope: Scope, question: str, lang: str, hits: list[ChunkHit], retrieval_mode: str, context_doc_id: int | None) -> Answer:
        """No confident answer. Say exactly which words the site does not know, and quote the nearest text when it
        covers the rest of the question, instead of a dead end."""
        subject = list(dict.fromkeys(subject_tokens(question)))
        unknown = self.store.unknown_terms(scope, question)
        known = [w for w in subject if w not in unknown]
        related: dict[int, ChunkHit] = {}
        for hit in hits:
            if hit.matched and hit.doc_id not in related and len(related) < 4:
                related[hit.doc_id] = hit
        titles = list(dict.fromkeys(h.title for h in related.values()))
        first = next(iter(related.values()), None)
        missing = self._display_words(question, unknown)
        if first and missing and known and len(unknown) < len(subject) and len(set(known) & set(first.matched)) >= 0.6 * len(known):
            quote = self._extractive(" ".join(known), [first], lang)
            more = [x for x in titles if x != first.title]
            return Answer(f"{t(lang, 'partial_quote', missing=self._join(missing, lang))}\n{quote.text}", lang, "partial", sources=quote.sources,
                          retrieval_mode=retrieval_mode, context_doc_id=first.doc_id, suggestions=more)
        if related and missing and known:
            text = t(lang, "partial", missing=self._join(missing, lang), found=self._join(self._display_words(question, known), lang))
        elif related:
            text = t(lang, "near")
        else:
            text = self.not_found_text.get(lang) or (t(lang, "not_found_word", words=self._join(missing, lang)) if missing else t(lang, "not_found"))
        return Answer(text, lang, "not_found", retrieval_mode=retrieval_mode, context_doc_id=context_doc_id, suggestions=titles)

    def _multi_part(self, scope: Scope, question: str, history: list[ChatTurn], context_doc_id: int | None, lang: str) -> Answer | None:
        """Two questions in one message, each answered from its own page. Only when one page does not already
        cover the whole message («اشتراک چقدر تخفیف داره و میشه لغوش کرد؟» is one page)."""
        parts = [p.strip(" ،,؟?") for p in _PARTS.split(question)]
        parts = [p for p in parts if subject_tokens(p)]
        if len(parts) < 2:
            return None
        whole = self.retriever.search(scope, question, limit=5)
        subject = set(subject_tokens(question)) - set(self.store.unknown_terms(scope, question))
        if whole.confident and whole.hits and subject <= set(whole.hits[0].matched):
            return None
        answers = [self._answer(scope, p, history, context_doc_id, lang, split=False) for p in parts]
        good = [a for a in answers if a.mode in SUBSTANTIVE]
        if not good or len({a.sources[0].url if a.sources else a.text for a in good}) < len(good):
            return None
        lines, sources, suggestions = [], [], []
        for part, answer in zip(parts, answers, strict=True):
            if answer.mode in SUBSTANTIVE:
                lines.append(answer.text)
                sources += [s for s in answer.sources if s not in sources]
            else:
                words = self._display_words(part, subject_tokens(part)) or [part]
                lines.append(t(lang, "not_found_word", words=self._join(words, lang)))
        modes = {a.mode for a in good}
        return Answer("\n\n".join(lines), lang, modes.pop() if len(modes) == 1 else "extractive", sources=sources,
                      context_doc_id=good[-1].context_doc_id, notes=[f"parts:{len(parts)}"], suggestions=suggestions,
                      stale=any(a.stale for a in good))

    def _contact_answer(self, scope: Scope, question: str, lang: str) -> Answer | None:
        """«شماره تلفنتون چنده؟»: quote the sentence of the site's contact page that answers it."""
        hits = self.retriever.search(scope, question, limit=8).hits
        hit = next((h for h in hits if h.content_type == "contact" or _CONTACT_TITLE.search(h.title)), None)
        if hit is None:
            docs = self.store.list_docs(scope, content_type="contact", limit=1) or [
                d for d in self.store.list_docs(scope, limit=300) if _CONTACT_TITLE.search(d["title"] or "")][:1]
            if not docs:
                return None
            chunks = self.store.doc_chunks(docs[0]["id"])
            found = self.store.hits_for_chunks(scope, [c["id"] for c in chunks[:1]])
            hit = next(iter(found.values()), None)
            if hit is None:
                return None
        answer = self._extractive(question, [hit], lang)
        answer.context_doc_id = hit.doc_id
        return answer

    # ---- FAQ -------------------------------------------------------------------
    def _match_faq(self, site_id: int, question: str, lang: str) -> dict | None:
        tokens = set(tokenize(question))
        normalized = f" {normalize(question)} "
        if not tokens:
            return None
        asked = {v for w in tokens for v in (match_group(w) | set(suffix_variants(w, unknown=True)))}
        best, best_score = None, 0.0
        for faq in self.store.faqs(site_id):
            if faq["language"] and faq["language"] != lang:
                continue
            keywords = [k.strip() for k in re.split(r"[،,\n]", faq["keywords"] or "") if k.strip()]
            # «دانشجوها» matches the keyword «دانشجو»: keywords are compared as stems, not only as text.
            if any(f" {normalize(k)} " in normalized or (tokenize(k) and all(match_group(w) & asked for w in tokenize(k))) for k in keywords):
                return faq
            candidate = set(tokenize(faq["question"]))
            if not candidate:
                continue
            overlap = len(tokens & candidate) / len(tokens | candidate)
            if overlap > best_score:
                best, best_score = faq, overlap
        return best if best_score >= 0.6 else None

    # ---- follow-ups ------------------------------------------------------------
    def _context_subject(self, history: list[ChatTurn], context_doc_id: int | None, scope: Scope) -> str:
        if context_doc_id:
            doc = self.store.get_doc(scope, context_doc_id)
            if doc:
                product = (doc.get("structured") or {}).get("product", {})
                return product.get("name") or doc["title"]
        for turn in reversed(history):
            if turn.role == "user":
                return turn.text
        return ""

    def _is_followup(self, question: str) -> bool:
        """«موجوده؟» names no subject and refers back. «شرایط ارسال چیست؟» has its own subject and must stand alone."""
        subject_words = subject_tokens(question)
        return not subject_words or (len(subject_words) <= 1 and bool(_FOLLOWUP.search(normalize(question))))

    def _resolve_followup(self, question: str, history: list[ChatTurn], context_doc_id: int | None, scope: Scope) -> str:
        """Expanded with the previous subject when it refers back. No model rewrites the question."""
        if (history or context_doc_id is not None) and self._is_followup(question):
            return f"{self._context_subject(history, context_doc_id, scope)} {question}".strip()
        return question

    # ---- structured facts ------------------------------------------------------
    def _catalog_answer(self, scope: Scope, lang: str) -> Answer | None:
        docs = self.store.list_docs(scope, content_type="product", limit=8)
        if not docs:
            return None
        names = [(d["structured"].get("product", {}).get("name") or d["title"]) for d in docs]
        text = t(lang, "products_head") + "\n" + "\n".join(f"• {n}" for n in names)
        sources = [Source(d["url"], d["title"], as_utc(d["crawl_date"]).isoformat(), d["content_type"]) for d in docs]
        return Answer(text, lang, "structured", sources=sources)

    def _distinctive_products(self, scope: Scope, query: str) -> dict[int, int]:
        """Products named by a word only their own name contains: «روبوستا» names «دانه قهوه روبوستا» although
        «دانه» and «قهوه» are in other names too. Returns doc id -> number of such words in the question."""
        names = {d["id"]: set(tokenize((d["structured"] or {}).get("product", {}).get("name") or d["title"])) for d in self.store.list_docs(scope, content_type="product", limit=2000)}
        df: dict[str, int] = {}
        for tokens in names.values():
            for token in tokens:
                df[token] = df.get(token, 0) + 1
        asked = {s for w in tokenize(query) for s in match_group(w)}
        out = {}
        for doc_id, tokens in names.items():
            own = {w for w in tokens if df[w] == 1 and not w.isdigit()} & asked
            if own:
                out[doc_id] = len(own)
        return out

    def _pick_product(self, scope: Scope, hits: list[ChunkHit], query: str) -> ChunkHit | None:
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
        if best_ratio >= 0.5:
            return best
        named = self._distinctive_products(scope, query)
        top = max(named.values(), default=0)
        winners = [d for d, n in named.items() if n == top]
        if len(winners) == 1:
            return next((h for h in hits if h.doc_id == winners[0] and h.structured.get("product")), None)
        return None

    def _asks_beyond(self, scope: Scope, hit: ChunkHit, query: str) -> bool:
        """«ضمانت دستگاه اسپرسو چقدره؟» names a product but asks about its warranty: «چقدره» is not always a price.
        A known word outside the product name means the question is about something else."""
        name = set(tokenize((hit.structured.get("product") or {}).get("name") or hit.title))
        unknown = set(self.store.unknown_terms(scope, query))
        soft = soft_stems_for(query)
        return any(not (match_group(w) & name) and w not in unknown and not is_soft(w, soft) for w in subject_tokens(query))

    def _product_choice(self, scope: Scope, hits: list[ChunkHit], question: str, lang: str, with_price: bool) -> Answer | None:
        """«قیمت کوله پشتی چنده؟» matches several products and names none: list them, with prices, and ask which."""
        products: dict[int, ChunkHit] = {}
        for hit in hits:
            if hit.content_type == "product" and hit.structured.get("product") and hit.coverage >= self.retriever.min_coverage:
                products.setdefault(hit.doc_id, hit)
        if len(products) < 2:
            return None
        topic = self._join(self._display_words(question, subject_tokens(question)), lang) or question
        lines, names = [t(lang, "clarify_many", topic=topic)], []
        for hit in list(products.values())[:5]:
            product = hit.structured["product"]
            name = product.get("name") or hit.title
            if name in names:
                continue
            names.append(name)
            price = ""
            if with_price and product.get("price"):
                currency = CURRENCY.get(product.get("currency", ""), {}).get(lang, product.get("currency", ""))
                price = f": {fmt_number(product['price'], lang)} {currency}".rstrip()
            lines.append(f"• {name}{price}")
        if len(names) < 2:
            return None
        return Answer("\n".join(lines), lang, "clarify", sources=[self._source(h) for h in products.values()][:5], suggestions=names[:4])

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
        hit = self._pick_product(scope, hits, query)
        if not hit and context_doc_id:
            hit = next((h for h in hits if h.doc_id == context_doc_id and h.structured.get("product")), None)
        if not hit or self._asks_beyond(scope, hit, query):
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
        named = self._distinctive_products(scope, query)
        products: dict[int, ChunkHit] = {}
        for hit in hits:
            product = hit.structured.get("product") if hit.content_type == "product" else None
            name = set(tokenize(product.get("name") or hit.title)) if product else set()
            if name and (len(name & q_tokens) / len(name) >= 0.5 or hit.doc_id in named):
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
        # Synonyms and colloquial forms count, so «گارانتی» picks the sentence that says «ضمانت».
        q_tokens = {s for w in tokenize(query) for s in match_group(w)}
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
        # Only sources that hold most of what the question asks. A weakly matching page adds tokens and invites the
        # model to merge facts that belong to different things.
        floor = 0.5 * max((h.coverage for h in hits), default=0.0)
        used = [h for h in hits[:4] if h is hits[0] or h.coverage >= floor]
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
            # «قهوه» alone: a bare topic word with confident sources. The model sees no question to answer, but the
            # visitor wants to know about that topic, so quote the site as the no-model path does.
            if len(set(subject_tokens(question))) <= 1 and len(tokenize(question)) <= 2:
                fallback = self._extractive(query, hits, lang)
                fallback.notes.append("llm_declined_topic")
                return fallback
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
