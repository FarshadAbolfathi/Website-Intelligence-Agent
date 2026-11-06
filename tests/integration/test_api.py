"""The HTTP API end to end: real FastAPI app, real worker functions, real crawl of a live local site."""

import pytest
from fastapi.testclient import TestClient

from backend.api.app import create_app
from backend.api.auth import hash_password, read_session, sign_session, verify_password
from backend.services import jobs
from backend.settings import Settings


def settings(tmp_path, **kw):
    return Settings(
        database_url=f"sqlite:///{tmp_path}/t.sqlite3", cookie_secure=False, crawler_allow_hosts=frozenset({"127.0.0.1"}),
        crawler_delay=0, competitor_delay=0, frontend_dist=str(tmp_path / "none"), **kw,
    )


@pytest.fixture
def api(tmp_path, monkeypatch):
    monkeypatch.setenv("WIA_SETUP_TOKEN", "setup-secret")
    app = create_app(settings(tmp_path))
    return TestClient(app)


@pytest.fixture
def admin(api):
    r = api.post("/api/setup", json={"username": "owner", "password": "a-long-password", "setup_token": "setup-secret"})
    assert r.status_code == 200
    return api


def work(client):
    while jobs.run_one(client.app.state.container):
        pass


def make_site(client, url, language="en"):
    r = client.post("/api/sites", json={"name": "Test", "base_url": url, "default_language": language})
    assert r.status_code == 201, r.text
    return r.json()


def crawl(client, site):
    assert client.post(f"/api/sites/{site['id']}/crawl").status_code == 202
    work(client)


ADMIN_ROUTES = [
    ("get", "/api/sites"), ("post", "/api/sites"), ("get", "/api/sites/1"), ("patch", "/api/sites/1"), ("delete", "/api/sites/1"),
    ("post", "/api/sites/1/crawl"), ("get", "/api/sites/1/crawls"), ("get", "/api/sites/1/documents"), ("delete", "/api/sites/1/documents/1"),
    ("get", "/api/sites/1/search?q=x"), ("post", "/api/sites/1/chat-test"), ("get", "/api/sites/1/faqs"), ("post", "/api/sites/1/faqs"),
    ("get", "/api/sites/1/competitors"), ("post", "/api/sites/1/competitors"), ("post", "/api/sites/1/competitors/1/crawl"),
    ("get", "/api/sites/1/competitor-reports"), ("post", "/api/sites/1/competitor-reports"), ("post", "/api/sites/1/evaluations"),
    ("get", "/api/sites/1/evaluations"), ("get", "/api/sites/1/conversations"), ("delete", "/api/sites/1/conversations"),
    ("get", "/api/system/status"), ("get", "/api/models"), ("post", "/api/models/select"), ("post", "/api/models/pull"), ("get", "/api/jobs"),
]


@pytest.mark.parametrize("method,path", ADMIN_ROUTES)
def test_every_admin_route_requires_login(api, method, path):
    assert getattr(api, method)(path).status_code == 401


def test_setup_needs_token_and_runs_once(api):
    assert api.get("/api/setup").json() == {"needs_setup": True}
    assert api.post("/api/setup", json={"username": "x", "password": "a-long-password", "setup_token": "wrong"}).status_code == 403
    assert api.post("/api/setup", json={"username": "x", "password": "short", "setup_token": "setup-secret"}).status_code == 422
    assert api.post("/api/setup", json={"username": "owner", "password": "a-long-password", "setup_token": "setup-secret"}).status_code == 200
    assert api.post("/api/setup", json={"username": "evil", "password": "a-long-password", "setup_token": "setup-secret"}).status_code == 409


def test_login_logout_and_cookie_flags(admin):
    admin.post("/api/auth/logout")
    admin.cookies.clear()
    assert admin.post("/api/auth/login", json={"username": "owner", "password": "wrong-password"}).status_code == 401
    assert admin.post("/api/auth/login", json={"username": "ghost", "password": "wrong-password"}).status_code == 401
    r = admin.post("/api/auth/login", json={"username": "owner", "password": "a-long-password"})
    assert r.status_code == 200
    cookie = r.headers["set-cookie"].lower()
    assert "httponly" in cookie and "samesite=strict" in cookie
    assert admin.get("/api/auth/me").json() == {"username": "owner"}


def test_login_is_rate_limited(admin):
    admin.cookies.clear()
    codes = [admin.post("/api/auth/login", json={"username": "owner", "password": f"wrong-{i}"}).status_code for i in range(12)]
    assert 429 in codes


def test_forged_session_rejected():
    token = sign_session("owner", "secret-a")
    assert read_session(token, "secret-a") == "owner"
    assert read_session(token, "secret-b") is None
    payload, sig = token.split(".")
    assert read_session(payload[:-2] + "xx." + sig, "secret-a") is None
    assert read_session(sign_session("owner", "secret-a", ttl=-1), "secret-a") is None
    assert read_session("garbage", "secret-a") is None


def test_password_hashing():
    stored = hash_password("correct horse")
    assert verify_password("correct horse", stored) and not verify_password("wrong", stored)
    assert "correct" not in stored and stored != hash_password("correct horse")


@pytest.mark.parametrize("bad", ["http://169.254.169.254/", "http://localhost/", "file:///etc/passwd", "ftp://example.com", "not a url"])
def test_site_creation_blocks_unsafe_urls(tmp_path, monkeypatch, bad):
    monkeypatch.setenv("WIA_SETUP_TOKEN", "t")
    client = TestClient(create_app(Settings(database_url=f"sqlite:///{tmp_path}/u.sqlite3", cookie_secure=False, frontend_dist="none")))
    client.post("/api/setup", json={"username": "owner", "password": "a-long-password", "setup_token": "t"})
    assert client.post("/api/sites", json={"name": "x", "base_url": bad}).status_code == 422


def test_full_flow_crawl_chat_and_multi_turn(admin, sites):
    site = make_site(admin, sites["fa"].url, "fa")
    crawl(admin, site)
    view = admin.get(f"/api/sites/{site['id']}").json()
    assert view["stats"]["documents"]["active"] >= 6 and view["stats"]["chunks"] >= 6
    runs = admin.get(f"/api/sites/{site['id']}/crawls").json()
    assert runs[0]["status"] == "done" and runs[0]["stats"]["new"] >= 6

    public = TestClient(admin.app)  # a visitor: no admin cookie
    first = public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "قیمت گوشی ایکس چقدر است؟"})
    assert first.status_code == 200, first.text
    body = first.json()
    assert "۲۵٬۰۰۰٬۰۰۰" in body["answer"] and body["mode"] == "structured" and body["sources"][0]["url"].endswith("/products/phone-x")
    assert isinstance(body["suggestions"], list) and body["actions"] == []  # same response shape as the WordPress edition
    second = public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "موجوده؟", "conversation_id": body["conversation_id"]}).json()
    assert "گوشی ایکس" in second["answer"] and "موجود است" in second["answer"] and second["conversation_id"] == body["conversation_id"]
    assert len(admin.get(f"/api/sites/{site['id']}/conversations/{body['conversation_id']}").json()) == 4


def test_public_api_is_narrow_and_tenant_isolated(admin, sites):
    a = make_site(admin, sites["en"].url)
    b = make_site(admin, sites["ar"].url, "ar")
    crawl(admin, a)
    crawl(admin, b)
    public = TestClient(admin.app)
    assert public.post("/public/v1/chat", json={"site_key": "nope", "message": "hi"}).status_code == 404
    answer = public.post("/public/v1/chat", json={"site_key": a["public_key"], "message": "How much is Laptop A?"}).json()
    # A conversation id from site A presented with site B's key must not see A's history.
    other = public.post("/public/v1/chat", json={"site_key": b["public_key"], "message": "ما هو الضمان", "conversation_id": answer["conversation_id"]}).json()
    assert other["conversation_id"] != answer["conversation_id"]
    assert public.get(f"/api/sites/{a['id']}/documents").status_code == 401
    assert public.post("/public/v1/chat", json={"site_key": a["public_key"], "message": "x" * 1001}).status_code == 422


def test_public_widget_config_exposes_only_widget_fields(admin, sites):
    site = make_site(admin, sites["en"].url)
    config = TestClient(admin.app).get("/public/v1/widget", params={"site_key": site["public_key"]}).json()
    assert set(config) == {"title", "welcome", "not_found", "color", "position", "language", "avatar_url", "show_sources", "enabled", "starters", "teaser", "teaser_delay", "font_url"}


def test_widget_settings_are_cleaned_and_served(admin, sites):
    site = make_site(admin, sites["en"].url)
    widget = {"enabled": False, "teaser": {"en": "Questions?", "xx": "dropped"}, "teaser_delay": 999, "font_url": "http://insecure.test/f.woff2", "starters": {"en": "Shipping\n\nReturns"}}
    assert admin.patch(f"/api/sites/{site['id']}", json={"widget": widget}).status_code == 200
    config = TestClient(admin.app).get("/public/v1/widget", params={"site_key": site["public_key"]}).json()
    assert config["enabled"] is False and config["teaser"] == {"en": "Questions?"} and config["teaser_delay"] == 120
    assert config["font_url"] == "" and config["starters"] == ["Shipping", "Returns"]
    off = TestClient(admin.app).post("/public/v1/chat", json={"site_key": site["public_key"], "message": "hello"})
    assert off.status_code == 403  # switched off: the public API refuses messages too


def test_cors_only_for_the_sites_own_origin(admin, sites):
    site = make_site(admin, sites["en"].url)
    public = TestClient(admin.app)
    good = public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "hello"}, headers={"Origin": sites["en"].url})
    assert good.status_code == 200 and good.headers["access-control-allow-origin"] == sites["en"].url
    bad = public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "hello"}, headers={"Origin": "https://evil.test"})
    assert bad.status_code == 403 and "access-control-allow-origin" not in bad.headers


def test_chat_rate_limit(tmp_path, monkeypatch, sites):
    monkeypatch.setenv("WIA_SETUP_TOKEN", "t")
    client = TestClient(create_app(settings(tmp_path, chat_rate_limit=3)))
    client.post("/api/setup", json={"username": "owner", "password": "a-long-password", "setup_token": "t"})
    site = make_site(client, sites["en"].url)
    codes = [client.post("/public/v1/chat", json={"site_key": site["public_key"], "message": "hello"}).status_code for _ in range(5)]
    assert codes == [200, 200, 200, 429, 429]


def test_widget_settings_are_sanitised(admin, sites):
    site = make_site(admin, sites["en"].url)
    r = admin.patch(f"/api/sites/{site['id']}", json={"widget": {
        "color": "red;}body{display:none", "position": "<script>", "avatar_url": "javascript:alert(1)", "title": {"en": "Hi", "xx": "dropped"}, "evil": "x", "show_sources": False,
    }})
    widget = r.json()["settings"]["widget"]
    assert widget["color"] == "#2563eb" and widget["position"] == "right" and widget["avatar_url"] == ""
    assert widget["title"] == {"en": "Hi"} and "evil" not in widget and widget["show_sources"] is False


def test_sources_hidden_when_owner_disables_them(admin, sites):
    site = make_site(admin, sites["en"].url)
    crawl(admin, site)
    admin.patch(f"/api/sites/{site['id']}", json={"widget": {"show_sources": False}})
    body = TestClient(admin.app).post("/public/v1/chat", json={"site_key": site["public_key"], "message": "How much is Laptop A?"}).json()
    assert body["sources"] == [] and "999" in body["answer"]


def test_custom_not_found_message(admin, sites):
    site = make_site(admin, sites["en"].url)
    crawl(admin, site)
    admin.patch(f"/api/sites/{site['id']}", json={"widget": {"not_found": {"en": "Ask our team instead."}}})
    body = TestClient(admin.app).post("/public/v1/chat", json={"site_key": site["public_key"], "message": "Do you sell nuclear submarines?"}).json()
    assert body["answer"] == "Ask our team instead." and body["mode"] == "not_found"


def test_competitor_flow_and_separation_through_api(admin, sites):
    site = make_site(admin, sites["en"].url)
    crawl(admin, site)
    comp = admin.post(f"/api/sites/{site['id']}/competitors", json={"name": "Rival", "base_url": sites["competitor"].url}).json()
    admin.post(f"/api/sites/{site['id']}/competitors/{comp['id']}/crawl")
    work(admin)
    assert admin.post(f"/api/sites/{site['id']}/competitor-reports", params={"competitor_id": comp["id"]}).status_code == 202
    work(admin)
    report = admin.get(f"/api/sites/{site['id']}/competitor-reports").json()[0]["report"]
    assert report["products"]["matched"][0]["price_difference"] == -100.0
    public = TestClient(admin.app)
    for question, forbidden in [("How much is Drone Q?", "777"), ("How much is Laptop A?", "899"), ("Do you offer free overnight shipping?", "overnight shipping on every")]:
        answer = public.post("/public/v1/chat", json={"site_key": site["public_key"], "message": question}).json()
        # The reply may repeat the visitor's own words («I found nothing about "overnight"»); the competitor's text must not appear.
        assert forbidden not in answer["answer"], question
        assert not any(sites["competitor"].url in s["url"] for s in answer["sources"])
    assert admin.post(f"/api/sites/{site['id']}/competitors", json={"name": "self", "base_url": sites["en"].url}).status_code == 422


def test_evaluation_through_api(admin, sites):
    site = make_site(admin, sites["en"].url)
    crawl(admin, site)
    admin.post(f"/api/sites/{site['id']}/evaluations")
    work(admin)
    run = admin.get(f"/api/sites/{site['id']}/evaluations").json()[0]
    assert run["status"] == "done" and run["metrics"]["no_answer_precision"] == 1.0 and run["items"]


def test_delete_site_removes_everything(admin, sites):
    site = make_site(admin, sites["en"].url)
    crawl(admin, site)
    TestClient(admin.app).post("/public/v1/chat", json={"site_key": site["public_key"], "message": "hello"})
    assert admin.delete(f"/api/sites/{site['id']}").json() == {"deleted": True}
    assert admin.get(f"/api/sites/{site['id']}").status_code == 404
    from sqlalchemy import func, select

    from core.knowledge import schema as t

    with admin.app.state.container.engine.connect() as c:
        for table in (t.documents, t.chunks, t.postings, t.conversations, t.messages, t.crawl_runs):
            assert c.execute(select(func.count()).select_from(table)).scalar_one() == 0, table.name


def test_failing_job_retries_then_fails_without_stopping_worker(admin):
    job_id = jobs.enqueue(admin.app.state.container, "crawl", site_id=99999)
    container = admin.app.state.container
    assert jobs.run_one(container)
    from sqlalchemy import select, update

    from core.knowledge import schema as t

    with container.engine.begin() as c:
        c.execute(update(t.jobs).values(run_after=t.jobs.c.created_at))
    jobs.run_one(container)
    with container.engine.begin() as c:
        c.execute(update(t.jobs).values(run_after=t.jobs.c.created_at))
    jobs.run_one(container)
    with container.engine.connect() as c:
        row = c.execute(select(t.jobs).where(t.jobs.c.id == job_id)).mappings().first()
    assert row["status"] == "failed" and "site not found" in row["error"] and row["attempts"] == 3


def test_schedule_creates_job(admin, sites):
    site = make_site(admin, sites["en"].url)
    admin.patch(f"/api/sites/{site['id']}", json={"crawl_every_hours": 12})
    assert jobs.fire_schedules(admin.app.state.container) == 1
    assert jobs.fire_schedules(admin.app.state.container) == 0  # next run moved 12 hours ahead
    assert any(j["kind"] == "crawl" for j in admin.get("/api/jobs").json())


def test_conversation_retention_purge(admin, sites):
    from datetime import timedelta

    from sqlalchemy import func, select, update

    from core.knowledge import schema as t
    from core.knowledge.store import utcnow

    site = make_site(admin, sites["en"].url)
    TestClient(admin.app).post("/public/v1/chat", json={"site_key": site["public_key"], "message": "hello"})
    container = admin.app.state.container
    with container.engine.begin() as c:
        c.execute(update(t.conversations).values(updated_at=utcnow() - timedelta(days=90)))
    assert jobs.purge_conversations(container) == {"deleted": 1}
    with container.engine.connect() as c:
        assert c.execute(select(func.count()).select_from(t.messages)).scalar_one() == 0


def test_security_headers_present(api):
    r = api.get("/api/setup")
    assert r.headers["x-content-type-options"] == "nosniff" and "frame-ancestors" in r.headers["content-security-policy"]
    assert r.headers["cache-control"] == "no-store"


def test_status_reports_real_state(admin):
    status = admin.get("/api/system/status").json()
    assert status["database"]["healthy"] and status["model"]["name"] == "none" and "extractive" in status["model"]["mode"]
    assert status["embedder"]["name"] == "none" and status["resources"]["cpu_count"] > 0
