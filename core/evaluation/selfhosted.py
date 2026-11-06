"""Produce Self-Hosted results for the shared evaluation dataset.

The site in shared/eval/site.json is rendered to HTML and learned through the real extractor and ingestor, then
every conversation is played through ChatEngine with history and context handled as the public chat API does.

    python -m core.evaluation.selfhosted --llm none --out dist/eval/selfhosted-none.json
    python -m core.evaluation.selfhosted --llm ollama:qwen2.5-coder:14b --out dist/eval/selfhosted-qwen.json
    python -m core.evaluation.selfhosted --llm failing --out dist/eval/x.json   # every model call fails: the fallback path
    python -m core.evaluation.selfhosted --set holdout --out dist/eval/holdout.json   # the held-out set
"""

from __future__ import annotations

import argparse
import html
import json
import time
from pathlib import Path

from sqlalchemy import create_engine, insert
from sqlalchemy.pool import StaticPool

from core.chat.engine import ChatEngine, ChatTurn
from core.embeddings.providers import NoEmbedder
from core.evaluation.dataset import load
from core.extractor.html import extract
from core.knowledge import schema as t
from core.knowledge.ingest import Ingestor
from core.knowledge.store import Scope, SqlKnowledgeStore, utcnow
from core.llm.providers import LLMError, NoLLM, OllamaProvider
from core.retrieval.hybrid import Retriever

BASE = "https://coffee.example.test"
AVAILABILITY = {"instock": "InStock", "outofstock": "OutOfStock"}


class FailingLLM:
    """A model that is configured but never answers: timeouts, a crashed runtime."""

    name = "failing"

    def generate(self, messages, *, temperature=0.2, max_tokens=400):
        raise LLMError("simulated outage")

    def healthy(self):
        return False


def _page(lang: str, title: str, body: str, head: str = "") -> str:
    direction = "ltr" if lang == "en" else "rtl"
    return (f'<!doctype html><html lang="{lang}" dir="{direction}"><head><meta charset="utf-8"><title>{html.escape(title)}</title>{head}</head>'
            f"<body><main><h1>{html.escape(title)}</h1>{body}</main></body></html>")


def build(store: SqlKnowledgeStore, site: dict) -> int:
    with store.engine.begin() as c:
        site_id = c.execute(insert(t.sites).values(name="eval", base_url=BASE, public_key="eval", default_language="fa", settings={}, created_at=utcnow())).inserted_primary_key[0]
        for faq in site["faqs"]:
            c.execute(insert(t.faqs).values(site_id=site_id, question=faq["question"], answer=faq["answer"], keywords=faq.get("keywords", ""), language="", created_at=utcnow()))
    ingestor = Ingestor(store, NoEmbedder())
    scope = Scope(site_id)
    for p in site["pages"]:
        ingestor.ingest_page(scope, extract(_page(p["lang"], p["title"], p["html"]), f"{BASE}/{p['slug']}"))
    for p in site["products"]:
        data = {"@context": "https://schema.org", "@type": "Product", "name": p["name"], "sku": p["slug"], "description": p["body"],
                "offers": {"@type": "Offer", "price": p["price"], "priceCurrency": site["currency"], "availability": f"https://schema.org/{AVAILABILITY[p['stock']]}"}}
        head = f'<script type="application/ld+json">{json.dumps(data, ensure_ascii=False)}</script>'
        ingestor.ingest_page(scope, extract(_page("fa", p["name"], f"<p>{html.escape(p['body'])}</p>", head), f"{BASE}/product/{p['slug']}"))
    return site_id


class Metered:
    """Counts the tokens a provider reports. Ollama returns them with every reply; nothing is estimated."""

    def __init__(self, provider) -> None:
        self.provider = provider
        self.name = provider.name
        self.prompt_tokens = 0
        self.completion_tokens = 0

    def generate(self, messages, *, temperature=0.2, max_tokens=400):
        reply = self.provider.generate(messages, temperature=temperature, max_tokens=max_tokens)
        usage = getattr(self.provider, "last_usage", None) or {}
        self.prompt_tokens += int(usage.get("prompt_tokens", 0))
        self.completion_tokens += int(usage.get("completion_tokens", 0))
        return reply

    def healthy(self):
        return self.provider.healthy()


def provider_for(spec: str, ollama_url: str):
    if spec in ("", "none"):
        return NoLLM()
    if spec == "failing":
        return FailingLLM()
    if spec.startswith("ollama:"):
        return OllamaProvider(ollama_url, spec.split(":", 1)[1])
    raise SystemExit(f"unknown --llm {spec}")


def run(spec: str = "none", *, ollama_url: str = "http://localhost:11434", dataset: str = "dev") -> dict:
    site, data = load(dataset)
    engine = create_engine("sqlite://", poolclass=StaticPool, connect_args={"check_same_thread": False})
    t.metadata.create_all(engine)
    store = SqlKnowledgeStore(engine)
    site_id = build(store, site)
    provider = provider_for(spec, ollama_url)
    llm = provider if isinstance(provider, NoLLM) else Metered(provider)
    chat = ChatEngine(store, Retriever(store, NoEmbedder()), llm)
    turns = []
    for case in data["cases"]:
        messages: list[ChatTurn] = []
        state: dict = {}
        for index, turn in enumerate(case["turns"]):
            before = (getattr(llm, "prompt_tokens", 0), getattr(llm, "completion_tokens", 0))
            started = time.monotonic()
            # The public API passes the last six stored messages and the document under discussion.
            answer = chat.answer(site_id, turn["q"], history=messages[-6:], context_doc_id=state.get("doc"), default_language="fa")
            elapsed = int((time.monotonic() - started) * 1000)
            if answer.context_doc_id:
                state["doc"] = answer.context_doc_id
            messages += [ChatTurn("user", turn["q"]), ChatTurn("assistant", answer.text)]
            turns.append({
                "case": case["id"], "turn": index, "q": turn["q"], "text": answer.text, "mode": answer.mode, "language": answer.language,
                "sources": [s.title for s in answer.sources], "latency_ms": elapsed, "notes": answer.notes,
                "prompt_tokens": getattr(llm, "prompt_tokens", 0) - before[0], "completion_tokens": getattr(llm, "completion_tokens", 0) - before[1],
            })
    return {"edition": "selfhosted", "set": dataset, "config": {"llm": spec, "embeddings": "none"}, "turns": turns}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--llm", default="none", help="none | failing | ollama:<model>")
    parser.add_argument("--ollama", default="http://localhost:11434")
    parser.add_argument("--set", default="dev", choices=["dev", "holdout"])
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    results = run(args.llm, ollama_url=args.ollama, dataset=args.set)
    Path(args.out).parent.mkdir(parents=True, exist_ok=True)
    Path(args.out).write_text(json.dumps(results, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"wrote {len(results['turns'])} turns to {args.out}")


if __name__ == "__main__":
    main()
