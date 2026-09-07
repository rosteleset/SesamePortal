<?php

declare(strict_types=1);

namespace SesamePortal;

use InvalidArgumentException;

trait VideoWallPages
{
    private static function wt(string $key): string
    {
        return self::t('wall.' . $key, VideoWallTranslations::messages()['en']['wall.' . $key] ?? $key);
    }

    private static function videoWallsPage(): void
    {
        $user = Auth::requireLogin();
        $path = Util::path();
        $method = self::apiMethod();
        header('Cache-Control: no-store');
        if (!in_array($method, $path === '/video-walls' ? ['GET', 'POST'] : ['GET'], true)) {
            http_response_code(405);
            header('Allow: ' . ($path === '/video-walls' ? 'GET, POST' : 'GET'));
            return;
        }
        $id = max(0, (int)($method === 'POST' ? Util::post('id', 0) : ($_GET['id'] ?? 0)));
        $wall = $id ? VideoWalls::find($user, $id) : null;
        if (($id && !$wall) || (!$id && in_array($path, ['/video-walls/view', '/video-walls/stream'], true))) {
            http_response_code(404);
            self::layout(self::wt('title'), static fn() => self::notice(self::wt('notFound')), []);
            return;
        }
        if ($method === 'POST') {
            if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)Util::post('csrf', ''))) {
                http_response_code(419);
                return;
            }
            if (Util::post('action') === 'delete' && $wall) {
                if (Util::post('confirm_delete') !== '1') {
                    http_response_code(422);
                    self::videoWallsList($user, self::wt('deleteConfirm'));
                    return;
                }
                VideoWalls::delete($user, $wall);
                Util::redirect('/video-walls');
            }
            $input = ['name' => Util::post('name'), 'rows' => Util::post('rows'), 'columns' => Util::post('columns'), 'cameraIds' => (array)Util::post('cameraIds', [])];
            if (isset($_POST['camera_ids'])) {
                $input['cameraIds'] = json_decode((string)$_POST['camera_ids'], true);
            }
            try {
                $saved = VideoWalls::save($user, $wall, $input);
                Util::redirect('/video-walls/view?id=' . (int)$saved['id'] . '&saved=1');
            } catch (InvalidArgumentException $error) {
                http_response_code(422);
                self::videoWallEditor($user, $wall, $input, self::t($error->getMessage(), self::wt('invalidSelection')));
            }
            return;
        }
        match ($path) {
            '/video-walls/edit' => self::videoWallEditor($user, $wall),
            '/video-walls/view' => self::videoWallView($user, $wall),
            '/video-walls/stream' => self::videoWallStream($user, $wall),
            default => self::videoWallsList($user),
        };
    }

    private static function videoWallsList(array $user, string $error = ''): void
    {
        $pager = VideoWalls::page($user, (int)($_GET['page'] ?? 1));
        $deleting = isset($_GET['delete']) ? VideoWalls::find($user, (int)$_GET['delete']) : null;
        self::layout(self::wt('title'), function () use ($user, $pager, $error, $deleting): void {
            self::notice($error, 'danger');
            if ($deleting) {
                echo '<section class="panel delete-confirm" role="dialog" aria-label="' . Util::h(self::wt('deleteConfirm')) . '"><h2>' . Util::h($deleting['name']) . '</h2><form method="post" action="/video-walls">' . Csrf::field();
                echo '<input type="hidden" name="id" value="' . (int)$deleting['id'] . '"><input type="hidden" name="action" value="delete">';
                echo '<label class="check"><input type="checkbox" name="confirm_delete" value="1" required> ' . Util::h(self::wt('deleteConfirm')) . '</label><div class="vw-actions"><button class="danger">' . self::t('action.delete', 'Удалить') . '</button><a class="btn" href="/video-walls">' . self::t('action.cancel', 'Отмена') . '</a></div></form></section>';
            }
            echo '<section class="vw-library"><div class="vw-toolbar"><a class="btn primary" href="/video-walls/edit">' . Util::h(self::wt('new')) . '</a></div>';
            if (!$pager['rows']) {
                echo '<p class="empty">' . Util::h(self::wt('empty')) . '</p>';
            }
            echo '<div class="vw-library-list">';
            foreach ($pager['rows'] as $wall) {
                $id = (int)$wall['id'];
                echo '<article class="vw-library-row"><a class="vw-library-open" href="/video-walls/view?id=' . $id . '"><span class="vw-mini-grid" aria-hidden="true" style="--wall-cols:' . (int)$wall['grid_cols'] . '">';
                for ($i = 0; $i < (int)$wall['grid_rows'] * (int)$wall['grid_cols']; $i++) {
                    echo '<i' . ($i < count(VideoWalls::ids($wall)) ? ' class="occupied"' : '') . '></i>';
                }
                echo '</span><span><strong>' . Util::h($wall['name']) . '</strong><small>' . (int)$wall['grid_rows'] . ' × ' . (int)$wall['grid_cols'] . ' · ' . Util::h(self::wt('cameras')) . ': ' . count(VideoWalls::ids($wall));
                if ($user['role'] === 'admin') {
                    echo ' · ' . Util::h(self::wt('owner')) . ': ' . Util::h($wall['owner_login']);
                }
                echo '</small></span></a><div class="vw-actions">';
                self::iconActionLink('/video-walls/edit?id=' . $id, self::wt('edit'), 'edit');
                self::iconActionLink('/video-walls?delete=' . $id, self::t('action.delete', 'Удалить'), 'trash', 'danger');
                echo '</div></article>';
            }
            echo '</div>';
            $pages = max(1, (int)ceil($pager['total'] / $pager['pageSize']));
            if ($pages > 1) {
                echo '<nav class="vw-actions" aria-label="' . Util::h(self::t('pager.page', 'Страница')) . '">';
                for ($p = 1; $p <= $pages; $p++) {
                    echo '<a class="btn" href="/video-walls?page=' . $p . '"' . ($p === $pager['page'] ? ' aria-current="page"' : '') . '>' . $p . '</a>';
                }
                echo '</nav>';
            }
            echo '</section>';
        });
    }

    private static function videoWallEditor(array $user, ?array $wall, ?array $input = null, string $error = ''): void
    {
        $owner = $wall ? VideoWalls::owner($wall) : $user;
        $candidates = empty($owner['blocked']) ? Repo::accessibleCameras($owner) : [];
        $cameras = [];
        $blockedServers = [];
        foreach (Repo::all('dvr_servers') as $server) {
            if (!empty($server['blocked'])) {
                $blockedServers[(int)$server['id']] = true;
            }
        }
        foreach ($candidates as $camera) {
            if (!empty($camera['server_url']) && !isset($blockedServers[(int)$camera['server_id']])) {
                $cameras[] = $camera;
            }
        }
        $groups = Repo::groupsForUser($owner);
        $groupSet = array_fill_keys(array_map('intval', array_column($groups, 'id')), true);
        $cameraGroups = [];
        foreach (DB::pdo()->query('SELECT camera_id, group_id FROM camera_groups')->fetchAll() as $link) {
            if (isset($groupSet[(int)$link['group_id']])) {
                $cameraGroups[(int)$link['camera_id']][] = (int)$link['group_id'];
            }
        }
        $selected = $input['cameraIds'] ?? ($wall ? VideoWalls::ids($wall) : []);
        $selected = is_array($selected) ? array_slice(array_values(array_unique(array_map('intval', $selected))), 0, 36) : [];
        $rows = max(1, min(6, (int)($input['rows'] ?? $wall['grid_rows'] ?? 3)));
        $cols = max(1, min(6, (int)($input['columns'] ?? $wall['grid_cols'] ?? 3)));
        $name = $input['name'] ?? $wall['name'] ?? '';
        $catalog = [];
        $byGroup = [];
        foreach ($cameras as $camera) {
            $id = (int)$camera['id'];
            $catalog[$id] = ['id' => $id, 'name' => $camera['name'], 'stream' => $camera['dvr_stream_name'], 'server' => $camera['server_name'] ?? ''];
            foreach ($cameraGroups[$id] ?? [0] as $groupId) {
                $byGroup[$groupId][] = $catalog[$id];
            }
        }
        self::layout($wall ? self::wt('edit') : self::wt('new'), function () use ($wall, $rows, $cols, $name, $selected, $catalog, $groups, $byGroup, $error): void {
            self::notice($error, 'danger');
            echo '<form method="post" action="/video-walls" class="vw-editor" data-wall-editor>' . Csrf::field();
            echo '<input type="hidden" name="id" value="' . (int)($wall['id'] ?? 0) . '"><input type="hidden" name="camera_ids" value="' . Util::h(json_encode($selected)) . '" data-wall-ids disabled>';
            echo '<div class="vw-toolbar"><label class="vw-name">' . Util::h(self::wt('name')) . '<input name="name" maxlength="255" required value="' . Util::h(is_string($name) ? $name : '') . '"></label>';
            foreach (['rows' => $rows, 'columns' => $cols] as $field => $value) {
                echo '<label>' . Util::h(self::wt($field)) . '<select name="' . $field . '">';
                for ($i = 1; $i <= 6; $i++) {
                    echo '<option value="' . $i . '"' . ($i === $value ? ' selected' : '') . '>' . $i . '</option>';
                }
                echo '</select></label>';
            }
            echo '<button class="primary" data-wall-save>' . self::t('action.save', 'Сохранить') . '</button><a class="btn" href="/video-walls">' . self::t('action.cancel', 'Отмена') . '</a></div>';
            echo '<div class="vw-editor-body"><section class="vw-picker"><h2>' . Util::h(self::wt('cameras')) . '</h2><input type="search" data-wall-search aria-label="' . Util::h(self::t('table.search', 'Поиск')) . '" placeholder="' . Util::h(self::t('table.search', 'Поиск')) . '"><div class="vw-camera-tree">';
            [$byId, $children] = self::groupTreeStructure($groups);
            $renderCameras = static function (array $items) use ($selected): void {
                foreach ($items as $camera) {
                    echo '<label class="vw-camera-option" data-wall-camera-row><input type="checkbox" name="cameraIds[]" value="' . $camera['id'] . '"' . (in_array($camera['id'], $selected, true) ? ' checked' : '') . '><span><strong>' . Util::h($camera['name']) . '</strong><small>' . Util::h($camera['stream'] . ' · ' . $camera['server']) . '</small></span></label>';
                }
            };
            $renderCameras($byGroup[0] ?? []);
            $visited = [];
            $renderGroups = static function (int $parent) use (&$renderGroups, &$visited, $children, $byId, $byGroup, $renderCameras): void {
                foreach ($children[$parent] ?? [] as $id) {
                    if (isset($visited[$id])) {
                        continue;
                    }
                    $visited[$id] = true;
                    echo '<details data-wall-group><summary>' . Util::h($byId[$id]['name']) . '</summary><div class="vw-branch">';
                    $renderCameras($byGroup[$id] ?? []);
                    $renderGroups($id);
                    echo '</div></details>';
                }
            };
            $renderGroups(0);
            echo '<p data-wall-no-results' . ($catalog ? ' hidden' : '') . '>' . self::t('assignment.empty', 'Ничего не найдено') . '</p></div></section>';
            echo '<section class="vw-order"><div class="vw-section-head"><h2>' . Util::h(self::wt('selection')) . '</h2><output data-wall-count></output></div><p class="alert danger" data-wall-limit hidden>' . Util::h(self::wt('invalidSelection')) . '</p><ol data-wall-order></ol></section></div>';
            echo '<script type="application/json" data-wall-catalog>' . json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script>';
            foreach (['earlier', 'later', 'remove', 'unavailable'] as $label) {
                echo '<input type="hidden" data-wall-label="' . $label . '" value="' . Util::h(self::wt($label)) . '">';
            }
            echo '</form>';
        });
    }

    private static function videoWallView(array $user, array $wall): void
    {
        $ids = VideoWalls::ids($wall);
        $cameras = VideoWalls::cameras($user, $ids);
        $archiveAllowed = !self::userArchiveHidden($user);
        self::layout($wall['name'], function () use ($wall, $ids, $cameras, $user, $archiveAllowed): void {
            if (isset($_GET['saved'])) {
                self::notice(self::wt('saved'), 'success');
            }
            echo '<section class="vw-screen" data-wall-view data-wall-archive="' . ($archiveAllowed ? '1' : '0') . '"><div class="vw-toolbar"><a class="btn" href="/video-walls">' . self::t('action.back', 'Назад') . '</a><button type="button" class="icon-action" data-wall-play title="' . Util::h(self::wt('pause')) . '" aria-label="' . Util::h(self::wt('pause')) . '"><span data-wall-play-icon>' . self::icon('pause') . '</span><span data-wall-resume-icon hidden>' . self::icon('play') . '</span></button><button type="button" data-wall-live aria-pressed="true">LIVE</button><output data-wall-clock aria-live="off"></output>';
            self::iconActionLink('/video-walls/edit?id=' . (int)$wall['id'], self::wt('edit'), 'edit');
            echo '<button type="button" class="icon-action" data-wall-fullscreen title="' . Util::h(self::wt('fullscreen')) . '" aria-label="' . Util::h(self::wt('fullscreen')) . '">' . self::icon('scan') . '</button></div>';
            echo '<div class="vw-video-grid" style="--wall-cols:' . (int)$wall['grid_cols'] . ';--wall-rows:' . (int)$wall['grid_rows'] . '">';
            $capacity = (int)$wall['grid_rows'] * (int)$wall['grid_cols'];
            for ($slot = 0; $slot < $capacity; $slot++) {
                $cameraId = $ids[$slot] ?? 0;
                $camera = $cameras[$cameraId] ?? null;
                $name = $camera['name'] ?? self::wt($cameraId ? 'unavailable' : 'emptySlot');
                echo '<article class="vw-video-tile"><div class="vw-video-stage">';
                if ($camera) {
                    $src = '/video-walls/stream?' . http_build_query(['id' => (int)$wall['id'], 'camera_id' => $cameraId]);
                    echo '<iframe data-wall-frame data-wall-camera-id="' . $cameraId . '" data-wall-origin="' . Util::h(self::videoWallOrigin((string)$camera['server_url'])) . '" data-src="' . Util::h($src) . '" title="' . Util::h($name) . '" allow="autoplay" referrerpolicy="same-origin"></iframe><span class="vw-playback-state" data-wall-state role="status" hidden></span>';
                    if ((int)($camera['watermark_enabled'] ?? 0) === 1) {
                        echo '<div class="vw-watermark" aria-hidden="true" style="--watermark-alpha:' . number_format(self::watermarkIntensity($camera['watermark_intensity'] ?? 16) / 100, 2, '.', '') . '">';
                        for ($i = 0; $i < 6; $i++) {
                            echo '<span>' . Util::h($user['login']) . '</span>';
                        }
                        echo '</div>';
                    }
                } else {
                    echo '<span class="vw-slot-state">' . Util::h($name) . '</span>';
                }
                echo '</div><div class="vw-tile-caption"><span>' . ($slot + 1) . '. ' . Util::h($name) . '</span>';
                if ($camera) {
                    self::iconActionLink('/viewer/player?' . http_build_query(['id' => $cameraId, 'back' => '/video-walls/view?id=' . (int)$wall['id']]), self::wt('open'), 'scan');
                }
                echo '</div></article>';
            }
            echo '</div>';
            if ($archiveAllowed) {
                echo '<section class="vw-archive" data-wall-archive-controls aria-label="' . Util::h(self::wt('timeline')) . '"><form class="vw-archive-toolbar" data-wall-jump><label>' . Util::h(self::wt('dateTime')) . '<input type="datetime-local" step="1" data-wall-date required></label><button type="submit" class="icon-action" title="' . Util::h(self::wt('seek')) . '" aria-label="' . Util::h(self::wt('seek')) . '"><span aria-hidden="true">↦</span></button><label>' . Util::h(self::wt('speed')) . '<select data-wall-speed>';
                foreach ([0.5, 1, 2, 4, 8] as $rate) {
                    echo '<option value="' . $rate . '"' . ($rate === 1 ? ' selected' : '') . '>' . $rate . '×</option>';
                }
                echo '</select></label><div class="vw-actions">';
                foreach (['previousWindow' => '←', 'zoomIn' => '+', 'zoomOut' => '−', 'nextWindow' => '→'] as $action => $icon) {
                    echo '<button type="button" class="icon-action" data-wall-timeline-action="' . $action . '" title="' . Util::h(self::wt($action)) . '" aria-label="' . Util::h(self::wt($action)) . '"><span aria-hidden="true">' . $icon . '</span></button>';
                }
                echo '</div><output data-wall-window></output></form><p class="vw-archive-status" data-wall-archive-status role="status"></p><div class="vw-timeline-scroll"><canvas data-wall-timeline role="img" aria-label="' . Util::h(self::wt('timeline')) . '"></canvas></div><input type="range" data-wall-seek step="1" aria-label="' . Util::h(self::wt('seek')) . '"></section>';
            }
            $labels = [];
            foreach (['pause', 'play', 'timeline', 'noRecording', 'buffering', 'updateDvr', 'archiveDenied', 'rangesError', 'connecting', 'syncing', 'paused'] as $key) {
                $labels[$key] = self::wt($key);
            }
            echo '<script type="application/json" data-wall-playback-labels>' . json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script></section>';
        });
    }

    private static function videoWallStream(array $user, array $wall): void
    {
        $cameraId = (int)($_GET['camera_id'] ?? 0);
        $cameras = in_array($cameraId, VideoWalls::ids($wall), true) ? VideoWalls::cameras($user, [$cameraId]) : [];
        $camera = $cameras[$cameraId] ?? null;
        if (!$camera || empty($camera['server_url'])) {
            http_response_code(403);
            echo Util::h(self::wt('unavailable'));
            return;
        }
        $extra = ['hidecontrols' => 'true', 'screenshot' => 'false', 'preview' => 'false'];
        if (isset($_GET['controller_id'])) {
            $channel = $_GET['controller_id'];
            if (!is_string($channel) || !preg_match('/^[a-f0-9]{32}$/D', $channel)) {
                http_response_code(400);
                return;
            }
            $extra += ['controller_id' => $channel, 'controller_origin' => self::videoWallOrigin(self::absolutePortalUrl('/')), 'controller_version' => '1'];
        }
        header('Referrer-Policy: no-referrer');
        header('Location: ' . self::embedUrl($camera, (string)$user['daily_token'], '', '', '', '', !self::userArchiveHidden($user), $extra), true, 302);
    }

    private static function videoWallOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return '';
        }
        $port = $parts['port'] ?? null;
        $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;
        return $parts['scheme'] . '://' . strtolower($parts['host']) . ($port && $port !== $defaultPort ? ':' . $port : '');
    }

    private static function apiVideoWalls(array $parts): void
    {
        $user = self::apiRequireUser();
        $method = self::apiMethod();
        $id = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : 0;
        if (count($parts) > 2 || (isset($parts[1]) && !$id)) {
            self::apiError(404, 'not_found', 'Video wall not found');
        }
        $wall = $id ? VideoWalls::find($user, $id) : null;
        if ($id && !$wall) {
            self::apiError(404, 'not_found', 'Video wall not found');
        }
        if ($method === 'GET') {
            if ($wall) {
                self::apiJson(['data' => VideoWalls::payload($wall)]);
            }
            $pager = VideoWalls::page($user, (int)($_GET['page'] ?? 1), self::apiPageSize());
            self::apiJson(['data' => array_map([VideoWalls::class, 'payload'], $pager['rows']), 'pagination' => self::apiPagination($pager)]);
        }
        if ((!$id && $method !== 'POST') || ($id && !in_array($method, ['PUT', 'PATCH', 'DELETE'], true))) {
            self::apiError(405, 'method_not_allowed', 'Method not allowed');
        }
        $input = self::apiInput();
        // Session-authenticated writes also require CSRF; bearer tokens are not ambient browser credentials.
        $tokenAuth = (preg_match('/^Bearer\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $match) && trim($match[1]) !== '')
            || trim((string)($_SERVER['HTTP_X_PORTAL_TOKEN'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '')) !== '';
        if (!$tokenAuth && (!isset($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf'] ?? '')))) {
            self::apiError(419, 'csrf_mismatch', 'CSRF token mismatch');
        }
        if ($method === 'DELETE') {
            VideoWalls::delete($user, $wall);
            http_response_code(204);
            exit;
        }
        try {
            $saved = VideoWalls::save($user, $wall, $input);
            self::apiJson(['data' => VideoWalls::payload($saved)], $wall ? 200 : 201);
        } catch (InvalidArgumentException $error) {
            self::apiError(422, 'invalid_video_wall', VideoWallTranslations::messages()['en'][$error->getMessage()] ?? 'Invalid video wall', ['reason' => $error->getMessage()]);
        }
    }
}
