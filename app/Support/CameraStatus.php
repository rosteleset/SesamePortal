<?php

declare(strict_types=1);

namespace SesamePortal;

trait CameraStatus
{
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
}
