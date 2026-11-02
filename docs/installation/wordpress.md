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

## What it learns

Published pages, posts and WooCommerce products by default. You can turn on other public post types under **Advanced**. Category and brand descriptions are included too.

It does not read drafts, password-protected pages, catalog-hidden products, or anything outside your WordPress database.

Edits apply immediately. A daily sweep catches bulk edits, imports and direct database changes.

## Removing it

Deleting the plugin keeps its data, unless you tick **When the plugin is deleted, also delete all its data** under **Advanced** first.

## Limits of this edition

- It finds and quotes your site's text. It does not write new sentences, summarise several pages or reason across them.
- Search matches words and their variants, not meaning. A question using entirely different words from your content may not be answered.
- It answers in the language of the question, but quotes your content in the language it was written. It does not translate.
- Only exact duplicate pages are detected. Near-duplicates are not.
- It has no competitor research or automatic self-evaluation. Those need the Self-Hosted edition.
- JavaScript-only page builders that store no text in the post content cannot be read. They show up as low "Learned" counts on the Status tab.

For generated answers, run the Self-Hosted edition on your own server and connect it under **Advanced → Your own self-hosted engine**. This is off by default. When on, visitors' questions are sent to the address you enter.

## Tested on

The test suite in `tests/wordpress/` installs the real ZIP on WordPress with PHP 8.2 and MariaDB 10.11, with and without WooCommerce, and includes a 3000-article site. See [the status table in the README](../../README.md#project-status) for what has not been tested yet.
