"""System status for the dashboard, and the catalog of local models with their hardware needs."""

from __future__ import annotations

import os
import shutil

from sqlalchemy import func, select

from backend.container import Container
from core.knowledge import schema as t

# Sizes are the Ollama download sizes; RAM is a practical minimum for CPU inference. Shown before any download.
# "tested" is set only for models actually run through this project's evaluation. See docs/models.md.
MODEL_CATALOG = [
    {"name": "bge-m3", "role": "embedding", "download_gb": 1.2, "min_ram_gb": 2, "languages": {"fa": "good", "ar": "good", "en": "good"}, "tested": False, "note": "Default embedding model. Multilingual."},
    {"name": "qwen2.5:3b", "role": "chat", "download_gb": 2.2, "min_ram_gb": 5, "languages": {"fa": "fair", "ar": "fair", "en": "good"}, "tested": False, "note": "Small VPS option. Expect slow CPU responses."},
    {"name": "qwen2.5:7b", "role": "chat", "download_gb": 4.7, "min_ram_gb": 8, "languages": {"fa": "fair", "ar": "good", "en": "good"}, "tested": False, "note": "Balanced option."},
    {"name": "qwen2.5:14b", "role": "chat", "download_gb": 9.0, "min_ram_gb": 16, "languages": {"fa": "good", "ar": "good", "en": "good"}, "tested": False, "note": "Better quality; a GPU is recommended."},
    {"name": "aya:8b", "role": "chat", "download_gb": 4.8, "min_ram_gb": 8, "languages": {"fa": "fair", "ar": "good", "en": "good"}, "tested": False, "note": "Built for many languages. Research-only licence: check before commercial use."},
]


def _resources() -> dict:
    disk = shutil.disk_usage(".")
    out = {"disk_free_gb": round(disk.free / 1e9, 1), "disk_total_gb": round(disk.total / 1e9, 1), "cpu_count": os.cpu_count()}
    if hasattr(os, "getloadavg"):
        out["load_1m"] = round(os.getloadavg()[0], 2)
    try:
        import psutil

        memory = psutil.virtual_memory()
        out |= {"ram_total_gb": round(memory.total / 1e9, 1), "ram_used_percent": memory.percent, "process_rss_mb": round(psutil.Process().memory_info().rss / 1e6)}
    except ImportError:
        try:
            pages, size = os.sysconf("SC_PHYS_PAGES"), os.sysconf("SC_PAGE_SIZE")
            out["ram_total_gb"] = round(pages * size / 1e9, 1)
        except (ValueError, OSError, AttributeError):
            pass
    return out


def system_status(container: Container) -> dict:
    database = {"backend": container.engine.dialect.name, "healthy": True}
    try:
        with container.engine.connect() as c:
            database["sites"] = c.execute(select(func.count()).select_from(t.sites)).scalar_one()
            jobs = dict(c.execute(select(t.jobs.c.status, func.count()).group_by(t.jobs.c.status)).all())
    except Exception as exc:  # noqa: BLE001 - the status page must report a failure instead of failing itself
        database |= {"healthy": False, "error": type(exc).__name__}
        jobs = {}
    llm = container.llm
    vectors = container.vectors
    return {
        "database": database,
        "model": {"name": llm.name, "healthy": llm.healthy(), "mode": "generative" if llm.name != "none" else "extractive (no model configured)"},
        "embedder": {"name": container.embedder.name, "healthy": container.embedder.healthy(), "semantic": container.embedder.semantic},
        "vector_store": {"name": vectors.name if vectors else "none (lexical search only)", "healthy": vectors.healthy() if vectors else True},
        "jobs": jobs,
        "resources": _resources(),
    }
