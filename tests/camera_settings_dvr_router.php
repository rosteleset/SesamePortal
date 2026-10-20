<?php

declare(strict_types=1);

// Isolated HTTP fixture; started only by camera_settings.php on localhost.
$file = getenv('SESAME_PORTAL_DVR_SETTINGS_FIXTURE');
$state = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$state['requests'][] = ['method' => $method, 'path' => $path, 'body' => $input];
header('Content-Type: application/json');
$status = $state['errors'][$method . ' ' . $path] ?? 200;
$result = ['error' => 'fixture_error'];
if (($_SERVER['HTTP_X_MANAGEMENT_TOKEN'] ?? '') !== 'settings-test-secret') {
    $status = 401;
} elseif ($status === 200 && preg_match('~^/(one|two)/api/streams(?:/([^/]+))?$~', $path, $matches)) {
    $server = $matches[1];
    $name = isset($matches[2]) ? rawurldecode($matches[2]) : ($input['name'] ?? '');
    $stream = $state['servers'][$server][$name] ?? null;
    if ($method === 'GET' && !isset($matches[2])) {
        $rows = array_values($state['servers'][$server] ?? []);
        $result = !empty($state['wrappedLists'][$server]) ? ['streams' => $rows] : $rows;
    } elseif ($method === 'POST' && !isset($matches[2])) {
        $state['servers'][$server][$name] = $input;
        $status = 201;
        $result = $input;
    } elseif (!$stream) {
        $status = 404;
    } elseif ($method === 'PUT') {
        if (is_array($input['push'] ?? null)) {
            $input['push'] = array_replace($stream['push'] ?? [], $input['push']);
        }
        $state['servers'][$server][$name] = array_replace($stream, $input);
        $result = $state['servers'][$server][$name];
    } else {
        $result = $stream;
    }
} elseif ($status === 200) {
    $status = 404;
}
file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
http_response_code($status);
echo json_encode($state['responses'][$method . ' ' . $path] ?? $result, JSON_THROW_ON_ERROR);
