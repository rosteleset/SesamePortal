<?php

declare(strict_types=1);

namespace SesamePortal;

trait ViewerPages
{
    private static function viewer(string $mode): void
    {
        $user = Auth::requireLogin();
        $filter = (string)($_GET['filter'] ?? 'all');
        $searchQuery = self::viewerSearchQuery();
        $groups = self::groupRowsWithTreeLabels(Repo::groupsForUser($user));
        $cols = self::viewerColumns($user, $mode !== 'map');
        $previewRefresh = PortalSettings::mosaicPreviewRefresh($user);
        $cameraPager = null;
        if ($mode === 'map') {
            $cameras = Repo::accessibleMapCameras($user, $filter, $searchQuery);
        } else {
            $cameraPager = Repo::accessibleCamerasPage($user, $filter, $searchQuery, (int)($_GET['page'] ?? 1), self::viewerPageSize($cols));
            $cameras = $cameraPager['rows'];
        }
        $favorites = Repo::favoritesMap((int)$user['id']);

        $title = $mode === 'map' ? self::t('nav.map', 'Карта') : self::t('nav.mosaic', 'Список');
        self::layout($title, function () use ($mode, $groups, $filter, $searchQuery, $cameras, $favorites, $cameraPager, $cols, $previewRefresh) {
            self::filters($mode, $groups, $filter, $searchQuery, $cols);
            if ($mode === 'map') {
                self::map($cameras, $favorites);
            } else {
                self::mosaic($cameras, $favorites, $cameraPager ?? [], $cols, $previewRefresh);
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

    private static function mosaic(array $cameras, array $favorites, array $pager, int $cols, string $previewRefresh): void
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
            echo '<article class="camera-card">';
            echo '<a class="' . Util::h($previewClass) . '" href="' . Util::h($player) . '" aria-label="' . Util::h($openPlayerLabel) . '">';
            if ($preview) {
                echo '<canvas data-preview-src="' . Util::h($preview) . '" data-preview-refresh="' . Util::h($previewRefresh) . '"';
                if ($previewRefresh !== 'off') {
                    echo ' data-preview-refresh-ms="' . Util::h((string)((int)$previewRefresh * 1000)) . '"';
                }
                echo ' width="640" height="360" aria-hidden="true" hidden></canvas>';
            }
            echo '<span class="preview-spinner" aria-hidden="true"></span><span class="preview-state">' . Util::h($stateText) . '</span><span class="preview-play" aria-hidden="true"></span><span class="sr-only">' . Util::h($openPlayerLabel) . '</span></a><div class="camera-meta"><strong>' . Util::h($camera['name']) . '</strong><span>' . Util::h($camera['server_name'] ?? self::t('common.noServer', 'Без сервера')) . '</span></div>';
            self::favoriteButton((int)$camera['id'], isset($favorites[(int)$camera['id']]));
            echo '</article>';
        }
        echo '</section>';
        self::pager('/', $pager, [
            'filter' => ($pager['filter'] ?? 'all') === 'all' ? '' : ($pager['filter'] ?? ''),
            'cols' => $cols,
        ]);
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
            !self::userArchiveHidden($user)
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

    private static function filters(string $mode, array $groups, string $filter, string $searchQuery, int $cols = 3): void
    {
        $base = $mode === 'map' ? '/viewer/map' : '/';
        $url = static function (array $params = []) use ($base): string {
            $query = http_build_query(array_filter($params, fn($value) => $value !== '' && $value !== null));
            return $base . ($query ? '?' . $query : '');
        };
        $viewParams = static function (array $params = []) use ($mode, $cols): array {
            if ($mode !== 'map') {
                $params['cols'] = $cols;
            }
            return $params;
        };

        echo '<section class="filters viewer-filters">';
        $queryParam = $searchQuery === '' ? [] : ['q' => $searchQuery];
        $clearHref = $url($viewParams());
        echo '<a class="' . ($filter === 'all' ? 'active' : '') . '" href="' . Util::h($clearHref) . '">' . self::t('filter.all', 'Все') . '</a>';
        echo '<a class="' . ($filter === 'favorites' ? 'active' : '') . '" href="' . Util::h($url($viewParams(['filter' => 'favorites', ...$queryParam]))) . '">' . self::t('filter.favorites', 'Избранное') . '</a>';
        echo '<form method="get" action="' . Util::h($base) . '" class="group-filter">';
        if ($mode !== 'map') {
            echo '<input type="hidden" name="cols" value="' . Util::h($cols) . '">';
        }
        echo '<input type="hidden" name="filter" value="' . Util::h(str_starts_with($filter, 'group:') ? $filter : '') . '">';
        self::groupTreeFilter($groups, $filter, function (int $groupId) use ($url, $viewParams, $queryParam): string {
            return $url($viewParams(['filter' => 'group:' . $groupId, ...$queryParam]));
        });
        echo '<input class="camera-search-input" name="q" value="' . Util::h($searchQuery) . '" placeholder="' . Util::h(self::t('filter.cameraSearchPlaceholder', 'Название, поток или IP')) . '">';
        echo '<a class="camera-search-clear" href="' . Util::h($clearHref) . '" title="' . Util::h(self::t('filter.clearSearch', 'Сбросить поиск')) . '" aria-label="' . Util::h(self::t('filter.clearSearch', 'Сбросить поиск')) . '">&times;<span class="sr-only">' . Util::h(self::t('filter.clearSearch', 'Сбросить поиск')) . '</span></a>';
        echo '<button class="group-filter-submit">' . self::t('action.find', 'Найти') . '</button>';
        echo '</form>';
        if ($mode !== 'map') {
            self::densitySwitch($filter, $searchQuery, $cols);
        }
        echo '</section>';
    }

    private static function densitySwitch(string $filter, string $searchQuery, int $cols): void
    {
        echo '<nav class="density-switch" aria-label="' . Util::h(self::t('viewer.columnsPerRow', 'Камер в ряду')) . '">';
        echo '<span>' . Util::h(self::t('viewer.columnsPerRow', 'Камер в ряду')) . '</span>';
        for ($candidate = 2; $candidate <= 6; $candidate++) {
            $params = ['cols' => $candidate];
            if ($filter !== 'all') {
                $params['filter'] = $filter;
            }
            if ($searchQuery !== '') {
                $params['q'] = $searchQuery;
            }
            $href = '/?' . http_build_query($params);
            echo '<a class="' . ($cols === $candidate ? 'active' : '') . '" href="' . Util::h($href) . '" data-cols="' . $candidate . '">' . $candidate . '</a>';
        }
        echo '</nav>';
    }
}
