<?php

declare(strict_types=1);

namespace SesamePortal;

final class Csrf
{
    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = Util::randomToken();
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf" value="' . Util::h(self::token()) . '">';
    }

    public static function verify(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        $path = Util::path();
        if ($path === '/login') {
            return;
        }
        if ($path === '/api/portal/v1' || str_starts_with($path, '/api/portal/v1/')) {
            return;
        }

        Auth::start();
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            http_response_code(419);
            echo 'CSRF token mismatch';
            exit;
        }
    }
}
