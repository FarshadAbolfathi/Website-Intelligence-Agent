"""HTTP API: authenticated admin endpoints, a narrow public chat API, and static dashboard and widget files."""

import logging
import os
import secrets
from pathlib import Path
from typing import Annotated
from urllib.parse import urlsplit

from fastapi import Depends, FastAPI, HTTPException, Query, Request, Response
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field
from sqlalchemy import delete, func, insert, select, update

from backend.api.auth import DUMMY_HASH, SESSION_COOKIE, SESSION_SECONDS, RateLimiter, hash_password, read_session, sign_session, verify_password
from backend.container import Container, kv_get, kv_set
from backend.services import jobs as job_service
from backend.services.system import MODEL_CATALOG, system_status
from backend.settings import Settings
from core.chat.engine import ChatTurn
from core.knowledge import schema as t
from core.knowledge.store import Corpus, Scope, utcnow
from core.security.url_guard import UnsafeURLError

log = logging.getLogger("wia.api")
MAX_MESSAGE_CHARS = 1000
DEFAULT_WIDGET = {
    "title": {"fa": "دستیار سایت", "ar": "مساعد الموقع", "en": "Site assistant"},
    "welcome": {},
    "not_found": {},
    "color": "#2563eb",
    "position": "right",
    "language": "",
    "avatar_url": "",
    "show_sources": True,
    "enabled": True,
    "starters": [],
}


class LoginBody(BaseModel):
    username: str = Field(max_length=100)
    password: str = Field(max_length=200)


class SetupBody(LoginBody):
    setup_token: str = Field(max_length=200)
    password: str = Field(min_length=10, max_length=200)


class SiteBody(BaseModel):
    name: str = Field(min_length=1, max_length=200)
    base_url: str = Field(max_length=2048)
    default_language: str = Field(default="fa", pattern="^(fa|ar|en)$")


class SiteUpdate(BaseModel):
    name: str | None = Field(default=None, max_length=200)
    default_language: str | None = Field(default=None, pattern="^(fa|ar|en)$")
    widget: dict | None = None
    crawl: dict | None = None
    crawl_every_hours: int | None = Field(default=None, ge=0, le=24 * 30)


class FaqBody(BaseModel):
    question: str = Field(min_length=1, max_length=2000)
    answer: str = Field(min_length=1, max_length=5000)
    keywords: str = Field(default="", max_length=1000)
    language: str = Field(default="", pattern="^(|fa|ar|en)$")


class CompetitorBody(BaseModel):
    name: str = Field(min_length=1, max_length=200)
    base_url: str = Field(max_length=2048)


class ChatBody(BaseModel):
    site_key: str = Field(max_length=64)
    message: str = Field(min_length=1, max_length=MAX_MESSAGE_CHARS)
    conversation_id: str | None = Field(default=None, max_length=40)


class TestChatBody(BaseModel):
    message: str = Field(min_length=1, max_length=MAX_MESSAGE_CHARS)


class ModelSelect(BaseModel):
    backend: str = Field(pattern="^(none|ollama|llamacpp)$")
    model: str = Field(default="", max_length=120)


class ModelPull(BaseModel):
    name: str = Field(max_length=120, pattern=r"^[a-zA-Z0-9._:\-/]+$")


def _clean_widget(raw: dict, current: dict) -> dict:
    """Keep only known widget fields, with safe types. Widget settings are served publicly."""
    merged = {**DEFAULT_WIDGET, **current}
    for key in ("title", "welcome", "not_found"):
        if isinstance(raw.get(key), dict):
            merged[key] = {k: str(v)[:500] for k, v in raw[key].items() if k in ("fa", "ar", "en")}
    color = str(raw.get("color", merged["color"]))
    if len(color) in (4, 7) and color[0] == "#" and all(ch in "0123456789abcdefABCDEF" for ch in color[1:]):
        merged["color"] = color
    if raw.get("position") in ("left", "right"):
        merged["position"] = raw["position"]
    if raw.get("language") in ("", "fa", "ar", "en"):
        merged["language"] = raw["language"]
    avatar = str(raw.get("avatar_url", merged["avatar_url"]))
    merged["avatar_url"] = avatar if avatar.startswith("https://") or not avatar else merged["avatar_url"]
    for key in ("show_sources", "enabled"):
        if isinstance(raw.get(key), bool):
            merged[key] = raw[key]
    return merged


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or Settings()
    container = Container(settings)
    production = os.environ.get("WIA_ENV", "production") == "production"
    app = FastAPI(title="Website Intelligence Agent", version="0.1.0",
                  docs_url=None if production else "/api/docs", redoc_url=None, openapi_url=None if production else "/api/openapi.json")
    app.state.container = container
    login_limiter = RateLimiter(10, 300)
    chat_limiter = RateLimiter(settings.chat_rate_limit, 60)

    def client_ip(request: Request) -> str:
        if settings.trust_proxy:
            forwarded = request.headers.get("x-forwarded-for", "").split(",")[0].strip()
            if forwarded:
                return forwarded
        return request.client.host if request.client else "unknown"

    def require_admin(request: Request) -> str:
        token = request.cookies.get(SESSION_COOKIE, "")
        username = read_session(token, container.secret_key) if token else None
        if not username:
            raise HTTPException(401, "authentication required")
        return username

    Admin = Annotated[str, Depends(require_admin)]

    def get_site(site_id: int) -> dict:
        with container.engine.connect() as c:
            row = c.execute(select(t.sites).where(t.sites.c.id == site_id)).mappings().first()
        if not row:
            raise HTTPException(404, "site not found")
        return dict(row)

    def check_url(url: str) -> str:
        url = url.strip().rstrip("/")
        try:
            container.guard.check(url)
        except UnsafeURLError as exc:
            raise HTTPException(422, f"unsafe or invalid URL: {exc}") from exc
        return url

    @app.middleware("http")
    async def security_headers(request: Request, call_next):
        response = await call_next(request)
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
        path = request.url.path
        if not path.startswith(("/public/", "/widget/")):
            response.headers["X-Frame-Options"] = "DENY"
            response.headers["Content-Security-Policy"] = "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'"
        if path.startswith("/api/"):
            response.headers["Cache-Control"] = "no-store"
        return response

    # ---- health and auth -----------------------------------------------------------------
    @app.get("/healthz")
    def healthz():
        with container.engine.connect() as c:
            c.execute(select(func.count()).select_from(t.kv))
        return {"status": "ok"}

    def admin_exists() -> bool:
        with container.engine.connect() as c:
            return bool(c.execute(select(func.count()).select_from(t.admins)).scalar_one())

    def setup_token() -> str:
        """Printed to the server log on first start, so only someone with server access can claim the install."""
        token = os.environ.get("WIA_SETUP_TOKEN") or kv_get(container.engine, "setup_token")
        if not token:
            token = secrets.token_urlsafe(24)
            kv_set(container.engine, "setup_token", token)
            log.warning("FIRST RUN: open the dashboard and use this setup token: %s", token)
            print(f"[wia] FIRST RUN setup token: {token}", flush=True)  # noqa: T201
        return token

    if not admin_exists():
        setup_token()

    @app.get("/api/setup")
    def setup_state():
        return {"needs_setup": not admin_exists()}

    @app.post("/api/setup")
    def setup(body: SetupBody, request: Request, response: Response):
        if not login_limiter.allow("setup:" + client_ip(request)):
            raise HTTPException(429, "too many attempts")
        if admin_exists():
            raise HTTPException(409, "already set up")
        if not secrets.compare_digest(body.setup_token, setup_token()):
            raise HTTPException(403, "invalid setup token")
        with container.engine.begin() as c:
            c.execute(insert(t.admins).values(username=body.username, password_hash=hash_password(body.password), created_at=utcnow()))
        c_token = sign_session(body.username, container.secret_key)
        response.set_cookie(SESSION_COOKIE, c_token, max_age=SESSION_SECONDS, httponly=True, secure=settings.cookie_secure, samesite="strict", path="/")
        return {"username": body.username}

    @app.post("/api/auth/login")
    def login(body: LoginBody, request: Request, response: Response):
        ip = client_ip(request)
        if not login_limiter.allow(f"login:{ip}") or not login_limiter.allow(f"user:{body.username.lower()}"):
            raise HTTPException(429, "too many attempts")
        with container.engine.connect() as c:
            row = c.execute(select(t.admins).where(t.admins.c.username == body.username)).mappings().first()
        ok = verify_password(body.password, row["password_hash"] if row else DUMMY_HASH)
        if not row or not ok:
            raise HTTPException(401, "invalid credentials")
        response.set_cookie(SESSION_COOKIE, sign_session(body.username, container.secret_key), max_age=SESSION_SECONDS, httponly=True, secure=settings.cookie_secure, samesite="strict", path="/")
        return {"username": body.username}

    @app.post("/api/auth/logout")
    def logout(response: Response):
        response.delete_cookie(SESSION_COOKIE, path="/")
        return {"ok": True}

    @app.get("/api/auth/me")
    def me(username: Admin):
        return {"username": username}

    # ---- system and models ---------------------------------------------------------------
    @app.get("/api/system/status")
    def status(_: Admin):
        return system_status(container)

    @app.get("/api/models")
    def models(_: Admin):
        llm = container.llm
        installed = []
        try:
            import httpx

            installed = [{"name": m["name"], "size": m.get("size", 0)} for m in httpx.get(f"{settings.llm_url.rstrip('/')}/api/tags", timeout=3).json().get("models", [])]
        except Exception:  # noqa: BLE001, S110 - Ollama is optional; the page shows it as unreachable
            pass
        return {"catalog": MODEL_CATALOG, "installed": installed, "active": llm.name, "active_healthy": llm.healthy(), "embedder": container.embedder.name}

    @app.post("/api/models/select")
    def select_model(body: ModelSelect, _: Admin):
        kv_set(container.engine, "llm_backend", body.backend)
        kv_set(container.engine, "llm_model", body.model)
        return {"active": container.llm.name}

    @app.post("/api/models/pull", status_code=202)
    def pull_model(body: ModelPull, _: Admin):
        return {"job_id": job_service.enqueue(container, "pull_model", payload={"name": body.name})}

    @app.get("/api/jobs")
    def list_jobs(_: Admin, site_id: int | None = None, limit: int = Query(50, le=200)):
        q = select(t.jobs).order_by(t.jobs.c.id.desc()).limit(limit)
        if site_id:
            q = q.where(t.jobs.c.site_id == site_id)
        with container.engine.connect() as c:
            return [dict(r) for r in c.execute(q).mappings()]

    # ---- sites ---------------------------------------------------------------------------
    def site_view(site: dict) -> dict:
        scope = Scope(site["id"])
        return {**site, "stats": container.store.stats(scope)}

    @app.get("/api/sites")
    def list_sites(_: Admin):
        with container.engine.connect() as c:
            rows = [dict(r) for r in c.execute(select(t.sites).order_by(t.sites.c.id)).mappings()]
        return [site_view(s) for s in rows]

    @app.post("/api/sites", status_code=201)
    def create_site(body: SiteBody, _: Admin):
        url = check_url(body.base_url)
        with container.engine.begin() as c:
            site_id = c.execute(insert(t.sites).values(
                name=body.name, base_url=url, public_key=secrets.token_urlsafe(24), default_language=body.default_language,
                settings={"widget": DEFAULT_WIDGET}, created_at=utcnow(),
            )).inserted_primary_key[0]
        return site_view(get_site(site_id))

    @app.get("/api/sites/{site_id}")
    def read_site(site_id: int, _: Admin):
        return site_view(get_site(site_id))

    @app.patch("/api/sites/{site_id}")
    def update_site(site_id: int, body: SiteUpdate, _: Admin):
        site = get_site(site_id)
        values: dict = {}
        if body.name:
            values["name"] = body.name
        if body.default_language:
            values["default_language"] = body.default_language
        site_settings = dict(site["settings"] or {})
        if body.widget is not None:
            site_settings["widget"] = _clean_widget(body.widget, site_settings.get("widget", {}))
        if body.crawl is not None:
            site_settings["crawl"] = {k: body.crawl[k] for k in ("max_pages", "delay_seconds", "include_paths", "exclude_paths") if k in body.crawl}
        values["settings"] = site_settings
        with container.engine.begin() as c:
            c.execute(update(t.sites).where(t.sites.c.id == site_id).values(**values))
            if body.crawl_every_hours is not None:
                c.execute(delete(t.schedules).where(t.schedules.c.site_id == site_id, t.schedules.c.kind == "crawl"))
                if body.crawl_every_hours:
                    c.execute(insert(t.schedules).values(site_id=site_id, kind="crawl", every_hours=body.crawl_every_hours, next_run=utcnow()))
        return site_view(get_site(site_id))

    @app.delete("/api/sites/{site_id}")
    def delete_site(site_id: int, _: Admin):
        """Delete a site and everything learned or stored for it, including vectors and conversations."""
        get_site(site_id)
        with container.engine.connect() as c:
            comps = [r.id for r in c.execute(select(t.competitors.c.id).where(t.competitors.c.site_id == site_id))]
            convs = [r.id for r in c.execute(select(t.conversations.c.id).where(t.conversations.c.site_id == site_id))]
        scopes = [Scope(site_id)] + [Scope(site_id, Corpus.COMPETITOR, comp) for comp in comps]
        for scope in scopes:
            if container.vectors:
                container.vectors.delete_scope(scope)
            container.store.delete_scope(scope)
        with container.engine.begin() as c:
            if convs:
                c.execute(delete(t.messages).where(t.messages.c.conversation_id.in_(convs)))
            for table in (t.conversations, t.faqs, t.crawl_runs, t.evaluation_runs, t.competitor_reports, t.schedules, t.competitors, t.links, t.jobs):
                c.execute(delete(table).where(table.c.site_id == site_id))
            c.execute(delete(t.sites).where(t.sites.c.id == site_id))
        return {"deleted": True}

    @app.post("/api/sites/{site_id}/crawl", status_code=202)
    def start_crawl(site_id: int, _: Admin):
        get_site(site_id)
        return {"job_id": job_service.enqueue(container, "crawl", site_id=site_id)}

    @app.get("/api/sites/{site_id}/crawls")
    def crawls(site_id: int, _: Admin, corpus: str = "own", limit: int = Query(20, le=100)):
        with container.engine.connect() as c:
            return [dict(r) for r in c.execute(select(t.crawl_runs).where(t.crawl_runs.c.site_id == site_id, t.crawl_runs.c.corpus == corpus).order_by(t.crawl_runs.c.id.desc()).limit(limit)).mappings()]

    @app.get("/api/sites/{site_id}/documents")
    def documents(site_id: int, _: Admin, status_filter: str | None = Query(None, alias="status"), content_type: str | None = None, limit: int = Query(100, le=500), offset: int = 0):
        get_site(site_id)
        rows = container.store.list_docs(Scope(site_id), status=status_filter, content_type=content_type, limit=limit, offset=offset)
        return [{k: d[k] for k in ("id", "url", "title", "content_type", "language", "crawl_date", "last_modified", "source", "content_hash", "status", "version", "structured")} for d in rows]

    @app.delete("/api/sites/{site_id}/documents/{doc_id}")
    def remove_document(site_id: int, doc_id: int, _: Admin):
        scope = Scope(site_id)
        doc = container.store.get_doc(scope, doc_id)
        if not doc:
            raise HTTPException(404, "document not found")
        container.ingestor.remove_doc(scope, doc)
        return {"removed": True}

    @app.get("/api/sites/{site_id}/search")
    def search_kb(site_id: int, _: Admin, q: str = Query(min_length=1, max_length=500)):
        get_site(site_id)
        result = container.retriever.search(Scope(site_id), q, limit=8)
        return {"confident": result.confident, "mode": result.mode, "best_coverage": result.best_coverage, "best_vector": result.best_vector,
                "hits": [{"url": h.url, "title": h.title, "heading": h.heading, "text": h.text[:400], "score": h.score, "coverage": h.coverage} for h in result.hits]}

    @app.post("/api/sites/{site_id}/chat-test")
    def chat_test(site_id: int, body: TestChatBody, _: Admin):
        site = get_site(site_id)
        return container.chat().answer(site_id, body.message, default_language=site["default_language"]).as_dict()

    # ---- FAQ -----------------------------------------------------------------------------
    @app.get("/api/sites/{site_id}/faqs")
    def faqs(site_id: int, _: Admin):
        get_site(site_id)
        return container.store.faqs(site_id)

    @app.post("/api/sites/{site_id}/faqs", status_code=201)
    def add_faq(site_id: int, body: FaqBody, _: Admin):
        get_site(site_id)
        with container.engine.begin() as c:
            faq_id = c.execute(insert(t.faqs).values(site_id=site_id, created_at=utcnow(), **body.model_dump())).inserted_primary_key[0]
        return {"id": faq_id}

    @app.delete("/api/sites/{site_id}/faqs/{faq_id}")
    def delete_faq(site_id: int, faq_id: int, _: Admin):
        with container.engine.begin() as c:
            done = c.execute(delete(t.faqs).where(t.faqs.c.site_id == site_id, t.faqs.c.id == faq_id)).rowcount
        if not done:
            raise HTTPException(404, "not found")
        return {"deleted": True}

    # ---- competitors ---------------------------------------------------------------------
    @app.get("/api/sites/{site_id}/competitors")
    def competitors(site_id: int, _: Admin):
        with container.engine.connect() as c:
            rows = [dict(r) for r in c.execute(select(t.competitors).where(t.competitors.c.site_id == site_id)).mappings()]
        return [{**r, "stats": container.store.stats(Scope(site_id, Corpus.COMPETITOR, r["id"]))} for r in rows]

    @app.post("/api/sites/{site_id}/competitors", status_code=201)
    def add_competitor(site_id: int, body: CompetitorBody, _: Admin):
        site = get_site(site_id)
        url = check_url(body.base_url)
        if urlsplit(url).netloc == urlsplit(site["base_url"]).netloc:
            raise HTTPException(422, "a competitor cannot be the site itself")
        with container.engine.begin() as c:
            comp_id = c.execute(insert(t.competitors).values(site_id=site_id, name=body.name, base_url=url, enabled=True, created_at=utcnow())).inserted_primary_key[0]
        return {"id": comp_id}

    def own_competitor(site_id: int, comp_id: int) -> None:
        with container.engine.connect() as c:
            if not c.execute(select(t.competitors.c.id).where(t.competitors.c.id == comp_id, t.competitors.c.site_id == site_id)).first():
                raise HTTPException(404, "competitor not found")

    @app.delete("/api/sites/{site_id}/competitors/{comp_id}")
    def delete_competitor(site_id: int, comp_id: int, _: Admin):
        own_competitor(site_id, comp_id)
        scope = Scope(site_id, Corpus.COMPETITOR, comp_id)
        if container.vectors:
            container.vectors.delete_scope(scope)
        container.store.delete_scope(scope)
        with container.engine.begin() as c:
            c.execute(delete(t.competitors).where(t.competitors.c.id == comp_id))
        return {"deleted": True}

    @app.post("/api/sites/{site_id}/competitors/{comp_id}/crawl", status_code=202)
    def crawl_competitor(site_id: int, comp_id: int, _: Admin):
        own_competitor(site_id, comp_id)
        return {"job_id": job_service.enqueue(container, "crawl", site_id=site_id, payload={"competitor_id": comp_id})}

    @app.post("/api/sites/{site_id}/competitor-reports", status_code=202)
    def make_report(site_id: int, _: Admin, competitor_id: int | None = None):
        get_site(site_id)
        if competitor_id is not None:
            own_competitor(site_id, competitor_id)
        return {"job_id": job_service.enqueue(container, "competitor_report", site_id=site_id, payload={"competitor_id": competitor_id})}

    @app.get("/api/sites/{site_id}/competitor-reports")
    def reports(site_id: int, _: Admin, limit: int = Query(10, le=50)):
        with container.engine.connect() as c:
            return [dict(r) for r in c.execute(select(t.competitor_reports).where(t.competitor_reports.c.site_id == site_id).order_by(t.competitor_reports.c.id.desc()).limit(limit)).mappings()]

    # ---- evaluation ----------------------------------------------------------------------
    @app.post("/api/sites/{site_id}/evaluations", status_code=202)
    def start_eval(site_id: int, _: Admin):
        get_site(site_id)
        return {"job_id": job_service.enqueue(container, "evaluate", site_id=site_id)}

    @app.get("/api/sites/{site_id}/evaluations")
    def evals(site_id: int, _: Admin, limit: int = Query(10, le=50)):
        with container.engine.connect() as c:
            return [dict(r) for r in c.execute(select(t.evaluation_runs).where(t.evaluation_runs.c.site_id == site_id).order_by(t.evaluation_runs.c.id.desc()).limit(limit)).mappings()]

    # ---- conversations -------------------------------------------------------------------
    @app.get("/api/sites/{site_id}/conversations")
    def conversations(site_id: int, _: Admin, limit: int = Query(50, le=200)):
        with container.engine.connect() as c:
            return [dict(r) for r in c.execute(select(t.conversations.c.id, t.conversations.c.created_at, t.conversations.c.updated_at).where(t.conversations.c.site_id == site_id).order_by(t.conversations.c.updated_at.desc()).limit(limit)).mappings()]

    @app.get("/api/sites/{site_id}/conversations/{conv_id}")
    def conversation(site_id: int, conv_id: str, _: Admin):
        with container.engine.connect() as c:
            if not c.execute(select(t.conversations.c.id).where(t.conversations.c.id == conv_id, t.conversations.c.site_id == site_id)).first():
                raise HTTPException(404, "not found")
            return [dict(r) for r in c.execute(select(t.messages).where(t.messages.c.conversation_id == conv_id).order_by(t.messages.c.id)).mappings()]

    @app.delete("/api/sites/{site_id}/conversations")
    def delete_conversations(site_id: int, _: Admin):
        with container.engine.begin() as c:
            ids = [r.id for r in c.execute(select(t.conversations.c.id).where(t.conversations.c.site_id == site_id))]
            if ids:
                c.execute(delete(t.messages).where(t.messages.c.conversation_id.in_(ids)))
            c.execute(delete(t.conversations).where(t.conversations.c.site_id == site_id))
        return {"deleted": len(ids)}

    # ---- public API ----------------------------------------------------------------------
    def public_site(key: str) -> dict:
        with container.engine.connect() as c:
            row = c.execute(select(t.sites).where(t.sites.c.public_key == key)).mappings().first()
        if not row:
            raise HTTPException(404, "unknown site")
        return dict(row)

    def origin_allowed(site: dict, origin: str) -> bool:
        """Browsers send Origin on cross-site calls. It must match the site. Non-browser calls are limited by rate only."""
        if not origin:
            return True
        host = urlsplit(origin).netloc.lower().removeprefix("www.")
        return host == urlsplit(site["base_url"]).netloc.lower().removeprefix("www.")

    def public_headers(response: Response, origin: str) -> None:
        if origin:
            response.headers["Access-Control-Allow-Origin"] = origin
            response.headers["Vary"] = "Origin"
        response.headers["Cache-Control"] = "no-store"

    @app.options("/public/v1/{path:path}")
    def public_preflight(path: str, request: Request):
        origin = request.headers.get("origin", "")
        response = Response(status_code=204)
        if origin:
            response.headers["Access-Control-Allow-Origin"] = origin
            response.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
            response.headers["Access-Control-Allow-Headers"] = "Content-Type"
            response.headers["Access-Control-Max-Age"] = "600"
            response.headers["Vary"] = "Origin"
        return response

    @app.get("/public/v1/widget")
    def widget_config(site_key: str, request: Request, response: Response):
        site = public_site(site_key)
        origin = request.headers.get("origin", "")
        if not origin_allowed(site, origin):
            raise HTTPException(403, "origin not allowed")
        public_headers(response, origin)
        widget = {**DEFAULT_WIDGET, **(site["settings"] or {}).get("widget", {})}
        widget["language"] = widget.get("language") or site["default_language"]
        return widget

    @app.post("/public/v1/chat")
    def public_chat(body: ChatBody, request: Request, response: Response):
        site = public_site(body.site_key)
        origin = request.headers.get("origin", "")
        if not origin_allowed(site, origin):
            raise HTTPException(403, "origin not allowed")
        public_headers(response, origin)
        if not chat_limiter.allow(f"{site['id']}:{client_ip(request)}"):
            return JSONResponse({"error": "rate_limited"}, status_code=429, headers={"Retry-After": "60", **({"Access-Control-Allow-Origin": origin} if origin else {})})
        widget = {**DEFAULT_WIDGET, **(site["settings"] or {}).get("widget", {})}
        if not widget.get("enabled", True):
            raise HTTPException(403, "assistant disabled")

        now = utcnow()
        conv_id = body.conversation_id
        state: dict = {}
        history: list[ChatTurn] = []
        with container.engine.connect() as c:
            row = None
            if conv_id:
                # The site filter stops a conversation id from one site being read through another site's key.
                row = c.execute(select(t.conversations).where(t.conversations.c.id == conv_id, t.conversations.c.site_id == site["id"])).mappings().first()
            if row:
                state = dict(row["state"] or {})
                last = list(c.execute(select(t.messages.c.role, t.messages.c.text).where(t.messages.c.conversation_id == conv_id).order_by(t.messages.c.id.desc()).limit(6)))
                history = [ChatTurn(m.role, m.text) for m in reversed(last)]
            else:
                conv_id = None
        answer = container.chat(widget.get("not_found") or None).answer(
            site["id"], body.message, history=history, context_doc_id=state.get("doc"), default_language=widget.get("language") or site["default_language"],
        )
        if answer.context_doc_id:
            state["doc"] = answer.context_doc_id
        with container.engine.begin() as c:
            if conv_id:
                c.execute(update(t.conversations).where(t.conversations.c.id == conv_id).values(state=state, updated_at=now))
            else:
                conv_id = secrets.token_urlsafe(24)
                c.execute(insert(t.conversations).values(id=conv_id, site_id=site["id"], state=state, created_at=now, updated_at=now))
            c.execute(insert(t.messages), [
                {"conversation_id": conv_id, "role": "user", "text": body.message, "meta": {}, "created_at": now},
                {"conversation_id": conv_id, "role": "assistant", "text": answer.text, "meta": {"mode": answer.mode, "latency_ms": answer.latency_ms}, "created_at": now},
            ])
        sources = [{"url": s.url, "title": s.title, "checked_at": s.crawled_at} for s in answer.sources] if widget.get("show_sources", True) else []
        return {"conversation_id": conv_id, "answer": answer.text, "language": answer.language, "mode": answer.mode, "sources": sources, "stale": answer.stale,
                # Same shape as the WordPress edition. Clarifying suggestions and contact buttons exist only in Lite so far.
                "suggestions": [], "actions": []}

    # ---- static files --------------------------------------------------------------------
    widget_file = Path(settings.widget_path)

    @app.get("/widget/wia-widget.js")
    def widget_js():
        if not widget_file.is_file():
            raise HTTPException(404)
        return FileResponse(widget_file, media_type="application/javascript", headers={"Cache-Control": "public, max-age=3600"})

    dist = Path(settings.frontend_dist)
    if (dist / "index.html").is_file():
        app.mount("/assets", StaticFiles(directory=dist / "assets"), name="assets")

        @app.get("/{path:path}", include_in_schema=False)
        def spa(path: str):
            if path.startswith(("api/", "public/")):
                raise HTTPException(404)
            return FileResponse(dist / "index.html", headers={"Cache-Control": "no-cache"})

    return app


app_factory = create_app
