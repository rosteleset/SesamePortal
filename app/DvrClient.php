<?php

declare(strict_types=1);

namespace SesamePortal;

final class DvrClient
{
    public static function syncCamera(int $cameraId): array
    {
        $camera = Repo::camera($cameraId);
        if (!$camera || !$camera['server_id']) {
            return self::storeCameraSync($cameraId, false, 'No SesameDVR server selected');
        }

        $server = Repo::server((int)$camera['server_id']);
        if (!$server || (int)$server['blocked'] === 1) {
            return self::storeCameraSync($cameraId, false, 'SesameDVR server is unavailable or blocked');
        }

        $controlMode = (string)($camera['dvr_control_mode'] ?? 'managed');
        if ($controlMode === 'read_only') {
            return self::storeCameraSync($cameraId, true, I18n::t('cameras.readOnlySyncSkipped', 'Read-only mode: DVR management skipped'));
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        if ($token === '') {
            return self::storeCameraSync($cameraId, false, 'SesameDVR management token is missing');
        }

        $name = trim((string)($camera['dvr_stream_name'] ?: $camera['name']));
        $displayName = trim((string)($camera['name'] ?? ''));
        $displayName = $displayName !== '' ? $displayName : $name;
        if (!Util::isDvrStreamName($name)) {
            return self::storeCameraSync($cameraId, false, I18n::t('cameras.invalidStreamName', 'Technical stream name must start with a Latin letter or digit and can contain only Latin letters, digits, dot, hyphen, and underscore, up to 128 characters.'));
        }

        if ($controlMode === 'edge_agent') {
            $agentId = trim((string)($camera['agent_id'] ?? ''));
            $agentCameraId = trim((string)($camera['agent_camera_id'] ?? ''));
            if ($name === '' || $agentId === '' || $agentCameraId === '') {
                return self::storeCameraSync($cameraId, false, I18n::t('cameras.agentRequired', 'Edge-agent mode requires server, Agent ID, and Agent camera ID'));
            }

            $payload = [
                'name' => $name,
                'displayName' => $displayName,
                'sourceType' => 'push',
                'source' => 'push://' . $name,
                'enabled' => ((int)$camera['blocked'] === 0),
                'retentionDays' => $camera['retention_days'],
                'archiveEnabled' => (int)($camera['archive_enabled'] ?? 1) === 1,
                'authMode' => 'authBackend',
                'push' => [
                    'transport' => 'rtmp',
                    'publisherKind' => 'agent',
                    'streamName' => $name,
                    'requested' => true,
                    'agentId' => $agentId,
                    'agentCameraId' => $agentCameraId,
                    'onvifEventsRequested' => (int)($camera['onvif_events_requested'] ?? 0) === 1,
                ],
            ];
        } else {
            $existing = self::fetchStream($server, $token, $name);
            if (is_array($existing) && self::streamUsesAgentPublisher($existing)) {
                return self::storeCameraSync($cameraId, false, I18n::t('cameras.agentOverwriteBlocked', 'This DVR stream is already managed by Edge Agent. Switch the Portal camera to Edge Agent or read-only mode to avoid overwriting push configuration.'));
            }

            $payload = [
                'name' => $name,
                'displayName' => $displayName,
                'sourceType' => 'direct',
                'source' => $camera['source_url'],
                'push' => null,
                'enabled' => ((int)$camera['blocked'] === 0),
                'retentionDays' => $camera['retention_days'],
                'archiveEnabled' => (int)($camera['archive_enabled'] ?? 1) === 1,
                'authMode' => 'authBackend',
            ];
        }
        $payload = array_merge($payload, self::cameraDvrOptionsPayload($camera));

        $base = rtrim($server['base_url'], '/');
        $endpoint = $base . '/api/streams/' . rawurlencode($name);
        $result = self::request('PUT', $endpoint, $token, $payload);
        if ($result['status'] === 404) {
            $endpoint = $base . '/api/streams';
            $result = self::request('POST', $endpoint, $token, $payload);
        }

        $ok = $result['status'] >= 200 && $result['status'] < 300;
        $message = self::responseSummary($result, $endpoint);

        if ($ok && $controlMode === 'managed' && trim((string)($camera['onvif_host'] ?? '')) !== '') {
            $onvifPayload = [
                'id' => $name,
                'name' => $displayName,
                'host' => trim((string)$camera['onvif_host']),
                'port' => max(1, min(65535, (int)($camera['onvif_port'] ?: 80))),
                'username' => trim((string)($camera['onvif_username'] ?? '')),
                'password' => trim((string)($camera['onvif_password'] ?? '')),
                'enabled' => ((int)$camera['blocked'] === 0),
                'eventsEnabled' => true,
                'sourceStreams' => [$name],
            ];
            $onvifResult = self::upsertOnvifDevice((int)$camera['server_id'], $onvifPayload);
            $onvifPath = $onvifResult['deviceId'] ?? $name;
            $onvifMsg = self::responseSummary(['status' => $onvifResult['status'] ?? 0, 'body' => json_encode($onvifResult['data'] ?? null)], '/api/onvif/devices/' . rawurlencode((string)$onvifPath));
            $message .= ' | ONVIF: ' . $onvifMsg;
        }

        return self::storeCameraSync($cameraId, $ok, $message);
    }

    private static function cameraDvrOptionsPayload(array $camera): array
    {
        return [
            'webrtcFastStart' => (int)($camera['webrtc_fast_start'] ?? 0) === 1,
            'eventArchiveRetentionEnabled' => (int)($camera['event_archive_retention_enabled'] ?? 0) === 1,
            'eventArchiveMaxBytes' => self::optionalNonNegativeInt($camera['event_archive_max_bytes'] ?? null),
            'eventArchiveMaxDuration' => self::optionalString($camera['event_archive_max_duration'] ?? null),
            'eventArchiveMaxAge' => self::optionalString($camera['event_archive_max_age'] ?? null),
            'timelapseEnabled' => (int)($camera['timelapse_enabled'] ?? 0) === 1,
            'timelapseFramesPerHour' => self::positiveInt($camera['timelapse_frames_per_hour'] ?? null, 60),
            'timelapseRetentionDays' => self::optionalString($camera['timelapse_retention_days'] ?? null),
            'timelapsePlaybackFps' => self::positiveInt($camera['timelapse_playback_fps'] ?? null, 25),
            'directArchiveVideoTimelineRepairMode' => self::optionalTimelineRepairMode($camera['direct_archive_video_timeline_repair_mode'] ?? null),
            'audioCodec' => self::audioCodec($camera['audio_codec'] ?? null),
        ];
    }

    private static function optionalString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private static function optionalNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return max(0, (int)$value);
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        $int = (int)$value;
        return $int > 0 ? $int : $default;
    }

    private static function optionalTimelineRepairMode(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, ['auto', 'always', 'off'], true) ? $value : null;
    }

    private static function audioCodec(mixed $value): string
    {
        return strtolower(trim((string)$value)) === 'aac' ? 'aac' : 'copy';
    }

    public static function deleteCameraStream(int $cameraId, bool $purgeArchive): array
    {
        $camera = Repo::camera($cameraId);
        if (!$camera) {
            return ['ok' => false, 'message' => 'camera_not_found'];
        }
        if (!$camera['server_id']) {
            return ['ok' => false, 'message' => 'No SesameDVR server selected'];
        }
        if (($camera['dvr_control_mode'] ?? 'managed') === 'read_only') {
            return ['ok' => false, 'message' => I18n::t('cameras.deleteDvrReadOnly', 'Read-only camera: DVR stream deletion is unavailable')];
        }

        $server = Repo::server((int)$camera['server_id']);
        if (!$server || (int)$server['blocked'] === 1) {
            return ['ok' => false, 'message' => 'SesameDVR server is unavailable or blocked'];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $tokenIssue = self::managementTokenIssue($server, $token);
        if ($tokenIssue !== null) {
            return ['ok' => false, 'message' => self::managementTokenIssueMessage($tokenIssue), 'reason' => $tokenIssue];
        }

        $name = trim((string)($camera['dvr_stream_name'] ?: $camera['name']));
        if ($name === '') {
            return ['ok' => false, 'message' => 'DVR stream name is empty'];
        }
        if (!Util::isDvrStreamName($name)) {
            return ['ok' => false, 'message' => I18n::t('cameras.invalidStreamName', 'Technical stream name must start with a Latin letter or digit and can contain only Latin letters, digits, dot, hyphen, and underscore, up to 128 characters.')];
        }

        $endpoint = rtrim($server['base_url'], '/') . '/api/streams/' . rawurlencode($name);
        if ($purgeArchive) {
            $endpoint .= '?purge=true';
        }
        $result = self::request('DELETE', $endpoint, $token, null, $purgeArchive ? 300 : 12);
        $message = self::responseSummary($result, $endpoint);

        if ((int)$result['status'] >= 200 && (int)$result['status'] < 300) {
            self::deleteOnvifDevice((int)$camera['server_id'], $name);
        }

        if ((int)$result['status'] === 404) {
            return ['ok' => true, 'message' => $message . ' stream already absent'];
        }

        return [
            'ok' => $result['status'] >= 200 && $result['status'] < 300,
            'message' => $message,
        ];
    }

    public static function checkServer(int $serverId): array
    {
        $server = Repo::server($serverId);
        if (!$server) {
            return ['ok' => false, 'message' => 'server_not_found'];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $tokenIssue = self::managementTokenIssue($server, $token);
        if ($tokenIssue !== null) {
            $message = self::managementTokenIssueMessage($tokenIssue);
            DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ? WHERE id = ?')
                ->execute([Util::now(), $message, $serverId]);
            return ['ok' => false, 'message' => $message, 'reason' => $tokenIssue];
        }

        $result = self::request('GET', rtrim($server['base_url'], '/') . '/api/system/version', $token, null);
        $ok = $result['status'] >= 200 && $result['status'] < 300;
        $message = self::responseSummary($result, rtrim($server['base_url'], '/') . '/api/system/version');
        DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ? WHERE id = ?')
            ->execute([Util::now(), $message, $serverId]);
        return ['ok' => $ok, 'message' => $message];
    }

    public static function fetchServerMetrics(int $serverId): array
    {
        $server = Repo::server($serverId);
        if (!$server) {
            return ['ok' => false, 'message' => 'server_not_found'];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $tokenIssue = self::managementTokenIssue($server, $token);
        if ($tokenIssue !== null) {
            $message = self::managementTokenIssueMessage($tokenIssue);
            $payload = [
                'version' => ['error' => $tokenIssue],
                'status' => ['error' => $tokenIssue],
                'streams' => ['error' => $tokenIssue],
                'fetchedAt' => Util::now(),
            ];
            DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ?, last_metrics_at = ?, last_metrics_json = ? WHERE id = ?')
                ->execute([Util::now(), $message, Util::now(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $serverId]);
            return ['ok' => false, 'message' => $message, 'metrics' => $payload, 'reason' => $tokenIssue];
        }

        $base = rtrim($server['base_url'], '/');
        $version = self::request('GET', $base . '/api/system/version', $token, null);
        $status = self::request('GET', $base . '/api/system/status', $token, null);
        $streams = self::request('GET', $base . '/api/streams', $token, null);
        $ok = $version['status'] >= 200 && $version['status'] < 300
            && $status['status'] >= 200 && $status['status'] < 300
            && $streams['status'] >= 200 && $streams['status'] < 300;
        $payload = [
            'version' => self::jsonOrBody($version),
            'status' => self::jsonOrBody($status),
            'streams' => self::jsonOrBody($streams),
            'fetchedAt' => Util::now(),
        ];
        $message = self::responseSummary($version, $base . '/api/system/version') . '; ' .
            self::responseSummary($status, $base . '/api/system/status') . '; ' .
            self::responseSummary($streams, $base . '/api/streams');
        DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ?, last_metrics_at = ?, last_metrics_json = ? WHERE id = ?')
            ->execute([Util::now(), $message, Util::now(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $serverId]);
        return ['ok' => $ok, 'message' => $message, 'metrics' => $payload];
    }

    public static function listStreams(int $serverId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/streams', null, 20);
    }

    public static function listAgents(int $serverId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/agents');
    }

    public static function createAgent(int $serverId, array $payload): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents', $payload);
    }

    public static function updateAgent(int $serverId, string $agentId, array $payload): array
    {
        return self::apiRequest($serverId, 'PATCH', '/api/agents/' . rawurlencode($agentId), $payload);
    }

    public static function deleteAgent(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'DELETE', '/api/agents/' . rawurlencode($agentId));
    }

    public static function setAgentEnrollmentPassword(int $serverId, string $agentId, string $password): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/enrollment-password', ['password' => $password]);
    }

    public static function revokeAgent(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/revoke');
    }

    public static function rotateAgentSecret(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/rotate-secret');
    }

    public static function agentCameras(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/agents/' . rawurlencode($agentId) . '/cameras');
    }

    public static function scanAgentCameras(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/cameras/scan');
    }

    public static function agentDiagnostics(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/diagnostics');
    }

    public static function agentCommands(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/agents/' . rawurlencode($agentId) . '/commands');
    }

    public static function agentCommand(int $serverId, string $agentId, string $command, array $payload, ?int $timeoutMs = null): array
    {
        $body = [
            'command' => $command,
            'payload' => $payload === [] ? new \stdClass() : $payload,
        ];
        if ($timeoutMs !== null && $timeoutMs > 0) {
            $body['timeoutMs'] = $timeoutMs;
        }
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/commands', $body);
    }

    public static function agentLogs(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/agents/' . rawurlencode($agentId) . '/logs');
    }

    public static function agentSnapshot(int $serverId, string $agentId, string $cameraId, bool $fresh = false): array
    {
        $path = '/api/agents/' . rawurlencode($agentId) . '/cameras/' . rawurlencode($cameraId) . '/snapshot.jpg';
        $path .= '?timeoutMs=2500' . ($fresh ? '&fresh=true' : '');
        return self::apiRequest($serverId, 'GET', $path, null, 5, false);
    }

    public static function listOnvifDevices(int $serverId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/onvif/devices');
    }

    public static function getOnvifDevice(int $serverId, string $id): array
    {
        return self::apiRequest($serverId, 'GET', '/api/onvif/devices/' . rawurlencode($id));
    }

    private static function resolveOnvifDeviceId(int $serverId, string $streamName): ?string
    {
        if ($streamName === '') {
            return null;
        }
        $result = self::listOnvifDevices($serverId);
        if (!$result['ok'] || !is_array($result['data'])) {
            return null;
        }
        $devices = $result['data']['devices'] ?? null;
        if (!is_array($devices)) {
            return null;
        }
        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $streams = $device['sourceStreams'] ?? $device['source_streams'] ?? [];
            if (is_array($streams) && in_array($streamName, $streams, true)) {
                $id = trim((string)($device['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }
        return null;
    }

    public static function upsertOnvifDevice(int $serverId, array $payload): array
    {
        $streamName = trim((string)($payload['id'] ?? ''));
        if ($streamName === '') {
            return ['ok' => false, 'status' => 0, 'message' => 'ONVIF stream name is empty', 'data' => null, 'deviceId' => null];
        }

        $resolvedId = self::resolveOnvifDeviceId($serverId, $streamName);
        if ($resolvedId !== null) {
            $result = self::apiRequest($serverId, 'PUT', '/api/onvif/devices/' . rawurlencode($resolvedId), $payload);
            $result['deviceId'] = $resolvedId;
            return $result;
        }

        $result = self::apiRequest($serverId, 'POST', '/api/onvif/devices', $payload);
        if ($result['ok'] && is_array($result['data'])) {
            $result['deviceId'] = trim((string)($result['data']['id'] ?? $streamName));
        } else {
            $result['deviceId'] = null;
        }
        return $result;
    }

    public static function deleteOnvifDevice(int $serverId, string $streamName): array
    {
        if ($streamName === '') {
            return ['ok' => false, 'status' => 0, 'message' => 'ONVIF stream name is empty', 'data' => null];
        }
        $resolvedId = self::resolveOnvifDeviceId($serverId, $streamName);
        if ($resolvedId === null) {
            return ['ok' => true, 'status' => 200, 'message' => 'ONVIF device already absent for stream ' . $streamName, 'data' => null];
        }
        return self::apiRequest($serverId, 'DELETE', '/api/onvif/devices/' . rawurlencode($resolvedId));
    }

    public static function scanOnvifDevices(int $serverId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/onvif/devices/scan');
    }

    public static function checkOnvifDevice(int $serverId, string $id): array
    {
        return self::apiRequest($serverId, 'POST', '/api/onvif/devices/' . rawurlencode($id) . '/check');
    }

    private static function managementTokenIssue(array $server, string $token): ?string
    {
        if ($token !== '') {
            return null;
        }

        $encoded = trim((string)($server['management_token_enc'] ?? ''));
        return $encoded === '' ? 'management_token_missing' : 'management_token_unreadable';
    }

    private static function managementTokenIssueMessage(string $issue): string
    {
        return $issue === 'management_token_unreadable'
            ? 'Management token cannot be decrypted'
            : 'Management token is not configured';
    }

    private static function apiRequest(int $serverId, string $method, string $path, ?array $payload = null, int $timeout = 12, bool $decodeJson = true): array
    {
        $server = Repo::server($serverId);
        if (!$server || (int)$server['blocked'] === 1) {
            return ['ok' => false, 'status' => 0, 'message' => 'SesameDVR server is unavailable or blocked', 'data' => null];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $tokenIssue = self::managementTokenIssue($server, $token);
        if ($tokenIssue !== null) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => self::managementTokenIssueMessage($tokenIssue),
                'reason' => $tokenIssue,
                'data' => null,
            ];
        }

        $endpoint = rtrim($server['base_url'], '/') . $path;
        $result = self::request($method, $endpoint, $token, $payload, $timeout);
        $ok = $result['status'] >= 200 && $result['status'] < 300;
        return [
            'ok' => $ok,
            'status' => (int)$result['status'],
            'message' => self::responseSummary($result, $endpoint),
            'data' => $decodeJson ? self::jsonOrBody($result) : $result['body'],
            'contentType' => (string)($result['content_type'] ?? ''),
        ];
    }

    private static function fetchStream(array $server, string $token, string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        $endpoint = rtrim($server['base_url'], '/') . '/api/streams/' . rawurlencode($name);
        $result = self::request('GET', $endpoint, $token, null, 8);
        if ((int)$result['status'] < 200 || (int)$result['status'] >= 300) {
            return null;
        }

        $decoded = json_decode((string)$result['body'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function streamUsesAgentPublisher(array $stream): bool
    {
        $push = $stream['push'] ?? null;
        if (!is_array($push)) {
            return false;
        }

        return ($stream['sourceType'] ?? $stream['source_type'] ?? '') === 'push'
            && ($push['publisherKind'] ?? $push['publisher_kind'] ?? '') === 'agent';
    }

    private static function request(string $method, string $url, string $token, ?array $payload, int $timeout = 12): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'X-Management-Token: ' . $token;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => $body === false ? $error : (string)$body, 'content_type' => (string)$contentType];
    }

    private static function storeCameraSync(int $cameraId, bool $ok, string $message): array
    {
        DB::pdo()->prepare('UPDATE cameras SET last_sync_at = ?, last_sync_ok = ?, last_sync_message = ? WHERE id = ?')
            ->execute([Util::now(), $ok ? 1 : 0, mb_substr($message, 0, 1000), $cameraId]);
        return ['ok' => $ok, 'message' => $message];
    }

    private static function responseSummary(array $result, string $endpoint): string
    {
        $body = trim((string)($result['body'] ?? ''));
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $body = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $body = preg_replace('/\s+/', ' ', $body);
        return 'HTTP ' . (int)($result['status'] ?? 0) . ' ' . $endpoint . ' ' . mb_substr((string)$body, 0, 420);
    }

    private static function jsonOrBody(array $result): mixed
    {
        $decoded = json_decode((string)($result['body'] ?? ''), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'httpStatus' => (int)($result['status'] ?? 0),
            'body' => mb_substr((string)($result['body'] ?? ''), 0, 1000),
        ];
    }
}
