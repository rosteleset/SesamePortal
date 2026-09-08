<?php

declare(strict_types=1);

namespace SesamePortal;

trait SettingsPages
{
    private static function settings(): void
    {
        Auth::requireAdmin();
        $message = '';
        $messageClass = '';
        $updateResult = null;
        $forceCheck = false;
        $mapSettings = PortalSettings::mapConfiguration();
        $previewRefresh = PortalSettings::mosaicPreviewRefresh();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            if ($action === 'check_update') {
                $forceCheck = true;
            } elseif ($action === 'run_update') {
                $updateResult = PortalUpdateService::run();
                $forceCheck = !empty($updateResult['ok']);
                $message = !empty($updateResult['ok'])
                    ? self::t('settings.updateDone', 'Обновление Portal выполнено')
                    : self::t('settings.updateFailed', 'Обновление Portal не выполнено');
                $messageClass = !empty($updateResult['ok']) ? 'success' : 'danger';
            } elseif ($action === 'save_mosaic_settings') {
                $refreshInput = Util::post('mosaic_preview_refresh');
                try {
                    PortalSettings::setMosaicPreviewRefresh(is_string($refreshInput) ? $refreshInput : '');
                    $previewRefresh = PortalSettings::mosaicPreviewRefresh();
                    $message = self::t('settings.mosaicSaved', 'Настройки мозаики сохранены');
                    $messageClass = 'success';
                    Audit::log('settings.mosaic.save', 'preview_refresh=' . $previewRefresh . ' ip=' . Audit::clientIp());
                } catch (\InvalidArgumentException) {
                    $message = self::t('settings.previewRefreshInvalid', 'Выберите допустимый интервал обновления превью.');
                    $messageClass = 'danger';
                }
            } elseif ($action === 'save_map_settings' || $action === 'save_map_center') {
                $latitudeInput = trim((string)Util::post('map_default_latitude'));
                $longitudeInput = trim((string)Util::post('map_default_longitude'));
                $zoomInput = $action === 'save_map_settings'
                    ? trim((string)Util::post('map_default_zoom'))
                    : (string)$mapSettings['zoom'];
                if ($zoomInput === '') {
                    $zoomInput = (string)$mapSettings['zoom'];
                }
                $latitude = self::coordinateFromInput($latitudeInput, -90.0, 90.0);
                $longitude = self::coordinateFromInput($longitudeInput, -180.0, 180.0);
                $zoom = filter_var($zoomInput, FILTER_VALIDATE_INT);
                $zoomValid = $zoom !== false
                    && $zoom >= PortalSettings::MIN_MAP_ZOOM
                    && $zoom <= PortalSettings::MAX_MAP_ZOOM;
                $providerInput = $action === 'save_map_settings'
                    ? trim((string)Util::post('map_provider'))
                    : (string)$mapSettings['provider'];
                try {
                    $provider = PortalSettings::normalizeMapProvider($providerInput, false);
                } catch (\InvalidArgumentException) {
                    $provider = null;
                }
                if ($latitude === null || $longitude === null) {
                    $message = self::t(
                        'settings.mapInvalid',
                        'Укажите широту от -90 до 90 и долготу от -180 до 180.'
                    );
                    $messageClass = 'danger';
                    $mapSettings['latitude'] = $latitudeInput;
                    $mapSettings['longitude'] = $longitudeInput;
                    $mapSettings['zoom'] = $zoomValid ? $zoom : $zoomInput;
                    $mapSettings['provider'] = $provider ?? PortalSettings::DEFAULT_MAP_PROVIDER;
                } elseif (!$zoomValid) {
                    $message = self::t('settings.mapZoomInvalid', 'Укажите масштаб от 0 до 19.');
                    $messageClass = 'danger';
                    $mapSettings['latitude'] = $latitude;
                    $mapSettings['longitude'] = $longitude;
                    $mapSettings['zoom'] = $zoomInput;
                    $mapSettings['provider'] = $provider ?? PortalSettings::DEFAULT_MAP_PROVIDER;
                } elseif ($provider === null) {
                    $message = self::t('settings.mapProviderInvalid', 'Выберите поддерживаемого провайдера карт.');
                    $messageClass = 'danger';
                } else {
                    $yandexApiKey = $action === 'save_map_settings'
                        ? trim((string)Util::post('map_yandex_api_key'))
                        : null;
                    $googleApiKey = $action === 'save_map_settings'
                        ? trim((string)Util::post('map_google_api_key'))
                        : null;
                    try {
                        PortalSettings::setMapConfiguration(
                            $latitude,
                            $longitude,
                            $zoom,
                            $provider,
                            $yandexApiKey,
                            $googleApiKey
                        );
                        $mapSettings = PortalSettings::mapConfiguration();
                        $message = self::t('settings.mapSaved', 'Настройки карты сохранены');
                        $messageClass = 'success';
                        Audit::log(
                            'settings.map.save',
                            'provider=' . $provider
                            . ' latitude=' . PortalSettings::formatCoordinate($latitude)
                            . ' longitude=' . PortalSettings::formatCoordinate($longitude)
                            . ' zoom=' . $zoom
                            . ' yandex_key=' . ($mapSettings['yandexApiKey'] !== '' ? 'configured' : 'missing')
                            . ' google_key=' . ($mapSettings['googleApiKey'] !== '' ? 'configured' : 'missing')
                            . ' ip=' . Audit::clientIp()
                        );
                    } catch (\InvalidArgumentException) {
                        $mapSettings['latitude'] = $latitude;
                        $mapSettings['longitude'] = $longitude;
                        $mapSettings['provider'] = $provider;
                        $message = sprintf(
                            self::t(
                                'settings.mapProviderKeyRequired',
                                'Для провайдера %s необходимо указать API key.'
                            ),
                            $provider === 'yandex' ? 'Yandex Maps' : 'Google Maps'
                        );
                        $messageClass = 'danger';
                    }
                }
            }
        }

        $status = PortalUpdateService::status($forceCheck, true);
        if ($forceCheck && $updateResult === null) {
            $message = empty($status['checkError'])
                ? self::t('settings.checkDone', 'Проверка обновлений выполнена')
                : self::t('settings.checkFailed', 'Проверка обновлений не выполнена');
            $messageClass = empty($status['checkError']) ? 'success' : 'danger';
        }

        self::layout(self::t('settings.title', 'Настройки'), function () use ($message, $messageClass, $status, $updateResult, $mapSettings, $previewRefresh) {
            self::notice($message, $messageClass);
            self::portalMosaicSettingsPanel($previewRefresh);
            self::portalMapSettingsPanel($mapSettings);
            self::portalUpdatePanel($status, $updateResult);
        });
    }

    private static function personalSettings(): void
    {
        $user = Auth::requireLogin();
        $previewRefresh = $user['mosaic_preview_refresh'] ?? 'default';
        if (!in_array($previewRefresh, PortalSettings::MOSAIC_PREVIEW_REFRESH_OPTIONS, true)) {
            $previewRefresh = 'default';
        }
        $message = '';
        $messageClass = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (Util::post('action') !== 'save_mosaic_settings') {
                http_response_code(400);
                return;
            }
            $refreshInput = Util::post('mosaic_preview_refresh');
            if ($refreshInput === 'default' || in_array($refreshInput, PortalSettings::MOSAIC_PREVIEW_REFRESH_OPTIONS, true)) {
                DB::pdo()->prepare('UPDATE users SET mosaic_preview_refresh = ? WHERE id = ?')
                    ->execute([$refreshInput === 'default' ? null : $refreshInput, (int)$user['id']]);
                $previewRefresh = $refreshInput;
                $message = self::t('settings.mosaicSaved', 'Настройки мозаики сохранены');
                $messageClass = 'success';
                Audit::log('user.preferences.save', 'preview_refresh=' . $previewRefresh . ' ip=' . Audit::clientIp());
            } else {
                $message = self::t('settings.previewRefreshInvalid', 'Выберите допустимый интервал обновления превью.');
                $messageClass = 'danger';
            }
        }
        self::layout(self::t('settings.personalTitle', 'Личные настройки'), function () use ($previewRefresh, $message, $messageClass): void {
            self::notice($message, $messageClass);
            self::portalMosaicSettingsPanel($previewRefresh, true);
        });
    }

    private static function portalMosaicSettingsPanel(string $previewRefresh, bool $personal = false): void
    {
        echo '<section class="panel portal-mosaic-settings-panel"><h2>' . self::t('nav.mosaic', 'Мозаика') . '</h2>';
        echo '<form method="post" action="' . ($personal ? '/settings' : '/admin/settings') . '" class="form portal-mosaic-settings-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="save_mosaic_settings">';
        self::previewRefreshSelect($previewRefresh, $personal);
        echo '<button type="submit" class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
    }

    private static function portalMapSettingsPanel(array $mapSettings): void
    {
        $provider = PortalSettings::normalizeMapProvider((string)($mapSettings['provider'] ?? ''));
        $yandexConfigured = trim((string)($mapSettings['yandexApiKey'] ?? '')) !== '';
        $googleConfigured = trim((string)($mapSettings['googleApiKey'] ?? '')) !== '';
        $keyPlaceholder = self::t(
            'settings.mapApiKeyPlaceholder',
            'Оставьте пустым, чтобы сохранить текущий ключ'
        );
        echo '<section class="panel portal-map-settings-panel">';
        echo '<div class="section-head"><div><h2>' . self::t('settings.mapTitle', 'Настройки карты') . '</h2>';
        echo '<p class="muted">' . Util::h(self::t(
            'settings.mapProviderHint',
            'Провайдер используется на карте камер и в редакторе положения камеры.'
        )) . '</p></div></div>';
        echo '<form method="post" action="/admin/settings" class="form portal-map-settings-form" data-map-provider-settings>' . Csrf::field();
        echo '<input type="hidden" name="action" value="save_map_settings">';
        echo '<label>' . self::t('settings.mapProvider', 'Провайдер карт') . '<select name="map_provider" data-map-provider-select>';
        foreach ([
            'osm' => 'OpenStreetMap (OSM)',
            'yandex' => 'Yandex Maps',
            'google' => 'Google Maps',
        ] as $value => $label) {
            echo '<option value="' . $value . '" ' . ($provider === $value ? 'selected' : '') . '>' . $label . '</option>';
        }
        echo '</select></label>';
        echo '<div class="map-provider-key" data-map-provider-key="yandex"' . ($provider === 'yandex' ? '' : ' hidden') . '>';
        echo '<label>' . self::t('settings.mapYandexApiKey', 'API key Yandex Tiles')
            . '<input type="password" name="map_yandex_api_key" autocomplete="new-password" value="" placeholder="'
            . Util::h($yandexConfigured ? $keyPlaceholder : '') . '"></label>';
        if ($yandexConfigured) {
            echo '<span class="field-hint success-text">' . self::t('settings.mapApiKeyConfigured', 'API key настроен') . '</span>';
        }
        echo '<a class="field-hint" href="https://yandex.com/maps-api/docs/tiles-api/quickstart.html" target="_blank" rel="noopener">Yandex Tiles API</a></div>';
        echo '<div class="map-provider-key" data-map-provider-key="google"' . ($provider === 'google' ? '' : ' hidden') . '>';
        echo '<label>' . self::t('settings.mapGoogleApiKey', 'API key Google Map Tiles')
            . '<input type="password" name="map_google_api_key" autocomplete="new-password" value="" placeholder="'
            . Util::h($googleConfigured ? $keyPlaceholder : '') . '"></label>';
        if ($googleConfigured) {
            echo '<span class="field-hint success-text">' . self::t('settings.mapApiKeyConfigured', 'API key настроен') . '</span>';
        }
        echo '<a class="field-hint" href="https://developers.google.com/maps/documentation/tile/get-api-key" target="_blank" rel="noopener">Google Map Tiles API</a></div>';
        echo '<p class="muted map-center-hint">' . Util::h(self::t(
            'settings.mapCenterHint',
            'Эти координаты используются как центр карты при добавлении камеры без заданного положения.'
        )) . '</p>';
        echo '<div class="form-row portal-map-center-fields">';
        echo '<label>' . self::t('settings.mapLatitude', 'Начальная широта')
            . '<input name="map_default_latitude" type="number" inputmode="decimal" min="-90" max="90" step="any" required value="'
            . Util::h($mapSettings['latitude'] ?? PortalSettings::DEFAULT_MAP_LATITUDE) . '"></label>';
        echo '<label>' . self::t('settings.mapLongitude', 'Начальная долгота')
            . '<input name="map_default_longitude" type="number" inputmode="decimal" min="-180" max="180" step="any" required value="'
            . Util::h($mapSettings['longitude'] ?? PortalSettings::DEFAULT_MAP_LONGITUDE) . '"></label>';
        echo '<label>' . self::t('settings.mapZoom', 'Начальный масштаб')
            . '<input name="map_default_zoom" type="number" inputmode="numeric" min="'
            . PortalSettings::MIN_MAP_ZOOM . '" max="' . PortalSettings::MAX_MAP_ZOOM
            . '" step="1" required value="'
            . Util::h($mapSettings['zoom'] ?? PortalSettings::DEFAULT_MAP_ZOOM) . '"></label>';
        echo '</div><div class="form-actions"><button class="primary" type="submit">'
            . self::t('settings.mapSave', 'Сохранить настройки карты') . '</button></div></form></section>';
    }

    private static function portalUpdatePanel(array $status, ?array $updateResult = null): void
    {
        $current = is_array($status['current'] ?? null) ? $status['current'] : [];
        $latest = is_array($status['latest'] ?? null) ? $status['latest'] : [];
        $badge = self::portalUpdateBadge($status);

        echo '<section class="panel portal-update-panel"><div class="section-head"><div><h2>' . self::t('settings.portalUpdates', 'Обновления Portal') . '</h2><p class="muted">' . Util::h(self::t('settings.updateHint', 'Portal сравнивает текущую сборку с последним commit выбранной ветки GitHub.')) . '</p></div>';
        echo '<span class="pill ' . Util::h($badge['class']) . '">' . Util::h($badge['text']) . '</span></div>';

        echo '<div class="portal-version-grid">';
        self::portalVersionCard(self::t('settings.currentVersion', 'Текущая версия'), $current);
        self::portalVersionCard(self::t('settings.githubVersion', 'Доступная версия на GitHub'), $latest);
        echo '</div>';

        echo '<dl class="portal-update-meta">';
        echo '<dt>' . self::t('settings.githubRepo', 'GitHub repository') . '</dt><dd><code>' . Util::h((string)($status['repo'] ?? '')) . '</code></dd>';
        echo '<dt>' . self::t('settings.githubRef', 'GitHub branch/ref') . '</dt><dd><code>' . Util::h((string)($status['ref'] ?? '')) . '</code></dd>';
        echo '<dt>' . self::t('settings.checkedAt', 'Проверено') . '</dt><dd>' . self::portalUpdateValue($status['checkedAt'] ?? null) . '</dd>';
        echo '<dt>' . self::t('settings.updateTool', 'Update tool') . '</dt><dd>' . ((bool)($status['toolInstalled'] ?? false) ? self::t('settings.toolInstalled', 'установлен') : self::t('settings.toolMissing', 'не установлен')) . '</dd>';
        if (!empty($status['checkError'])) {
            echo '<dt>' . self::t('settings.checkError', 'Ошибка проверки') . '</dt><dd class="danger-text">' . Util::h((string)$status['checkError']) . '</dd>';
        }
        echo '</dl>';

        echo '<div class="form-actions portal-update-actions">';
        self::smallPost('/admin/settings', ['action' => 'check_update'], self::t('settings.checkUpdates', 'Проверить обновления'));
        if ((bool)($status['enabled'] ?? false) && (bool)($status['toolInstalled'] ?? false) && (bool)($status['updateAvailable'] ?? false)) {
            self::smallPost(
                '/admin/settings',
                ['action' => 'run_update'],
                self::t('settings.installUpdate', 'Обновить Portal'),
                'primary',
                self::t('settings.updateConfirm', 'Обновить код Portal из GitHub и выполнить миграции?')
            );
        } else {
            $disabledReason = !(bool)($status['enabled'] ?? false)
                ? self::t('settings.updateDisabled', 'обновления отключены')
                : (!(bool)($status['toolInstalled'] ?? false)
                    ? self::t('settings.toolMissing', 'не установлен')
                    : self::t('settings.noUpdateAvailable', 'нет доступного обновления'));
            echo '<button type="button" disabled title="' . Util::h($disabledReason) . '">' . self::icon('download') . self::t('settings.installUpdate', 'Обновить Portal') . '</button>';
        }
        echo '</div>';

        if ($updateResult !== null) {
            $summary = !empty($updateResult['ok'])
                ? self::t('settings.updateOutputOk', 'Вывод updater')
                : self::t('settings.updateOutputFailed', 'Вывод updater с ошибкой');
            echo '<details class="technical-result" open><summary>' . Util::h($summary) . '</summary><pre>' . Util::h((string)($updateResult['output'] ?? '')) . '</pre></details>';
        }

        echo '</section>';
    }

    private static function portalUpdateBadge(array $status): array
    {
        if (!(bool)($status['enabled'] ?? false)) {
            return ['class' => 'warn', 'text' => self::t('settings.updateDisabled', 'обновления отключены')];
        }
        if (!empty($status['checkError']) && empty($status['latest'])) {
            return ['class' => 'danger', 'text' => self::t('settings.checkFailed', 'проверка не выполнена')];
        }
        if ((bool)($status['updateAvailable'] ?? false)) {
            return ['class' => 'warn', 'text' => self::t('settings.updateAvailable', 'доступно обновление')];
        }
        if ((bool)($status['upToDate'] ?? false)) {
            return ['class' => 'success', 'text' => self::t('settings.upToDate', 'актуально')];
        }
        return ['class' => 'info', 'text' => self::t('settings.notChecked', 'не проверено')];
    }

    private static function portalVersionCard(string $title, array $release): void
    {
        $version = self::portalUpdateVersionLabel($release);
        $commit = self::shortCommit((string)($release['sourceCommit'] ?? ''));
        $date = (string)($release['commitDate'] ?? $release['builtAt'] ?? '');
        echo '<div class="summary-card portal-version-card"><span>' . Util::h($title) . '</span><strong>' . Util::h($version) . '</strong>';
        if ($commit !== '') {
            echo '<code>' . Util::h($commit) . '</code>';
        }
        if ($date !== '') {
            echo '<small>' . Util::h($date) . '</small>';
        }
        if (!empty($release['message'])) {
            echo '<p>' . Util::h((string)$release['message']) . '</p>';
        }
        echo '</div>';
    }

    private static function portalUpdateVersionLabel(array $release): string
    {
        $version = trim((string)($release['version'] ?? ''));
        if ($version !== '') {
            return $version;
        }
        $commit = self::shortCommit((string)($release['sourceCommit'] ?? ''));
        return $commit !== '' ? $commit : self::t('settings.versionUnknown', 'неизвестно');
    }

    private static function portalUpdateValue(mixed $value): string
    {
        $text = trim((string)($value ?? ''));
        return $text !== '' ? Util::h($text) : '<span class="muted">' . self::t('settings.notChecked', 'не проверено') . '</span>';
    }

    private static function shortCommit(string $sha): string
    {
        $sha = trim($sha);
        return preg_match('/^[A-Fa-f0-9]{7,40}$/', $sha) ? substr($sha, 0, 12) : '';
    }

    private static function previewRefreshSelect(string $previewRefresh, bool $personal = false): void
    {
        $label = $personal
            ? self::t('viewer.previewRefresh', 'Обновление превью')
            : self::t('settings.previewRefreshDefault', 'Обновление превью по умолчанию');
        echo '<label><span>' . Util::h($label) . '</span><select name="mosaic_preview_refresh">';
        if ($personal) {
            $defaultRefresh = PortalSettings::mosaicPreviewRefresh();
            $defaultLabel = $defaultRefresh === 'off'
                ? self::t('viewer.refreshOff', 'Отключено')
                : sprintf(self::t('viewer.refreshSeconds', '%d сек.'), (int)$defaultRefresh);
            echo '<option value="default"' . ($previewRefresh === 'default' ? ' selected' : '') . '>'
                . Util::h(sprintf(self::t('settings.portalDefault', 'По умолчанию в Portal: %s'), $defaultLabel)) . '</option>';
        }
        foreach (PortalSettings::MOSAIC_PREVIEW_REFRESH_OPTIONS as $value) {
            $label = $value === 'off'
                ? self::t('viewer.refreshOff', 'Отключено')
                : sprintf(self::t('viewer.refreshSeconds', '%d сек.'), (int)$value);
            echo '<option value="' . Util::h($value) . '"' . ($previewRefresh === $value ? ' selected' : '') . '>' . Util::h($label) . '</option>';
        }
        echo '</select></label>';
    }

    private static function portalUpdateBanner(): void
    {
        $status = PortalUpdateService::cachedStatus();
        if (empty($status['updateAvailable'])) {
            return;
        }

        $current = is_array($status['current'] ?? null) ? $status['current'] : [];
        $latest = is_array($status['latest'] ?? null) ? $status['latest'] : [];
        echo '<section class="portal-update-banner">';
        echo '<div><strong>' . Util::h(self::t('settings.updateAvailable', 'Доступно обновление')) . '</strong>';
        echo '<span>' . Util::h(self::t('settings.currentVersion', 'Текущая версия')) . ': ' . Util::h(self::portalUpdateVersionLabel($current)) . ' · ';
        echo Util::h(self::t('settings.githubVersion', 'Доступная версия на GitHub')) . ': ' . Util::h(self::portalUpdateVersionLabel($latest)) . '</span></div>';
        echo '<div class="portal-update-banner-actions">';
        if ((bool)($status['toolInstalled'] ?? false)) {
            self::smallPost(
                '/admin/settings',
                ['action' => 'run_update'],
                self::t('settings.installUpdate', 'Обновить Portal'),
                'primary',
                self::t('settings.updateConfirm', 'Обновить код Portal из GitHub и выполнить миграции?')
            );
        }
        echo '<a class="btn" href="/admin/settings">' . self::t('nav.settings', 'Настройки') . '</a></div>';
        echo '</section>';
    }
}
