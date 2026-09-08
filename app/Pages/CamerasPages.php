<?php

declare(strict_types=1);

namespace SesamePortal;

use PDO;

trait CamerasPages
{
    private static function cameras(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $selection = Util::post('server_selection') === 'auto' ? 'auto' : 'manual';
                $controlMode = self::cameraControlMode(Util::post('dvr_control_mode'));
                $serverId = (int)Util::post('server_id', 0) ?: null;
                if ($controlMode === 'edge_agent') {
                    $selection = 'manual';
                } elseif (!$serverId) {
                    $selection = 'auto';
                }
                if ($selection === 'auto' && !$serverId) {
                    $serverId = self::randomActiveServerId();
                }
                $sourceUrl = trim((string)Util::post('source_url'));
                [$name, $stream] = self::cameraNamesFromInput(
                    [
                        'display_name' => Util::post('display_name', Util::post('name')),
                        'dvr_stream_name' => Util::post('dvr_stream_name'),
                    ],
                    $id > 0 ? Repo::camera($id) : null
                );
                $agentId = trim((string)Util::post('agent_id'));
                $agentCameraId = trim((string)Util::post('agent_camera_id'));
                if ($name === '' || $stream === '') {
                    $message = I18n::t('cameras.nameOrStreamRequired', 'Stream title or technical stream name is required');
                } elseif (!Util::isDvrStreamName($stream)) {
                    $message = I18n::t('cameras.invalidStreamName', 'Technical stream name must start with a Latin letter or digit and can contain only Latin letters, digits, dot, hyphen, and underscore, up to 128 characters.');
                } elseif ($controlMode === 'managed' && $sourceUrl === '') {
                    $message = I18n::t('cameras.sourceRequired', 'Source URL is required for full DVR management mode');
                } elseif ($controlMode === 'edge_agent' && (!$serverId || $agentId === '' || $agentCameraId === '')) {
                    $message = I18n::t('cameras.agentRequired', 'Edge-agent mode requires server, Agent ID, and Agent camera ID');
                } else {
                    $values = [
                        $name,
                        $sourceUrl,
                        $serverId,
                        $selection,
                        self::nullableFloat(Util::post('latitude')),
                        self::nullableFloat(Util::post('longitude')),
                        (int)Util::post('direction_deg', 0),
                        (int)Util::post('view_angle_deg', 60),
                        Util::post('retention_days', '7d'),
                        Util::checkbox('archive_enabled'),
                        Util::checkbox('webrtc_fast_start'),
                        Util::checkbox('event_archive_retention_enabled'),
                        self::cameraEventArchiveMaxBytesFromPost(),
                        self::cameraOptionalString(Util::post('event_archive_max_duration')),
                        self::cameraOptionalString(Util::post('event_archive_max_age')),
                        Util::checkbox('timelapse_enabled'),
                        self::cameraPositiveInt(Util::post('timelapse_frames_per_hour', 60), 60),
                        self::cameraOptionalString(Util::post('timelapse_retention_days')),
                        self::cameraPositiveInt(Util::post('timelapse_playback_fps', 25), 25),
                        self::cameraTimelineRepairMode(Util::post('direct_archive_video_timeline_repair_mode')),
                        self::cameraAudioCodec(Util::post('audio_codec', 'copy')),
                        $controlMode,
                        $agentId !== '' ? $agentId : null,
                        $agentCameraId !== '' ? $agentCameraId : null,
                        Util::checkbox('onvif_events_requested'),
                        Util::checkbox('watermark_enabled'),
                        self::watermarkIntensity(Util::post('watermark_intensity', 16)),
                        Util::checkbox('blocked'),
                        $stream,
                    ];
                    if ($id > 0) {
                        $pdo->prepare('UPDATE cameras SET name=?, source_url=?, server_id=?, server_selection=?, latitude=?, longitude=?, direction_deg=?, view_angle_deg=?, retention_days=?, archive_enabled=?, webrtc_fast_start=?, event_archive_retention_enabled=?, event_archive_max_bytes=?, event_archive_max_duration=?, event_archive_max_age=?, timelapse_enabled=?, timelapse_frames_per_hour=?, timelapse_retention_days=?, timelapse_playback_fps=?, direct_archive_video_timeline_repair_mode=?, audio_codec=?, dvr_control_mode=?, agent_id=?, agent_camera_id=?, onvif_events_requested=?, watermark_enabled=?, watermark_intensity=?, blocked=?, dvr_stream_name=?, updated_at=? WHERE id=?')
                            ->execute([...$values, Util::now(), $id]);
                    } else {
                        $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, archive_enabled, webrtc_fast_start, event_archive_retention_enabled, event_archive_max_bytes, event_archive_max_duration, event_archive_max_age, timelapse_enabled, timelapse_frames_per_hour, timelapse_retention_days, timelapse_playback_fps, direct_archive_video_timeline_repair_mode, audio_codec, dvr_control_mode, agent_id, agent_camera_id, onvif_events_requested, watermark_enabled, watermark_intensity, blocked, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([...$values, Util::now(), Util::now()]);
                        $id = DB::lastInsertId('cameras');
                    }
                    self::replaceLinks('camera_groups', 'camera_id', $id, 'group_id', $_POST['group_ids'] ?? []);
                    $sync = DvrClient::syncCamera($id);
                    $message = self::cameraSaveNotice($sync);
                    Audit::log('camera.save', $name . ' mode=' . $controlMode . ' sync=' . $sync['message']);
                }
            } elseif ($action === 'delete' && $id > 0) {
                $camera = Repo::camera($id);
                if (!$camera) {
                    $message = self::t('cameras.deleteMissing', 'Камера уже удалена или не найдена');
                } elseif (Util::checkbox('confirm_delete') !== 1) {
                    $message = self::t('cameras.deleteConfirmRequired', 'Подтвердите удаление камеры');
                } else {
                    $deleteDvrStream = Util::checkbox('delete_dvr_stream') === 1;
                    $dvrMessage = '';
                    if ($deleteDvrStream) {
                        $deleteResult = DvrClient::deleteCameraStream($id, true);
                        $dvrMessage = $deleteResult['message'];
                        if (!$deleteResult['ok']) {
                            $message = self::t('cameras.deleteDvrFailed', 'Поток на DVR не удалён') . ': ' . $dvrMessage;
                            Audit::log('camera.delete_failed', 'camera_id=' . $id . ' dvr=yes result=' . $dvrMessage);
                        }
                    }

                    if ($message === '') {
                        $pdo->prepare('DELETE FROM camera_groups WHERE camera_id=?')->execute([$id]);
                        $pdo->prepare('DELETE FROM cameras WHERE id=?')->execute([$id]);
                        $message = self::t('cameras.deleteDone', 'Камера удалена');
                        if ($dvrMessage !== '') {
                            $message .= ': ' . $dvrMessage;
                        }
                        Audit::log('camera.delete', 'camera_id=' . $id . ' dvr=' . ($deleteDvrStream ? 'yes' : 'no') . ' result=' . $dvrMessage);
                    }
                }
            } elseif ($action === 'sync' && $id > 0) {
                $result = DvrClient::syncCamera($id);
                $message = self::cameraSyncNotice($result);
            }
        }

        $edit = self::rowById('cameras', (int)($_GET['edit'] ?? 0));
        $form = self::cameraFormDefaults($edit);
        $delete = self::cameraDeleteCandidate((int)($_GET['delete'] ?? 0));
        $linkedGroups = $edit ? self::linkedIds('camera_groups', 'camera_id', (int)$edit['id'], 'group_id') : [];
        $servers = Repo::all('dvr_servers', 'name ASC');
        $groups = self::groupRowsWithDisplayLabels(Repo::all('portal_groups', 'name ASC'));
        $list = self::filteredCameras();
        $backPath = self::safeLocalPath((string)($_GET['back'] ?? ''));
        $cameras = $list['rows'];
        $defaultMapCenter = PortalSettings::mapCenter();
        self::layout(self::t('cameras.title', 'Камеры'), function () use ($edit, $form, $delete, $servers, $groups, $linkedGroups, $cameras, $message, $list, $backPath, $defaultMapCenter) {
            self::notice($message);
            if ($delete) {
                self::cameraDeletePanel($delete);
            }
            echo '<div class="admin-grid"><section class="panel"><div class="section-head"><h2>' . ($edit ? self::t('cameras.edit', 'Изменить камеру') : self::t('cameras.new', 'Новая камера')) . '</h2>';
            if ($backPath !== '') {
                echo '<a class="btn" href="' . Util::h($backPath) . '">' . self::t('action.back', 'Назад') . '</a>';
            }
            if ($edit) {
                echo '<a class="btn" href="' . Util::h(self::tableActionUrl('/admin/cameras', [], $list)) . '">' . self::t('cameras.new', 'Новая камера') . '</a>';
            }
            echo '<a class="btn" href="/admin/cameras/import">' . self::t('cameras.importFromDvr', 'Импорт с DVR') . '</a>';
            echo '</div>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('cameras.displayName', 'Название потока') . '<input name="display_name" value="' . Util::h($form['name'] ?? '') . '"></label>';
            $edgeAgentMode = ($form['dvr_control_mode'] ?? 'managed') === 'edge_agent';
            echo '<label>' . self::t('cameras.mode', 'Режим камеры') . '<select name="dvr_control_mode" data-camera-mode-select>';
            echo '<option value="managed" ' . (($form['dvr_control_mode'] ?? 'managed') === 'managed' ? 'selected' : '') . '>' . self::t('cameras.modeManaged', 'Полное управление на DVR') . '</option>';
            echo '<option value="edge_agent" ' . (($form['dvr_control_mode'] ?? '') === 'edge_agent' ? 'selected' : '') . '>' . self::t('cameras.modeEdgeAgent', 'Edge Agent push stream') . '</option>';
            echo '<option value="read_only" ' . (($form['dvr_control_mode'] ?? '') === 'read_only' ? 'selected' : '') . '>' . self::t('cameras.modeReadOnly', 'Read-only поток с DVR') . '</option></select></label>';
            echo '<label>' . self::t('cameras.sourceUrl', 'URL источника') . '<input name="source_url" value="' . Util::h($form['source_url'] ?? '') . '"></label>';
            echo '<label>' . self::t('cameras.server', 'Сервер') . '<select name="server_id"><option value="">' . self::t('cameras.serverAutoNone', 'Авто/не выбран') . '</option>';
            foreach ($servers as $server) {
                echo '<option value="' . (int)$server['id'] . '" ' . (($form['server_id'] ?? '') == $server['id'] ? 'selected' : '') . '>' . Util::h($server['name']) . '</option>';
            }
            echo '</select></label>';
            echo '<label>' . self::t('cameras.serverSelection', 'Выбор сервера') . '<select name="server_selection"><option value="manual">' . self::t('cameras.selectionManual', 'конкретный') . '</option><option value="auto" ' . (($form['server_selection'] ?? '') === 'auto' ? 'selected' : '') . '>' . self::t('cameras.selectionAuto', 'автоматический случайный') . '</option></select></label>';
            $streamNameHint = self::t('cameras.streamNameHint', 'Starts with A-Z, a-z, or 0-9; then A-Z, a-z, 0-9, dot, hyphen, and underscore are allowed. Leave empty to generate it.');
            echo '<label>' . self::t('cameras.streamName', 'Техническое имя потока') . '<input name="dvr_stream_name" value="' . Util::h($form['dvr_stream_name'] ?? '') . '" maxlength="' . Util::DVR_STREAM_NAME_MAX_BYTES . '" pattern="' . Util::DVR_STREAM_NAME_HTML_PATTERN . '" placeholder="domofon-g-sukhum-ul-kiaraz-9-p1" autocomplete="off" autocapitalize="none" spellcheck="false" title="' . Util::h($streamNameHint) . '"></label>';
            echo '<div class="form-row" data-camera-agent-field' . ($edgeAgentMode ? '' : ' hidden') . '><label>' . self::t('cameras.agentId', 'Agent ID') . '<input name="agent_id" value="' . Util::h($form['agent_id'] ?? '') . '"></label><label>' . self::t('cameras.agentCameraId', 'Agent camera ID') . '<input name="agent_camera_id" value="' . Util::h($form['agent_camera_id'] ?? '') . '"></label></div>';
            echo '<label class="check" data-camera-agent-field' . ($edgeAgentMode ? '' : ' hidden') . '><input type="checkbox" name="onvif_events_requested" ' . (!empty($form['onvif_events_requested']) ? 'checked' : '') . '> ' . self::t('cameras.onvifEvents', 'Запускать ONVIF events через агента') . '</label>';
            $watermarkEnabled = !empty($form['watermark_enabled']);
            echo '<div class="form-row"><label class="check"><input type="checkbox" name="watermark_enabled" data-watermark-toggle ' . ($watermarkEnabled ? 'checked' : '') . '> ' . self::t('cameras.watermarkEnabled', 'Показывать водяной знак с логином в плеере') . '</label>';
            echo '<label data-watermark-dependent' . ($watermarkEnabled ? '' : ' hidden') . '>' . self::t('cameras.watermarkIntensity', 'Интенсивность водяного знака, %') . '<input name="watermark_intensity" type="number" min="1" max="100" value="' . Util::h(self::watermarkIntensity($form['watermark_intensity'] ?? 16)) . '"></label></div>';
            $lat = $form['latitude'] ?? '';
            $lng = $form['longitude'] ?? '';
            echo '<details class="camera-location-options" data-camera-location-options><summary>' . self::t('cameras.locationOptions', 'Расположение камеры') . '</summary>';
            echo '<div class="form-row"><label>' . self::t('geo.latitude', 'Широта') . '<input id="camera-latitude" name="latitude" value="' . Util::h($lat) . '"></label><label>' . self::t('geo.longitude', 'Долгота') . '<input id="camera-longitude" name="longitude" value="' . Util::h($lng) . '"></label></div>';
            echo '<div class="camera-position-field"><div class="camera-position-head"><strong>' . self::t('cameras.position', 'Положение на карте') . '</strong><button type="button" class="camera-map-clear">' . self::t('cameras.clearPosition', 'Очистить точку') . '</button></div>';
            self::renderMapProviderConfig();
            echo '<div id="camera-position-map" class="camera-position-map" data-lat="' . Util::h($lat) . '" data-lng="' . Util::h($lng)
                . '" data-default-lat="' . Util::h(PortalSettings::formatCoordinate((float)$defaultMapCenter['latitude']))
                . '" data-default-lng="' . Util::h(PortalSettings::formatCoordinate((float)$defaultMapCenter['longitude'])) . '"></div></div>';
            echo '<div class="form-row"><label>' . self::t('cameras.direction', 'Направление') . '<input id="camera-direction" name="direction_deg" type="number" min="0" max="359" value="' . Util::h($form['direction_deg'] ?? 0) . '"></label><label>' . self::t('cameras.viewAngle', 'Угол обзора') . '<input name="view_angle_deg" type="number" min="1" max="180" value="' . Util::h($form['view_angle_deg'] ?? 60) . '"></label></div>';
            echo '</details>';
            $timelineRepairMode = self::cameraTimelineRepairMode($form['direct_archive_video_timeline_repair_mode'] ?? null) ?? '';
            $audioCodec = self::cameraAudioCodec($form['audio_codec'] ?? 'copy');
            $archiveEnabled = !empty($form['archive_enabled']);
            $eventArchiveEnabled = !empty($form['event_archive_retention_enabled']);
            $timelapseEnabled = !empty($form['timelapse_enabled']);
            echo '<details class="camera-stream-options" data-dvr-stream-options><summary>' . self::t('cameras.dvrStreamOptions', 'Настройки потока DVR') . '</summary>';
            echo '<div class="form-row camera-dvr-toggle-row"><label class="check"><input type="checkbox" name="archive_enabled" data-dvr-toggle="archive" ' . ($archiveEnabled ? 'checked' : '') . '> ' . self::t('cameras.archiveEnabled', 'Пишет архив') . '</label>';
            echo '<label data-dvr-dependent="archive"' . ($archiveEnabled ? '' : ' hidden') . '>' . self::t('cameras.retention', 'Глубина архива') . '<input name="retention_days" value="' . Util::h($form['retention_days'] ?? '7d') . '"></label></div>';
            echo '<label class="check"><input type="checkbox" name="webrtc_fast_start" ' . (!empty($form['webrtc_fast_start']) ? 'checked' : '') . '> ' . self::t('cameras.webrtcFastStart', 'WebRTC FastStart') . '</label>';
            echo '<label class="check"><input type="checkbox" name="event_archive_retention_enabled" data-dvr-toggle="event-archive" ' . ($eventArchiveEnabled ? 'checked' : '') . '> ' . self::t('cameras.eventArchiveRetentionEnabled', 'Сохранять архив по событиям') . '</label>';
            echo '<div class="camera-dvr-dependent" data-dvr-dependent="event-archive"' . ($eventArchiveEnabled ? '' : ' hidden') . '>';
            echo '<div class="form-row"><label>' . self::t('cameras.eventArchiveMaxMb', 'Лимит размера архива событий, MB') . '<input name="event_archive_max_mb" type="number" min="0" step="0.01" value="' . Util::h(self::eventArchiveBytesToMegabytesInput($form['event_archive_max_bytes'] ?? null)) . '"></label><label>' . self::t('cameras.eventArchiveMaxDuration', 'Максимальная длительность архива событий') . '<input name="event_archive_max_duration" value="' . Util::h($form['event_archive_max_duration'] ?? '') . '" placeholder="6h"></label></div>';
            echo '<label>' . self::t('cameras.eventArchiveMaxAge', 'Срок хранения архива событий') . '<input name="event_archive_max_age" value="' . Util::h($form['event_archive_max_age'] ?? '') . '" placeholder="30d"></label>';
            echo '</div>';
            echo '<label class="check"><input type="checkbox" name="timelapse_enabled" data-dvr-toggle="timelapse" ' . ($timelapseEnabled ? 'checked' : '') . '> ' . self::t('cameras.timelapseEnabled', 'Писать timelapse') . '</label>';
            echo '<div class="camera-dvr-dependent" data-dvr-dependent="timelapse"' . ($timelapseEnabled ? '' : ' hidden') . '>';
            echo '<div class="form-row"><label>' . self::t('cameras.timelapseFramesPerHour', 'Кадров в час') . '<input name="timelapse_frames_per_hour" type="number" min="1" value="' . Util::h(self::cameraPositiveInt($form['timelapse_frames_per_hour'] ?? 60, 60)) . '"></label><label>' . self::t('cameras.timelapseRetentionDays', 'Хранение timelapse') . '<input name="timelapse_retention_days" value="' . Util::h($form['timelapse_retention_days'] ?? '') . '" placeholder="30d"></label></div>';
            echo '<label>' . self::t('cameras.timelapsePlaybackFps', 'FPS воспроизведения') . '<input name="timelapse_playback_fps" type="number" min="1" value="' . Util::h(self::cameraPositiveInt($form['timelapse_playback_fps'] ?? 25, 25)) . '"></label>';
            echo '</div>';
            echo '<div class="form-row"><label>' . self::t('cameras.timelineRepairMode', 'MP4 timeline repair') . '<select name="direct_archive_video_timeline_repair_mode">';
            echo '<option value="" ' . ($timelineRepairMode === '' ? 'selected' : '') . '>' . self::t('cameras.timelineRepairDefault', 'По умолчанию') . '</option>';
            echo '<option value="auto" ' . ($timelineRepairMode === 'auto' ? 'selected' : '') . '>auto</option>';
            echo '<option value="always" ' . ($timelineRepairMode === 'always' ? 'selected' : '') . '>always</option>';
            echo '<option value="off" ' . ($timelineRepairMode === 'off' ? 'selected' : '') . '>off</option>';
            echo '</select></label><label>' . self::t('cameras.audioCodec', 'Аудиокодек') . '<select name="audio_codec">';
            echo '<option value="copy" ' . ($audioCodec === 'copy' ? 'selected' : '') . '>' . self::t('cameras.audioCodecCopy', 'Копировать') . '</option>';
            echo '<option value="aac" ' . ($audioCodec === 'aac' ? 'selected' : '') . '>' . self::t('cameras.audioCodecAac', 'Транскодировать AAC') . '</option>';
            echo '</select></label></div></details>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($form['blocked']) ? 'checked' : '') . '> ' . self::t('cameras.blocked', 'Заблокирована') . '</label>';
            self::groupCheckboxTree(self::t('cameras.groups', 'Группы'), 'group_ids[]', $groups, $linkedGroups);
            echo '<button class="primary">' . self::t('action.saveSync', 'Сохранить и синхронизировать') . '</button></form></section>';
            self::table(self::t('cameras.title', 'Камеры'), ['name', 'server_name', 'dvr_control_mode', 'agent_id', 'agent_camera_id', 'retention_days', 'archive_enabled', 'last_sync_message'], $cameras, '/admin/cameras', true, $list);
            echo '</div>';
        });
    }

    private static function cameraDeleteCandidate(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = DB::pdo()->prepare('SELECT c.*, s.name AS server_name, s.base_url AS server_base_url, s.blocked AS server_blocked, s.management_token_enc AS server_management_token_enc
            FROM cameras c
            LEFT JOIN dvr_servers s ON s.id = c.server_id
            WHERE c.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function cameraDeletePanel(array $camera): void
    {
        $stream = trim((string)($camera['dvr_stream_name'] ?: $camera['name']));
        $canDeleteDvr = !empty($camera['server_id'])
            && (int)($camera['server_blocked'] ?? 0) === 0
            && ($camera['dvr_control_mode'] ?? 'managed') !== 'read_only'
            && trim((string)($camera['server_management_token_enc'] ?? '')) !== ''
            && $stream !== '';

        echo '<section class="panel delete-confirm"><div class="section-head"><h2>' . self::t('cameras.deleteTitle', 'Удалить камеру') . '</h2><a href="/admin/cameras">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '<div class="alert warn">';
        echo '<strong>' . self::t('cameras.deleteWarning', 'Это действие нельзя отменить.') . '</strong> ';
        echo self::t('cameras.deleteWarningText', 'Сначала подтвердите удаление камеры из портала. Отдельным флажком можно удалить связанный поток на DVR вместе с архивом.');
        echo '</div>';
        echo '<dl class="delete-meta">';
        echo '<dt>' . self::t('cameras.name', 'Имя') . '</dt><dd>' . Util::h($camera['name']) . '</dd>';
        echo '<dt>' . self::t('cameras.streamName', 'Имя потока SesameDVR') . '</dt><dd>' . Util::h($stream ?: '-') . '</dd>';
        echo '<dt>' . self::t('cameras.server', 'Сервер') . '</dt><dd>' . Util::h($camera['server_name'] ?: '-') . '</dd>';
        echo '</dl>';
        echo '<form method="post" action="/admin/cameras" class="form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$camera['id'] . '">';
        echo '<label class="check"><input type="checkbox" name="confirm_delete" required> ' . self::t('cameras.confirmDelete', 'Подтверждаю удаление камеры из портала') . '</label>';
        if ($canDeleteDvr) {
            echo '<label class="check"><input type="checkbox" name="delete_dvr_stream"> ' . self::t('cameras.deleteDvrStream', 'Также удалить поток на DVR и очистить архив, превью и индексы') . '</label>';
        } else {
            echo '<p class="muted">' . self::t('cameras.deleteDvrUnavailable', 'Удаление потока на DVR недоступно для этой камеры: проверьте сервер, token управления и режим управления.') . '</p>';
        }
        echo '<div class="form-actions"><button class="danger">' . self::t('action.delete', 'Удалить') . '</button><a href="/admin/cameras">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '</form></section>';
    }

    private static function filteredCameras(int $pageSize = 25): array
    {
        $filters = self::cameraListFilters();
        $page = $filters['page'];
        $where = [];
        $params = [];
        $join = ' LEFT JOIN dvr_servers s ON s.id = c.server_id';

        if ($filters['q'] !== '') {
            $columns = ['c.name', 'c.source_url', 'c.dvr_stream_name', 'c.dvr_control_mode', 'c.agent_id', 'c.agent_camera_id', 's.name', 'c.last_sync_message'];
            $where[] = '(' . implode(' OR ', array_map([DB::class, 'caseInsensitiveLike'], $columns)) . ')';
            array_push($params, ...array_fill(0, count($columns), '%' . $filters['q'] . '%'));
        }

        if ($filters['server_id'] === 'none') {
            $where[] = 'c.server_id IS NULL';
        } elseif ($filters['server_id'] !== '') {
            $where[] = 'c.server_id = ?';
            $params[] = (int)$filters['server_id'];
        }

        if ($filters['mode'] !== '') {
            $where[] = 'c.dvr_control_mode = ?';
            $params[] = $filters['mode'];
        }

        if ($filters['archive'] === 'on') {
            $where[] = 'c.archive_enabled = 1';
        } elseif ($filters['archive'] === 'off') {
            $where[] = 'c.archive_enabled = 0';
        }

        if ($filters['sync'] === 'ok') {
            $where[] = 'c.last_sync_ok = 1';
        } elseif ($filters['sync'] === 'bad') {
            $where[] = 'c.last_sync_ok = 0';
        } elseif ($filters['sync'] === 'readonly') {
            $where[] = '(' . DB::caseInsensitiveLike('c.last_sync_message') . ' OR ' . DB::caseInsensitiveLike('c.last_sync_message') . ' OR ' . DB::caseInsensitiveLike('c.last_sync_message') . ')';
            array_push($params, '%read-only%', '%read_only%', '%readonly%');
        } elseif ($filters['sync'] === 'empty') {
            $where[] = '(c.last_sync_message IS NULL OR c.last_sync_message = \'\')';
        }

        if ($filters['group_id'] > 0) {
            $join .= ' JOIN camera_groups cg_filter ON cg_filter.camera_id = c.id';
            $groupIds = Repo::groupBranchIds([$filters['group_id']], true, true);
            if (!$groupIds) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'cg_filter.group_id IN (' . self::sqlPlaceholders($groupIds) . ')';
                array_push($params, ...$groupIds);
            }
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(DISTINCT c.id) FROM cameras c' . $join . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url FROM cameras c' . $join . $sqlWhere . ' ORDER BY ' . self::cameraListOrderSql($filters['sort'], $filters['dir']) . ' LIMIT ? OFFSET ?');
        $bind = [...$params, $pageSize, ($page - 1) * $pageSize];
        foreach ($bind as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return [
            'rows' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            ...$filters,
        ];
    }

    private static function cameraListFilters(): array
    {
        $serverId = trim((string)($_GET['server_id'] ?? ''));
        if ($serverId !== 'none' && (!ctype_digit($serverId) || (int)$serverId <= 0)) {
            $serverId = '';
        }

        $mode = (string)($_GET['mode'] ?? '');
        if (!in_array($mode, ['managed', 'edge_agent', 'read_only'], true)) {
            $mode = '';
        }

        $archive = (string)($_GET['archive'] ?? '');
        if (!in_array($archive, ['on', 'off'], true)) {
            $archive = '';
        }

        $sync = (string)($_GET['sync'] ?? '');
        if (!in_array($sync, ['ok', 'bad', 'readonly', 'empty'], true)) {
            $sync = '';
        }

        $sort = (string)($_GET['sort'] ?? 'name');
        if (!array_key_exists($sort, self::cameraListSortColumns())) {
            $sort = 'name';
        }

        $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return [
            'q' => trim((string)($_GET['q'] ?? '')),
            'page' => max(1, (int)($_GET['page'] ?? 1)),
            'server_id' => $serverId,
            'mode' => $mode,
            'archive' => $archive,
            'sync' => $sync,
            'group_id' => max(0, (int)($_GET['group_id'] ?? 0)),
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    private static function cameraListSortColumns(): array
    {
        return [
            'name' => 'c.name',
            'stream' => 'c.dvr_stream_name',
            'server' => 'COALESCE(s.name, \'\')',
            'mode' => 'c.dvr_control_mode',
            'archive' => 'c.archive_enabled',
            'retention' => 'c.retention_days',
            'sync' => 'COALESCE(c.last_sync_ok, -1)',
            'updated' => 'c.updated_at',
            'created' => 'c.created_at',
        ];
    }

    private static function cameraListOrderSql(string $sort, string $dir): string
    {
        $columns = self::cameraListSortColumns();
        $column = $columns[$sort] ?? $columns['name'];
        $direction = $dir === 'desc' ? 'DESC' : 'ASC';
        $tieDirection = $sort === 'name' ? $direction : 'ASC';
        return $column . ' ' . $direction . ', c.name ' . $tieDirection . ', c.id ASC';
    }

    private static function cameraTableFilters(array $pager): void
    {
        $servers = Repo::all('dvr_servers', 'name ASC');
        $groups = self::groupRowsWithDisplayLabels(Repo::all('portal_groups', 'name ASC'));
        $sortOptions = [
            'name' => self::columnLabel('name'),
            'stream' => self::t('cameras.streamName', 'Техническое имя потока'),
            'server' => self::columnLabel('server_name'),
            'mode' => self::columnLabel('dvr_control_mode'),
            'archive' => self::columnLabel('archive_enabled'),
            'retention' => self::columnLabel('retention_days'),
            'sync' => self::columnLabel('last_sync_message'),
            'updated' => self::t('cameraFilter.updated', 'Обновлено'),
            'created' => self::t('cameraFilter.created', 'Создано'),
        ];

        echo '<form method="get" action="/admin/cameras" class="table-search camera-admin-filters">';
        echo '<input name="q" value="' . Util::h($pager['q'] ?? '') . '" placeholder="' . Util::h(self::t('filter.cameraSearchPlaceholder', 'Название, поток или IP')) . '">';
        echo '<select name="server_id" aria-label="' . Util::h(self::t('cameras.server', 'Сервер')) . '">';
        self::selectOption('', self::t('cameraFilter.allServers', 'Все серверы'), (string)($pager['server_id'] ?? ''));
        self::selectOption('none', self::t('common.noServer', 'Нет сервера'), (string)($pager['server_id'] ?? ''));
        foreach ($servers as $server) {
            self::selectOption((string)$server['id'], (string)$server['name'], (string)($pager['server_id'] ?? ''));
        }
        echo '</select>';
        echo '<select name="mode" aria-label="' . Util::h(self::t('cameras.mode', 'Режим камеры')) . '">';
        self::selectOption('', self::t('cameraFilter.allModes', 'Все режимы'), (string)($pager['mode'] ?? ''));
        self::selectOption('managed', self::t('cameras.modeManaged', 'Полное управление на DVR'), (string)($pager['mode'] ?? ''));
        self::selectOption('edge_agent', self::t('cameras.modeEdgeAgent', 'Edge Agent push stream'), (string)($pager['mode'] ?? ''));
        self::selectOption('read_only', self::t('cameras.modeReadOnly', 'Read-only поток с DVR'), (string)($pager['mode'] ?? ''));
        echo '</select>';
        echo '<select name="archive" aria-label="' . Util::h(self::t('cameras.archiveEnabled', 'Пишет архив')) . '">';
        self::selectOption('', self::t('cameraFilter.allArchive', 'Архив: все'), (string)($pager['archive'] ?? ''));
        self::selectOption('on', self::t('cameraFilter.archiveOn', 'Архив включён'), (string)($pager['archive'] ?? ''));
        self::selectOption('off', self::t('cameraFilter.archiveOff', 'Архив выключен'), (string)($pager['archive'] ?? ''));
        echo '</select>';
        echo '<select name="sync" aria-label="' . Util::h(self::columnLabel('last_sync_message')) . '">';
        self::selectOption('', self::t('cameraFilter.allSync', 'Синхронизация: все'), (string)($pager['sync'] ?? ''));
        self::selectOption('ok', self::t('cameraFilter.syncOk', 'Синхронизация ok'), (string)($pager['sync'] ?? ''));
        self::selectOption('bad', self::t('cameraFilter.syncBad', 'Синхронизация с ошибкой'), (string)($pager['sync'] ?? ''));
        self::selectOption('readonly', self::t('cameraFilter.syncReadonly', 'Read-only'), (string)($pager['sync'] ?? ''));
        self::selectOption('empty', self::t('cameraFilter.syncEmpty', 'Без результата'), (string)($pager['sync'] ?? ''));
        echo '</select>';
        echo '<select name="group_id" aria-label="' . Util::h(self::t('groups.title', 'Группы')) . '">';
        self::selectOption('0', self::t('cameraFilter.allGroups', 'Все группы'), (string)(int)($pager['group_id'] ?? 0));
        foreach ($groups as $group) {
            self::selectOption((string)$group['id'], (string)($group['display_name'] ?? $group['name']), (string)(int)($pager['group_id'] ?? 0));
        }
        echo '</select>';
        echo '<select name="sort" aria-label="' . Util::h(self::t('cameraFilter.sort', 'Сортировка')) . '">';
        foreach ($sortOptions as $value => $label) {
            self::selectOption($value, self::t('cameraFilter.sortBy', 'Сортировка') . ': ' . $label, (string)($pager['sort'] ?? 'name'));
        }
        echo '</select>';
        echo '<select name="dir" aria-label="' . Util::h(self::t('cameraFilter.direction', 'Направление сортировки')) . '">';
        self::selectOption('asc', self::t('cameraFilter.asc', 'По возрастанию'), (string)($pager['dir'] ?? 'asc'));
        self::selectOption('desc', self::t('cameraFilter.desc', 'По убыванию'), (string)($pager['dir'] ?? 'asc'));
        echo '</select>';
        echo '<button>' . self::t('action.find', 'Найти') . '</button>';
        echo '<a class="camera-filter-reset" href="/admin/cameras">' . self::t('cameraFilter.reset', 'Сбросить') . '</a>';
        echo '</form>';
    }
}
