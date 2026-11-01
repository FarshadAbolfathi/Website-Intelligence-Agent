"""Embedding engines. All run on the owner's machine. Add a new one by implementing `Embedder`."""

from __future__ import annotations

import hashlib
import math
from typing import Protocol

import httpx

from core.text.normalize import tokenize


class Embedder(Protocol):
    name: str
    semantic: bool

    def embed(self, texts: list[str]) -> list[list[float]]: ...
    def healthy(self) -> bool: ...


class NoEmbedder:
    """Lexical-only mode, for machines that cannot run an embedding model."""

    name = "none"
    semantic = False

    def embed(self, texts: list[str]) -> list[list[float]]:
        return [[] for _ in texts]

    def healthy(self) -> bool:
        return True


class HashingEmbedder:
    """Deterministic feature hashing, for tests and vector-store plumbing. It has no semantic understanding."""

    semantic = False

    def __init__(self, dim: int = 256) -> None:
        self.dim = dim
        self.name = f"hashing-{dim}"

    def embed(self, texts: list[str]) -> list[list[float]]:
        out = []
        for text in texts:
            vec = [0.0] * self.dim
            for token in tokenize(text):
                digest = hashlib.blake2b(token.encode(), digest_size=8).digest()
                vec[int.from_bytes(digest[:4], "big") % self.dim] += 1.0 if digest[4] & 1 else -1.0
            norm = math.sqrt(sum(v * v for v in vec)) or 1.0
            out.append([v / norm for v in vec])
        return out

    def healthy(self) -> bool:
        return True


class OllamaEmbedder:
    """Local embeddings through Ollama. The default `bge-m3` supports fa, ar and en. See docs/models.md."""

    semantic = True

    def __init__(self, base_url: str = "http://localhost:11434", model: str = "bge-m3", timeout: float = 120.0, batch: int = 16) -> None:
        self.base_url = base_url.rstrip("/")
        self.model = model
        self.name = f"ollama:{model}"
        self.timeout = timeout
        self.batch = batch

    def embed(self, texts: list[str]) -> list[list[float]]:
        out: list[list[float]] = []
        with httpx.Client(timeout=self.timeout) as client:
            for i in range(0, len(texts), self.batch):
                response = client.post(f"{self.base_url}/api/embed", json={"model": self.model, "input": texts[i:i + self.batch]})
                response.raise_for_status()
                out.extend(response.json()["embeddings"])
        return out

    def healthy(self) -> bool:
        try:
            r = httpx.get(f"{self.base_url}/api/tags", timeout=3)
            return r.status_code == 200 and any(m["name"].split(":")[0] == self.model.split(":")[0] for m in r.json().get("models", []))
        except (httpx.HTTPError, ValueError, KeyError):
            return False


def cosine(a: list[float], b: list[float]) -> float:
    dot = sum(x * y for x, y in zip(a, b, strict=False))
    na = math.sqrt(sum(x * x for x in a))
    nb = math.sqrt(sum(y * y for y in b))
    return dot / (na * nb) if na and nb else 0.0
