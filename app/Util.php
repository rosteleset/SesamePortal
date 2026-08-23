<?php

declare(strict_types=1);

namespace SesamePortal;

final class Util
{
    public const DVR_STREAM_NAME_HTML_PATTERN = '[A-Za-z0-9][A-Za-z0-9._-]*';
    public const DVR_STREAM_NAME_MAX_BYTES = 128;

    public static function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function now(): string
    {
        return gmdate('c');
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function mapProvider(): string
    {
        $provider = (string)DB::setting('map_provider', (string)Config::get('map_provider', 'openstreetmap'));
        $allowed = ['openstreetmap', 'yandex'];
        return in_array($provider, $allowed, true) ? $provider : 'openstreetmap';
    }

    public static function mapTileUrl(string $provider): string
    {
        return match ($provider) {
            'yandex' => 'https://core-renderer-tiles.maps.yandex.net/tiles?l=map&x={x}&y={y}&z={z}&lang=ru_RU',
            default => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        };
    }

    public static function mapAttribution(string $provider): string
    {
        return match ($provider) {
            'yandex' => '&copy; <a href="https://yandex.ru/maps/">Яндекс Карты</a>',
            default => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        };
    }

    public static function mapDefaultView(): array
    {
        $default = ['lat' => 47.242057, 'lng' => 38.889615];
        $lat = (string)DB::setting('map_default_lat', '');
        if ($lat === '') {
            $lat = Config::get('map_default_lat', $default['lat']);
        }
        $lng = (string)DB::setting('map_default_lng', '');
        if ($lng === '') {
            $lng = Config::get('map_default_lng', $default['lng']);
        }
        return [
            'lat' => is_numeric($lat) ? (float)$lat : $default['lat'],
            'lng' => is_numeric($lng) ? (float)$lng : $default['lng'],
        ];
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return $path ?: '/';
    }

    public static function post(string $key, mixed $default = ''): mixed
    {
        return $_POST[$key] ?? $default;
    }

    public static function checkbox(string $key): int
    {
        return isset($_POST[$key]) ? 1 : 0;
    }

    public static function isDvrStreamName(string $name): bool
    {
        $name = trim($name);
        return $name !== ''
            && strlen($name) <= self::DVR_STREAM_NAME_MAX_BYTES
            && preg_match('/^' . self::DVR_STREAM_NAME_HTML_PATTERN . '$/', $name) === 1;
    }

    public static function dvrStreamSlug(string $value): string
    {
        $source = trim($value);
        $ascii = self::asciiTransliterate($source);
        $slug = strtolower($ascii);
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug);
        $slug = preg_replace('/[._-]*-[._-]*/', '-', (string)$slug);
        $slug = trim((string)$slug, '-_.');

        if ($slug === '') {
            $slug = 'camera-' . substr(hash('sha256', $source), 0, 12);
        }

        if (strlen($slug) > self::DVR_STREAM_NAME_MAX_BYTES) {
            $slug = trim(substr($slug, 0, self::DVR_STREAM_NAME_MAX_BYTES), '-_.');
        }

        return $slug !== '' ? $slug : 'camera-' . substr(hash('sha256', $source), 0, 12);
    }

    private static function asciiTransliterate(string $value): string
    {
        $value = strtr($value, [
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'E',
            'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
            'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
            'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch',
            'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ]);

        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
            if (is_string($transliterated) && $transliterated !== '') {
                $value = $transliterated;
            }
        } elseif (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($transliterated) && $transliterated !== '') {
                $value = $transliterated;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    }
}
