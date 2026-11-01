#!/usr/bin/env bash
# Builds dist/website-intelligence-agent-lite.zip, an installable WordPress plugin package.
# Shared language data and the widget come from shared/, so both editions ship the same files.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/wordpress/plugin/website-intelligence-agent"
OUT="$ROOT/dist"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PKG="$WORK/website-intelligence-agent"
mkdir -p "$PKG/shared/widget" "$OUT"
cp -R "$SRC/." "$PKG/"
cp "$ROOT/shared/language.json" "$ROOT/shared/chat-messages.json" "$PKG/shared/"
cp "$ROOT/shared/widget/wia-widget.js" "$PKG/shared/widget/"
cp "$ROOT/LICENSE" "$PKG/LICENSE" 2>/dev/null || true
python3 "$ROOT/scripts/compile_mo.py" "$PKG/languages"

rm -f "$OUT/website-intelligence-agent-lite.zip"
(cd "$WORK" && zip -qr "$OUT/website-intelligence-agent-lite.zip" website-intelligence-agent -x '*.DS_Store')
echo "built $OUT/website-intelligence-agent-lite.zip ($(du -h "$OUT/website-intelligence-agent-lite.zip" | cut -f1))"
