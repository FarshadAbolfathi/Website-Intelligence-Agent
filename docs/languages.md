# Languages

Persian, Arabic and English are handled at every stage, not only in the interface.

## What is handled

| Stage | Behaviour |
|---|---|
| Letters | ي/ی, ك/ک, ة/ه, أ/إ/آ/ا and ؤ/و are unified for matching. Harakat and kashida are removed |
| Digits | ۱۲۳, ١٢٣ and 123 are the same. Thousands separators are ignored, so `۲٬۵۰۰٬۰۰۰` matches `2500000` |
| Spacing | The half-space counts as a space. `لپ تاپ`, `لپ‌تاپ` and `لپتاپ` match each other |
| Word forms | Persian plurals, the Arabic article and attached letters (`لمدة` / `مدة`), English plurals |
| Typos | One wrong letter is tolerated in a rare word of five or more letters. Common words, and question words such as «میرسه», are never "corrected", because that creates confident wrong answers |
| Colloquial forms | Every word also matches its form without a colloquial or possessive ending: «روزه» → «روز», «سفارشم» → «سفارش», «آدرستون» → «آدرس». For a word the site does not contain, a trailing «ی» is also removed («تولیدی» → «تولید») and then it may match the start of a longer word («کوه» → «کوهنوردی») |
| Synonyms | Groups in `shared/language.json`: گارانتی/ضمانت, مرجوعی/عودت/بازگشت, قسطی/اقساطی, ارسال/پست/تحویل, بچه/کودک, جنس/پارچه, مدرسه/مدارس and others, including English and Arabic words. A word is found if any word of its group is on the site |
| Question words | Price, availability and colloquial verbs («چنده», «میفرستین», «بدم», «میاد») never block an answer, and an unknown word that looks like a verb («نمیشه») does not either. «کیف», «قد» and «هل» are Persian words; they are treated as the Arabic question words only in Arabic questions |
| Language detection | Separates Persian from Arabic by letters and common words, for questions as short as one word |
| Answers | Fixed texts exist in all three languages. A chat model is told which language to use, and evaluation checks the result |

## Setting the language

- **Answers:** follow the language of each question. The default applies when a question is ambiguous.
- **Widget:** the widget's default language decides its interface text and direction. Answer bubbles set their own direction from their content.
- **Dashboard:** Persian and English. WordPress admin: Persian, Arabic and English, following the WordPress site language.

## How well it understands

`tests/wordpress/suite-conversation.php` and `suite-conversation-service.php` ask questions the way customers write them, colloquial and formal, with follow-ups, and check each answer for the fact it must contain. Measured on the Lite edition with no language model:

| Test business | Result |
|---|---|
| A bag shop, 37 core questions and dialogues. These were used while tuning the rules | 37/37 |
| The same shop, 7 hard questions (idioms, either-or questions, other languages) | 5/7 |
| A language school, 21 questions **not** used for tuning | 20/21 |

The shared evaluation sets in `shared/eval` extend this to Arabic, English, mixed-language and multi-turn questions, and score both editions with the same code. See [evaluation](evaluation.md).

The two hard misses show the limit of an edition that quotes and has no model: «اگه خوشم نیومد میتونم پس بدم؟» (an idiom for returning an item) and «تولیدی هستید یا فروشگاه؟» (an either-or question). These are small sample sites, not real ones.

## Known weaknesses

- Stemming is deliberately light. Persian verb conjugations are not unified, and only the broken plurals listed as synonyms (such as مدارس/مدرسه) are.
- Synonyms are a fixed list. A site's own vocabulary is better taught through the **Learning** tab. Semantic search covers some of this on the Self-Hosted edition; Lite has no such fallback.
- A question in one language about content in another works only with embeddings. Without a chat model, the answer quotes the content in its own language and says so.
- Very short questions are hard to classify. A single word shared by Persian and Arabic falls back to the site's default language.
- Mixed messages are decided by their grammar words: «international shipping دارید؟» is Persian, "Is there گارانتی?" is English. A message with no grammar words at all falls back to counting letters.
- Arabic dialects are covered only through their common question words («ابي», «شو», «وين», «شلون»). Broken plurals («أيام» for «يوم», «مقاهي» for «مقهى») are not unified.
- The language-quality metric checks the language and script of an answer, not its style. Fluency needs human review. See [the model notes](models.md).
