# Changelog

## 0.1.0 (unreleased)

First pre-release.

### Self-Hosted edition

- Crawler with robots.txt, sitemaps, internal links, conditional requests and rate limiting
- Main-content, metadata and product extraction, with repeated-block removal
- Knowledge base with hash-based change detection, duplicate detection and removal of vanished pages
- Hybrid search: BM25 in SQL plus vectors (Qdrant or SQL scan), with a confidence gate
- Local model providers: Ollama and llama.cpp
- Chat pipeline with structured price and stock answers, follow-up handling and answer verification
- Competitor crawling and comparison reports, kept separate from customer-facing answers
- Self-evaluation with measurable metrics
- FastAPI backend, database-backed job queue, schedules and a React dashboard in Persian and English

### Lite edition (WordPress)

- Standalone plugin installed from a ZIP, with Persian, Arabic and English admin text
- Learns posts, pages, WooCommerce products and category descriptions
- Resumable batched scanning and instant updates when content changes
- MySQL-backed BM25 search with flat memory use
- Live price and stock from WooCommerce, owner FAQ, chat widget and conversation review
- Optional connection to your own Self-Hosted engine, off by default
- Optional complete data removal on uninstall

### Shared

- Language rules, intent patterns and answer texts in `shared/`, used and tested by both editions
- One chat widget and one public API shape
