#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_DIR="$(mktemp -d)"
PORT="${SESAME_PORTAL_TEST_PORT:-18089}"
COOKIE_JAR="$STATE_DIR/cookies.txt"
SERVER_LOG="$STATE_DIR/server.log"

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$STATE_DIR"
}
trap cleanup EXIT

export SESAME_PORTAL_STATE_DIR="$STATE_DIR"
export SESAME_PORTAL_SECRET="test-secret"
export ROOT

php "$ROOT/bin/portal" migrate >/dev/null
php "$ROOT/bin/portal" create-admin admin admin123 >/dev/null

TOKEN="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
\SesamePortal\DB::migrate();
$pdo = \SesamePortal\DB::pdo();
$now = \SesamePortal\Util::now();
$key = hash('sha256', (string)\SesamePortal\Config::get('app_secret'), true);
$iv = random_bytes(12);
$tag = '';
$cipher = openssl_encrypt('legacy-management-secret', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
$legacy = base64_encode($iv . $tag . $cipher);
$metrics = json_encode([
    'version' => ['version' => ['appVersion' => '0.1.0', 'buildId' => 'smoke-build']],
    'status' => [
        'cpu' => ['aggregate' => ['usagePercent' => 12.345]],
        'memory' => ['usedBytes' => 1, 'totalBytes' => 4],
        'archiveOrphans' => ['activeCameraCount' => 2],
    ],
    'streams' => ['streams' => [['name' => 'smoke-cam'], ['name' => 'readonly-cam']]],
    'fetchedAt' => $now,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, last_check_result, last_metrics_at, last_metrics_json, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Smoke DVR', 'https://dvr.example.invalid', $legacy, 'HTTP 200 {"version":{"sourceCommit":"abcdef1234567890"}}', $now, $metrics, $now]);
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, last_check_result, created_at) VALUES(?, ?, ?, ?, ?)')
    ->execute(['No Token DVR', 'https://no-token.example.invalid', null, '', $now]);
$pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_stream_name, latitude, longitude, direction_deg, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Smoke Cam', 'rtsp://example.invalid/smoke', 1, 'manual', '1d', 'smoke-cam', 25.2048, 55.2708, 90, $now, $now]);
$pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_control_mode, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Read Only Cam', '', 1, 'manual', '1d', 'read_only', 'readonly-cam', $now, $now]);
$pdo->prepare('INSERT INTO portal_groups(name, description, blocked, created_at) VALUES(?, ?, ?, ?)')
    ->execute(['Smoke Group', 'smoke test group', 0, $now]);
$pdo->prepare('INSERT INTO camera_groups(camera_id, group_id) VALUES(?, ?)')
    ->execute([1, 1]);
\SesamePortal\DvrClient::syncCamera(2);
$pdo->prepare('INSERT INTO audit_logs(actor_user_id, action, details, created_at) VALUES(?, ?, ?, ?)')
    ->execute([1, 'camera.save', 'camera_id=1 sync=ok', $now]);
$stmt = $pdo->prepare('SELECT daily_token FROM users WHERE login = ?');
$stmt->execute(['admin']);
echo $stmt->fetchColumn();
PHP
)"

rotate_output="$(php "$ROOT/bin/portal" rotate-secrets)"
grep -q "rotated 1 encrypted secrets" <<<"$rotate_output"
crypto_check="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$stmt = \SesamePortal\DB::pdo()->query('SELECT management_token_enc FROM dvr_servers WHERE id = 1');
$encoded = (string)$stmt->fetchColumn();
echo str_starts_with($encoded, 'v2:')
    && \SesamePortal\Crypto::decrypt($encoded) === 'legacy-management-secret'
    && !\SesamePortal\Crypto::needsRotation($encoded)
    ? 'crypto ok'
    : 'crypto failed';
PHP
)"
test "$crypto_check" = "crypto ok"

php -S "127.0.0.1:$PORT" -t "$ROOT/public" >"$SERVER_LOG" 2>&1 &
SERVER_PID="$!"
sleep 0.4

login_page="$(curl -fsS -c "$COOKIE_JAR" "http://127.0.0.1:$PORT/login")"
csrf="$(printf "%s" "$login_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$csrf"
printf "%s" "$login_page" | grep -q "/assets/brand-mark.svg"
printf "%s" "$login_page" | grep -q "/assets/favicon.svg"
curl -fsS "http://127.0.0.1:$PORT/assets/brand-mark.svg" | grep -q "SesameDVR mark"

status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$csrf" -d "login=admin" -d "password=admin123" \
    "http://127.0.0.1:$PORT/login"
)"
test "$status" = "303"

dashboard_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/dashboard")"
printf "%s" "$dashboard_page" | grep -q "SesameDVR серверы"
printf "%s" "$dashboard_page" | grep -q "technical-result"
printf "%s" "$dashboard_page" | grep -q "0.1.0"
printf "%s" "$dashboard_page" | grep -q "12.35%"
printf "%s" "$dashboard_page" | grep -q "25%"
printf "%s" "$dashboard_page" | grep -q "Management token не указан"
printf "%s" "$dashboard_page" | grep -q 'class="local-time"'
printf "%s" "$dashboard_page" | grep -q 'datetime="'
! printf "%s" "$dashboard_page" | grep -q ">Array<"
dashboard_csrf="$(printf "%s" "$dashboard_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$dashboard_csrf"
no_token_refresh="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$dashboard_csrf" -d "action=refresh_server" -d "id=2" \
    "http://127.0.0.1:$PORT/admin/dashboard"
)"
printf "%s" "$no_token_refresh" | grep -q "No Token DVR: Management token не указан"
! printf "%s" "$no_token_refresh" | grep -q "HTTP 200 https://"
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/dashboard?lang=en" | grep -q "SesameDVR servers"
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?q=admin" | grep -q "admin"
admin_groups="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/groups?edit=1")"
printf "%s" "$admin_groups" | grep -q "assignment-picker"
printf "%s" "$admin_groups" | grep -q "assignment-search"
printf "%s" "$admin_groups" | grep -q "assignment-selected-only"
printf "%s" "$admin_groups" | grep -q "Smoke Cam"
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/servers" | grep -q "technical-result"
admin_cameras="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?q=Read")"
printf "%s" "$admin_cameras" | grep -q "read_only"
printf "%s" "$admin_cameras" | grep -q "Read-only mode"
printf "%s" "$admin_cameras" | grep -q "technical-result"
printf "%s" "$admin_cameras" | grep -q "table-cameras"
printf "%s" "$admin_cameras" | grep -q "table-wrap"
! printf "%s" "$admin_cameras" | grep -q 'class="crumb"'
printf "%s" "$admin_cameras" | grep -q "camera-position-map"
printf "%s" "$admin_cameras" | grep -q "camera-direction"
audit_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/audit?q=camera&action=camera.save&actor=1")"
printf "%s" "$audit_page" | grep -q "camera_id"
printf "%s" "$audit_page" | grep -q "table-audit"
printf "%s" "$audit_page" | grep -q "audit-action"
printf "%s" "$audit_page" | grep -q 'class="local-time"'
mosaic_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/")"
printf "%s" "$mosaic_page" | grep -q "/viewer/player"
printf "%s" "$mosaic_page" | grep -q "data-preview-refresh-ms"
printf "%s" "$mosaic_page" | grep -q 'data-preview-src='
printf "%s" "$mosaic_page" | grep -q 'class="preview is-loading"'
printf "%s" "$mosaic_page" | grep -q 'decoding="async" hidden'
! printf "%s" "$mosaic_page" | grep -E -q '<img src="[^"]*preview\.jpg'
printf "%s" "$mosaic_page" | grep -q "group-filter"
printf "%s" "$mosaic_page" | grep -q "Smoke Group"
printf "%s" "$mosaic_page" | grep -q 'name="filter"'
! printf "%s" "$mosaic_page" | grep -q "group_q"
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?filter=group:1" | grep -q "Smoke Cam"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "aspect-ratio: 16 / 9"
map_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/map")"
printf "%s" "$map_page" | grep -q "preview.jpg"
printf "%s" "$map_page" | grep -q "leaflet.markercluster"
printf "%s" "$map_page" | grep -q "direction"
printf "%s" "$map_page" | grep -q "viewAngle"
printf "%s" "$map_page" | grep -q "window.SESAME_CSRF"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "camera-view-cone"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "markerClusterGroup"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "camera-cluster"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "setPlainLeafletAttribution"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "map-popup-actions"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "/favorite/toggle"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "new Image"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "previewLoading"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "is-loading"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "initLocalTimes"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "preview-spin"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "local-time"
player_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/player?id=1")"
printf "%s" "$player_page" | grep -q "back_url="
printf "%s" "$player_page" | grep -q "back_label="
! printf "%s" "$player_page" | grep -q "back-link"
! printf "%s" "$player_page" | grep -q "player-fullscreen"
! printf "%s" "$player_page" | grep -q "player-edge-swipe"
! printf "%s" "$player_page" | grep -q 'class="topbar"'

denied="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=bad&camera=missing"
)"
test "$denied" = "403"

qs="$(TOKEN="$TOKEN" php -r 'echo rawurlencode("token=" . getenv("TOKEN"));')"
allowed="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=NonAvailable&qs=$qs&name=smoke-cam"
)"
test "$allowed" = "200"

echo "http smoke ok"
