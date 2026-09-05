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

if (str_ends_with($path, '/motion_events.json')) {
    $parts = explode('/', trim($path, '/'));
    $stream = urldecode($parts[0]);
    $now = time();
    echo json_encode([
        'bucketSeconds' => 0,
        'devices' => [[
            'deviceId' => 'mock-device-' . $stream,
            'deviceName' => $stream,
            'sourceStreams' => [$stream],
        ]],
        'from' => $now - 86400,
        'to' => $now,
        'intervals' => [
            ['deviceId' => 'mock-device-' . $stream, 'from' => $now - 3600, 'to' => $now - 3600 + 15, 'duration' => 15, 'state' => 'motion'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

if (preg_match('#^/[^/]+/([0-9]+)-preview\.jpg$#', $path)) {
    header('Content-Type: image/jpeg');
    echo "SmokeJpeg";
    return;
}

http_response_code(404);
echo json_encode(['error' => 'not_found']);
