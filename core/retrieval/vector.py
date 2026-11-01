"""Vector search behind `VectorIndex`: Qdrant, or a dependency-free SQL scan for small sites.

Own-site and competitor vectors live in different Qdrant collections, not only behind a payload filter. A filter
bug therefore cannot surface competitor content in a customer's answer.
"""

from __future__ import annotations

import heapq
from typing import Protocol

import httpx
from sqlalchemy import delete, insert, select
from sqlalchemy.engine import Engine

from core.embeddings.providers import cosine
from core.knowledge import schema as t
from core.knowledge.store import Corpus, Scope


class VectorIndex(Protocol):
    name: str

    def upsert(self, scope: Scope, model: str, items: list[tuple[int, list[float]]]) -> None: ...
    def delete(self, scope: Scope, chunk_ids: list[int]) -> None: ...
    def delete_scope(self, scope: Scope) -> None: ...
    def search(self, scope: Scope, vector: list[float], limit: int = 10) -> list[tuple[int, float]]: ...
    def healthy(self) -> bool: ...


class SqlVectorIndex:
    """Linear scan over stored vectors. Fine up to a few thousand chunks with no extra service to run."""

    name = "sql"

    def __init__(self, engine: Engine) -> None:
        self.engine = engine

    def upsert(self, scope: Scope, model: str, items: list[tuple[int, list[float]]]) -> None:
        if not items:
            return
        with self.engine.begin() as c:
            c.execute(delete(t.embeddings).where(t.embeddings.c.chunk_id.in_([i for i, _ in items])))
            c.execute(insert(t.embeddings), [
                {"chunk_id": i, "site_id": scope.site_id, "corpus": scope.corpus.value, "model": model, "vector": v} for i, v in items
            ])

    def delete(self, scope: Scope, chunk_ids: list[int]) -> None:
        if chunk_ids:
            with self.engine.begin() as c:
                c.execute(delete(t.embeddings).where(t.embeddings.c.site_id == scope.site_id, t.embeddings.c.chunk_id.in_(chunk_ids)))

    def delete_scope(self, scope: Scope) -> None:
        with self.engine.begin() as c:
            c.execute(delete(t.embeddings).where(t.embeddings.c.site_id == scope.site_id, t.embeddings.c.corpus == scope.corpus.value))

    def search(self, scope: Scope, vector: list[float], limit: int = 10) -> list[tuple[int, float]]:
        q = select(t.embeddings.c.chunk_id, t.embeddings.c.vector).where(t.embeddings.c.site_id == scope.site_id, t.embeddings.c.corpus == scope.corpus.value)
        with self.engine.connect() as c:
            scored = ((row.chunk_id, cosine(vector, row.vector)) for row in c.execute(q))
            return heapq.nlargest(limit, scored, key=lambda x: x[1])

    def healthy(self) -> bool:
        return True


class QdrantVectorIndex:
    """Qdrant over its REST API."""

    name = "qdrant"

    def __init__(self, base_url: str, *, api_key: str = "", prefix: str = "wia", timeout: float = 30.0) -> None:
        self.base_url = base_url.rstrip("/")
        self.prefix = prefix
        headers = {"api-key": api_key} if api_key else {}
        self.client = httpx.Client(base_url=self.base_url, timeout=timeout, headers=headers)
        self._ready: set[str] = set()

    def _collection(self, scope: Scope) -> str:
        return f"{self.prefix}_{'competitor' if scope.corpus is Corpus.COMPETITOR else 'own'}"

    def _ensure(self, name: str, size: int) -> None:
        if name in self._ready:
            return
        if self.client.get(f"/collections/{name}").status_code == 404:
            self.client.put(f"/collections/{name}", json={"vectors": {"size": size, "distance": "Cosine"}}).raise_for_status()
            self.client.put(f"/collections/{name}/index", json={"field_name": "site_id", "field_schema": "integer"}).raise_for_status()
        self._ready.add(name)

    def _filter(self, scope: Scope) -> dict:
        must = [{"key": "site_id", "match": {"value": scope.site_id}}]
        if scope.competitor_id is not None:
            must.append({"key": "competitor_id", "match": {"value": scope.competitor_id}})
        return {"must": must}

    def upsert(self, scope: Scope, model: str, items: list[tuple[int, list[float]]]) -> None:
        if not items:
            return
        name = self._collection(scope)
        self._ensure(name, len(items[0][1]))
        points = [{"id": i, "vector": v, "payload": {"site_id": scope.site_id, "competitor_id": scope.competitor_id, "model": model}} for i, v in items]
        self.client.put(f"/collections/{name}/points", params={"wait": "true"}, json={"points": points}).raise_for_status()

    def delete(self, scope: Scope, chunk_ids: list[int]) -> None:
        if not chunk_ids:
            return
        name = self._collection(scope)
        if self.client.get(f"/collections/{name}").status_code == 404:
            return
        self.client.post(f"/collections/{name}/points/delete", params={"wait": "true"}, json={"points": chunk_ids}).raise_for_status()

    def delete_scope(self, scope: Scope) -> None:
        name = self._collection(scope)
        if self.client.get(f"/collections/{name}").status_code == 404:
            return
        self.client.post(f"/collections/{name}/points/delete", params={"wait": "true"}, json={"filter": self._filter(scope)}).raise_for_status()

    def search(self, scope: Scope, vector: list[float], limit: int = 10) -> list[tuple[int, float]]:
        name = self._collection(scope)
        response = self.client.post(f"/collections/{name}/points/search", json={"vector": vector, "limit": limit, "filter": self._filter(scope)})
        if response.status_code == 404:
            return []
        response.raise_for_status()
        return [(int(p["id"]), float(p["score"])) for p in response.json()["result"]]

    def healthy(self) -> bool:
        try:
            return self.client.get("/healthz", timeout=3).status_code == 200
        except httpx.HTTPError:
            return False
