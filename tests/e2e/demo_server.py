"""Starts a demo stack for browser checks: three sample sites, the real API, and a worker thread.

    WIA_E2E_DIR=/tmp/x python tests/e2e/demo_server.py

Prints the addresses as JSON on the first line. The API uses a throwaway SQLite file.
"""

from __future__ import annotations

import json
import os
import sys
import threading
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT / "tests"))

import uvicorn  # noqa: E402
from conftest import TestSite, build_sites  # noqa: E402

from backend.api.app import create_app  # noqa: E402
from backend.services import jobs  # noqa: E402
from backend.settings import Settings  # noqa: E402

workdir = Path(os.environ["WIA_E2E_DIR"])
workdir.mkdir(parents=True, exist_ok=True)
os.environ.setdefault("WIA_SETUP_TOKEN", "demo-setup-token")
os.environ.setdefault("WIA_ENV", "development")

sites = {name: TestSite(pages) for name, pages in build_sites().items()}
api_port = int(os.environ.get("WIA_E2E_PORT", "8123"))
snippet = f'<script src="http://127.0.0.1:{api_port}/widget/wia-widget.js" data-endpoint="http://127.0.0.1:{api_port}/public/v1" data-site-key="__KEY__" defer></script>'
for site in sites.values():
    site.pages["/widget-demo"] = site.pages["/"].replace("</body>", snippet + "</body>")

settings = Settings(
    database_url=f"sqlite:///{workdir}/demo.sqlite3", cookie_secure=False, crawler_allow_hosts=frozenset({"127.0.0.1"}),
    crawler_delay=0, competitor_delay=0, frontend_dist=str(ROOT / "frontend" / "dist"), widget_path=str(ROOT / "shared" / "widget" / "wia-widget.js"),
    llm_backend=os.environ.get("WIA_LLM_BACKEND", "none"), llm_url=os.environ.get("WIA_LLM_URL", "http://localhost:11434"), llm_model=os.environ.get("WIA_LLM_MODEL", ""),
)
app = create_app(settings)
container = app.state.container


def worker() -> None:
    while True:
        if not jobs.run_one(container):
            time.sleep(0.3)


threading.Thread(target=worker, daemon=True).start()


class SetKey(threading.Thread):
    """Lets the browser script publish the real site key into the sample page once a site exists."""

    daemon = True

    def run(self) -> None:
        from sqlalchemy import select

        from core.knowledge import schema as t

        while True:
            with container.engine.connect() as c:
                for row in c.execute(select(t.sites.c.base_url, t.sites.c.public_key)):
                    for site in sites.values():
                        if site.url == row.base_url:
                            site.pages["/widget-demo"] = site.pages["/widget-demo"].replace("__KEY__", row.public_key)
            time.sleep(0.5)


SetKey().start()
print(json.dumps({"api": f"http://127.0.0.1:{api_port}", **{k: v.url for k, v in sites.items()}}), flush=True)
uvicorn.run(app, host="127.0.0.1", port=api_port, log_level="warning")
