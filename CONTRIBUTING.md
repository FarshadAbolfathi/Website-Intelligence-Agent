# Contributing

## Setup

```bash
python -m venv .venv && . .venv/bin/activate
pip install -e ".[dev]"
pytest
```

Dashboard: `cd frontend && npm install && npm run dev`, with the API running on port 8000.

Plugin: `bash tests/wordpress/run.sh` needs Docker. It builds the ZIP and installs it on a throwaway WordPress at port 8090.

## Tests

| Command | Covers | Needs |
|---|---|---|
| `pytest tests/unit tests/security tests/multilingual tests/integration` | The engine, against live local HTTP sites | Nothing |
| `WIA_TEST_LLM=qwen2.5:7b WIA_TEST_EMBED=bge-m3 pytest tests/integration/test_real_models.py -s` | Real models | Ollama with those models |
| `bash tests/wordpress/run.sh` | The plugin end to end | Docker |

Add a test with every behaviour change. Prefer real behaviour over mocks: the HTTP tests use real sockets, and the plugin tests use real WordPress and MySQL.

## Rules that keep the project honest

- **No required cloud service.** Everything must work with no outside API.
- **Nothing is called AI unless a model produced it.** Lite must never claim to generate text.
- **Never let a model state a price or stock level.** These come from structured data.
- **Competitor data must never reach customer-facing answers.** Keep every read behind a `Scope`.
- **Report limits plainly.** If a feature is partial, the documentation says so.

## Changing language behaviour

Language rules exist in two languages: Python (`core/text/normalize.py`) and PHP (`WIA_Text`).

1. Add the expected behaviour to `shared/normalization-vectors.json`.
2. Change both implementations.
3. Run both test suites.

Words and patterns belong in `shared/language.json`, not in code. Answer text belongs in `shared/chat-messages.json`.

## Adding a model runtime or database

Implement the matching interface (`LLMProvider`, `Embedder`, `VectorIndex` or `Fetcher`) and add one branch in `backend/container.py`.

## Style

Python follows `ruff` as configured in `pyproject.toml`. PHP follows WordPress conventions, stays compatible with PHP 7.4, and escapes all output. New user-visible text needs Persian, Arabic and English.

## License

By contributing, you agree that your contribution is licensed under GPL-3.0-or-later.
