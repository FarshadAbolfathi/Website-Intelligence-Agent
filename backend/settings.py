"""All configuration comes from environment variables. See .env.example."""

from __future__ import annotations

import os
from dataclasses import dataclass, field


def _csv(name: str) -> frozenset[str]:
    return frozenset(x.strip().lower() for x in os.environ.get(name, "").split(",") if x.strip())


def _bool(name: str, default: bool) -> bool:
    return os.environ.get(name, str(default)).strip().lower() in ("1", "true", "yes", "on")


@dataclass
class Settings:
    database_url: str = field(default_factory=lambda: os.environ.get("WIA_DATABASE_URL", "sqlite:///./wia.sqlite3"))
    secret_key: str = field(default_factory=lambda: os.environ.get("WIA_SECRET_KEY", ""))
    cookie_secure: bool = field(default_factory=lambda: _bool("WIA_COOKIE_SECURE", True))
    vector_backend: str = field(default_factory=lambda: os.environ.get("WIA_VECTOR_BACKEND", "sql"))  # sql | qdrant
    qdrant_url: str = field(default_factory=lambda: os.environ.get("WIA_QDRANT_URL", "http://qdrant:6333"))
    qdrant_api_key: str = field(default_factory=lambda: os.environ.get("WIA_QDRANT_API_KEY", ""))
    llm_backend: str = field(default_factory=lambda: os.environ.get("WIA_LLM_BACKEND", "none"))  # none | ollama | llamacpp
    llm_url: str = field(default_factory=lambda: os.environ.get("WIA_LLM_URL", "http://ollama:11434"))
    llm_model: str = field(default_factory=lambda: os.environ.get("WIA_LLM_MODEL", ""))
    llm_timeout: float = field(default_factory=lambda: float(os.environ.get("WIA_LLM_TIMEOUT", "180")))
    embed_backend: str = field(default_factory=lambda: os.environ.get("WIA_EMBED_BACKEND", "none"))  # none | ollama
    embed_url: str = field(default_factory=lambda: os.environ.get("WIA_EMBED_URL", "http://ollama:11434"))
    embed_model: str = field(default_factory=lambda: os.environ.get("WIA_EMBED_MODEL", "bge-m3"))
    crawler_allow_hosts: frozenset[str] = field(default_factory=lambda: _csv("WIA_CRAWLER_ALLOW_HOSTS"))
    crawler_delay: float = field(default_factory=lambda: float(os.environ.get("WIA_CRAWLER_DELAY", "1.0")))
    crawler_max_pages: int = field(default_factory=lambda: int(os.environ.get("WIA_CRAWLER_MAX_PAGES", "500")))
    crawler_js: bool = field(default_factory=lambda: _bool("WIA_CRAWLER_JS", False))
    competitor_delay: float = field(default_factory=lambda: float(os.environ.get("WIA_COMPETITOR_DELAY", "3.0")))
    chat_rate_limit: int = field(default_factory=lambda: int(os.environ.get("WIA_CHAT_RATE_LIMIT", "30")))  # per IP per minute
    conversation_retention_days: int = field(default_factory=lambda: int(os.environ.get("WIA_CONVERSATION_RETENTION_DAYS", "30")))
    volatile_ttl_hours: float = field(default_factory=lambda: float(os.environ.get("WIA_VOLATILE_TTL_HOURS", "24")))
    trust_proxy: bool = field(default_factory=lambda: _bool("WIA_TRUST_PROXY", False))
    frontend_dist: str = field(default_factory=lambda: os.environ.get("WIA_FRONTEND_DIST", "frontend/dist"))
    widget_path: str = field(default_factory=lambda: os.environ.get("WIA_WIDGET_PATH", "shared/widget/wia-widget.js"))
