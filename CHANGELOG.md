# Changelog

## 0.2.0 (unreleased)

### Fixed

- «کیف» (bag), «قد» (height) and «هل» (cardamom) were dropped as stopwords, because after normalisation they are the same strings as Arabic question words. A question such as «کیف» had no subject at all. They are now ordinary words, and count as question words only in Arabic questions. Both editions.
- A question with a word the site does not know could borrow the previous message's subject and get that subject's answer. Both editions.

### Lite edition (WordPress)

- Clarifying questions: several matching products are listed with live prices and offered as buttons
- A miss says which word is not on the site and offers what is, or the site's main categories
- «نه، این نبود» brings back the alternatives; «بله» accepts a single offer
- Contact details and handoff after two misses in a row, with call, mail, WhatsApp and Telegram buttons
- Farewell, "who are you" and "how are you" replies; starter questions under the welcome message
- Learning from conversations: phrases become active after three votes from different conversations, with owner review
- Knowledge map: an interactive graph of topics, pages, learned phrases, gaps and reference-only topics, with growth charts
- Reference sites: up to five other sites read for vocabulary only, honouring robots.txt; never used in answers
- Live price added to quoted product answers

### Shared

- The public chat response gains `suggestions` and `actions`, and the widget configuration gains `starters`. The Self-Hosted edition returns them empty for now.

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
