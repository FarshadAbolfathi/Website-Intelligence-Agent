"""Knowledge storage behind a small interface. Engine logic depends on `KnowledgeStore`, not on a database.

Isolation is structural. No read or write exists without a `Scope`, every query filters on it, and the type of the
scope is what separates one site from another and a site's own content from its competitors'.
"""

from __future__ import annotations

import hashlib
import math
import re
from collections import Counter
from dataclasses import dataclass
from datetime import UTC, datetime
from enum import Enum
from typing import Protocol

from sqlalchemy import and_, delete, func, insert, select, update
from sqlalchemy.engine import Engine

from core.knowledge import schema as t
from core.text.normalize import LANGUAGE, VARIANTS, arabic_clitic_variant, looks_like_verb, match_group, normalize, soft_stems_for, stem, subject_tokens, suffix_variants, tokenize


class Corpus(str, Enum):
    OWN = "own"
    COMPETITOR = "competitor"


@dataclass(frozen=True)
class Scope:
    site_id: int
    corpus: Corpus = Corpus.OWN
    competitor_id: int | None = None

    def __post_init__(self) -> None:
        if self.corpus is Corpus.OWN and self.competitor_id is not None:
            raise ValueError("an own-site scope cannot name a competitor")


@dataclass
class ChunkDraft:
    heading: str
    text: str
    language: str = ""
    boost_terms: str = ""  # title and aliases: searchable, weighted, never shown as content


@dataclass
class ChunkHit:
    chunk_id: int
    doc_id: int
    score: float
    coverage: float
    text: str
    heading: str
    language: str
    url: str
    title: str
    content_type: str
    corpus: str
    crawl_date: datetime
    structured: dict


def utcnow() -> datetime:
    return datetime.now(UTC)


def url_key(url: str) -> str:
    return hashlib.sha256(url.encode()).hexdigest()


def term_counts(heading: str, text: str, boost_terms: str = "", language: str = "") -> Counter[str]:
    """Terms for one chunk: body tokens, heavier title tokens and joined pairs, so «لپ تاپ» matches «لپتاپ»."""
    counts: Counter[str] = Counter()
    body = tokenize(text)
    counts.update(body)
    if language == "ar":
        counts.update(v for v in map(arabic_clitic_variant, body) if v)
    for token in tokenize(f"{heading} {boost_terms}"):
        counts[token] += 4
    for a, b in zip(body, body[1:], strict=False):
        if len(a) <= 4 and len(b) <= 4 and not a.isdigit() and not b.isdigit():
            counts[a + b] += 1
    return counts


def query_terms(query: str) -> list[str]:
    tokens = list(dict.fromkeys(tokenize(query)))
    joined = [a + b for a, b in zip(tokens, tokens[1:], strict=False) if len(a) <= 4 and len(b) <= 4 and not a.isdigit() and not b.isdigit()]
    return tokens + [j for j in joined if j not in tokens]


class KnowledgeStore(Protocol):
    def find_doc(self, scope: Scope, url: str) -> dict | None: ...
    def save_doc(self, scope: Scope, url: str, fields: dict, drafts: list[ChunkDraft]) -> tuple[int, list[int]]: ...
    def touch_doc(self, doc_id: int, **fields) -> None: ...
    def set_status(self, doc_id: int, status: str, dup_of: int | None = None) -> None: ...
    def search_lexical(self, scope: Scope, query: str, limit: int = 10) -> list[ChunkHit]: ...
    def unknown_terms(self, scope: Scope, text: str) -> list[str]: ...
    def hits_for_chunks(self, scope: Scope, ids: list[int]) -> dict[int, ChunkHit]: ...


_PRICE_INTENT = re.compile(LANGUAGE["intents"]["price"], re.IGNORECASE)


class SqlKnowledgeStore:
    """SQLAlchemy Core implementation with a BM25 inverted index stored in the database.

    Postings in SQL keep memory flat: a query loads only the rows for its own terms, not an index of the whole site.
    The WordPress edition uses the same design in MySQL.
    """

    K1 = 1.4
    B = 0.7

    def __init__(self, engine: Engine) -> None:
        self.engine = engine

    # ---- documents -------------------------------------------------------------
    @staticmethod
    def _doc_filter(scope: Scope):
        conds = [t.documents.c.site_id == scope.site_id, t.documents.c.corpus == scope.corpus.value]
        if scope.corpus is Corpus.COMPETITOR and scope.competitor_id is not None:
            conds.append(t.documents.c.competitor_id == scope.competitor_id)
        return and_(*conds)

    @staticmethod
    def _chunk_filter(scope: Scope):
        conds = [t.chunks.c.site_id == scope.site_id, t.chunks.c.corpus == scope.corpus.value]
        if scope.corpus is Corpus.COMPETITOR and scope.competitor_id is not None:
            conds.append(t.chunks.c.competitor_id == scope.competitor_id)
        return and_(*conds)

    def find_doc(self, scope: Scope, url: str) -> dict | None:
        q = select(t.documents).where(self._doc_filter(scope), t.documents.c.url_key == url_key(url))
        with self.engine.connect() as c:
            row = c.execute(q).mappings().first()
        return dict(row) if row else None

    def get_doc(self, scope: Scope, doc_id: int) -> dict | None:
        q = select(t.documents).where(self._doc_filter(scope), t.documents.c.id == doc_id)
        with self.engine.connect() as c:
            row = c.execute(q).mappings().first()
        return dict(row) if row else None

    def list_docs(self, scope: Scope, *, status: str | None = "active", content_type: str | None = None, limit: int = 10000, offset: int = 0) -> list[dict]:
        q = select(t.documents).where(self._doc_filter(scope))
        if status:
            q = q.where(t.documents.c.status == status)
        if content_type:
            q = q.where(t.documents.c.content_type == content_type)
        q = q.order_by(t.documents.c.id).limit(limit).offset(offset)
        with self.engine.connect() as c:
            return [dict(r) for r in c.execute(q).mappings()]

    def save_doc(self, scope: Scope, url: str, fields: dict, drafts: list[ChunkDraft]) -> tuple[int, list[int]]:
        """Create or replace a document and its chunks in one transaction. Returns (doc_id, chunk ids)."""
        now = utcnow()
        key = url_key(url)
        with self.engine.begin() as c:
            existing = c.execute(select(t.documents.c.id, t.documents.c.version).where(self._doc_filter(scope), t.documents.c.url_key == key)).first()
            values = {"crawl_date": now, "last_checked": now, **fields}
            if existing:
                doc_id = existing.id
                values.setdefault("version", existing.version + 1)
                c.execute(update(t.documents).where(t.documents.c.id == doc_id).values(**values))
                old = [r.id for r in c.execute(select(t.chunks.c.id).where(t.chunks.c.doc_id == doc_id))]
                if old:
                    c.execute(delete(t.postings).where(t.postings.c.chunk_id.in_(old)))
                    c.execute(delete(t.embeddings).where(t.embeddings.c.chunk_id.in_(old)))
                    c.execute(delete(t.chunks).where(t.chunks.c.id.in_(old)))
            else:
                result = c.execute(insert(t.documents).values(
                    site_id=scope.site_id, corpus=scope.corpus.value, competitor_id=scope.competitor_id, url=url, url_key=key, created_at=now, **values,
                ))
                doc_id = result.inserted_primary_key[0]
            ids: list[int] = []
            for i, draft in enumerate(drafts):
                counts = term_counts(draft.heading, draft.text, draft.boost_terms, draft.language)
                chunk_id = c.execute(insert(t.chunks).values(
                    doc_id=doc_id, site_id=scope.site_id, corpus=scope.corpus.value, competitor_id=scope.competitor_id, ord=i,
                    heading=draft.heading, text=draft.text, language=draft.language, length=sum(counts.values()),
                )).inserted_primary_key[0]
                ids.append(chunk_id)
                if counts:
                    c.execute(insert(t.postings), [
                        {"site_id": scope.site_id, "corpus": scope.corpus.value, "term": term[:80], "chunk_id": chunk_id, "tf": float(tf)}
                        for term, tf in counts.items()
                    ])
        return doc_id, ids

    def touch_doc(self, doc_id: int, **fields) -> None:
        with self.engine.begin() as c:
            c.execute(update(t.documents).where(t.documents.c.id == doc_id).values(last_checked=utcnow(), **fields))

    def set_status(self, doc_id: int, status: str, dup_of: int | None = None) -> None:
        with self.engine.begin() as c:
            c.execute(update(t.documents).where(t.documents.c.id == doc_id).values(status=status, dup_of=dup_of, last_checked=utcnow()))

    def delete_scope(self, scope: Scope) -> int:
        with self.engine.begin() as c:
            ids = [r.id for r in c.execute(select(t.documents.c.id).where(self._doc_filter(scope)))]
            chunk_ids = [r.id for r in c.execute(select(t.chunks.c.id).where(t.chunks.c.doc_id.in_(ids)))] if ids else []
            for i in range(0, len(chunk_ids), 500):
                batch = chunk_ids[i:i + 500]
                c.execute(delete(t.postings).where(t.postings.c.chunk_id.in_(batch)))
                c.execute(delete(t.embeddings).where(t.embeddings.c.chunk_id.in_(batch)))
            if ids:
                c.execute(delete(t.chunks).where(t.chunks.c.doc_id.in_(ids)))
                c.execute(delete(t.changes).where(t.changes.c.doc_id.in_(ids)))
                c.execute(delete(t.documents).where(t.documents.c.id.in_(ids)))
        return len(ids)

    def record_change(self, doc_id: int, field: str, old: str, new: str) -> None:
        with self.engine.begin() as c:
            c.execute(insert(t.changes).values(doc_id=doc_id, field=field, old=old, new=new, at=utcnow()))

    def doc_chunks(self, doc_id: int) -> list[dict]:
        with self.engine.connect() as c:
            return [dict(r) for r in c.execute(select(t.chunks).where(t.chunks.c.doc_id == doc_id).order_by(t.chunks.c.ord)).mappings()]

    def stats(self, scope: Scope) -> dict:
        with self.engine.connect() as c:
            by_status = dict(c.execute(select(t.documents.c.status, func.count()).where(self._doc_filter(scope)).group_by(t.documents.c.status)).all())
            by_type = dict(c.execute(select(t.documents.c.content_type, func.count()).where(self._doc_filter(scope), t.documents.c.status == "active").group_by(t.documents.c.content_type)).all())
            by_lang = dict(c.execute(select(t.documents.c.language, func.count()).where(self._doc_filter(scope), t.documents.c.status == "active").group_by(t.documents.c.language)).all())
            chunks = c.execute(select(func.count()).select_from(t.chunks).join(t.documents, t.documents.c.id == t.chunks.c.doc_id).where(self._chunk_filter(scope), t.documents.c.status == "active")).scalar_one()
        return {"documents": by_status, "types": by_type, "languages": by_lang, "chunks": chunks}

    # ---- retrieval -------------------------------------------------------------
    def _active_join(self):
        return t.chunks.join(t.documents, t.documents.c.id == t.chunks.c.doc_id)

    def corpus_stats(self, scope: Scope) -> tuple[int, float]:
        q = select(func.count(), func.avg(t.chunks.c.length)).select_from(self._active_join()).where(self._chunk_filter(scope), t.documents.c.status == "active")
        with self.engine.connect() as c:
            n, avg = c.execute(q).one()
        return int(n or 0), float(avg or 1.0)

    def known_similar_term(self, scope: Scope, term: str) -> str:
        """For a word this site does not contain: a typo fix toward a rare indexed word, else the word without a
        colloquial ending («سفارشم»), else a longer indexed word it starts («کوه» -> «کوهنوردی»). Empty if none."""
        fixed = self._typo_fix(scope, term)
        if fixed:
            return fixed
        for variant in suffix_variants(term, unknown=True):
            if self._df(scope, [variant]):
                return variant
        if len(term) >= VARIANTS["prefix_min"] and not term.isdigit():
            lo, hi = len(term) + VARIANTS["prefix_extra_min"], len(term) + VARIANTS["prefix_extra_max"]
            q = (
                select(t.postings.c.term, func.count().label("df"))
                .where(t.postings.c.site_id == scope.site_id, t.postings.c.corpus == scope.corpus.value, t.postings.c.term.like(term + "%"), func.length(t.postings.c.term).between(lo, hi))
                .group_by(t.postings.c.term).order_by(func.count().desc(), t.postings.c.term).limit(1)
            )
            with self.engine.connect() as c:
                row = c.execute(q).first()
            if row:
                return row.term
        return ""

    def _df(self, scope: Scope, words: list[str]) -> dict[str, int]:
        join = t.postings.join(t.chunks, t.chunks.c.id == t.postings.c.chunk_id).join(t.documents, t.documents.c.id == t.chunks.c.doc_id)
        conds = [t.postings.c.site_id == scope.site_id, t.postings.c.corpus == scope.corpus.value, t.documents.c.status == "active", t.postings.c.term.in_(words)]
        if scope.corpus is Corpus.COMPETITOR and scope.competitor_id is not None:
            conds.append(t.chunks.c.competitor_id == scope.competitor_id)
        with self.engine.connect() as c:
            return dict(c.execute(select(t.postings.c.term, func.count()).select_from(join).where(*conds).group_by(t.postings.c.term)).all())

    def _typo_fix(self, scope: Scope, term: str) -> str:
        """Correct a typo only toward a rare indexed word. A correction toward a common word would create wrong answers."""
        if len(term) < 5:
            return ""
        q = (
            select(t.postings.c.term, func.count().label("df"))
            .where(t.postings.c.site_id == scope.site_id, t.postings.c.corpus == scope.corpus.value, t.postings.c.term.like(term[0] + "%"),
                   func.length(t.postings.c.term).between(len(term) - 1, len(term) + 1))
            .group_by(t.postings.c.term).limit(3000)
        )
        n, _ = self.corpus_stats(scope)
        best, best_df = "", 0
        with self.engine.connect() as c:
            for row in c.execute(q):
                if row.df <= max(6, int(n * 0.02)) and row.df > best_df and _edit_distance_within_one(row.term, term):
                    best, best_df = row.term, row.df
        return best

    def unknown_terms(self, scope: Scope, text: str) -> list[str]:
        """Subject words of ``text`` that this scope has never seen, even allowing one typo."""
        words = list(dict.fromkeys(subject_tokens(text)))
        if not words:
            return []
        known = set(self._df(scope, sorted({s for w in words for s in match_group(w)})))
        unknown = [w for w in words if not (match_group(w) & known) and not self.known_similar_term(scope, w)]
        return [w for w in unknown if not looks_like_verb(w)] if len(unknown) < len(words) else unknown

    def search_lexical(self, scope: Scope, query: str, limit: int = 10) -> list[ChunkHit]:
        terms = query_terms(query)
        if not terms:
            return []
        # «هزینه دوره آیلتس چقدره؟»: the page that states prices should rank first. These words are soft, so their
        # absence never blocks an answer.
        if _PRICE_INTENT.search(normalize(query)):
            terms = list(dict.fromkeys([*terms, *(stem(normalize(w)) for w in LANGUAGE["price_terms"]["words"])]))
        n, avg = self.corpus_stats(scope)
        if not n:
            return []
        scope_filter = [t.postings.c.site_id == scope.site_id, t.postings.c.corpus == scope.corpus.value]
        join = t.postings.join(t.chunks, t.chunks.c.id == t.postings.c.chunk_id).join(t.documents, t.documents.c.id == t.chunks.c.doc_id)
        conds = [*scope_filter, t.documents.c.status == "active"]
        if scope.corpus is Corpus.COMPETITOR and scope.competitor_id is not None:
            conds.append(t.chunks.c.competitor_id == scope.competitor_id)

        def df_of(words: list[str]) -> dict[str, int]:
            q = select(t.postings.c.term, func.count()).select_from(join).where(*conds, t.postings.c.term.in_(words)).group_by(t.postings.c.term)
            with self.engine.connect() as c:
                return dict(c.execute(q).all())

        base_tokens = [w for w in terms if w in tokenize(query)]
        expanded = lambda: list(dict.fromkeys([*terms, *(s for w in base_tokens for s in match_group(w))]))  # noqa: E731
        df = df_of(expanded())
        corrected: dict[str, str] = {}
        soft = soft_stems_for(query)
        for term in base_tokens:
            # A question word such as «میرسه» is never "corrected" into a subject («مدرسه»).
            if term not in soft and not (match_group(term) & df.keys()):
                fixed = self.known_similar_term(scope, term)
                if fixed:
                    corrected[term] = fixed
        if corrected:
            terms = [corrected.get(w, w) for w in terms]
            base_tokens = [corrected.get(w, w) for w in base_tokens]
            df = df_of(expanded())
        if not df:
            return []

        idf = {w: math.log(1 + (n - f + 0.5) / (f + 0.5)) for w, f in df.items()}
        # A query word absent from this site weighs the most: «X» that we have never seen must block the answer.
        # A word counts as present when any word of its synonym group is present.
        soft = soft_stems_for(query)
        subject_tokens = [w for w in base_tokens if w not in soft] or base_tokens
        groups = {w: match_group(w) & df.keys() for w in subject_tokens}
        # An unknown verb («میاد») does not block a question that has another subject word.
        nouns = [w for w in subject_tokens if groups[w] or not looks_like_verb(w)]
        if nouns and len(nouns) < len(subject_tokens):
            subject_tokens = nouns
            groups = {w: groups[w] for w in nouns}
        weights = {w: max((idf[s] for s in groups[w]), default=math.log(1 + n) + 1) for w in subject_tokens} or dict.fromkeys(df, 1.0)
        total_weight = sum(weights.values()) or 1.0

        q = select(t.postings.c.chunk_id, t.postings.c.term, t.postings.c.tf, t.chunks.c.length).select_from(join).where(*conds, t.postings.c.term.in_(list(df)))
        scores: dict[int, float] = {}
        matched: dict[int, set[str]] = {}
        with self.engine.connect() as c:
            for row in c.execute(q):
                norm = row.tf * (self.K1 + 1) / (row.tf + self.K1 * (1 - self.B + self.B * row.length / (avg or 1)))
                scores[row.chunk_id] = scores.get(row.chunk_id, 0.0) + idf[row.term] * norm
                matched.setdefault(row.chunk_id, set()).add(row.term)
        top = sorted(scores, key=lambda i: scores[i], reverse=True)[:limit]
        hits = self.hits_for_chunks(scope, top)
        out = []
        for chunk_id in top:
            hit = hits.get(chunk_id)
            if hit:
                hit.score = round(scores[chunk_id], 4)
                hit.coverage = round(sum(v for w, v in weights.items() if (groups.get(w) or {w}) & matched[chunk_id]) / total_weight, 4)
                out.append(hit)
        return out

    def hits_for_chunks(self, scope: Scope, ids: list[int]) -> dict[int, ChunkHit]:
        if not ids:
            return {}
        q = (
            select(t.chunks.c.id, t.chunks.c.doc_id, t.chunks.c.text, t.chunks.c.heading, t.chunks.c.language, t.chunks.c.corpus,
                   t.documents.c.url, t.documents.c.title, t.documents.c.content_type, t.documents.c.crawl_date, t.documents.c.structured)
            .select_from(self._active_join())
            .where(self._chunk_filter(scope), t.documents.c.status == "active", t.chunks.c.id.in_(ids))
        )
        with self.engine.connect() as c:
            return {
                r.id: ChunkHit(r.id, r.doc_id, 0.0, 0.0, r.text, r.heading, r.language, r.url, r.title, r.content_type, r.corpus, r.crawl_date, r.structured or {})
                for r in c.execute(q)
            }

    # ---- FAQ -------------------------------------------------------------------
    def faqs(self, site_id: int) -> list[dict]:
        with self.engine.connect() as c:
            return [dict(r) for r in c.execute(select(t.faqs).where(t.faqs.c.site_id == site_id).order_by(t.faqs.c.id)).mappings()]


def _edit_distance_within_one(a: str, b: str) -> bool:
    if a == b:
        return True
    if abs(len(a) - len(b)) > 1:
        return False
    if len(a) > len(b):
        a, b = b, a
    i = j = edits = 0
    while i < len(a) and j < len(b):
        if a[i] == b[j]:
            i += 1
            j += 1
            continue
        edits += 1
        if edits > 1:
            return False
        if len(a) == len(b):
            i += 1
        j += 1
    return edits + (len(b) - j) + (len(a) - i) <= 1


def simhash(text: str) -> str:
    """64-bit fingerprint for near-duplicate detection without comparing every pair of documents."""
    tokens = tokenize(text)
    if len(tokens) < 8:
        return ""
    weights = [0] * 64
    for i in range(len(tokens) - 2):
        digest = int.from_bytes(hashlib.blake2b(" ".join(tokens[i:i + 3]).encode(), digest_size=8).digest(), "big")
        for bit in range(64):
            weights[bit] += 1 if digest >> bit & 1 else -1
    value = sum(1 << bit for bit in range(64) if weights[bit] > 0)
    return f"{value:016x}"


def hamming(a: str, b: str) -> int:
    return (int(a, 16) ^ int(b, 16)).bit_count()


__all__ = ["ChunkDraft", "ChunkHit", "Corpus", "KnowledgeStore", "Scope", "SqlKnowledgeStore", "hamming", "normalize", "query_terms", "simhash", "term_counts", "url_key", "utcnow"]
