<?php

declare(strict_types=1);

namespace SesamePortal;

trait MapPages
{
    private static function googleMapSession(): void
    {
        Auth::requireLogin();
        $settings = PortalSettings::mapConfiguration();
        if ($settings['provider'] !== 'google' || $settings['googleApiKey'] === '') {
            self::apiError(409, 'map_provider_not_configured', 'Google Maps is not configured');
        }

        try {
            self::apiJson(MapTilesService::googleSessionPayload(
                (string)$settings['googleApiKey'],
                I18n::htmlLocale(),
                self::googleMapRegion()
            ));
        } catch (\Throwable $error) {
            error_log('SesamePortal Google Maps session failed: ' . $error->getMessage());
            self::apiError(502, 'map_provider_unavailable', 'Google Maps is temporarily unavailable');
        }
    }

    private static function googleMapAttribution(): void
    {
        Auth::requireLogin();
        $settings = PortalSettings::mapConfiguration();
        if ($settings['provider'] !== 'google' || $settings['googleApiKey'] === '') {
            self::apiError(409, 'map_provider_not_configured', 'Google Maps is not configured');
        }

        $north = self::coordinateFromInput($_GET['north'] ?? null, -90.0, 90.0);
        $south = self::coordinateFromInput($_GET['south'] ?? null, -90.0, 90.0);
        $east = self::coordinateFromInput($_GET['east'] ?? null, -180.0, 180.0);
        $west = self::coordinateFromInput($_GET['west'] ?? null, -180.0, 180.0);
        $zoom = filter_var($_GET['zoom'] ?? null, FILTER_VALIDATE_INT);
        if ($north === null || $south === null || $east === null || $west === null
            || $north < $south || $zoom === false || $zoom < 0 || $zoom > 22) {
            self::apiError(400, 'invalid_viewport', 'Invalid map viewport');
        }

        try {
            $copyright = MapTilesService::googleAttribution(
                (string)$settings['googleApiKey'],
                I18n::htmlLocale(),
                self::googleMapRegion(),
                $north,
                $south,
                $east,
                $west,
                $zoom
            );
            self::apiJson(['copyright' => $copyright]);
        } catch (\Throwable $error) {
            error_log('SesamePortal Google Maps attribution failed: ' . $error->getMessage());
            self::apiError(502, 'map_provider_unavailable', 'Google Maps attribution is temporarily unavailable');
        }
    }

    private static function googleMapRegion(): string
    {
        return match (I18n::locale()) {
            'ru' => 'RU',
            'de' => 'DE',
            'fr' => 'FR',
            'es' => 'ES',
            'it' => 'IT',
            'pt' => 'PT',
            'bg' => 'BG',
            'pl' => 'PL',
            'zh' => 'CN',
            'ja' => 'JP',
            'ko' => 'KR',
            'ar' => 'AE',
            'hy' => 'AM',
            default => 'US',
        };
    }

    private static function map(array $cameras, array $favorites): void
    {
        echo '<section class="panel map-panel"><div id="map" class="map"></div></section>';
        self::renderMapProviderConfig();
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

    private static function renderMapProviderConfig(): void
    {
        $settings = PortalSettings::mapConfiguration();
        $provider = PortalSettings::normalizeMapProvider((string)$settings['provider']);
        $config = [
            'provider' => $provider,
            'defaultCenter' => [
                'lat' => (float)$settings['latitude'],
                'lng' => (float)$settings['longitude'],
            ],
            'defaultZoom' => (int)$settings['zoom'],
        ];

        if ($provider === 'yandex' && $settings['yandexApiKey'] !== '') {
            $language = I18n::locale() === 'ru' ? 'ru_RU' : 'en_US';
            $config['tileUrl'] = 'https://tiles.api-maps.yandex.ru/v1/tiles/?apikey='
                . rawurlencode((string)$settings['yandexApiKey'])
                . '&lang=' . rawurlencode($language)
                . '&x={x}&y={y}&z={z}&l=map&projection=web_mercator&maptype=future_map';
            $config['maxZoom'] = 20;
            $config['logoUrl'] = I18n::locale() === 'ru'
                ? self::assetUrl('/assets/yandex-map-logo-ru.png')
                : self::assetUrl('/assets/yandex-map-logo-en.png');
            $config['mapsUrl'] = I18n::locale() === 'ru'
                ? 'https://yandex.ru/maps/'
                : 'https://yandex.com/maps/';
        } elseif ($provider === 'google' && $settings['googleApiKey'] !== '') {
            $config['sessionUrl'] = '/viewer/map/google-session';
            $config['attributionUrl'] = '/viewer/map/google-attribution';
        }

        echo '<script>window.SESAME_MAP_CONFIG = ' . json_encode(
            $config,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) . ';</script>';
    }
}
