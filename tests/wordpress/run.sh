#!/usr/bin/env bash
# End-to-end plugin test on a throwaway WordPress: install from the real ZIP on a fresh site, then again with
# WooCommerce, then uninstall. Exits non-zero on any failure, including any PHP warning in the debug log.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE=(docker compose -f "$ROOT/deployment/docker/wp-test/docker-compose.yml")
wp() { "${COMPOSE[@]}" exec -T cli wp "$@"; }
FAILED=0

trap '[ "${KEEP:-0}" = "1" ] || "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1' EXIT

bash "$ROOT/scripts/build-wp-zip.sh" || exit 1
"${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1
"${COMPOSE[@]}" up -d --quiet-pull 2>&1 | tail -1
for _ in $(seq 1 60); do
  "${COMPOSE[@]}" exec -T cli test -f /var/www/html/wp-config.php && break
  sleep 2
done
"${COMPOSE[@]}" exec -T wp sh -c 'chown -R www-data:www-data /var/www/html' >/dev/null
wp core install --url=http://localhost:8090 --title=Test --admin_user=admin --admin_password=wia-test-pass --admin_email=a@example.test --skip-email >/dev/null || exit 1
wp rewrite structure '/%postname%/' >/dev/null

run_suite() {
  echo "=== $1"
  wp eval-file "/tests/$2" || FAILED=1
}

echo "=== install from ZIP on a fresh WordPress, without WooCommerce"
wp plugin install /plugin/website-intelligence-agent-lite.zip --activate || exit 1
wp cron event list --fields=hook --format=csv | grep -E 'wia_(scan_batch|daily)' | sort -u
run_suite "core suite (no WooCommerce)" suite-core.php

echo "=== HTTP: public REST endpoints from outside WordPress"
BASE=http://localhost:8090/wp-json/wia/v1
post() { curl -s -m 20 -X POST "$BASE/chat" -H 'Content-Type: application/json' -d "$1"; }
check() { # name, haystack, needle
  if printf '%s' "$2" | grep -q "$3"; then echo "ok   $1"; else echo "FAIL $1: $2" | cut -c1-400; FAILED=1; fi
}
check "widget config is public" "$(curl -s -m 20 "$BASE/widget")" '"position":"right"'
check "widget config has starter suggestions" "$(curl -s -m 20 "$BASE/widget")" '"starters":\['
ANSWER=$(post '{"message":"how do I reset the widget gearbox"}')
check "real answer over HTTP" "$ANSWER" 'conversation_id'
check "answer comes from content" "$ANSWER" 'small red button'
check "source link is returned" "$ANSWER" 'gearbox-guide'
check "answer carries suggestions and actions" "$ANSWER" '"suggestions":.*"actions":'
CODE=$(curl -s -m 20 -o /dev/null -w '%{http_code}' -X POST "$BASE/chat" -H 'Content-Type: application/json' -d '{}')
check "missing message rejected" "$CODE" '400'
HOME_HTML=$(curl -s -m 20 http://localhost:8090/)
check "widget loaded on the front end" "$HOME_HTML" 'wia-widget.js'
check "front-end has WIA endpoint" "$HOME_HTML" 'wia\\/v1'
CODE=$(curl -s -m 20 -o /dev/null -w '%{http_code}' "http://localhost:8090/wp-admin/admin-post.php?action=wia_purge")
check "admin action without login refused" "$CODE" '^\(302\|400\|403\)$'
LIMIT=$(for _ in $(seq 1 45); do curl -s -m 10 -o /dev/null -w '%{http_code} ' -X POST "$BASE/chat" -H 'Content-Type: application/json' -d '{"message":"hello"}'; done)
check "rate limit reached over HTTP" "$LIMIT" '429'
wp transient delete --all >/dev/null

echo "=== admin screens render for a logged-in admin"
wp eval-file /tests/admin-pages.php || FAILED=1

echo "=== with WooCommerce"
# A local copy in dist/cache (not committed) avoids depending on a slow connection to wordpress.org.
WOO=woocommerce
[ -f "$ROOT/dist/cache/woocommerce.zip" ] && WOO=/plugin/cache/woocommerce.zip
wp plugin install "$WOO" --activate >/dev/null 2>&1 || { echo "FAIL could not install WooCommerce"; FAILED=1; }
run_suite "WooCommerce suite" suite-woo.php

run_suite "dialogue, learning, knowledge map and reference sites" suite-dialog.php
run_suite "conversation quality: a shop, tuned questions" suite-conversation.php
run_suite "conversation quality: a language school, untuned questions" suite-conversation-service.php

echo "=== evaluation dataset (shared/eval), scored by core/evaluation/dataset.py"
mkdir -p "$ROOT/dist/eval"
EVAL_OUT=$(wp eval-file /tests/suite-eval.php) || FAILED=1
printf '%s\n' "$EVAL_OUT" | grep -v '^WIA_EVAL_JSON '
printf '%s\n' "$EVAL_OUT" | sed -n 's/^WIA_EVAL_JSON //p' > "$ROOT/dist/eval/lite.json"
# Scored on the host with the standard library only: the same scorer as the Self-Hosted edition.
(cd "$ROOT" && python3 -m core.evaluation.dataset dist/eval/lite.json --min "${WIA_EVAL_MIN:-0.95}") || FAILED=1
EVAL_OUT=$("${COMPOSE[@]}" exec -T -e WIA_EVAL_SET=holdout cli wp eval-file /tests/suite-eval.php) || FAILED=1
printf '%s\n' "$EVAL_OUT" | sed -n 's/^WIA_EVAL_JSON //p' > "$ROOT/dist/eval/lite-holdout.json"
(cd "$ROOT" && python3 -m core.evaluation.dataset dist/eval/lite-holdout.json --min "${WIA_EVAL_HOLDOUT_MIN:-0.9}") || FAILED=1

echo "=== large site: batched scan under a low memory limit"
run_suite "scale suite" suite-scale.php

echo "=== PHP notices and warnings raised by the plugin"
LOG=$("${COMPOSE[@]}" exec -T wp sh -c 'cat /tmp/wp-debug.log 2>/dev/null; true' | grep -E 'website-intelligence-agent|WIA_' | grep -v 'translation loading' || true)
if [ -n "$LOG" ]; then echo "FAIL plugin log entries:"; printf '%s\n' "$LOG" | sort -u | cut -c1-300 | head -20; FAILED=1; else echo "ok   none"; fi

echo "=== uninstall keeps data unless asked, and removes everything when asked"
wp plugin deactivate website-intelligence-agent >/dev/null
wp plugin uninstall website-intelligence-agent >/dev/null
count_tables() { wp eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}wia\\_%\""));' | tr -d ' \n'; }
count_options() { wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \"wia\\_%\"");' | tr -d ' \n'; }
KEPT=$(count_tables)
[ "$KEPT" = "9" ] && echo "ok   default uninstall kept the 9 tables" || { echo "FAIL expected 9 tables, found $KEPT"; FAILED=1; }
wp plugin install /plugin/website-intelligence-agent-lite.zip --activate >/dev/null
wp option update wia_delete_on_uninstall 1 >/dev/null
wp plugin deactivate website-intelligence-agent >/dev/null
wp plugin uninstall website-intelligence-agent >/dev/null
LEFT=$(count_tables)
OPTS=$(count_options)
[ "$LEFT" = "0" ] && [ "$OPTS" = "0" ] && echo "ok   explicit uninstall removed all tables and options" || { echo "FAIL left tables=$LEFT options=$OPTS"; FAILED=1; }

[ "$FAILED" = "0" ] && echo "RESULT: ALL PASSED" || echo "RESULT: FAILURES ABOVE"
exit "$FAILED"
