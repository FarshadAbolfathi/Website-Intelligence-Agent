"""Admin authentication using only the standard library: scrypt passwords and HMAC-signed session cookies."""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import secrets
import time
from collections import defaultdict, deque
from threading import Lock

SESSION_COOKIE = "wia_session"
SESSION_SECONDS = 8 * 3600
_N, _R, _P = 2**15, 8, 1


def hash_password(password: str) -> str:
    salt = os.urandom(16)
    key = hashlib.scrypt(password.encode(), salt=salt, n=_N, r=_R, p=_P, maxmem=64 * 1024 * 1024, dklen=32)
    return f"scrypt${_N}${_R}${_P}${base64.b64encode(salt).decode()}${base64.b64encode(key).decode()}"


def verify_password(password: str, stored: str) -> bool:
    try:
        _, n, r, p, salt, key = stored.split("$")
        derived = hashlib.scrypt(password.encode(), salt=base64.b64decode(salt), n=int(n), r=int(r), p=int(p), maxmem=64 * 1024 * 1024, dklen=32)
        return hmac.compare_digest(derived, base64.b64decode(key))
    except (ValueError, TypeError):
        return False


# Spent on unknown usernames too, so response time does not reveal which usernames exist.
DUMMY_HASH = hash_password(secrets.token_hex(16))


def _b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode().rstrip("=")


def _unb64(data: str) -> bytes:
    return base64.urlsafe_b64decode(data + "=" * (-len(data) % 4))


def sign_session(username: str, secret: str, ttl: int = SESSION_SECONDS) -> str:
    payload = _b64(json.dumps({"u": username, "e": int(time.time()) + ttl}).encode())
    signature = _b64(hmac.new(secret.encode(), payload.encode(), hashlib.sha256).digest())
    return f"{payload}.{signature}"


def read_session(token: str, secret: str) -> str | None:
    try:
        payload, signature = token.split(".")
        expected = _b64(hmac.new(secret.encode(), payload.encode(), hashlib.sha256).digest())
        if not hmac.compare_digest(signature, expected):
            return None
        data = json.loads(_unb64(payload))
        return data["u"] if data["e"] > time.time() else None
    except (ValueError, KeyError, TypeError):
        return None


class RateLimiter:
    """Sliding window per key, in memory. Each API process limits on its own; put a proxy limit in front for more."""

    def __init__(self, limit: int, window_seconds: float) -> None:
        self.limit = limit
        self.window = window_seconds
        self._hits: dict[str, deque[float]] = defaultdict(deque)
        self._lock = Lock()

    def allow(self, key: str) -> bool:
        now = time.monotonic()
        with self._lock:
            if len(self._hits) > 50_000:
                self._hits.clear()
            hits = self._hits[key]
            while hits and hits[0] <= now - self.window:
                hits.popleft()
            if len(hits) >= self.limit:
                return False
            hits.append(now)
            return True
