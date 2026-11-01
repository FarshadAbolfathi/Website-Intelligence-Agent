"""Background work: a job queue stored in the main database, plus recurring schedules.

No Redis or Celery. `FOR UPDATE SKIP LOCKED` on PostgreSQL gives safe concurrent workers, which saves a service and
memory on a small VPS. A crash leaves a job 'running' with an old lock, and the next worker pass re-queues it.
"""

from __future__ import annotations

import logging
from datetime import timedelta

from sqlalchemy import and_, delete, insert, select, update

from backend.container import Container
from core.knowledge import schema as t
from core.knowledge.store import Corpus, Scope, utcnow

log = logging.getLogger("wia.worker")
MAX_ATTEMPTS = 3
LOCK_TIMEOUT = timedelta(hours=4)


def enqueue(container: Container, kind: str, *, site_id: int | None = None, payload: dict | None = None, delay_seconds: int = 0) -> int:
    now = utcnow()
    with container.engine.begin() as c:
        return c.execute(insert(t.jobs).values(
            site_id=site_id, kind=kind, payload=payload or {}, status="queued", run_after=now + timedelta(seconds=delay_seconds), created_at=now,
        )).inserted_primary_key[0]


def claim_job(container: Container) -> dict | None:
    now = utcnow()
    with container.engine.begin() as c:
        query = select(t.jobs).where(t.jobs.c.status == "queued", t.jobs.c.run_after <= now).order_by(t.jobs.c.id).limit(1)
        if container.engine.dialect.name == "postgresql":
            query = query.with_for_update(skip_locked=True)
        row = c.execute(query).mappings().first()
        if not row:
            return None
        claimed = c.execute(
            update(t.jobs).where(t.jobs.c.id == row["id"], t.jobs.c.status == "queued").values(status="running", locked_at=now, attempts=row["attempts"] + 1)
        ).rowcount
        return dict(row) if claimed else None


def recover_stale(container: Container) -> int:
    with container.engine.begin() as c:
        return c.execute(update(t.jobs).where(t.jobs.c.status == "running", t.jobs.c.locked_at < utcnow() - LOCK_TIMEOUT).values(status="queued", locked_at=None)).rowcount


def finish(container: Container, job_id: int, *, result: dict | None = None, error: str = "", retry: bool = False) -> None:
    values: dict = {"finished_at": utcnow(), "error": error[:2000], "result": result}
    if error and retry:
        values |= {"status": "queued", "run_after": utcnow() + timedelta(minutes=5), "finished_at": None, "locked_at": None}
    else:
        values["status"] = "failed" if error else "done"
    with container.engine.begin() as c:
        c.execute(update(t.jobs).where(t.jobs.c.id == job_id).values(**values))


# ---- handlers ------------------------------------------------------------------------------
def run_crawl(container: Container, site_id: int, competitor_id: int | None = None, overrides: dict | None = None) -> dict:
    with container.engine.connect() as c:
        site = c.execute(select(t.sites).where(t.sites.c.id == site_id)).mappings().first()
        if not site:
            raise ValueError("site not found")
        comp = None
        if competitor_id is not None:
            comp = c.execute(select(t.competitors).where(t.competitors.c.id == competitor_id, t.competitors.c.site_id == site_id)).mappings().first()
            if not comp:
                raise ValueError("competitor not found")
    scope = Scope(site_id, Corpus.COMPETITOR, competitor_id) if comp else Scope(site_id)
    base_url = comp["base_url"] if comp else site["base_url"]
    site_overrides = dict((site["settings"] or {}).get("crawl", {}))
    site_overrides.update(overrides or {})

    now = utcnow()
    with container.engine.begin() as c:
        run_id = c.execute(insert(t.crawl_runs).values(site_id=site_id, corpus=scope.corpus.value, competitor_id=competitor_id, status="running", stats={}, failures=[], started_at=now)).inserted_primary_key[0]
    fetcher = container.fetcher()
    try:
        stats = container.ingestor.crawl_site(scope, base_url, fetcher, container.crawl_config(competitor=bool(comp), overrides=site_overrides))
    except Exception as exc:
        with container.engine.begin() as c:
            c.execute(update(t.crawl_runs).where(t.crawl_runs.c.id == run_id).values(status="failed", failures=[{"url": base_url, "error": str(exc)[:500]}], finished_at=utcnow()))
        raise
    finally:
        if hasattr(fetcher, "close"):
            fetcher.close()
    status = "blocked" if stats.crawl.get("stopped_early", "").startswith("robots") else "done"
    with container.engine.begin() as c:
        c.execute(update(t.crawl_runs).where(t.crawl_runs.c.id == run_id).values(status=status, stats=stats.as_dict(), failures=stats.failures[:200], finished_at=utcnow()))
    return {"run_id": run_id, **stats.as_dict()}


def run_evaluation(container: Container, site_id: int) -> dict:
    with container.engine.connect() as c:
        site = c.execute(select(t.sites).where(t.sites.c.id == site_id)).mappings().first()
    if not site:
        raise ValueError("site not found")
    with container.engine.begin() as c:
        run_id = c.execute(insert(t.evaluation_runs).values(site_id=site_id, status="running", metrics={}, items=[], findings={}, started_at=utcnow())).inserted_primary_key[0]
    try:
        result = container.evaluator().run(site_id, default_language=site["default_language"])
    except Exception:
        with container.engine.begin() as c:
            c.execute(update(t.evaluation_runs).where(t.evaluation_runs.c.id == run_id).values(status="failed", finished_at=utcnow()))
        raise
    with container.engine.begin() as c:
        c.execute(update(t.evaluation_runs).where(t.evaluation_runs.c.id == run_id).values(status="done", metrics=result["metrics"], items=result["items"], findings=result["findings"], finished_at=utcnow()))
    return {"run_id": run_id, "metrics": result["metrics"]}


def run_competitor_report(container: Container, site_id: int, competitor_id: int | None = None) -> dict:
    report = container.analyzer.full_report(site_id, competitor_id)
    with container.engine.begin() as c:
        report_id = c.execute(insert(t.competitor_reports).values(site_id=site_id, kind="full", report=report, created_at=utcnow())).inserted_primary_key[0]
    return {"report_id": report_id}


def purge_conversations(container: Container) -> dict:
    cutoff = utcnow() - timedelta(days=container.settings.conversation_retention_days)
    with container.engine.begin() as c:
        ids = [r.id for r in c.execute(select(t.conversations.c.id).where(t.conversations.c.updated_at < cutoff))]
        if ids:
            c.execute(delete(t.messages).where(t.messages.c.conversation_id.in_(ids)))
            c.execute(delete(t.conversations).where(t.conversations.c.id.in_(ids)))
    return {"deleted": len(ids)}


def pull_model(container: Container, name: str) -> dict:
    """Ask the owner's Ollama to download a model. Only ever called from an authenticated admin request."""
    import httpx

    with httpx.Client(timeout=None) as client:
        response = client.post(f"{container.settings.llm_url.rstrip('/')}/api/pull", json={"model": name, "stream": False})
        response.raise_for_status()
        return {"status": response.json().get("status", "")}


HANDLERS = {
    "crawl": lambda c, job: run_crawl(c, job["site_id"], job["payload"].get("competitor_id"), job["payload"].get("overrides")),
    "evaluate": lambda c, job: run_evaluation(c, job["site_id"]),
    "competitor_report": lambda c, job: run_competitor_report(c, job["site_id"], job["payload"].get("competitor_id")),
    "purge": lambda c, job: purge_conversations(c),
    "pull_model": lambda c, job: pull_model(c, job["payload"]["name"]),
}


def run_one(container: Container) -> bool:
    job = claim_job(container)
    if not job:
        return False
    handler = HANDLERS.get(job["kind"])
    if not handler:
        finish(container, job["id"], error=f"unknown job kind: {job['kind']}")
        return True
    try:
        finish(container, job["id"], result=handler(container, job))
    except Exception as exc:  # noqa: BLE001 - one failed job must not stop the worker
        log.exception("job %s (%s) failed", job["id"], job["kind"])
        finish(container, job["id"], error=f"{type(exc).__name__}: {exc}", retry=job["attempts"] + 1 < MAX_ATTEMPTS)
    return True


def fire_schedules(container: Container) -> int:
    """Turn due schedules into jobs. Competitor sites get one crawl job each. Skips work already queued."""
    now = utcnow()
    fired = 0
    with container.engine.connect() as c:
        due = list(c.execute(select(t.schedules).where(t.schedules.c.next_run <= now)).mappings())
    for schedule in due:
        with container.engine.begin() as c:
            won = c.execute(update(t.schedules).where(and_(t.schedules.c.id == schedule["id"], t.schedules.c.next_run == schedule["next_run"])).values(next_run=now + timedelta(hours=schedule["every_hours"]))).rowcount
        if not won:
            continue
        if schedule["kind"] == "crawl_competitors":
            with container.engine.connect() as c:
                comps = [r.id for r in c.execute(select(t.competitors.c.id).where(t.competitors.c.site_id == schedule["site_id"], t.competitors.c.enabled.is_(True)))]
            for comp in comps:
                enqueue(container, "crawl", site_id=schedule["site_id"], payload={"competitor_id": comp})
        else:
            enqueue(container, schedule["kind"], site_id=schedule["site_id"])
        fired += 1
    return fired
