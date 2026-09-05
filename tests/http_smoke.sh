#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_DIR="$(mktemp -d)"
PORT="${SESAME_PORTAL_TEST_PORT:-18089}"
DVR_PORT="${SESAME_PORTAL_TEST_DVR_PORT:-$((PORT + 1))}"
COOKIE_JAR="$STATE_DIR/cookies.txt"
SERVER_LOG="$STATE_DIR/server.log"
DVR_SERVER_LOG="$STATE_DIR/dvr-server.log"

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  if [[ -n "${DVR_SERVER_PID:-}" ]]; then
    kill "$DVR_SERVER_PID" 2>/dev/null || true
    wait "$DVR_SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$STATE_DIR"
}
trap cleanup EXIT

export SESAME_PORTAL_STATE_DIR="$STATE_DIR"
export SESAME_PORTAL_SECRET="test-secret"
export SESAME_PORTAL_UPDATE_AUTO_CHECK=0
export ROOT
export DVR_PORT

cat >"$STATE_DIR/config.php" <<'PHP'
<?php
return [
    'hidden_archive_player_overlays' => [[
        'type' => 'imageLink',
        'href' => 'https://apsny.camera',
        'imageUrl' => 'https://apsny.camera/player/logo.png',
        'target' => '_blank',
        'alt' => 'Apsny Camera',
        'position' => [
            'top' => '0%',
            'right' => '10%',
            'width' => '20%',
            'maxWidth' => '500px',
        ],
    ]],
    'db_dsn' => getenv('SESAME_PORTAL_DB_DSN') ?: null,
    'db_user' => getenv('SESAME_PORTAL_DB_USER') ?: null,
    'db_password' => getenv('SESAME_PORTAL_DB_PASSWORD') ?: null,
];
PHP

if [[ -z "${SESAME_PORTAL_DB_DSN:-}" ]]; then
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
fi

php "$ROOT/bin/portal" migrate >/dev/null
php "$ROOT/bin/portal" create-admin admin admin123 >/dev/null

php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';

$class = new ReflectionClass(\SesamePortal\I18n::class);
$locales = $class->getConstant('LOCALES');
$method = $class->getMethod('messages');
$method->setAccessible(true);
$messages = $method->invoke(null, false);
$baseKeys = array_keys($messages['en'] ?? []);
$failed = false;

foreach (array_keys($locales) as $locale) {
    $items = $messages[$locale] ?? [];
    $missing = array_values(array_diff($baseKeys, array_keys($items)));
    $extra = array_values(array_diff(array_keys($items), $baseKeys));
    if ($missing !== [] || $extra !== []) {
        $failed = true;
        fwrite(
            STDERR,
            sprintf(
                "Locale %s is not in sync: missing=%s extra=%s\n",
                $locale,
                $missing === [] ? '-' : implode(',', $missing),
                $extra === [] ? '-' : implode(',', $extra)
            )
        );
    }
}

if ($failed) {
    exit(1);
}
PHP

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
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, last_check_result, created_at) VALUES(?, ?, ?, ?, ?)')
    ->execute(['Import DVR', 'http://127.0.0.1:' . getenv('DVR_PORT'), \SesamePortal\Crypto::encrypt('import-management-secret'), '', $now]);
$pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_stream_name, latitude, longitude, direction_deg, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Smoke Cam', 'rtsp://192.0.2.77/smoke', 1, 'manual', '1d', 'smoke-cam', 25.2048, 55.2708, 90, $now, $now]);
$pdo->exec('UPDATE cameras SET watermark_enabled = 1 WHERE id = 1');
$pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_control_mode, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Read Only Cam', '', 1, 'manual', '1d', 'read_only', 'readonly-cam', $now, $now]);
$pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, retention_days, dvr_control_mode, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['ZZ Already in Portal', '', 3, 'manual', '1d', 'read_only', 'already-portal', $now, $now]);
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
$pdo->prepare('INSERT INTO group_folders(group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
    ->execute([1, 'Smoke Folder', 'smoke test folder', 0, $now]);
$smokeFolder1 = \SesamePortal\DB::lastInsertId('group_folders');
$pdo->prepare('INSERT INTO group_folders(group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
    ->execute([2, 'Subgroup Folder', 'smoke child folder', 0, $now]);
$smokeFolder2 = \SesamePortal\DB::lastInsertId('group_folders');
$pdo->prepare('INSERT INTO camera_folders(camera_id, folder_id) VALUES(?, ?)')
    ->execute([1, $smokeFolder1]);
$pdo->prepare('INSERT INTO camera_folders(camera_id, folder_id) VALUES(?, ?)')
    ->execute([2, $smokeFolder2]);
$pdo->prepare('INSERT INTO camera_folders(camera_id, folder_id) VALUES(?, ?)')
    ->execute([$unicodeCameraId, $smokeFolder1]);
$pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, static_token_hash, created_at) VALUES(?, ?, ?, ?, ?, ?)')
    ->execute(['plain-user', password_hash('user123', PASSWORD_DEFAULT), 'user', 0, password_hash('sp_smoke_user_token', PASSWORD_DEFAULT), $now]);
$plainUserId = \SesamePortal\DB::lastInsertId('users');
$pdo->prepare('UPDATE users SET hide_archive = 1 WHERE id = ?')
    ->execute([$plainUserId]);
$pdo->prepare('UPDATE users SET mosaic_enabled = 1 WHERE id = ?')
    ->execute([$plainUserId]);
$pdo->prepare('INSERT INTO user_folders(user_id, folder_id) VALUES(?, ?)')
    ->execute([$plainUserId, $smokeFolder1]);
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

php -S "127.0.0.1:$DVR_PORT" "$ROOT/tests/fake_dvr_router.php" >"$DVR_SERVER_LOG" 2>&1 &
DVR_SERVER_PID="$!"
php -S "127.0.0.1:$PORT" -t "$ROOT/public" "$ROOT/tests/router.php" >"$SERVER_LOG" 2>&1 &
SERVER_PID="$!"
sleep 0.4

login_page="$(curl -fsS -c "$COOKIE_JAR" "http://127.0.0.1:$PORT/login")"
csrf="$(printf "%s" "$login_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$csrf"
printf "%s" "$login_page" | grep -q "/assets/logo-sesameportal-inverse.svg"
printf "%s" "$login_page" | grep -q "/assets/favicon.svg"
printf "%s" "$login_page" | grep -q 'select name="lang"'
printf "%s" "$login_page" | grep -q 'DE - Deutsch'
printf "%s" "$login_page" | grep -q 'AR - العربية'
printf "%s" "$login_page" | grep -q 'name="remember_me"'
curl -fsS "http://127.0.0.1:$PORT/assets/brand-mark.svg" | grep -q "SesameDVR mark"

status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "login=admin" -d "password=admin123" \
    "http://127.0.0.1:$PORT/login"
)"
test "$status" = "303"

# Remember-me: login with remember_me=1, verify cookie is set
REMEMBER_JAR="$STATE_DIR/remember-cookies.txt"
remember_headers="$(curl -sS -D - -o /dev/null -c "$REMEMBER_JAR" \
  -d "login=admin&password=admin123&remember_me=1" \
  "http://127.0.0.1:$PORT/login")"
printf "%s" "$remember_headers" | grep -i "Set-Cookie.*sesame_remember"
# Access admin page with only remember-me cookie (no session)
remember_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$REMEMBER_JAR" \
    "http://127.0.0.1:$PORT/admin/dashboard"
)"
test "$remember_status" = "200"
# Logout clears remember-me cookie
logout_headers="$(curl -sS -D - -o /dev/null -b "$REMEMBER_JAR" -c "$REMEMBER_JAR" \
  "http://127.0.0.1:$PORT/logout")"
printf "%s" "$logout_headers" | grep -i "Set-Cookie.*sesame_remember"

CURRENT_SHA="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || printf 'abcdef1234567890abcdef1234567890abcdef12')"
cat > "$STATE_DIR/portal-update-status.json" <<JSON
{
  "checkedAt": "2026-06-02T00:00:00+00:00",
  "latest": {
    "version": "ffffffffffff",
    "sourceCommit": "ffffffffffffffffffffffffffffffffffffffff",
    "commitDate": "2026-06-02T00:00:00Z",
    "message": "Smoke available update"
  },
  "error": null,
  "currentForSmoke": "$CURRENT_SHA"
}
JSON

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
printf "%s" "$dashboard_page" | grep -q "portal-update-banner"
printf "%s" "$dashboard_page" | grep -q "Доступно обновление"
printf "%s" "$dashboard_page" | grep -q 'class="local-time"'
printf "%s" "$dashboard_page" | grep -q 'datetime="'
! printf "%s" "$dashboard_page" | grep -q ">Array<"
dashboard_csrf="$(printf "%s" "$dashboard_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$dashboard_csrf"
missing_csrf_refresh_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "action=refresh_server" -d "id=2" \
    "http://127.0.0.1:$PORT/admin/dashboard"
)"
test "$missing_csrf_refresh_status" = "419"
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
settings_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/settings?lang=ru")"
printf "%s" "$settings_page" | grep -q "Обновления Portal"
printf "%s" "$settings_page" | grep -q "Текущая версия"
printf "%s" "$settings_page" | grep -q "Доступная версия на GitHub"
printf "%s" "$settings_page" | grep -q "Smoke available update"
printf "%s" "$settings_page" | grep -q "Проверить обновления"
printf "%s" "$settings_page" | grep -q "Обновить Portal"
# SMTP settings panel present
printf "%s" "$settings_page" | grep -q "SMTP"
printf "%s" "$settings_page" | grep -q 'name="smtp_host"'
printf "%s" "$settings_page" | grep -q 'name="action" value="save_smtp"'
printf "%s" "$settings_page" | grep -q 'name="action" value="test_smtp"'
# Save SMTP via DB-backed settings (valid)
settings_csrf="$(printf "%s" "$settings_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$settings_csrf"
smtp_save_response="$(
  curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$settings_csrf" -d "action=save_smtp" \
    -d "smtp_host=smtp.example.com" -d "smtp_port=465" \
    -d "smtp_user=test@example.com" -d "smtp_password=secret123" \
    -d "smtp_security=ssl" -d "smtp_from_email=test@example.com" \
    -d "smtp_from_name=SesamePortal" \
    "http://127.0.0.1:$PORT/admin/settings"
)"
# SMTP saved message shown
printf "%s" "$smtp_save_response" | grep -q "SMTP-конфигурация сохранена"
# Persisted in DB
php -r 'require getenv("ROOT")."/app/Portal.php"; echo \SesamePortal\DB::setting("smtp_host","");' | grep -q "smtp.example.com"
# Invalid port rejected
smtp_bad_response="$(
  curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$settings_csrf" -d "action=save_smtp" \
    -d "smtp_host=smtp.example.com" -d "smtp_port=99999" \
    "http://127.0.0.1:$PORT/admin/settings"
)"
printf "%s" "$smtp_bad_response" | grep -q "Некорректные параметры SMTP"
# Map provider saved to DB
map_save_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$settings_csrf" -d "action=save_map_provider" -d "map_provider=yandex" \
    "http://127.0.0.1:$PORT/admin/settings"
)"
test "$map_save_status" = "200"
php -r 'require getenv("ROOT")."/app/Portal.php"; echo \SesamePortal\DB::setting("map_provider","");' | grep -q "yandex"
admin_users_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?q=admin&lang=ru")"
printf "%s" "$admin_users_page" | grep -q "admin"
printf "%s" "$admin_users_page" | grep -q "Статический токен"
printf "%s" "$admin_users_page" | grep -q "Выпустить статический токен"
printf "%s" "$admin_users_page" | grep -q ">нет<"
printf "%s" "$admin_users_page" | grep -F -q 'class="row-actions row-actions-icons"'
printf "%s" "$admin_users_page" | grep -F -q 'aria-label="Изменить"'
printf "%s" "$admin_users_page" | grep -F -q 'aria-label="Выпустить статический токен"'
printf "%s" "$admin_users_page" | grep -F -q 'aria-label="Удалить"'
printf "%s" "$admin_users_page" | grep -q "group-tree-checkbox-list"
printf "%s" "$admin_users_page" | grep -F -q 'name="folder_ids_json"'
printf "%s" "$admin_users_page" | grep -F -q 'data-group-tree-check-all'
printf "%s" "$admin_users_page" | grep -F -q 'data-group-tree-clear-all'
printf "%s" "$admin_users_page" | grep -F -q 'data-submit-progress="Сохраняем пользователя...'
printf "%s" "$admin_users_page" | grep -F -q 'data-submit-status'
printf "%s" "$admin_users_page" | grep -F -q 'name="admin_comment"'
printf "%s" "$admin_users_page" | grep -F -q 'name="phone"'
printf "%s" "$admin_users_page" | grep -F -q 'name="hide_archive"'
printf "%s" "$admin_users_page" | grep -F -q 'name="mosaic_enabled"'
printf "%s" "$admin_users_page" | grep -F -q 'name="folder_id"'
printf "%s" "$admin_users_page" | grep -q "Все папки"
printf "%s" "$admin_users_page" | grep -F -q '<th>Комментарий администратора</th>'
printf "%s" "$admin_users_page" | grep -F -q '<th>Скрывать архив</th>'
! printf "%s" "$admin_users_page" | grep -F -q '>Удалить</button>'
user_csrf="$(printf "%s" "$admin_users_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$user_csrf"
user_group_save="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$user_csrf" -d "action=save" -d "id=1" \
    -d "login=admin" -d "password=" -d "role=admin" \
    --data-urlencode "admin_comment=admin-only smoke note" \
    -d "folder_ids[]=1" -d "folder_ids[]=2" \
    "http://127.0.0.1:$PORT/admin/users?q=admin"
)"
printf "%s" "$user_group_save" | grep -q "admin"
printf "%s" "$user_group_save" | grep -q "Пользователь сохранён"
printf "%s" "$user_group_save" | grep -q "admin-only smoke note"
admin_users_group_filter="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?folder_id=1")"
printf "%s" "$admin_users_group_filter" | grep -q "admin"
printf "%s" "$admin_users_group_filter" | grep -q "plain-user"
printf "%s" "$admin_users_group_filter" | grep -F -q '<option value="1" selected>'
admin_users_subgroup_filter="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?folder_id=2")"
printf "%s" "$admin_users_subgroup_filter" | grep -q "admin"
! printf "%s" "$admin_users_subgroup_filter" | grep -q "plain-user"
api_admin_users_group_filter="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/users?folderId=2&pageSize=100")"
printf "%s" "$api_admin_users_group_filter" | grep -q '"login": "admin"'
! printf "%s" "$api_admin_users_group_filter" | grep -q '"login": "plain-user"'
admin_user_edit_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?q=admin&edit=1")"
printf "%s" "$admin_user_edit_page" | php -r '$html = stream_get_contents(STDIN); $selected = strpos($html, ">Smoke Folder<"); exit($selected !== false ? 0 : 1);'
api_admin_user="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/users/1")"
printf "%s" "$api_admin_user" | php -r '$d=json_decode(stream_get_contents(STDIN), true); $ids=$d["user"]["folderIds"] ?? []; sort($ids); exit($ids === [1, 2] ? 0 : 1);'
printf "%s" "$api_admin_user" | grep -q '"adminComment": "admin-only smoke note"'
printf "%s" "$api_admin_user" | grep -q '"hideArchive": false'
api_me_no_admin_comment="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/me")"
! printf "%s" "$api_me_no_admin_comment" | grep -q '"adminComment"'
api_duplicate_user_status="$(
  curl -sS -o "$STATE_DIR/api_duplicate_user_login.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"login":"admin","password":"secret123","role":"admin"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/users"
)"
test "$api_duplicate_user_status" = "409"
grep -q '"code": "login_exists"' "$STATE_DIR/api_duplicate_user_login.json"
admin_groups="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/groups?edit=1")"
printf "%s" "$admin_groups" | grep -q "group-edit-tabs"
printf "%s" "$admin_groups" | grep -q "group-edit-head"
printf "%s" "$admin_groups" | grep -F -q 'href="/admin/groups">Назад</a>'
printf "%s" "$admin_groups" | grep -q "folder-form"
printf "%s" "$admin_groups" | grep -F -q 'name="folder_name"'
printf "%s" "$admin_groups" | grep -F -q 'name="action" value="save_folder"'
printf "%s" "$admin_groups" | grep -q "Smoke Folder"
printf "%s" "$admin_groups" | grep -F -q '>Изменить</a>'
printf "%s" "$admin_groups" | grep -F -q 'class="folder-cameras-grid"'
printf "%s" "$admin_groups" | grep -F -q 'href="/admin/cameras?edit=1&amp;back=%2Fadmin%2Fgroups%3Fedit%3D1%26tab%3D2"'
printf "%s" "$admin_groups" | grep -q "folder-action-dropdown"
printf "%s" "$admin_groups" | grep -q "folder-action-trigger"
printf "%s" "$admin_groups" | grep -q "folder-pick-camera-btn"
printf "%s" "$admin_groups" | grep -q "camera-picker-dialog"
printf "%s" "$admin_groups" | grep -q "camera-picker-search"
printf "%s" "$admin_groups" | grep -F -q 'value="add_camera_to_folder"'
printf "%s" "$admin_groups" | grep -F -q 'href="/admin/cameras?new=1&amp;back='
admin_groups_list="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/groups")"
printf "%s" "$admin_groups_list" | grep -q "<th>ID</th>"
printf "%s" "$admin_groups_list" | grep -q "<td>1</td>"
printf "%s" "$admin_groups_list" | grep -q "Smoke Subgroup"
printf "%s" "$admin_groups_list" | grep -F -q 'href="/admin/groups?delete=1"'
admin_group_delete="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/groups?delete=1")"
printf "%s" "$admin_group_delete" | grep -q "Удалить группу"
printf "%s" "$admin_group_delete" | grep -q "Smoke Group"
printf "%s" "$admin_group_delete" | grep -q 'name="confirm_delete"'
admin_groups_filtered="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/groups?q=tEsT")"
printf "%s" "$admin_groups_filtered" | grep -q "Test Group 1"
admin_cameras_form="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?edit=1")"
printf "%s" "$admin_cameras_form" | grep -q "Изменить камеру"
printf "%s" "$admin_cameras_form" | grep -F -q 'href="/admin/cameras">Новая камера</a>'
printf "%s" "$admin_cameras_form" | grep -q "Название потока"
printf "%s" "$admin_cameras_form" | grep -q "Техническое имя потока"
printf "%s" "$admin_cameras_form" | grep -F -q 'name="dvr_control_mode" data-camera-mode-select'
printf "%s" "$admin_cameras_form" | grep -F -q 'data-camera-agent-field hidden'
printf "%s" "$admin_cameras_form" | grep -q "Показывать водяной знак"
printf "%s" "$admin_cameras_form" | grep -q "Интенсивность водяного знака"
printf "%s" "$admin_cameras_form" | grep -F -q 'name="watermark_enabled" data-watermark-toggle checked'
printf "%s" "$admin_cameras_form" | grep -F -q 'data-watermark-dependent'
printf "%s" "$admin_cameras_form" | grep -q "Расположение камеры"
printf "%s" "$admin_cameras_form" | grep -F -q 'data-camera-location-options'
printf "%s" "$admin_cameras_form" | grep -q "Пишет архив"
printf "%s" "$admin_cameras_form" | grep -q "Глубина архива"
printf "%s" "$admin_cameras_form" | grep -q "Настройки потока DVR"
printf "%s" "$admin_cameras_form" | grep -F -q 'data-dvr-stream-options'
printf "%s" "$admin_cameras_form" | grep -F -q 'name="archive_enabled" data-dvr-toggle="archive" checked'
printf "%s" "$admin_cameras_form" | grep -F -q 'data-dvr-dependent="archive"'
printf "%s" "$admin_cameras_form" | grep -q "WebRTC FastStart"
printf "%s" "$admin_cameras_form" | grep -q "Сохранять архив по событиям"
printf "%s" "$admin_cameras_form" | grep -F -q 'name="event_archive_max_mb"'
printf "%s" "$admin_cameras_form" | grep -q "Лимит размера архива событий, MB"
printf "%s" "$admin_cameras_form" | grep -F -q 'data-dvr-dependent="event-archive" hidden'
printf "%s" "$admin_cameras_form" | grep -q "Писать timelapse"
printf "%s" "$admin_cameras_form" | grep -F -q 'data-dvr-dependent="timelapse" hidden'
printf "%s" "$admin_cameras_form" | grep -q "Аудиокодек"
printf "%s" "$admin_cameras_form" | grep -F -q 'pattern="[A-Za-z0-9][A-Za-z0-9._-]*"'
printf "%s" "$admin_cameras_form" | grep -q "group-tree-checkbox-list"
printf "%s" "$admin_cameras_form" | grep -F -q 'name="folder_ids[]"'
printf "%s" "$admin_cameras_form" | grep -q "data-group-tree-toggle"
printf "%s" "$admin_cameras_form" | grep -q "Smoke Subgroup"
printf "%s" "$admin_cameras_form" | php -r '$html = stream_get_contents(STDIN); $selected = strpos($html, ">Smoke Folder<"); exit($selected !== false ? 0 : 1);'
admin_cameras_back_form="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?edit=1&back=%2Fviewer%2Fplayer%3Fid%3D1%26back%3D%252F%253Fcols%253D6")"
printf "%s" "$admin_cameras_back_form" | grep -F -q 'href="/viewer/player?id=1&amp;back=%2F%3Fcols%3D6">Назад</a>'
admin_cameras_new_form="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras")"
printf "%s" "$admin_cameras_new_form" | grep -F -q 'data-watermark-dependent hidden'
printf "%s" "$admin_cameras_new_form" | grep -F -q '<option value="auto" selected>автоматический случайный</option>'
printf "%s" "$admin_cameras_new_form" | grep -F -q 'href="/admin/cameras/import">Импорт с DVR</a>'
admin_camera_import="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras/import?server_id=3")"
printf "%s" "$admin_camera_import" | grep -q "Импорт потоков с DVR"
printf "%s" "$admin_camera_import" | grep -F -q 'data-dvr-import-form'
printf "%s" "$admin_camera_import" | grep -F -q 'name="stream_names[]" value="import-cam-1"'
printf "%s" "$admin_camera_import" | grep -F -q 'name="stream_names[]" value="import-cam-2"'
! printf "%s" "$admin_camera_import" | grep -F -q 'name="stream_names[]" value="already-portal"'
printf "%s" "$admin_camera_import" | grep -q "Пропущено потоков с неподдерживаемым техническим именем: 1"
printf "%s" "$admin_camera_import" | grep -F -q 'data-dvr-import-select-all'
printf "%s" "$admin_camera_import" | grep -F -q 'data-dvr-import-clear-all'
printf "%s" "$admin_camera_import" | grep -F -q 'name="folder_ids[]"'
camera_import_csrf="$(printf "%s" "$admin_camera_import" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$camera_import_csrf"
camera_import_result="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$camera_import_csrf" -d "action=import" -d "server_id=3" \
    -d "stream_names[]=import-cam-1" -d "stream_names[]=import-cam-2" \
    -d "folder_ids[]=1" \
    "http://127.0.0.1:$PORT/admin/cameras/import?server_id=3"
)"
printf "%s" "$camera_import_result" | grep -F -q '<div class="alert">Добавлено потоков: 2</div>'
printf "%s" "$camera_import_result" | grep -q "На выбранном DVR нет потоков, отсутствующих в Portal"
imported_camera_check="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$rows = \SesamePortal\DB::pdo()->query("SELECT c.dvr_stream_name, c.name, c.dvr_control_mode, c.server_id, c.source_url, c.archive_enabled, c.retention_days, COUNT(cf.folder_id) AS folders_count FROM cameras c LEFT JOIN camera_folders cf ON cf.camera_id = c.id WHERE c.dvr_stream_name IN ('import-cam-1', 'import-cam-2') GROUP BY c.id ORDER BY c.dvr_stream_name")->fetchAll();
echo json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP
)"
printf "%s" "$imported_camera_check" | grep -F -q '"dvr_stream_name":"import-cam-1","name":"ZZ Imported Entrance","dvr_control_mode":"read_only","server_id":3,"source_url":"rtsp://example.invalid/import-1","archive_enabled":1,"retention_days":"14d","folders_count":1'
printf "%s" "$imported_camera_check" | grep -F -q '"dvr_stream_name":"import-cam-2","name":"ZZ Imported Yard","dvr_control_mode":"read_only","server_id":3,"source_url":"push://import-cam-2","archive_enabled":0,"retention_days":"3d","folders_count":1'
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
    -d "archive_enabled=1" \
    "http://127.0.0.1:$PORT/admin/cameras?edit=1"
)"
printf "%s" "$invalid_camera_form" | grep -q "Техническое имя потока должно начинаться"
readonly_camera_save="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$camera_csrf" -d "action=save" -d "id=2" \
    --data-urlencode "display_name=Read Only Cam" \
    -d "dvr_control_mode=read_only" \
    -d "server_id=1" -d "server_selection=manual" \
    --data-urlencode "dvr_stream_name=readonly-cam" \
    -d "retention_days=1d" -d "direction_deg=0" -d "view_angle_deg=60" \
    -d "archive_enabled=1" \
    -d "folder_ids[]=2" \
    "http://127.0.0.1:$PORT/admin/cameras?edit=2"
)"
printf "%s" "$readonly_camera_save" | grep -F -q '<div class="alert">Камера сохранена</div>'
! printf "%s" "$readonly_camera_save" | grep -F -q '<div class="alert">Read-only mode'
# Block the fake Import DVR server so auto server selection deterministically
# picks an unreachable example.invalid server (sync must fail).
php -r 'require getenv("ROOT")."/app/Portal.php"; \SesamePortal\DB::pdo()->exec("UPDATE dvr_servers SET blocked = 1 WHERE id = 3");'
failed_camera_save="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$camera_csrf" -d "action=save" \
    --data-urlencode "display_name=No Sync Notice Cam" \
    -d "dvr_control_mode=managed" \
    --data-urlencode "source_url=rtsp://example.invalid/no-sync-notice" \
    -d "server_selection=manual" \
    --data-urlencode "dvr_stream_name=no-sync-notice-cam" \
    -d "retention_days=1d" -d "direction_deg=0" -d "view_angle_deg=60" \
    -d "archive_enabled=1" \
    -d "event_archive_max_mb=128" \
    -d "blocked=1" \
    "http://127.0.0.1:$PORT/admin/cameras"
)"
printf "%s" "$failed_camera_save" | grep -F -q '<div class="alert">Камера сохранена, но синхронизация с DVR не выполнена</div>'
! printf "%s" "$failed_camera_save" | grep -F -q '<div class="alert">No SesameDVR server selected</div>'
blank_server_camera="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$stmt = \SesamePortal\DB::pdo()->prepare("SELECT server_selection || ':' || COALESCE(server_id, 0) || ':' || COALESCE(event_archive_max_bytes, 0) FROM cameras WHERE dvr_stream_name = ?");
$stmt->execute(['no-sync-notice-cam']);
echo (string)$stmt->fetchColumn();
PHP
)"
[[ "$blank_server_camera" =~ ^auto:[1-9][0-9]*:134217728$ ]]
admin_servers="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/servers")"
printf "%s" "$admin_servers" | grep -q "technical-result"
printf "%s" "$admin_servers" | grep -F -q 'aria-label="Изменить"'
printf "%s" "$admin_servers" | grep -F -q 'aria-label="Проверить"'
printf "%s" "$admin_servers" | grep -F -q 'aria-label="Удалить"'
admin_cameras="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?q=read")"
printf "%s" "$admin_cameras" | grep -q "read_only"
printf "%s" "$admin_cameras" | grep -q "Read-only режим"
printf "%s" "$admin_cameras" | grep -q "sync-result-dot-readonly"
printf "%s" "$admin_cameras" | grep -F -q 'class="table-search camera-admin-filters"'
printf "%s" "$admin_cameras" | grep -F -q 'name="server_id"'
printf "%s" "$admin_cameras" | grep -F -q 'name="mode"'
printf "%s" "$admin_cameras" | grep -F -q 'name="archive"'
printf "%s" "$admin_cameras" | grep -F -q 'name="sync"'
printf "%s" "$admin_cameras" | grep -F -q 'name="folder_id"'
printf "%s" "$admin_cameras" | grep -F -q 'name="sort"'
printf "%s" "$admin_cameras" | grep -F -q 'name="dir"'
printf "%s" "$admin_cameras" | grep -F -q 'href="/admin/cameras">Сбросить</a>'
printf "%s" "$admin_cameras" | grep -q "table-result"
! printf "%s" "$admin_cameras" | grep -q "technical-result"
printf "%s" "$admin_cameras" | grep -q "table-cameras"
printf "%s" "$admin_cameras" | grep -q "table-wrap"
printf "%s" "$admin_cameras" | grep -F -q 'aria-label="Изменить"'
printf "%s" "$admin_cameras" | grep -F -q 'aria-label="Синхронизировать"'
printf "%s" "$admin_cameras" | grep -F -q 'aria-label="Удалить"'
! printf "%s" "$admin_cameras" | grep -F -q '>Синхронизировать</button>'
! printf "%s" "$admin_cameras" | grep -q 'class="crumb"'
admin_cameras_filtered="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?mode=read_only&archive=on&sync=readonly&server_id=1&sort=server&dir=desc")"
printf "%s" "$admin_cameras_filtered" | grep -q "Read Only Cam"
printf "%s" "$admin_cameras_filtered" | grep -F -q '<option value="read_only" selected>'
printf "%s" "$admin_cameras_filtered" | grep -F -q '<option value="on" selected>'
printf "%s" "$admin_cameras_filtered" | grep -F -q '<option value="readonly" selected>'
printf "%s" "$admin_cameras_filtered" | grep -F -q '<option value="server" selected>'
printf "%s" "$admin_cameras_filtered" | grep -F -q '<option value="desc" selected>'
admin_cameras_group_filter="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?folder_id=1")"
printf "%s" "$admin_cameras_group_filter" | grep -q "Smoke Cam"
! printf "%s" "$admin_cameras_group_filter" | grep -q "Smoke Extra 01"
admin_cameras_sorted="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?q=Smoke%20Extra&sort=name&dir=desc")"
printf "%s" "$admin_cameras_sorted" | php -r '$html = stream_get_contents(STDIN); $first = strpos($html, "Smoke Extra 30"); $next = strpos($html, "Smoke Extra 29"); exit($first !== false && $next !== false && $first < $next ? 0 : 1);'
admin_cameras_paged="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?q=smoke&page=2")"
printf "%s" "$admin_cameras_paged" | grep -F -q 'href="/admin/cameras?q=smoke&amp;page=2&amp;edit='
printf "%s" "$admin_cameras_paged" | grep -F -q 'href="/admin/cameras?q=smoke&amp;page=2&amp;delete='
printf "%s" "$admin_cameras_paged" | grep -F -q 'action="/admin/cameras?q=smoke&amp;page=2"'
admin_cameras_filtered_paged="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/cameras?q=smoke&server_id=1&mode=managed&archive=on&sort=stream&dir=desc&page=2")"
printf "%s" "$admin_cameras_filtered_paged" | grep -F -q 'href="/admin/cameras?q=smoke&amp;server_id=1&amp;mode=managed&amp;archive=on&amp;sort=stream&amp;dir=desc&amp;page=2&amp;edit='
printf "%s" "$admin_cameras_filtered_paged" | grep -F -q 'action="/admin/cameras?q=smoke&amp;server_id=1&amp;mode=managed&amp;archive=on&amp;sort=stream&amp;dir=desc&amp;page=2"'
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
events_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/events?hours=24")"
printf "%s" "$events_page" | grep -q "event-grid"
printf "%s" "$events_page" | grep -F -q 'class="events-filter"'
printf "%s" "$events_page" | grep -F -q 'name="cameraId"'
printf "%s" "$events_page" | grep -F -q 'name="hours"'
printf "%s" "$events_page" | grep -q "event-card"
printf "%s" "$events_page" | grep -q "Движение"
printf "%s" "$events_page" | grep -F -q 'href="/viewer/player?id='
printf "%s" "$events_page" | grep -F -q 'src="/viewer/preview?id='
printf "%s" "$events_page" | grep -F -q '&ts='
events_frame_headers="$(curl -sS -D - -o /dev/null -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/preview?id=3&ts=$(date +%s)")"
printf "%s" "$events_frame_headers" | grep -q "^HTTP/[0-9.]* 200"
printf "%s" "$events_frame_headers" | grep -i "Content-Type: image/jpeg"
printf "%s" "$mosaic_page" | grep -q "group-filter"
! printf "%s" "$mosaic_page" | grep -q "group-tree-picker"
! printf "%s" "$mosaic_page" | grep -q "Smoke Group"
printf "%s" "$mosaic_page" | grep -q 'name="q"'
printf "%s" "$mosaic_page" | grep -q "camera-search-input"
printf "%s" "$mosaic_page" | grep -q "camera-search-clear"
printf "%s" "$mosaic_page" | grep -q "density-switch"
printf "%s" "$mosaic_page" | grep -q "camera-grid cols-3"
printf "%s" "$mosaic_page" | grep -q "Показано 1-6"
printf "%s" "$mosaic_page" | grep -q 'data-cols="6"'
printf "%s" "$mosaic_page" | grep -q "cols=6"
printf "%s" "$mosaic_page" | grep -q "pager"
! printf "%s" "$mosaic_page" | grep -q "group_q"
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?cols=5" >/dev/null
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/dashboard" >/dev/null
remembered_columns_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/")"
grep -q "camera-grid cols-5" <<<"$remembered_columns_page"
grep -q "Показано 1-15" <<<"$remembered_columns_page"
grep -F -q 'class="active" href="/?cols=5"' <<<"$remembered_columns_page"
search_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?q=smoke%20extra%2030&cols=5")"
printf "%s" "$search_page" | grep -q "camera-grid cols-5"
printf "%s" "$search_page" | grep -q "Smoke Extra 30"
! printf "%s" "$search_page" | grep -q "Smoke Extra 29"
printf "%s" "$search_page" | grep -q 'value="smoke extra 30"'
printf "%s" "$search_page" | grep -F -q 'class="active" href="/?cols=5">Все</a>'
printf "%s" "$search_page" | grep -F -q 'class="camera-search-clear" href="/?cols=5"'
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
printf "%s" "$mosaic_page" | grep -q "smoke-cam"
printf "%s" "$mosaic_page" | grep -q "camera-tech"
ip_search_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/?q=192.0.2.77")"
printf "%s" "$ip_search_page" | grep -q "Smoke Cam"
! printf "%s" "$ip_search_page" | grep -q "Smoke Extra 30"
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
! printf "%s" "$group_page" | grep -q "Read Only Cam"
styles_css_asset="$(curl -fsS "http://127.0.0.1:$PORT/assets/styles.css")"
grep -q "aspect-ratio: 16 / 9" <<<"$styles_css_asset"
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
map_ip_search_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/map?q=192.0.2.77")"
printf "%s" "$map_ip_search_page" | grep -q "Smoke Cam"
! printf "%s" "$map_ip_search_page" | grep -q "Двор Камера"
map_unicode_search_page="$(
  curl -G -fsS -b "$COOKIE_JAR" \
    --data-urlencode "q=дВоР" \
    "http://127.0.0.1:$PORT/viewer/map"
)"
printf "%s" "$map_unicode_search_page" | grep -q "Двор Камера"
app_js_asset="$(curl -fsS "http://127.0.0.1:$PORT/assets/app.js")"
grep -q "camera-view-cone" <<<"$app_js_asset"
grep -q "markerHitSize" <<<"$app_js_asset"
grep -q "markerClusterGroup" <<<"$app_js_asset"
grep -q "camera-cluster" <<<"$styles_css_asset"
grep -q "camera-marker-icon" <<<"$styles_css_asset"
grep -q "setPlainLeafletAttribution" <<<"$app_js_asset"
grep -q "map-popup-actions" <<<"$app_js_asset"
grep -q "/favorite/toggle" <<<"$app_js_asset"
grep -q "new Image" <<<"$app_js_asset"
grep -q "previewLoading" <<<"$app_js_asset"
grep -q "is-loading" <<<"$app_js_asset"
grep -q "initDensitySwitch" <<<"$app_js_asset"
grep -q "updateViewerLinks" <<<"$app_js_asset"
grep -q "initLocalTimes" <<<"$app_js_asset"
grep -q "preview-spin" <<<"$styles_css_asset"
grep -q "local-time" <<<"$styles_css_asset"
player_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/player?id=1")"
printf "%s" "$player_page" | grep -q "back_url="
printf "%s" "$player_page" | grep -q "back_label="
printf "%s" "$player_page" | grep -q "settings_url="
printf "%s" "$player_page" | grep -q "settings_label="
printf "%s" "$player_page" | grep -q "admin%2Fcameras%3Fedit%3D1"
printf "%s" "$player_page" | grep -q "player-watermark"
printf "%s" "$player_page" | grep -q -- "--player-watermark-alpha:0.16"
printf "%s" "$player_page" | grep -q "screenshot=false"
printf "%s" "$player_page" | grep -q ">admin<"
! printf "%s" "$player_page" | grep -q "back-link"
! printf "%s" "$player_page" | grep -q "player-fullscreen"
! printf "%s" "$player_page" | grep -q "player-edge-swipe"
! printf "%s" "$player_page" | grep -q 'class="topbar"'
PLAIN_COOKIE_JAR="$STATE_DIR/plain-cookies.txt"
curl -fsS -c "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/login" >/dev/null
plain_login_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$PLAIN_COOKIE_JAR" -c "$PLAIN_COOKIE_JAR" \
    -d "login=plain-user" -d "password=user123" \
    "http://127.0.0.1:$PORT/login"
)"
test "$plain_login_status" = "303"
plain_mosaic_page="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/")"
grep -q "camera-grid cols-3" <<<"$plain_mosaic_page"
grep -F -q 'class="active" href="/?cols=3"' <<<"$plain_mosaic_page"
! printf "%s" "$plain_mosaic_page" | grep -q "camera-tech"
plain_player_page="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/viewer/player?id=1")"
! printf "%s" "$plain_player_page" | grep -q "settings_url="
! printf "%s" "$plain_player_page" | grep -q "admin%2Fcameras"

# Plain user can open the rename form for an accessible camera
plain_rename_page="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/camera/rename?id=1")"
printf "%s" "$plain_rename_page" | grep -q "Smoke Cam"
printf "%s" "$plain_rename_page" | grep -q 'name="name"'
rename_csrf="$(printf "%s" "$plain_rename_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$rename_csrf"
rename_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$PLAIN_COOKIE_JAR" \
    -d "csrf=$rename_csrf" -d "id=1" -d "name=Renamed Smoke Cam" \
    "http://127.0.0.1:$PORT/camera/rename"
)"
test "$rename_status" = "303"
renamed_mosaic="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/")"
printf "%s" "$renamed_mosaic" | grep -q "Renamed Smoke Cam"
! printf "%s" "$renamed_mosaic" | grep -q "Smoke Cam"
renamed_camera="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras/1")"
printf "%s" "$renamed_camera" | grep -q '"name": "Renamed Smoke Cam"'
printf "%s" "$renamed_camera" | grep -q '"dvrStreamName": "smoke-cam"'
# Plain user cannot rename a camera outside their groups
rename_forbidden="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$PLAIN_COOKIE_JAR" \
    "http://127.0.0.1:$PORT/camera/rename?id=3"
)"
test "$rename_forbidden" = "403"
# Renaming to an existing name or an empty name is rejected
restore_rename_page="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/camera/rename?id=1")"
restore_csrf="$(printf "%s" "$restore_rename_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$restore_csrf"
duplicate_rename_page="$(
  curl -fsS -b "$PLAIN_COOKIE_JAR" \
    -d "csrf=$restore_csrf" -d "id=1" -d "name=Двор Камера" \
    "http://127.0.0.1:$PORT/camera/rename"
)"
printf "%s" "$duplicate_rename_page" | grep -q "уже существует"
empty_rename_page="$(
  curl -fsS -b "$PLAIN_COOKIE_JAR" \
    -d "csrf=$restore_csrf" -d "id=1" -d "name=" \
    "http://127.0.0.1:$PORT/camera/rename"
)"
printf "%s" "$empty_rename_page" | grep -q "Укажите название камеры"
# Restore the original name for later tests
curl -fsS -o /dev/null -b "$PLAIN_COOKIE_JAR" \
  -d "csrf=$restore_csrf" -d "id=1" -d "name=Smoke Cam" \
  "http://127.0.0.1:$PORT/camera/rename"
restored_camera="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras/1")"
printf "%s" "$restored_camera" | grep -q '"name": "Smoke Cam"'
printf "%s" "$restored_camera" | grep -q '"dvrStreamName": "smoke-cam"'

api_unauth="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/portal/v1/me"
)"
test "$api_unauth" = "401"
curl -fsS "http://127.0.0.1:$PORT/api/portal/v1" | grep -q '"name": "SesamePortal API"'
api_me="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/me")"
printf "%s" "$api_me" | grep -q '"login": "admin"'
printf "%s" "$api_me" | grep -q '"folderIds"'
api_dashboard="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/dashboard")"
printf "%s" "$api_dashboard" | grep -q '"counts"'
printf "%s" "$api_dashboard" | grep -q '"lastMetrics"'
api_cameras="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?pageSize=3")"
printf "%s" "$api_cameras" | grep -q '"pageSize": 3'
printf "%s" "$api_cameras" | grep -q '"Smoke Cam"'
printf "%s" "$api_cameras" | grep -q '"archiveEnabled": true'
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
    -d '{"displayName":"Display Smoke Cam","sourceUrl":"rtsp://example.invalid/display","serverId":1,"dvrStreamName":"display-smoke-cam","archiveEnabled":false,"webrtcFastStart":true,"eventArchiveRetentionEnabled":true,"eventArchiveMaxBytes":123456,"eventArchiveMaxDuration":"6h","eventArchiveMaxAge":"30d","timelapseEnabled":true,"timelapseFramesPerHour":12,"timelapseRetentionDays":"14d","timelapsePlaybackFps":15,"directArchiveVideoTimelineRepairMode":"auto","audioCodec":"aac","folderIds":[1,2],"skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
printf "%s" "$api_display_camera" | grep -q '"name": "Display Smoke Cam"'
printf "%s" "$api_display_camera" | grep -q '"displayName": "Display Smoke Cam"'
printf "%s" "$api_display_camera" | grep -q '"dvrStreamName": "display-smoke-cam"'
printf "%s" "$api_display_camera" | grep -q '"archiveEnabled": false'
printf "%s" "$api_display_camera" | grep -q '"webrtcFastStart": true'
printf "%s" "$api_display_camera" | grep -q '"eventArchiveRetentionEnabled": true'
printf "%s" "$api_display_camera" | grep -q '"eventArchiveMaxBytes": 123456'
printf "%s" "$api_display_camera" | grep -q '"eventArchiveMaxDuration": "6h"'
printf "%s" "$api_display_camera" | grep -q '"eventArchiveMaxAge": "30d"'
printf "%s" "$api_display_camera" | grep -q '"timelapseEnabled": true'
printf "%s" "$api_display_camera" | grep -q '"timelapseFramesPerHour": 12'
printf "%s" "$api_display_camera" | grep -q '"timelapseRetentionDays": "14d"'
printf "%s" "$api_display_camera" | grep -q '"timelapsePlaybackFps": 15'
printf "%s" "$api_display_camera" | grep -q '"directArchiveVideoTimelineRepairMode": "auto"'
printf "%s" "$api_display_camera" | grep -q '"audioCodec": "aac"'
display_camera_groups="$(printf "%s" "$api_display_camera" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo implode(",", $d["camera"]["folderIds"] ?? []);')"
test "$display_camera_groups" = "1,2"
api_duplicate_camera_status="$(
  curl -sS -o "$STATE_DIR/api_duplicate_camera_name.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Display Smoke Cam","sourceUrl":"rtsp://example.invalid/display-duplicate","serverId":1,"dvrStreamName":"display-smoke-cam-duplicate","skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
test "$api_duplicate_camera_status" = "409"
grep -q '"code": "camera_name_exists"' "$STATE_DIR/api_duplicate_camera_name.json"
api_display_camera_by_stream="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras/display-smoke-cam")"
printf "%s" "$api_display_camera_by_stream" | grep -q '"displayName": "Display Smoke Cam"'
api_display_camera_patch_by_stream="$(
  curl -fsS -b "$COOKIE_JAR" -X PATCH -H 'Content-Type: application/json' \
    -d '{"retentionDays":"9d","skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras/display-smoke-cam"
)"
printf "%s" "$api_display_camera_patch_by_stream" | grep -q '"retentionDays": "9d"'
api_numeric_stream_camera="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Numeric Stream Cam","sourceUrl":"rtsp://example.invalid/numeric","serverId":1,"dvrStreamName":"1","skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
printf "%s" "$api_numeric_stream_camera" | grep -q '"dvrStreamName": "1"'
api_numeric_id_priority="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras/1")"
printf "%s" "$api_numeric_id_priority" | grep -q '"id": 1'
if printf "%s" "$api_numeric_id_priority" | grep -q '"Numeric Stream Cam"'; then
  echo "camera id/name conflict did not prefer id" >&2
  exit 1
fi
api_dotted_stream_camera="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Dotted Stream Cam","sourceUrl":"rtsp://example.invalid/dotted","serverId":1,"dvrStreamName":"camera.test-1","skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
printf "%s" "$api_dotted_stream_camera" | grep -q '"dvrStreamName": "camera.test-1"'
api_dotted_camera_by_stream="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras/camera.test-1")"
printf "%s" "$api_dotted_camera_by_stream" | grep -q '"displayName": "Dotted Stream Cam"'
api_generated_camera="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Домофон. г. Сухум, ул. Киараз 9, п1","sourceUrl":"rtsp://example.invalid/generated","serverId":1,"skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
printf "%s" "$api_generated_camera" | grep -qE '"dvrStreamName": "domofon-g-sukhum-ul-kiaraz-9-p1(-[0-9a-f]{6})?"'
api_leading_dot_stream_status="$(
  curl -sS -o "$STATE_DIR/api_leading_dot_stream.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Leading Dot Stream","sourceUrl":"rtsp://example.invalid/leading-dot","serverId":1,"dvrStreamName":".hidden","skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
test "$api_leading_dot_stream_status" = "422"
grep -q '"code": "invalid_stream_name"' "$STATE_DIR/api_leading_dot_stream.json"
api_invalid_stream_status="$(
  curl -sS -o "$STATE_DIR/api_invalid_stream.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Bad Stream","sourceUrl":"rtsp://example.invalid/bad","serverId":1,"dvrStreamName":"Bad Stream, 1","skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
test "$api_invalid_stream_status" = "422"
grep -q '"code": "invalid_stream_name"' "$STATE_DIR/api_invalid_stream.json"
api_invalid_camera_group_status="$(
  curl -sS -o "$STATE_DIR/api_invalid_camera_group.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"displayName":"Bad Group Cam","sourceUrl":"rtsp://example.invalid/bad-group","serverId":1,"dvrStreamName":"bad-group-cam","folderIds":[99999],"skipSync":true}' \
    "http://127.0.0.1:$PORT/api/portal/v1/cameras"
)"
test "$api_invalid_camera_group_status" = "422"
grep -q '"field": "folderIds"' "$STATE_DIR/api_invalid_camera_group.json"
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
! printf "%s" "$api_accessible" | grep -q '"Read Only Cam"'
api_admin_group_filter="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?filter=group:1&pageSize=100")"
printf "%s" "$api_admin_group_filter" | grep -q '"Smoke Cam"'
printf "%s" "$api_admin_group_filter" | grep -q '"Display Smoke Cam"'
! printf "%s" "$api_admin_group_filter" | grep -q '"Read Only Cam"'
! printf "%s" "$api_admin_group_filter" | grep -q '"technical-only-cam"'
api_admin_group_id_filter="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?groupID=1&pageSize=100")"
printf "%s" "$api_admin_group_id_filter" | grep -q '"Smoke Cam"'
! printf "%s" "$api_admin_group_id_filter" | grep -q '"Read Only Cam"'
api_admin_group_ids_filter="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?groupIds=2,3&pageSize=100")"
printf "%s" "$api_admin_group_ids_filter" | grep -q '"Read Only Cam"'
printf "%s" "$api_admin_group_ids_filter" | grep -q '"Display Smoke Cam"'
! printf "%s" "$api_admin_group_ids_filter" | grep -q '"Smoke Cam"'
api_admin_numeric_group_filter="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/cameras?filter=1&pageSize=100")"
printf "%s" "$api_admin_numeric_group_filter" | grep -q '"Smoke Cam"'
! printf "%s" "$api_admin_numeric_group_filter" | grep -q '"Read Only Cam"'
api_group="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/1")"
printf "%s" "$api_group" | grep -q '"id": 1'
printf "%s" "$api_group" | grep -q '"parentGroupId": null'
printf "%s" "$api_group" | grep -q '"childGroupIds"'
printf "%s" "$api_group" | grep -q '"Smoke Subgroup"'
printf "%s" "$api_group" | grep -q '"folderIds"'
printf "%s" "$api_group" | grep -q '"folders"'
api_groups_search="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups?q=sMoKe%20sUb")"
printf "%s" "$api_groups_search" | grep -q '"Smoke Subgroup"'
api_group_children="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/1/children")"
printf "%s" "$api_group_children" | grep -q '"Smoke Subgroup"'
printf "%s" "$api_group_children" | grep -q '"childGroupIds"'
api_created_group="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"name":"API Smoke Group","description":"api"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/groups"
)"
api_group_id="$(printf "%s" "$api_created_group" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["group"]["id"] ?? "";')"
test -n "$api_group_id"
api_duplicate_name_group="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"name":"API Smoke Group","description":"api duplicate name"}' \
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
# Create a folder in the duplicate group and put the Read Only Cam in it.
api_duplicate_folder="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d "{\"name\":\"Dup Folder\",\"groupId\":$api_duplicate_name_group_id}" \
    "http://127.0.0.1:$PORT/api/portal/v1/folders"
)"
api_duplicate_folder_id="$(printf "%s" "$api_duplicate_folder" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["folder"]["id"] ?? "";')"
test -n "$api_duplicate_folder_id"
curl -fsS -b "$COOKIE_JAR" -X POST -H 'Content-Type: application/json' \
  -d '{"cameraIds":[2]}' \
  "http://127.0.0.1:$PORT/api/portal/v1/folders/$api_duplicate_folder_id/cameras" >/dev/null
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/groups/$api_duplicate_name_group_id/cameras" | grep -q '"Read Only Cam"'
api_invalid_group_camera_status="$(
  curl -sS -o "$STATE_DIR/api_invalid_group_camera.json" -w '%{http_code}' -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d '{"name":"API Invalid Folder Group","groupId":99999}' \
    "http://127.0.0.1:$PORT/api/portal/v1/folders"
)"
test "$api_invalid_group_camera_status" = "422"
grep -q '"groupId is required' "$STATE_DIR/api_invalid_group_camera.json"
invalid_group_count="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$stmt = \SesamePortal\DB::pdo()->prepare('SELECT COUNT(*) FROM portal_groups WHERE name = ?');
$stmt->execute(['API Invalid Folder Group']);
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
    -d '{"id":"9002","name":"API Explicit subGroup","description":"explicit child id"}' \
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
# Folder members API (replaces group-level write members).
api_group_folder="$(
  curl -fsS -b "$COOKIE_JAR" -H 'Content-Type: application/json' \
    -d "{\"name\":\"Members Folder\",\"groupId\":$api_group_id}" \
    "http://127.0.0.1:$PORT/api/portal/v1/folders"
)"
api_group_folder_id="$(printf "%s" "$api_group_folder" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["folder"]["id"] ?? "";')"
test -n "$api_group_folder_id"
curl -fsS -b "$COOKIE_JAR" -X PUT -H 'Content-Type: application/json' \
  -d '{"userIds":[1]}' \
  "http://127.0.0.1:$PORT/api/portal/v1/folders/$api_group_folder_id/users" >/dev/null
api_folder_users="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/folders/$api_group_folder_id/users")"
printf "%s" "$api_folder_users" | grep -q '"userIds"'
printf "%s" "$api_folder_users" | grep -q '"login": "admin"'
api_folder_users_empty="$(
  curl -fsS -b "$COOKIE_JAR" -X PUT -H 'Content-Type: application/json' \
    -d '{"userIds":[]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/folders/$api_group_folder_id/users"
)"
printf "%s" "$api_folder_users_empty" | grep -q '"userIds": \[\]'
curl -fsS -b "$COOKIE_JAR" -X POST -H 'Content-Type: application/json' \
  -d '{"userIds":[1]}' \
  "http://127.0.0.1:$PORT/api/portal/v1/folders/$api_group_folder_id/users" | grep -q '"login": "admin"'
api_folder_cameras_empty="$(
  curl -fsS -b "$COOKIE_JAR" -X DELETE -H 'Content-Type: application/json' \
    -d '{"cameraIds":[1]}' \
    "http://127.0.0.1:$PORT/api/portal/v1/folders/$api_group_folder_id/cameras"
)"
printf "%s" "$api_folder_cameras_empty" | grep -q '"cameraIds": \[\]'
curl -fsS -b "$COOKIE_JAR" -X PUT -H 'Content-Type: application/json' \
  -d '{"cameraIds":[1]}' \
  "http://127.0.0.1:$PORT/api/portal/v1/folders/$api_group_folder_id/cameras" | grep -q '"Smoke Cam"'
api_patched_folder="$(
  curl -fsS -b "$COOKIE_JAR" -X PATCH -H 'Content-Type: application/json' \
    -d '{"description":"patched folder"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/folders/$api_group_folder_id"
)"
printf "%s" "$api_patched_folder" | grep -q "patched folder"
api_patched_group="$(
  curl -fsS -b "$COOKIE_JAR" -X PATCH -H 'Content-Type: application/json' \
    -d '{"description":"api patched"}' \
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
api_static_reveal="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/users/1/static-token")"
revealed_static_token="$(printf "%s" "$api_static_reveal" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["token"] ?? "";')"
test "$revealed_static_token" = "$STATIC_TOKEN"
curl -fsS -H "Authorization: Bearer $STATIC_TOKEN" "http://127.0.0.1:$PORT/api/portal/v1/me" | grep -q '"login": "admin"'
api_static_replace="$(
  curl -fsS -b "$COOKIE_JAR" -X POST \
    "http://127.0.0.1:$PORT/api/portal/v1/users/1/static-token"
)"
STATIC_TOKEN_REPLACED="$(printf "%s" "$api_static_replace" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["token"] ?? "";')"
test -n "$STATIC_TOKEN_REPLACED"
test "$STATIC_TOKEN_REPLACED" != "$STATIC_TOKEN"
old_static_denied="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $STATIC_TOKEN" \
    "http://127.0.0.1:$PORT/api/portal/v1/me"
)"
test "$old_static_denied" = "401"
curl -fsS -H "Authorization: Bearer $STATIC_TOKEN_REPLACED" "http://127.0.0.1:$PORT/api/portal/v1/me" | grep -q '"login": "admin"'
STATIC_TOKEN="$STATIC_TOKEN_REPLACED"
api_static_reveal_replaced="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/users/1/static-token")"
revealed_static_token_replaced="$(printf "%s" "$api_static_reveal_replaced" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["token"] ?? "";')"
test "$revealed_static_token_replaced" = "$STATIC_TOKEN"
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
curl -fsS -H "Authorization: Bearer sp_smoke_user_token" -X PUT "http://127.0.0.1:$PORT/api/portal/v1/favorites/1" | grep -q '"favorite": true'
plain_user_favorites="$(
  curl -fsS -H "Authorization: Bearer sp_smoke_user_token" \
    "http://127.0.0.1:$PORT/api/portal/v1/favorites"
)"
plain_user_favorite_ids="$(printf "%s" "$plain_user_favorites" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo implode(",", $d["cameraIds"] ?? []);')"
test "$plain_user_favorite_ids" = "1"
curl -fsS -H "Authorization: Bearer sp_smoke_user_token" -X DELETE "http://127.0.0.1:$PORT/api/portal/v1/favorites/1" | grep -q '"favorite": false'
curl -fsS -b "$COOKIE_JAR" -X DELETE "http://127.0.0.1:$PORT/api/portal/v1/users/1/static-token" | grep -q '"ok": true'
revoked_static_denied="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer $STATIC_TOKEN" \
    "http://127.0.0.1:$PORT/api/portal/v1/me"
)"
test "$revoked_static_denied" = "401"
static_token_audit="$(
  php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$rows = \SesamePortal\DB::pdo()->query("SELECT action || ' ' || details FROM audit_logs WHERE action LIKE 'user.static_token.%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
echo implode("\n", $rows);
PHP
)"
grep -q "user.static_token.issue user_id=1 login=admin" <<<"$static_token_audit"
grep -q "user.static_token.replace user_id=1 login=admin" <<<"$static_token_audit"
grep -q "user.static_token.revoke user_id=1 login=admin" <<<"$static_token_audit"
grep -q "previous=yes" <<<"$static_token_audit"
admin_users_revoked="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?q=admin&lang=ru")"
printf "%s" "$admin_users_revoked" | grep -q "Выпустить статический токен"
printf "%s" "$admin_users_revoked" | grep -q ">нет<"
admin_edit_revoked="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?edit=1&lang=ru")"
! printf "%s" "$admin_edit_revoked" | grep -q 'data-static-token-reveal'
printf "%s" "$admin_edit_revoked" | grep -q ">нет<"
plain_user_id="$(
  curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/users?q=plain-user&pageSize=1" \
    | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["users"][0]["id"] ?? "";'
)"
test -n "$plain_user_id"
admin_users_edit_panel="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?edit=$plain_user_id&lang=ru")"
printf "%s" "$admin_users_edit_panel" | grep -q "Постоянный токен пользователя"
printf "%s" "$admin_users_edit_panel" | grep -q ">есть<"
printf "%s" "$admin_users_edit_panel" | grep -q "Заменить статический токен"
printf "%s" "$admin_users_edit_panel" | grep -q "Отозвать"
printf "%s" "$admin_users_edit_panel" | grep -q 'data-static-token-reveal'
printf "%s" "$admin_users_edit_panel" | grep -q 'value="*******"'
plain_static_reveal="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/users/$plain_user_id/static-token")"
printf "%s" "$plain_static_reveal" | grep -q '"token": null'
plain_me="$(curl -fsS -H "Authorization: Bearer sp_smoke_user_token" "http://127.0.0.1:$PORT/api/portal/v1/me")"
printf "%s" "$plain_me" | grep -q '"login": "plain-user"'
printf "%s" "$plain_me" | grep -q '"role": "user"'
plain_admin_denied="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer sp_smoke_user_token" \
    "http://127.0.0.1:$PORT/api/portal/v1/users"
)"
test "$plain_admin_denied" = "403"
plain_cameras="$(curl -fsS -H "Authorization: Bearer sp_smoke_user_token" "http://127.0.0.1:$PORT/api/portal/v1/cameras?scope=accessible&pageSize=50")"
plain_camera_names="$(printf "%s" "$plain_cameras" | php -r '$d=json_decode(stream_get_contents(STDIN), true); foreach (($d["cameras"] ?? []) as $c) { echo $c["name"] ?? "", "\n"; }')"
grep -q "Smoke Cam" <<<"$plain_camera_names"
grep -q "Двор Камера" <<<"$plain_camera_names"
plain_restricted_camera_absent="$(grep -c "Read Only Cam" <<<"$plain_camera_names" || true)"
test "$plain_restricted_camera_absent" = "0"

denied="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=bad&camera=missing"
)"
test "$denied" = "403"
unknown_plain="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=sp_smoke_user_token&name=rbt-only-stream"
)"
test "$unknown_plain" = "403"
daily_token_qs="$(TOKEN="$TOKEN" php -r 'echo rawurlencode(getenv("TOKEN"));')"
unknown_daily="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=$daily_token_qs&name=rbt-only-stream"
)"
test "$unknown_daily" = "403"
authbackend_admin_static="$(
  curl -fsS -b "$COOKIE_JAR" -X POST "http://127.0.0.1:$PORT/api/portal/v1/users/1/static-token"
)"
admin_authbackend_token="$(printf "%s" "$authbackend_admin_static" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["token"] ?? "";')"
test -n "$admin_authbackend_token"
admin_authbackend_token_qs="$(TOKEN="$admin_authbackend_token" php -r 'echo rawurlencode(getenv("TOKEN"));')"
unknown_admin="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=$admin_authbackend_token_qs&name=rbt-only-stream"
)"
test "$unknown_admin" = "200"

qs="$(TOKEN="$TOKEN" php -r 'echo rawurlencode("token=" . getenv("TOKEN"));')"
allowed="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=NonAvailable&qs=$qs&name=smoke-cam"
)"
test "$allowed" = "200"
plain_qs="$(php -r 'echo rawurlencode("token=sp_smoke_user_token");')"
hidden_live="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=NonAvailable&qs=$plain_qs&name=smoke-cam&proto=hls&dvr=false"
)"
test "$hidden_live" = "200"
hidden_player="$(
  curl -fsS \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=NonAvailable&qs=$plain_qs&name=smoke-cam&proto=player&dvr=false"
)"
printf "%s" "$hidden_player" | grep -q '"allowed_dvr_ranges":\[\]'
printf "%s" "$hidden_player" | grep -q '"playerOverlays":\['
printf "%s" "$hidden_player" | grep -q '"imageUrl":"https://apsny.camera/player/logo.png"'
hidden_ranges="$(
  curl -fsS \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=NonAvailable&qs=$plain_qs&name=smoke-cam&proto=player&dvr=true"
)"
printf "%s" "$hidden_ranges" | grep -q '"allowed_dvr_ranges":\[\]'
printf "%s" "$hidden_ranges" | grep -q '"playerOverlays":\['
hidden_archive_hls="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=NonAvailable&qs=$plain_qs&name=smoke-cam&proto=hls&dvr=true"
)"
test "$hidden_archive_hls" = "403"
archive_uri="$(TOKEN="$TOKEN" php -r 'echo rawurlencode("/smoke-cam/archive-1700000000-60.mp4?token=" . getenv("TOKEN"));')"
archive_allowed="$(
  curl -sS -o /dev/null -w '%{http_code}' -H 'X-Forwarded-For: 203.0.113.9' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?uri=$archive_uri"
)"
test "$archive_allowed" = "200"
hidden_archive_uri="$(php -r 'echo rawurlencode("/smoke-cam/archive-1700000000-60.mp4?token=sp_smoke_user_token");')"
hidden_archive_allowed="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?uri=$hidden_archive_uri"
)"
test "$hidden_archive_allowed" = "403"
archive_audit_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/audit?q=archive.download&action=archive.download&actor=1")"
printf "%s" "$archive_audit_page" | grep -q "archive.download"
printf "%s" "$archive_audit_page" | grep -q "camera_id=1"
printf "%s" "$archive_audit_page" | grep -q "from=1700000000"
printf "%s" "$archive_audit_page" | grep -q "duration=60"
printf "%s" "$archive_audit_page" | grep -q "ip=203.0.113.9"

# --- Custom mosaics ---
mosaic_list="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/mosaic")"
printf "%s" "$mosaic_list" | grep -q "Мозаика"
printf "%s" "$mosaic_list" | grep -q "Создать мозаику"
mosaic_new="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/mosaic/new")"
printf "%s" "$mosaic_new" | grep -F -q 'name="name"'
printf "%s" "$mosaic_new" | grep -F -q 'name="grid_rows"'
printf "%s" "$mosaic_new" | grep -F -q 'name="grid_cols"'
printf "%s" "$mosaic_new" | grep -F -q 'name="cameras[]"'
mosaic_csrf="$(printf "%s" "$mosaic_new" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$mosaic_csrf"
mosaic_save_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" \
    -d "csrf=$mosaic_csrf" -d "name=Admin Mosaic" -d "grid_rows=3" -d "grid_cols=3" -d "cameras[]=1" -d "cameras[]=2" \
    "http://127.0.0.1:$PORT/mosaic/save"
)"
test "$mosaic_save_status" = "303"
mosaic_list_after="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/mosaic")"
printf "%s" "$mosaic_list_after" | grep -q "Admin Mosaic"
mosaic_view="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/mosaic/view?id=1")"
printf "%s" "$mosaic_view" | grep -q "Admin Mosaic"
printf "%s" "$mosaic_view" | grep -q "mosaic-grid"
printf "%s" "$mosaic_view" | grep -q "mosaic-tile-frame"
printf "%s" "$mosaic_view" | grep -q "hidecontrols=true"
printf "%s" "$mosaic_view" | grep -q "screenshot=false"
! printf "%s" "$mosaic_view" | grep -E -q "back_url="
! printf "%s" "$mosaic_view" | grep -q "camera-meta"
# Mosaic without name is rejected
mosaic_no_name_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" \
    -d "csrf=$mosaic_csrf" -d "name=" -d "grid_rows=3" -d "grid_cols=3" \
    "http://127.0.0.1:$PORT/mosaic/save"
)"
test "$mosaic_no_name_status" = "422"

# Plain user can create their own mosaic (from accessible cameras)
plain_mosaic_new="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/mosaic/new")"
plain_mosaic_csrf="$(printf "%s" "$plain_mosaic_new" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$plain_mosaic_csrf"
plain_mosaic_save_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$PLAIN_COOKIE_JAR" \
    -d "csrf=$plain_mosaic_csrf" -d "name=Plain Mosaic" -d "grid_rows=2" -d "grid_cols=2" -d "cameras[]=1" \
    "http://127.0.0.1:$PORT/mosaic/save"
)"
test "$plain_mosaic_save_status" = "303"
plain_mosaic_list="$(curl -fsS -b "$PLAIN_COOKIE_JAR" "http://127.0.0.1:$PORT/mosaic")"
printf "%s" "$plain_mosaic_list" | grep -q "Plain Mosaic"
# Plain user does NOT see admin's mosaic
! printf "%s" "$plain_mosaic_list" | grep -q "Admin Mosaic"
# Plain user cannot view admin's mosaic
plain_forbidden_view="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$PLAIN_COOKIE_JAR" \
    "http://127.0.0.1:$PORT/mosaic/view?id=1"
)"
test "$plain_forbidden_view" = "403"
# Admin sees plain user's mosaic
admin_list_all="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/mosaic")"
printf "%s" "$admin_list_all" | grep -q "Plain Mosaic"
# Admin sees mosaic owner login
printf "%s" "$admin_list_all" | grep -q "mosaic-owner"
printf "%s" "$admin_list_all" | grep -q "plain-user"
# Admin can view plain user's mosaic
admin_view_plain="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" \
    "http://127.0.0.1:$PORT/mosaic/view?id=2"
)"
test "$admin_view_plain" = "200"

# Onboarding: admin creates user without password -> default password, must_change_password=1
onboarding_csrf="$(printf "%s" "$admin_users_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
# Create a new user via admin form with empty password (role=user)
onboarding_create_response="$(
  curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$onboarding_csrf" -d "action=save" -d "id=0" \
    -d "login=onboard-user" -d "password=" -d "role=user" \
    -d "folder_ids_json=" \
    "http://127.0.0.1:$PORT/admin/users"
)"
# Check that default password was generated (success message contains temporary password)
printf "%s" "$onboarding_create_response" | grep -q "Временный пароль"
printf "%s" "$onboarding_create_response" | grep -q "Пользователь сохранён"
# Check that the user was really created with must_change_password=1
onboard_user_id="$(php -r 'require getenv("ROOT") . "/app/Portal.php"; $u = \SesamePortal\DB::pdo()->query("SELECT id FROM users WHERE login = '"'"'onboard-user'"'"'")->fetch(PDO::FETCH_ASSOC); echo $u["id"] ?? "";')"
test -n "$onboard_user_id"
onboard_mcp_after_create="$(OB_ID="$onboard_user_id" php -r 'require getenv("ROOT") . "/app/Portal.php"; $id = (int)getenv("OB_ID"); echo (string)\SesamePortal\DB::pdo()->query("SELECT must_change_password FROM users WHERE id = $id")->fetch(PDO::FETCH_COLUMN);')"
test "$onboard_mcp_after_create" = "1"
# Admin page now lists the new user
onboarding_admin_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users")"
printf "%s" "$onboarding_admin_page" | grep -q "onboard-user"
# Admin login does NOT redirect to onboarding (admin exempt)
# (admin already logged in via COOKIE_JAR, verify / is accessible)
admin_home_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" \
    "http://127.0.0.1:$PORT/"
)"
test "$admin_home_status" = "200"

# Onboarding form has must_change_password, email fields
onboarding_form_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?edit=3")"
printf "%s" "$onboarding_form_page" | grep -q 'name="mosaic_enabled"'
printf "%s" "$onboarding_form_page" | grep -q 'name="hide_archive"'

# Admin users form has email field for viewing/editing user email
admin_users_email_form="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?edit=3&lang=ru")"
printf "%s" "$admin_users_email_form" | grep -q 'name="email"'
# Save email via admin form
onboarding_email_save_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$onboarding_csrf" -d "action=save" -d "id=$onboard_user_id" \
    -d "login=onboard-user" -d "email=onboard@example.com" -d "password=" -d "role=user" \
    -d "folder_ids_json=" \
    "http://127.0.0.1:$PORT/admin/users"
)"
test "$onboarding_email_save_status" = "200"
admin_users_email_saved="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?edit=$onboard_user_id&lang=ru")"
printf "%s" "$admin_users_email_saved" | grep -q 'value="onboard@example.com"'
onboard_email_db="$(OB_ID="$onboard_user_id" php -r 'require getenv("ROOT") . "/app/Portal.php"; $id = (int)getenv("OB_ID"); echo (string)\SesamePortal\DB::pdo()->query("SELECT email FROM users WHERE id = $id")->fetch(PDO::FETCH_COLUMN);')"
test "$onboard_email_db" = "onboard@example.com"
# Invalid email is rejected
onboarding_email_invalid_response="$(
  curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$onboarding_csrf" -d "action=save" -d "id=$onboard_user_id" \
    -d "login=onboard-user" -d "email=not-an-email" -d "password=" -d "role=user" \
    -d "folder_ids_json=" \
    "http://127.0.0.1:$PORT/admin/users"
)"
printf "%s" "$onboarding_email_invalid_response" | grep -q "Введите корректный email"

# Admin edit of an existing user must NOT reset must_change_password
OB_ID="$onboard_user_id" php -r 'require getenv("ROOT") . "/app/Portal.php"; \SesamePortal\DB::pdo()->exec("UPDATE users SET must_change_password = 0 WHERE id = " . (int)getenv("OB_ID"));'
onboarding_mcp_save_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$onboarding_csrf" -d "action=save" -d "id=$onboard_user_id" \
    -d "login=onboard-user" -d "email=onboard@example.com" -d "password=" -d "role=user" \
    -d "folder_ids_json=" \
    "http://127.0.0.1:$PORT/admin/users"
)"
test "$onboarding_mcp_save_status" = "200"
onboard_mcp_db="$(OB_ID="$onboard_user_id" php -r 'require getenv("ROOT") . "/app/Portal.php"; $id = (int)getenv("OB_ID"); echo (string)\SesamePortal\DB::pdo()->query("SELECT must_change_password FROM users WHERE id = $id")->fetch(PDO::FETCH_COLUMN);')"
test "$onboard_mcp_db" = "0"

# User deletion requires confirmation dialog with Cancel selected by default
onboarding_delete_confirm_page="$(curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users")"
printf "%s" "$onboarding_delete_confirm_page" | grep -q 'data-confirm="'
printf "%s" "$onboarding_delete_confirm_page" | grep -q 'data-confirm-ok="'
printf "%s" "$onboarding_delete_confirm_page" | grep -q 'data-confirm-ok="Удаление пользователя"'

# Login page has forgot password link
login_page_html="$(curl -fsS "http://127.0.0.1:$PORT/login")"
printf "%s" "$login_page_html" | grep -q 'href="/forgot"'

# Forgot password page renders
forgot_page="$(curl -fsS "http://127.0.0.1:$PORT/forgot")"
printf "%s" "$forgot_page" | grep -q 'name="email"'
printf "%s" "$forgot_page" | grep -q 'href="/login"'

# Forgot password always reports "instructions sent", even for unknown emails (anti-enumeration)
forgot_unknown="$(curl -fsS -d "email=no-such-user@example.com" "http://127.0.0.1:$PORT/forgot?lang=ru")"
printf "%s" "$forgot_unknown" | grep -q "Письмо отправлено"
! printf "%s" "$forgot_unknown" | grep -q "Если email найден"

# Onboarding page exists (GET without login redirects to /login)
onboarding_no_login="$(
  curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/onboarding"
)"
# Should redirect (302 or 303) to /login
test "$onboarding_no_login" = "302" || test "$onboarding_no_login" = "303"

# Phone callback authorization (Вход по звонку)
callback_phone="79000000001"
callback_user_login="smoke-callback-user"
callback_setup_output="$(
  P="$callback_phone" L="$callback_user_login" php <<'PHP'
<?php
require getenv('ROOT') . '/app/Portal.php';
$phone = getenv('P');
$login = getenv('L');
$stmt = \SesamePortal\DB::pdo()->prepare('SELECT id FROM users WHERE phone = ?');
$stmt->execute([$phone]);
if ($stmt->fetchColumn() === false) {
    \SesamePortal\DB::pdo()->prepare('INSERT INTO users(login, phone, password_hash, role, blocked, created_at) VALUES(?, ?, ?, ?, ?, ?)')
        ->execute([$login, $phone, password_hash('x', PASSWORD_DEFAULT), 'user', 0, '2026-01-01 00:00:00']);
}
\SesamePortal\DB::setSetting('callback_enabled', '1');
\SesamePortal\DB::setSetting('callback_phone', $phone);
\SesamePortal\DB::setSetting('callback_webhook_token', 'smoke-callback-secret-1');
echo 'setup ok';
PHP
)"
test "$callback_setup_output" = "setup ok"

# Login page shows the call-sign-in tab and pane
callback_login_page="$(curl -fsS "http://127.0.0.1:$PORT/login?lang=ru")"
printf "%s" "$callback_login_page" | grep -q 'data-login-tab="callback"'
printf "%s" "$callback_login_page" | grep -q 'name="phone" data-callback-phone'

# Start a callback request and extract pending_id
callback_start_json="$(curl -fsS -X POST -H 'Content-Type: application/json' \
  -d "{\"phone\":\"$callback_phone\"}" \
  "http://127.0.0.1:$PORT/api/portal/v1/auth/callback/start")"
callback_pending_id="$(printf "%s" "$callback_start_json" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo (string)($d["pending_id"] ?? "");')"
test -n "$callback_pending_id"

# Wrong webhook token is rejected with 403
callback_webhook_bad_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Authorization: Bearer wrong-token' -H 'Content-Type: application/json' \
    -d "{\"phone\":\"$callback_phone\"}" \
    "http://127.0.0.1:$PORT/api/portal/v1/auth/callback/webhook"
)"
test "$callback_webhook_bad_status" = "403"

# Correct webhook token confirms the pending request
callback_webhook_json="$(curl -fsS -X POST -H 'Authorization: Bearer smoke-callback-secret-1' -H 'Content-Type: application/json' \
  -d "{\"phone\":\"$callback_phone\",\"call_id\":\"smoke-call-1\"}" \
  "http://127.0.0.1:$PORT/api/portal/v1/auth/callback/webhook")"
printf "%s" "$callback_webhook_json" | grep -q '"ok": true'

# Poll shows the request as confirmed
callback_poll_json="$(curl -fsS "http://127.0.0.1:$PORT/api/portal/v1/auth/callback/poll?pending_id=$callback_pending_id")"
printf "%s" "$callback_poll_json" | grep -q '"status": "confirmed"'

# Complete signs the user in (a browser session is created) and redirects to /
CALLBACK_COOKIE_JAR="$STATE_DIR/callback-cookies.txt"
callback_complete_json="$(curl -fsS -c "$CALLBACK_COOKIE_JAR" -X POST -H 'Content-Type: application/json' \
  -d "{\"pending_id\":\"$callback_pending_id\"}" \
  "http://127.0.0.1:$PORT/api/portal/v1/auth/callback/complete")"
printf "%s" "$callback_complete_json" | grep -q '"redirect": "/"'
callback_me="$(curl -fsS -b "$CALLBACK_COOKIE_JAR" "http://127.0.0.1:$PORT/api/portal/v1/me")"
printf "%s" "$callback_me" | grep -q "\"login\": \"$callback_user_login\""
printf "%s" "$callback_me" | grep -q "\"phone\": \"$callback_phone\""

# Unregistered phone is rejected with 401 (anti-enumeration delay applied)
callback_unknown_status="$(
  curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d '{"phone":"79999999999"}' \
    "http://127.0.0.1:$PORT/api/portal/v1/auth/callback/start"
)"
test "$callback_unknown_status" = "401"

echo "http smoke ok"
