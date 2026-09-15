<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Portal.php';

use SesamePortal\App;
use SesamePortal\Config;
use SesamePortal\DB;
use SesamePortal\Util;

// All fixtures stay in memory; never open a configured portal database.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
(new ReflectionProperty(DB::class, 'driver'))->setValue(null, 'sqlite');
(new ReflectionProperty(Config::class, 'config'))->setValue(null, [
    'timezone' => 'UTC',
    'hidden_archive_player_overlays' => [[
        'type' => 'imageLink',
        'imageUrl' => 'https://example.invalid/logo.png',
    ]],
]);
DB::migrate();

$count = 0;
function check(bool $ok, string $message): void
{
    global $count;
    $count++;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function authRequest(array $params): array
{
    $_GET = $params;
    $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    http_response_code(200);
    ob_start();
    try {
        (new ReflectionMethod(App::class, 'authBackend'))->invoke(null);
        return [http_response_code(), (string)ob_get_contents()];
    } finally {
        ob_end_clean();
    }
}

function allowedPtz(array $params, string $userId): array
{
    [$status, $body] = authRequest($params + ['proto' => 'ptz', 'name' => 'cam-1']);
    check($status === 200, 'Expected PTZ authorization');
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    check(($payload['ptz_allowed'] ?? null) === true, 'PTZ grant must be boolean true');
    check(($payload['user_id'] ?? null) === $userId, 'Trusted stable string user ID');
    return $payload;
}

function deniedPtz(array $params, string $reason = "denied\n"): void
{
    [$status, $body] = authRequest($params + ['proto' => 'ptz', 'name' => 'cam-1']);
    check($status === 403 && $body === $reason, 'Denied request must not grant PTZ');
}

$now = Util::now();
foreach ([['admin', 'admin'], ['viewer', 'user']] as [$login, $role]) {
    $pdo->prepare('INSERT INTO users(login, password_hash, role, daily_token, daily_token_date, static_token_hash, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
        ->execute([$login, 'unused', $role, 'daily-' . $login, '2026-09-15', password_hash('sp_' . $login, PASSWORD_DEFAULT), $now]);
}
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, created_at) VALUES(?, ?, ?)')
    ->execute(['Fixture', 'https://dvr.example.invalid', $now]);
foreach (['Allowed', 'Private'] as $name) {
    $pdo->prepare('INSERT INTO portal_groups(name, created_at) VALUES(?, ?)')->execute([$name, $now]);
}
$pdo->exec('INSERT INTO user_groups VALUES(2, 1)');
for ($i = 1; $i <= 2; $i++) {
    $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, dvr_stream_name, created_at, updated_at) VALUES(?, ?, 1, ?, ?, ?)')
        ->execute(['Camera ' . $i, 'rtsp://example.invalid/' . $i, 'cam-' . $i, $now, $now]);
    $pdo->prepare('INSERT INTO camera_groups VALUES(?, ?)')->execute([$i, $i]);
}

foreach (['daily-admin', 'sp_admin'] as $token) {
    allowedPtz(['token' => $token], '1');
}
foreach (['daily-viewer', 'sp_viewer'] as $token) {
    $payload = allowedPtz(['token' => $token, 'user_id' => 'forged-admin'], '2');
    check(array_keys($payload) === ['ptz_allowed', 'user_id'], 'No credentials in PTZ response');
}
allowedPtz(['token' => 'NonAvailable', 'qs' => 'token=daily-viewer', 'proto' => ' PTZ '], '2');
allowedPtz(['uri' => '/cam-1/ptz.json?token=daily-viewer'], '2');
allowedPtz(['token' => 'sp_admin', 'name' => 'external-stream'], '1');
deniedPtz(['token' => 'daily-admin', 'name' => 'external-stream']);
deniedPtz(['token' => 'sp_viewer', 'name' => 'external-stream']);
deniedPtz(['token' => 'daily-viewer', 'name' => 'cam-2']);
deniedPtz(['token' => 'daily-viewer', 'name' => '']);
deniedPtz(['token' => 'invalid']);
deniedPtz([]);

foreach (['player', 'hls', 'webrtc', ''] as $proto) {
    [$status, $body] = authRequest(['token' => 'daily-viewer', 'name' => 'cam-1', 'proto' => $proto]);
    check($status === 200 && $body === "ok\n", 'Existing playback response is unchanged');
}

$pdo->exec('UPDATE users SET hide_archive = 1 WHERE id = 2');
$payload = allowedPtz(['token' => 'daily-viewer'], '2');
check(($payload['allowed_dvr_ranges'] ?? null) === [], 'PTZ must preserve archive restriction');
check(!isset($payload['playerOverlays']), 'Player overlays do not belong in PTZ replies');
$payload = allowedPtz(['token' => 'daily-viewer', 'uri' => '/cam-1/timeline_ranges.json'], '2');
check(($payload['allowed_dvr_ranges'] ?? null) === [], 'Metadata capability also preserves PTZ');
[$status, $body] = authRequest(['token' => 'daily-viewer', 'name' => 'cam-1', 'proto' => 'player']);
$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
check($status === 200 && $payload['allowed_dvr_ranges'] === [] && isset($payload['playerOverlays']), 'Existing player overlays and archive restriction');
check(!array_key_exists('ptz_allowed', $payload) && !array_key_exists('user_id', $payload), 'Normal player reply is unchanged');
deniedPtz(['token' => 'daily-viewer', 'uri' => '/cam-1/archive-1700000000-60.mp4'], "archive_denied\n");
[$status] = authRequest(['token' => 'daily-viewer', 'name' => 'cam-1', 'proto' => 'hls', 'dvr' => 'true']);
check($status === 403, 'Archive HLS is still denied');

$pdo->exec("UPDATE users SET daily_token = 'rotated-viewer', previous_daily_token = NULL WHERE id = 2");
deniedPtz(['token' => 'daily-viewer']);
allowedPtz(['token' => 'rotated-viewer'], '2');
allowedPtz(['token' => 'sp_viewer'], '2');
$pdo->exec('UPDATE users SET static_token_hash = NULL WHERE id = 2');
deniedPtz(['token' => 'sp_viewer']);
$pdo->exec('UPDATE users SET blocked = 1');
deniedPtz(['token' => 'rotated-viewer']);
deniedPtz(['token' => 'sp_admin']);
$pdo->exec('UPDATE users SET blocked = 0');
$pdo->exec('UPDATE portal_groups SET blocked = 1 WHERE id = 1');
deniedPtz(['token' => 'rotated-viewer']);
$pdo->exec('UPDATE portal_groups SET blocked = 0 WHERE id = 1');
$pdo->exec('UPDATE cameras SET blocked = 1 WHERE id = 1');
deniedPtz(['token' => 'rotated-viewer']);
deniedPtz(['token' => 'daily-admin']);
deniedPtz(['token' => 'sp_admin']);

echo "auth backend: {$count} checks passed\n";
