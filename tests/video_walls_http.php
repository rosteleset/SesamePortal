<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Portal.php';

use SesamePortal\DB;
use SesamePortal\TokenService;
use SesamePortal\Util;

$port = (int)($argv[1] ?? 0);
if (!$port || !getenv('SESAME_PORTAL_STATE_DIR')) throw new RuntimeException('Run inside the local HTTP smoke harness');
$base = 'http://127.0.0.1:' . $port;
$pdo = DB::pdo();
$now = Util::now();
$users = [];
foreach (['wall-owner' => 'user', 'wall-other' => 'user', 'wall-admin' => 'admin'] as $login => $role) {
    $pdo->prepare('INSERT INTO users(login, password_hash, role, created_at) VALUES(?, ?, ?, ?)')->execute([$login, password_hash('wall-test-password', PASSWORD_DEFAULT), $role, $now]);
    $id = DB::lastInsertId('users');
    $users[$login] = ['id' => $id, 'token' => TokenService::issueStaticToken($id), 'cookie' => getenv('SESAME_PORTAL_STATE_DIR') . '/' . $login . '.cookies'];
}
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, created_at) VALUES(?, ?, ?)')->execute(['Wall DVR fixture', 'https://dvr.example.invalid', $now]);
$serverId = DB::lastInsertId('dvr_servers');
$pdo->prepare('INSERT INTO portal_groups(name, created_at) VALUES(?, ?)')->execute(['Wall group fixture', $now]);
$groupId = DB::lastInsertId('portal_groups');
$pdo->prepare('INSERT INTO user_groups VALUES(?, ?)')->execute([$users['wall-owner']['id'], $groupId]);
$cameraIds = [];
for ($i = 0; $i < 2; $i++) {
    $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, dvr_stream_name, watermark_enabled, created_at, updated_at) VALUES(?, ?, ?, ?, 1, ?, ?)')->execute(['Wall Camera ' . $i, 'rtsp://example.invalid/' . $i, $serverId, 'wall-stream-' . $i, $now, $now]);
    $cameraIds[] = DB::lastInsertId('cameras');
    $pdo->prepare('INSERT INTO camera_groups VALUES(?, ?)')->execute([end($cameraIds), $groupId]);
}
$checks = 0;
function expect(bool $ok, string $label): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException('Video wall HTTP: ' . $label);
}
function request(string $login, string $method, string $path, ?array $data = null, bool $token = true, array $headers = []): array {
    global $base, $users;
    $user = $users[$login];
    if ($token) $headers[] = 'Authorization: Bearer ' . $user['token'];
    $curl = curl_init($base . $path);
    $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $user['cookie'], CURLOPT_COOKIEJAR => $user['cookie'], CURLOPT_TIMEOUT => 20];
    if ($data !== null) {
        $json = str_starts_with($path, '/api/');
        $headers[] = $json ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_POSTFIELDS] = $json ? json_encode($data) : http_build_query($data);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    if ($response === false) throw new RuntimeException(curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $length = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    return [$status, substr($response, $length), substr($response, 0, $length)];
}
[$status] = request('wall-owner', 'POST', '/login', ['login' => 'wall-owner', 'password' => 'wall-test-password'], false);
expect($status === 303, 'login');
[$status, $page] = request('wall-owner', 'GET', '/video-walls/edit', null, false);
expect($status === 200 && str_contains($page, 'data-wall-editor'), 'editor');
preg_match('/name="csrf" value="([^"]+)"/', $page, $match);
$csrf = $match[1];
expect(str_contains($page, 'Wall group fixture') && str_contains($page, 'name="cameraIds[]"'), 'tree');
expect(str_contains($page, '>Список</span>') && str_contains($page, '>Видеостены</span>'), 'navigation');
expect(request('wall-owner', 'POST', '/video-walls', ['name' => 'no csrf'], false)[0] === 419, 'form CSRF');
$data = ['name' => 'HTTP wall', 'rows' => 1, 'columns' => 2, 'cameraIds' => array_reverse($cameraIds)];
expect(request('wall-owner', 'POST', '/api/portal/v1/video-walls', $data, false)[0] === 419, 'cookie API CSRF');
expect(request('wall-owner', 'POST', '/api/portal/v1/video-walls', $data, false, ['Authorization: Bearer '])[0] === 419, 'empty bearer must not bypass cookie CSRF');
expect(request('wall-owner', 'POST', '/api/portal/v1/video-walls', $data, false, ['Authorization: Bearer invalid'])[0] === 401, 'invalid bearer must not fall back to session');
[$status, $body] = request('wall-owner', 'POST', '/api/portal/v1/video-walls', $data);
$wall = json_decode($body, true)['data'];
$id = $wall['id'];
expect($status === 201 && $wall['cameraIds'] === array_reverse($cameraIds), 'API create and order');
expect(request('wall-owner', 'PATCH', '/api/portal/v1/video-walls/' . $id, ['name' => 'HTTP wall'], false, ['X-CSRF-Token: ' . $csrf])[0] === 200, 'cookie API with CSRF succeeds');
foreach (['GET', 'PATCH', 'DELETE'] as $method) {
    expect(request('wall-other', $method, '/api/portal/v1/video-walls/' . $id, $method === 'PATCH' ? ['name' => 'steal'] : null)[0] === 404, 'foreign ' . $method);
}
expect(request('wall-admin', 'GET', '/api/portal/v1/video-walls/' . $id)[0] === 200, 'admin access');
$list = json_decode(request('wall-other', 'GET', '/api/portal/v1/video-walls')[1], true);
expect($list['data'] === [], 'private list');
expect(request('wall-owner', 'PATCH', '/api/portal/v1/video-walls/' . $id, ['columns' => 1])[0] === 422, 'capacity validation');
[$status, $view] = request('wall-owner', 'GET', '/video-walls/view?id=' . $id, null, false);
expect($status === 200 && substr_count($view, 'data-wall-frame') === 2 && str_contains($view, 'vw-watermark'), 'view and watermark');
expect(substr_count($view, 'data-wall-camera-zoom aria-pressed="false"') === 2, 'every accessible camera has a disabled-by-default zoom toggle');
expect(!str_contains($view, 'token='), 'no token baked into view');
[$status, , $headers] = request('wall-owner', 'GET', '/video-walls/stream?id=' . $id . '&camera_id=' . $cameraIds[0], null, false);
expect($status === 302 && str_contains($headers, 'dvr=true') && str_contains($headers, 'hidecontrols=true'), 'wall embed permits authorized archive');
expect(str_contains(strtolower($headers), 'cache-control: no-store'), 'private redirect');
$streamPath = '/video-walls/stream?id=' . $id . '&camera_id=' . $cameraIds[0];
expect(str_contains($view, 'data-wall-archive-controls'), 'shared archive controls');
$channel = str_repeat('a', 32);
[$status, , $headers] = request('wall-owner', 'GET', $streamPath . '&controller_id=' . $channel, null, false);
expect($status === 302 && str_contains($headers, 'controller_id=' . $channel) && str_contains($headers, 'controller_version=1'), 'controlled embed');
expect(str_contains($headers, 'controller_origin=' . rawurlencode($base)), 'exact parent origin set by Portal');
expect(request('wall-owner', 'GET', $streamPath . '&controller_id=bad', null, false)[0] === 400, 'invalid channel rejected');
$pdo->prepare('UPDATE users SET hide_archive = 1 WHERE id = ?')->execute([$users['wall-owner']['id']]);
$hidden = request('wall-owner', 'GET', '/video-walls/view?id=' . $id, null, false)[1];
expect(!str_contains($hidden, 'data-wall-archive-controls') && str_contains($hidden, 'data-wall-archive="0"'), 'archive hidden in wall UI');
expect(substr_count($hidden, 'data-wall-camera-zoom') === 2, 'camera zoom toggle is independent of archive access');
expect(str_contains(request('wall-owner', 'GET', $streamPath . '&controller_id=' . $channel, null, false)[2], 'dvr=false'), 'archive disabled in controlled embed for hidden user');
$pdo->prepare('UPDATE users SET hide_archive = 0 WHERE id = ?')->execute([$users['wall-owner']['id']]);
$pdo->prepare('DELETE FROM user_groups WHERE user_id = ?')->execute([$users['wall-owner']['id']]);
$view = request('wall-owner', 'GET', '/video-walls/view?id=' . $id, null, false)[1];
expect(!str_contains($view, 'data-wall-frame') && !str_contains($view, 'Wall Camera'), 'revoke removes embeds and names');
expect(!str_contains($view, 'data-wall-camera-zoom'), 'unavailable cameras have no zoom toggle');
expect(request('wall-owner', 'GET', '/video-walls/stream?id=' . $id . '&camera_id=' . $cameraIds[0], null, false)[0] === 403, 'revoke denies fresh iframe');
$pdo->prepare('INSERT INTO user_groups VALUES(?, ?)')->execute([$users['wall-owner']['id'], $groupId]);
$pdo->prepare('UPDATE dvr_servers SET blocked = 1 WHERE id = ?')->execute([$serverId]);
expect(request('wall-owner', 'GET', '/video-walls/stream?id=' . $id . '&camera_id=' . $cameraIds[0], null, false)[0] === 403, 'blocked server denies stream');
$pdo->prepare('UPDATE dvr_servers SET blocked = 0 WHERE id = ?')->execute([$serverId]);
$edit = request('wall-owner', 'GET', '/video-walls/edit?id=' . $id, null, false)[1];
expect(str_contains($edit, 'HTTP wall'), 'persisted editor');
expect(request('wall-owner', 'GET', '/video-walls?delete=' . $id, null, false)[0] === 200, 'delete confirmation');
expect(request('wall-owner', 'POST', '/video-walls', ['csrf' => $csrf, 'action' => 'delete', 'id' => $id], false)[0] === 422, 'delete requires confirmation');
expect(request('wall-owner', 'POST', '/video-walls', ['csrf' => $csrf, 'action' => 'delete', 'id' => $id, 'confirm_delete' => '1'], false)[0] === 303, 'confirmed delete');
expect(request('wall-owner', 'GET', '/api/portal/v1/video-walls/' . $id)[0] === 404, 'deleted');
echo "video wall HTTP: {$checks} checks passed\n";
