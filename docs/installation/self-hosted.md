# Install the Self-Hosted edition

Requirements: Docker with the Compose plugin. Memory depends on the model: 2 GB is enough with no model, 4 GB adds embeddings, and 8 GB or more is needed for a chat model (see [choosing a model](../models.md)).

## With Docker Compose

```bash
git clone <repository-url>
cd website-intelligence-agent
cp .env.example .env
# Set POSTGRES_PASSWORD in .env: openssl rand -hex 24
docker compose up -d
docker compose logs api | grep "setup token"
```

Open http://127.0.0.1:8000 and create the admin account with the setup token. The token is there so that only someone with access to the server can claim a new install.

The panel listens only on this machine by default. On a remote server, use an SSH tunnel (`ssh -L 8000:127.0.0.1:8000 you@server`), or set up HTTPS as below.

## HTTPS on a public domain

A visitor's browser must reach the engine for the chat widget to work, so a public site needs this. Point a domain at the server, then set these in `.env`:

```dotenv
WIA_DOMAIN=assistant.example.com
WIA_TRUST_PROXY=true
```

```bash
docker compose --profile https up -d
```

Caddy obtains and renews the certificate.

## First site

1. In **Sites**, add your site's URL.
2. Open it and select **Start learning**.
3. Use **Test chat** to try it.
4. Copy the install code from the **Widget** tab into your site's HTML.

The widget only works from the domain you registered. Pages at other origins are refused.

## Add local models

With no model, answers quote your site's text. To add meaning-based search and generated answers:

```bash
docker compose exec ollama ollama pull bge-m3
docker compose exec ollama ollama pull qwen2.5:7b
```

In `.env`, set `WIA_EMBED_BACKEND=ollama`, then run `docker compose up -d`. Run **Start learning** again so existing pages get embeddings. Select the chat model on the **Local models** page.

Models can also be downloaded from that page, which shows the size and memory needed before you start.

To use llama.cpp instead, run `llama-server` yourself and set `WIA_LLM_BACKEND=llamacpp` and `WIA_LLM_URL`.

## Without Docker

Requires Python 3.11 or newer, PostgreSQL 14 or newer and Node 20 or newer. Qdrant and Ollama are optional.

```bash
python -m venv .venv && . .venv/bin/activate
pip install ".[metrics]"
(cd frontend && npm ci && npm run build)
export WIA_DATABASE_URL=postgresql://wia:PASSWORD@localhost:5432/wia
uvicorn backend.api.main:app --port 8000      # one service
python -m backend.workers.worker              # another service
```

## JavaScript-heavy sites

Install the optional renderer and set `WIA_CRAWLER_JS=true`:

```bash
pip install ".[js]" && playwright install --with-deps chromium
```

This uses much more memory and is slower. Leave it off if your pages contain their text in the HTML.

## Backups and removal

Back up the `db_data` volume. Qdrant can be rebuilt by running a new crawl. Deleting a site from the dashboard removes its documents, vectors, conversations and reports. `docker compose down -v` removes everything.
