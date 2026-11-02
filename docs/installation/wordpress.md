# Install the Lite edition on WordPress

Requirements: WordPress 6.0 or newer, PHP 7.4 or newer, MySQL or MariaDB with InnoDB and utf8mb4. WooCommerce is optional. No SSH, Docker, Python or Redis is needed.

1. Download `website-intelligence-agent-lite.zip`.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, choose the file and select **Install Now**.
3. Select **Activate**.
4. Open **Site Assistant** in the admin menu.
5. A first scan starts by itself. Keeping the **Status** tab open makes it finish faster, and it also runs in the background through WP-Cron.
6. Check **What the assistant reads** to confirm what was learned.
7. Open **Chat widget** to set the title, colors and messages. The widget is on by default.

To open the chat from your own button or link, add the class `js-wia-open` to it.

8. Under **Chat widget → Contact and handoff**, enter how visitors can reach you. Without this, the assistant links your contact page if it finds one.

## How it talks with visitors

- **Several matches:** «قیمت کوله پشتی چنده؟» on a shop with several backpacks lists them with live prices and offers each as a button.
- **A word the site lacks:** it says which word it could not find, and offers what it does have, or the site's main categories.
- **Rejection:** «نه، این نبود» brings back the alternatives. «نه، قیمت X چنده؟» answers the new question.
- **Handoff:** after two misses in a row, or when asked how to reach you, it gives your contact details as buttons.
- **Small talk:** greetings, thanks, farewells, "who are you" and "how are you".

It still writes no sentences of its own: every answer is a quote, a fixed reply, or a fact read live from WooCommerce.

## How it learns

- **Your content:** every page, post and product, updated as soon as you edit it.
- **Your visitors:** when a visitor's word is not on the site and the visitor then settles on a page, that is one vote. After three votes from different conversations, the word leads to that page. Review, approve or reject these under **Learning**, or teach a phrase yourself. Turn off automatic activation there if you prefer to approve each one.
- **Other sites:** under **Learning → Reference sites**, add up to five sites, such as competitors or suppliers. Their public pages are read in the background, at most 30 per site, honouring robots.txt. Only the words they use are kept. They are never used to answer visitors: they show you topics they cover and you do not.

**Knowledge map** shows all of this as a graph, with the words visitors ask about that your site lacks, and how the share of answered questions changes over time. Counters keep numbers and single words only, so they work with conversation logging off.

## What it learns

Published pages, posts and WooCommerce products by default. You can turn on other public post types under **Advanced**. Category and brand descriptions are included too.

It does not read drafts, password-protected pages, catalog-hidden products, or anything outside your WordPress database.

Edits apply immediately. A daily sweep catches bulk edits, imports and direct database changes.

## Removing it

Deleting the plugin keeps its data, unless you tick **When the plugin is deleted, also delete all its data** under **Advanced** first.

## Limits of this edition

- It finds and quotes your site's text. It does not write new sentences, summarise several pages or reason across them.
- Search matches words and their variants, not meaning. A question using entirely different words from your content is not answered until visitors or you teach the phrase.
- Learned phrases must match the visitor's unknown words exactly. «ساک» learned for one page does not cover «ساک دستی».
- Reference sites are read with WordPress's safe HTTP functions, which refuse private addresses, also after redirects. A site whose DNS changes between the check and the request is not caught.
- It answers in the language of the question, but quotes your content in the language it was written. It does not translate.
- Only exact duplicate pages are detected. Near-duplicates are not.
- It has no competitor research or automatic self-evaluation. Those need the Self-Hosted edition.
- JavaScript-only page builders that store no text in the post content cannot be read. They show up as low "Learned" counts on the Status tab.

For generated answers, run the Self-Hosted edition on your own server and connect it under **Advanced → Your own self-hosted engine**. This is off by default. When on, visitors' questions are sent to the address you enter.

## Tested on

The test suite in `tests/wordpress/` installs the real ZIP on WordPress with PHP 8.2 and MariaDB 10.11, with and without WooCommerce, and includes a 3000-article site. See [the status table in the README](../../README.md#project-status) for what has not been tested yet.
