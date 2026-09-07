<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Portal.php';

use SesamePortal\DB;
use SesamePortal\Cli;
use SesamePortal\I18n;
use SesamePortal\Repo;
use SesamePortal\Util;
use SesamePortal\VideoWalls;
use SesamePortal\VideoWallTranslations;

// This test never opens the configured database.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
(new ReflectionProperty(DB::class, 'pdo'))->setValue(null, $pdo);
(new ReflectionProperty(DB::class, 'driver'))->setValue(null, 'sqlite');
DB::migrate();
DB::migrate();
$count = 0;
function check(bool $ok, string $message): void {
    global $count;
    $count++;
    if (!$ok) throw new RuntimeException($message);
}
function rejects(callable $operation, string $reason): void {
    try { $operation(); } catch (InvalidArgumentException $error) {
        check($error->getMessage() === $reason, 'Unexpected reason: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $reason);
}
$now = Util::now();
foreach ([['admin', 'admin'], ['owner', 'user'], ['other', 'user']] as [$login, $role]) {
    $pdo->prepare('INSERT INTO users(login, password_hash, role, created_at) VALUES(?, ?, ?, ?)')->execute([$login, 'unused', $role, $now]);
}
$admin = $pdo->query('SELECT * FROM users WHERE id = 1')->fetch();
$owner = $pdo->query('SELECT * FROM users WHERE id = 2')->fetch();
$other = $pdo->query('SELECT * FROM users WHERE id = 3')->fetch();
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, created_at) VALUES(?, ?, ?)')->execute(['Local fixture', 'https://dvr.example.invalid', $now]);
foreach ([['Parent', null], ['Child', 1], ['Private', null]] as [$name, $parent]) {
    $pdo->prepare('INSERT INTO portal_groups(name, parent_group_id, created_at) VALUES(?, ?, ?)')->execute([$name, $parent, $now]);
}
$pdo->exec('INSERT INTO user_groups VALUES(2, 1)');
for ($i = 1; $i <= 3; $i++) {
    $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, dvr_stream_name, created_at, updated_at) VALUES(?, ?, 1, ?, ?, ?)')->execute(['Camera ' . $i, 'rtsp://example.invalid/' . $i, 'cam-' . $i, $now, $now]);
    $pdo->prepare('INSERT INTO camera_groups VALUES(?, ?)')->execute([$i, $i === 3 ? 3 : 2]);
}
$wall = VideoWalls::save($owner, null, ['name' => 'Entrance', 'rows' => 1, 'columns' => 2, 'cameraIds' => [2, 1]]);
check(VideoWalls::ids($wall) === [2, 1], 'Order must be preserved');
check(VideoWalls::find($other, (int)$wall['id']) === null, 'Foreign wall must be private');
check(VideoWalls::find($admin, (int)$wall['id']) !== null, 'Admin must see wall');
check(VideoWalls::page($owner)['total'] === 1 && VideoWalls::page($other)['total'] === 0, 'List scope');
check(count(VideoWalls::cameras($owner, [1, 2, 3])) === 2, 'Inherited access');
foreach ([0, 7, true, [], null, '2.5'] as $rows) {
    rejects(fn() => VideoWalls::save($owner, $wall, ['rows' => $rows]), 'wall.invalidGrid');
}
foreach ([[], [1, 1], [1, 2, 3], ['x'], [false], null] as $ids) {
    rejects(fn() => VideoWalls::save($owner, $wall, ['cameraIds' => $ids]), 'wall.invalidSelection');
}
foreach (['', str_repeat('x', 256), null, []] as $name) {
    rejects(fn() => VideoWalls::save($owner, $wall, ['name' => $name]), 'wall.invalidName');
}
rejects(fn() => VideoWalls::save($owner, $wall, ['cameraIds' => [3]]), 'wall.cameraUnavailable');
rejects(fn() => VideoWalls::save($admin, $wall, ['cameraIds' => [3]]), 'wall.cameraUnavailable');
$pdo->exec('UPDATE portal_groups SET blocked = 1 WHERE id = 1');
check(VideoWalls::cameras($owner, [1, 2]) === [], 'Revoked parent must deny saved cameras');
rejects(fn() => VideoWalls::save($owner, $wall, ['name' => 'Changed']), 'wall.cameraUnavailable');
$pdo->exec('UPDATE portal_groups SET blocked = 0 WHERE id = 1');
$pdo->exec('UPDATE dvr_servers SET blocked = 1');
check(VideoWalls::cameras($owner, [1, 2]) === [], 'Blocked DVR must not get embeds');
$pdo->exec('UPDATE dvr_servers SET blocked = 0');
$pdo->exec('DELETE FROM cameras WHERE id = 2');
check(count(VideoWalls::cameras($owner, VideoWalls::ids($wall))) === 1, 'Deleted camera omitted, saved slot retained');
$wall = VideoWalls::save($admin, $wall, ['name' => 'Edited by admin', 'cameraIds' => [1]]);
check((int)$wall['user_id'] === 2, 'Admin edit must preserve owner');
VideoWalls::delete($owner, $wall);
check(VideoWalls::page($admin)['total'] === 0, 'Delete');
check((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'video_wall.%'")->fetchColumn() === 3, 'Create/update/delete audit');
$wall = VideoWalls::save($owner, null, ['name' => 'Cascade', 'cameraIds' => [1]]);
$backup = tempnam(sys_get_temp_dir(), 'portal-wall-backup-');
try {
    (new ReflectionMethod(Cli::class, 'backup'))->invoke(null, $backup);
    VideoWalls::delete($owner, $wall);
    (new ReflectionMethod(Cli::class, 'restore'))->invoke(null, $backup);
    check(VideoWalls::ids(VideoWalls::find($owner, (int)$wall['id'])) === [1], 'Backup restores wall and order');
    $next = VideoWalls::save($owner, null, ['name' => 'After restore', 'cameraIds' => [1]]);
    check((int)$next['id'] > (int)$wall['id'], 'Auto ID after restore');
    $legacy = json_decode((string)file_get_contents($backup), true);
    unset($legacy['tables']['video_walls']);
    file_put_contents($backup, json_encode($legacy));
    (new ReflectionMethod(Cli::class, 'restore'))->invoke(null, $backup);
    check(VideoWalls::page($admin)['total'] === 0, 'Old backups without video walls remain valid');
} finally {
    unlink($backup);
}
VideoWalls::save($owner, null, ['name' => 'Cascade', 'cameraIds' => [1]]);
$pdo->exec('DELETE FROM users WHERE id = 2');
check(VideoWalls::page($admin)['total'] === 0, 'Owner deletion cascades');
$messages = (new ReflectionMethod(I18n::class, 'messages'))->invoke(null, false);
foreach (VideoWallTranslations::messages() as $locale => $items) {
    check(count($items) === 46, 'Locale parity ' . $locale);
    foreach ($items as $key => $text) check(($messages[$locale][$key] ?? '') === $text, 'Translation ' . $locale . '/' . $key);
}
echo "video walls: {$count} checks passed\n";
