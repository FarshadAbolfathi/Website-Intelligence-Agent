"""Hybrid retrieval: BM25 and vectors merged with reciprocal rank fusion, plus an honest confidence gate."""

from __future__ import annotations

from dataclasses import dataclass

from core.embeddings.providers import Embedder
from core.knowledge.store import ChunkHit, Scope, SqlKnowledgeStore
from core.retrieval.vector import VectorIndex

RRF_K = 60


@dataclass
class RetrievalResult:
    hits: list[ChunkHit]
    confident: bool
    best_coverage: float
    best_vector: float
    mode: str  # lexical | hybrid | vector


class Retriever:
    def __init__(
        self, store: SqlKnowledgeStore, embedder: Embedder, vectors: VectorIndex | None = None, *,
        min_coverage: float = 0.6, min_vector: float = 0.60,
    ) -> None:
        self.store = store
        self.embedder = embedder
        self.vectors = vectors
        self.min_coverage = min_coverage
        self.min_vector = min_vector

    def search(self, scope: Scope, query: str, limit: int = 5) -> RetrievalResult:
        lexical = self.store.search_lexical(scope, query, limit=limit * 3)
        best_coverage = max((h.coverage for h in lexical), default=0.0)

        dense: list[tuple[int, float]] = []
        use_vectors = self.vectors is not None and self.embedder.semantic
        if use_vectors:
            try:
                vector = self.embedder.embed([query])[0]
                dense = self.vectors.search(scope, vector, limit=limit * 3)
            except Exception:  # noqa: BLE001 - a vector outage degrades to lexical search and never fails the chat
                dense, use_vectors = [], False
        best_vector = dense[0][1] if dense else 0.0

        if not dense:
            hits = lexical[:limit]
            return RetrievalResult(hits, bool(hits) and best_coverage >= self.min_coverage, best_coverage, 0.0, "lexical")

        ranks: dict[int, float] = {}
        for rank, hit in enumerate(lexical):
            ranks[hit.chunk_id] = ranks.get(hit.chunk_id, 0.0) + 1 / (RRF_K + rank + 1)
        for rank, (chunk_id, _) in enumerate(dense):
            ranks[chunk_id] = ranks.get(chunk_id, 0.0) + 1 / (RRF_K + rank + 1)
        order = sorted(ranks, key=lambda i: ranks[i], reverse=True)[:limit]

        by_id = {h.chunk_id: h for h in lexical}
        missing = [i for i in order if i not in by_id]
        by_id.update(self.store.hits_for_chunks(scope, missing))  # filters on scope and status again
        vector_scores = dict(dense)
        hits = []
        for chunk_id in order:
            hit = by_id.get(chunk_id)
            if hit:
                hit.score = round(ranks[chunk_id], 5)
                hits.append(hit)
        confident = bool(hits) and (best_coverage >= self.min_coverage or max((vector_scores.get(h.chunk_id, 0.0) for h in hits), default=0.0) >= self.min_vector)
        return RetrievalResult(hits, confident, best_coverage, best_vector, "hybrid" if lexical else "vector")
