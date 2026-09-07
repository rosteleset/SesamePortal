<?php

// Local browser-test DVR fixture. Never used by public/index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/test-dvr/sample.mp4') {
    header('Content-Type: video/mp4');
    readfile(getenv('SESAME_PORTAL_STATE_DIR') . '/sample.mp4');
    return;
}
if (str_starts_with($path, '/test-dvr/') && str_ends_with($path, '/embed.html')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><style>html,body{margin:0;width:100%;height:100%;background:#000}video{width:100%;height:100%;object-fit:contain}</style></head><body><video src="/test-dvr/sample.mp4" autoplay muted loop playsinline></video></body></html>';
    return;
}
$public = dirname(__DIR__) . '/public';
$file = realpath($public . $path);
if ($path !== '/' && $file && str_starts_with($file, $public . '/') && is_file($file)) return false;
require $public . '/index.php';
