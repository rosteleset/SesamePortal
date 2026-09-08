<?php

declare(strict_types=1);

namespace SesamePortal;

trait CameraFields
{
    private static function cameraControlMode(mixed $value): string
    {
        $mode = trim((string)$value);
        return in_array($mode, ['managed', 'edge_agent', 'read_only'], true) ? $mode : 'managed';
    }

    private static function cameraTimelineRepairMode(mixed $value): ?string
    {
        $mode = strtolower(trim((string)$value));
        return in_array($mode, ['auto', 'always', 'off'], true) ? $mode : null;
    }

    private static function cameraAudioCodec(mixed $value): string
    {
        return strtolower(trim((string)$value)) === 'aac' ? 'aac' : 'copy';
    }

    private static function cameraPositiveInt(mixed $value, int $default): int
    {
        $value = (int)$value;
        return $value > 0 ? $value : $default;
    }

    private static function cameraOptionalNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return max(0, (int)$value);
    }

    private static function cameraOptionalMegabytesAsBytes(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $megabytes = max(0.0, (float)str_replace(',', '.', trim((string)$value)));
        return (int)ceil($megabytes * 1024 * 1024);
    }

    private static function cameraEventArchiveMaxBytesFromPost(): ?int
    {
        if (array_key_exists('event_archive_max_mb', $_POST)) {
            return self::cameraOptionalMegabytesAsBytes(Util::post('event_archive_max_mb'));
        }
        return self::cameraOptionalNonNegativeInt(Util::post('event_archive_max_bytes'));
    }

    private static function eventArchiveBytesToMegabytesInput(mixed $value): string
    {
        $bytes = self::cameraOptionalNonNegativeInt($value);
        if ($bytes === null) {
            return '';
        }
        $text = number_format($bytes / 1024 / 1024, 2, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }

    private static function cameraOptionalString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private static function watermarkIntensity(mixed $value): int
    {
        $intensity = (int)$value;
        return max(1, min(100, $intensity > 0 ? $intensity : 16));
    }

    private static function cameraNamesFromInput(array $input, ?array $current): array
    {
        $displayValue = self::firstInputValue(
            $input,
            ['displayName', 'display_name', 'name'],
            $current['name'] ?? ''
        );
        $streamValue = self::firstInputValue(
            $input,
            ['dvrStreamName', 'dvr_stream_name', 'streamName', 'stream_name'],
            $current['dvr_stream_name'] ?? ''
        );

        $displayName = trim((string)$displayValue);
        $streamName = trim((string)$streamValue);
        if ($streamName === '' && $displayName !== '') {
            $streamName = Util::dvrStreamSlug($displayName);
        }
        if ($displayName === '' && $streamName !== '') {
            $displayName = $streamName;
        }

        return [$displayName, $streamName];
    }

    private static function firstInputValue(array $input, array $keys, mixed $fallback = ''): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }
        }
        return $fallback;
    }

    private static function cameraSaveNotice(array $sync): string
    {
        return !empty($sync['ok'])
            ? self::t('cameras.saveDone', 'Камера сохранена')
            : self::t('cameras.saveSyncFailed', 'Камера сохранена, но синхронизация с DVR не выполнена');
    }

    private static function cameraSyncNotice(array $sync): string
    {
        return !empty($sync['ok'])
            ? self::t('cameras.syncDone', 'Синхронизация выполнена')
            : self::t('cameras.syncFailed', 'Синхронизация не выполнена');
    }

    private static function cameraFormDefaults(?array $edit): array
    {
        if ($edit) {
            return $edit;
        }

        [$name, $stream] = self::cameraNamesFromInput([
            'display_name' => $_GET['display_name'] ?? $_GET['displayName'] ?? $_GET['name'] ?? '',
            'dvr_stream_name' => $_GET['stream'] ?? $_GET['dvr_stream_name'] ?? $_GET['dvrStreamName'] ?? '',
        ], null);
        $serverId = (int)($_GET['server_id'] ?? 0) ?: '';
        $controlMode = self::cameraControlMode($_GET['mode'] ?? $_GET['dvr_control_mode'] ?? 'managed');
        $serverSelection = $controlMode === 'edge_agent' || $serverId !== '' ? 'manual' : 'auto';
        if (isset($_GET['server_selection'])) {
            $serverSelection = $_GET['server_selection'] === 'auto' && $controlMode !== 'edge_agent' ? 'auto' : 'manual';
        }

        return [
            'name' => $name,
            'source_url' => (string)($_GET['source_url'] ?? ''),
            'server_id' => $serverId,
            'server_selection' => $serverSelection,
            'latitude' => '',
            'longitude' => '',
            'direction_deg' => 0,
            'view_angle_deg' => 60,
            'retention_days' => (string)($_GET['retention_days'] ?? '7d'),
            'archive_enabled' => array_key_exists('archive_enabled', $_GET) ? (int)!empty($_GET['archive_enabled']) : 1,
            'webrtc_fast_start' => !empty($_GET['webrtc_fast_start']) ? 1 : 0,
            'event_archive_retention_enabled' => !empty($_GET['event_archive_retention_enabled']) ? 1 : 0,
            'event_archive_max_bytes' => array_key_exists('event_archive_max_mb', $_GET)
                ? self::cameraOptionalMegabytesAsBytes($_GET['event_archive_max_mb'])
                : self::cameraOptionalNonNegativeInt($_GET['event_archive_max_bytes'] ?? null),
            'event_archive_max_duration' => self::cameraOptionalString($_GET['event_archive_max_duration'] ?? ''),
            'event_archive_max_age' => self::cameraOptionalString($_GET['event_archive_max_age'] ?? ''),
            'timelapse_enabled' => !empty($_GET['timelapse_enabled']) ? 1 : 0,
            'timelapse_frames_per_hour' => self::cameraPositiveInt($_GET['timelapse_frames_per_hour'] ?? 60, 60),
            'timelapse_retention_days' => self::cameraOptionalString($_GET['timelapse_retention_days'] ?? ''),
            'timelapse_playback_fps' => self::cameraPositiveInt($_GET['timelapse_playback_fps'] ?? 25, 25),
            'direct_archive_video_timeline_repair_mode' => self::cameraTimelineRepairMode($_GET['direct_archive_video_timeline_repair_mode'] ?? null),
            'audio_codec' => self::cameraAudioCodec($_GET['audio_codec'] ?? 'copy'),
            'dvr_control_mode' => $controlMode,
            'agent_id' => (string)($_GET['agent_id'] ?? ''),
            'agent_camera_id' => (string)($_GET['agent_camera_id'] ?? ''),
            'onvif_events_requested' => !empty($_GET['onvif_events_requested']) ? 1 : 0,
            'watermark_enabled' => !empty($_GET['watermark_enabled']) ? 1 : 0,
            'watermark_intensity' => self::watermarkIntensity($_GET['watermark_intensity'] ?? 16),
            'blocked' => 0,
            'dvr_stream_name' => $stream,
        ];
    }
}
