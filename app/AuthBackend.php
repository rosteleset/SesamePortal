<?php

declare(strict_types=1);

namespace SesamePortal;

trait AuthBackend
{
    private static function authBackend(): void
    {
        $token = self::playbackTokenFromAuthRequest();
        $cameraName = self::cameraNameFromAuthRequest();
        $staticUser = TokenService::userByStaticToken($token);
        $user = $staticUser ?: TokenService::userByToken($token);
        if (!$user || $cameraName === '') {
            http_response_code(403);
            echo "denied\n";
            return;
        }

        $stmt = DB::pdo()->prepare('SELECT id, name, dvr_stream_name FROM cameras WHERE (dvr_stream_name = ? OR name = ?) AND blocked = 0 LIMIT 1');
        $stmt->execute([$cameraName, $cameraName]);
        $camera = $stmt->fetch();
        if (!$camera && !self::authBackendAllowsUnknownCamera($staticUser)) {
            http_response_code(403);
            echo "denied\n";
            return;
        }

        if ($camera && !Repo::cameraAllowedForUser($user, (int)$camera['id'])) {
            http_response_code(403);
            echo "denied\n";
            return;
        }

        if (self::userArchiveHidden($user)) {
            $restriction = self::authBackendArchiveRestriction();
            if ($restriction === 'deny') {
                http_response_code(403);
                echo "archive_denied\n";
                return;
            }
            if ($restriction === 'capability') {
                self::authBackendCapabilityResponse(false);
                return;
            }
        }

        $audit = $camera ? self::authBackendAuditEvent($camera) : null;
        if ($audit !== null) {
            Audit::logForUser((int)$user['id'], $audit['action'], $audit['details']);
        }

        echo "ok\n";
    }

    private static function authBackendAllowsUnknownCamera(?array $staticUser): bool
    {
        return $staticUser !== null && ($staticUser['role'] ?? '') === 'admin';
    }

    private static function userArchiveHidden(array $user): bool
    {
        return (int)($user['hide_archive'] ?? 0) === 1;
    }

    private static function authBackendArchiveRestriction(): string
    {
        $proto = strtolower(self::usableAuthValue($_GET['proto'] ?? ''));
        $dvr = strtolower(self::usableAuthValue($_GET['dvr'] ?? ''));

        foreach (self::authRequestTargets() as $target) {
            if (self::authTargetIsArchiveMedia($target)) {
                return 'deny';
            }
            if (self::authTargetIsArchiveMetadata($target)) {
                return 'capability';
            }
        }

        if ($proto === 'player') {
            return 'capability';
        }

        if ($dvr === 'true' && in_array($proto, ['hls', 'failover'], true)) {
            return 'deny';
        }

        return 'allow';
    }

    private static function authTargetIsArchiveMetadata(string $target): bool
    {
        $file = basename((string)(parse_url($target, PHP_URL_PATH) ?: ''));
        return in_array($file, [
            'playback_info.json',
            'recording_status.json',
            'timeline_ranges.json',
            'motion_events.json',
            'timelapse_segments.json',
        ], true);
    }

    private static function authTargetIsArchiveMedia(string $target): bool
    {
        $path = '/' . ltrim((string)(parse_url($target, PHP_URL_PATH) ?: ''), '/');
        $file = basename($path);
        if (preg_match('/^archive-\d+-\d+\.mp4$/', $file)) {
            return true;
        }
        if (preg_match('~/dvr(?:/|$)~', $path)) {
            return true;
        }
        if (preg_match('/^(index|motion|timelapse)-.+\.m3u8$/', $file)) {
            return true;
        }
        if (in_array($file, ['dvr.m3u8', 'motion_dvr.m3u8', 'timelapse_dvr.m3u8'], true)) {
            return true;
        }
        return false;
    }

    private static function authBackendCapabilityResponse(bool $allowArchive): void
    {
        $payload = $allowArchive ? [] : ['allowed_dvr_ranges' => []];

        if (!$allowArchive && self::authBackendPlayerRequest()) {
            $overlays = self::hiddenArchivePlayerOverlays();
            if ($overlays !== []) {
                $payload['playerOverlays'] = $overlays;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    private static function authBackendPlayerRequest(): bool
    {
        return strtolower(self::usableAuthValue($_GET['proto'] ?? '')) === 'player';
    }

    private static function hiddenArchivePlayerOverlays(): array
    {
        $configured = Config::get('hidden_archive_player_overlays', []);
        if (!is_array($configured)) {
            return [];
        }

        return array_values(array_filter($configured, static fn(mixed $overlay): bool => is_array($overlay)));
    }

    private static function authBackendAuditEvent(array $camera): ?array
    {
        $target = self::authRequestTarget();
        $path = (string)(parse_url($target, PHP_URL_PATH) ?: '');
        $file = basename($path);
        if (!preg_match('/^archive-(\d+)-(\d+)\.mp4$/', $file, $match)) {
            return null;
        }

        $stream = (string)($camera['dvr_stream_name'] ?? $camera['name'] ?? '');
        return [
            'action' => 'archive.download',
            'details' => implode(' ', [
                'camera_id=' . (int)$camera['id'],
                'stream=' . Audit::cleanValue($stream),
                'from=' . $match[1],
                'duration=' . $match[2],
                'method=' . self::authRequestMethod(),
                'path=' . Audit::cleanValue($path, 240),
                'ip=' . Audit::clientIp(),
            ]),
        ];
    }

    private static function playbackTokenFromAuthRequest(): string
    {
        foreach (['token', 'auth_token', 'playback_token'] as $key) {
            $token = self::usableAuthValue($_GET[$key] ?? '');
            if ($token !== '') {
                return $token;
            }
        }

        $qs = self::usableAuthValue($_GET['qs'] ?? '');
        if ($qs !== '') {
            parse_str($qs, $params);
            foreach (['token', 'auth_token', 'playback_token'] as $key) {
                $token = self::usableAuthValue($params[$key] ?? '');
                if ($token !== '') {
                    return $token;
                }
            }
        }

        foreach (self::authRequestTargets() as $target) {
            $query = parse_url($target, PHP_URL_QUERY);
            if (!$query) {
                continue;
            }
            parse_str($query, $params);
            foreach (['token', 'auth_token', 'playback_token'] as $tokenKey) {
                $token = self::usableAuthValue($params[$tokenKey] ?? '');
                if ($token !== '') {
                    return $token;
                }
            }
        }

        return '';
    }

    private static function usableAuthValue(mixed $value): string
    {
        $value = trim((string)$value);
        return $value === '' || $value === 'NonAvailable' ? '' : $value;
    }

    private static function cameraNameFromAuthRequest(): string
    {
        foreach (['camera', 'stream', 'name'] as $key) {
            if (!empty($_GET[$key])) {
                return basename((string)$_GET[$key]);
            }
        }

        foreach (self::authRequestTargets() as $target) {
            $path = trim((string)parse_url($target, PHP_URL_PATH), '/');
            if ($path !== '') {
                return basename(explode('/', $path)[0] ?? '');
            }
        }

        return '';
    }

    private static function authRequestTarget(): string
    {
        return self::authRequestTargets()[0] ?? '';
    }

    private static function authRequestTargets(): array
    {
        $values = [];
        foreach (['uri', 'path', 'request_uri', 'original_uri'] as $key) {
            if (!empty($_GET[$key])) {
                $values[] = (string)$_GET[$key];
            }
        }
        foreach (['HTTP_X_ORIGINAL_URI', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_FORWARDED_URI', 'HTTP_X_REQUEST_URI'] as $key) {
            if (!empty($_SERVER[$key])) {
                $values[] = (string)$_SERVER[$key];
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $values), static fn(string $value): bool => $value !== '')));
    }

    private static function authRequestMethod(): string
    {
        $method = (string)(
            $_GET['method']
            ?? $_GET['request_method']
            ?? $_SERVER['HTTP_X_ORIGINAL_METHOD']
            ?? $_SERVER['HTTP_X_FORWARDED_METHOD']
            ?? 'GET'
        );
        $method = strtoupper(preg_replace('/[^A-Z]/i', '', $method) ?: 'GET');
        return substr($method, 0, 12);
    }
}
