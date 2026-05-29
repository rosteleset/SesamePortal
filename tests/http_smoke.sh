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

OLD_UNIQUE_STATE="$STATE_DIR/old-unique"
mkdir -p "$OLD_UNIQUE_STATE"
sqlite_duplicate_group_migration="$(
  SESAME_PORTAL_STATE_DIR="$OLD_UNIQUE_STATE" SESAME_PORTAL_SECRET="test-secret" php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$pdo = \SesamePortal\DB::pdo();
$now = \SesamePortal\Util::now();
$pdo->exec('CREATE TABLE portal_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_group_id INTEGER REFERENCES portal_groups(id) ON DELETE SET NULL,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT "",
    blocked INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
)');
$pdo->prepare('INSERT INTO portal_groups(name, description, blocked, created_at) VALUES(?, ?, ?, ?)')
    ->execute(['Duplicate Group Name', 'before migration', 0, $now]);
\SesamePortal\DB::migrate();
$pdo->prepare('INSERT INTO portal_groups(name, description, blocked, created_at) VALUES(?, ?, ?, ?)')
    ->execute(['Duplicate Group Name', 'after migration', 0, $now]);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM portal_groups WHERE name = ?');
$stmt->execute(['Duplicate Group Name']);
echo (string)$stmt->fetchColumn();
PHP
)"
test "$sqlite_duplicate_group_migration" = "2"

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
    'streams' => ['streams' => [['name' => 'smoke-cam'], ['name' => 'readonly-cam'], ['name' => 'extra-cam-1', 'running' => false]]],
    'fetchedAt' => $now,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, last_check_result, last_metrics_at, last_metrics_json, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Smoke DVR', 'https://dvr.example.invalid', $legacy, 'HTTP 200 {"version":{"sourceCommit":"abcdef1234567890"}}', $now, $metrics, $now]);
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, last_check_result, created_at) VALUES(?, ?, ?, ?, ?)')
    ->execute(['No Token DVR', 'https://no-token.example.invalid', null, '', $now]);
$pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_stream_name, latitude, longitude, direction_deg, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Smoke Cam', 'rtsp://example.invalid/smoke', 1, 'manual', '1d', 'smoke-cam', 25.2048, 55.2708, 90, $now, $now]);
$pdo->exec('UPDATE cameras SET watermark_enabled = 1 WHERE id = 1');
$pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_control_mode, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Read Only Cam', '', 1, 'manual', '1d', 'read_only', 'readonly-cam', $now, $now]);
$extraCamera = $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 30; $i++) {
    $extraCamera->execute([sprintf('Smoke Extra %02d', $i), 'rtsp://example.invalid/extra-' . $i, 1, 'manual', '1d', 'extra-cam-' . $i, $now, $now]);
}
$pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_stream_name, latitude, longitude, direction_deg, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Двор Камера', 'rtsp://example.invalid/yard', 1, 'manual', '1d', 'unicode-yard-cam', 25.205, 55.271, 135, $now, $now]);
$unicodeCameraId = \SesamePortal\DB::lastInsertId('cameras');
$pdo->prepare('INSERT INTO portal_groups(name, description, blocked, created_at) VALUES(?, ?, ?, ?)')
    ->execute(['Smoke Group', 'smoke test group', 0, $now]);
$pdo->prepare('INSERT INTO portal_groups(parent_group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
    ->execute([1, 'Smoke Subgroup', 'smoke child group', 0, $now]);
$pdo->prepare('INSERT INTO portal_groups(name, description, blocked, created_at) VALUES(?, ?, ?, ?)')
    ->execute(['Moscow', 'parent city group', 0, $now]);
$pdo->prepare('INSERT INTO portal_groups(parent_group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
    ->execute([3, 'Test Group 1', 'filtered child group', 0, $now]);
$pdo->prepare('INSERT INTO camera_groups(camera_id, group_id) VALUES(?, ?)')
    ->execute([1, 1]);
$pdo->prepare('INSERT INTO camera_groups(camera_id, group_id) VALUES(?, ?)')
    ->execute([2, 2]);
$pdo->prepare('INSERT INTO camera_groups(camera_id, group_id) VALUES(?, ?)')
    ->execute([$unicodeCameraId, 1]);
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
printf "%s" "$login_page" | grep -q 'select name="lang"'
printf "%s" "$login_page" | grep -q 'DE - Deutsch'
printf "%s" "$login_page" | grep -q 'AR - العربية'
curl -fsS "http://127.0.0.1:$PORT/assets/brand-mark.svg" | grep -q "SesameDVR mark"

status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$csrf" -d "login=admin" -d "password=admin123" \
    "http://127.0.0.1:$PORT/login"
)"
test "$status" = "303"

while IFS='|' read -r locale title; do
  [[ -z "$locale" ]] && continue
  localized_viewer="$(curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" "http://127.0.0.1:$PORT/?lang=$locale")"
  printf "%s" "$localized_viewer" | grep -F -q "<h1>$title</h1>"
  ! printf "%s" "$localized_viewer" | grep -F -q "<h1>Камеры</h1>"
done <<'LOCALES'
en|Cameras
de|Kameras
fr|Caméras
es|Cámaras
it|Telecamere
pt|Câmaras
bg|Камери
pl|Kamery
zh|摄像机
ja|カメラ
ko|카메라
ar|الكاميرات
hy|Տեսախցիկներ
LOCALES
curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" "http://127.0.0.1:$PORT/?lang=ru" >/dev/null

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
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/dashboard?lang=de" | grep -q "Benutzer"
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/dashboard?lang=ar" | grep -q 'dir="rtl"'
admin_users_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?q=admin&lang=ru")"
printf "%s" "$admin_users_page" | grep -q "admin"
printf "%s" "$admin_users_page" | grep -q "Статический токен"
printf "%s" "$admin_users_page" | grep -q "Выпустить статический токен"
printf "%s" "$admin_users_page" | grep -q ">нет<"
printf "%s" "$admin_users_page" | grep -F -q 'class="row-actions row-actions-icons"'
printf "%s" "$admin_users_page" | grep -F -q 'aria-label="Изменить"'
printf "%s" "$admin_users_page" | grep -F -q 'aria-label="Выпустить статический токен"'
printf "%s" "$admin_users_page" | grep -F -q 'aria-label="Удалить"'
! printf "%s" "$admin_users_page" | grep -F -q '>Удалить</button>'
admin_groups="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/groups?edit=1")"
printf "%s" "$admin_groups" | grep -q "<th>ID</th>"
printf "%s" "$admin_groups" | grep -q "<th>Родитель</th>"
printf "%s" "$admin_groups" | grep -q "<td>1</td>"
printf "%s" "$admin_groups" | grep -q "Родительская группа"
printf "%s" "$admin_groups" | grep -q "group-tree-select"
printf "%s" "$admin_groups" | grep -q 'name="parent_group_id"'
printf "%s" "$admin_groups" | grep -q "data-group-tree-select-value"
printf "%s" "$admin_groups" | grep -q "Smoke Subgroup"
printf "%s" "$admin_groups" | grep -q "assignment-picker"
printf "%s" "$admin_groups" | grep -q "assignment-search"
printf "%s" "$admin_groups" | grep -q "assignment-selected-only"
printf "%s" "$admin_groups" | grep -q "Smoke Cam"
printf "%s" "$admin_groups" | grep -F -q 'aria-label="Изменить"'
printf "%s" "$admin_groups" | grep -F -q 'aria-label="Удалить"'
admin_groups_filtered="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/groups?q=tEsT")"
printf "%s" "$admin_groups_filtered" | grep -q "Test Group 1"
printf "%s" "$admin_groups_filtered" | grep -q "Moscow"
printf "%s" "$admin_groups_filtered" | grep -q "<td>Moscow</td><td>Test Group 1</td>"
! printf "%s" "$admin_groups_filtered" | grep -q "<td>Без родителя</td><td>Test Group 1</td>"
admin_cameras_form="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?edit=1")"
printf "%s" "$admin_cameras_form" | grep -q "Изменить камеру"
printf "%s" "$admin_cameras_form" | grep -F -q 'href="/admin/cameras">Новая камера</a>'
printf "%s" "$admin_cameras_form" | grep -q "Название потока"
printf "%s" "$admin_cameras_form" | grep -q "Техническое имя потока"
printf "%s" "$admin_cameras_form" | grep -q "Показывать водяной знак"
printf "%s" "$admin_cameras_form" | grep -q "Интенсивность водяного знака"
printf "%s" "$admin_cameras_form" | grep -F -q 'pattern="[A-Za-z0-9_-]+"'
printf "%s" "$admin_cameras_form" | grep -q "group-tree-checkbox-list"
printf "%s" "$admin_cameras_form" | grep -F -q 'name="group_ids[]"'
printf "%s" "$admin_cameras_form" | grep -q "data-group-tree-toggle"
printf "%s" "$admin_cameras_form" | grep -q "Smoke Subgroup"
camera_csrf="$(printf "%s" "$admin_cameras_form" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$camera_csrf"
invalid_camera_form="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$camera_csrf" -d "action=save" -d "id=1" \
    --data-urlencode "display_name=Smoke Cam" \
    -d "dvr_control_mode=managed" \
    --data-urlencode "source_url=rtsp://example.invalid/smoke" \
    -d "server_id=1" -d "server_selection=manual" \
    --data-urlencode "dvr_stream_name=Invalid Stream, 1" \
    -d "retention_days=1d" -d "direction_deg=90" -d "view_angle_deg=60" \
    "http://127.0.0.1:$PORT/admin/cameras?edit=1"
)"
printf "%s" "$invalid_camera_form" | grep -q "Техническое имя потока может содержать"
readonly_camera_save="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$camera_csrf" -d "action=save" -d "id=2" \
    --data-urlencode "display_name=Read Only Cam" \
    -d "dvr_control_mode=read_only" \
    -d "server_id=1" -d "server_selection=manual" \
    --data-urlencode "dvr_stream_name=readonly-cam" \
    -d "retention_days=1d" -d "direction_deg=0" -d "view_angle_deg=60" \
    -d "group_ids[]=2" \
    "http://127.0.0.1:$PORT/admin/cameras?edit=2"
)"
printf "%s" "$readonly_camera_save" | grep -F -q '<div class="alert">Камера сохранена</div>'
! printf "%s" "$readonly_camera_save" | grep -F -q '<div class="alert">Read-only mode'
failed_camera_save="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$camera_csrf" -d "action=save" \
    --data-urlencode "display_name=No Sync Notice Cam" \
    -d "dvr_control_mode=managed" \
    --data-urlencode "source_url=rtsp://example.invalid/no-sync-notice" \
    -d "server_selection=manual" \
    --data-urlencode "dvr_stream_name=no-sync-notice-cam" \
    -d "retention_days=1d" -d "direction_deg=0" -d "view_angle_deg=60" \
    -d "blocked=1" \
    "http://127.0.0.1:$PORT/admin/cameras"
)"
printf "%s" "$failed_camera_save" | grep -F -q '<div class="alert">Камера сохранена, но синхронизация с DVR не выполнена</div>'
! printf "%s" "$failed_camera_save" | grep -F -q '<div class="alert">No SesameDVR server selected</div>'
admin_servers="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/servers")"
printf "%s" "$admin_servers" | grep -q "technical-result"
printf "%s" "$admin_servers" | grep -F -q 'aria-label="Изменить"'
printf "%s" "$admin_servers" | grep -F -q 'aria-label="Проверить"'
printf "%s" "$admin_servers" | grep -F -q 'aria-label="Удалить"'
admin_cameras="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?q=read")"
printf "%s" "$admin_cameras" | grep -q "read_only"
printf "%s" "$admin_cameras" | grep -q "Read-only mode"
printf "%s" "$admin_cameras" | grep -q "sync-result-dot-readonly"
printf "%s" "$admin_cameras" | grep -q "table-result"
! printf "%s" "$admin_cameras" | grep -q "technical-result"
printf "%s" "$admin_cameras" | grep -q "table-cameras"
printf "%s" "$admin_cameras" | grep -q "table-wrap"
printf "%s" "$admin_cameras" | grep -F -q 'aria-label="Изменить"'
printf "%s" "$admin_cameras" | grep -F -q 'aria-label="Синхронизировать"'
printf "%s" "$admin_cameras" | grep -F -q 'aria-label="Удалить"'
! printf "%s" "$admin_cameras" | grep -F -q '>Синхронизировать</button>'
! printf "%s" "$admin_cameras" | grep -q 'class="crumb"'
admin_cameras_paged="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?q=smoke&page=2")"
printf "%s" "$admin_cameras_paged" | grep -F -q 'href="/admin/cameras?q=smoke&amp;page=2&amp;edit='
printf "%s" "$admin_cameras_paged" | grep -F -q 'href="/admin/cameras?q=smoke&amp;page=2&amp;delete='
printf "%s" "$admin_cameras_paged" | grep -F -q 'action="/admin/cameras?q=smoke&amp;page=2"'
admin_cameras_paged_form="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?q=smoke&page=2&edit=1")"
printf "%s" "$admin_cameras_paged_form" | grep -F -q 'href="/admin/cameras?q=smoke&amp;page=2">Новая камера</a>'
printf "%s" "$admin_cameras_paged_form" | grep -q 'value="smoke"'
printf "%s" "$admin_cameras_paged_form" | grep -F -q 'class="active" href="/admin/cameras?q=smoke&amp;page=2"'
printf "%s" "$admin_cameras" | grep -q "camera-position-map"
printf "%s" "$admin_cameras" | grep -q "camera-direction"
audit_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/audit?q=camera&action=camera.save&actor=1")"
printf "%s" "$audit_page" | grep -q "camera_id"
printf "%s" "$audit_page" | grep -q "table-audit"
printf "%s" "$audit_page" | grep -q "audit-action"
printf "%s" "$audit_page" | grep -q 'class="local-time"'
login_audit_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/audit?q=auth.login&action=auth.login&actor=1")"
printf "%s" "$login_audit_page" | grep -q "auth.login"
printf "%s" "$login_audit_page" | grep -q "login=admin"
printf "%s" "$login_audit_page" | grep -q "ip=127.0.0.1"
mosaic_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/")"
printf "%s" "$mosaic_page" | grep -q "/viewer/player"
printf "%s" "$mosaic_page" | grep -q "data-preview-refresh-ms"
printf "%s" "$mosaic_page" | grep -q 'name="refresh"'
printf "%s" "$mosaic_page" | grep -q 'value="off"'
printf "%s" "$mosaic_page" | grep -q "preview-refresh-control"
printf "%s" "$mosaic_page" | grep -q 'data-preview-src='
printf "%s" "$mosaic_page" | grep -q 'data-preview-src="/viewer/preview?id='
! printf "%s" "$mosaic_page" | grep -E -q 'data-preview-src="[^"]*token='
printf "%s" "$mosaic_page" | grep -q 'class="preview is-loading"'
printf "%s" "$mosaic_page" | grep -q "stream-unavailable"
printf "%s" "$mosaic_page" | grep -q 'decoding="async" hidden'
! printf "%s" "$mosaic_page" | grep -E -q '<img src="[^"]*preview\.jpg'
preview_headers="$(curl -sS -D - -o /dev/null -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/preview?id=1&_=smoke")"
printf "%s" "$preview_headers" | grep -E -q '^HTTP/[0-9.]+ 302'
printf "%s" "$preview_headers" | grep -F -q "Location: https://dvr.example.invalid/smoke-cam/preview.jpg?token="
printf "%s" "$preview_headers" | grep -F -q "_=smoke"
printf "%s" "$preview_headers" | grep -F -q "Cache-Control: no-store"
printf "%s" "$mosaic_page" | grep -q "group-filter"
printf "%s" "$mosaic_page" | grep -q "group-tree-picker"
printf "%s" "$mosaic_page" | grep -q "data-group-tree-toggle"
printf "%s" "$mosaic_page" | grep -q 'data-group-tree-children hidden'
printf "%s" "$mosaic_page" | grep -q "Smoke Group"
printf "%s" "$mosaic_page" | grep -q "Smoke Subgroup"
printf "%s" "$mosaic_page" | grep -q 'name="filter"'
printf "%s" "$mosaic_page" | grep -q 'name="q"'
printf "%s" "$mosaic_page" | grep -q "camera-search-input"
printf "%s" "$mosaic_page" | grep -q "density-switch"
printf "%s" "$mosaic_page" | grep -q "camera-grid cols-3"
printf "%s" "$mosaic_page" | grep -q "Показано 1-6"
printf "%s" "$mosaic_page" | grep -q 'data-cols="6"'
printf "%s" "$mosaic_page" | grep -q "cols=6"
printf "%s" "$mosaic_page" | grep -q "pager"
! printf "%s" "$mosaic_page" | grep -q "group_q"
search_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?q=smoke%20extra%2030&cols=5")"
printf "%s" "$search_page" | grep -q "camera-grid cols-5"
printf "%s" "$search_page" | grep -q "Smoke Extra 30"
! printf "%s" "$search_page" | grep -q "Smoke Extra 29"
printf "%s" "$search_page" | grep -q 'value="smoke extra 30"'
printf "%s" "$search_page" | grep -F -q "q=smoke+extra+30"
unicode_search_page="$(
  curl -G -fsS -b "$COOKIE_JAR" \
    --data-urlencode "q=дВоР" \
    --data-urlencode "cols=5" \
    "http://127.0.0.1:$PORT/"
)"
printf "%s" "$unicode_search_page" | grep -q "Двор Камера"
! printf "%s" "$unicode_search_page" | grep -q "Smoke Extra 30"
stream_search_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?q=UNICODE-YARD-CAM")"
printf "%s" "$stream_search_page" | grep -q "Двор Камера"
cols_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?cols=2&page=2")"
printf "%s" "$cols_page" | grep -q "camera-grid cols-2"
printf "%s" "$cols_page" | grep -q "Показано 5-8"
printf "%s" "$cols_page" | grep -q "Smoke Extra 03"
! printf "%s" "$cols_page" | grep -q "Smoke Extra 07"
printf "%s" "$cols_page" | grep -q 'class="active" href="/?page=2&amp;cols=2"'
cols4_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?cols=4")"
printf "%s" "$cols4_page" | grep -q "Показано 1-12"
cols5_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?cols=5")"
printf "%s" "$cols5_page" | grep -q "Показано 1-15"
cols6_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?cols=6")"
printf "%s" "$cols6_page" | grep -q "Показано 1-18"
refresh_off_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?refresh=off")"
printf "%s" "$refresh_off_page" | grep -q 'data-preview-refresh="off"'
! printf "%s" "$refresh_off_page" | grep -q "data-preview-refresh-ms"
group_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?filter=group:1")"
printf "%s" "$group_page" | grep -q "Smoke Cam"
printf "%s" "$group_page" | grep -q "Read Only Cam"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "aspect-ratio: 16 / 9"
map_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/map")"
printf "%s" "$map_page" | grep -q '"/viewer/preview?id=1"'
! printf "%s" "$map_page" | grep -E -q '"preview":"[^"]*token='
printf "%s" "$map_page" | grep -q "leaflet.markercluster"
printf "%s" "$map_page" | grep -q "direction"
printf "%s" "$map_page" | grep -q "viewAngle"
printf "%s" "$map_page" | grep -q "window.SESAME_CSRF"
map_search_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/map?q=smoke%20cam")"
printf "%s" "$map_search_page" | grep -q "Smoke Cam"
! printf "%s" "$map_search_page" | grep -q "Read Only Cam"
map_unicode_search_page="$(
  curl -G -fsS -b "$COOKIE_JAR" \
    --data-urlencode "q=дВоР" \
    "http://127.0.0.1:$PORT/viewer/map"
)"
printf "%s" "$map_unicode_search_page" | grep -q "Двор Камера"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "camera-view-cone"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "markerHitSize"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "markerClusterGroup"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "camera-cluster"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "camera-marker-icon"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "setPlainLeafletAttribution"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "map-popup-actions"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "/favorite/toggle"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "new Image"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "previewLoading"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "is-loading"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "initDensitySwitch"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "updateViewerLinks"
curl -fsS "http://127.0.0.1:$PORT/assets/app.js" | grep -q "initLocalTimes"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "preview-spin"
curl -fsS "http://127.0.0.1:$PORT/assets/styles.css" | grep -q "local-time"
player_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/player?id=1")"
printf "%s" "$player_page" | grep -q "back_url="
printf "%s" "$player_page" | grep -q "back_label="
printf "%s" "$player_page" | grep -q "player-watermark"
printf "%s" "$player_page" | grep -q -- "--player-watermark-alpha:0.16"
printf "%s" "$player_page" | grep -q ">admin<"
! printf "%s" "$player_page" | grep -q "back-link"
! printf "%s" "$player_page" | grep -q "player-fullscreen"
! printf "%s" "$player_page" | grep -q "player-edge-swipe"
! printf "%s" "$player_page" | grep -q 'class="topbar"'

api_unauth="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/portal/v1/me"
)"
test "$api_unauth" = "401"
curl -fsS "http://127.0.0.1:$PORT/api/portal/v1" | grep -q '"name": "SesamePortal API"'
api_me="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/me")"
printf "%s" "$api_me" | grep -q '"login": "admin"'
printf "%s" "$api_me" | grep -q '"groupIds"'
api_dashboard="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/dashboard")"
printf "%s" "$api_dashboard" | grep -q '"counts"'
printf "%s" "$api_dashboard" | grep -q '"lastMetrics"'
api_cameras="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?pageSize=3")"
printf "%s" "$api_cameras" | grep -q '"pageSize": 3'
printf "%s" "$api_cameras" | grep -q '"Smoke Cam"'
printf "%s" "$api_cameras" | grep -q '"watermarkEnabled": true'
printf "%s" "$api_cameras" | grep -q '"watermarkIntensity": 16'
api_cameras_search="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?q=read")"
printf "%s" "$api_cameras_search" | grep -q '"Read Only Cam"'
api_cameras_unicode_search="$(
  curl -G -fsS -b "$COOKIE_JAR" \
    --data-urlencode "q=дВоР" \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
printf "%s" "$api_cameras_unicode_search" | grep -q '"Двор Камера"'
api_cameras_stream_search="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?q=UNICODE-YARD-CAM")"
printf "%s" "$api_cameras_stream_search" | grep -q '"dvrStreamName": "unicode-yard-cam"'
api_display_camera="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Display Smoke Cam","sourceUrl":"rtsp://example.invalid/display","serverId":1,"dvrStreamName":"display-smoke-cam","groupIds":[1,2],"skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
printf "%s" "$api_display_camera" | grep -q '"name": "Display Smoke Cam"'
printf "%s" "$api_display_camera" | grep -q '"displayName": "Display Smoke Cam"'
printf "%s" "$api_display_camera" | grep -q '"dvrStreamName": "display-smoke-cam"'
display_camera_groups="$(printf "%s" "$api_display_camera" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo implode(",", $d["camera"]["groupIds"] ?? []);')"
test "$display_camera_groups" = "1,2"
api_generated_camera="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Домофон. г. Сухум, ул. Киараз 9, п1","sourceUrl":"rtsp://example.invalid/generated","serverId":1,"skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
printf "%s" "$api_generated_camera" | grep -q '"dvrStreamName": "domofon-g-sukhum-ul-kiaraz-9-p1"'
api_invalid_stream_status="$(
  curl -sS -o "$STATE_DIR/api_invalid_stream.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Bad Stream","sourceUrl":"rtsp://example.invalid/bad","serverId":1,"dvrStreamName":"Bad Stream, 1","skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
test "$api_invalid_stream_status" = "422"
grep -q '"code": "invalid_stream_name"' "$STATE_DIR/api_invalid_stream.json"
api_invalid_camera_group_status="$(
  curl -sS -o "$STATE_DIR/api_invalid_camera_group.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Bad Group Cam","sourceUrl":"rtsp://example.invalid/bad-group","serverId":1,"dvrStreamName":"bad-group-cam","groupIds":[99999],"skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
test "$api_invalid_camera_group_status" = "422"
grep -q '"field": "groupIds"' "$STATE_DIR/api_invalid_camera_group.json"
bad_group_cam_count="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$stmt = \SesamePortal\DB::pdo()->prepare('SELECT COUNT(*) FROM cameras WHERE dvr_stream_name = ?');
$stmt->execute(['bad-group-cam']);
echo (string)$stmt->fetchColumn();
PHP
)"
test "$bad_group_cam_count" = "0"
api_technical_camera="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"sourceUrl":"rtsp://example.invalid/technical","serverId":1,"dvrStreamName":"technical-only-cam","skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
printf "%s" "$api_technical_camera" | grep -q '"name": "technical-only-cam"'
printf "%s" "$api_technical_camera" | grep -q '"displayName": "technical-only-cam"'
api_accessible="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?scope=accessible&filter=group:1")"
printf "%s" "$api_accessible" | grep -q '"Smoke Cam"'
printf "%s" "$api_accessible" | grep -q '"Read Only Cam"'
api_group="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/1")"
printf "%s" "$api_group" | grep -q '"id": 1'
printf "%s" "$api_group" | grep -q '"parentGroupId": null'
printf "%s" "$api_group" | grep -q '"childGroupIds"'
printf "%s" "$api_group" | grep -q '"Smoke Subgroup"'
printf "%s" "$api_group" | grep -q '"userIds"'
printf "%s" "$api_group" | grep -q '"cameraIds"'
api_groups_search="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups?q=sMoKe%20sUb")"
printf "%s" "$api_groups_search" | grep -q '"Smoke Subgroup"'
api_group_children="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/1/children")"
printf "%s" "$api_group_children" | grep -q '"Smoke Subgroup"'
printf "%s" "$api_group_children" | grep -q '"childGroupIds"'
api_created_group="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"name":"API Smoke Group","description":"api","userIds":[1],"cameraIds":[1]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups"
)"
api_group_id="$(printf "%s" "$api_created_group" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["group"]["id"] ?? "";')"
test -n "$api_group_id"
api_duplicate_name_group="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"name":"API Smoke Group","description":"api duplicate name","userIds":[1],"cameraIds":[2]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups"
)"
api_duplicate_name_group_id="$(printf "%s" "$api_duplicate_name_group" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["group"]["id"] ?? "";')"
test -n "$api_duplicate_name_group_id"
test "$api_duplicate_name_group_id" != "$api_group_id"
printf "%s" "$api_duplicate_name_group" | grep -q '"name": "API Smoke Group"'
printf "%s" "$api_duplicate_name_group" | grep -q '"api duplicate name"'
duplicate_name_count="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$stmt = \SesamePortal\DB::pdo()->prepare('SELECT COUNT(*) FROM portal_groups WHERE name = ?');
$stmt->execute(['API Smoke Group']);
echo (string)$stmt->fetchColumn();
PHP
)"
test "$duplicate_name_count" = "2"
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_duplicate_name_group_id/cameras" | grep -q '"Read Only Cam"'
api_invalid_group_camera_status="$(
  curl -sS -o "$STATE_DIR/api_invalid_group_camera.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"name":"API Invalid Camera Link Group","cameraIds":[99999]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups"
)"
test "$api_invalid_group_camera_status" = "422"
grep -q '"field": "cameraIds"' "$STATE_DIR/api_invalid_group_camera.json"
invalid_group_count="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$stmt = \SesamePortal\DB::pdo()->prepare('SELECT COUNT(*) FROM portal_groups WHERE name = ?');
$stmt->execute(['API Invalid Camera Link Group']);
echo (string)$stmt->fetchColumn();
PHP
)"
test "$invalid_group_count" = "0"
api_child_group="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"name":"API Smoke Subgroup","description":"api child"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id/children"
)"
api_child_parent="$(printf "%s" "$api_child_group" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["group"]["parentGroupId"] ?? "";')"
test "$api_child_parent" = "$api_group_id"
api_child_group_id="$(printf "%s" "$api_child_group" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["group"]["id"] ?? "";')"
test -n "$api_child_group_id"
api_explicit_group="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"id":9001,"name":"API Explicit Group","description":"explicit id"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups"
)"
printf "%s" "$api_explicit_group" | grep -q '"id": 9001'
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/9001" | grep -q '"API Explicit Group"'
api_explicit_child="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"id":"9002","name":"API Explicit Subgroup","description":"explicit child id"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups/9001/children"
)"
printf "%s" "$api_explicit_child" | grep -q '"id": 9002'
printf "%s" "$api_explicit_child" | grep -q '"parentGroupId": 9001'
duplicate_group_id_status="$(
  curl -sS -o "$STATE_DIR/api_duplicate_group_id.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"id":9001,"name":"API Duplicate Explicit Group"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups"
)"
test "$duplicate_group_id_status" = "409"
grep -q '"code": "group_id_exists"' "$STATE_DIR/api_duplicate_group_id.json"
invalid_group_id_status="$(
  curl -sS -o "$STATE_DIR/api_invalid_group_id.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"id":"bad","name":"API Invalid Explicit Group"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups"
)"
test "$invalid_group_id_status" = "422"
grep -q '"code": "validation_failed"' "$STATE_DIR/api_invalid_group_id.json"
group_id_change_status="$(
  curl -sS -o "$STATE_DIR/api_group_id_change.json" -w '%{http_code}' -b "$COOKIE_JAR" -X PATCH -H 'Content-Type: application/json' \
    -d '{"id":9003,"name":"API Explicit Group Renamed"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups/9001"
)"
test "$group_id_change_status" = "422"
grep -q '"id cannot be changed"' "$STATE_DIR/api_group_id_change.json"
cycle_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -X PATCH -H 'Content-Type: application/json' \
    -d "{\"parentGroupId\":$api_child_group_id}" \
    "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id"
)"
test "$cycle_status" = "422"
api_group_users="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id/users")"
printf "%s" "$api_group_users" | grep -q '"userIds"'
printf "%s" "$api_group_users" | grep -q '"login": "admin"'
api_group_cameras="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id/cameras")"
printf "%s" "$api_group_cameras" | grep -q '"cameraIds"'
printf "%s" "$api_group_cameras" | grep -q '"Smoke Cam"'
api_group_users_empty="$(
  curl -fsS -b "$COOKIE_JAR" -X PUT -H 'Content-Type: application/json' \
    -d '{"userIds":[]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id/users"
)"
printf "%s" "$api_group_users_empty" | grep -q '"userIds": \[\]'
api_group_users_added="$(
  curl -fsS -b "$COOKIE_JAR" -X POST -H 'Content-Type: application/json' \
    -d '{"userIds":[1]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id/users"
)"
printf "%s" "$api_group_users_added" | grep -q '"login": "admin"'
api_group_cameras_empty="$(
  curl -fsS -b "$COOKIE_JAR" -X DELETE -H 'Content-Type: application/json' \
    -d '{"cameraIds":[1]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id/cameras"
)"
printf "%s" "$api_group_cameras_empty" | grep -q '"cameraIds": \[\]'
curl -fsS -b "$COOKIE_JAR" -X PUT -H 'Content-Type: application/json' \
  -d '{"cameraIds":[1]}' \
  "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id/cameras" | grep -q '"Smoke Cam"'
api_patched_group="$(
  curl -fsS -b "$COOKIE_JAR" -X PATCH -H 'Content-Type: application/json' \
    -d '{"description":"api patched","cameraIds":[1]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id"
)"
printf "%s" "$api_patched_group" | grep -q "api patched"
curl -fsS -b "$COOKIE_JAR" -X DELETE "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_group_id" | grep -q '"ok": true'
api_static="$(
  curl -fsS -b "$COOKIE_JAR" -X POST \
    "http://127.0.0.1:$PORT/api/portal/v1/users/1/static-token"
)"
STATIC_TOKEN="$(printf "%s" "$api_static" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["token"] ?? "";')"
test -n "$STATIC_TOKEN"
curl -fsS -H "Authorization: Bearer $STATIC_TOKEN" "http://127.0.0.1:$PORT/api/portal/v1/me" | grep -q '"login": "admin"'
api_daily_denied="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $TOKEN" \
    "http://127.0.0.1:$PORT/api/portal/v1/me"
)"
test "$api_daily_denied" = "401"
admin_users_static="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?q=admin&lang=ru")"
printf "%s" "$admin_users_static" | grep -q "Заменить статический токен"
printf "%s" "$admin_users_static" | grep -q "Старый статический токен сразу перестанет работать"
printf "%s" "$admin_users_static" | grep -q ">есть<"
curl -fsS -H "X-Portal-Token: $STATIC_TOKEN" "http://127.0.0.1:$PORT/api/portal/v1/cameras?scope=accessible&pageSize=1" | grep -q '"pageSize": 1'
curl -fsS -b "$COOKIE_JAR" -X PUT "http://127.0.0.1:$PORT/api/portal/v1/favorites/1" | grep -q '"favorite": true'
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/favorites" | grep -q '"cameraIds"'
curl -fsS -b "$COOKIE_JAR" -X DELETE "http://127.0.0.1:$PORT/api/portal/v1/favorites/1" | grep -q '"favorite": false'
curl -fsS -b "$COOKIE_JAR" -X DELETE "http://127.0.0.1:$PORT/api/portal/v1/users/1/static-token" | grep -q '"ok": true'
admin_users_revoked="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?q=admin&lang=ru")"
printf "%s" "$admin_users_revoked" | grep -q "Выпустить статический токен"
printf "%s" "$admin_users_revoked" | grep -q ">нет<"

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
archive_uri="$(TOKEN="$TOKEN" php -r 'echo rawurlencode("/smoke-cam/archive-1700000000-60.mp4?token=" . getenv("TOKEN"));')"
archive_allowed="$(
  curl -sS -o /dev/null -w '%{http_code}' -H 'X-Forwarded-For: 203.0.113.9' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?uri=$archive_uri"
)"
test "$archive_allowed" = "200"
archive_audit_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/audit?q=archive.download&action=archive.download&actor=1")"
printf "%s" "$archive_audit_page" | grep -q "archive.download"
printf "%s" "$archive_audit_page" | grep -q "camera_id=1"
printf "%s" "$archive_audit_page" | grep -q "from=1700000000"
printf "%s" "$archive_audit_page" | grep -q "duration=60"
printf "%s" "$archive_audit_page" | grep -q "ip=203.0.113.9"

echo "http smoke ok"
