# Build context is the repository root.
FROM node:20-alpine AS dashboard
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM python:3.12-slim AS engine
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 WIA_FRONTEND_DIST=/app/frontend/dist WIA_WIDGET_PATH=/app/shared/widget/wia-widget.js
WORKDIR /app
COPY pyproject.toml README.md ./
COPY core/ core/
COPY backend/ backend/
COPY shared/ shared/
RUN pip install --no-cache-dir ".[metrics]"
COPY --from=dashboard /build/dist frontend/dist
RUN useradd --system --uid 10001 --home /app wia
USER wia
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD python -c "import urllib.request as u;u.urlopen('http://127.0.0.1:8000/healthz',timeout=4)"
CMD ["uvicorn", "backend.api.main:app", "--host", "0.0.0.0", "--port", "8000"]
