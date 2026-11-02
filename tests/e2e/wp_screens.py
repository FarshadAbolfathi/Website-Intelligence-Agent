"""Checks the installed plugin in a real browser, in Persian, and saves screenshots.

    python tests/e2e/wp_screens.py http://localhost:8090 OUTPUT_DIR
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

base, out = sys.argv[1], Path(sys.argv[2])
out.mkdir(parents=True, exist_ok=True)
failures: list[str] = []


def check(name: str, ok: bool, detail: object = "") -> None:
    print(("ok   " if ok else "FAIL ") + name + ("" if ok else f": {str(detail)[:300]}"), flush=True)
    if not ok:
        failures.append(name)


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get("WIA_CHROMIUM") or None)
    context = browser.new_context(viewport={"width": 1280, "height": 860}, locale="fa-IR")
    page = context.new_page()
    errors: list[str] = []
    page.on("pageerror", lambda e: errors.append(str(e)))

    page.goto(f"{base}/wp-login.php")
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "wia-test-pass")
    page.click("#wp-submit")
    page.wait_for_load_state()

    page.goto(f"{base}/wp-admin/admin.php?page=website-intelligence-agent")
    body = page.inner_text("#wpbody")
    check("plugin admin page is translated to Persian", "دستیار سایت" in body and "اسناد یادگرفته‌شده" in body, body[:300])
    check("admin page is right-to-left", page.evaluate("document.documentElement.dir") == "rtl")
    for _ in range(40):
        if "در حال انجام" not in page.inner_text("#wia-scan-state"):
            break
        page.wait_for_timeout(500)
    page.reload()
    page.screenshot(path=out / "wp-status.png", full_page=True)
    stats = page.locator(".wia-stat b").first.inner_text()
    check("the scan button's batches learned the content", stats not in ("0", "۰"), stats)

    page.goto(f"{base}/wp-admin/admin.php?page=website-intelligence-agent&tab=widget")
    page.screenshot(path=out / "wp-widget-settings.png", full_page=True)

    visitor = browser.new_context(viewport={"width": 1280, "height": 860}, locale="fa-IR")
    front = visitor.new_page()
    front.on("pageerror", lambda e: errors.append(str(e)))
    front.goto(f"{base}/")
    front.wait_for_selector("#wia-widget", state="attached", timeout=15000)
    front.locator("#wia-widget >> .fab").click()
    front.locator("#wia-widget >> textarea").fill("قیمت گوشی ایکس چقدر است؟")
    front.locator("#wia-widget >> .send").click()
    front.wait_for_function("document.querySelector('#wia-widget').shadowRoot.querySelectorAll('.m.bot:not(.typing)').length >= 2", timeout=20000)
    answer = front.locator("#wia-widget >> .m.bot:not(.typing)").last.inner_text()
    check("the visitor's widget answers with the live price", "۲۵٬۰۰۰٬۰۰۰" in answer, answer)
    front.locator("#wia-widget >> textarea").fill("شرایط ارسال چیست؟")
    front.locator("#wia-widget >> .send").click()
    front.wait_for_function("document.querySelector('#wia-widget').shadowRoot.querySelectorAll('.m.bot:not(.typing)').length >= 3", timeout=20000)
    answer = front.locator("#wia-widget >> .m.bot:not(.typing)").last.inner_text()
    check("the widget answers from page content", "پست پیشتاز" in answer, answer)
    front.wait_for_timeout(300)
    front.screenshot(path=out / "wp-widget.png")
    check("no JavaScript errors", not errors, errors)
    browser.close()

print(f"--- {'FAILED: ' + ', '.join(failures) if failures else 'all WordPress browser checks passed'}")
sys.exit(1 if failures else 0)
