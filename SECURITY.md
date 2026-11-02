# Security Policy

## Reporting a vulnerability

Please do not open a public issue for a vulnerability. Use GitHub's private vulnerability reporting on this repository, and include steps to reproduce. You can expect an acknowledgement within a week.

## Supported versions

Only the latest release receives security fixes.

## Scope

Particularly relevant reports:

- Reaching private or internal addresses through the crawler (SSRF)
- Reading one site's data through another site's key, or competitor data through the public chat API
- Bypassing admin authentication
- Script injection through crawled content, chat messages or widget settings
- Making a generated answer state unsupported facts in a way verification misses

## Known limits

- Injection filtering of crawled text is pattern based and can be evaded. Answer verification limits the damage but cannot prove an answer true.
- The public chat endpoints are unauthenticated by design. They are limited per IP, and each process counts separately.
- A browser's `Origin` check stops other websites embedding your widget. It does not stop scripts calling your public API directly, so keep public content public and secrets out of it.
- Self-Hosted admin sessions cannot be revoked one by one before they expire (8 hours), except by changing `WIA_SECRET_KEY`.

See [the architecture notes](docs/architecture/overview.md) for how these defences work.
