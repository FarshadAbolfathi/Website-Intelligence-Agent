# Website Intelligence Agent

Self-hosted, multilingual (fa/ar/en) site assistant in two editions: a Python engine and a WordPress plugin. Read `docs/architecture/overview.md` first. Speak Persian with the user.

## Commands

```bash
pytest tests/unit tests/security tests/multilingual tests/integration   # engine
bash tests/wordpress/run.sh                                             # plugin end to end, needs Docker (port 8090)
bash scripts/build-wp-zip.sh                                            # dist/website-intelligence-agent-lite.zip
cd frontend && npm run build                                            # dashboard
```

## Rules

- No required cloud AI service. A model must stay optional.
- Lite must never claim to generate text. It quotes.
- Models never state prices or stock. These come from structured data.
- All knowledge access goes through a `Scope`. The chat path can only build an own-site scope.
- Language rules live in `shared/`. Change Python and PHP together, and add a vector to `shared/normalization-vectors.json`.
- PHP stays compatible with 7.4. Use `$wpdb->prepare`, and escape all output.
- `README.md` has a status table. Move an item to "Tested" only after really running it.
- Never add brand names, customer data or personal contact details to this repository.

## Boundaries

- Push only when the user explicitly asks. Local commits are fine.
- Do not touch other projects or containers on this machine. The plugin test stack uses the Compose project `wia-wptest`.
