"""Relational schema. Portable across PostgreSQL (production) and SQLite (tests, small installs)."""

from __future__ import annotations

from sqlalchemy import (
    JSON,
    BigInteger,
    Boolean,
    Column,
    DateTime,
    Float,
    ForeignKey,
    Index,
    Integer,
    MetaData,
    String,
    Table,
    Text,
    UniqueConstraint,
)

metadata = MetaData()
PK = BigInteger().with_variant(Integer, "sqlite")

sites = Table(
    "sites", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("name", String(200), nullable=False),
    Column("base_url", String(2048), nullable=False),
    Column("public_key", String(64), nullable=False, unique=True),
    Column("default_language", String(8), nullable=False, default="fa"),
    Column("settings", JSON, nullable=False, default=dict),
    Column("created_at", DateTime(timezone=True), nullable=False),
)

competitors = Table(
    "competitors", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("site_id", PK, ForeignKey("sites.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("name", String(200), nullable=False),
    Column("base_url", String(2048), nullable=False),
    Column("enabled", Boolean, nullable=False, default=True),
    Column("created_at", DateTime(timezone=True), nullable=False),
    UniqueConstraint("site_id", "base_url", name="uq_competitor_url"),
)

# corpus is 'own' or 'competitor'. Every read goes through a Scope, and the public chat path can only build an 'own' one.
documents = Table(
    "documents", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("site_id", PK, ForeignKey("sites.id", ondelete="CASCADE"), nullable=False),
    Column("corpus", String(12), nullable=False),
    Column("competitor_id", PK, ForeignKey("competitors.id", ondelete="CASCADE"), nullable=True),
    Column("url", String(2048), nullable=False),
    Column("url_key", String(64), nullable=False),
    Column("title", Text, nullable=False, default=""),
    Column("description", Text, nullable=False, default=""),
    Column("content_type", String(20), nullable=False, default="page"),
    Column("language", String(8), nullable=False, default=""),
    Column("source", String(20), nullable=False, default="crawler"),
    Column("content_hash", String(64), nullable=False, default=""),
    Column("simhash", String(20), nullable=False, default=""),
    Column("status", String(12), nullable=False, default="active"),
    Column("dup_of", PK, nullable=True),
    Column("version", Integer, nullable=False, default=1),
    Column("structured", JSON, nullable=False, default=dict),
    Column("etag", String(255), nullable=False, default=""),
    Column("http_last_modified", String(64), nullable=False, default=""),
    Column("last_modified", String(64), nullable=False, default=""),
    Column("crawl_date", DateTime(timezone=True), nullable=False),
    Column("last_checked", DateTime(timezone=True), nullable=False),
    Column("created_at", DateTime(timezone=True), nullable=False),
    UniqueConstraint("site_id", "corpus", "competitor_id", "url_key", name="uq_doc_url"),
    Index("ix_doc_scope", "site_id", "corpus", "status"),
)

chunks = Table(
    "chunks", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("doc_id", PK, ForeignKey("documents.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("site_id", PK, nullable=False),
    Column("corpus", String(12), nullable=False),
    Column("competitor_id", PK, nullable=True),
    Column("ord", Integer, nullable=False),
    Column("heading", Text, nullable=False, default=""),
    Column("text", Text, nullable=False),
    Column("language", String(8), nullable=False, default=""),
    Column("length", Integer, nullable=False, default=0),
    Index("ix_chunk_scope", "site_id", "corpus"),
)

postings = Table(
    "postings", metadata,
    Column("site_id", PK, nullable=False),
    Column("corpus", String(12), nullable=False),
    Column("term", String(80), nullable=False),
    Column("chunk_id", PK, ForeignKey("chunks.id", ondelete="CASCADE"), nullable=False),
    Column("tf", Float, nullable=False),
    Index("ix_postings_lookup", "site_id", "corpus", "term"),
    Index("ix_postings_chunk", "chunk_id"),
)

embeddings = Table(
    "embeddings", metadata,
    Column("chunk_id", PK, ForeignKey("chunks.id", ondelete="CASCADE"), primary_key=True),
    Column("site_id", PK, nullable=False),
    Column("corpus", String(12), nullable=False),
    Column("model", String(120), nullable=False),
    Column("vector", JSON, nullable=False),
    Index("ix_embed_scope", "site_id", "corpus"),
)

changes = Table(
    "changes", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("doc_id", PK, ForeignKey("documents.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("field", String(40), nullable=False),
    Column("old", Text, nullable=False, default=""),
    Column("new", Text, nullable=False, default=""),
    Column("at", DateTime(timezone=True), nullable=False),
)

links = Table(
    "links", metadata,
    Column("site_id", PK, nullable=False),
    Column("corpus", String(12), nullable=False),
    Column("from_key", String(64), nullable=False),
    Column("to_key", String(64), nullable=False),
    Index("ix_links", "site_id", "corpus", "from_key"),
)

faqs = Table(
    "faqs", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("site_id", PK, ForeignKey("sites.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("question", Text, nullable=False),
    Column("answer", Text, nullable=False),
    Column("keywords", Text, nullable=False, default=""),
    Column("language", String(8), nullable=False, default=""),
    Column("created_at", DateTime(timezone=True), nullable=False),
)

crawl_runs = Table(
    "crawl_runs", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("site_id", PK, ForeignKey("sites.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("corpus", String(12), nullable=False),
    Column("competitor_id", PK, nullable=True),
    Column("status", String(12), nullable=False),
    Column("stats", JSON, nullable=False, default=dict),
    Column("failures", JSON, nullable=False, default=list),
    Column("started_at", DateTime(timezone=True), nullable=False),
    Column("finished_at", DateTime(timezone=True), nullable=True),
)

jobs = Table(
    "jobs", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("site_id", PK, nullable=True, index=True),
    Column("kind", String(30), nullable=False),
    Column("payload", JSON, nullable=False, default=dict),
    Column("status", String(12), nullable=False, default="queued"),
    Column("attempts", Integer, nullable=False, default=0),
    Column("error", Text, nullable=False, default=""),
    Column("result", JSON, nullable=True),
    Column("run_after", DateTime(timezone=True), nullable=False),
    Column("locked_at", DateTime(timezone=True), nullable=True),
    Column("created_at", DateTime(timezone=True), nullable=False),
    Column("finished_at", DateTime(timezone=True), nullable=True),
    Index("ix_jobs_pick", "status", "run_after"),
)

schedules = Table(
    "schedules", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("site_id", PK, ForeignKey("sites.id", ondelete="CASCADE"), nullable=False),
    Column("kind", String(30), nullable=False),
    Column("every_hours", Integer, nullable=False),
    Column("next_run", DateTime(timezone=True), nullable=False),
    UniqueConstraint("site_id", "kind", name="uq_schedule"),
)

conversations = Table(
    "conversations", metadata,
    Column("id", String(40), primary_key=True),
    Column("site_id", PK, ForeignKey("sites.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("state", JSON, nullable=False, default=dict),
    Column("created_at", DateTime(timezone=True), nullable=False),
    Column("updated_at", DateTime(timezone=True), nullable=False, index=True),
)

messages = Table(
    "messages", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("conversation_id", String(40), ForeignKey("conversations.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("role", String(10), nullable=False),
    Column("text", Text, nullable=False),
    Column("meta", JSON, nullable=False, default=dict),
    Column("created_at", DateTime(timezone=True), nullable=False),
)

evaluation_runs = Table(
    "evaluation_runs", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("site_id", PK, ForeignKey("sites.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("status", String(12), nullable=False),
    Column("metrics", JSON, nullable=False, default=dict),
    Column("items", JSON, nullable=False, default=list),
    Column("findings", JSON, nullable=False, default=dict),
    Column("started_at", DateTime(timezone=True), nullable=False),
    Column("finished_at", DateTime(timezone=True), nullable=True),
)

competitor_reports = Table(
    "competitor_reports", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("site_id", PK, ForeignKey("sites.id", ondelete="CASCADE"), nullable=False, index=True),
    Column("kind", String(30), nullable=False),
    Column("report", JSON, nullable=False),
    Column("created_at", DateTime(timezone=True), nullable=False),
)

admins = Table(
    "admins", metadata,
    Column("id", PK, primary_key=True, autoincrement=True),
    Column("username", String(100), nullable=False, unique=True),
    Column("password_hash", String(255), nullable=False),
    Column("created_at", DateTime(timezone=True), nullable=False),
)

kv = Table(
    "kv", metadata,
    Column("key", String(100), primary_key=True),
    Column("value", JSON, nullable=False),
)
