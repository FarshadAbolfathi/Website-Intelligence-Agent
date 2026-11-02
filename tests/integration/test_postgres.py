"""The production database. Skipped unless WIA_TEST_DATABASE_URL points at a disposable PostgreSQL."""

import os
import threading

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import func, select

from backend.api.app import create_app
from backend.container import make_engine
from backend.services import jobs
from backend.settings import Settings
from core.knowledge import schema as t
from core.knowledge.store import Scope

URL = os.environ.get("WIA_TEST_DATABASE_URL", "")
pytestmark = pytest.mark.skipif(not URL, reason="set WIA_TEST_DATABASE_URL to a disposable PostgreSQL")


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setenv("WIA_SETUP_TOKEN", "t")
    engine = make_engine(URL)
    t.metadata.drop_all(engine)
    engine.dispose()
    client = TestClient(create_app(Settings(database_url=URL, cookie_secure=False, crawler_allow_hosts=frozenset({"127.0.0.1"}), crawler_delay=0, competitor_delay=0, frontend_dist="none")))
    assert client.post("/api/setup", json={"username": "owner", "password": "a-long-password", "setup_token": "t"}).status_code == 200
    return client


def drain(client):
    while jobs.run_one(client.app.state.container):
        pass


def test_full_flow_on_postgres(client, sites):
    assert client.app.state.container.engine.dialect.name == "postgresql"
    site = client.post("/api/sites", json={"name": "fa", "base_url": sites["fa"].url, "default_language": "fa"}).json()
    client.post(f"/api/sites/{site['id']}/crawl")
    drain(client)
    assert client.get(f"/api/sites/{site['id']}").json()["stats"]["documents"]["active"] >= 6

    public = TestClient(client.app)
    first = public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "قیمت گوشی ایکس چقدر است؟"}).json()
    assert "۲۵٬۰۰۰٬۰۰۰" in first["answer"]
    assert "موجود است" in public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "موجوده؟", "conversation_id": first["conversation_id"]}).json()["answer"]
    assert public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "هل تبيعون غواصات نووية؟"}).json()["mode"] == "not_found"

    # Unicode, JSON and search behave the same on the production database
    store = client.app.state.container.store
    assert store.search_lexical(Scope(site["id"]), "گارانتي گوشي چند ماه است")
    assert store.search_lexical(Scope(site["id"]), "لپتاپ زد")

    sites["fa"].pages["/products/phone-x"] = sites["fa"].pages["/products/phone-x"].replace("25000000", "27500000")
    client.post(f"/api/sites/{site['id']}/crawl")
    drain(client)
    assert "۲۷٬۵۰۰٬۰۰۰" in public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "قیمت گوشی ایکس چقدر است؟"}).json()["answer"]

    client.post(f"/api/sites/{site['id']}/evaluations")
    drain(client)
    run = client.get(f"/api/sites/{site['id']}/evaluations").json()[0]
    assert run["status"] == "done" and run["metrics"]["groundedness"] == 1.0

    assert client.delete(f"/api/sites/{site['id']}").status_code == 200
    with client.app.state.container.engine.connect() as c:
        for table in (t.documents, t.chunks, t.postings, t.messages):
            assert c.execute(select(func.count()).select_from(table)).scalar_one() == 0


def test_concurrent_workers_never_run_the_same_job_twice(client):
    container = client.app.state.container
    ids = {jobs.enqueue(container, "purge") for _ in range(40)}
    claimed: list[int] = []
    lock = threading.Lock()

    def worker():
        while True:
            job = jobs.claim_job(container)
            if not job:
                return
            with lock:
                claimed.append(job["id"])

    threads = [threading.Thread(target=worker) for _ in range(8)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()
    assert sorted(claimed) == sorted(ids)


def test_driver_url_is_upgraded_to_psycopg():
    engine = make_engine("postgresql://u:p@localhost/x")
    assert engine.dialect.driver == "psycopg"
