# Architecture

Two editions, one contract. The Self-Hosted edition is a Python modular monolith. The Lite edition is a PHP WordPress plugin. PHP and Python cannot share code, so they share data and behaviour instead.

## What the editions share

| Shared item | File | How drift is prevented |
|---|---|---|
| Normalisation, tokenising and language rules | `shared/normalization-vectors.json` | Both test suites run the same vectors |
| Stopwords, intent patterns, injection patterns | `shared/language.json` | Both editions load this file at runtime |
| Answer texts in fa/ar/en | `shared/chat-messages.json` | Both editions load this file at runtime |
| Chat widget | `shared/widget/wia-widget.js` | The same file is served by both |
| Public chat API shape | `docs/api/README.md` | The widget works unchanged against either edition |
| Search design | BM25 with postings in SQL, k1 1.4, b 0.7, coverage gate | Implemented twice, tested against the same behaviours |

## Self-Hosted modules

```text
core/                     no web framework, no database driver in the logic
  text/        normalisation, tokens, language detection
  security/    URL guard (SSRF), injection defences
  crawler/     robots, sitemaps, polite crawling; Fetcher interface (HTTP, Playwright)
  extractor/   HTML to sections, metadata and product facts; boilerplate removal
  knowledge/   schema, KnowledgeStore interface and SQL implementation, ingest pipeline
  embeddings/  Embedder interface (Ollama, none, hashing for tests)
  retrieval/   VectorIndex interface (Qdrant, SQL scan), hybrid retriever and confidence gate
  llm/         LLMProvider interface (Ollama, llama.cpp, none)
  chat/        answer pipeline, verification, messages
  competitor/  comparison reports
  evaluation/  question generation, metrics, findings
backend/
  api/         FastAPI app, auth
  services/    jobs, system status
  workers/     background worker
  container.py chooses implementations from settings
frontend/      React dashboard
```

Adding a model runtime, vector database or fetcher means writing one class that implements its interface and one branch in `backend/container.py`. No other code changes.

## Key decisions

**No Redis or Celery.** The job queue is a table with `FOR UPDATE SKIP LOCKED` on PostgreSQL. This keeps a service and its memory off a small VPS. Redis would be worth adding only for very high job throughput.

**Lexical search stays even with embeddings.** Product names, model numbers and exact terms are found better by BM25, and it keeps working if the model is down. Results are merged with reciprocal rank fusion.

**Prices never pass through a model.** Price and stock answers are built from structured data. The Lite edition reads them live from WooCommerce. The Self-Hosted edition uses JSON-LD or Open Graph data from the crawl, refreshes it from the live page when older than `WIA_VOLATILE_TTL_HOURS`, and otherwise states the date of the last check.

**Learning is RAG, not fine-tuning.** Site content changes constantly. Updating an index is immediate and reversible; retraining a model is neither.

## Separating sites and competitors

Every store and vector operation takes a `Scope(site_id, corpus, competitor_id)`.

- Each query filters on the scope.
- `ChatEngine._scope()` is the only place the chat path builds a scope, and it can only build `Corpus.OWN`. Retrieved hits are checked again before use.
- On Qdrant, own and competitor vectors are in separate collections, so a faulty filter still cannot mix them.
- A conversation ID from one site is ignored when presented with another site's key.

## Answer pipeline

```text
question
  -> language detection
  -> greeting or thanks             -> fixed text
  -> injection or secret probe      -> fixed refusal; the model is never called
  -> owner's FAQ                    -> owner's text
  -> catalog question               -> product list
  -> follow-up resolution           (adds the previous subject, without a model)
  -> hybrid retrieval
  -> comparison of two named products
  -> confidence gate                -> honest "not found"
  -> price or stock                 -> structured facts, with date
  -> generate with fenced sources   (or quote, when no model is configured)
  -> verify numbers and sentence support
       failed                       -> quote the site's text instead
```

### Dialogue in the Lite edition

`WIA_Chat::answer()` handles one question as above, without a model. `WIA_Chat::converse()` wraps it with the conversation state:

```text
«نه، این نبود»                     -> the previous answer's alternatives
«بله» after a single offer         -> answer that offer
contact question                   -> owner's contact details, or the contact page
several products, none fully named -> list with live prices, offered as buttons ("clarify")
gate fails                         -> learned phrase for the unknown words, if active
                                   -> otherwise name the unknown words and offer what exists
second miss in a row               -> append the contact details ("handoff")
```

`WIA_Learn` turns a miss followed by a confident answer into a vote for (unknown words -> that page). A phrase becomes active after three votes from different conversations and must match the unknown words exactly. It only changes which of the site's own pages is quoted.

`WIA_Graph` builds the knowledge map from the postings table: topics are words found on several pages, links are shared pages. `WIA_Reference` reads up to five owner-chosen sites through `wp_safe_remote_get`, honouring robots.txt, and stores only per-site word counts in `wia_ref_terms`. Nothing in the chat path reads those tables.

## Prompt injection

Crawled text is treated as data, in three independent layers:

1. Instruction-like sentences are removed before storage.
2. Sources are fenced in the prompt with a random per-request delimiter, and the system prompt says fenced text is never instructions.
3. Every generated answer is verified against its sources. A number that is not in the sources, or a sentence with little lexical support, rejects the answer.

Layer 1 is pattern matching and can be evaded. Layer 3 limits what a successful injection can do, but cannot prove an answer true.

## SSRF

`URLGuard` allows only http(s) on ports 80, 443, 8080 and 8443, and requires every address a host resolves to be public. Every redirect hop is checked again, and the connection is pinned to the validated address so DNS rebinding cannot redirect it. Sitemaps are parsed with entity expansion and network access disabled. Responses have a size limit.
