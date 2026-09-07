<?php

declare(strict_types=1);

namespace SesamePortal;

trait AppViewerTrait
{
    private static function viewer(string $mode): void
    {
        $user = Auth::requireLogin();
        $filter = (string)($_GET['filter'] ?? 'all');
        $searchQuery = self::viewerSearchQuery();
        $cols = self::viewerColumns($user, $mode !== 'map');
        $previewRefresh = self::viewerPreviewRefresh();
        $cameraPager = null;
        if ($mode === 'map') {
            $cameras = Repo::accessibleMapCameras($user, $filter, $searchQuery);
        } else {
            $cameraPager = Repo::accessibleCamerasPage($user, $filter, $searchQuery, (int)($_GET['page'] ?? 1), self::viewerPageSize($cols));
            $cameras = $cameraPager['rows'];
        }
        $favorites = Repo::favoritesMap((int)$user['id']);

        $isAdmin = ($user['role'] ?? '') === 'admin';
        $canRename = $isAdmin || !empty($user['can_rename_cameras']);
        $title = $mode === 'map' ? self::t('nav.map', 'Карта') : self::t('cameras.title', 'Камеры');
        self::layout($title, function () use ($mode, $filter, $searchQuery, $cameras, $favorites, $cameraPager, $cols, $previewRefresh, $isAdmin, $canRename) {
            self::filters($mode, [], $filter, $searchQuery, $cols, $previewRefresh);
            if ($mode === 'map') {
                self::map($cameras, $favorites);
            } else {
                self::mosaic($cameras, $favorites, $cameraPager ?? [], $cols, $previewRefresh, $isAdmin, $canRename);
            }
        });
    }

    private static function viewerColumns(array $user, bool $saveQueryValue): int
    {
        $stored = self::normalizeViewerColumns($user['mosaic_columns'] ?? 3);
        if (!$saveQueryValue || !array_key_exists('cols', $_GET)) {
            return $stored;
        }

        $cols = self::normalizeViewerColumns($_GET['cols']);
        if ($cols !== $stored) {
            DB::pdo()->prepare('UPDATE users SET mosaic_columns = ? WHERE id = ?')
                ->execute([$cols, (int)$user['id']]);
        }
        return $cols;
    }

    private static function normalizeViewerColumns(mixed $cols): int
    {
        return min(6, max(2, (int)$cols));
    }

    private static function normalizeGridDimension(mixed $value): int
    {
        return min(6, max(2, (int)$value));
    }

    private static function viewerSearchQuery(): string
    {
        $query = trim((string)($_GET['q'] ?? ''));
        if ($query === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($query, 0, 120) : substr($query, 0, 120);
    }

    private static function viewerPageSize(int $cols): int
    {
        return match ($cols) {
            2 => 4,
            3 => 6,
            4 => 12,
            5 => 15,
            6 => 18,
            default => 6,
        };
    }

    private static function viewerPreviewRefresh(): string
    {
        $refresh = (string)($_GET['refresh'] ?? '30');
        return in_array($refresh, ['off', '10', '30', '60', '300'], true) ? $refresh : '30';
    }

    private static function mosaic(array $cameras, array $favorites, array $pager, int $cols, string $previewRefresh, bool $isAdmin, bool $canRename = false): void
    {
        $streamUnavailableByServer = self::mapStreamUnavailableByServer($cameras);
        echo '<section class="camera-grid cols-' . Util::h($cols) . '">';
        foreach ($cameras as $camera) {
            $player = self::playerUrl($camera);
            $preview = self::previewUrl($camera);
            $streamUnavailable = self::cameraStreamUnavailableFromMapMetrics($camera, $streamUnavailableByServer);
            $stateText = $streamUnavailable
                ? self::t('js.streamUnavailable', 'Поток недоступен')
                : self::t('js.previewUnavailable', 'Превью недоступно');
            $openPlayerLabel = self::t('viewer.openPlayer', 'Открыть плеер');
            $previewClass = 'preview' . ($preview ? ' is-loading' : ' no-preview') . ($streamUnavailable ? ' stream-unavailable' : '');
            echo '<article class="camera-card' . ($isAdmin ? ' camera-card-admin' : '') . '">';
            echo '<a class="' . Util::h($previewClass) . '" href="' . Util::h($player) . '" aria-label="' . Util::h($openPlayerLabel) . '">';
            if ($preview) {
                echo '<img data-preview-src="' . Util::h($preview) . '" data-preview-refresh="' . Util::h($previewRefresh) . '"';
                if ($previewRefresh !== 'off') {
                    echo ' data-preview-refresh-ms="' . Util::h((string)((int)$previewRefresh * 1000)) . '"';
                }
                echo ' alt="" loading="lazy" decoding="async" hidden>';
            }
            echo '<span class="preview-spinner" aria-hidden="true"></span><span class="preview-state">' . Util::h($stateText) . '</span><span class="preview-play" aria-hidden="true"></span><span class="sr-only">' . Util::h($openPlayerLabel) . '</span></a><div class="camera-meta"><strong>' . Util::h($camera['name']) . '</strong>';
            if ($isAdmin) {
                echo '<span>' . Util::h($camera['server_name'] ?? self::t('common.noServer', 'Без сервера')) . '</span>';
                echo '<span class="camera-tech">' . Util::h($camera['dvr_stream_name'] ?: $camera['name']) . '</span>';
            }
            echo '</div>';
            self::favoriteButton((int)$camera['id'], isset($favorites[(int)$camera['id']]));
            if ($canRename) {
                self::cameraRenameButton((int)$camera['id']);
            }
            if ($isAdmin) {
                self::cameraSettingsButton((int)$camera['id']);
            }
            echo '</article>';
        }
        echo '</section>';
        self::pager('/', $pager, [
            'filter' => ($pager['filter'] ?? 'all') === 'all' ? '' : ($pager['filter'] ?? ''),
            'cols' => $cols,
            'refresh' => $previewRefresh === '30' ? '' : $previewRefresh,
        ]);
    }

    private static function map(array $cameras, array $favorites): void
    {
        echo '<section class="panel map-panel"><div id="map" class="map"></div></section>';
        $payload = [];
        $streamUnavailableByServer = self::mapStreamUnavailableByServer($cameras);
        foreach ($cameras as $camera) {
            if ($camera['latitude'] === null || $camera['longitude'] === null) {
                continue;
            }
            $payload[] = [
                'id' => (int)$camera['id'],
                'name' => $camera['name'],
                'lat' => (float)$camera['latitude'],
                'lng' => (float)$camera['longitude'],
                'direction' => (int)$camera['direction_deg'],
                'viewAngle' => (int)$camera['view_angle_deg'],
                'favorite' => isset($favorites[(int)$camera['id']]),
                'player' => self::playerUrl($camera),
                'preview' => self::previewUrl($camera),
                'streamUnavailable' => self::cameraStreamUnavailableFromMapMetrics($camera, $streamUnavailableByServer),
                'server' => $camera['server_name'] ?? self::t('common.noServer', 'Без сервера'),
            ];
        }
        echo '<script>window.SESAME_CAMERAS = ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
    }

    private static function player(): void
    {
        $user = Auth::requireLogin();
        $cameraId = (int)($_GET['id'] ?? 0);
        if (!Repo::cameraAllowedForUser($user, $cameraId)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $stmt = DB::pdo()->prepare(
            'SELECT c.*, s.name AS server_name, s.base_url AS server_url
             FROM cameras c
             LEFT JOIN dvr_servers s ON s.id = c.server_id
             WHERE c.id = ? AND c.blocked = 0'
        );
        $stmt->execute([$cameraId]);
        $camera = $stmt->fetch();
        if (!$camera) {
            http_response_code(404);
            echo 'Camera not found';
            return;
        }

        $back = self::safeBackPath((string)($_GET['back'] ?? ($_SERVER['HTTP_REFERER'] ?? '/')));
        $settingsUrl = '';
        if (($user['role'] ?? '') === 'admin') {
            $settingsBack = self::safeLocalPath((string)($_SERVER['REQUEST_URI'] ?? ''));
            if ($settingsBack === '') {
                $settingsBack = '/viewer/player?' . http_build_query(['id' => $cameraId]);
            }
            $settingsUrl = '/admin/cameras?' . http_build_query([
                'edit' => $cameraId,
                'back' => $settingsBack,
            ]);
        }
        $embed = self::embedUrl(
            $camera,
            (string)($user['daily_token'] ?? ''),
            $back,
            self::t('action.back', 'Назад'),
            $settingsUrl,
            self::t('settings.title', 'Настройки'),
            !self::userArchiveHidden($user),
            self::playerArchiveTimeQuery($user, (string)($_GET['start'] ?? ''), (string)($_GET['end'] ?? ''))
        );
        $watermarkLogin = (int)($camera['watermark_enabled'] ?? 0) === 1 ? (string)$user['login'] : '';
        $watermarkAlpha = number_format(self::watermarkIntensity($camera['watermark_intensity'] ?? 16) / 100, 2, '.', '');
        self::layout(self::t('player.title', 'Плеер'), function () use ($embed, $watermarkLogin, $watermarkAlpha) {
            echo '<section class="player-page">';
            echo '<div class="player-stage"><iframe class="player-frame" src="' . Util::h($embed) . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen webkitallowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>';
            if ($watermarkLogin !== '') {
                echo '<div class="player-watermark" style="--player-watermark-alpha:' . Util::h($watermarkAlpha) . '" aria-hidden="true">';
                for ($i = 0; $i < 24; $i++) {
                    echo '<span>' . Util::h($watermarkLogin) . '</span>';
                }
                echo '</div>';
            }
            echo '</div>';
            echo '</section>';
        }, [], 'player-view', false);
    }

    private static function previewProxy(): void
    {
        $user = Auth::requireLogin();
        $cameraId = (int)($_GET['id'] ?? 0);
        if (!Repo::cameraAllowedForUser($user, $cameraId)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $stmt = DB::pdo()->prepare(
            'SELECT c.*, s.name AS server_name, s.base_url AS server_url
             FROM cameras c
             LEFT JOIN dvr_servers s ON s.id = c.server_id
             WHERE c.id = ? AND c.blocked = 0'
        );
        $stmt->execute([$cameraId]);
        $camera = $stmt->fetch();
        if (!$camera || empty($camera['server_url']) || empty($camera['dvr_stream_name'])) {
            http_response_code(404);
            echo 'Preview not found';
            return;
        }

        $ts = (int)($_GET['ts'] ?? 0);
        if ($ts > 0) {
            if (self::userArchiveHidden($user)) {
                http_response_code(404);
                echo 'Not found';
                return;
            }
            $ts = min($ts, time());
            $frame = DvrClient::timestampPreview($cameraId, $ts);
            if ($frame['status'] === 0 || $frame['status'] >= 400) {
                http_response_code($frame['status'] >= 400 ? $frame['status'] : 502);
                echo 'Preview unavailable';
                return;
            }
            header('Content-Type: ' . (string)$frame['contentType']);
            header('Content-Length: ' . strlen($frame['body']));
            header('Cache-Control: public, max-age=3600');
            echo (string)$frame['body'];
            return;
        }

        $token = (string)($user['daily_token'] ?? '');
        if ($token === '') {
            http_response_code(403);
            echo 'Token missing';
            return;
        }

        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Vary: Cookie');
        header('Location: ' . self::externalPreviewUrl($camera, $token, (string)($_GET['_'] ?? '')), true, 302);
    }

    private static function events(): void
    {
        $user = Auth::requireLogin();
        $archiveHidden = self::userArchiveHidden($user);

        $cameraId = max(0, (int)($_GET['cameraId'] ?? 0));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = self::eventsPageSize();
        $searchQuery = trim((string)($_GET['q'] ?? ''));

        $timezone = (string)Config::get('timezone', 'UTC');
        $date = trim((string)($_GET['date'] ?? ''));
        $now = time();
        $to = $now;
        $from = $to - 168 * 3600;
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            try {
                $dayStart = (new \DateTimeImmutable($date, new \DateTimeZone($timezone)))->setTime(0, 0, 0);
                $dayEnd = $dayStart->modify('+1 day -1 second');
                $from = $dayStart->getTimestamp();
                $to = $dayEnd->getTimestamp();
            } catch (\Throwable) {
                $date = '';
            }
        }

        $events = [];
        $cameras = [];
        if (!$archiveHidden) {
            $cameras = Repo::accessibleCameras($user, 'all', $searchQuery);
            foreach ($cameras as $camera) {
                if ($cameraId > 0 && (int)$camera['id'] !== $cameraId) {
                    continue;
                }
                if (!Repo::cameraAllowedForUser($user, (int)$camera['id'])) {
                    continue;
                }
                $serverId = (int)($camera['server_id'] ?? 0);
                $stream = trim((string)($camera['dvr_stream_name'] ?? ''));
                if ($serverId <= 0 || $stream === '') {
                    continue;
                }
                $result = DvrClient::motionEvents($serverId, $stream, $from, $to);
                if (empty($result['ok'])) {
                    continue;
                }
                foreach ($result['intervals'] ?? [] as $interval) {
                    if ((int)($interval['from'] ?? 0) <= 0) {
                        continue;
                    }
                    $events[] = [
                        'cameraId' => (int)$camera['id'],
                        'cameraName' => (string)($camera['name'] ?? $stream),
                        'stream' => $stream,
                        'from' => (int)$interval['from'],
                        'to' => (int)($interval['to'] ?? $interval['from']),
                        'duration' => (int)($interval['duration'] ?? 0),
                        'state' => (string)($interval['state'] ?? 'motion'),
                    ];
                }
            }
            usort($events, static fn(array $a, array $b): int => $b['from'] <=> $a['from']);
        }

        $total = count($events);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pages);
        $rows = array_slice($events, ($page - 1) * $pageSize, $pageSize);
        $pager = [
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'rows' => $rows,
            'cameraId' => $cameraId,
            'date' => $date,
            'q' => $searchQuery,
        ];

        self::layout(self::t('events.title', 'События'), function () use ($cameras, $cameraId, $date, $searchQuery, $user, $rows, $total, $pager, $timezone): void {
            echo '<section class="panel events-panel"><form class="events-filter" method="get" action="/viewer/events">';
            echo '<label>' . Util::h(self::t('events.camera', 'Камера')) . ' <select name="cameraId" onchange="this.form.submit()">';
            echo '<option value="0"' . ($cameraId === 0 ? ' selected' : '') . '>' . Util::h(self::t('events.allCameras', 'Все камеры')) . '</option>';
            foreach ($cameras as $camera) {
                $id = (int)$camera['id'];
                echo '<option value="' . $id . '"' . ($cameraId === $id ? ' selected' : '') . '>' . Util::h($camera['name']) . '</option>';
            }
            echo '</select></label>';
            if (($user['role'] ?? '') === 'admin') {
                echo '<label class="events-search">' . Util::h(self::t('events.search', 'Поиск камер')) . ' <input class="camera-search-input" name="q" value="' . Util::h($searchQuery) . '" placeholder="' . Util::h(self::t('filter.cameraSearchPlaceholder', 'Название, поток или IP')) . '" onchange="this.form.submit()"></label>';
            }
            echo '<label>' . Util::h(self::t('events.date', 'Дата')) . ' <input type="date" name="date" value="' . Util::h($date) . '" onchange="this.form.submit()"></label>';
            echo '</form></section>';

            if ($total === 0) {
                echo '<p class="empty">' . Util::h(self::t('events.dateEmpty', 'Нет событий движения за выбранную дату')) . '</p>';
                return;
            }

            echo '<section class="event-grid">';
            foreach ($rows as $event) {
                self::eventsCard($event, $timezone);
            }
            echo '</section>';
            self::pager('/viewer/events', $pager, [
                'cameraId' => $pager['cameraId'] === 0 ? '' : $pager['cameraId'],
                'date' => $pager['date'],
                'q' => $pager['q'],
            ]);
        });
    }

    private static function eventsCard(array $event, string $timezone): void
    {
        $player = self::eventsPlayerUrl((int)$event['cameraId'], (int)$event['from']);
        $preview = '/viewer/preview?id=' . (int)$event['cameraId'] . '&ts=' . (int)$event['from'];
        try {
            $time = (new \DateTimeImmutable('@' . (int)$event['from']))->setTimezone(new \DateTimeZone($timezone));
            $iso = $time->format(\DateTimeInterface::ATOM);
            $label = $time->format('d.m.Y H:i:s');
        } catch (\Throwable) {
            $iso = '';
            $label = '';
        }
        $duration = self::t('events.duration', '%d с.');

        echo '<article class="event-card">';
        echo '<a class="event-preview" href="' . Util::h($player) . '" title="' . Util::h(self::t('events.openArchive', 'Открыть в архиве')) . '">';
        echo '<img src="' . Util::h($preview) . '" alt="' . Util::h($event['cameraName']) . '" loading="lazy" decoding="async">';
        echo '</a>';
        echo '<div class="event-meta"><strong>' . Util::h($event['cameraName']) . '</strong>';
        echo '<time class="local-time" datetime="' . Util::h($iso) . '">' . Util::h($label) . '</time>';
        echo '<span class="event-duration">' . Util::h(self::t('events.motion', 'Движение')) . ' · ' . Util::h(sprintf($duration, (int)$event['duration'])) . '</span>';
        echo '</div></article>';
    }

    private static function eventsPlayerUrl(int $cameraId, int $ts): string
    {
        $back = self::safeBackPath((string)($_SERVER['REQUEST_URI'] ?? '/viewer/events'));
        $query = ['id' => $cameraId, 'back' => $back];
        if ($ts > 0) {
            $query['start'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
        }
        return '/viewer/player?' . http_build_query($query);
    }

    private static function eventsPageSize(): int
    {
        return min(48, max(12, (int)($_GET['pageSize'] ?? 24)));
    }

    private static function playerArchiveTimeQuery(array $user, string $start, string $end): array
    {
        if (self::userArchiveHidden($user)) {
            return [];
        }

        $query = [];
        $startIso = self::normalizeArchiveTime($start);
        if ($startIso !== null) {
            $query['start'] = $startIso;
        }
        $endIso = self::normalizeArchiveTime($end);
        if ($endIso !== null) {
            $query['end'] = $endIso;
        }
        return $query;
    }

    private static function normalizeArchiveTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
        $ts = $time->getTimestamp();
        if ($ts <= 0 || $ts > time() + 3600) {
            return null;
        }
        return $time->format('Y-m-d\TH:i:sP');
    }

    private static function toggleFavorite(): void
    {
        $user = Auth::requireLogin();
        $cameraId = (int)Util::post('camera_id');
        if (!Repo::cameraAllowedForUser($user, $cameraId)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND camera_id = ?');
        $stmt->execute([$user['id'], $cameraId]);
        if ($stmt->fetch()) {
            $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND camera_id = ?')->execute([$user['id'], $cameraId]);
        } else {
            $pdo->prepare('INSERT INTO favorites(user_id, camera_id, created_at) VALUES(?, ?, ?)')
                ->execute([$user['id'], $cameraId, Util::now()]);
        }
        Util::redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }

    private static function renameCamera(): void
    {
        $user = Auth::requireLogin();
        $cameraId = (int)($_GET['id'] ?? Util::post('id'));
        $isAdmin = ($user['role'] ?? '') === 'admin';
        if (!$isAdmin && empty($user['can_rename_cameras'])) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        if (!Repo::cameraAllowedForUser($user, $cameraId)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $stmt = DB::pdo()->prepare(
            'SELECT c.*, s.name AS server_name
             FROM cameras c
             LEFT JOIN dvr_servers s ON s.id = c.server_id
             WHERE c.id = ?'
        );
        $stmt->execute([$cameraId]);
        $camera = $stmt->fetch();
        if (!$camera) {
            http_response_code(404);
            echo 'Camera not found';
            return;
        }

        $back = self::safeBackPath((string)Util::post('back', (string)($_GET['back'] ?? ($_SERVER['HTTP_REFERER'] ?? '/'))));
        if ($back === '') {
            $back = '/';
        }

        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $name = trim((string)Util::post('name'));
            $name = function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255);
            if ($name === '') {
                $error = self::t('cameras.nameRequired', 'Укажите название камеры');
            } else {
                $existing = self::cameraByName($name);
                if ($existing && (int)$existing['id'] !== $cameraId) {
                    $error = self::t('cameras.nameExists', 'Камера с таким названием уже существует');
                } else {
                    DB::pdo()->prepare('UPDATE cameras SET name = ?, updated_at = ? WHERE id = ?')
                        ->execute([$name, Util::now(), $cameraId]);
                    Audit::logForUser((int)$user['id'], 'camera.rename', $camera['name'] . ' -> ' . $name);
                    Util::redirect($back);
                }
            }
        } else {
            $name = (string)$camera['name'];
        }

        self::layout(self::t('cameras.edit', 'Изменить камеру'), function () use ($camera, $name, $error, $back) {
            echo '<section class="panel">';
            echo '<div class="section-head"><h2>' . Util::h(self::t('cameras.edit', 'Изменить камеру')) . '</h2><a href="' . Util::h($back) . '">' . Util::h(self::t('action.cancel', 'Отмена')) . '</a></div>';
            echo '<form method="post" action="/camera/rename" class="form">' . Csrf::field();
            echo '<input type="hidden" name="id" value="' . (int)$camera['id'] . '">';
            echo '<input type="hidden" name="back" value="' . Util::h($back) . '">';
            if ($error !== '') {
                self::notice($error, 'danger');
            }
            echo '<label>' . Util::h(self::t('cameras.name', 'Название')) . '<input name="name" value="' . Util::h($name) . '" maxlength="255" required autofocus></label>';
            echo '<div class="form-actions"><button class="primary">' . Util::h(self::t('action.save', 'Сохранить')) . '</button><a href="' . Util::h($back) . '">' . Util::h(self::t('action.cancel', 'Отмена')) . '</a></div>';
            echo '</form></section>';
        });
    }

    private static function mosaics(): void
    {
        $user = Auth::requireLogin();
        if (($user['role'] ?? '') !== 'admin' && (int)($user['mosaic_enabled'] ?? 0) !== 1) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $mosaics = Repo::mosaicsForUser($user);
        self::layout(self::t('mosaic.title', 'Мозаика'), function () use ($mosaics, $user): void {
            echo '<section class="panel"><div class="panel-head"><h2>' . Util::h(self::t('mosaic.title', 'Мозаика')) . '</h2><a class="btn primary" href="/mosaic/new">' . Util::h(self::t('mosaic.create', 'Создать мозаику')) . '</a></div>';
            if (!$mosaics) {
                echo '<p class="empty">' . Util::h(self::t('mosaic.empty', 'Мозаики не созданы')) . '</p>';
            } else {
                echo '<ul class="mosaic-list">';
                foreach ($mosaics as $mosaic) {
                    $cameraCount = count(Repo::mosaicCameraIds($mosaic));
                    echo '<li class="mosaic-item">';
                    echo '<a class="mosaic-item-link" href="/mosaic/view?id=' . (int)$mosaic['id'] . '">';
                    echo '<strong>' . Util::h($mosaic['name']) . '</strong>';
                    echo '<span>' . $cameraCount . ' ' . Util::h(self::t('mosaic.cameras', 'камер')) . '</span>';
                    if (($user['role'] ?? '') === 'admin' && !empty($mosaic['owner_login'])) {
                        echo '<span class="mosaic-owner">' . Util::h(self::t('mosaic.owner', 'Автор')) . ': ' . Util::h($mosaic['owner_login']) . '</span>';
                    }
                    echo '</a>';
                    echo '<div class="mosaic-item-actions">';
                    echo '<a class="btn" href="/mosaic/edit?id=' . (int)$mosaic['id'] . '">' . Util::h(self::t('mosaic.edit', 'Изменить')) . '</a>';
                    echo '<form method="post" action="/mosaic/delete" data-confirm="' . Util::h(self::t('mosaic.confirmDelete', 'Удалить мозаику?')) . '">' . Csrf::field() . '<input type="hidden" name="id" value="' . (int)$mosaic['id'] . '"><button class="btn danger">' . Util::h(self::t('mosaic.delete', 'Удалить')) . '</button></form>';
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</section>';
        });
    }

    private static function mosaicEdit(): void
    {
        $user = Auth::requireLogin();
        if (($user['role'] ?? '') !== 'admin' && (int)($user['mosaic_enabled'] ?? 0) !== 1) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $mosaicId = (int)($_GET['id'] ?? 0);
        $mosaic = null;
        if ($mosaicId > 0) {
            if (!Repo::mosaicAllowedForUser($user, $mosaicId)) {
                http_response_code(403);
                echo 'Forbidden';
                return;
            }
            $mosaic = Repo::mosaicById($mosaicId);
            if (!$mosaic) {
                http_response_code(404);
                echo 'Not found';
                return;
            }
        }

        $name = $mosaic['name'] ?? '';
        $gridRows = $mosaic ? (int)($mosaic['grid_rows'] ?? 3) : 3;
        $gridCols = $mosaic ? (int)($mosaic['grid_cols'] ?? 3) : 3;
        $selectedIds = $mosaic ? Repo::mosaicCameraIds($mosaic) : [];

        $cameras = self::mosaicAccessibleCameras($user);

        self::layout(self::t('mosaic.title', 'Мозаика'), function () use ($mosaicId, $name, $gridRows, $gridCols, $selectedIds, $cameras): void {
            echo '<section class="panel"><form method="post" action="/mosaic/save" class="mosaic-form">' . Csrf::field();
            if ($mosaicId > 0) {
                echo '<input type="hidden" name="id" value="' . $mosaicId . '">';
            }
            echo '<div class="form-field"><span class="form-field-label">' . Util::h(self::t('mosaic.name', 'Название')) . '</span><input name="name" value="' . Util::h($name) . '" maxlength="255" required autofocus></div>';
            echo '<div class="form-field"><span class="form-field-label">' . Util::h(self::t('mosaic.rows', 'Строки')) . '</span><select name="grid_rows">';
            for ($r = 2; $r <= 6; $r++) {
                echo '<option value="' . $r . '"' . ($gridRows === $r ? ' selected' : '') . '>' . $r . '</option>';
            }
            echo '</select></div>';
            echo '<div class="form-field"><span class="form-field-label">' . Util::h(self::t('mosaic.columns', 'Колонки')) . '</span><select name="grid_cols">';
            for ($c = 2; $c <= 6; $c++) {
                echo '<option value="' . $c . '"' . ($gridCols === $c ? ' selected' : '') . '>' . $c . '</option>';
            }
            echo '</select></div>';
            echo '<div class="form-field"><span class="form-field-label">' . Util::h(self::t('mosaic.cameras', 'Камеры')) . '</span>';
            if (!$cameras) {
                echo '<p class="empty">' . Util::h(self::t('mosaic.noCameras', 'Нет доступных камер')) . '</p>';
            } else {
                echo '<div class="mosaic-camera-list">';
                foreach ($cameras as $camera) {
                    $id = (int)$camera['id'];
                    echo '<label class="mosaic-camera-check"><input type="checkbox" name="cameras[]" value="' . $id . '"' . (in_array($id, $selectedIds, true) ? ' checked' : '') . '> <span>' . Util::h($camera['name']) . '</span></label>';
                }
                echo '</div>';
            }
            echo '</div>';
            echo '<div class="form-actions"><button class="primary">' . Util::h(self::t('mosaic.save', 'Сохранить')) . '</button><a href="/mosaic">' . Util::h(self::t('action.cancel', 'Отмена')) . '</a></div>';
            echo '</form></section>';
        });
    }

    private static function mosaicAccessibleCameras(array $user): array
    {
        $cameras = Repo::accessibleCameras($user);
        $allowed = [];
        foreach ($cameras as $camera) {
            if (Repo::cameraAllowedForUser($user, (int)$camera['id'])) {
                $allowed[] = $camera;
            }
        }
        return $allowed;
    }

    private static function mosaicSave(): void
    {
        $user = Auth::requireLogin();
        if (($user['role'] ?? '') !== 'admin' && (int)($user['mosaic_enabled'] ?? 0) !== 1) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $mosaicId = (int)Util::post('id');
        if ($mosaicId > 0 && !Repo::mosaicAllowedForUser($user, $mosaicId)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $name = trim((string)Util::post('name'));
        $gridRows = self::normalizeGridDimension((int)Util::post('grid_rows', 3));
        $gridCols = self::normalizeGridDimension((int)Util::post('grid_cols', 3));
        $cameraIds = array_values(array_unique(array_filter(array_map('intval', (array)Util::post('cameras', [])), static fn(int $id): bool => $id > 0)));

        if ($name === '') {
            http_response_code(422);
            echo 'Name required';
            return;
        }

        $validCameraIds = [];
        foreach ($cameraIds as $cameraId) {
            if (Repo::cameraAllowedForUser($user, $cameraId)) {
                $validCameraIds[] = $cameraId;
            }
        }

        $pdo = DB::pdo();
        $now = Util::now();
        $camerasJson = json_encode($validCameraIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($mosaicId > 0) {
            $pdo->prepare('UPDATE cameras_mosaic SET name = ?, cameras_json = ?, grid_rows = ?, grid_cols = ?, updated_at = ? WHERE id = ?')
                ->execute([$name, $camerasJson, $gridRows, $gridCols, $now, $mosaicId]);
        } else {
            $pdo->prepare('INSERT INTO cameras_mosaic(user_id, name, cameras_json, grid_rows, grid_cols, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$user['id'], $name, $camerasJson, $gridRows, $gridCols, $now, $now]);
            $mosaicId = (int)$pdo->lastInsertId();
        }
        Util::redirect('/mosaic/view?id=' . $mosaicId);
    }

    private static function mosaicView(): void
    {
        $user = Auth::requireLogin();
        if (($user['role'] ?? '') !== 'admin' && (int)($user['mosaic_enabled'] ?? 0) !== 1) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $mosaicId = (int)($_GET['id'] ?? 0);
        if (!Repo::mosaicAllowedForUser($user, $mosaicId)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $mosaic = Repo::mosaicById($mosaicId);
        if (!$mosaic) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $cameraIds = Repo::mosaicCameraIds($mosaic);
        $cameras = Repo::camerasByIds($cameraIds);
        $gridRows = self::normalizeGridDimension((int)($mosaic['grid_rows'] ?? 3));
        $gridCols = self::normalizeGridDimension((int)($mosaic['grid_cols'] ?? 3));
        $token = (string)($user['daily_token'] ?? '');

        self::layout((string)$mosaic['name'], function () use ($mosaic, $cameras, $gridRows, $gridCols, $token): void {
            echo '<section class="mosaic-grid mosaic-grid-' . Util::h($gridRows) . 'x' . Util::h($gridCols) . ' mosaic-view">';
            if (!$cameras) {
                echo '<p class="empty">' . Util::h(self::t('mosaic.noCameras', 'В мозаике нет доступных камер')) . '</p>';
            }
            foreach ($cameras as $camera) {
                $embed = self::embedUrl($camera, $token, '', '', '', '', false, ['screenshot' => 'false', 'hidecontrols' => 'true']);
                echo '<article class="mosaic-tile">';
                echo '<div class="mosaic-tile-frame"><iframe src="' . Util::h($embed) . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen webkitallowfullscreen referrerpolicy="no-referrer-when-downgrade" loading="lazy"></iframe></div>';
                echo '</article>';
            }
            echo '</section>';
        });
    }

    private static function mosaicDelete(): void
    {
        $user = Auth::requireLogin();
        if (($user['role'] ?? '') !== 'admin' && (int)($user['mosaic_enabled'] ?? 0) !== 1) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $mosaicId = (int)Util::post('id');
        if (!Repo::mosaicAllowedForUser($user, $mosaicId)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        DB::pdo()->prepare('DELETE FROM cameras_mosaic WHERE id = ?')->execute([$mosaicId]);
        Util::redirect('/mosaic');
    }

    private static function updateTheme(): void
    {
        $user = Auth::requireLogin();
        $theme = (string)Util::post('theme');
        $theme = in_array($theme, ['light', 'dark'], true) ? $theme : '';
        DB::pdo()->prepare('UPDATE users SET theme = ? WHERE id = ?')->execute([$theme, (int)$user['id']]);
        Util::redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
}
