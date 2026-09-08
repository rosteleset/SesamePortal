<?php

declare(strict_types=1);

namespace SesamePortal;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;
trait AppRenderTrait
{
    private static function layout(string $title, callable $body, ?array $userOverride = [], string $bodyClass = '', bool $showChrome = true): void
    {
        $user = $userOverride === null ? null : Auth::user();
        $theme = $user && $showChrome ? (string)($user['theme'] ?? '') : '';
        if (!in_array($theme, ['light', 'dark'], true)) {
            $theme = '';
        }
        echo '<!doctype html><html lang="' . Util::h(I18n::htmlLocale()) . '" dir="' . Util::h(I18n::dir()) . '"' . ($theme !== '' ? ' data-theme="' . Util::h($theme) . '"' : '') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        if ($user && $showChrome && $theme === '') {
            echo '<script>if(!document.documentElement.dataset.theme&&window.matchMedia){document.documentElement.dataset.theme=matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";document.documentElement.dataset.themeAuto="1";}</script>';
        }
        echo '<title>' . Util::h($title) . ' - SesamePortal</title>';
        echo '<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">';
        echo '<link rel="manifest" href="/manifest.json">';
        echo '<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">';
        echo '<meta name="theme-color" content="#161616">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes">';
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
        echo '<meta name="apple-mobile-web-app-title" content="SesamePortal">';
        echo '<meta name="mobile-web-app-capable" content="yes">';
        echo '<link rel="stylesheet" href="' . Util::h(self::assetUrl('/assets/styles.css')) . '">';
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">';
        if (str_starts_with(Util::path(), '/video-walls')) {
            echo '<link rel="stylesheet" href="' . Util::h(self::assetUrl('/assets/video-walls.css')) . '">';
        }
        echo '</head><body' . ($bodyClass !== '' ? ' class="' . Util::h($bodyClass) . '"' : '') . '>';
        if ($user && $showChrome) {
            echo '<div class="shell"><aside class="sidebar">';
            echo '<a class="brand-logo-link" href="/"><img class="brand-logo-full" src="/assets/logo-sesameportal-inverse.svg" alt="SesamePortal"></a>';
            echo '<div class="nav-section">' . Util::h(self::t('nav.section.view', 'Просмотр')) . '</div><nav class="nav">';
            $viewerFilter = (string)($_GET['filter'] ?? 'all');
            self::navLink('/', self::t('nav.cameras', 'Камеры'), 'grid', Util::path() === '/' && $viewerFilter !== 'favorites');
            if (($user['role'] ?? '') === 'admin' || (int)($user['mosaic_enabled'] ?? 0) === 1) {
                self::navLink('/mosaic', self::t('nav.mosaic', 'Мозаика'), 'grid', Util::path() === '/mosaic');
                self::navLink('/video-walls', self::t('wall.title', 'Видеостена'), 'dashboard', str_starts_with(Util::path(), '/video-walls'));
            }
            self::navLink('/viewer/map', self::t('nav.map', 'Карта'), 'map');
            self::navLink('/viewer/events', self::t('nav.events', 'События'), 'events');
            self::navLink('/?filter=favorites', self::t('filter.favorites', 'Избранное'), 'star', ($_GET['filter'] ?? '') === 'favorites' && Util::path() === '/');
            echo '</nav>';
            if ($user['role'] === 'admin') {
                echo '<div class="nav-section">' . Util::h(self::t('nav.section.admin', 'Администрирование')) . '</div><nav class="nav">';
                self::navLink('/admin/dashboard', self::t('nav.dashboard', 'Dashboard'), 'dashboard');
                self::navLink('/admin/users', self::t('nav.users', 'Пользователи'), 'user');
                self::navLink('/admin/groups', self::t('nav.groups', 'Группы'), 'group');
                self::navLink('/admin/cameras', self::t('nav.camerasAdmin', 'Управление камерами'), 'camera', str_starts_with(Util::path(), '/admin/cameras'));
                self::navLink('/admin/servers', self::t('nav.dvr', 'DVR'), 'server');
                self::navLink('/admin/agents', self::t('nav.agents', 'Edge Agents'), 'agent');
                self::navLink('/admin/audit', self::t('nav.audit', 'Журнал'), 'audit');
                self::navLink('/admin/settings', self::t('nav.settings', 'Настройки'), 'settings');
                echo '</nav>';
            }
            echo '<div class="sidebar-foot">' . I18n::languageLinks() . '<a class="logout-link" href="/logout">' . self::icon('logout') . self::t('nav.logout', 'Выход') . '</a></div></aside>';
            $displayName = (string)($user['name'] !== '' ? $user['name'] : $user['login']);
            $initial = strtoupper(mb_substr($displayName, 0, 1) ?: 'U');
            $toggleIcon = $theme === 'dark' ? 'sun' : 'moon';
            $profileHref = $user['role'] === 'admin'
                ? '/admin/users?edit=1&id=' . (int)$user['id']
                : '/profile';
            echo '<main class="main workspace"><div class="topbar"><div class="topbar-left"><button type="button" class="nav-toggle" data-nav-toggle aria-label="' . Util::h(self::t('nav.toggle', 'Меню')) . '" aria-expanded="false">' . self::icon('menu') . '</button><h1>' . Util::h($title) . '</h1></div><div class="topbar-actions"><button type="button" class="theme-toggle" data-theme-toggle title="' . Util::h(self::t('nav.theme', 'Тема')) . '" aria-label="' . Util::h(self::t('nav.theme', 'Тема')) . '" data-title-light="' . Util::h(self::t('nav.theme.toLight', 'Включить светлую тему')) . '" data-title-dark="' . Util::h(self::t('nav.theme.toDark', 'Включить тёмную тему')) . '">' . self::icon($toggleIcon) . '</button><div class="user-dropdown"><button type="button" class="user" data-user-menu-toggle aria-haspopup="menu" aria-expanded="false" title="' . Util::h(self::t('nav.profile', 'Профиль')) . '" aria-label="' . Util::h(self::t('nav.profile', 'Профиль')) . '">' . Util::h($initial) . '</button><div class="user-menu" role="menu"><a role="menuitem" href="' . Util::h($profileHref) . '">' . self::icon('user') . self::t('nav.profile', 'Профиль') . '</a><a role="menuitem" href="/logout">' . self::icon('logout') . self::t('nav.logout', 'Выход') . '</a></div></div></div></div>';
            if ($user['role'] === 'admin') {
                self::portalUpdateBanner();
            }
            $body();
            echo '</main></div>';
            echo '<div class="nav-backdrop" data-nav-backdrop hidden></div>';
        } else {
            echo '<main class="' . ($user ? 'workspace' : 'login-page') . '">';
            $body();
            echo '</main>';
        }
        echo '<script>window.SESAME_I18N = ' . json_encode(I18n::js(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; window.SESAME_CSRF = ' . json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES) . '; window.SESAME_MAP_PROVIDER = ' . json_encode(Util::mapProvider(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; window.SESAME_MAP_VIEW = ' . json_encode(Util::mapDefaultView(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>';
        if (str_starts_with(Util::path(), '/video-walls')) {
            echo '<script src="' . Util::h(self::assetUrl('/assets/video-wall-playback.js')) . '"></script><script src="' . Util::h(self::assetUrl('/assets/video-walls.js')) . '"></script>';
        }
        echo '<script src="' . Util::h(self::assetUrl('/assets/app.js')) . '"></script>';
        echo '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("/sw.js")["catch"](function(){})})}</script>';
        echo '</body></html>';
    }

    private static function assetUrl(string $path): string
    {
        $file = dirname(__DIR__) . '/public' . $path;
        if (!is_file($file)) {
            return $path;
        }

        return $path . '?v=' . filemtime($file);
    }

    private static function navLink(string $href, string $label, string $icon, ?bool $activeOverride = null): void
    {
        $path = Util::path();
        $hrefPath = (string)(parse_url($href, PHP_URL_PATH) ?: '/');
        $active = $activeOverride ?? ($path === $hrefPath);
        echo '<a class="' . ($active ? 'active' : '') . '" href="' . Util::h($href) . '">' . self::icon($icon) . '<span>' . Util::h($label) . '</span></a>';
    }

    private static function icon(string $name): string
    {
        $paths = [
            'grid' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
            'map' => '<path d="m3 6 6-2 6 2 6-2v14l-6 2-6-2-6 2V6z"/><path d="M9 4v14M15 6v14"/>',
            'events' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2z"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>',
            'star' => '<path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3z"/>',
            'dashboard' => '<path d="M4 13h7V4H4v9zM13 20h7V4h-7v16zM4 20h7v-5H4v5z"/>',
            'user' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M4 20a8 8 0 0 1 16 0"/>',
            'group' => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM17 12a3 3 0 1 0 0-6"/><path d="M2 21a7 7 0 0 1 14 0M14 20a5 5 0 0 1 8 0"/>',
            'camera' => '<path d="M4 7h11a3 3 0 0 1 3 3v7H4V7z"/><path d="m18 11 4-3v8l-4-3"/>',
            'server' => '<path d="M4 6h16v5H4zM4 13h16v5H4z"/><path d="M8 8h.01M8 15h.01"/>',
            'agent' => '<path d="M12 3 4 7v10l8 4 8-4V7l-8-4z"/><path d="M8 9h8M8 13h8M10 17h4"/>',
            'audit' => '<path d="M6 3h12v18H6z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
            'settings' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m19.4 15 .6 2.2-2 3.4-2.2-.6a8 8 0 0 1-1.9 1.1L13.3 23h-4l-.6-1.9A8 8 0 0 1 6.8 20l-2.2.6-2-3.4.6-2.2A8 8 0 0 1 2 13.2L0 12l2-1.2A8 8 0 0 1 3.2 9l-.6-2.2 2-3.4 2.2.6A8 8 0 0 1 8.7 2.9L9.3 1h4l.6 1.9A8 8 0 0 1 15.8 4l2.2-.6 2 3.4-.6 2.2a8 8 0 0 1 1.1 1.8L22 12l-1.5 1.2a8 8 0 0 1-1.1 1.8z"/>',
            'logout' => '<path d="M10 4H5v16h5"/><path d="M14 8l4 4-4 4M18 12H9"/>',
            'edit' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="m14.5 7.5 2 2"/>',
            'check' => '<path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" d="M20 6 9 17l-5-5"/>',
            'sync' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h11a5 5 0 0 1 5 5M4 7l4-4M4 7l4 4M20 17H9a5 5 0 0 1-5-5m16 5-4-4m4 4-4 4"/>',
            'trash' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M8 7l1-3h6l1 3M7 7l1 14h8l1-14"/>',
            'key' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M15 7a4 4 0 1 0 2.8 1.2L21 5l-2-2-3.2 3.2A4 4 0 0 0 15 7zM9 13l-6 6m3-3 2 2"/>',
            'token-issue' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3.5 8.5h11V11a2 2 0 0 0 0 4v2.5h-11V15a2 2 0 0 0 0-4V8.5zM8 10.5v5M19 5v6M16 8h6"/>',
            'token-refresh' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 8.5h10.5V11a2 2 0 0 0 0 4v2.5H4V15a2 2 0 0 0 0-4V8.5zM8.5 10.5v5M20 6v4h-4M4 18v-4h4M18.8 10a6.5 6.5 0 0 0-10.2-3.4M5.2 14a6.5 6.5 0 0 0 10.2 3.4"/>',
            'token-revoke' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 5.5 6.2V12c0 4.8 2.6 8.3 6.5 9.8 3.9-1.5 6.5-5 6.5-9.8V6.2L12 3.5zM9 10l6 6M15 10l-6 6"/>',
            'ban' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M5 5a10 10 0 0 1 14 14M19 5A10 10 0 0 0 5 19M5 5l14 14"/>',
            'scan' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M8 12h8M12 8v8"/>',
            'diagnostics' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l2-6 4 12 2-6h6"/>',
            'download' => '<path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
            'sun' => '<circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'moon' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z"/>',
            'menu' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/>',
            'play' => '<path d="M8 5v14l11-7z"/>',
            'pause' => '<path d="M6 5h4v14H6zM14 5h4v14h-4z"/>',
            'search' => '<circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="m20 20-3.5-3.5"/>',
        ];
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
    }

    private static function filters(string $mode, array $groups, string $filter, string $searchQuery, int $cols = 3, string $previewRefresh = '30'): void
    {
        $base = $mode === 'map' ? '/viewer/map' : '/';
        $url = static function (array $params = []) use ($base): string {
            $query = http_build_query(array_filter($params, fn($value) => $value !== '' && $value !== null));
            return $base . ($query ? '?' . $query : '');
        };
        $viewParams = static function (array $params = []) use ($mode, $cols, $previewRefresh): array {
            if ($mode !== 'map') {
                $params['cols'] = $cols;
                if ($previewRefresh !== '30') {
                    $params['refresh'] = $previewRefresh;
                }
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
        echo '<input class="camera-search-input" name="q" value="' . Util::h($searchQuery) . '" placeholder="' . Util::h(self::t('filter.cameraSearchPlaceholder', 'Название, поток или IP')) . '">';
        echo '<a class="camera-search-clear" href="' . Util::h($clearHref) . '" title="' . Util::h(self::t('filter.clearSearch', 'Сбросить поиск')) . '" aria-label="' . Util::h(self::t('filter.clearSearch', 'Сбросить поиск')) . '">&times;<span class="sr-only">' . Util::h(self::t('filter.clearSearch', 'Сбросить поиск')) . '</span></a>';
        echo '<button class="group-filter-submit">' . self::t('action.find', 'Найти') . '</button>';
        if ($mode !== 'map') {
            self::previewRefreshSelect($previewRefresh);
        }
        echo '</form>';
        if ($mode !== 'map') {
            self::densitySwitch($filter, $searchQuery, $cols, $previewRefresh);
        }
        echo '</section>';
    }

    private static function groupTreeFilter(array $groups, string $filter, callable $hrefFor): void
    {
        $selectedId = str_starts_with($filter, 'group:') ? (int)substr($filter, 6) : 0;
        [$byId, $children] = self::groupTreeStructure($groups);

        $pathLabels = self::groupPathLabels($groups);
        $placeholder = self::t('filter.groupSelectPlaceholder', 'Выбрать группу');
        $selectedLabel = $selectedId > 0 && isset($byId[$selectedId])
            ? ($pathLabels[$selectedId] ?? (string)$byId[$selectedId]['name'])
            : $placeholder;
        $expanded = self::groupTreeExpandedAncestors($byId, $selectedId > 0 ? [$selectedId] : []);

        echo '<div class="group-tree-picker" data-group-tree-picker>';
        echo '<button type="button" class="group-tree-trigger" aria-haspopup="true" aria-expanded="false"><span>' . Util::h($selectedLabel) . '</span><span class="group-tree-caret" aria-hidden="true"></span></button>';
        echo '<div class="group-tree-menu" data-group-tree-menu hidden>';
        if (!$groups) {
            echo '<div class="group-tree-empty">' . Util::h(self::t('filter.noGroups', 'Группы не найдены')) . '</div>';
        } else {
            echo '<div class="group-tree-list" role="tree" aria-label="' . Util::h(self::t('filter.groupSelect', 'Группа')) . '">';
            self::renderGroupTreeNodes($byId, $children, $expanded, function (array $group, int $depth, bool $hasChildren, bool $isExpanded, callable $renderToggle) use ($selectedId, $hrefFor): void {
                $id = (int)$group['id'];
                $isActive = $selectedId === $id;
                echo '<div class="group-tree-row" style="--depth: ' . (int)$depth . '">';
                $renderToggle();
                echo '<a class="group-tree-option' . ($isActive ? ' active' : '') . '" href="' . Util::h($hrefFor($id)) . '" role="treeitem" aria-level="' . (int)($depth + 1) . '"' . ($isActive ? ' aria-current="true"' : '') . '>' . Util::h((string)$group['name']) . '</a>';
                echo '</div>';
            });
            echo '</div>';
        }
        echo '</div></div>';
    }

    /**
     * Пикер родительской группы: выпадающее дерево с опциями из groupParentOptions().
     * Родитель определяет положение группы в визуальном дереве (путь «Родитель / Группа»)
     * и разворачивание папок при фильтре group:* в API. Не влияет на права доступа —
     * каждая папка выдаётся явно через user_folders.
     */
    private static function groupParentTreePicker(string $label, array $groups, ?int $selectedId): void
    {
        [$byId, $children] = self::groupTreeStructure($groups);
        $pathLabels = self::groupPathLabels($groups);
        $selectedId = $selectedId !== null && isset($byId[$selectedId]) ? $selectedId : null;
        $selectedLabel = $selectedId !== null
            ? ($pathLabels[$selectedId] ?? (string)$byId[$selectedId]['name'])
            : self::t('groups.noParent', 'Без родителя');
        $expanded = self::groupTreeExpandedAncestors($byId, $selectedId !== null ? [$selectedId] : []);

        echo '<div class="form-field group-parent-field"><span class="form-field-label">' . Util::h($label) . '</span>';
        echo '<div class="group-tree-picker group-tree-select" data-group-tree-picker data-group-tree-select>';
        echo '<input type="hidden" name="parent_group_id" value="' . Util::h((string)($selectedId ?? '')) . '">';
        echo '<button type="button" class="group-tree-trigger" aria-haspopup="true" aria-expanded="false"><span data-group-tree-trigger-label>' . Util::h($selectedLabel) . '</span><span class="group-tree-caret" aria-hidden="true"></span></button>';
        echo '<div class="group-tree-menu" data-group-tree-menu hidden><div class="group-tree-list" role="tree" aria-label="' . Util::h($label) . '">';
        echo '<div class="group-tree-row" style="--depth: 0"><span class="group-tree-spacer" aria-hidden="true"></span><button type="button" class="group-tree-option group-tree-select-option' . ($selectedId === null ? ' active' : '') . '" data-group-tree-select-value="" data-group-tree-select-label="' . Util::h(self::t('groups.noParent', 'Без родителя')) . '" role="treeitem" aria-level="1"' . ($selectedId === null ? ' aria-current="true"' : '') . '>' . Util::h(self::t('groups.noParent', 'Без родителя')) . '</button></div>';
        if (!$groups) {
            echo '<div class="group-tree-empty">' . Util::h(self::t('filter.noGroups', 'Группы не найдены')) . '</div>';
        } else {
            self::renderGroupTreeNodes($byId, $children, $expanded, function (array $group, int $depth, bool $hasChildren, bool $isExpanded, callable $renderToggle) use ($selectedId, $pathLabels): void {
                $id = (int)$group['id'];
                $isActive = $selectedId === $id;
                $label = $pathLabels[$id] ?? (string)$group['name'];
                echo '<div class="group-tree-row" style="--depth: ' . (int)$depth . '">';
                $renderToggle();
                echo '<button type="button" class="group-tree-option group-tree-select-option' . ($isActive ? ' active' : '') . '" data-group-tree-select-value="' . $id . '" data-group-tree-select-label="' . Util::h($label) . '" role="treeitem" aria-level="' . (int)($depth + 1) . '"' . ($isActive ? ' aria-current="true"' : '') . '>' . Util::h((string)$group['name']) . '</button>';
                echo '</div>';
            });
        }
        echo '</div></div></div></div>';
    }

    private static function groupTreeStructure(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            $byId[(int)$group['id']] = $group;
        }

        $children = [0 => []];
        foreach ($byId as $id => $group) {
            $parentId = (int)($group['parent_group_id'] ?? 0);
            $children[($parentId > 0 && isset($byId[$parentId])) ? $parentId : 0][] = $id;
        }
        foreach ($children as &$ids) {
            usort($ids, static function (int $left, int $right) use ($byId): int {
                return strnatcasecmp((string)$byId[$left]['name'], (string)$byId[$right]['name']);
            });
        }
        unset($ids);

        return [$byId, $children];
    }

    private static function sortGroupTreeSelectedFirst(array &$children, array $byId, array $selectedSet): void
    {
        $branchSelected = [];
        $hasSelected = static function (int $id) use (&$hasSelected, &$branchSelected, $children, $selectedSet): bool {
            if (array_key_exists($id, $branchSelected)) {
                return $branchSelected[$id];
            }
            if (isset($selectedSet[$id])) {
                $branchSelected[$id] = true;
                return true;
            }
            foreach ($children[$id] ?? [] as $childId) {
                if ($hasSelected((int)$childId)) {
                    $branchSelected[$id] = true;
                    return true;
                }
            }
            $branchSelected[$id] = false;
            return false;
        };

        foreach ($children as &$ids) {
            usort($ids, static function (int $left, int $right) use ($byId, $selectedSet, $hasSelected): int {
                $leftSelected = isset($selectedSet[$left]) ? 0 : 1;
                $rightSelected = isset($selectedSet[$right]) ? 0 : 1;
                if ($leftSelected !== $rightSelected) {
                    return $leftSelected <=> $rightSelected;
                }

                $leftBranchSelected = $hasSelected($left) ? 0 : 1;
                $rightBranchSelected = $hasSelected($right) ? 0 : 1;
                if ($leftBranchSelected !== $rightBranchSelected) {
                    return $leftBranchSelected <=> $rightBranchSelected;
                }

                return strnatcasecmp((string)$byId[$left]['name'], (string)$byId[$right]['name']);
            });
        }
        unset($ids);
    }

    private static function groupTreeExpandedAncestors(array $byId, array $selectedIds): array
    {
        $expanded = [];
        foreach ($selectedIds as $selectedId) {
            $selectedId = (int)$selectedId;
            if ($selectedId <= 0 || !isset($byId[$selectedId])) {
                continue;
            }
            $parentId = (int)($byId[$selectedId]['parent_group_id'] ?? 0);
            $guard = [];
            while ($parentId > 0 && isset($byId[$parentId]) && !isset($guard[$parentId])) {
                $guard[$parentId] = true;
                $expanded[$parentId] = true;
                $parentId = (int)($byId[$parentId]['parent_group_id'] ?? 0);
            }
        }
        return $expanded;
    }

    private static function renderGroupTreeNodes(array $byId, array $children, array $expanded, callable $renderRow): void
    {
        $rendered = [];
        $renderNode = function (int $id, int $depth) use (&$renderNode, &$rendered, $byId, $children, $expanded, $renderRow): void {
            if (isset($rendered[$id]) || !isset($byId[$id])) {
                return;
            }
            $rendered[$id] = true;
            $group = $byId[$id];
            $name = (string)$group['name'];
            $hasChildren = !empty($children[$id]);
            $isExpanded = $hasChildren && isset($expanded[$id]);
            $expandLabel = sprintf(self::t('filter.expandGroup', 'Раскрыть группу %s'), $name);
            $collapseLabel = sprintf(self::t('filter.collapseGroup', 'Свернуть группу %s'), $name);
            $renderToggle = static function () use ($hasChildren, $isExpanded, $expandLabel, $collapseLabel): void {
                if ($hasChildren) {
                    echo '<button type="button" class="group-tree-toggle" data-group-tree-toggle aria-expanded="' . ($isExpanded ? 'true' : 'false') . '" aria-label="' . Util::h($isExpanded ? $collapseLabel : $expandLabel) . '" data-expand-label="' . Util::h($expandLabel) . '" data-collapse-label="' . Util::h($collapseLabel) . '">' . ($isExpanded ? '-' : '+') . '</button>';
                } else {
                    echo '<span class="group-tree-spacer" aria-hidden="true"></span>';
                }
            };

            echo '<div class="group-tree-node' . ($isExpanded ? ' is-expanded' : '') . '" data-group-tree-node>';
            $renderRow($group, $depth, $hasChildren, $isExpanded, $renderToggle);
            if ($hasChildren) {
                echo '<div class="group-tree-children" data-group-tree-children' . ($isExpanded ? '' : ' hidden') . '>';
                foreach ($children[$id] ?? [] as $childId) {
                    $renderNode((int)$childId, $depth + 1);
                }
                echo '</div>';
            }
            echo '</div>';
        };

        foreach ($children[0] ?? [] as $rootId) {
            $renderNode((int)$rootId, 0);
        }
        foreach (array_keys($byId) as $id) {
            if (!isset($rendered[$id])) {
                $renderNode((int)$id, 0);
            }
        }
    }

    /**
     * Навигация по вкладкам редактирования группы.
     * tab=1: Настройки, tab=2: Папки и камеры, tab=3: Пользователи.
     * Если группа не задана (новая) — показать только вкладку «Настройки» без навигации.
     */
    private static function groupEditTabNav(int $activeTab, int $folderCount, int $userCount): void
    {
        echo '<nav class="tab-nav" role="tablist">';
        $tabs = [
            [1, self::t('groups.tabSettings', 'Настройки')],
            [2, self::t('groups.tabFolders', 'Папки и камеры') . ' (' . $folderCount . ')'],
            [3, self::t('groups.tabUsers', 'Пользователи') . ' (' . $userCount . ')'],
        ];
        foreach ($tabs as [$id, $label]) {
            $active = $id === $activeTab ? ' active' : '';
            echo '<button type="button" class="tab-btn' . $active . '" data-tab="' . $id . '" role="tab" aria-selected="' . ($id === $activeTab ? 'true' : 'false') . '">' . Util::h($label) . '</button>';
        }
        echo '</nav>';
    }

    /**
     * Вкладка «Папки и камеры»: форма создания/редактирования папки + таблица папок
     * с раскрывающимися списками камер.
     */
    private static function groupEditFoldersPanel(array $edit, array $groupFolders, array $groupCameras, ?array $editFolder): void
    {
        echo '<details class="panel group-create-panel"' . ($editFolder ? ' open' : '') . '>';
        echo '<summary><h2>' . ($editFolder ? self::t('action.edit', 'Изменить') . ' — ' . Util::h($editFolder['name'] ?? '') : self::t('folders.new', 'Новая папка')) . '</h2></summary>';
        echo '<form method="post" class="form folder-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="save_folder">';
        echo '<input type="hidden" name="group_id" value="' . (int)$edit['id'] . '">';
        echo '<input type="hidden" name="folder_id" value="' . (int)($editFolder['id'] ?? 0) . '">';
        echo '<label>' . self::t('folders.name', 'Название папки') . '<input name="folder_name" value="' . Util::h($editFolder['name'] ?? '') . '" required></label>';
        echo '<label>' . self::t('column.description', 'Описание') . '<textarea name="folder_description">' . Util::h($editFolder['description'] ?? '') . '</textarea></label>';
        echo '<label class="check"><input type="checkbox" name="folder_blocked" ' . (!empty($editFolder['blocked']) ? 'checked' : '') . '> ' . self::t('column.blocked', 'Заблокирована') . '</label>';
        echo '<button class="primary">' . ($editFolder ? self::t('action.save', 'Сохранить') : self::t('folders.new', 'Новая папка')) . '</button>';
        if ($editFolder) {
            echo '<a class="btn" href="/admin/groups?edit=' . (int)$edit['id'] . '&tab=2">' . self::t('action.cancel', 'Отмена') . '</a>';
        }
        echo '</form>';
        echo '</details>';

        if ($groupFolders) {
            echo '<table class="folder-list"><thead><tr><th></th><th>' . self::t('column.name', 'Название') . '</th><th>' . self::t('cameras.count', 'Камер') . '</th><th>' . self::t('column.blocked', 'Заблокирована') . '</th><th></th></tr></thead><tbody>';
            foreach ($groupFolders as $f) {
                $folderId = (int)$f['id'];
                $cameras = $groupCameras[$folderId] ?? [];
                $cameraCount = count($cameras);
                $hasCameras = $cameraCount > 0;
                echo '<tr class="folder-expand-row" data-folder-id="' . $folderId . '">';
                echo '<td class="folder-caret-cell"><span class="folder-caret" aria-hidden="true">' . ($hasCameras ? '&#9654;' : '') . '</span></td>';
                echo '<td>' . Util::h((string)$f['name']) . '</td>';
                echo '<td>' . $cameraCount . '</td>';
                echo '<td>' . (!empty($f['blocked']) ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет')) . '</td>';
                echo '<td>';
                $groupBack = '/admin/groups?edit=' . (int)$edit['id'] . '&tab=2';
                echo '<div class="folder-action-dropdown">';
                echo '<button type="button" class="btn folder-action-trigger">' . self::t('folders.addCamera', 'Добавить камеру') . ' &#9662;</button>';
                echo '<div class="folder-action-menu" hidden>';
                echo '<a href="' . Util::h('/admin/cameras?new=1&back=' . rawurlencode($groupBack)) . '">' . self::t('folders.addNewCamera', 'Добавить новую камеру') . '</a>';
                echo '<button type="button" class="folder-pick-camera-btn" data-folder-id="' . $folderId . '">' . self::t('folders.addExistingCamera', 'Добавить существующую камеру') . '</button>';
                echo '</div></div> ';
                echo '<a class="btn" href="/admin/groups?edit=' . (int)$edit['id'] . '&tab=2&edit_folder=' . $folderId . '">' . self::t('action.edit', 'Изменить') . '</a> ';
                self::smallPost('/admin/groups', ['action' => 'delete_folder', 'id' => $folderId, 'group_id' => (int)$edit['id']], self::t('action.delete', 'Удалить'), 'danger');
                echo '</td></tr>';
                echo '<tr class="folder-cameras-row" data-folder-id="' . $folderId . '" hidden><td colspan="5">';
                if ($hasCameras) {
                    $groupBack = '/admin/groups?edit=' . (int)$edit['id'] . '&tab=2';
                    echo '<div class="folder-cameras-grid">';
                    foreach ($cameras as $cam) {
                        $editUrl = '/admin/cameras?edit=' . (int)$cam['id'] . '&back=' . rawurlencode($groupBack);
                        $mode = $cam['dvr_control_mode'] ?? 'managed';
                        $modeLabel = $mode === 'read_only' ? 'read-only' : ($mode === 'edge_agent' ? 'edge' : '');
                        $status = !empty($cam['blocked']) ? ' <span class="pill danger">' . self::t('column.blocked', 'забл.') . '</span>' : '';
                        $modeSuffix = $modeLabel !== '' ? ' <span class="muted">(' . Util::h($modeLabel) . ')</span>' : '';
                        $syncMsg = trim((string)($cam['last_sync_message'] ?? ''));
                        $syncDot = '';
                        if ($syncMsg !== '') {
                            $syncStatus = self::syncResultStatus($syncMsg);
                            $syncDot = ' <span class="sync-result-dot sync-result-dot-' . Util::h($syncStatus) . '" title="' . Util::h($syncMsg) . '" aria-label="' . Util::h($syncMsg) . '" role="img"></span>';
                        }
                        $syncTime = '';
                        if (!empty($cam['last_sync_at'])) {
                            $syncTime = ' <span class="muted" style="font-size:0.8em"><time class="local-time" datetime="' . Util::h($cam['last_sync_at']) . '">' . Util::h($cam['last_sync_at']) . '</time></span>';
                        }
                        echo '<a class="folder-camera-card" href="' . Util::h($editUrl) . '"><span class="folder-camera-name">' . Util::h($cam['name']) . '</span><span class="folder-camera-meta"><span class="muted">' . Util::h($cam['dvr_stream_name'] ?? '') . '</span>' . $modeSuffix . $status . $syncDot . $syncTime . '</span></a>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="muted" style="padding:6px 16px 6px 32px;">' . self::t('folders.noCameras', 'Нет камер') . '</div>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            foreach ($groupFolders as $f) {
                $folderId = (int)$f['id'];
                $availableCameras = Repo::camerasNotInFolder($folderId);
                echo '<dialog class="camera-picker-dialog" id="camera-picker-' . $folderId . '">';
                echo '<div class="camera-picker-header">';
                echo '<h3>' . self::t('folders.pickCamera', 'Добавить камеру в') . ' ' . Util::h((string)$f['name']) . '</h3>';
                echo '<button type="button" class="btn camera-picker-close" aria-label="' . self::t('action.close', 'Закрыть') . '">&times;</button>';
                echo '</div>';
                echo '<input type="search" class="camera-picker-search" placeholder="' . self::t('action.search', 'Поиск...') . '">';
                echo '<div class="camera-picker-list">';
                if ($availableCameras) {
                    foreach ($availableCameras as $cam) {
                        echo '<form method="post" class="camera-pick-item" data-camera-name="' . Util::h(strtolower($cam['name'])) . '">';
                        echo Csrf::field();
                        echo '<input type="hidden" name="action" value="add_camera_to_folder">';
                        echo '<input type="hidden" name="folder_id" value="' . $folderId . '">';
                        echo '<input type="hidden" name="group_id" value="' . (int)$edit['id'] . '">';
                        echo '<input type="hidden" name="camera_id" value="' . (int)$cam['id'] . '">';
                        echo '<span class="camera-pick-name">' . Util::h($cam['name']) . '</span>';
                        echo '<span class="camera-pick-stream muted">' . Util::h($cam['dvr_stream_name'] ?? '') . '</span>';
                        echo '<button type="submit" class="btn primary camera-pick-add">' . self::t('action.add', 'Добавить') . '</button>';
                        echo '</form>';
                    }
                } else {
                    echo '<div class="muted camera-pick-empty">' . self::t('folders.allCamerasLinked', 'Все камеры уже добавлены') . '</div>';
                }
                echo '</div></dialog>';
            }
        } else {
            echo '<p class="muted">' . self::t('folders.empty', 'Пока нет папок') . '</p>';
        }
    }

    /**
     * Вкладка «Пользователи»: список пользователей, которым выдана хотя бы одна папка группы.
     */
    private static function groupEditUsersPanel(array $groupUsers, array $groupFolders, int $groupId): void
    {
        if (!$groupUsers) {
            echo '<p class="muted">' . self::t('groups.noUsers', 'Нет пользователей с доступом к папкам этой группы') . '</p>';
            return;
        }

        echo '<table class="data-table"><thead><tr>';
        echo '<th>' . self::t('column.name', 'Логин') . '</th>';
        echo '<th>' . self::t('column.role', 'Роль') . '</th>';
        echo '<th>' . self::t('column.blocked', 'Заблокирован') . '</th>';
        echo '<th>' . self::t('column.email', 'Email') . '</th>';
        echo '<th>' . self::t('groups.userFolders', 'Папки в группе') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($groupUsers as $u) {
            $folderNames = Repo::folderNamesForUserInGroup((int)$u['id'], $groupId);
            $back = '/admin/groups?edit=1&tab=3';
            echo '<tr data-href="/admin/users?edit=1&amp;id=' . (int)$u['id'] . '&amp;back=' . rawurlencode($back) . '">';
            echo '<td>' . Util::h($u['login']) . '</td>';
            $roleClass = ($u['role'] ?? '') === 'admin' ? 'success' : '';
            echo '<td><span class="pill ' . $roleClass . '">' . Util::h($u['role'] ?? 'user') . '</span></td>';
            echo '<td>' . (!empty($u['blocked']) ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет')) . '</td>';
            echo '<td>' . Util::h($u['email'] ?? '') . '</td>';
            echo '<td>' . Util::h(implode(', ', $folderNames)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function previewRefreshSelect(string $previewRefresh): void
    {
        $options = [
            'off' => self::t('viewer.refreshOff', 'Отключено'),
            '10' => sprintf(self::t('viewer.refreshSeconds', '%d сек.'), 10),
            '30' => sprintf(self::t('viewer.refreshSeconds', '%d сек.'), 30),
            '60' => sprintf(self::t('viewer.refreshSeconds', '%d сек.'), 60),
            '300' => sprintf(self::t('viewer.refreshSeconds', '%d сек.'), 300),
        ];
        echo '<label class="preview-refresh-control"><span>' . Util::h(self::t('viewer.previewRefresh', 'Обновление превью')) . '</span><select name="refresh" aria-label="' . Util::h(self::t('viewer.previewRefresh', 'Обновление превью')) . '">';
        foreach ($options as $value => $label) {
            echo '<option value="' . Util::h($value) . '"' . ($previewRefresh === $value ? ' selected' : '') . '>' . Util::h($label) . '</option>';
        }
        echo '</select></label>';
    }

    private static function densitySwitch(string $filter, string $searchQuery, int $cols, string $previewRefresh): void
    {
        echo '<nav class="density-switch" aria-label="' . Util::h(self::t('viewer.columnsPerRow', 'Камер в ряду')) . '">';
        echo '<span>' . Util::h(self::t('viewer.columnsPerRow', 'Камер в ряду')) . '</span>';
        for ($candidate = 2; $candidate <= 6; $candidate++) {
            $params = ['cols' => $candidate];
            if ($previewRefresh !== '30') {
                $params['refresh'] = $previewRefresh;
            }
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

    private static function serverMetricCard(array $server): void
    {
        $metrics = json_decode((string)($server['last_metrics_json'] ?? ''), true);
        $metrics = is_array($metrics) ? $metrics : [];
        $version = is_array($metrics['version'] ?? null) ? $metrics['version'] : [];
        $status = is_array($metrics['status'] ?? null) ? $metrics['status'] : [];
        $versionText = self::serverVersionText($version);
        $cpu = self::serverCpuText($status);
        $memory = self::serverMemoryText($status);
        $streams = self::serverStreamsText($metrics, $status);
        $tokenIssue = self::serverManagementTokenIssue($server);
        $metricExplanation = self::serverMetricExplanation($server, $tokenIssue);

        echo '<article class="server-card">';
        echo '<div><strong>' . Util::h($server['name']) . '</strong><span>' . Util::h($server['base_url']) . '</span></div>';
        echo '<dl>';
        echo '<dt>' . self::t('server.version', 'Версия') . '</dt><dd>' . Util::h($versionText) . '</dd>';
        echo '<dt>CPU</dt><dd>' . Util::h($cpu ?? '-') . '</dd>';
        echo '<dt>RAM</dt><dd>' . Util::h($memory ?? '-') . '</dd>';
        echo '<dt>' . self::t('server.streams', 'Потоки') . '</dt><dd>' . Util::h($streams ?? '-') . '</dd>';
        echo '<dt>' . self::t('server.check', 'Проверка') . '</dt><dd>' . self::localTime($server['last_metrics_at'] ?: $server['last_check_at'] ?: '') . '</dd>';
        echo '</dl>';
        if ($metricExplanation !== null) {
            echo '<div class="server-metric-explain">' . Util::h($metricExplanation) . '</div>';
        }
        if (!empty($server['last_check_result']) && $tokenIssue === null) {
            echo '<div class="server-check-result">';
            self::technicalResult((string)$server['last_check_result']);
            echo '</div>';
        }
        self::smallPost('/admin/dashboard', ['action' => 'refresh_server', 'id' => $server['id']], self::t('action.update', 'Обновить'));
        echo '</article>';
    }

    private static function serverManagementTokenIssue(array $server): ?string
    {
        $encoded = trim((string)($server['management_token_enc'] ?? ''));
        if ($encoded === '') {
            return 'management_token_missing';
        }

        return Crypto::decrypt($encoded) === '' ? 'management_token_unreadable' : null;
    }

    private static function serverMetricExplanation(array $server, ?string $tokenIssue): ?string
    {
        if ($tokenIssue !== null) {
            return self::metricFailureNotice($tokenIssue, '');
        }

        $lastResult = (string)($server['last_check_result'] ?? '');
        if (preg_match('/^HTTP\s+401\b/', $lastResult) || str_contains($lastResult, 'HTTP 401')) {
            return self::metricFailureNotice('', $lastResult);
        }

        return null;
    }

    private static function serverVersionText(array $version): string
    {
        $info = $version;
        if (isset($info['version']) && is_array($info['version'])) {
            $info = $info['version'];
        }

        foreach (['appVersion', 'version', 'buildId', 'commit', 'sourceCommit'] as $key) {
            $value = self::scalarText($info[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return 'unknown';
    }

    private static function serverCpuText(array $status): ?string
    {
        $value = self::numericMetric($status, [
            'cpu.aggregate.usagePercent',
            'cpu.totalPercent',
            'cpu.percent',
            'system.cpuPercent',
        ]);
        return $value === null ? null : self::formatPercent($value);
    }

    private static function serverMemoryText(array $status): ?string
    {
        $value = self::numericMetric($status, [
            'memory.usedPercent',
            'system.memoryUsedPercent',
            'ram.usedPercent',
        ]);
        if ($value !== null) {
            return self::formatPercent($value);
        }

        $used = self::numericMetric($status, ['memory.usedBytes', 'ram.usedBytes']);
        $total = self::numericMetric($status, ['memory.totalBytes', 'ram.totalBytes']);
        if ($used !== null && $total !== null && $total > 0) {
            return self::formatPercent(($used / $total) * 100);
        }

        return null;
    }

    private static function serverStreamsText(array $metrics, array $status): ?string
    {
        $streams = $metrics['streams'] ?? null;
        if (is_array($streams)) {
            if (isset($streams['streams']) && is_array($streams['streams'])) {
                return (string)count($streams['streams']);
            }
            if (array_is_list($streams)) {
                return (string)count($streams);
            }
        }

        $value = self::numericMetric($status, [
            'streams.total',
            'streamCount',
            'cameras.total',
            'archiveOrphans.activeCameraCount',
        ]);
        return $value === null ? null : (string)(int)$value;
    }

    private static function numericMetric(array $data, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = self::arrayPath($data, $path);
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return (float)$value;
            }
        }
        return null;
    }

    private static function scalarText(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }
        return null;
    }

    private static function formatPercent(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.') . '%';
    }

    private static function arrayPath(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private static function filteredRows(string $table, array $searchColumns, string $order, int $pageSize = 25): array
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = '';
        $params = [];

        if ($q !== '') {
            $likes = [];
            foreach ($searchColumns as $column) {
                $likes[] = DB::caseInsensitiveLike($column);
                $params[] = '%' . $q . '%';
            }
            $where = ' WHERE ' . implode(' OR ', $likes);
        }

        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT * FROM ' . $table . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
        $bind = [...$params, $pageSize, ($page - 1) * $pageSize];
        foreach ($bind as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'q' => $q];
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

        if ($filters['folder_id'] > 0) {
            $join .= ' JOIN camera_folders cf_filter ON cf_filter.camera_id = c.id';
            $where[] = 'cf_filter.folder_id = ?';
            $params[] = (int)$filters['folder_id'];
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(DISTINCT c.id) FROM cameras c' . $join . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT * FROM (SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url, COALESCE(s.name, \'\') AS sort_server, COALESCE(c.last_sync_ok, -1) AS sort_sync FROM cameras c' . $join . $sqlWhere . ') AS list ORDER BY ' . self::cameraListOrderSql($filters['sort'], $filters['dir']) . ' LIMIT ? OFFSET ?');
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
            'folder_id' => max(0, (int)($_GET['folder_id'] ?? 0)),
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    private static function filteredUsers(int $pageSize = 25): array
    {
        $filters = self::userListFilters();
        $page = $filters['page'];
        $where = [];
        $params = [];
        $join = '';

        if ($filters['q'] !== '') {
            $columns = ['u.login', 'u.name', 'u.phone', 'u.role', 'u.admin_comment'];
            $where[] = '(' . implode(' OR ', array_map([DB::class, 'caseInsensitiveLike'], $columns)) . ')';
            array_push($params, ...array_fill(0, count($columns), '%' . $filters['q'] . '%'));
        }

        if ($filters['folder_id'] > 0) {
            $join .= ' JOIN user_folders uf_filter ON uf_filter.user_id = u.id';
            $where[] = 'uf_filter.folder_id = ?';
            $params[] = (int)$filters['folder_id'];
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(DISTINCT u.id) FROM users u' . $join . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT DISTINCT u.* FROM users u' . $join . $sqlWhere . ' ORDER BY u.login ASC LIMIT ? OFFSET ?');
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

    private static function userListFilters(): array
    {
        return [
            'q' => trim((string)($_GET['q'] ?? '')),
            'page' => max(1, (int)($_GET['page'] ?? 1)),
            'folder_id' => max(0, (int)($_GET['folder_id'] ?? $_GET['folderId'] ?? $_GET['folderID'] ?? 0)),
        ];
    }

    private static function cameraListSortColumns(): array
    {
        return [
            'name' => 'name',
            'stream' => 'dvr_stream_name',
            'server' => 'sort_server',
            'mode' => 'dvr_control_mode',
            'archive' => 'archive_enabled',
            'retention' => 'retention_days',
            'sync' => 'sort_sync',
            'updated' => 'updated_at',
            'created' => 'created_at',
        ];
    }

    private static function cameraListOrderSql(string $sort, string $dir): string
    {
        $columns = self::cameraListSortColumns();
        $column = $columns[$sort] ?? $columns['name'];
        $direction = $dir === 'desc' ? 'DESC' : 'ASC';
        $tieDirection = $sort === 'name' ? $direction : 'ASC';
        return $column . ' ' . $direction . ', name ' . $tieDirection . ', id ASC';
    }

    private static function sqlPlaceholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private static function filteredAudit(int $pageSize = 50): array
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $action = trim((string)($_GET['action'] ?? ''));
        $actor = (int)($_GET['actor'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(' . implode(' OR ', array_map([DB::class, 'caseInsensitiveLike'], ['a.action', 'a.details', 'u.login'])) . ')';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        if ($action !== '') {
            $where[] = 'a.action = ?';
            $params[] = $action;
        }
        if ($actor > 0) {
            $where[] = 'a.actor_user_id = ?';
            $params[] = $actor;
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id' . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT a.*, u.login FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id' . $sqlWhere . ' ORDER BY a.id DESC LIMIT ? OFFSET ?');
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
            'q' => $q,
            'action' => $action,
            'actor' => $actor,
        ];
    }

    private static function auditDetails(string $details): void
    {
        $details = trim($details);
        if ($details === '') {
            echo '-';
            return;
        }

        $json = json_decode($details, true);
        if (is_array($json)) {
            echo '<dl class="audit-details">';
            foreach ($json as $key => $value) {
                echo '<dt>' . Util::h((string)$key) . '</dt><dd>' . Util::h(is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</dd>';
            }
            echo '</dl>';
            return;
        }

        preg_match_all('/(?:^|\\s)([A-Za-z0-9_.-]+)=([^\\s]+)/', $details, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!$matches) {
            echo '<div class="audit-details audit-details-text">' . Util::h($details) . '</div>';
            return;
        }

        echo '<div class="audit-details">';
        $context = trim(substr($details, 0, (int)$matches[0][0][1]));
        if ($context !== '') {
            echo '<span class="audit-context">' . Util::h($context) . '</span>';
        }
        foreach ($matches as $match) {
            echo '<span><strong>' . Util::h($match[1][0]) . '</strong> ' . Util::h($match[2][0]) . '</span>';
        }
        if (strlen($details) > 120 || count($matches) > 1) {
            echo '<details class="audit-raw"><summary>' . self::t('audit.raw', 'Полный текст') . '</summary><pre>' . Util::h($details) . '</pre></details>';
        }
        echo '</div>';
    }

    private static function table(string $title, array $columns, array $rows, string $base, bool $actions = false, ?array $pager = null, bool $showSearch = true): void
    {
        echo '<section class="panel"><div class="section-head"><h2>' . Util::h($title) . '</h2>';
        if ($showSearch) {
            if ($base === '/admin/cameras') {
                self::cameraTableFilters($pager ?? []);
            } elseif ($base === '/admin/users') {
                self::userTableFilters($pager ?? []);
            } else {
                echo '<form method="get" action="' . Util::h($base) . '" class="table-search">';
                echo '<input name="q" value="' . Util::h($pager['q'] ?? '') . '" placeholder="' . self::t('table.search', 'Поиск') . '">';
                echo '<button>' . self::t('action.find', 'Найти') . '</button>';
                echo '</form>';
            }
        }
        $tableClass = 'data-table';
        if (str_starts_with($base, '/admin/')) {
            $tableClass .= ' table-' . str_replace(['/', '_'], '-', trim(substr($base, strlen('/admin/')), '/'));
        }

        echo '</div><div class="table-wrap"><table class="' . Util::h($tableClass) . '"><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . Util::h(self::columnLabel($column)) . '</th>';
        }
        $actionUrl = self::tableActionUrl($base, [], $pager);
        echo '<th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $editUrl = self::tableActionUrl($base, ['edit' => (int)$row['id']], $pager);
            echo '<tr data-href="' . Util::h($editUrl) . '">';
            foreach ($columns as $column) {
                self::tableCell($column, $row[$column] ?? '');
            }
            echo '<td><div class="row-actions row-actions-icons">';
            self::iconActionLink(self::tableActionUrl($base, ['edit' => (int)$row['id']], $pager), self::t('action.edit', 'Изменить'), 'edit');
            if ($actions && str_contains($base, 'servers')) {
                self::smallPost($actionUrl, ['action' => 'check', 'id' => $row['id']], self::t('action.check', 'Проверить'), '', '', 'check');
            }
            if ($actions && str_contains($base, 'cameras')) {
                self::smallPost($actionUrl, ['action' => 'sync', 'id' => $row['id']], self::t('action.sync', 'Синхронизировать'), '', '', 'sync');
            }
            if ($base === '/admin/cameras' || $base === '/admin/groups') {
                self::iconActionLink(self::tableActionUrl($base, ['delete' => (int)$row['id']], $pager), self::t('action.delete', 'Удалить'), 'trash', 'danger');
            } elseif ($base === '/admin/users') {
                self::smallPost(
                    $actionUrl,
                    ['action' => 'delete', 'id' => $row['id']],
                    self::t('action.delete', 'Удалить'),
                    'danger',
                    sprintf(self::t('users.deleteConfirmText', 'Удалить пользователя «%s»? Это действие нельзя отменить.'), $row['login'] ?? ''),
                    'trash',
                    self::t('users.deleteConfirmButton', 'Удаление пользователя')
                );
            } else {
                self::smallPost($actionUrl, ['action' => 'delete', 'id' => $row['id']], self::t('action.delete', 'Удалить'), 'danger', '', 'trash');
            }
            if ($base === '/admin/users') {
                $hasStaticToken = trim((string)($row['static_token_hash'] ?? '')) !== '';
                self::smallPost(
                    $actionUrl,
                    ['action' => 'issue_static', 'id' => $row['id']],
                    $hasStaticToken ? self::t('token.staticReplace', 'Заменить статический токен') : self::t('token.staticIssue', 'Выпустить статический токен'),
                    '',
                    $hasStaticToken ? self::t('token.staticReplaceConfirm', 'Старый статический токен сразу перестанет работать. Выпустить новый токен?') : '',
                    $hasStaticToken ? 'token-refresh' : 'token-issue'
                );
                if ($hasStaticToken) {
                    self::smallPost($actionUrl, ['action' => 'revoke_static', 'id' => $row['id']], self::t('action.revoke', 'Отозвать'), 'danger', '', 'token-revoke');
                }
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
        if ($pager) {
            self::pager($base, $pager);
        }
        echo '</section>';
    }

    private static function cameraTableFilters(array $pager): void
    {
        $servers = Repo::all('dvr_servers', 'name ASC');
        $folders = self::folderRowsWithGroupLabels(Repo::allFolders());
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
        echo '<select name="folder_id" aria-label="' . Util::h(self::t('folders.title', 'Папки')) . '">';
        self::selectOption('0', self::t('cameraFilter.allFolders', 'Все папки'), (string)(int)($pager['folder_id'] ?? 0));
        foreach ($folders as $folder) {
            self::selectOption((string)$folder['id'], (string)($folder['display_name'] ?? $folder['name']), (string)(int)($pager['folder_id'] ?? 0));
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

    private static function userTableFilters(array $pager): void
    {
        $folders = self::folderRowsWithGroupLabels(Repo::allFolders());

        echo '<form method="get" action="/admin/users" class="table-search user-admin-filters">';
        echo '<input name="q" value="' . Util::h($pager['q'] ?? '') . '" placeholder="' . self::t('table.search', 'Поиск') . '">';
        echo '<select name="folder_id" aria-label="' . Util::h(self::t('folders.title', 'Папки')) . '">';
        self::selectOption('0', self::t('cameraFilter.allFolders', 'Все папки'), (string)(int)($pager['folder_id'] ?? 0));
        foreach ($folders as $folder) {
            self::selectOption((string)$folder['id'], (string)($folder['display_name'] ?? $folder['name']), (string)(int)($pager['folder_id'] ?? 0));
        }
        echo '</select>';
        echo '<button>' . self::t('action.find', 'Найти') . '</button>';
        echo '<a class="camera-filter-reset" href="/admin/users">' . self::t('cameraFilter.reset', 'Сбросить') . '</a>';
        echo '</form>';
    }

    private static function selectOption(string $value, string $label, string $selected): void
    {
        echo '<option value="' . Util::h($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . Util::h($label) . '</option>';
    }

    private static function columnLabel(string $column): string
    {
        return self::t('column.' . $column, $column);
    }

    private static function tableCell(string $column, mixed $value): void
    {
        if ($column === 'archive_enabled') {
            $enabled = (int)$value === 1;
            $label = $enabled ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет');
            echo '<td><span class="pill ' . ($enabled ? 'success' : 'danger') . '">' . Util::h($label) . '</span></td>';
            return;
        }

        if ($column === 'hide_archive') {
            $enabled = (int)$value === 1;
            $label = $enabled ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет');
            echo '<td><span class="pill ' . ($enabled ? 'warn' : 'success') . '">' . Util::h($label) . '</span></td>';
            return;
        }

        if ($column === 'static_token_hash') {
            $hasToken = trim((string)$value) !== '';
            $label = $hasToken
                ? self::t('token.staticPresent', 'есть')
                : self::t('token.staticMissing', 'нет');
            echo '<td><span class="pill ' . ($hasToken ? 'success' : 'danger') . '">' . Util::h($label) . '</span></td>';
            return;
        }

        if ($column === 'admin_comment') {
            $text = trim((string)$value);
            if ($text === '') {
                echo '<td class="muted">-</td>';
                return;
            }
            echo '<td class="table-comment" title="' . Util::h($text) . '">' . Util::h(self::technicalSummary($text)) . '</td>';
            return;
        }

        if ($column === 'last_sync_message') {
            self::syncResultCell(trim((string)$value));
            return;
        }

        if ($column === 'last_check_result') {
            $text = trim((string)$value);
            if ($text === '') {
                echo '<td class="muted">-</td>';
                return;
            }
            echo '<td class="table-technical">';
            self::technicalResult($text);
            echo '</td>';
            return;
        }

        if ($column === 'phone') {
            $phone = trim((string)$value);
            if ($phone === '') {
                echo '<td class="muted">-</td>';
                return;
            }
            echo '<td>' . Util::h(self::callbackFormatPhone($phone)) . '</td>';
            return;
        }

        if (str_ends_with($column, '_at')) {
            echo '<td class="time-cell">' . self::localTime($value) . '</td>';
            return;
        }

        echo '<td>' . Util::h($value) . '</td>';
    }

    private static function localTime(mixed $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '<span class="muted">-</span>';
        }

        try {
            $time = new DateTimeImmutable($text);
        } catch (\Throwable) {
            return Util::h($text);
        }

        return '<time class="local-time" datetime="' . Util::h($time->format(DateTimeInterface::ATOM)) . '">' . Util::h($text) . '</time>';
    }

    private static function technicalResult(string $text, ?string $summary = null): void
    {
        echo '<details class="technical-result"><summary>' . Util::h($summary ?? self::technicalSummary($text)) . '</summary><pre>' . Util::h($text) . '</pre></details>';
    }

    private static function technicalSummary(string $text): string
    {
        if (preg_match('/^HTTP\\s+\\d+/', $text, $match)) {
            return $match[0];
        }
        if (strlen($text) <= 80) {
            return $text;
        }
        return rtrim(substr($text, 0, 77)) . '...';
    }

    private static function syncResultCell(string $text): void
    {
        if ($text === '') {
            echo '<td class="muted">-</td>';
            return;
        }

        $status = self::syncResultStatus($text);
        echo '<td class="table-result"><span class="sync-result-dot sync-result-dot-' . Util::h($status) . '" title="' . Util::h($text) . '" aria-label="' . Util::h($text) . '" role="img"></span></td>';
    }

    private static function syncResultStatus(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        if (str_contains($lower, 'read-only') || str_contains($lower, 'read_only') || str_contains($lower, 'readonly') || str_contains($lower, 'только чт')) {
            return 'readonly';
        }

        if (preg_match('/\\bHTTP\\s+(\\d{3})\\b/i', $text, $match)) {
            return (int)$match[1] >= 400 ? 'bad' : 'ok';
        }

        $badMarkers = [
            'error',
            'failed',
            'failure',
            'timeout',
            'timed out',
            'unavailable',
            'blocked',
            'missing',
            'invalid',
            'denied',
            'forbidden',
            'unauthorized',
            'cannot',
            'refused',
            'mismatch',
            'not_found',
            'not configured',
            'no sesamedvr',
            'ошиб',
            'не выполн',
            'недоступ',
            'заблок',
            'не указан',
            'не настро',
            'нельзя',
            'отказ',
            'таймаут',
        ];
        foreach ($badMarkers as $marker) {
            if (str_contains($lower, $marker)) {
                return 'bad';
            }
        }

        return 'ok';
    }

    private static function pager(string $base, array $pager, array $extraParams = []): void
    {
        if (!$pager) {
            return;
        }
        $total = (int)($pager['total'] ?? 0);
        $pageSize = max(1, (int)($pager['pageSize'] ?? 1));
        $currentPage = max(1, (int)($pager['page'] ?? 1));
        $rowCount = count($pager['rows'] ?? []);
        $from = $total === 0 ? 0 : (($currentPage - 1) * $pageSize) + 1;
        $to = $total === 0 ? 0 : min($total, $from + $rowCount - 1);
        $shown = $total === 0 ? '0' : $from . '-' . $to;

        echo '<div class="pager-note">' . self::t('table.shown', 'Показано') . ' ' . Util::h($shown) . ' ' . self::t('table.of', 'из') . ' ' . Util::h($total) . '</div>';
        $pages = (int)ceil(max(1, (int)$pager['total']) / max(1, (int)$pager['pageSize']));
        if ($pages <= 1) {
            return;
        }

        $pageHref = static function (int $page) use ($base, $pager, $extraParams): string {
            $queryParams = self::pagerQueryParams($pager);
            $queryParams['page'] = $page;
            foreach ($extraParams as $key => $value) {
                $queryParams[$key] = $value;
            }
            $query = http_build_query(array_filter($queryParams, fn($value) => $value !== '' && $value !== null && $value !== 0));
            return $base . ($query ? '?' . $query : '');
        };
        $visible = [1, $pages];
        for ($page = $currentPage - 2; $page <= $currentPage + 2; $page++) {
            if ($page >= 1 && $page <= $pages) {
                $visible[] = $page;
            }
        }
        $visible = array_values(array_unique($visible));
        sort($visible);

        echo '<nav class="pager">';
        if ($currentPage > 1) {
            echo '<a href="' . Util::h($pageHref($currentPage - 1)) . '">&lsaquo;</a>';
        }
        $previous = 0;
        foreach ($visible as $page) {
            if ($previous > 0 && $page > $previous + 1) {
                echo '<span class="pager-gap">...</span>';
            }
            echo '<a class="' . ($currentPage === $page ? 'active' : '') . '" href="' . Util::h($pageHref($page)) . '">' . $page . '</a>';
            $previous = $page;
        }
        if ($currentPage < $pages) {
            echo '<a href="' . Util::h($pageHref($currentPage + 1)) . '">&rsaquo;</a>';
        }
        echo '</nav>';
    }

    private static function tableActionUrl(string $base, array $params = [], ?array $pager = null): string
    {
        $query = [];
        if ($pager) {
            $query = self::pagerQueryParams($pager);
            $page = (int)($pager['page'] ?? 1);
            if ($page > 1) {
                $query['page'] = $page;
            }
        }

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || $value === 0) {
                continue;
            }
            $query[$key] = $value;
        }

        $encoded = http_build_query($query);
        return $base . ($encoded !== '' ? '?' . $encoded : '');
    }

    private static function pagerQueryParams(array $pager): array
    {
        $query = [];
        foreach (['q', 'server_id', 'mode', 'archive', 'sync'] as $key) {
            $value = trim((string)($pager[$key] ?? ''));
            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        $folderId = (int)($pager['folder_id'] ?? 0);
        if ($folderId > 0) {
            $query['folder_id'] = $folderId;
        }

        $sort = trim((string)($pager['sort'] ?? ''));
        $dir = strtolower(trim((string)($pager['dir'] ?? 'asc')));
        if ($sort !== '' && ($sort !== 'name' || $dir === 'desc')) {
            $query['sort'] = $sort;
        }
        if ($dir === 'desc') {
            $query['dir'] = 'desc';
        }

        return $query;
    }

    private static function iconActionLink(string $href, string $label, string $icon, string $class = ''): void
    {
        $classes = trim('icon-action ' . $class);
        echo '<a href="' . Util::h($href) . '" class="' . Util::h($classes) . '" title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '">' . self::icon($icon) . '<span class="sr-only">' . Util::h($label) . '</span></a>';
    }

    private static function smallPost(string $path, array $fields, string $label, string $class = '', string $confirm = '', string $icon = '', string $confirmOk = ''): void
    {
        self::smallPostFormOpen('', $path, $fields, $confirm, $confirmOk);
        self::smallPostButton('', $label, $class, $icon);
        self::smallPostFormClose();
    }

    private static function smallPostFormOpen(string $formId, string $path, array $fields, string $confirm = '', string $confirmOk = ''): void
    {
        echo '<form method="post" action="' . Util::h($path) . '" class="inline-form"';
        if ($formId !== '') {
            echo ' id="' . Util::h($formId) . '"';
        }
        if ($confirm !== '') {
            echo ' data-confirm="' . Util::h($confirm) . '"';
            if ($confirmOk !== '') {
                echo ' data-confirm-ok="' . Util::h($confirmOk) . '"';
            }
        }
        echo '>' . Csrf::field();
        foreach ($fields as $key => $value) {
            echo '<input type="hidden" name="' . Util::h($key) . '" value="' . Util::h($value) . '">';
        }
    }

    private static function smallPostButton(string $formId, string $label, string $class = '', string $icon = ''): void
    {
        $buttonClass = trim($class . ($icon !== '' ? ' icon-action' : ''));
        echo '<button type="submit"';
        if ($formId !== '') {
            echo ' form="' . Util::h($formId) . '"';
        }
        echo ' class="' . Util::h($buttonClass) . '"';
        if ($icon !== '') {
            echo ' title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '"';
        }
        echo '>';
        if ($icon !== '') {
            echo self::icon($icon) . '<span class="sr-only">' . Util::h($label) . '</span>';
        } else {
            echo Util::h($label);
        }
        echo '</button>';
    }

    private static function smallPostFormClose(): void
    {
        echo '</form>';
    }

    private static function checkboxList(string $title, string $name, array $rows, array $selected, string $labelKey): void
    {
        echo '<fieldset><legend>' . Util::h($title) . '</legend><div class="check-list">';
        foreach ($rows as $row) {
            echo '<label class="check"><input type="checkbox" name="' . Util::h($name) . '" value="' . (int)$row['id'] . '" ' . (in_array((int)$row['id'], $selected, true) ? 'checked' : '') . '> ' . Util::h($row['display_name'] ?? $row[$labelKey]) . '</label>';
        }
        echo '</div></fieldset>';
    }

    private static function groupCheckboxTree(string $title, string $name, array $groups, array $selected, string $jsonName = ''): void
    {
        [$byId, $children] = self::groupTreeStructure($groups);
        $selectedIds = self::apiIntArray($selected);
        $selectedSet = array_flip($selectedIds);
        self::sortGroupTreeSelectedFirst($children, $byId, $selectedSet);
        $expanded = self::groupTreeExpandedAncestors($byId, array_keys($selectedSet));

        echo '<fieldset class="group-tree-field"><legend>' . Util::h($title) . '</legend>';
        if (!$groups) {
            echo '<div class="group-tree-empty">' . Util::h(self::t('filter.noGroups', 'Группы не найдены')) . '</div></fieldset>';
            return;
        }

        echo '<div class="group-tree-actions">';
        echo '<button type="button" data-group-tree-check-all>' . Util::h(self::t('groups.selectAll', 'Выбрать все')) . '</button>';
        echo '<button type="button" data-group-tree-clear-all>' . Util::h(self::t('groups.clearAll', 'Снять все')) . '</button>';
        echo '</div>';
        if ($jsonName !== '') {
            echo '<input type="hidden" name="' . Util::h($jsonName) . '" value="' . Util::h(json_encode($selectedIds)) . '" data-group-tree-json>';
        }
        echo '<div class="group-tree-list group-tree-checkbox-list" role="tree" aria-label="' . Util::h($title) . '">';
        self::renderGroupTreeNodes($byId, $children, $expanded, static function (array $group, int $depth, bool $hasChildren, bool $isExpanded, callable $renderToggle) use ($name, $jsonName, $selectedSet): void {
            $id = (int)$group['id'];
            $inputName = $jsonName === '' ? ' name="' . Util::h($name) . '"' : '';
            echo '<div class="group-tree-row" style="--depth: ' . (int)$depth . '">';
            $renderToggle();
            echo '<label class="group-tree-check" role="treeitem" aria-level="' . (int)($depth + 1) . '"><input type="checkbox"' . $inputName . ' value="' . $id . '" ' . (isset($selectedSet[$id]) ? 'checked' : '') . '> <span>' . Util::h((string)$group['name']) . '</span></label>';
            echo '</div>';
        });
        echo '</div></fieldset>';
    }

    private static function folderCheckboxTree(string $title, string $name, array $folders, array $selected, string $jsonName = ''): void
    {
        $groups = Repo::all('portal_groups', 'name ASC');
        [$byId, $children, $foldersByGroup] = self::folderTreeStructure($folders, $groups);
        $selectedIds = self::apiIntArray($selected);
        $selectedSet = array_flip($selectedIds);

        echo '<fieldset class="group-tree-field folder-tree-field"><legend>' . Util::h($title) . '</legend>';
        if (!$folders) {
            echo '<div class="group-tree-empty">' . Util::h(self::t('folders.empty', 'Папки не найдены')) . '</div></fieldset>';
            return;
        }

        echo '<div class="group-tree-actions">';
        echo '<button type="button" data-group-tree-check-all>' . Util::h(self::t('groups.selectAll', 'Выбрать все')) . '</button>';
        echo '<button type="button" data-group-tree-clear-all>' . Util::h(self::t('groups.clearAll', 'Снять все')) . '</button>';
        echo '</div>';
        echo '<input type="search" class="group-tree-search" placeholder="' . Util::h(self::t('filter.searchGroups', 'Поиск группы')) . '" data-group-tree-search autocomplete="off">';
        if ($jsonName !== '') {
            echo '<input type="hidden" name="' . Util::h($jsonName) . '" value="' . Util::h(json_encode($selectedIds)) . '" data-group-tree-json>';
        }
        echo '<div class="group-tree-list group-tree-checkbox-list" role="tree" aria-label="' . Util::h($title) . '">';

        $renderGroup = static function (int $groupId, int $depth) use (&$renderGroup, $byId, $children, $foldersByGroup, $name, $jsonName, $selectedSet): void {
            if (!isset($byId[$groupId])) {
                return;
            }
            $group = $byId[$groupId];
            $hasChildGroups = !empty($children[$groupId]);
            $hasFolders = !empty($foldersByGroup[$groupId]);
            if (!$hasChildGroups && !$hasFolders) {
                return;
            }

            echo '<div class="group-tree-node" data-group-tree-node>';
            echo '<div class="group-tree-row" style="--depth: ' . (int)$depth . '">';
            echo '<button type="button" class="group-tree-toggle" data-group-tree-toggle aria-expanded="false" aria-label="' . Util::h(sprintf(self::t('filter.expandGroup', 'Раскрыть группу %s'), (string)$group['name'])) . '" data-expand-label="' . Util::h(sprintf(self::t('filter.expandGroup', 'Раскрыть группу %s'), (string)$group['name'])) . '" data-collapse-label="' . Util::h(sprintf(self::t('filter.collapseGroup', 'Свернуть группу %s'), (string)$group['name'])) . '">+</button>';
            echo '<span class="group-tree-folder-header">' . Util::h((string)$group['name']) . '</span>';
            echo '</div>';
            echo '<div class="group-tree-children" data-group-tree-children hidden>';

            foreach ($foldersByGroup[$groupId] ?? [] as $folder) {
                $fid = (int)$folder['id'];
                $inputName = $jsonName === '' ? ' name="' . Util::h($name) . '"' : '';
                echo '<div class="group-tree-row" style="--depth: ' . (int)($depth + 1) . '">';
                echo '<span class="group-tree-spacer" aria-hidden="true"></span>';
                echo '<label class="group-tree-check" role="treeitem" aria-level="' . (int)($depth + 2) . '"><input type="checkbox"' . $inputName . ' value="' . $fid . '" ' . (isset($selectedSet[$fid]) ? 'checked' : '') . '> <span>' . Util::h((string)$folder['name']) . '</span></label>';
                echo '</div>';
            }

            foreach ($children[$groupId] ?? [] as $childId) {
                $renderGroup((int)$childId, $depth + 1);
            }
            echo '</div></div>';
        };

        foreach ($children[0] ?? [] as $rootId) {
            $renderGroup((int)$rootId, 0);
        }
        // Orphan folders whose group is missing are shown flat.
        $rootlessFolders = [];
        foreach ($folders as $folder) {
            if (!isset($byId[(int)$folder['group_id']])) {
                $rootlessFolders[] = $folder;
            }
        }
        foreach ($rootlessFolders as $folder) {
            $fid = (int)$folder['id'];
            $inputName = $jsonName === '' ? ' name="' . Util::h($name) . '"' : '';
            echo '<div class="group-tree-row" style="--depth: 0">';
            echo '<label class="group-tree-check" role="treeitem" aria-level="1"><input type="checkbox"' . $inputName . ' value="' . $fid . '" ' . (isset($selectedSet[$fid]) ? 'checked' : '') . '> <span>' . Util::h((string)$folder['name']) . '</span></label>';
            echo '</div>';
        }

        echo '</div></fieldset>';
    }

    private static function assignmentPicker(string $title, string $name, array $rows, array $selected, string $labelKey, string $searchPlaceholder): void
    {
        $selectedSet = array_flip(array_map('intval', $selected));
        $ordered = $rows;
        usort($ordered, static function (array $left, array $right) use ($selectedSet, $labelKey): int {
            $leftSelected = isset($selectedSet[(int)$left['id']]) ? 0 : 1;
            $rightSelected = isset($selectedSet[(int)$right['id']]) ? 0 : 1;
            if ($leftSelected !== $rightSelected) {
                return $leftSelected <=> $rightSelected;
            }

            return strnatcasecmp((string)$left[$labelKey], (string)$right[$labelKey]);
        });

        $selectedCount = 0;
        foreach ($rows as $row) {
            if (isset($selectedSet[(int)$row['id']])) {
                $selectedCount++;
            }
        }

        echo '<fieldset class="assignment-picker" data-assignment-picker><legend>' . Util::h($title) . '</legend>';
        echo '<div class="assignment-toolbar">';
        echo '<input type="search" class="assignment-search" placeholder="' . Util::h($searchPlaceholder) . '" autocomplete="off">';
        echo '<button type="button" class="assignment-selected-only" aria-pressed="false">' . self::t('assignment.selectedOnly', 'Только выбранные') . '</button>';
        echo '<span class="assignment-count" data-total="' . count($rows) . '">' . self::t('js.selectedCount', 'Выбрано') . ': ' . $selectedCount . ' / ' . count($rows) . '</span>';
        echo '</div>';
        echo '<div class="assignment-list">';
        foreach ($ordered as $row) {
            $checked = isset($selectedSet[(int)$row['id']]);
            echo '<label class="assignment-row"><input type="checkbox" name="' . Util::h($name) . '" value="' . (int)$row['id'] . '" ' . ($checked ? 'checked' : '') . '><span>' . Util::h($row[$labelKey]) . '</span></label>';
        }
        echo '<div class="assignment-empty" hidden>' . self::t('assignment.empty', 'Ничего не найдено') . '</div>';
        echo '</div></fieldset>';
    }

    private static function favoriteButton(int $cameraId, bool $isFavorite): void
    {
        echo '<form method="post" action="/favorite/toggle" class="favorite-form">' . Csrf::field();
        echo '<input type="hidden" name="camera_id" value="' . $cameraId . '">';
        $label = self::t('filter.favorites', 'Избранное');
        echo '<button title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '" class="' . ($isFavorite ? 'favorite active' : 'favorite') . '">' . ($isFavorite ? '★' : '☆') . '</button></form>';
    }

    private static function cameraSettingsButton(int $cameraId): void
    {
        $back = self::safeLocalPath((string)($_SERVER['REQUEST_URI'] ?? ''));
        if ($back === '') {
            $back = '/';
        }
        $href = '/admin/cameras?' . http_build_query(['edit' => $cameraId, 'back' => $back]);
        $label = self::t('settings.title', 'Настройки');
        echo '<a class="camera-settings" href="' . Util::h($href) . '" title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '">' . self::icon('settings') . '<span class="sr-only">' . Util::h($label) . '</span></a>';
    }

    private static function cameraRenameButton(int $cameraId): void
    {
        $back = self::safeLocalPath((string)($_SERVER['REQUEST_URI'] ?? ''));
        if ($back === '') {
            $back = '/';
        }
        $href = '/camera/rename?' . http_build_query(['id' => $cameraId, 'back' => $back]);
        $label = self::t('action.edit', 'Изменить');
        echo '<a class="camera-rename" href="' . Util::h($href) . '" title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '">' . self::icon('edit') . '<span class="sr-only">' . Util::h($label) . '</span></a>';
    }

    private static function notice(string $message, string $class = ''): void
    {
        if ($message !== '') {
            $classes = trim('alert ' . $class);
            echo '<div class="' . Util::h($classes) . '">' . Util::h($message) . '</div>';
        }
    }
}
