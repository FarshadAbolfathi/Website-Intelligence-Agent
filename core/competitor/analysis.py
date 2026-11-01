"""Competitor intelligence: comparison reports built only from public pages collected under a competitor scope.

Reports are for the site owner's dashboard. Nothing here is reachable from the public chat path. Every finding
carries the URL it came from and the date it was collected. Topic gaps are lexical comparisons, reported as leads
for a human to judge rather than as conclusions.
"""

from __future__ import annotations

from collections import Counter
from datetime import UTC, datetime

from core.knowledge.store import Corpus, Scope, SqlKnowledgeStore
from core.text.normalize import STOPWORDS, tokenize

GENERIC_TERMS = frozenset(
    tokenize(
        "home page site website store shop online contact about blog news privacy policy terms read more guide "
        "صفحه سایت فروشگاه خانه تماس درباره وبلاگ مقاله اخبار قوانین راهنما الرئيسية موقع متجر اتصل مدونة"
    )
)


def _iso(value: datetime) -> str:
    return (value.replace(tzinfo=UTC) if value.tzinfo is None else value).isoformat()


def _evidence(doc: dict) -> dict:
    return {"url": doc["url"], "title": doc["title"], "collected_at": _iso(doc["crawl_date"])}


def _as_float(value) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


class CompetitorAnalyzer:
    def __init__(self, store: SqlKnowledgeStore) -> None:
        self.store = store

    def _docs(self, site_id: int, competitor_id: int | None = None) -> tuple[list[dict], list[dict]]:
        own = self.store.list_docs(Scope(site_id, Corpus.OWN))
        rival = self.store.list_docs(Scope(site_id, Corpus.COMPETITOR, competitor_id))
        return own, rival

    @staticmethod
    def _product(doc: dict) -> dict:
        return (doc.get("structured") or {}).get("product", {})

    def compare_products(self, site_id: int, competitor_id: int | None = None) -> dict:
        own, rival = self._docs(site_id, competitor_id)
        own_products = [d for d in own if d["content_type"] == "product"]
        rival_products = [d for d in rival if d["content_type"] == "product"]

        def key(doc: dict) -> frozenset[str]:
            return frozenset(tokenize(self._product(doc).get("name") or doc["title"]))

        matched, only_rival, seen_own = [], [], set()
        for r in rival_products:
            r_key = key(r)
            best, best_score = None, 0.0
            for o in own_products:
                o_key = key(o)
                union = r_key | o_key
                score = len(r_key & o_key) / len(union) if union else 0.0
                if score > best_score:
                    best, best_score = o, score
            if best and best_score >= 0.6:
                seen_own.add(best["id"])
                own_price, rival_price = _as_float(self._product(best).get("price")), _as_float(self._product(r).get("price"))
                comparable = own_price is not None and rival_price is not None and self._product(best).get("currency") == self._product(r).get("currency")
                matched.append({
                    "name": self._product(best).get("name") or best["title"],
                    "own": {"price": self._product(best).get("price"), "currency": self._product(best).get("currency"), "availability": self._product(best).get("availability"), **_evidence(best)},
                    "competitor": {"price": self._product(r).get("price"), "currency": self._product(r).get("currency"), "availability": self._product(r).get("availability"), **_evidence(r)},
                    "price_difference": round(rival_price - own_price, 2) if comparable else None,
                    "match_confidence": round(best_score, 2),
                })
            else:
                only_rival.append({"name": self._product(r).get("name") or r["title"], "price": self._product(r).get("price"), "currency": self._product(r).get("currency"), **_evidence(r)})
        only_own = [{"name": self._product(o).get("name") or o["title"], **_evidence(o)} for o in own_products if o["id"] not in seen_own]
        return {"kind": "products", "matched": matched, "only_competitor": only_rival, "only_own": only_own,
                "note": "Matching uses product names. Review low-confidence matches, because similar names may be different products."}

    def _terms(self, docs: list[dict]) -> tuple[Counter[str], dict[str, list[dict]]]:
        counts: Counter[str] = Counter()
        where: dict[str, list[dict]] = {}
        for doc in docs:
            headings = " ".join(c["heading"] for c in self.store.doc_chunks(doc["id"]))
            terms = {t for t in tokenize(f"{doc['title']} {headings}") if t not in GENERIC_TERMS and t not in STOPWORDS and len(t) > 2}
            counts.update(terms)
            for term in terms:
                where.setdefault(term, []).append(doc)
        return counts, where

    def content_gaps(self, site_id: int, competitor_id: int | None = None, limit: int = 25) -> dict:
        own, rival = self._docs(site_id, competitor_id)
        own_full: set[str] = set()
        for doc in own:
            for chunk in self.store.doc_chunks(doc["id"]):
                own_full.update(tokenize(f"{chunk['heading']} {chunk['text']}"))
            own_full.update(tokenize(doc["title"]))
        counts, where = self._terms(rival)
        gaps = []
        for term, count in counts.most_common():
            if term in own_full:
                continue
            docs = where[term]
            gaps.append({"topic": term, "competitor_pages": count, "examples": [_evidence(d) for d in docs[:3]]})
            if len(gaps) >= limit:
                break
        return {"kind": "content_gaps", "gaps": gaps,
                "note": "Topics found in competitor titles and headings but nowhere in your indexed content. These are leads for an editor, not automatic recommendations."}

    def blog_ideas(self, site_id: int, competitor_id: int | None = None, limit: int = 15) -> dict:
        own, rival = self._docs(site_id, competitor_id)
        own_terms: set[str] = set()
        for doc in own:
            own_terms.update(tokenize(doc["title"]))
        ideas = []
        for doc in rival:
            if doc["content_type"] not in ("article", "page"):
                continue
            terms = [t for t in tokenize(doc["title"]) if t not in GENERIC_TERMS]
            if not terms:
                continue
            new_ratio = sum(1 for t in terms if t not in own_terms) / len(terms)
            if new_ratio >= 0.5:
                ideas.append({"competitor_title": doc["title"], "new_term_ratio": round(new_ratio, 2), **_evidence(doc)})
        ideas.sort(key=lambda i: i["new_term_ratio"], reverse=True)
        return {"kind": "blog_ideas", "ideas": ideas[:limit],
                "note": "Competitor article titles that cover ground your titles do not. Write original content; do not copy theirs."}

    def compare_services(self, site_id: int, competitor_id: int | None = None) -> dict:
        own, rival = self._docs(site_id, competitor_id)

        def pages(docs: list[dict]) -> list[dict]:
            return [_evidence(d) for d in docs if d["content_type"] == "service"]

        return {"kind": "services", "own": pages(own), "competitor": pages(rival)}

    def structure(self, site_id: int, competitor_id: int | None = None) -> dict:
        own, rival = self._docs(site_id, competitor_id)
        return {
            "kind": "structure",
            "own": dict(Counter(d["content_type"] for d in own)),
            "competitor": dict(Counter(d["content_type"] for d in rival)),
        }

    def full_report(self, site_id: int, competitor_id: int | None = None) -> dict:
        return {
            "generated_at": datetime.now(UTC).isoformat(), "site_id": site_id, "competitor_id": competitor_id,
            "disclaimer": "Built only from public pages allowed by each site's robots.txt. Each item lists its source and collection date. Competitor data never reaches customer-facing answers.",
            "products": self.compare_products(site_id, competitor_id),
            "services": self.compare_services(site_id, competitor_id),
            "content_gaps": self.content_gaps(site_id, competitor_id),
            "blog_ideas": self.blog_ideas(site_id, competitor_id),
            "structure": self.structure(site_id, competitor_id),
        }
