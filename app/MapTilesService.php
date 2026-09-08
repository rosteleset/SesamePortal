<?php

declare(strict_types=1);

namespace SesamePortal;

use RuntimeException;

final class MapTilesService
{
    private const GOOGLE_CREATE_SESSION_URL = 'https://tile.googleapis.com/v1/createSession';
    private const GOOGLE_TILE_URL = 'https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}';
    private const GOOGLE_VIEWPORT_URL = 'https://tile.googleapis.com/tile/v1/viewport';

    public static function googleSessionPayload(string $apiKey, string $language, string $region): array
    {
        $session = self::googleSession($apiKey, $language, $region);
        $query = http_build_query([
            'session' => $session['session'],
            'key' => $apiKey,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'tileUrl' => self::GOOGLE_TILE_URL . '?' . $query,
            'maxZoom' => 22,
            'expiresAt' => gmdate('c', (int)$session['expiry']),
        ];
    }

    public static function googleAttribution(
        string $apiKey,
        string $language,
        string $region,
        float $north,
        float $south,
        float $east,
        float $west,
        int $zoom
    ): string {
        $session = self::googleSession($apiKey, $language, $region);
        $url = self::GOOGLE_VIEWPORT_URL . '?' . http_build_query([
            'session' => $session['session'],
            'key' => $apiKey,
            'zoom' => $zoom,
            'north' => PortalSettings::formatCoordinate($north),
            'south' => PortalSettings::formatCoordinate($south),
            'east' => PortalSettings::formatCoordinate($east),
            'west' => PortalSettings::formatCoordinate($west),
        ], '', '&', PHP_QUERY_RFC3986);
        $payload = self::requestJson($url);

        return trim((string)($payload['copyright'] ?? ''));
    }

    private static function googleSession(string $apiKey, string $language, string $region): array
    {
        if ($apiKey === '') {
            throw new RuntimeException('google_map_api_key_missing');
        }

        $cacheDir = Config::stateDir() . '/cache/map-tiles';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0750, true) && !is_dir($cacheDir)) {
            throw new RuntimeException('map_tile_cache_unavailable');
        }

        $cacheKey = hash('sha256', $apiKey . "\0" . $language . "\0" . $region);
        $cachePath = $cacheDir . '/google-session-' . $cacheKey . '.json';
        $lock = fopen($cachePath . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('map_tile_cache_unavailable');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('map_tile_cache_unavailable');
            }

            $cached = self::readCachedGoogleSession($cachePath);
            if ($cached !== null) {
                return $cached;
            }

            $url = self::GOOGLE_CREATE_SESSION_URL . '?key=' . rawurlencode($apiKey);
            $payload = self::requestJson($url, [
                'mapType' => 'roadmap',
                'language' => $language,
                'region' => $region,
            ]);
            $session = trim((string)($payload['session'] ?? ''));
            $expiry = (int)($payload['expiry'] ?? 0);
            if ($session === '' || $expiry <= time() + 60) {
                throw new RuntimeException('google_map_session_invalid');
            }

            $cached = ['session' => $session, 'expiry' => $expiry];
            if (file_put_contents(
                $cachePath,
                json_encode($cached, JSON_UNESCAPED_SLASHES),
                LOCK_EX
            ) === false) {
                throw new RuntimeException('map_tile_cache_unavailable');
            }
            chmod($cachePath, 0600);
            return $cached;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function readCachedGoogleSession(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) {
            return null;
        }
        $session = trim((string)($payload['session'] ?? ''));
        $expiry = (int)($payload['expiry'] ?? 0);
        if ($session === '' || $expiry <= time() + 300) {
            return null;
        }

        return ['session' => $session, 'expiry' => $expiry];
    }

    private static function requestJson(string $url, ?array $postBody = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('map_provider_request_failed');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($postBody !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($postBody, JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('map_provider_request_failed' . ($error !== '' ? ': ' . $error : ''));
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new RuntimeException('map_provider_response_invalid');
        }
        return $payload;
    }
}
