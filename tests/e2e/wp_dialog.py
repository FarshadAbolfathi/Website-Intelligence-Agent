"""Dialogue widget and knowledge map in a real browser, in Persian. Saves screenshots.

    docker compose -f deployment/docker/wp-test/docker-compose.yml exec -T cli wp eval-file /tests/e2e-dialog-setup.php
    python tests/e2e/wp_dialog.py http://localhost:8090 OUTPUT_DIR
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

base, out = sys.argv[1], Path(sys.argv[2])
out.mkdir(parents=True, exist_ok=True)
failures: list[str] = []
BOT = "#wia-widget >> .m.bot:not(.typing)"


def check(name: str, ok: bool, detail: object = "") -> None:
    print(("ok   " if ok else "FAIL ") + name + ("" if ok else f": {str(detail)[:300]}"), flush=True)
    if not ok:
        failures.append(name)


def ask(page, text: str, count: int) -> str:
    page.locator("#wia-widget >> textarea").fill(text)
    page.locator("#wia-widget >> .send").click()
    page.wait_for_function(f"document.querySelector('#wia-widget').shadowRoot.querySelectorAll('.m.bot:not(.typing)').length >= {count}", timeout=20000)
    return page.locator(BOT).last.inner_text()


def chips(page) -> list[str]:
    return [c.inner_text() for c in page.locator("#wia-widget >> .chips .chip").all()]


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get("WIA_CHROMIUM") or None)
    errors: list[str] = []

    for name, viewport in (("desktop", {"width": 1280, "height": 860}), ("mobile", {"width": 390, "height": 844})):
        ctx = browser.new_context(viewport=viewport, locale="fa-IR")
        front = ctx.new_page()
        front.on("pageerror", lambda e: errors.append(str(e)))
        front.goto(f"{base}/")
        front.wait_for_selector("#wia-widget", state="attached", timeout=15000)
        front.locator("#wia-widget >> .fab").click()
        front.wait_for_timeout(300)
        starters = chips(front)
        check(f"{name}: welcome shows starter buttons", "کوله پشتی" in starters and "ارتباط با پشتیبانی" in starters, starters)
        answer = ask(front, "کیف", 2)
        check(f"{name}: «کیف» is answered", "کیف لپ تاپ" in answer, answer)
        answer = ask(front, "قیمت کوله پشتی چنده؟", 3)
        offered = chips(front)
        check(f"{name}: ambiguous question lists products with prices", "۱٬۲۰۰٬۰۰۰" in answer and "۳٬۵۰۰٬۰۰۰" in answer and len(offered) >= 2, (answer, offered))
        front.locator("#wia-widget >> .chips .chip", has_text="کوله پشتی مدرسه آبی").click()
        front.wait_for_function("document.querySelector('#wia-widget').shadowRoot.querySelectorAll('.m.bot:not(.typing)').length >= 4", timeout=20000)
        answer = front.locator(BOT).last.inner_text()
        check(f"{name}: choosing a button answers that product", "مدرسه" in answer and "۱٬۲۰۰٬۰۰۰" in answer, answer)
        if name == "desktop":
            front.screenshot(path=out / "wp-dialog-clarify.png")
        ask(front, "هلیکوپتر", 5)
        answer = ask(front, "زیردریایی", 6)
        links = [a.get_attribute("href") for a in front.locator("#wia-widget >> .m.bot:not(.typing) .act").all()]
        check(f"{name}: two misses hand off with contact buttons", "021-0000-0000" in answer and "tel:02100000000" in links and "https://wa.me/989000000000" in links, (answer, links))
        width = front.evaluate("document.querySelector('#wia-widget').shadowRoot.querySelector('.panel').getBoundingClientRect().width")
        check(f"{name}: panel fits the screen", width <= viewport["width"], width)
        front.screenshot(path=out / f"wp-dialog-handoff-{name}.png")
        ctx.close()

    ctx = browser.new_context(viewport={"width": 1280, "height": 900}, locale="fa-IR")
    page = ctx.new_page()
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.goto(f"{base}/wp-login.php")
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "wia-test-pass")
    page.click("#wp-submit")
    page.wait_for_load_state()
    page.goto(f"{base}/wp-admin/admin.php?page=website-intelligence-agent&tab=map")
    page.wait_for_selector("#wia-graph canvas")
    page.wait_for_timeout(2500)
    body = page.inner_text("#wpbody")
    check("map tab is in Persian", "نقشه دانش" in body and "کلمات شناخته‌شده" in body, body[:300])
    colours = page.evaluate("""() => {
        const c = document.querySelector('#wia-graph canvas'), x = c.getContext('2d');
        const d = x.getImageData(0, 0, c.width, c.height).data, seen = new Set();
        for (let i = 0; i < d.length; i += 4 * 97) seen.add(d[i] >> 4 << 8 | d[i + 1] >> 4 << 4 | d[i + 2] >> 4);
        return seen.size;
    }""")
    check("the map is drawn on the canvas", colours > 6, colours)
    page.screenshot(path=out / "wp-knowledge-map.png", full_page=True)
    page.locator(".wia-graph-search").fill("ساک")
    page.wait_for_timeout(800)
    info = page.locator(".wia-graph-info")
    check("searching the map selects a node and shows its details", info.is_visible() and "ساک" in info.inner_text(), info.inner_text() if info.is_visible() else "hidden")
    page.screenshot(path=out / "wp-knowledge-map-selected.png")
    gaps = page.inner_text(".wia-card:has-text('هلیکوپتر')") if page.locator(".wia-card:has-text('هلیکوپتر')").count() else ""
    check("unanswered words are listed", "هلیکوپتر" in gaps and "زیردریایی" in gaps, gaps[:200])
    check("reference-only topics are listed", "ارگونومیک" in body, body[-400:])
    page.goto(f"{base}/wp-admin/admin.php?page=website-intelligence-agent&tab=learning")
    body = page.inner_text("#wpbody")
    check("learning tab shows the learned phrase and the reference site", "ساک" in body and "ref:8080" in body and "فعال" in body, body[:400])
    page.screenshot(path=out / "wp-learning.png", full_page=True)
    check("no JavaScript errors", not errors, errors)
    browser.close()

print(f"--- {'FAILED: ' + ', '.join(failures) if failures else 'all dialogue browser checks passed'}")
sys.exit(1 if failures else 0)
