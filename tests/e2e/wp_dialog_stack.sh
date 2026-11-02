#!/usr/bin/env bash
# Fresh test WordPress in Persian with WooCommerce and the plugin, filled for tests/e2e/wp_dialog.py.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE=(docker compose -f "$ROOT/deployment/docker/wp-test/docker-compose.yml")
wp() { "${COMPOSE[@]}" exec -T cli wp "$@"; }
bash "$ROOT/scripts/build-wp-zip.sh" >/dev/null || exit 1
"${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1
"${COMPOSE[@]}" up -d --quiet-pull 2>&1 | tail -1
for _ in $(seq 1 60); do "${COMPOSE[@]}" exec -T cli test -f /var/www/html/wp-config.php && break; sleep 2; done
"${COMPOSE[@]}" exec -T wp sh -c 'chown -R www-data:www-data /var/www/html' >/dev/null
wp core install --url=http://localhost:8090 --title=Test --admin_user=admin --admin_password=wia-test-pass --admin_email=a@example.test --skip-email >/dev/null || exit 1
wp rewrite structure '/%postname%/' >/dev/null
wp language core install fa_IR --activate >/dev/null 2>&1 || echo "note: Persian WordPress language pack not installed"
WOO=woocommerce
[ -f "$ROOT/dist/cache/woocommerce.zip" ] && WOO=/plugin/cache/woocommerce.zip
wp plugin install "$WOO" --activate >/dev/null 2>&1 || { echo "could not install WooCommerce"; exit 1; }
wp plugin install /plugin/website-intelligence-agent-lite.zip --activate >/dev/null || exit 1
wp eval-file /tests/e2e-dialog-setup.php
wp transient delete --all >/dev/null
