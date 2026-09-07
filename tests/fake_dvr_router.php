<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$token = (string)($_SERVER['HTTP_X_MANAGEMENT_TOKEN'] ?? '');
$query = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json; charset=utf-8');

if ($token !== getenv('MGMT_IMPORT')) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    return;
}

$stateFile = getenv('FAKE_DVR_STATE') ?: (sys_get_temp_dir() . '/fake_dvr_router_state.json');
$state = ['streams' => [], 'onvif' => [], 'log' => []];
if (is_file($stateFile)) {
    $loaded = json_decode((string)file_get_contents($stateFile), true);
    if (is_array($loaded)) {
        $state = array_merge($state, $loaded);
    }
}

$persist = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};
$log = static function (string $entry) use (&$state, $persist): void {
    $state['log'][] = $entry;
    if (count($state['log']) > 200) {
        $state['log'] = array_slice($state['log'], -200);
    }
    $persist();
};
$body = '';
$payload = [];
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $body = (string)$raw;
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if ($path === '/api/streams') {
    $log($method . ' ' . $path);
    if ($method === 'GET') {
        $streams = array_values($state['streams']);
        $seed = [
            ['name' => 'already-portal', 'displayName' => 'ZZ Already in Portal', 'source' => 'rtsp://example.invalid/already', 'sourceType' => 'direct', 'enabled' => true, 'archiveEnabled' => true, 'retentionDays' => '1d'],
            ['name' => 'import-cam-1', 'displayName' => 'ZZ Imported Entrance', 'source' => 'rtsp://example.invalid/import-1', 'sourceType' => 'direct', 'enabled' => true, 'archiveEnabled' => true, 'retentionDays' => '14d', 'webrtcFastStart' => true, 'audioCodec' => 'aac'],
            ['name' => 'import-cam-2', 'displayName' => 'ZZ Imported Yard', 'source' => 'push://import-cam-2', 'sourceType' => 'push', 'enabled' => false, 'archiveEnabled' => false, 'retentionDays' => '3d', 'timelapseEnabled' => true, 'timelapseFramesPerHour' => 120, 'timelapseRetentionDays' => '30d', 'timelapsePlaybackFps' => 20],
            ['name' => 'legacy stream name', 'displayName' => 'Legacy invalid name', 'source' => 'rtsp://example.invalid/legacy', 'sourceType' => 'direct', 'enabled' => true, 'archiveEnabled' => true],
        ];
        $names = array_column($streams, 'name');
        foreach ($seed as $s) {
            if (!in_array($s['name'], $names, true)) {
                $streams[] = $s;
            }
        }
        echo json_encode(['streams' => $streams], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($method === 'POST') {
        $name = (string)($payload['name'] ?? '');
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'name_required']);
            return;
        }
        $state['streams'][$name] = $payload;
        $log('POST stream ' . $name);
        $persist();
        http_response_code(201);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

if (preg_match('#^/api/streams/([^/]+)$#', $path, $m)) {
    $name = urldecode($m[1]);
    $log($method . ' ' . $path);
    if ($method === 'GET') {
        if (isset($state['streams'][$name])) {
            echo json_encode($state['streams'][$name], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    if ($method === 'PUT') {
        $payload['name'] = $name;
        $state['streams'][$name] = $payload;
        $log('PUT stream ' . $name);
        $persist();
        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($method === 'DELETE') {
        if (isset($state['streams'][$name])) {
            unset($state['streams'][$name]);
            $log('DELETE stream ' . $name);
            $persist();
            http_response_code(204);
            echo '';
            return;
        }
        $log('DELETE stream ' . $name . ' (absent)');
        $persist();
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

if ($path === '/api/onvif/devices') {
    $log($method . ' ' . $path);
    if ($method === 'GET') {
        $devices = array_values($state['onvif']);
        echo json_encode(['devices' => $devices], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($method === 'POST') {
        $id = (string)($payload['id'] ?? '');
        if ($id === '') {
            http_response_code(422);
            echo json_encode(['error' => 'id_required']);
            return;
        }
        $state['onvif'][$id] = $payload;
        $log('POST onvif ' . $id);
        $persist();
        http_response_code(201);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

if (preg_match('#^/api/onvif/devices/([^/]+)$#', $path, $m)) {
    $id = urldecode($m[1]);
    $log($method . ' ' . $path);
    if ($method === 'GET') {
        if (isset($state['onvif'][$id])) {
            echo json_encode($state['onvif'][$id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    if ($method === 'PUT') {
        $payload['id'] = $id;
        $state['onvif'][$id] = $payload;
        $log('PUT onvif ' . $id);
        $persist();
        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($method === 'DELETE') {
        if (isset($state['onvif'][$id])) {
            unset($state['onvif'][$id]);
            $log('DELETE onvif ' . $id);
            $persist();
            http_response_code(204);
            echo '';
            return;
        }
        $log('DELETE onvif ' . $id . ' (absent)');
        $persist();
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
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
