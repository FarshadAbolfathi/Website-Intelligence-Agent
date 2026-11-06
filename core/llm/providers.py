"""LLM providers behind one interface. A new runtime needs only a class that implements `LLMProvider`.

Nothing here contacts a cloud service unless the owner points a base URL at one. The default is no model at all,
in which case the chat engine answers extractively from the site's own text.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Protocol

import httpx


@dataclass
class Message:
    role: str  # system | user | assistant
    content: str


class LLMError(RuntimeError):
    pass


class LLMProvider(Protocol):
    name: str

    def generate(self, messages: list[Message], *, temperature: float = 0.2, max_tokens: int = 400) -> str: ...
    def healthy(self) -> bool: ...


class NoLLM:
    name = "none"

    def generate(self, messages: list[Message], *, temperature: float = 0.2, max_tokens: int = 400) -> str:
        raise LLMError("no model configured")

    def healthy(self) -> bool:
        return False


class OllamaProvider:
    def __init__(self, base_url: str = "http://localhost:11434", model: str = "qwen2.5", *, timeout: float = 180.0, num_ctx: int = 4096) -> None:
        self.base_url = base_url.rstrip("/")
        self.model = model
        self.timeout = timeout
        self.num_ctx = num_ctx
        self.name = f"ollama:{model}"

    def generate(self, messages: list[Message], *, temperature: float = 0.2, max_tokens: int = 400) -> str:
        payload = {
            "model": self.model, "stream": False, "messages": [m.__dict__ for m in messages],
            "options": {"temperature": temperature, "num_predict": max_tokens, "num_ctx": self.num_ctx},
        }
        try:
            response = httpx.post(f"{self.base_url}/api/chat", json=payload, timeout=self.timeout)
            response.raise_for_status()
            body = response.json()
            # Reported by the runtime, so cost per conversation is measured rather than estimated.
            self.last_usage = {"prompt_tokens": int(body.get("prompt_eval_count", 0)), "completion_tokens": int(body.get("eval_count", 0))}
            return body["message"]["content"].strip()
        except (httpx.HTTPError, KeyError, ValueError) as exc:
            raise LLMError(f"ollama: {exc}") from exc

    def healthy(self) -> bool:
        try:
            response = httpx.get(f"{self.base_url}/api/tags", timeout=3)
            return response.status_code == 200 and any(m["name"] == self.model or m["name"].split(":")[0] == self.model for m in response.json().get("models", []))
        except (httpx.HTTPError, ValueError, KeyError):
            return False

    def list_models(self) -> list[dict]:
        response = httpx.get(f"{self.base_url}/api/tags", timeout=5)
        response.raise_for_status()
        return response.json().get("models", [])


class LlamaCppProvider:
    """llama.cpp `llama-server`, through its OpenAI-compatible endpoint."""

    def __init__(self, base_url: str = "http://localhost:8080", model: str = "local", *, timeout: float = 180.0, api_key: str = "") -> None:
        self.base_url = base_url.rstrip("/")
        self.model = model
        self.timeout = timeout
        self.headers = {"Authorization": f"Bearer {api_key}"} if api_key else {}
        self.name = f"llamacpp:{model}"

    def generate(self, messages: list[Message], *, temperature: float = 0.2, max_tokens: int = 400) -> str:
        payload = {"model": self.model, "messages": [m.__dict__ for m in messages], "temperature": temperature, "max_tokens": max_tokens, "stream": False}
        try:
            response = httpx.post(f"{self.base_url}/v1/chat/completions", json=payload, headers=self.headers, timeout=self.timeout)
            response.raise_for_status()
            body = response.json()
            usage = body.get("usage") or {}
            self.last_usage = {"prompt_tokens": int(usage.get("prompt_tokens", 0)), "completion_tokens": int(usage.get("completion_tokens", 0))}
            return body["choices"][0]["message"]["content"].strip()
        except (httpx.HTTPError, KeyError, IndexError, ValueError) as exc:
            raise LLMError(f"llama.cpp: {exc}") from exc

    def healthy(self) -> bool:
        try:
            return httpx.get(f"{self.base_url}/health", headers=self.headers, timeout=3).status_code == 200
        except httpx.HTTPError:
            return False
