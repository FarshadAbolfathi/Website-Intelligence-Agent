# Languages

Persian, Arabic and English are handled at every stage, not only in the interface.

## What is handled

| Stage | Behaviour |
|---|---|
| Letters | ي/ی, ك/ک, ة/ه, أ/إ/آ/ا and ؤ/و are unified for matching. Harakat and kashida are removed |
| Digits | ۱۲۳, ١٢٣ and 123 are the same. Thousands separators are ignored, so `۲٬۵۰۰٬۰۰۰` matches `2500000` |
| Spacing | The half-space counts as a space. `لپ تاپ`, `لپ‌تاپ` and `لپتاپ` match each other |
| Word forms | Persian plurals, the Arabic article and attached letters (`لمدة` / `مدة`), English plurals |
| Typos | One wrong letter is tolerated in a rare word of five or more letters. Common words are never "corrected", because that creates confident wrong answers |
| Language detection | Separates Persian from Arabic by letters and common words, for questions as short as one word |
| Answers | Fixed texts exist in all three languages. A chat model is told which language to use, and evaluation checks the result |

## Setting the language

- **Answers:** follow the language of each question. The default applies when a question is ambiguous.
- **Widget:** the widget's default language decides its interface text and direction. Answer bubbles set their own direction from their content.
- **Dashboard:** Persian and English. WordPress admin: Persian, Arabic and English, following the WordPress site language.

## Known weaknesses

- Stemming is deliberately light. Persian verb conjugations and Arabic broken plurals (`كتب` / `كتاب`) are not unified. Semantic search covers some of this on the Self-Hosted edition; Lite has no such fallback.
- A question in one language about content in another works only with embeddings. Without a chat model, the answer quotes the content in its own language and says so.
- Very short questions are hard to classify. A single word shared by Persian and Arabic falls back to the site's default language.
- The language-quality metric checks the language and script of an answer, not its style. Fluency needs human review. See [the model notes](models.md).
