<?php

declare(strict_types=1);

namespace SesamePortal;

trait CamerasApi
{
    private static function apiCameras(array $parts): void
    {
        $user = self::apiRequireUser();
        $method = self::apiMethod();
        $identifier = isset($parts[1]) ? trim((string)$parts[1]) : '';
        $camera = $identifier !== '' ? self::apiCameraByIdentifier($identifier) : null;
        $id = $camera ? (int)$camera['id'] : 0;
        $admin = ($user['role'] ?? '') === 'admin';

        if (count($parts) === 1) {
            if ($method === 'GET') {
                $filter = self::apiCameraListFilter();
                if ($admin && (string)($_GET['scope'] ?? 'all') !== 'accessible' && $filter === 'all') {
                    $list = self::filteredCameras(self::apiPageSize(25, 500));
                } else {
                    $list = Repo::accessibleCamerasPage($user, $filter, self::viewerSearchQuery(), (int)($_GET['page'] ?? 1), self::apiPageSize(25, 500));
                }
                self::apiJson(['cameras' => self::apiCameraRows($list['rows']), 'pagination' => self::apiPagination($list)]);
                return;
            }
            if ($method === 'POST') {
                self::apiRequireAdmin();
                self::apiSaveCamera(0, self::apiInput());
                return;
            }
        }
        if ($id <= 0) {
            self::apiError(404, 'not_found', 'Camera not found');
            return;
        }
        if (count($parts) === 2) {
            if ($method === 'GET') {
                if (!$camera || (!$admin && !Repo::cameraAllowedForUser($user, $id))) {
                    self::apiError(404, 'not_found', 'Camera not found');
                    return;
                }
                self::apiJson(['camera' => self::apiCameraRow($camera, true)]);
                return;
            }
            self::apiRequireAdmin();
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveCamera($id, self::apiInput());
                return;
            }
            if ($method === 'DELETE') {
                $input = self::apiInput();
                $purge = self::apiBool($input['purge'] ?? $input['deleteDvrStream'] ?? $input['delete_dvr_stream'] ?? $_GET['purge'] ?? false);
                $dvrResult = null;
                if ($purge) {
                    $dvrResult = DvrClient::deleteCameraStream($id, true);
                    if (empty($dvrResult['ok'])) {
                        self::apiError(502, 'dvr_delete_failed', (string)($dvrResult['message'] ?? 'DVR stream delete failed'));
                        return;
                    }
                }
                DB::pdo()->prepare('DELETE FROM camera_groups WHERE camera_id=?')->execute([$id]);
                DB::pdo()->prepare('DELETE FROM cameras WHERE id=?')->execute([$id]);
                Audit::log('camera.delete', 'camera_id=' . $id . ' dvr=' . ($purge ? 'yes' : 'no'));
                self::apiJson(['ok' => true, 'dvr' => $dvrResult]);
                return;
            }
        }
        if (count($parts) === 3 && $parts[2] === 'sync' && $method === 'POST') {
            self::apiRequireAdmin();
            self::apiJson(DvrClient::syncCamera($id));
            return;
        }
        self::apiError(404, 'not_found', 'Unknown cameras endpoint');
    }

    private static function apiCameraListFilter(): string
    {
        foreach (['groupIds', 'groupIDs', 'group_ids'] as $key) {
            if (array_key_exists($key, $_GET)) {
                $groupIds = self::apiGroupIdsQueryValue($_GET[$key]);
                return 'group:' . implode(',', $groupIds);
            }
        }

        $groupId = (int)($_GET['groupId'] ?? $_GET['groupID'] ?? $_GET['group_id'] ?? 0);
        if ($groupId > 0) {
            return 'group:' . $groupId;
        }

        $filter = trim((string)($_GET['filter'] ?? 'all'));
        if ($filter === '') {
            return 'all';
        }
        if (preg_match('/^group(?:id)?[:=](\d+)$/i', $filter, $matches) === 1) {
            return 'group:' . (int)$matches[1];
        }
        if (ctype_digit($filter) && (int)$filter > 0) {
            return 'group:' . (int)$filter;
        }

        return $filter;
    }

    private static function apiGroupIdsQueryValue(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[,\s]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_map('intval', $values ?: []);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    private static function apiSaveCamera(int $id, array $input): void
    {
        $current = $id > 0 ? Repo::camera($id) : null;
        if ($id > 0 && !$current) {
            self::apiError(404, 'not_found', 'Camera not found');
            return;
        }
        $controlMode = self::cameraControlMode($input['dvrControlMode'] ?? $input['dvr_control_mode'] ?? ($current['dvr_control_mode'] ?? 'managed'));
        $sourceUrl = trim((string)($input['sourceUrl'] ?? $input['source_url'] ?? ($current['source_url'] ?? '')));
        $serverId = (int)($input['serverId'] ?? $input['server_id'] ?? ($current['server_id'] ?? 0)) ?: null;
        $selection = ($input['serverSelection'] ?? $input['server_selection'] ?? ($current['server_selection'] ?? 'manual')) === 'auto' ? 'auto' : 'manual';
        if ($controlMode === 'edge_agent') {
            $selection = 'manual';
        } elseif (!$serverId) {
            $selection = 'auto';
        }
        if ($selection === 'auto' && !$serverId) {
            $serverId = self::randomActiveServerId();
        }
        [$name, $stream] = self::cameraNamesFromInput($input, $current);
        if ($name === '' || $stream === '') {
            self::apiError(422, 'validation_failed', 'displayName or dvrStreamName is required');
            return;
        }
        if (!Util::isDvrStreamName($stream)) {
            self::apiError(422, 'invalid_stream_name', I18n::t('cameras.invalidStreamName', 'Technical stream name must start with a Latin letter or digit and can contain only Latin letters, digits, dot, hyphen, and underscore, up to 128 characters.'), [
                'field' => 'dvrStreamName',
                'pattern' => Util::DVR_STREAM_NAME_HTML_PATTERN,
                'maxBytes' => Util::DVR_STREAM_NAME_MAX_BYTES,
            ]);
            return;
        }
        $existingCamera = self::cameraByName($name);
        if ($existingCamera && (int)$existingCamera['id'] !== $id) {
            self::apiCameraNameExists($existingCamera);
            return;
        }
        $agentId = trim((string)($input['agentId'] ?? $input['agent_id'] ?? ($current['agent_id'] ?? '')));
        $agentCameraId = trim((string)($input['agentCameraId'] ?? $input['agent_camera_id'] ?? ($current['agent_camera_id'] ?? '')));
        if ($controlMode === 'managed' && $sourceUrl === '') {
            self::apiError(422, 'validation_failed', 'sourceUrl is required for managed cameras');
            return;
        }
        if ($controlMode === 'edge_agent' && (!$serverId || $agentId === '' || $agentCameraId === '')) {
            self::apiError(422, 'validation_failed', 'serverId, agentId, and agentCameraId are required for edge_agent cameras');
            return;
        }

        $values = [
            $name,
            $sourceUrl,
            $serverId,
            $selection,
            self::nullableFloat($input['latitude'] ?? ($current['latitude'] ?? null)),
            self::nullableFloat($input['longitude'] ?? ($current['longitude'] ?? null)),
            (int)($input['directionDeg'] ?? $input['direction_deg'] ?? ($current['direction_deg'] ?? 0)),
            (int)($input['viewAngleDeg'] ?? $input['view_angle_deg'] ?? ($current['view_angle_deg'] ?? 60)),
            (string)($input['retentionDays'] ?? $input['retention_days'] ?? ($current['retention_days'] ?? '7d')),
            array_key_exists('archiveEnabled', $input) || array_key_exists('archive_enabled', $input)
                ? (self::apiBool($input['archiveEnabled'] ?? $input['archive_enabled']) ? 1 : 0)
                : (int)($current['archive_enabled'] ?? 1),
            array_key_exists('webrtcFastStart', $input) || array_key_exists('webrtc_fast_start', $input)
                ? (self::apiBool($input['webrtcFastStart'] ?? $input['webrtc_fast_start']) ? 1 : 0)
                : (int)($current['webrtc_fast_start'] ?? 0),
            array_key_exists('eventArchiveRetentionEnabled', $input) || array_key_exists('event_archive_retention_enabled', $input)
                ? (self::apiBool($input['eventArchiveRetentionEnabled'] ?? $input['event_archive_retention_enabled']) ? 1 : 0)
                : (int)($current['event_archive_retention_enabled'] ?? 0),
            self::apiOptionalMegabytesAsBytes($input, ['eventArchiveMaxMb', 'event_archive_max_mb'], ['eventArchiveMaxBytes', 'event_archive_max_bytes'], $current, 'event_archive_max_bytes'),
            self::apiOptionalString($input, ['eventArchiveMaxDuration', 'event_archive_max_duration'], $current, 'event_archive_max_duration'),
            self::apiOptionalString($input, ['eventArchiveMaxAge', 'event_archive_max_age'], $current, 'event_archive_max_age'),
            array_key_exists('timelapseEnabled', $input) || array_key_exists('timelapse_enabled', $input)
                ? (self::apiBool($input['timelapseEnabled'] ?? $input['timelapse_enabled']) ? 1 : 0)
                : (int)($current['timelapse_enabled'] ?? 0),
            self::apiPositiveInt($input, ['timelapseFramesPerHour', 'timelapse_frames_per_hour'], $current, 'timelapse_frames_per_hour', 60),
            self::apiOptionalString($input, ['timelapseRetentionDays', 'timelapse_retention_days'], $current, 'timelapse_retention_days'),
            self::apiPositiveInt($input, ['timelapsePlaybackFps', 'timelapse_playback_fps'], $current, 'timelapse_playback_fps', 25),
            self::cameraTimelineRepairMode(self::apiOptionalString($input, ['directArchiveVideoTimelineRepairMode', 'direct_archive_video_timeline_repair_mode'], $current, 'direct_archive_video_timeline_repair_mode')),
            array_key_exists('audioCodec', $input) || array_key_exists('audio_codec', $input)
                ? self::cameraAudioCodec($input['audioCodec'] ?? $input['audio_codec'])
                : self::cameraAudioCodec($current['audio_codec'] ?? 'copy'),
            $controlMode,
            $agentId !== '' ? $agentId : null,
            $agentCameraId !== '' ? $agentCameraId : null,
            array_key_exists('onvifEventsRequested', $input) || array_key_exists('onvif_events_requested', $input)
                ? (self::apiBool($input['onvifEventsRequested'] ?? $input['onvif_events_requested']) ? 1 : 0)
                : (int)($current['onvif_events_requested'] ?? 0),
            array_key_exists('watermarkEnabled', $input) || array_key_exists('watermark_enabled', $input)
                ? (self::apiBool($input['watermarkEnabled'] ?? $input['watermark_enabled']) ? 1 : 0)
                : (int)($current['watermark_enabled'] ?? 0),
            array_key_exists('watermarkIntensity', $input) || array_key_exists('watermark_intensity', $input)
                ? self::watermarkIntensity($input['watermarkIntensity'] ?? $input['watermark_intensity'])
                : self::watermarkIntensity($current['watermark_intensity'] ?? 16),
            self::apiBlockedValue($input, $current),
            $stream,
        ];

        if (array_key_exists('groupIds', $input) || array_key_exists('group_ids', $input)) {
            $groupIds = self::apiIntArray($input['groupIds'] ?? $input['group_ids'] ?? []);
            self::apiValidateExistingIds('groupIds', 'portal_groups', $groupIds);
        } else {
            $groupIds = null;
        }
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE cameras SET name=?, source_url=?, server_id=?, server_selection=?, latitude=?, longitude=?, direction_deg=?, view_angle_deg=?, retention_days=?, archive_enabled=?, webrtc_fast_start=?, event_archive_retention_enabled=?, event_archive_max_bytes=?, event_archive_max_duration=?, event_archive_max_age=?, timelapse_enabled=?, timelapse_frames_per_hour=?, timelapse_retention_days=?, timelapse_playback_fps=?, direct_archive_video_timeline_repair_mode=?, audio_codec=?, dvr_control_mode=?, agent_id=?, agent_camera_id=?, onvif_events_requested=?, watermark_enabled=?, watermark_intensity=?, blocked=?, dvr_stream_name=?, updated_at=? WHERE id=?')
                    ->execute([...$values, Util::now(), $id]);
            } else {
                $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, archive_enabled, webrtc_fast_start, event_archive_retention_enabled, event_archive_max_bytes, event_archive_max_duration, event_archive_max_age, timelapse_enabled, timelapse_frames_per_hour, timelapse_retention_days, timelapse_playback_fps, direct_archive_video_timeline_repair_mode, audio_codec, dvr_control_mode, agent_id, agent_camera_id, onvif_events_requested, watermark_enabled, watermark_intensity, blocked, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$values, Util::now(), Util::now()]);
                $id = DB::lastInsertId('cameras');
            }
            if ($groupIds !== null) {
                self::replaceLinks('camera_groups', 'camera_id', $id, 'group_id', $groupIds);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof \PDOException && self::isCameraNameUniqueConstraint($error)) {
                self::apiCameraNameExists(self::cameraByName($name));
                return;
            }
            throw $error;
        }
        $syncRequested = array_key_exists('sync', $input)
            ? self::apiBool($input['sync'])
            : empty($input['skipSync']);
        $sync = $syncRequested ? DvrClient::syncCamera($id) : ['ok' => true, 'message' => 'sync skipped'];
        Audit::log('camera.save', $name . ' mode=' . $controlMode . ' sync=' . ($sync['message'] ?? ''));
        self::apiJson(['camera' => self::apiCameraRow(self::apiCameraById($id), true), 'sync' => $sync], $current ? 200 : 201);
    }

    private static function apiCameraNameExists(?array $existing): void
    {
        self::apiError(409, 'camera_name_exists', 'camera name already exists', [
            'existingId' => (int)($existing['id'] ?? 0),
        ]);
    }

    private static function isCameraNameUniqueConstraint(\PDOException $error): bool
    {
        $code = (string)$error->getCode();
        $message = strtolower($error->getMessage());
        return in_array($code, ['23000', '23505'], true)
            && (str_contains($message, 'cameras.name')
                || str_contains($message, 'cameras_name')
                || str_contains($message, 'for key \'name\'')
                || str_contains($message, 'for key "name"'));
    }

    private static function apiCameraById(int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT c.*, s.name AS server_name, s.base_url AS server_url, s.last_metrics_json AS server_metrics_json FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id WHERE c.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function cameraByName(string $name): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM cameras WHERE name = ?');
        $stmt->execute([$name]);
        return $stmt->fetch() ?: null;
    }

    private static function apiCameraByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (preg_match('/^[1-9][0-9]*$/', $identifier) === 1) {
            $camera = self::apiCameraById((int)$identifier);
            if ($camera) {
                return $camera;
            }
        }

        $stmt = DB::pdo()->prepare(
            'SELECT c.*, s.name AS server_name, s.base_url AS server_url, s.last_metrics_json AS server_metrics_json
             FROM cameras c
             LEFT JOIN dvr_servers s ON s.id = c.server_id
             WHERE c.dvr_stream_name = ? OR c.name = ?
             ORDER BY CASE WHEN c.dvr_stream_name = ? THEN 0 ELSE 1 END, c.id
             LIMIT 1'
        );
        $stmt->execute([$identifier, $identifier, $identifier]);
        return $stmt->fetch() ?: null;
    }

    private static function apiCameraRows(array $cameras): array
    {
        $streamUnavailableByServer = self::mapStreamUnavailableByServer($cameras);
        return array_map(
            static fn(array $camera): array => self::apiCameraRow($camera, false, $streamUnavailableByServer),
            $cameras
        );
    }

    private static function apiCameraRow(?array $camera, bool $detailed = false, ?array $streamUnavailableByServer = null): array
    {
        if (!$camera) {
            return [];
        }
        $streamUnavailable = $streamUnavailableByServer === null
            ? self::cameraStreamUnavailable($camera)
            : self::cameraStreamUnavailableFromMapMetrics($camera, $streamUnavailableByServer);
        $row = [
            'id' => (int)$camera['id'],
            'name' => (string)$camera['name'],
            'displayName' => (string)$camera['name'],
            'sourceUrl' => (string)($camera['source_url'] ?? ''),
            'serverId' => $camera['server_id'] !== null ? (int)$camera['server_id'] : null,
            'serverName' => $camera['server_name'] ?? null,
            'serverSelection' => (string)($camera['server_selection'] ?? 'manual'),
            'latitude' => $camera['latitude'] !== null ? (float)$camera['latitude'] : null,
            'longitude' => $camera['longitude'] !== null ? (float)$camera['longitude'] : null,
            'directionDeg' => (int)($camera['direction_deg'] ?? 0),
            'viewAngleDeg' => (int)($camera['view_angle_deg'] ?? 60),
            'retentionDays' => (string)($camera['retention_days'] ?? '7d'),
            'archiveEnabled' => (int)($camera['archive_enabled'] ?? 1) === 1,
            'webrtcFastStart' => (int)($camera['webrtc_fast_start'] ?? 0) === 1,
            'eventArchiveRetentionEnabled' => (int)($camera['event_archive_retention_enabled'] ?? 0) === 1,
            'eventArchiveMaxBytes' => ($camera['event_archive_max_bytes'] ?? null) !== null ? (int)$camera['event_archive_max_bytes'] : null,
            'eventArchiveMaxDuration' => $camera['event_archive_max_duration'] ?? null,
            'eventArchiveMaxAge' => $camera['event_archive_max_age'] ?? null,
            'timelapseEnabled' => (int)($camera['timelapse_enabled'] ?? 0) === 1,
            'timelapseFramesPerHour' => self::cameraPositiveInt($camera['timelapse_frames_per_hour'] ?? 60, 60),
            'timelapseRetentionDays' => $camera['timelapse_retention_days'] ?? null,
            'timelapsePlaybackFps' => self::cameraPositiveInt($camera['timelapse_playback_fps'] ?? 25, 25),
            'directArchiveVideoTimelineRepairMode' => self::cameraTimelineRepairMode($camera['direct_archive_video_timeline_repair_mode'] ?? null),
            'audioCodec' => self::cameraAudioCodec($camera['audio_codec'] ?? 'copy'),
            'dvrControlMode' => (string)($camera['dvr_control_mode'] ?? 'managed'),
            'agentId' => $camera['agent_id'] ?? null,
            'agentCameraId' => $camera['agent_camera_id'] ?? null,
            'onvifEventsRequested' => (int)($camera['onvif_events_requested'] ?? 0) === 1,
            'watermarkEnabled' => (int)($camera['watermark_enabled'] ?? 0) === 1,
            'watermarkIntensity' => self::watermarkIntensity($camera['watermark_intensity'] ?? 16),
            'blocked' => (int)($camera['blocked'] ?? 0) === 1,
            'dvrStreamName' => (string)($camera['dvr_stream_name'] ?? ''),
            'streamUnavailable' => $streamUnavailable,
            'lastSyncAt' => $camera['last_sync_at'] ?? null,
            'lastSyncOk' => $camera['last_sync_ok'] === null ? null : (int)$camera['last_sync_ok'] === 1,
            'lastSyncMessage' => $camera['last_sync_message'] ?? null,
            'createdAt' => $camera['created_at'] ?? null,
            'updatedAt' => $camera['updated_at'] ?? null,
        ];
        if ($detailed) {
            $row['groupIds'] = self::linkedIds('camera_groups', 'camera_id', (int)$camera['id'], 'group_id');
        }
        return $row;
    }
}
