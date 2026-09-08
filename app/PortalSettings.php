<?php

declare(strict_types=1);

namespace SesamePortal;

final class PortalSettings
{
    public const DEFAULT_MOSAIC_PREVIEW_REFRESH = '30';
    public const MOSAIC_PREVIEW_REFRESH_OPTIONS = ['off', '10', '30', '60', '300'];
    public const DEFAULT_MAP_LATITUDE = 25.2048;
    public const DEFAULT_MAP_LONGITUDE = 55.2708;
    public const DEFAULT_MAP_ZOOM = 10;
    public const MIN_MAP_ZOOM = 0;
    public const MAX_MAP_ZOOM = 19;
    public const DEFAULT_MAP_PROVIDER = 'osm';
    public const MAP_PROVIDERS = ['osm', 'yandex', 'google'];

    private const MOSAIC_PREVIEW_REFRESH_KEY = 'mosaic_preview_refresh';
    private const MAP_LATITUDE_KEY = 'map_default_latitude';
    private const MAP_LONGITUDE_KEY = 'map_default_longitude';
    private const MAP_ZOOM_KEY = 'map_default_zoom';
    private const MAP_PROVIDER_KEY = 'map_provider';
    private const MAP_YANDEX_API_KEY = 'map_yandex_api_key_enc';
    private const MAP_GOOGLE_API_KEY = 'map_google_api_key_enc';

    public static function mosaicPreviewRefresh(?array $user = null): string
    {
        $personalRefresh = $user['mosaic_preview_refresh'] ?? null;
        if (in_array($personalRefresh, self::MOSAIC_PREVIEW_REFRESH_OPTIONS, true)) {
            return $personalRefresh;
        }
        $values = self::values([self::MOSAIC_PREVIEW_REFRESH_KEY]);
        $refresh = $values[self::MOSAIC_PREVIEW_REFRESH_KEY] ?? '';
        return in_array($refresh, self::MOSAIC_PREVIEW_REFRESH_OPTIONS, true)
            ? $refresh
            : self::DEFAULT_MOSAIC_PREVIEW_REFRESH;
    }

    public static function setMosaicPreviewRefresh(string $refresh): void
    {
        if (!in_array($refresh, self::MOSAIC_PREVIEW_REFRESH_OPTIONS, true)) {
            throw new \InvalidArgumentException('Invalid mosaic preview refresh interval');
        }
        self::storeValues([self::MOSAIC_PREVIEW_REFRESH_KEY => $refresh]);
    }

    public static function mapCenter(): array
    {
        $settings = self::mapConfiguration();

        return [
            'latitude' => $settings['latitude'],
            'longitude' => $settings['longitude'],
        ];
    }

    public static function mapConfiguration(): array
    {
        $values = self::values([
            self::MAP_LATITUDE_KEY,
            self::MAP_LONGITUDE_KEY,
            self::MAP_ZOOM_KEY,
            self::MAP_PROVIDER_KEY,
            self::MAP_YANDEX_API_KEY,
            self::MAP_GOOGLE_API_KEY,
        ]);

        return [
            'latitude' => self::storedCoordinate(
                $values[self::MAP_LATITUDE_KEY] ?? null,
                -90.0,
                90.0,
                self::DEFAULT_MAP_LATITUDE
            ),
            'longitude' => self::storedCoordinate(
                $values[self::MAP_LONGITUDE_KEY] ?? null,
                -180.0,
                180.0,
                self::DEFAULT_MAP_LONGITUDE
            ),
            'zoom' => self::storedInteger(
                $values[self::MAP_ZOOM_KEY] ?? null,
                self::MIN_MAP_ZOOM,
                self::MAX_MAP_ZOOM,
                self::DEFAULT_MAP_ZOOM
            ),
            'provider' => self::normalizeMapProvider($values[self::MAP_PROVIDER_KEY] ?? null),
            'yandexApiKey' => Crypto::decrypt($values[self::MAP_YANDEX_API_KEY] ?? null),
            'googleApiKey' => Crypto::decrypt($values[self::MAP_GOOGLE_API_KEY] ?? null),
        ];
    }

    public static function setMapConfiguration(
        float $latitude,
        float $longitude,
        int $zoom,
        string $provider,
        ?string $yandexApiKey = null,
        ?string $googleApiKey = null
    ): void
    {
        if (!is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw new \InvalidArgumentException('Invalid map latitude');
        }
        if (!is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw new \InvalidArgumentException('Invalid map longitude');
        }
        if ($zoom < self::MIN_MAP_ZOOM || $zoom > self::MAX_MAP_ZOOM) {
            throw new \InvalidArgumentException('Invalid map zoom');
        }

        $provider = self::normalizeMapProvider($provider, false);
        $current = self::mapConfiguration();
        $nextYandexApiKey = self::replacementSecret($yandexApiKey, (string)$current['yandexApiKey']);
        $nextGoogleApiKey = self::replacementSecret($googleApiKey, (string)$current['googleApiKey']);

        if ($provider === 'yandex' && $nextYandexApiKey === '') {
            throw new \InvalidArgumentException('Yandex Maps API key is required');
        }
        if ($provider === 'google' && $nextGoogleApiKey === '') {
            throw new \InvalidArgumentException('Google Maps API key is required');
        }

        $settings = [
            self::MAP_LATITUDE_KEY => self::formatCoordinate($latitude),
            self::MAP_LONGITUDE_KEY => self::formatCoordinate($longitude),
            self::MAP_ZOOM_KEY => (string)$zoom,
            self::MAP_PROVIDER_KEY => $provider,
        ];
        if ($yandexApiKey !== null && trim($yandexApiKey) !== '') {
            $settings[self::MAP_YANDEX_API_KEY] = (string)Crypto::encrypt(trim($yandexApiKey));
        }
        if ($googleApiKey !== null && trim($googleApiKey) !== '') {
            $settings[self::MAP_GOOGLE_API_KEY] = (string)Crypto::encrypt(trim($googleApiKey));
        }

        self::storeValues($settings);
    }

    public static function setMapCenter(float $latitude, float $longitude): void
    {
        $current = self::mapConfiguration();
        self::setMapConfiguration(
            $latitude,
            $longitude,
            (int)$current['zoom'],
            (string)$current['provider']
        );
    }

    public static function normalizeMapProvider(?string $provider, bool $fallback = true): string
    {
        $provider = strtolower(trim((string)$provider));
        if (in_array($provider, self::MAP_PROVIDERS, true)) {
            return $provider;
        }
        if ($fallback) {
            return self::DEFAULT_MAP_PROVIDER;
        }
        throw new \InvalidArgumentException('Invalid map provider');
    }

    private static function values(array $keys): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT setting_key, setting_value FROM portal_settings WHERE setting_key IN ('
            . implode(',', array_fill(0, count($keys), '?')) . ')'
        );
        $stmt->execute($keys);

        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $values[(string)$row['setting_key']] = (string)$row['setting_value'];
        }
        return $values;
    }

    private static function replacementSecret(?string $replacement, string $current): string
    {
        $replacement = $replacement === null ? '' : trim($replacement);
        return $replacement !== '' ? $replacement : $current;
    }

    private static function storeValues(array $settings): void
    {
        if ($settings === []) {
            return;
        }

        $pdo = DB::pdo();
        $sql = match (DB::driver()) {
            'mysql' => 'INSERT INTO portal_settings(setting_key, setting_value, updated_at) VALUES(?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=VALUES(updated_at)',
            default => 'INSERT INTO portal_settings(setting_key, setting_value, updated_at) VALUES(?, ?, ?)
                ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value, updated_at=excluded.updated_at',
        };
        $stmt = $pdo->prepare($sql);
        $now = Util::now();

        $pdo->beginTransaction();
        try {
            foreach ($settings as $key => $value) {
                $stmt->execute([(string)$key, (string)$value, $now]);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    public static function formatCoordinate(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');
        return $formatted === '-0' ? '0' : $formatted;
    }

    private static function storedCoordinate(?string $value, float $min, float $max, float $fallback): float
    {
        if ($value === null || !is_numeric($value)) {
            return $fallback;
        }

        $coordinate = (float)$value;
        return is_finite($coordinate) && $coordinate >= $min && $coordinate <= $max
            ? $coordinate
            : $fallback;
    }

    private static function storedInteger(?string $value, int $min, int $max, int $fallback): int
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $fallback;
        }

        $integer = (int)$value;
        return $integer >= $min && $integer <= $max ? $integer : $fallback;
    }
}
