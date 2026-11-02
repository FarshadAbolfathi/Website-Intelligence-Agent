# Origin and audit

This project began as a private, single-store WordPress assistant built into a theme. This note records what was reused and why, without that store's details.

## Reused

| From the original | Now |
|---|---|
| Persian normalisation, light stemming, typo correction toward rare words | Extended to Arabic and English in `WIA_Text` and `core/text` |
| BM25 with title weighting and a coverage gate | Same parameters; postings moved into SQL |
| Sections to chunks, hash-based change detection, removal when a source disappears | `WIA_Ingest`, `WIA_Knowledge`, `core/knowledge` |
| Removing instruction-like sentences from content | `shared/language.json` and both editions |
| Admin idea: coverage report, unanswered questions, test box | `WIA_Admin` |

## Not reused

The original dialog logic was about 70% specific to one product category: recommendations, a personality quiz and domain vocabulary. A general product has no use for it, so it is not in this repository, nor is any of that store's knowledge, branding or data.

## Problems fixed in the move

| Problem in the original | Fix |
|---|---|
| The first full scan ran inside a web request, risking a timeout on shared hosting | Resumable batches, and none during activation |
| The whole search index was loaded into PHP memory on each request | Postings in MySQL, reading only the query's own terms |
| Each save re-read every row for duplicate and conflict checks | Indexed lookups |
| A cached index could stay stale within a request | Removed, along with the cache |
| A nonce in the public chat broke behind page caching | Public REST endpoints protected by a rate limit |
| Conversation logs had no retention limit | Retention setting, daily purge and a delete action |
| Personal contact details appeared in help text | Removed |

## Near-duplicate detection

The original compared near-duplicates (word 3-gram overlap) by reading all rows, which does not scale. Lite detects exact duplicates only, as a deliberate trade-off. Self-Hosted uses SimHash fingerprints.
