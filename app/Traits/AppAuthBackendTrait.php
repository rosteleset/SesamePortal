<?php

declare(strict_types=1);

namespace SesamePortal;

trait AppAuthBackendTrait
{
    private static function authBackend(): void
    {
        $token = self::playbackTokenFromAuthRequest();
        $cameraName = self::cameraNameFromAuthRequest();

        if ($token !== '' && $cameraName !== '') {
            $cameraFromToken = TokenService::cameraByPermanentToken($token, $cameraName);
            if ($cameraFromToken) {
                Audit::logForUser(null, 'camera.permanent_token.auth', 'camera_id=' . (int)$cameraFromToken['id'] . ' stream=' . Audit::cleanValue($cameraName) . ' ip=' . Audit::clientIp());
                echo "ok\n";
                return;
            }
        }

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
        foreach ($extraQuery as $key => $value) {
            $query[(string)$key] = (string)$value;
        }

        return rtrim($camera['server_url'], '/') . '/' . rawurlencode($camera['dvr_stream_name']) . '/embed.html?' . http_build_query($query);
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

        return rtrim((string)$camera['server_url'], '/') . '/' . rawurlencode((string)$camera['dvr_stream_name']) . '/preview.jpg?' . http_build_query($query);
    }

    private static function cameraStreamUnavailable(array $camera): bool
    {
        $metrics = json_decode((string)($camera['server_metrics_json'] ?? ''), true);
        if (!is_array($metrics)) {
            return false;
        }

        $streams = $metrics['streams']['streams'] ?? null;
        if (!is_array($streams)) {
            return false;
        }

        $streamName = (string)($camera['dvr_stream_name'] ?: $camera['name']);
        if ($streamName === '') {
            return false;
        }

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $name = (string)($stream['name'] ?? '');
            $displayName = (string)($stream['displayName'] ?? $stream['title'] ?? '');
            if ($name === $streamName || $name === (string)$camera['name'] || $displayName === (string)$camera['name']) {
                return self::streamMetricUnavailable($stream);
            }
        }

        return false;
    }

    private static function mapStreamUnavailableByServer(array $cameras): array
    {
        $serverIds = [];
        foreach ($cameras as $camera) {
            if (($camera['server_id'] ?? null) !== null) {
                $serverIds[] = (int)$camera['server_id'];
            }
        }

        $metricsByServer = Repo::serverMetricsJsonByIds($serverIds);
        $result = [];
        foreach ($metricsByServer as $serverId => $json) {
            $metrics = json_decode($json, true);
            $streams = is_array($metrics) ? ($metrics['streams']['streams'] ?? null) : null;
            if (!is_array($streams)) {
                continue;
            }

            $lookup = [];
            foreach ($streams as $stream) {
                if (!is_array($stream)) {
                    continue;
                }
                $unavailable = self::streamMetricUnavailable($stream);
                foreach ([(string)($stream['name'] ?? ''), (string)($stream['displayName'] ?? $stream['title'] ?? '')] as $key) {
                    if ($key !== '' && !array_key_exists($key, $lookup)) {
                        $lookup[$key] = $unavailable;
                    }
                }
            }

            if ($lookup) {
                $result[(int)$serverId] = $lookup;
            }
        }

        return $result;
    }

    private static function cameraStreamUnavailableFromMapMetrics(array $camera, array $streamUnavailableByServer): bool
    {
        $serverId = (int)($camera['server_id'] ?? 0);
        $lookup = $serverId > 0 ? ($streamUnavailableByServer[$serverId] ?? null) : null;
        if (!is_array($lookup)) {
            return false;
        }

        $streamName = (string)($camera['dvr_stream_name'] ?: $camera['name']);
        foreach ([$streamName, (string)$camera['name']] as $key) {
            if ($key !== '' && array_key_exists($key, $lookup)) {
                return (bool)$lookup[$key];
            }
        }

        return false;
    }

    private static function streamMetricUnavailable(array $stream): bool
    {
        if (array_key_exists('running', $stream)) {
            return !self::truthyMetricValue($stream['running']);
        }

        $problemCode = $stream['archiveStatus']['problem']['code'] ?? null;
        if ($problemCode === 'ingest_not_running') {
            return true;
        }

        if (array_key_exists('runtimeDesired', $stream) && !self::truthyMetricValue($stream['runtimeDesired'])) {
            return true;
        }

        return false;
    }

    private static function truthyMetricValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'running'], true);
        }

        return false;
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

    private static function portalUpdateBanner(): void
    {
        $status = PortalUpdateService::cachedStatus();
        if (empty($status['updateAvailable'])) {
            return;
        }

        $current = is_array($status['current'] ?? null) ? $status['current'] : [];
        $latest = is_array($status['latest'] ?? null) ? $status['latest'] : [];
        echo '<section class="portal-update-banner">';
        echo '<div><strong>' . Util::h(self::t('settings.updateAvailable', 'Доступно обновление')) . '</strong>';
        echo '<span>' . Util::h(self::t('settings.currentVersion', 'Текущая версия')) . ': ' . Util::h(self::portalUpdateVersionLabel($current)) . ' · ';
        echo Util::h(self::t('settings.githubVersion', 'Доступная версия на GitHub')) . ': ' . Util::h(self::portalUpdateVersionLabel($latest)) . '</span></div>';
        echo '<div class="portal-update-banner-actions">';
        if ((bool)($status['toolInstalled'] ?? false)) {
            self::smallPost(
                '/admin/settings',
                ['action' => 'run_update'],
                self::t('settings.installUpdate', 'Обновить Portal'),
                'primary',
                self::t('settings.updateConfirm', 'Обновить код Portal из GitHub и выполнить миграции?')
            );
        }
        echo '<a class="btn" href="/admin/settings">' . self::t('nav.settings', 'Настройки') . '</a></div>';
        echo '</section>';
    }
}
