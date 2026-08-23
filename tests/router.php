<?php

declare(strict_types=1);

// PHP built-in server router: serve existing static files as-is, otherwise
// fall through to the application front controller. This makes dotted path
// segments (e.g. /api/portal/v1/cameras/camera.test-1) reach the app instead
// of being treated as static files and returning 404.

$docRoot = __DIR__ . '/../public';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = $docRoot . $uri;

if ($uri !== '/' && is_file($file)) {
    return false; // let the built-in server serve the static file
}

require $docRoot . '/index.php';