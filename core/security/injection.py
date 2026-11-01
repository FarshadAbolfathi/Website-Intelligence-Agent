"""Prompt-injection defences.

Crawled text is data, never instructions. Three independent layers apply:

1. `strip_injection` removes instruction-like sentences before content is stored.
2. `wrap_untrusted` fences content in the prompt with a per-request random delimiter, so a page cannot close the
   fence and start speaking as the system.
3. The chat engine verifies every generated answer against its sources (see `core.chat.verify`). This layer does
   not rely on the model obeying anything.

Pattern matching catches common attacks only. It is not a guarantee, which is why layer 3 exists.
"""

from __future__ import annotations

import re
import secrets

from core.text.normalize import LANGUAGE, normalize, sentences

_INJECTION = re.compile("|".join(LANGUAGE["injection_patterns"]), re.IGNORECASE)
_SECRET_PROBE = re.compile(LANGUAGE["secret_pattern"], re.IGNORECASE)


def looks_like_injection(text: str) -> bool:
    return bool(_INJECTION.search(normalize(text)) or _INJECTION.search((text or "").lower()))


def probes_for_secrets(text: str) -> bool:
    return bool(_SECRET_PROBE.search(normalize(text)))


def strip_injection(text: str) -> tuple[str, int]:
    """Remove instruction-like sentences. Returns the cleaned text and how many sentences were dropped."""
    kept_lines: list[str] = []
    dropped = 0
    for line in (text or "").split("\n"):
        kept = []
        for sentence in sentences(line):
            if looks_like_injection(sentence):
                dropped += 1
            else:
                kept.append(sentence)
        if kept:
            kept_lines.append(" ".join(kept))
    return "\n".join(kept_lines), dropped


def wrap_untrusted(items: list[str]) -> tuple[str, str]:
    """Fence retrieved content. Returns the block and the fence, which the system prompt refers to by name."""
    fence = "DATA_" + secrets.token_hex(8)
    body = "\n".join(
        f"<{fence} id=\"{i}\">\n{text.replace(fence, '')}\n</{fence}>" for i, text in enumerate(items, start=1)
    )
    return body, fence
