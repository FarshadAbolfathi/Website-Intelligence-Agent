# Changelog

## 0.2.0 (unreleased)

### Widget (both editions)

- Redesigned: an assistant icon, a header with status, a restart button, source links as tags, a send button, and a full-screen panel with no background scrolling on phones
- Three animated dots while an answer is on its way, with a short pause scaled to the answer's length
- An optional message above the chat button a few seconds after the page loads, once per visit, with its own text and delay
- Common questions as buttons under the welcome message. Lite offers only those the site really answers
- Yekan Bakh first in the font stack when the site loads it, or from a font file the owner supplies; the font is not bundled because it is commercial
- Faster: only the launcher exists at page load, the panel and font are created on first open, and the script starts when the browser is idle. Lite prints its settings into the page, so no request is made until the first message

### On and off

- Lite: a switch at the top of the Status tab
- Self-Hosted: a switch at the top of each site's page. When off, the public chat API refuses messages too

### Conversation quality (both editions)

- Synonym groups (گارانتی = ضمانت, مرجوعی = عودت, قسطی = اقساطی, ship = ارسال and more)
- Colloquial and possessive endings («روزه», «سفارشم», «آدرستون»); for unknown words also a trailing «ی» and the start of a longer word («کوه» -> «کوهنوردی»)
- Colloquial verbs («میفرستین», «بدم», «میاد») no longer block an answer
- Price questions rank pages that state prices first
- Lite: a question that misses one word now quotes the closest passage and says which word was not found; a follow-up that names its own subject keeps it; contact questions quote the contact page when it holds the detail asked for
- Measured on two test businesses: a shop, 37 of 37 core questions and 5 of 7 hard ones; a language school whose questions were not used for tuning, 20 of 21

### Intelligence and evaluation (both editions)

- One evaluation dataset for both editions in `shared/eval`: a site in Persian, English and Arabic, 61 conversations with 75 turns (development set) and 44 conversations with 50 turns on a second site (held-out set), covering colloquial and mixed-language questions, typos, follow-ups, two-part questions, missing information, false assumptions and prompt injection. `core/evaluation/dataset.py` scores both editions with the same code and reports accuracy, hallucination measures, language consistency, latency and tokens
- Self-Hosted now has the dialogue rules Lite had: an honest partial answer that names the missing words and quotes the nearest text, contact questions answered from the contact page, farewell and "who are you", a follow-up that names its own subject keeps it, and a clarifying list when several products match. Its public API now returns `suggestions`
- New in both: two questions in one message are answered from their own pages; a product named only by its distinctive word («روبوستا موجوده؟», «فرق عربیکا با روبوستا») is recognised; an Arabic question is answered from the Arabic page when one exists; a mixed message such as «international shipping دارید؟» is answered in Persian; Arabic verbs are matched to their root («تشحنون» -> «شحن»); Gulf and Levantine question words («ابي», «شو», «وين») frame a question; FAQ keywords match plural and colloquial forms
- A product's price or stock is never given when the question asks about something else («ضمانت دستگاه اسپرسو چقدره؟» is about the warranty)
- Self-Hosted with a model: stock, and a product's price, are never passed to the model when the structured data could not be resolved. The evaluation caught a model saying «موجود است» about a product that was out of stock. Only sources relevant to the question are sent, and the prompt forbids merging facts from different sources
- Ollama and llama.cpp providers report token counts, so cost per conversation is measured

### Fixed

- Lite: splitting a two-part question used PHP `trim()` with Persian punctuation, which cut bytes off Persian letters
- «کیف» (bag), «قد» (height) and «هل» (cardamom) were dropped as stopwords, because after normalisation they are the same strings as Arabic question words. A question such as «کیف» had no subject at all. They are now ordinary words, and count as question words only in Arabic questions. Both editions.
- A question with a word the site does not know could borrow the previous message's subject and get that subject's answer. Both editions.
- A question word such as «میرسه» could be "corrected" into a subject word such as «مدرسه». Both editions.

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
