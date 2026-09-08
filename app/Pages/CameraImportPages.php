<?php

declare(strict_types=1);

namespace SesamePortal;

use PDO;

trait CameraImportPages
{
    private static function cameraImport(): void
    {
        Auth::requireAdmin();
        $servers = Repo::all('dvr_servers', 'name ASC');
        $selectedServerId = (int)(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            ? Util::post('server_id', 0)
            : ($_GET['server_id'] ?? 0));
        $message = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)Util::post('action') === 'import') {
            $selectedNames = is_array($_POST['stream_names'] ?? null) ? $_POST['stream_names'] : [];
            if ($selectedServerId <= 0) {
                $message = self::t('cameras.importChooseServer', 'Выберите DVR-сервер');
            } elseif ($selectedNames === []) {
                $message = self::t('cameras.importNoSelection', 'Выберите хотя бы один поток');
            } else {
                $available = self::cameraImportAvailableStreams($selectedServerId);
                if (!$available['ok']) {
                    $message = self::t('cameras.importLoadFailed', 'Не удалось загрузить потоки с DVR');
                } else {
                    $result = self::cameraImportSelectedStreams(
                        $selectedServerId,
                        $selectedNames,
                        $available['streams'],
                        is_array($_POST['group_ids'] ?? null) ? $_POST['group_ids'] : []
                    );
                    $message = $result['message'];
                }
            }
        }

        $available = $selectedServerId > 0
            ? self::cameraImportAvailableStreams($selectedServerId)
            : ['ok' => true, 'streams' => [], 'invalidCount' => 0, 'message' => ''];
        $groups = self::groupRowsWithDisplayLabels(Repo::all('portal_groups', 'name ASC'));

        self::layout(self::t('cameras.importTitle', 'Импорт потоков с DVR'), function () use ($servers, $selectedServerId, $available, $groups, $message) {
            self::notice($message);
            echo '<section class="panel camera-import-panel">';
            echo '<div class="section-head"><div><h2>' . self::t('cameras.importTitle', 'Импорт потоков с DVR') . '</h2><p class="muted">' . self::t('cameras.importHint', 'Выберите сервер и добавьте отсутствующие потоки в Portal. Они будут импортированы в безопасном read-only режиме без изменения конфигурации DVR.') . '</p></div>';
            echo '<a class="btn" href="/admin/cameras">' . self::t('action.back', 'Назад') . '</a></div>';

            echo '<form method="get" action="/admin/cameras/import" class="camera-import-server-form">';
            echo '<label>' . self::t('cameras.importServer', 'DVR-сервер') . '<select name="server_id" required><option value="">' . self::t('cameras.importChooseServer', 'Выберите DVR-сервер') . '</option>';
            foreach ($servers as $server) {
                $blocked = (int)($server['blocked'] ?? 0) === 1;
                echo '<option value="' . (int)$server['id'] . '"' . ($selectedServerId === (int)$server['id'] ? ' selected' : '') . ($blocked ? ' disabled' : '') . '>' . Util::h($server['name']) . ($blocked ? ' · ' . self::t('servers.blocked', 'Заблокирован') : '') . '</option>';
            }
            echo '</select></label><button>' . self::t('cameras.loadStreams', 'Показать потоки') . '</button></form>';

            if ($selectedServerId > 0 && !$available['ok']) {
                echo '<div class="alert">' . self::t('cameras.importLoadFailed', 'Не удалось загрузить потоки с DVR') . '</div>';
                self::technicalResult((string)($available['message'] ?? ''), self::t('agents.details', 'Технические детали'));
            } elseif ($selectedServerId > 0 && !$available['streams']) {
                echo '<div class="camera-import-empty">' . self::t('cameras.importEmpty', 'На выбранном DVR нет потоков, отсутствующих в Portal') . '</div>';
            } elseif ($selectedServerId > 0) {
                if ((int)($available['invalidCount'] ?? 0) > 0) {
                    echo '<div class="alert warn">' . sprintf(self::t('cameras.importSkippedInvalid', 'Пропущено потоков с неподдерживаемым техническим именем: %d'), (int)$available['invalidCount']) . '</div>';
                }
                echo '<form method="post" action="/admin/cameras/import?server_id=' . $selectedServerId . '" class="camera-import-form" data-dvr-import-form data-submit-progress="' . Util::h(self::t('cameras.importing', 'Импортируем потоки...')) . '">' . Csrf::field();
                echo '<input type="hidden" name="action" value="import"><input type="hidden" name="server_id" value="' . $selectedServerId . '">';
                echo '<div class="camera-import-toolbar"><input type="search" data-dvr-import-search placeholder="' . self::t('cameras.importSearch', 'Найти поток') . '">';
                echo '<button type="button" data-dvr-import-select-all>' . self::t('groups.selectAll', 'Выбрать все') . '</button>';
                echo '<button type="button" data-dvr-import-clear-all>' . self::t('groups.clearAll', 'Снять все') . '</button>';
                echo '<span class="camera-import-count" data-dvr-import-count></span></div>';
                echo '<div class="camera-import-list">';
                foreach ($available['streams'] as $stream) {
                    $name = (string)$stream['name'];
                    $displayName = (string)$stream['displayName'];
                    $search = trim($displayName . ' ' . $name . ' ' . (string)$stream['sourceType']);
                    echo '<label class="camera-import-row" data-dvr-import-row data-search="' . Util::h($search) . '">';
                    echo '<input type="checkbox" name="stream_names[]" value="' . Util::h($name) . '">';
                    echo '<span class="camera-import-identity"><strong>' . Util::h($displayName) . '</strong><code>' . Util::h($name) . '</code></span>';
                    echo '<span class="camera-import-meta"><span>' . Util::h((string)$stream['sourceType']) . '</span>';
                    echo '<span class="pill ' . (!empty($stream['archiveEnabled']) ? 'success' : 'info') . '">' . (!empty($stream['archiveEnabled']) ? self::t('cameraFilter.archiveOn', 'Архив включён') : self::t('cameraFilter.archiveOff', 'Архив выключен')) . '</span>';
                    echo '<span class="pill ' . (!empty($stream['enabled']) ? 'success' : 'warn') . '">' . (!empty($stream['enabled']) ? self::t('cameras.importEnabled', 'Включён') : self::t('cameras.importDisabled', 'Выключен')) . '</span></span>';
                    echo '</label>';
                }
                echo '<div class="camera-import-filter-empty" data-dvr-import-filter-empty hidden>' . self::t('assignment.empty', 'Ничего не найдено') . '</div></div>';
                echo '<p class="muted">' . self::t('cameras.importReadOnlyHint', 'Импорт не меняет потоки на DVR. После импорта режим отдельной камеры можно изменить в её настройках.') . '</p>';
                self::groupCheckboxTree(self::t('cameras.groups', 'Группы'), 'group_ids[]', $groups, []);
                echo '<div class="camera-import-actions"><button class="primary" data-dvr-import-submit data-submit-button disabled>' . self::t('cameras.importAction', 'Добавить выбранные потоки') . '</button><span class="muted" data-submit-status hidden></span></div>';
                echo '</form>';
            }
            echo '</section>';
        });
    }

    private static function cameraImportAvailableStreams(int $serverId): array
    {
        $result = DvrClient::listStreams($serverId);
        if (empty($result['ok'])) {
            return ['ok' => false, 'streams' => [], 'invalidCount' => 0, 'message' => (string)($result['message'] ?? '')];
        }

        $data = $result['data'] ?? null;
        if (!is_array($data) || (!array_is_list($data) && !is_array($data['streams'] ?? null))) {
            return ['ok' => false, 'streams' => [], 'invalidCount' => 0, 'message' => 'Invalid SesameDVR /api/streams response'];
        }
        $rows = array_is_list($data) ? $data : $data['streams'];
        $stmt = DB::pdo()->prepare('SELECT dvr_stream_name FROM cameras WHERE server_id = ?');
        $stmt->execute([$serverId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $existing[(string)$name] = true;
        }

        $streams = [];
        $seen = [];
        $invalidCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '' || isset($seen[$name]) || isset($existing[$name])) {
                continue;
            }
            $seen[$name] = true;
            if (!Util::isDvrStreamName($name)) {
                $invalidCount++;
                continue;
            }
            $displayName = trim((string)($row['displayName'] ?? $row['title'] ?? '')) ?: $name;
            $streams[] = [
                'name' => $name,
                'displayName' => $displayName,
                'source' => is_scalar($row['source'] ?? null) ? (string)$row['source'] : '',
                'sourceType' => trim((string)($row['sourceType'] ?? 'direct')) ?: 'direct',
                'enabled' => self::cameraImportBool($row['enabled'] ?? true, true),
                'archiveEnabled' => self::cameraImportBool($row['archiveEnabled'] ?? true, true),
                'retentionDays' => is_scalar($row['retentionDays'] ?? null) ? (string)$row['retentionDays'] : '7d',
                'webrtcFastStart' => self::cameraImportBool($row['webrtcFastStart'] ?? false, false),
                'eventArchiveRetentionEnabled' => self::cameraImportBool($row['eventArchiveRetentionEnabled'] ?? false, false),
                'eventArchiveMaxBytes' => $row['eventArchiveMaxBytes'] ?? null,
                'eventArchiveMaxDuration' => $row['eventArchiveMaxDuration'] ?? null,
                'eventArchiveMaxAge' => $row['eventArchiveMaxAge'] ?? null,
                'timelapseEnabled' => self::cameraImportBool($row['timelapseEnabled'] ?? false, false),
                'timelapseFramesPerHour' => $row['timelapseFramesPerHour'] ?? 60,
                'timelapseRetentionDays' => $row['timelapseRetentionDays'] ?? null,
                'timelapsePlaybackFps' => $row['timelapsePlaybackFps'] ?? 25,
                'directArchiveVideoTimelineRepairMode' => $row['directArchiveVideoTimelineRepairMode'] ?? null,
                'audioCodec' => $row['audioCodec'] ?? 'copy',
            ];
        }

        usort($streams, static function (array $left, array $right): int {
            return strnatcasecmp($left['displayName'], $right['displayName'])
                ?: strnatcasecmp($left['name'], $right['name']);
        });
        return ['ok' => true, 'streams' => $streams, 'invalidCount' => $invalidCount, 'message' => ''];
    }

    private static function cameraImportSelectedStreams(int $serverId, array $selectedNames, array $availableStreams, array $groupIds): array
    {
        $server = Repo::server($serverId);
        if (!$server || (int)$server['blocked'] === 1) {
            return ['ok' => false, 'message' => self::t('cameras.importChooseServer', 'Выберите DVR-сервер')];
        }

        $availableByName = [];
        foreach ($availableStreams as $stream) {
            $availableByName[(string)$stream['name']] = $stream;
        }
        $selectedNames = array_values(array_unique(array_filter(array_map(
            static fn(mixed $name): string => is_scalar($name) ? trim((string)$name) : '',
            $selectedNames
        ))));
        $selected = [];
        foreach ($selectedNames as $name) {
            if (isset($availableByName[$name])) {
                $selected[] = $availableByName[$name];
            }
        }
        if (!$selected) {
            return ['ok' => false, 'message' => self::t('cameras.importNoSelection', 'Выберите хотя бы один поток')];
        }

        $pdo = DB::pdo();
        $usedNames = [];
        foreach ($pdo->query('SELECT name FROM cameras')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $usedNames[self::cameraImportNameKey((string)$name)] = true;
        }
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
        $importedNames = [];

        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, archive_enabled, webrtc_fast_start, event_archive_retention_enabled, event_archive_max_bytes, event_archive_max_duration, event_archive_max_age, timelapse_enabled, timelapse_frames_per_hour, timelapse_retention_days, timelapse_playback_fps, direct_archive_video_timeline_repair_mode, audio_codec, dvr_control_mode, agent_id, agent_camera_id, onvif_events_requested, watermark_enabled, watermark_intensity, blocked, dvr_stream_name, last_sync_at, last_sync_ok, last_sync_message, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $exists = $pdo->prepare('SELECT 1 FROM cameras WHERE server_id = ? AND dvr_stream_name = ? LIMIT 1');
            $syncMessage = self::t('cameras.readOnlySyncSkipped', 'Read-only mode: DVR management skipped');
            foreach ($selected as $stream) {
                $exists->execute([$serverId, (string)$stream['name']]);
                if ($exists->fetchColumn()) {
                    continue;
                }
                $cameraName = self::cameraImportUniqueName((string)$stream['displayName'], (string)$stream['name'], (string)$server['name'], $usedNames);
                $now = Util::now();
                $insert->execute([
                    $cameraName,
                    (string)$stream['source'],
                    $serverId,
                    'manual',
                    null,
                    null,
                    0,
                    60,
                    trim((string)$stream['retentionDays']) ?: '7d',
                    !empty($stream['archiveEnabled']) ? 1 : 0,
                    !empty($stream['webrtcFastStart']) ? 1 : 0,
                    !empty($stream['eventArchiveRetentionEnabled']) ? 1 : 0,
                    self::cameraOptionalNonNegativeInt($stream['eventArchiveMaxBytes']),
                    self::cameraOptionalString($stream['eventArchiveMaxDuration']),
                    self::cameraOptionalString($stream['eventArchiveMaxAge']),
                    !empty($stream['timelapseEnabled']) ? 1 : 0,
                    self::cameraPositiveInt($stream['timelapseFramesPerHour'], 60),
                    self::cameraOptionalString($stream['timelapseRetentionDays']),
                    self::cameraPositiveInt($stream['timelapsePlaybackFps'], 25),
                    self::cameraTimelineRepairMode($stream['directArchiveVideoTimelineRepairMode']),
                    self::cameraAudioCodec($stream['audioCodec']),
                    'read_only',
                    null,
                    null,
                    0,
                    0,
                    16,
                    0,
                    (string)$stream['name'],
                    $now,
                    1,
                    $syncMessage,
                    $now,
                    $now,
                ]);
                $cameraId = DB::lastInsertId('cameras');
                self::replaceLinks('camera_groups', 'camera_id', $cameraId, 'group_id', $groupIds);
                $importedNames[] = (string)$stream['name'];
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SesamePortal camera import failed: ' . $error->getMessage());
            return ['ok' => false, 'message' => self::t('cameras.importFailed', 'Не удалось импортировать выбранные потоки')];
        }

        Audit::log('camera.import', json_encode([
            'serverId' => $serverId,
            'server' => (string)$server['name'],
            'count' => count($importedNames),
            'streams' => array_slice($importedNames, 0, 50),
            'truncated' => count($importedNames) > 50,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'ok' => true,
            'message' => sprintf(self::t('cameras.importDone', 'Добавлено потоков: %d'), count($importedNames)),
        ];
    }

    private static function cameraImportUniqueName(string $displayName, string $streamName, string $serverName, array &$usedNames): string
    {
        $base = trim($displayName) ?: $streamName;
        $candidate = $base;
        $suffix = trim($serverName) ?: 'DVR';
        $number = 1;
        while (isset($usedNames[self::cameraImportNameKey($candidate)])) {
            $candidate = $base . ' · ' . $suffix . ($number > 1 ? ' ' . $number : '');
            $number++;
        }
        $usedNames[self::cameraImportNameKey($candidate)] = true;
        return $candidate;
    }

    private static function cameraImportNameKey(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private static function cameraImportBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
