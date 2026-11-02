# Troubleshooting

## WordPress Lite

**The scan stays on "running".** WP-Cron may be disabled or your site has little traffic. Keep the Status tab open, because that page advances the scan itself.

**A page is not learned.** Check that it is published and not password protected, and that its content type is enabled under Advanced. Page builders that keep text outside the post content cannot be read.

**The widget does not appear.** Check that it is enabled on the Chat widget tab, then clear your page cache. A security plugin that blocks the REST API will also stop it: `/wp-json/wia/v1/*` must stay public.

**Answers are often "not found".** Lite matches words, not meaning. Use the Knowledge tab's test box to see what is found, then add content or a question and answer. The Conversations tab lists unanswered questions.

## Self-Hosted

**Login does nothing on `http://server-ip:8000`.** Session cookies are HTTPS-only. Use `http://127.0.0.1:8000` through an SSH tunnel, or the `https` profile. Avoid `WIA_COOKIE_SECURE=false` on a public address, because it sends the admin session unencrypted.

**The crawl says "blocked" or stops at once.** The site's robots.txt disallows crawling or could not be read, or the URL resolves to a private address. To crawl a site on your own LAN, add its host name to `WIA_CRAWLER_ALLOW_HOSTS`.

**The crawl found few pages.** The site may need JavaScript. Set `WIA_CRAWLER_JS=true` and install Playwright. Also check `WIA_CRAWLER_MAX_PAGES`.

**Answers are slow.** Check the system status page and `docs/models.md`. On a CPU, use a smaller model or no chat model.

**Model is shown as unavailable.** Confirm the model is installed: `docker compose exec ollama ollama list`. Downloads continue in the background, and the jobs list shows progress.

**The widget shows nothing.** Its host must match the site URL you registered. `www.` is ignored but other subdomains are not. Check the browser console for a 403 response.

**An answer is rejected and quoted instead.** That is verification at work. In **Test chat**, the notes show `llm_rejected` and the reason. A model that often fails this may be too small for your language.
