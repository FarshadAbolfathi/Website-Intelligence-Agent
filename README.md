# Website Intelligence Agent

A site assistant you host yourself. It learns your website's content and answers visitors in Persian, Arabic and English, with links to its sources. When your site has no answer, it says so instead of guessing.

There is no central service. Your content and conversations stay on your own server, and no cloud AI service is required.

| Asking which product the visitor means | Knowledge map in the WordPress admin |
|---|---|
| ![The widget lists matching products with live prices and offers them as buttons](docs/images/wp-dialog-clarify.png) | ![Knowledge map: topics, pages, learned phrases and gaps](docs/images/wp-knowledge-map-selected.png) |

> **Status: early pre-release, not yet used in production.** See [Project status](#project-status) for what has and has not been tested.

## Two editions

Choose by what your hosting can run.

| | **Lite** (WordPress plugin) | **Self-Hosted** (your VPS or server) |
|---|---|---|
| Install | Upload a ZIP in WordPress | Docker Compose or manual |
| Needs | PHP 7.4+, MySQL | Docker; 2 GB RAM, 8 GB+ with a chat model |
| Learns from | WordPress content, through its APIs | Any website, by crawling |
| Search | Words and their variants | Words and meaning (hybrid) |
| How answers are made | **Quotes your site's text. Writes no new sentences** | Local language model with source checks, or quotes if no model |
| Dialogue | Clarifying questions with buttons, rejection («نه، این نبود»), handoff to your contact details | Follow-up questions; no clarifying buttons yet |
| Learning | From conversations, plus a knowledge map and reference sites | Self-evaluation and competitor reports |
| Prices and stock | Read live from WooCommerce | Read from page data, refreshed when old |
| Competitor research | No | Yes |
| Self-evaluation | No | Yes |
| Language model | None | Ollama or llama.cpp, on your server |

Shared hosting cannot run a real language model, so Lite does not claim to. It finds the right passage and quotes it. For generated answers, run the Self-Hosted edition; a Lite site can optionally use it as its engine.

## What it does

- **Learns a site:** sitemaps, robots.txt, internal links, main-content extraction, product data, duplicate detection and language detection.
- **Stays current:** re-crawls only what changed, records price changes, and removes pages that disappear.
- **Answers with evidence:** every answer links its sources. Generated answers are checked against those sources, and a number that is not in them rejects the answer.
- **Handles three languages properly:** ی/ي, ک/ك, ۱۲۳/١٢٣/123 and half-spaces are unified, and Persian is told apart from Arabic. See [language handling](docs/languages.md).
- **Keeps competitors separate** (Self-Hosted): compares public products and topics, with source and date on every item. This data cannot reach visitors' answers.
- **Holds a conversation** (Lite): when several products match it asks which one, with live prices; when a word is not on the site it says which word and offers what it does have; «نه، این نبود» brings back the alternatives; after two misses in a row it gives your contact details.
- **Learns from visitors** (Lite): if visitors asking for a word the site lacks keep settling on the same page, that word starts leading to that page after three conversations. The owner can approve, reject or teach phrases directly. Answers still come only from the site's own pages.
- **Shows what it knows** (Lite): a knowledge map in the admin draws the site's topics, pages, learned phrases, unanswered words and topics found only on reference sites you choose. Reference sites are read for their vocabulary only and never used to answer visitors.
- **Tests itself** (Self-Hosted): generates questions from your content and measures retrieval, groundedness, unsupported claims, language and latency, including whether it declines questions it should not answer.

## Quick start

**WordPress:** download `website-intelligence-agent-lite.zip`, then go to **Plugins → Add New → Upload Plugin**. [Full guide](docs/installation/wordpress.md).

**Self-Hosted:**

```bash
git clone <repository-url>
cd website-intelligence-agent
cp .env.example .env        # then set POSTGRES_PASSWORD
docker compose up -d
docker compose logs api | grep "setup token"
```

Open http://127.0.0.1:8000. [Full guide](docs/installation/self-hosted.md), including HTTPS and adding models.

## Documentation

- [Install on WordPress](docs/installation/wordpress.md), including the limits of Lite
- [Install on a server](docs/installation/self-hosted.md)
- [Choosing a local model](docs/models.md), with measured results
- [Language handling](docs/languages.md)
- [Architecture](docs/architecture/overview.md)
- [API](docs/api/README.md)
- [Troubleshooting](docs/troubleshooting/README.md)
- [Contributing](CONTRIBUTING.md) · [Security policy](SECURITY.md) · [Changelog](CHANGELOG.md)

## Project status

Tests use realistic sample sites served over real HTTP, not real customer sites.

| Area | Status |
|---|---|
| Crawling, extraction, change detection, removal (fa, ar, en sample sites) | Tested |
| SSRF protection, including redirects and DNS rebinding | Tested |
| Retrieval, follow-up questions, honest refusal | Tested on SQLite |
| Generated answers with a real local model | Tested with one model; slow on the test machine. See [results](docs/models.md) |
| Competitor reports and their separation from answers | Tested |
| Self-evaluation, including that it fails a broken assistant | Tested |
| Dashboard and widget in a real browser | Tested in Chromium |
| WordPress plugin from its ZIP, with WooCommerce, on PHP 8.2 | Tested, including a 3000-article site |
| Lite dialogue, learning from conversations, knowledge map, reference sites (over HTTP to a local test site) | Tested, also in Chromium on desktop and mobile |
| **PostgreSQL** | **Not run yet.** Tests exist in `tests/integration/test_postgres.py` |
| **Qdrant** | **Not run yet.** Tests exist in `tests/integration/test_qdrant.py` |
| **Docker image and `docker compose up`** | **Not run yet** |
| **Semantic search with real embeddings** | **Not run yet** |
| **llama.cpp and Playwright rendering** | **Not run yet** |
| **PHP 7.4** | **Not run yet.** The code avoids PHP 8 syntax |
| **Real shared hosting** | **Not run yet** |
| Real websites at scale | Not run yet |
| **Reference sites on the real internet, and the knowledge map on a large real site** | **Not run yet.** Tested on a local test site and small sample shops |

## License

[GPL-3.0-or-later](LICENSE). WordPress plugins must be GPL-compatible, and using one license keeps the two editions simple.
