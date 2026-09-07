<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Portal.php';

use SesamePortal\DB;
use SesamePortal\Util;

// Only initialize the new temporary database created by the browser test.
$state = getenv('SESAME_PORTAL_STATE_DIR');
if (!$state || !str_contains(basename($state), 'portal-wall-browser-') || file_exists($state . '/portal.sqlite')) {
    throw new RuntimeException('Expected a new isolated browser-test state directory');
}
DB::migrate();
$pdo = DB::pdo();
$now = Util::now();
$pdo->prepare('INSERT INTO users(login, password_hash, role, created_at) VALUES(?, ?, ?, ?)')
    ->execute(['wall-demo', password_hash('wall-demo123', PASSWORD_DEFAULT), 'admin', $now]);
$pdo->prepare('INSERT INTO dvr_servers(name, base_url, created_at) VALUES(?, ?, ?)')
    ->execute(['Local DVR fixture', $argv[2] ?? ($argv[1] . '/test-dvr'), $now]);
foreach ([['Entrance', null], ['Parking', 1], ['Courtyard', null]] as [$name, $parent]) {
    $pdo->prepare('INSERT INTO portal_groups(name, parent_group_id, created_at) VALUES(?, ?, ?)')->execute([$name, $parent, $now]);
}
for ($id = 1; $id <= 8; $id++) {
    $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, dvr_stream_name, watermark_enabled, created_at, updated_at) VALUES(?, ?, 1, ?, 1, ?, ?)')
        ->execute(['Camera ' . $id, 'rtsp://example.invalid/' . $id, 'demo-' . $id, $now, $now]);
    $pdo->prepare('INSERT INTO camera_groups VALUES(?, ?)')->execute([$id, $id <= 4 ? 2 : 3]);
}
