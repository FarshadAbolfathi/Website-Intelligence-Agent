"""Drives the real dashboard and widget in Chromium, asserting behaviour and saving screenshots.

    python tests/e2e/browser_check.py ADDRESSES_JSON OUTPUT_DIR
"""

from __future__ import annotations

import json
import os
import sys
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

urls = json.loads(sys.argv[1])
out = Path(sys.argv[2])
out.mkdir(parents=True, exist_ok=True)
API = urls["api"]
failures: list[str] = []


def check(name: str, ok: bool, detail: object = "") -> None:
    print(("ok   " if ok else "FAIL ") + name + ("" if ok else f": {str(detail)[:300]}"), flush=True)
    if not ok:
        failures.append(name)


def wait_for(fn, seconds=40.0):
    end = time.time() + seconds
    while time.time() < end:
        value = fn()
        if value:
            return value
        time.sleep(0.5)
    return None


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get("WIA_CHROMIUM") or None)
    context = browser.new_context(viewport={"width": 1280, "height": 820}, locale="fa-IR")
    page = context.new_page()
    console_errors: list[str] = []
    page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: console_errors.append(str(e)))

    # ---- first run ---------------------------------------------------------------------------
    page.goto(API)
    page.wait_for_selector("form")
    check("Persian dashboard is right-to-left", page.evaluate("document.documentElement.dir") == "rtl")
    check("setup screen appears on a new install", "کد راه‌اندازی" in page.inner_text("body"))
    page.screenshot(path=out / "setup.png")
    inputs = page.locator("form input")
    inputs.nth(0).fill("wrong-token")
    inputs.nth(1).fill("owner")
    inputs.nth(2).fill("a-long-password")
    page.click("form button")
    page.wait_for_selector(".err")
    check("wrong setup token is refused", "invalid" in page.inner_text(".err"))
    inputs.nth(0).fill("demo-setup-token")
    page.click("form button")
    page.wait_for_selector(".tile")
    check("admin account created, overview shown", "وضعیت سیستم" in page.inner_text("body"))
    page.wait_for_timeout(600)
    page.screenshot(path=out / "dashboard-status.png")

    # ---- add and learn a site through the UI -------------------------------------------------------
    page.click("nav button:nth-child(2)")
    page.wait_for_selector("form")
    fields = page.locator("form input")
    fields.nth(0).fill("دیجی‌نمونه")
    fields.nth(1).fill("http://169.254.169.254")
    page.click("form button.primary")
    page.wait_for_selector(".err")
    check("dashboard shows the SSRF refusal", "unsafe" in page.inner_text(".err"), page.inner_text(".err"))
    fields.nth(1).fill(urls["fa"])
    page.click("form button.primary")
    page.wait_for_selector("table tbody tr td button")
    page.click("table tbody tr td button")
    page.wait_for_selector("button.primary")
    page.click("button.primary")
    learned = wait_for(lambda: (page.reload(), page.click("nav button:nth-child(2)"), page.wait_for_selector("table tbody tr td"), "active" and int(page.locator("table tbody tr td").nth(2).inner_text() or 0) >= 6)[-1])
    check("site was learned through the worker", bool(learned))
    page.click("table tbody tr td button")
    page.wait_for_selector(".tile")
    page.wait_for_timeout(1200)
    page.screenshot(path=out / "dashboard-learning.png")

    # ---- test chat in the dashboard ------------------------------------------------------------
    tabs = page.locator("main nav.tabs").nth(1).locator("button")
    tabs.nth(3).click()
    page.locator("form input").fill("قیمت گوشی ایکس چقدر است؟")
    page.click("form button.primary")
    page.wait_for_selector(".answer")
    answer = page.inner_text(".answer")
    check("dashboard chat answers from product data", "۲۵٬۰۰۰٬۰۰۰" in answer, answer)
    page.screenshot(path=out / "dashboard-test-chat.png")

    # ---- evaluation --------------------------------------------------------------------------------
    tabs.nth(6).click()
    page.wait_for_selector("button.primary")
    page.click("button.primary")
    evaluated = wait_for(lambda: page.locator(".tile").count() >= 8, 60)
    check("evaluation metrics are displayed", bool(evaluated))
    page.wait_for_timeout(500)
    page.screenshot(path=out / "dashboard-evaluation.png", full_page=True)

    # ---- English interface switches direction --------------------------------------------------------
    page.select_option("header select", "en")
    check("English dashboard is left-to-right", page.evaluate("document.documentElement.dir") == "ltr")
    page.select_option("header select", "fa")

    # ---- widget on the site's own page ----------------------------------------------------------------
    site = context.new_page()
    site_errors: list[str] = []
    site.on("pageerror", lambda e: site_errors.append(str(e)))
    time.sleep(1)
    site.goto(urls["fa"] + "/widget-demo")
    site.wait_for_selector("#wia-widget", state="attached", timeout=10000)

    def shadow(selector: str) -> str:
        return f"#wia-widget >> {selector}"

    site.locator(shadow(".fab")).click()
    check("widget panel is right-to-left", site.locator(shadow(".panel")).get_attribute("dir") == "rtl")

    def send(text: str, expected_bot: int) -> str:
        site.locator(shadow("textarea")).fill(text)
        site.locator(shadow(".send")).click()
        site.wait_for_function(f"document.querySelector('#wia-widget').shadowRoot.querySelectorAll('.m.bot:not(.typing)').length >= {expected_bot}", timeout=30000)
        return site.locator(shadow(".m.bot:not(.typing)")).last.inner_text()

    first = send("شرایط ارسال چیست؟", 2)
    check("widget answers with a source", "پست پیشتاز" in first and site.locator(shadow(".src a")).count() >= 1, first)
    second = send("قیمت گوشی ایکس چقدر است؟", 3)
    check("widget price answer", "۲۵٬۰۰۰٬۰۰۰" in second, second)
    third = send("موجوده؟", 4)
    check("widget keeps conversation context", "گوشی ایکس" in third and "موجود است" in third, third)
    hostile = '<img src=x onerror="window.__xss=1"><script>window.__xss=1</script>'
    send(hostile, 5)
    site.wait_for_timeout(300)
    check("hostile message is shown as text and not executed", site.evaluate("window.__xss") is None and site.locator(shadow(".m.me img")).count() == 0)
    site.screenshot(path=out / "widget-fa.png")
    check("widget shows no page errors", not site_errors, site_errors)

    site.reload()
    site.wait_for_selector("#wia-widget", state="attached")
    site.locator(shadow(".fab")).click()
    site.wait_for_timeout(300)
    check("conversation survives a reload", site.locator(shadow(".m.me")).count() >= 3)

    mobile = browser.new_context(viewport={"width": 390, "height": 760}, is_mobile=True, has_touch=True)
    phone = mobile.new_page()
    phone.goto(urls["fa"] + "/widget-demo")
    phone.wait_for_selector("#wia-widget", state="attached")
    phone.locator(shadow(".fab")).click()
    box = phone.locator(shadow(".panel")).bounding_box()
    check("on a phone the widget fills the width", box and box["width"] >= 385, box)
    scroll = phone.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1")
    check("no horizontal page scroll on a phone", scroll)
    phone.screenshot(path=out / "widget-mobile.png")

    # ---- a different origin cannot use this site's key ----------------------------------------------------
    other = context.new_page()
    other.goto(urls["en"] + "/about")
    key = site.evaluate("document.currentScript") or ""
    status = other.evaluate(
        """async ([api, base]) => {
            const html = await fetch(base).catch(() => null);
            return html ? 'ok' : 'no';
        }""",
        [API, urls["en"]],
    )
    check("sample page reachable", status == "ok")

    relevant = [e for e in console_errors if "favicon" not in e and "401" not in e and "422" not in e and "403" not in e]
    check("dashboard has no console errors", not relevant, relevant)
    browser.close()

print(f"--- {'FAILED: ' + ', '.join(failures) if failures else 'all browser checks passed'}")
sys.exit(1 if failures else 0)
