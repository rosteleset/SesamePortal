<?php

declare(strict_types=1);

namespace SesamePortal;

final class Config
{
    private static ?array $config = null;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function reset(): void
    {
        self::$config = null;
    }

    public static function stateDir(): string
    {
        return getenv('SESAME_PORTAL_STATE_DIR') ?: self::root() . '/var';
    }

    public static function all(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $stateDir = self::stateDir();
        $file = getenv('SESAME_PORTAL_CONFIG') ?: $stateDir . '/config.php';
        $loaded = is_file($file) ? require $file : [];

        $config = array_replace([
            'state_dir' => $stateDir,
            'db_path' => $stateDir . '/portal.sqlite',
            'db_dsn' => getenv('SESAME_PORTAL_DB_DSN') ?: null,
            'db_user' => getenv('SESAME_PORTAL_DB_USER') ?: null,
            'db_password' => getenv('SESAME_PORTAL_DB_PASSWORD') ?: null,
            'app_secret' => getenv('SESAME_PORTAL_SECRET') ?: 'dev-insecure-change-me',
            'timezone' => getenv('SESAME_PORTAL_TIMEZONE') ?: 'UTC',
            'locale' => getenv('SESAME_PORTAL_LOCALE') ?: 'ru',
            'base_url' => getenv('SESAME_PORTAL_BASE_URL') ?: '',
            'auth_backend_path' => '/api/sesamedvr/auth',
            'portal_update_enabled' => getenv('SESAME_PORTAL_UPDATE_ENABLED') !== '0',
            'portal_update_github_repo' => getenv('SESAME_PORTAL_UPDATE_GITHUB_REPO') ?: 'rosteleset/SesamePortal',
            'portal_update_github_ref' => getenv('SESAME_PORTAL_UPDATE_GITHUB_REF') ?: 'main',
            'portal_update_github_token' => getenv('SESAME_PORTAL_GITHUB_TOKEN') ?: '',
            'portal_update_check_ttl_seconds' => (int)(getenv('SESAME_PORTAL_UPDATE_CHECK_TTL_SECONDS') ?: 600),
            'portal_update_auto_check' => getenv('SESAME_PORTAL_UPDATE_AUTO_CHECK') !== '0',
            'portal_update_command' => getenv('SESAME_PORTAL_UPDATE_COMMAND') ?: 'sudo -n /usr/local/sbin/sesame-portal-update',
            'portal_update_pass_args' => getenv('SESAME_PORTAL_UPDATE_PASS_ARGS') === '1',
            'map_provider' => getenv('SESAME_PORTAL_MAP_PROVIDER') ?: 'openstreetmap',
            'map_default_lat' => (float)(getenv('SESAME_PORTAL_MAP_DEFAULT_LAT') ?: 47.242057),
            'map_default_lng' => (float)(getenv('SESAME_PORTAL_MAP_DEFAULT_LNG') ?: 38.889615),
            'smtp_host' => getenv('SESAME_PORTAL_SMTP_HOST') ?: '',
            'smtp_port' => (int)(getenv('SESAME_PORTAL_SMTP_PORT') ?: 465),
            'smtp_user' => getenv('SESAME_PORTAL_SMTP_USER') ?: '',
            'smtp_password' => getenv('SESAME_PORTAL_SMTP_PASSWORD') ?: '',
            'smtp_security' => getenv('SESAME_PORTAL_SMTP_SECURITY') ?: 'ssl',
            'smtp_from_email' => getenv('SESAME_PORTAL_SMTP_FROM_EMAIL') ?: '',
            'smtp_from_name' => getenv('SESAME_PORTAL_SMTP_FROM_NAME') ?: 'SesamePortal',
        ], is_array($loaded) ? $loaded : []);

        if (empty($config['crypto_keys']) || !is_array($config['crypto_keys'])) {
            $config['crypto_keys'] = ['default' => $config['app_secret']];
        }
        if (empty($config['crypto_primary_key'])) {
            $config['crypto_primary_key'] = array_key_first($config['crypto_keys']) ?: 'default';
        }

        self::$config = $config;
        return self::$config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }
}
