"""Background worker process: `python -m backend.workers.worker`."""

from __future__ import annotations

import logging
import signal
import time
from datetime import datetime, timedelta

from backend.container import Container
from backend.services import jobs
from backend.settings import Settings
from core.knowledge.store import utcnow

log = logging.getLogger("wia.worker")


def loop(container: Container, *, poll_seconds: float = 3.0) -> None:
    running = True

    def stop(*_):
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    last_housekeeping: datetime = utcnow() - timedelta(days=1)
    log.info("worker started")
    while running:
        if utcnow() - last_housekeeping > timedelta(minutes=1):
            jobs.recover_stale(container)
            jobs.fire_schedules(container)
            if last_housekeeping.date() != utcnow().date():
                jobs.enqueue(container, "purge")
            last_housekeeping = utcnow()
        if not jobs.run_one(container):
            time.sleep(poll_seconds)
    log.info("worker stopped")


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    loop(Container(Settings()))
