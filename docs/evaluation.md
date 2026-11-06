# Evaluation and intelligence audit

This page explains how answer quality is measured, what the November 2026 audit found, what changed, and what is still weak. Every number here comes from a run on the test machine. None is estimated.

## How it is measured

Both editions are scored on the same data, by the same code.

| Part | File |
|---|---|
| Development set: a coffee business with pages in Persian, English and Arabic, 5 products, 1 FAQ, and a page with a planted instruction. 61 conversations, 75 turns | `shared/eval/site.json`, `shared/eval/cases.json` |
| Held-out set: a bicycle shop, 44 conversations, 50 turns. Written after the development fixes and run once before any change was made for it | `shared/eval/holdout/` |
| Scorer, standard library only | `core/evaluation/dataset.py` |
| Self-Hosted answers | `core/evaluation/selfhosted.py` (real extractor, ingestor and chat engine, with history handled as the public API does) |
| Lite answers | `tests/wordpress/suite-eval.php` (real WordPress, WooCommerce and `converse()`) |

The cases cover:

- Colloquial and formal Persian, English, Arabic, and the Gulf dialect.
- Mixed-language messages and typos.
- Follow-ups and long conversations that return to an earlier product.
- Two-part questions, comparisons, and questions the site cannot answer.
- False assumptions, and direct and indirect prompt injection.

Each turn states the kind of reply it needs and the facts it must or must not contain.

| Measure | Definition |
|---|---|
| Accuracy | Turns whose reply is of the right kind and contains the required facts. Hard cases are reported apart |
| False answers | A substantive answer to a question the site cannot answer. Hallucination |
| Unsupported numbers | Numbers in a reply that appear nowhere on the site and not in the question |
| Contradictions | A reply that states the opposite of the site, such as «موجود است» for a product that is out of stock |
| Injection leaks | A reply that follows a planted or typed instruction |
| Language consistency | The reply is in the language of the question |
| Follow-up accuracy | Accuracy on the second and later turns of a conversation |
| Latency, tokens | Measured per turn. Tokens are the counts the model runtime reports |

```bash
python -m core.evaluation.selfhosted --set dev --llm none --out dist/eval/dev.json
python -m core.evaluation.selfhosted --set dev --llm ollama:<model> --out dist/eval/dev-model.json
python -m core.evaluation.selfhosted --set dev --llm failing --out dist/eval/dev-failing.json   # every model call fails
python -m core.evaluation.dataset dist/eval/dev.json --baseline dist/eval/before.json
bash tests/wordpress/run.sh        # Lite: both sets, floor 95% and 90%
pytest tests/integration/test_eval_dataset.py   # Self-Hosted: both sets, and that a failing model changes nothing
```

The checks are lexical: a correct paraphrase that avoids the expected words fails, and a subtle misreading that reuses them passes. Read failures and samples by hand, especially model answers.

## Audit findings

| Area | Finding | Effect on answers | Action |
|---|---|---|---|
| Dialogue, Self-Hosted | None of Lite's dialogue rules existed: no partial answer, no contact handling, no farewell, no clarifying list | 40 of 73 turns right, against 62 for Lite on the same questions | Ported, with the same rules in both editions |
| Product reference | A product was found only when half its name was in the question | «روبوستا موجوده؟» and «فرق عربیکا با روبوستا» failed. With a model, the first was answered «موجود است» for a product that is out of stock | Products are also recognised by a word only their name contains |
| Price and stock with a model | When the product could not be resolved, the question went to the model | The model stated availability: the one contradiction in all runs | Stock, and a product's price, never go to the model |
| «چقدره» | Read as a price question even when it asked about something else | «ضمانت دستگاه اسپرسو چقدره؟» would have been answered with the price | Price or stock only when the other words belong to the product name |
| Two-part questions | One answer, from one page | Half of «هزینه ارسال چقدره و گارانتی چقدره؟» was dropped silently | Each part is answered from its own page, and a part the site cannot answer is named |
| Source language | The best-scoring page won regardless of language | Arabic questions answered from the Persian page | A page in the question's language is preferred when it covers the question as well |
| Mixed messages | Language decided by counting letters | «international shipping دارید؟» answered in English | Decided by the grammar words |
| Arabic | Present-tense verbs and Gulf question words were unknown words | «هل تشحنون…», «ابي… شو الشروط؟» declined | Verb roots tried for unknown words; dialect question words are framing words in Arabic |
| Follow-ups | «قیمتش» was not seen as «قیمت», and «هزینه‌اش» left «اش» as a word | A price follow-up answered from an unrelated page that mentions «قیمت» | Fixed in the follow-up decision only. Changing it everywhere broke two held-out answers, so that was reverted |
| FAQ keywords | Matched as text | «دانشجوها» missed the keyword «دانشجو» | Matched as stems |
| Model context | Up to 4 sources, including weak matches | More tokens, and once a place from one page merged with an event from another | Only sources close to the best match, and a prompt rule against merging sources |
| Lite, PHP | `trim()` with Persian punctuation works on bytes | Cut the first byte of «د» and broke the second part of a question | Unicode-aware pattern |
| Cost | Token use was not recorded | No basis for cost per conversation | Ollama and llama.cpp providers report tokens |

Reviewed and left unchanged, because the runs gave no reason to change them:

- **Chunking.** No failure traced to a chunk boundary.
- **Prompt-injection defences.** No leak in any run, direct or planted.
- **Fallback when the model or vector search fails.** The failing-model run is identical to the no-model run.
- **Tenant isolation.** Conversation state is looked up with the site id, and the chat path can build only the site's own scope. The competitor isolation tests were tightened rather than loosened.
- **Long-term memory.** There is none: conversation state holds only the page under discussion and recent turns. Visitor profiles were not added, because nothing in the evaluation needs them and they would add personal data.
- **Streaming.** There is none. Lite and the no-model path answer in milliseconds; for a model it would help perceived speed. See recommendations.

## Results

Development set, 73 scored turns (2 hard turns reported apart).

| Run | Correct | Follow-ups | Persian | English | Arabic | False answers | Contradictions | Unsupported numbers |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Lite, before | 62 (85%) | 86% | 41/46 | 13/16 | 8/11 | 0 | 0 | 0 |
| Lite, after | 72 (99%) | 93% | 46/46 | 15/16 | 11/11 | 0 | 0 | 0 |
| Self-Hosted, no model, before | 40 (55%) | 29% | 26/46 | 8/16 | 6/11 | 0 | 0 | 0 |
| Self-Hosted, no model, after | 72 (99%) | 93% | 46/46 | 15/16 | 11/11 | 0 | 0 | 0 |
| Self-Hosted, model, before | 41 (56%) | 36% | 26/46 | 9/16 | 6/11 | 0 | **1** | 0 |
| Self-Hosted, model, after | 72 (99%) | 93% | 46/46 | 15/16 | 11/11 | 0 | 0 | 0 |
| Self-Hosted, model failing on every call | 72 (99%) | 93% | 46/46 | 15/16 | 11/11 | 0 | 0 | 0 |

Every run had no injection leaks. Language consistency was 100% after the changes (98.7% before, one mixed message).

The development set was used to find and fix these problems, so its "after" figures are optimistic. The held-out set is the better estimate for a new site:

| Held-out set, 50 turns | Correct |
|---|---:|
| Lite, first run, before any change for it | 47 (94%) |
| Self-Hosted, no model, first run | 47 (94%) |
| Both, after fixing the two general bugs it found | 50 (100%), no longer an unbiased figure |
| Self-Hosted, model, after | 49 (98%). The one miss is correct: «لنت ترمز گارانتی ندارد.», where the check expected «ندارند» |

The two editions gave the same answers on both sets.

### Latency and cost

The model was `qwen2.5-coder:14b` through Ollama, on a 24 GB Apple Silicon laptop. Lite and the no-model path have no token cost.

| Run | Model calls | Input tokens per call | Output tokens per call | Model answer, median | Model answer, 95th percentile |
|---|---:|---:|---:|---:|---:|
| Before | 26 | 678 | 41 | 12.5 s | 157 s |
| After | 33 | 653 | 38 | 8.0 s | 16.7 s |

Three things to keep in mind when reading this table:

- The first run started with the model cold, and one call timed out at 180 s, so most of the latency difference is the model being warm, not the changes.
- More questions reach the model now, because more are answered. Tokens per conversation rose from 307 to 374.
- Input per call fell by about 4% because weak sources are no longer sent.

Without a model, both editions answer in under 5 ms at the 95th percentile.

## Remaining failures and limits

- **"What's the minimum order?" after a wholesale question.** The wholesale page says "from 10 kg per month" and never uses "minimum" or "order". Only meaning-based search or a model reading the conversation would connect them.
- **A one-word question** («قهوه», «دوچرخه») gets a quote of the best page. With a model, a refusal on such a question now falls back to that quote. A choice of topics would be better; that is not built.
- **Wording that no rule covers.** Idioms and either-or questions on the shop suite («اگه خوشم نیومد میتونم پس بدم؟», «تولیدی هستید یا فروشگاه؟»), and Arabic broken plurals («أيام» for «يوم»).
- **A Persian question about English-only content** («به ترکیه هم ارسال دارید؟» when only the English page names Turkey) was answered here only because the Persian shipping page also matched.
- **Small, made-up sites.** Real sites have more pages, more overlap and messier text. Expect lower numbers until this is run on real content.
- **One model, measured on one machine.**
- **Not yet in the Self-Hosted edition:** rejection («نه، این نبود») and handoff to contact details.

## Fine-tuning assessment

Not justified now, for four reasons:

- **Every failure was fixed or explained without training.** The failures were retrieval, dialogue and data-flow problems, and fixing those raised the model run from 56% to 99%.
- **The one hallucination came from sending the model a question it should never have seen.** It was fixed by routing, which training would not have fixed reliably.
- **Site knowledge changes daily.** Retrieval updates it immediately and reversibly; a fine-tuned model would have to be retrained and would still need retrieval.
- **There is no clean training data.** There are no reviewed real conversations, and the project rule against unvalidated synthetic data rules out generating them.

Revisit fine-tuning only if real conversations show a stable problem of tone or phrasing that prompts cannot fix. Training would then use reviewed transcripts. It would be split into training, validation and test sets by conversation, with no site appearing in two splits. It would be judged on these evaluation sets against the base model, and the base model would stay configured so the change can be rolled back.

## Production recommendations

- Run `tests/wordpress/run.sh` and `pytest tests/integration/test_eval_dataset.py` on every change to language rules. A rule that helps one question can break another, as the reverted framing-word change showed.
- When a real failure is reported, add it to the development set, never to the held-out set. Write a new held-out set from time to time and record its first run.
- With a model, use a GPU or a smaller model, and add streaming before putting it in front of visitors: 8 seconds per answer is too slow. Keep a timeout well below the current 180 s, because a timed-out answer falls back to quoting anyway.
- Keep message logging off unless it is needed (Lite setting), and keep the retention period short. Conversations can contain personal data.
