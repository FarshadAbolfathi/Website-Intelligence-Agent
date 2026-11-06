# Choosing a local model

A model is optional. With none, the assistant quotes your site's text, responds in milliseconds and needs very little memory. Add one for answers written in natural sentences.

## Embedding model (meaning-based search)

`bge-m3` is the default: multilingual, a 1.2 GB download, about 2 GB of RAM. Its quality on this project's Persian and Arabic content has **not been measured yet**. `tests/integration/test_real_models.py` does this once the model is installed:

```bash
WIA_TEST_EMBED=bge-m3 pytest tests/integration/test_real_models.py -s
```

## Chat model

The dashboard shows each model's download size and memory needs before you download. These figures are practical minimums for CPU use.

| Model | Download | Min RAM | Notes |
|---|---:|---:|---|
| `qwen2.5:3b` | 2.2 GB | 5 GB | Smallest useful option. Expect weaker Persian |
| `qwen2.5:7b` | 4.7 GB | 8 GB | Balanced |
| `qwen2.5:14b` | 9 GB | 16 GB | Better quality; a GPU is recommended |
| `aya:8b` | 4.8 GB | 8 GB | Built for many languages. Research-only licence: check before commercial use |

The language ratings shown in the dashboard are general expectations, **not measurements from this project**, and every catalog entry is marked as untested. Run the evaluation on your own site before relying on a model.

## Measured results

**Setup:** one run on 2 November 2026 with `qwen2.5-coder:14b`, on a 24 GB Apple Silicon laptop that was also busy with other work. This is a code-focused variant, used because it was already installed. The content was small sample sites, so this shows the pipeline works, and does not rank models.

| Measure | fa | ar | en |
|---|---:|---:|---:|
| Questions | 12 | 8 | 12 |
| Retrieval accuracy | 1.0 | 1.0 | 1.0 |
| Groundedness | 1.0 | 1.0 | 1.0 |
| Unsupported claims | 0 | 0 | 0 |
| Language check | 1.0 | 1.0 | 1.0 |
| Out-of-scope correctly declined | 1.0 | 1.0 | 1.0 |

The run also found a real defect: in English, half of the questions of the form "Tell me about X" were wrongly declined. After the fix, a repeat English run passed all 11 of its questions and answered all of those in scope. The Persian and Arabic figures above predate the fix.

Sample answers from that run:

| Language | Question | Answer |
|---|---|---|
| fa | شرایط ارسال سفارش چیست؟ | سفارش‌ها به سراسر کشور با پست پیشتاز بین ۲ تا ۴ روز کاری ارسال می‌شوند. |
| ar | ما هي مدة الضمان على الأجهزة؟ | جميع الأجهزة تأتي مع ضمان لمدة سنتين. |
| en | How long do I have to return an item? | You may return any unused item within 30 days for a full refund. |

**Speed was poor:** a generated answer took about 1 to 3 minutes on this busy machine. That is too slow for visitors. Faster hardware, a GPU or a smaller model should help, but that has not been measured. Price, stock, FAQ and refused questions do not use the model and took milliseconds.

## Reading the evaluation

Groundedness and relevance compare words and exact numbers against the source. They catch invented figures and off-source sentences. They do not catch a subtle misreading that reuses the source's words, and the language check looks at language and script, not style. Have a fluent reader review samples, especially in Persian and Arabic.

## Fine-tuning

Not needed. Your content reaches the model through retrieval, and updating an index is immediate and reversible. Fine-tuning would only be worth examining later for tone, given enough reviewed conversations. The measured reasons are in [evaluation](evaluation.md#fine-tuning-assessment).
