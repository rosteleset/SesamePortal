<?php

declare(strict_types=1);

namespace SesamePortal;

trait PlaybackUrls
{
    private static function embedUrl(
        array $camera,
        string $token,
        string $back = '',
        string $backLabel = '',
        string $settings = '',
        string $settingsLabel = '',
        bool $dvr = true,
        array $extraQuery = []
    ): string
    {
        if (empty($camera['server_url'])) {
            return '#';
        }

        $query = [
            'dvr' => $dvr ? 'true' : 'false',
            'token' => $token,
        ];
        if ((int)($camera['watermark_enabled'] ?? 0) === 1) {
            $query['screenshot'] = 'false';
        }
        if ($back !== '') {
            $query['back_url'] = self::absolutePortalUrl($back);
            $query['back_label'] = $backLabel !== '' ? $backLabel : self::t('action.back', 'Назад');
        }
        if ($settings !== '') {
            $query['settings_url'] = self::absolutePortalUrl($settings);
            $query['settings_label'] = $settingsLabel !== '' ? $settingsLabel : self::t('settings.title', 'Настройки');
        }

        return rtrim($camera['server_url'], '/') . '/' . rawurlencode($camera['dvr_stream_name']) . '/embed.html?' . http_build_query(array_replace($query, $extraQuery));
    }

    private static function playerUrl(array $camera): string
    {
        $back = self::safeBackPath((string)($_SERVER['REQUEST_URI'] ?? '/'));
        return '/viewer/player?' . http_build_query([
            'id' => (int)$camera['id'],
            'back' => $back,
        ]);
    }

    private static function safeBackPath(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parts = parse_url($path);
            $path = (string)($parts['path'] ?? '/');
            if (!empty($parts['query'])) {
                $path .= '?' . $parts['query'];
            }
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return parse_url($path, PHP_URL_PATH) === '/viewer/player' ? '/' : $path;
    }

    private static function safeLocalPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\r") || str_contains($path, "\n")) {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parts = parse_url($path);
            $path = (string)($parts['path'] ?? '/');
            if (!empty($parts['query'])) {
                $path .= '?' . $parts['query'];
            }
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '';
        }

        return $path;
    }

    private static function absolutePortalUrl(string $path): string
    {
        $base = trim((string)Config::get('base_url', ''));
        if ($base === '') {
            $scheme = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
            if ($scheme === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            }
            $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
            if ($host === '') {
                return $path;
            }
            $base = $scheme . '://' . $host;
        }

        return rtrim($base, '/') . $path;
    }

    private static function previewUrl(array $camera): string
    {
        if (empty($camera['server_url']) || empty($camera['dvr_stream_name'])) {
            return '';
        }

        return '/viewer/preview?' . http_build_query(['id' => (int)$camera['id']]);
    }

    private static function externalPreviewUrl(array $camera, string $token, string $cacheBust = ''): string
    {
        $query = ['token' => $token];
        if ($cacheBust !== '') {
            $query['_'] = $cacheBust;
        }

        return rtrim((string)$camera['server_url'], '/') . '/' . rawurlencode((string)$camera['dvr_stream_name']) . '/preview.mp4?' . http_build_query($query);
    }
}
