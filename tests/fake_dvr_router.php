<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$token = (string)($_SERVER['HTTP_X_MANAGEMENT_TOKEN'] ?? '');

header('Content-Type: application/json; charset=utf-8');

if ($token !== 'import-management-secret') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    return;
}

if ($path === '/api/streams') {
    echo json_encode([
        'streams' => [
            [
                'name' => 'already-portal',
                'displayName' => 'ZZ Already in Portal',
                'source' => 'rtsp://example.invalid/already',
                'sourceType' => 'direct',
                'enabled' => true,
                'archiveEnabled' => true,
                'retentionDays' => '1d',
            ],
            [
                'name' => 'import-cam-1',
                'displayName' => 'ZZ Imported Entrance',
                'source' => 'rtsp://example.invalid/import-1',
                'sourceType' => 'direct',
                'enabled' => true,
                'archiveEnabled' => true,
                'retentionDays' => '14d',
                'webrtcFastStart' => true,
                'audioCodec' => 'aac',
            ],
            [
                'name' => 'import-cam-2',
                'displayName' => 'ZZ Imported Yard',
                'source' => 'push://import-cam-2',
                'sourceType' => 'push',
                'enabled' => false,
                'archiveEnabled' => false,
                'retentionDays' => '3d',
                'timelapseEnabled' => true,
                'timelapseFramesPerHour' => 120,
                'timelapseRetentionDays' => '30d',
                'timelapsePlaybackFps' => 20,
            ],
            [
                'name' => 'legacy stream name',
                'displayName' => 'Legacy invalid name',
                'source' => 'rtsp://example.invalid/legacy',
                'sourceType' => 'direct',
                'enabled' => true,
                'archiveEnabled' => true,
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'not_found']);
