<?php

declare(strict_types=1);

namespace SesamePortal;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class Config
{
    private static ?array $config = null;

    public static function root(): string
    {
        return dirname(__DIR__);
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

final class DB
{
    private static ?PDO $pdo = null;
    private static ?string $driver = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = (string)(Config::get('db_dsn') ?: '');
        if ($dsn === '') {
            $stateDir = Config::stateDir();
            if (!is_dir($stateDir)) {
                mkdir($stateDir, 0750, true);
            }
            $dsn = 'sqlite:' . Config::get('db_path');
        }

        $pdo = new PDO($dsn, Config::get('db_user') ?: null, Config::get('db_password') ?: null);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if (self::driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        $dsn = (string)(Config::get('db_dsn') ?: 'sqlite:' . Config::get('db_path'));
        self::$driver = strtolower(strtok($dsn, ':') ?: 'sqlite');
        return self::$driver;
    }

    public static function migrate(): void
    {
        $pdo = self::pdo();
        foreach (self::schemaStatements() as $statement) {
            $pdo->exec($statement);
        }

        self::ensureIndex('camera_groups', 'idx_camera_groups_group', 'group_id');
        self::ensureIndex('user_groups', 'idx_user_groups_group', 'group_id');
        self::ensureIndex('favorites', 'idx_favorites_user', 'user_id');
        self::ensureColumn('dvr_servers', 'last_metrics_at', 'TEXT');
        self::ensureColumn('dvr_servers', 'last_metrics_json', 'TEXT');
        self::ensureColumn('cameras', 'last_sync_at', 'TEXT');
        self::ensureColumn('cameras', 'last_sync_ok', 'INTEGER');
        self::ensureColumn('cameras', 'last_sync_message', 'TEXT');
        self::ensureColumn('cameras', 'dvr_control_mode', self::driver() === 'mysql' ? "VARCHAR(32) NOT NULL DEFAULT 'managed'" : "TEXT NOT NULL DEFAULT 'managed'");
    }

    public static function insertIgnoreSql(string $table, array $columns): string
    {
        $columnSql = implode(', ', $columns);
        $placeholderSql = implode(', ', array_fill(0, count($columns), '?'));
        return match (self::driver()) {
            'pgsql' => "INSERT INTO {$table}({$columnSql}) VALUES({$placeholderSql}) ON CONFLICT DO NOTHING",
            'mysql' => "INSERT IGNORE INTO {$table}({$columnSql}) VALUES({$placeholderSql})",
            default => "INSERT OR IGNORE INTO {$table}({$columnSql}) VALUES({$placeholderSql})",
        };
    }

    public static function randomOrderSql(): string
    {
        return self::driver() === 'mysql' ? 'RAND()' : 'RANDOM()';
    }

    public static function lastInsertId(string $table): int
    {
        if (self::driver() === 'pgsql') {
            $stmt = self::pdo()->prepare("SELECT currval(pg_get_serial_sequence(?, 'id'))");
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn();
        }
        return (int)self::pdo()->lastInsertId();
    }

    public static function setForeignKeys(bool $enabled): void
    {
        if (self::driver() === 'sqlite') {
            self::pdo()->exec('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'));
        } elseif (self::driver() === 'mysql') {
            self::pdo()->exec('SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0'));
        }
    }

    private static function schemaStatements(): array
    {
        return match (self::driver()) {
            'pgsql' => self::pgsqlSchema(),
            'mysql' => self::mysqlSchema(),
            default => self::sqliteSchema(),
        };
    }

    private static function sqliteSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "user",
                blocked INTEGER NOT NULL DEFAULT 0,
                daily_token TEXT,
                previous_daily_token TEXT,
                daily_token_date TEXT,
                static_token_hash TEXT,
                created_at TEXT NOT NULL,
                last_login_at TEXT
            )',
            'CREATE TABLE IF NOT EXISTS portal_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT NOT NULL DEFAULT "",
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS user_groups (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                group_id INTEGER NOT NULL REFERENCES portal_groups(id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, group_id)
            )',
            'CREATE TABLE IF NOT EXISTS dvr_servers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                base_url TEXT NOT NULL,
                management_token_enc TEXT,
                blocked INTEGER NOT NULL DEFAULT 0,
                last_check_at TEXT,
                last_check_result TEXT,
                last_metrics_at TEXT,
                last_metrics_json TEXT,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS cameras (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                source_url TEXT NOT NULL,
                server_id INTEGER REFERENCES dvr_servers(id) ON DELETE SET NULL,
                server_selection TEXT NOT NULL DEFAULT "manual",
                latitude REAL,
                longitude REAL,
                direction_deg INTEGER NOT NULL DEFAULT 0,
                view_angle_deg INTEGER NOT NULL DEFAULT 60,
                retention_days TEXT NOT NULL DEFAULT "7d",
                dvr_control_mode TEXT NOT NULL DEFAULT "managed",
                blocked INTEGER NOT NULL DEFAULT 0,
                dvr_stream_name TEXT NOT NULL,
                last_sync_at TEXT,
                last_sync_ok INTEGER,
                last_sync_message TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS camera_groups (
                camera_id INTEGER NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
                group_id INTEGER NOT NULL REFERENCES portal_groups(id) ON DELETE CASCADE,
                PRIMARY KEY (camera_id, group_id)
            )',
            'CREATE TABLE IF NOT EXISTS favorites (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                camera_id INTEGER NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, camera_id)
            )',
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_user_id INTEGER,
                action TEXT NOT NULL,
                details TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL
            )',
        ];
    }

    private static function pgsqlSchema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                login TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                blocked INTEGER NOT NULL DEFAULT 0,
                daily_token TEXT,
                previous_daily_token TEXT,
                daily_token_date TEXT,
                static_token_hash TEXT,
                created_at TEXT NOT NULL,
                last_login_at TEXT
            )",
            'CREATE TABLE IF NOT EXISTS portal_groups (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                description TEXT NOT NULL,
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS user_groups (
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                group_id BIGINT NOT NULL REFERENCES portal_groups(id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, group_id)
            )',
            'CREATE TABLE IF NOT EXISTS dvr_servers (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                name TEXT NOT NULL,
                base_url TEXT NOT NULL,
                management_token_enc TEXT,
                blocked INTEGER NOT NULL DEFAULT 0,
                last_check_at TEXT,
                last_check_result TEXT,
                last_metrics_at TEXT,
                last_metrics_json TEXT,
                created_at TEXT NOT NULL
            )',
            "CREATE TABLE IF NOT EXISTS cameras (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                source_url TEXT NOT NULL,
                server_id BIGINT REFERENCES dvr_servers(id) ON DELETE SET NULL,
                server_selection TEXT NOT NULL DEFAULT 'manual',
                latitude DOUBLE PRECISION,
                longitude DOUBLE PRECISION,
                direction_deg INTEGER NOT NULL DEFAULT 0,
                view_angle_deg INTEGER NOT NULL DEFAULT 60,
                retention_days TEXT NOT NULL DEFAULT '7d',
                dvr_control_mode TEXT NOT NULL DEFAULT 'managed',
                blocked INTEGER NOT NULL DEFAULT 0,
                dvr_stream_name TEXT NOT NULL,
                last_sync_at TEXT,
                last_sync_ok INTEGER,
                last_sync_message TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            'CREATE TABLE IF NOT EXISTS camera_groups (
                camera_id BIGINT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
                group_id BIGINT NOT NULL REFERENCES portal_groups(id) ON DELETE CASCADE,
                PRIMARY KEY (camera_id, group_id)
            )',
            'CREATE TABLE IF NOT EXISTS favorites (
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                camera_id BIGINT NOT NULL REFERENCES cameras(id) ON DELETE CASCADE,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, camera_id)
            )',
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                actor_user_id BIGINT,
                action TEXT NOT NULL,
                details TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        ];
    }

    private static function mysqlSchema(): array
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                login VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(32) NOT NULL DEFAULT 'user',
                blocked INTEGER NOT NULL DEFAULT 0,
                daily_token TEXT,
                previous_daily_token TEXT,
                daily_token_date VARCHAR(64),
                static_token_hash VARCHAR(255),
                created_at VARCHAR(64) NOT NULL,
                last_login_at VARCHAR(64)
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS portal_groups (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT NOT NULL,
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at VARCHAR(64) NOT NULL
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS user_groups (
                user_id BIGINT NOT NULL,
                group_id BIGINT NOT NULL,
                PRIMARY KEY (user_id, group_id),
                CONSTRAINT fk_user_groups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_groups_group FOREIGN KEY (group_id) REFERENCES portal_groups(id) ON DELETE CASCADE
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS dvr_servers (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                base_url TEXT NOT NULL,
                management_token_enc TEXT,
                blocked INTEGER NOT NULL DEFAULT 0,
                last_check_at VARCHAR(64),
                last_check_result TEXT,
                last_metrics_at VARCHAR(64),
                last_metrics_json MEDIUMTEXT,
                created_at VARCHAR(64) NOT NULL
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS cameras (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                source_url TEXT NOT NULL,
                server_id BIGINT,
                server_selection VARCHAR(32) NOT NULL DEFAULT 'manual',
                latitude DOUBLE,
                longitude DOUBLE,
                direction_deg INTEGER NOT NULL DEFAULT 0,
                view_angle_deg INTEGER NOT NULL DEFAULT 60,
                retention_days VARCHAR(64) NOT NULL DEFAULT '7d',
                dvr_control_mode VARCHAR(32) NOT NULL DEFAULT 'managed',
                blocked INTEGER NOT NULL DEFAULT 0,
                dvr_stream_name VARCHAR(255) NOT NULL,
                last_sync_at VARCHAR(64),
                last_sync_ok INTEGER,
                last_sync_message TEXT,
                created_at VARCHAR(64) NOT NULL,
                updated_at VARCHAR(64) NOT NULL,
                CONSTRAINT fk_cameras_server FOREIGN KEY (server_id) REFERENCES dvr_servers(id) ON DELETE SET NULL
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS camera_groups (
                camera_id BIGINT NOT NULL,
                group_id BIGINT NOT NULL,
                PRIMARY KEY (camera_id, group_id),
                CONSTRAINT fk_camera_groups_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE,
                CONSTRAINT fk_camera_groups_group FOREIGN KEY (group_id) REFERENCES portal_groups(id) ON DELETE CASCADE
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS favorites (
                user_id BIGINT NOT NULL,
                camera_id BIGINT NOT NULL,
                created_at VARCHAR(64) NOT NULL,
                PRIMARY KEY (user_id, camera_id),
                CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_favorites_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
            ){$suffix}",
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                actor_user_id BIGINT,
                action VARCHAR(255) NOT NULL,
                details TEXT NOT NULL,
                created_at VARCHAR(64) NOT NULL
            ){$suffix}",
        ];
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        $pdo = self::pdo();
        if (self::columnExists($table, $column)) {
            return;
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    private static function ensureIndex(string $table, string $index, string $column): void
    {
        if (self::indexExists($table, $index)) {
            return;
        }
        self::pdo()->exec("CREATE INDEX {$index} ON {$table}({$column})");
    }

    private static function columnExists(string $table, string $column): bool
    {
        $pdo = self::pdo();
        if (self::driver() === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
            foreach ($columns as $existing) {
                if (($existing['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        if (self::driver() === 'pgsql') {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?');
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }

    private static function indexExists(string $table, string $index): bool
    {
        $pdo = self::pdo();
        if (self::driver() === 'sqlite') {
            $indexes = $pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll();
            foreach ($indexes as $existing) {
                if (($existing['name'] ?? '') === $index) {
                    return true;
                }
            }
            return false;
        }

        if (self::driver() === 'pgsql') {
            $stmt = $pdo->prepare('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?');
            $stmt->execute([$table, $index]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    }
}

final class Util
{
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
}

final class I18n
{
    private const LOCALES = [
        'ru' => 'RU',
        'en' => 'EN',
    ];

    private static ?string $locale = null;

    public static function bootstrap(): void
    {
        Auth::start();
        $requested = (string)($_GET['lang'] ?? '');
        if (isset(self::LOCALES[$requested])) {
            $_SESSION['locale'] = $requested;
            self::$locale = $requested;
        }
    }

    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }

        $session = $_SESSION ?? [];
        $sessionLocale = (string)($session['locale'] ?? '');
        if (isset(self::LOCALES[$sessionLocale])) {
            self::$locale = $sessionLocale;
            return self::$locale;
        }

        $configLocale = (string)Config::get('locale', 'ru');
        self::$locale = isset(self::LOCALES[$configLocale]) ? $configLocale : 'ru';
        return self::$locale;
    }

    public static function t(string $key, string $fallback): string
    {
        return self::messages()[self::locale()][$key] ?? $fallback;
    }

    public static function js(): array
    {
        return [
            'openVideo' => self::t('js.openVideo', 'Открыть видео'),
            'previewUnavailable' => self::t('js.previewUnavailable', 'Превью недоступно'),
            'mapChangePending' => self::t('js.mapChangePending', 'Подтвердите изменение на карте'),
            'apply' => self::t('action.apply', 'Применить'),
            'cancel' => self::t('action.cancel', 'Отменить'),
            'fullscreen' => self::t('player.fullscreen', 'На весь экран'),
            'collapse' => self::t('player.collapse', 'Свернуть'),
        ];
    }

    public static function languageLinks(): string
    {
        $current = self::locale();
        $path = Util::path();
        $params = $_GET;
        $html = '<span class="locale-switch">';
        foreach (self::LOCALES as $locale => $label) {
            $params['lang'] = $locale;
            $href = $path . '?' . http_build_query($params);
            $html .= '<a class="' . ($current === $locale ? 'active' : '') . '" href="' . Util::h($href) . '">' . $label . '</a>';
        }
        return $html . '</span>';
    }

    private static function messages(): array
    {
        return [
            'ru' => [],
            'en' => [
                'nav.mosaic' => 'Mosaic',
                'nav.map' => 'Map',
                'nav.dashboard' => 'Dashboard',
                'nav.users' => 'Users',
                'nav.groups' => 'Groups',
                'nav.cameras' => 'Cameras',
                'nav.dvr' => 'DVR',
                'nav.audit' => 'Audit',
                'nav.logout' => 'Logout',
                'login.title' => 'Sign In',
                'login.subtitle' => 'SesameWare video surveillance portal',
                'login.invalid' => 'Invalid login or password',
                'field.login' => 'Login',
                'field.password' => 'Password',
                'action.login' => 'Sign in',
                'action.save' => 'Save',
                'action.saveSync' => 'Save and sync',
                'action.find' => 'Find',
                'action.show' => 'Show',
                'action.edit' => 'Edit',
                'action.delete' => 'Delete',
                'action.check' => 'Check',
                'action.update' => 'Refresh',
                'action.updateAll' => 'Refresh all',
                'action.back' => 'Back',
                'action.apply' => 'Apply',
                'action.cancel' => 'Cancel',
                'filter.all' => 'All',
                'filter.favorites' => 'Favorites',
                'viewer.openPlayer' => 'Open player',
                'player.title' => 'Player',
                'player.fullscreen' => 'Fullscreen',
                'player.collapse' => 'Exit fullscreen',
                'js.openVideo' => 'Open video',
                'js.previewUnavailable' => 'Preview unavailable',
                'js.mapChangePending' => 'Confirm map change',
                'dashboard.users' => 'Users',
                'dashboard.groups' => 'Groups',
                'dashboard.cameras' => 'Cameras',
                'dashboard.dvrServers' => 'DVR servers',
                'dashboard.dvrServersTitle' => 'SesameDVR servers',
                'dashboard.recentSync' => 'Recent camera sync',
                'server.version' => 'Version',
                'server.streams' => 'Streams',
                'server.check' => 'Check',
                'users.title' => 'Users',
                'users.new' => 'New user',
                'users.edit' => 'Edit user',
                'users.loginRequired' => 'Login is required',
                'users.passwordShort' => 'Password must be at least 6 characters',
                'users.passwordPlaceholderNew' => 'minimum 6 characters',
                'users.passwordPlaceholderEdit' => 'leave blank to keep unchanged',
                'users.blocked' => 'Blocked',
                'groups.title' => 'Groups',
                'groups.new' => 'New group',
                'groups.edit' => 'Edit group',
                'cameras.title' => 'Cameras',
                'cameras.new' => 'New camera',
                'cameras.edit' => 'Edit camera',
                'cameras.name' => 'Name',
                'cameras.sourceUrl' => 'Source URL',
                'cameras.server' => 'Server',
                'cameras.serverAutoNone' => 'Auto/not selected',
                'cameras.serverSelection' => 'Server selection',
                'cameras.selectionManual' => 'specific',
                'cameras.selectionAuto' => 'automatic random',
                'cameras.streamName' => 'SesameDVR stream name',
                'cameras.position' => 'Position on map',
                'cameras.clearPosition' => 'Clear point',
                'cameras.direction' => 'Direction',
                'cameras.viewAngle' => 'View angle',
                'cameras.retention' => 'Archive depth',
                'cameras.blocked' => 'Blocked',
                'cameras.groups' => 'Groups',
                'cameras.mode' => 'Camera mode',
                'cameras.modeManaged' => 'Full DVR management',
                'cameras.modeReadOnly' => 'Read-only DVR stream',
                'cameras.sourceRequired' => 'Source URL is required for full DVR management mode',
                'cameras.readOnlySyncSkipped' => 'Read-only mode: DVR management skipped',
                'servers.title' => 'SesameDVR servers',
                'servers.new' => 'New server',
                'servers.edit' => 'Edit server',
                'servers.managementKey' => 'Management key',
                'servers.blocked' => 'Blocked',
                'audit.title' => 'Audit log',
                'audit.search' => 'Search action, user, or details',
                'audit.allActions' => 'All actions',
                'audit.allUsers' => 'All users',
                'audit.time' => 'Time',
                'audit.user' => 'User',
                'audit.action' => 'Action',
                'audit.details' => 'Details',
                'table.search' => 'Search',
                'table.shown' => 'Shown',
                'table.of' => 'of',
                'common.noServer' => 'No server',
            ],
        ];
    }
}

final class Crypto
{
    private const PREFIX = 'v2';

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $keyId = self::primaryKeyId();
        $key = self::keyBytes(self::keys()[$keyId]);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('encrypt_failed');
        }

        return self::PREFIX . ':' . $keyId . ':' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $encoded): string
    {
        if (!$encoded) {
            return '';
        }

        if (str_starts_with($encoded, self::PREFIX . ':')) {
            return self::decryptVersioned($encoded);
        }

        return self::decryptRaw($encoded, hash('sha256', (string)Config::get('app_secret'), true));
    }

    public static function needsRotation(?string $encoded): bool
    {
        if (!$encoded || !str_starts_with($encoded, self::PREFIX . ':')) {
            return (bool)$encoded;
        }

        $parts = explode(':', $encoded, 3);
        return count($parts) !== 3 || $parts[1] !== self::primaryKeyId();
    }

    private static function decryptVersioned(string $encoded): string
    {
        $parts = explode(':', $encoded, 3);
        if (count($parts) !== 3 || $parts[1] === '' || $parts[2] === '') {
            return '';
        }

        $keys = self::keys();
        if (!isset($keys[$parts[1]])) {
            return '';
        }

        return self::decryptRaw($parts[2], self::keyBytes($keys[$parts[1]]));
    }

    private static function decryptRaw(string $encoded, string $key): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    private static function primaryKeyId(): string
    {
        $keyId = (string)Config::get('crypto_primary_key', 'default');
        if (str_contains($keyId, ':')) {
            throw new RuntimeException('crypto_primary_key_must_not_contain_colon');
        }

        $keys = self::keys();
        if (!isset($keys[$keyId])) {
            throw new RuntimeException('crypto_primary_key_not_found');
        }

        return $keyId;
    }

    private static function keys(): array
    {
        $keys = Config::get('crypto_keys', []);
        if (!is_array($keys) || !$keys) {
            return ['default' => (string)Config::get('app_secret')];
        }

        $usable = [];
        foreach ($keys as $id => $material) {
            $id = (string)$id;
            $material = (string)$material;
            if ($id !== '' && !str_contains($id, ':') && $material !== '') {
                $usable[$id] = $material;
            }
        }

        return $usable ?: ['default' => (string)Config::get('app_secret')];
    }

    private static function keyBytes(string $material): string
    {
        return hash('sha256', $material, true);
    }
}

final class Audit
{
    public static function log(string $action, string $details = ''): void
    {
        $user = Auth::user();
        DB::pdo()->prepare('INSERT INTO audit_logs(actor_user_id, action, details, created_at) VALUES(?, ?, ?, ?)')
            ->execute([$user['id'] ?? null, $action, $details, Util::now()]);
    }
}

final class TokenService
{
    public static function rotateAll(): int
    {
        $pdo = DB::pdo();
        $today = self::today();
        $users = $pdo->query('SELECT id, daily_token FROM users')->fetchAll();
        $stmt = $pdo->prepare(
            'UPDATE users SET previous_daily_token = ?, daily_token = ?, daily_token_date = ? WHERE id = ?'
        );

        foreach ($users as $user) {
            $stmt->execute([
                $user['daily_token'] ?: null,
                Util::randomToken(),
                $today,
                $user['id'],
            ]);
        }

        return count($users);
    }

    public static function ensureUserTokens(int $userId): void
    {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT id, daily_token, daily_token_date FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        if ($user['daily_token'] && $user['daily_token_date'] === self::today()) {
            return;
        }

        $pdo->prepare(
            'UPDATE users SET previous_daily_token = daily_token, daily_token = ?, daily_token_date = ? WHERE id = ?'
        )->execute([Util::randomToken(), self::today(), $userId]);
    }

    public static function issueStaticToken(int $userId): string
    {
        $token = 'sp_' . Util::randomToken();
        DB::pdo()->prepare('UPDATE users SET static_token_hash = ? WHERE id = ?')
            ->execute([password_hash($token, PASSWORD_DEFAULT), $userId]);
        Audit::log('user.static_token.issue', 'user_id=' . $userId);
        return $token;
    }

    public static function revokeStaticToken(int $userId): void
    {
        DB::pdo()->prepare('UPDATE users SET static_token_hash = NULL WHERE id = ?')->execute([$userId]);
        Audit::log('user.static_token.revoke', 'user_id=' . $userId);
    }

    public static function userByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $stmt = DB::pdo()->query('SELECT * FROM users WHERE blocked = 0');
        foreach ($stmt->fetchAll() as $user) {
            if (hash_equals((string)$user['daily_token'], $token)) {
                return $user;
            }

            if (self::isOverlapWindow() && $user['previous_daily_token'] && hash_equals($user['previous_daily_token'], $token)) {
                return $user;
            }

            if ($user['static_token_hash'] && password_verify($token, $user['static_token_hash'])) {
                return $user;
            }
        }

        return null;
    }

    public static function today(): string
    {
        $tz = new DateTimeZone((string)Config::get('timezone', 'UTC'));
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    public static function isOverlapWindow(): bool
    {
        $tz = new DateTimeZone((string)Config::get('timezone', 'UTC'));
        $hour = (int)(new DateTimeImmutable('now', $tz))->format('G');
        return $hour >= 0 && $hour < 6;
    }
}

final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('sesame_portal');
            session_start();
        }
    }

    public static function user(): ?array
    {
        self::start();
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }

        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE id = ? AND blocked = 0');
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: null;
        if ($user) {
            TokenService::ensureUserTokens((int)$user['id']);
            $stmt->execute([$id]);
            $user = $stmt->fetch() ?: null;
        }
        return $user;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            Util::redirect('/login');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        return $user;
    }

    public static function login(string $login, string $password): bool
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE login = ? AND blocked = 0');
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        TokenService::ensureUserTokens((int)$user['id']);
        DB::pdo()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Util::now(), $user['id']]);
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }
}

final class Csrf
{
    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = Util::randomToken();
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf" value="' . Util::h(self::token()) . '">';
    }

    public static function verify(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        Auth::start();
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            http_response_code(419);
            echo 'CSRF token mismatch';
            exit;
        }
    }
}

final class DvrClient
{
    public static function syncCamera(int $cameraId): array
    {
        $camera = Repo::camera($cameraId);
        if (!$camera || !$camera['server_id']) {
            return self::storeCameraSync($cameraId, false, 'No SesameDVR server selected');
        }

        $server = Repo::server((int)$camera['server_id']);
        if (!$server || (int)$server['blocked'] === 1) {
            return self::storeCameraSync($cameraId, false, 'SesameDVR server is unavailable or blocked');
        }

        if (($camera['dvr_control_mode'] ?? 'managed') === 'read_only') {
            return self::storeCameraSync($cameraId, true, I18n::t('cameras.readOnlySyncSkipped', 'Read-only mode: DVR management skipped'));
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        if ($token === '') {
            return self::storeCameraSync($cameraId, false, 'SesameDVR management token is missing');
        }

        $name = $camera['dvr_stream_name'] ?: $camera['name'];
        $payload = [
            'name' => $name,
            'source' => $camera['source_url'],
            'enabled' => ((int)$camera['blocked'] === 0),
            'retentionDays' => $camera['retention_days'],
            'authMode' => 'authBackend',
        ];

        $base = rtrim($server['base_url'], '/');
        $endpoint = $base . '/api/streams/' . rawurlencode($name);
        $result = self::request('PUT', $endpoint, $token, $payload);
        if ($result['status'] === 404) {
            $endpoint = $base . '/api/streams';
            $result = self::request('POST', $endpoint, $token, $payload);
        }

        $ok = $result['status'] >= 200 && $result['status'] < 300;
        $message = self::responseSummary($result, $endpoint);
        return self::storeCameraSync($cameraId, $ok, $message);
    }

    public static function checkServer(int $serverId): array
    {
        $server = Repo::server($serverId);
        if (!$server) {
            return ['ok' => false, 'message' => 'server_not_found'];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $result = self::request('GET', rtrim($server['base_url'], '/') . '/api/system/version', $token, null);
        $ok = $result['status'] >= 200 && $result['status'] < 300;
        $message = self::responseSummary($result, rtrim($server['base_url'], '/') . '/api/system/version');
        DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ? WHERE id = ?')
            ->execute([Util::now(), $message, $serverId]);
        return ['ok' => $ok, 'message' => $message];
    }

    public static function fetchServerMetrics(int $serverId): array
    {
        $server = Repo::server($serverId);
        if (!$server) {
            return ['ok' => false, 'message' => 'server_not_found'];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $base = rtrim($server['base_url'], '/');
        $version = self::request('GET', $base . '/api/system/version', $token, null);
        $status = self::request('GET', $base . '/api/system/status', $token, null);
        $ok = $version['status'] >= 200 && $version['status'] < 300 && $status['status'] >= 200 && $status['status'] < 300;
        $payload = [
            'version' => self::jsonOrBody($version),
            'status' => self::jsonOrBody($status),
            'fetchedAt' => Util::now(),
        ];
        $message = self::responseSummary($version, $base . '/api/system/version') . '; ' .
            self::responseSummary($status, $base . '/api/system/status');
        DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ?, last_metrics_at = ?, last_metrics_json = ? WHERE id = ?')
            ->execute([Util::now(), $message, Util::now(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $serverId]);
        return ['ok' => $ok, 'message' => $message, 'metrics' => $payload];
    }

    private static function request(string $method, string $url, string $token, ?array $payload): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'X-Management-Token: ' . $token;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => $body === false ? $error : (string)$body];
    }

    private static function storeCameraSync(int $cameraId, bool $ok, string $message): array
    {
        DB::pdo()->prepare('UPDATE cameras SET last_sync_at = ?, last_sync_ok = ?, last_sync_message = ? WHERE id = ?')
            ->execute([Util::now(), $ok ? 1 : 0, mb_substr($message, 0, 1000), $cameraId]);
        return ['ok' => $ok, 'message' => $message];
    }

    private static function responseSummary(array $result, string $endpoint): string
    {
        $body = trim((string)($result['body'] ?? ''));
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $body = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $body = preg_replace('/\s+/', ' ', $body);
        return 'HTTP ' . (int)($result['status'] ?? 0) . ' ' . $endpoint . ' ' . mb_substr((string)$body, 0, 420);
    }

    private static function jsonOrBody(array $result): mixed
    {
        $decoded = json_decode((string)($result['body'] ?? ''), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'httpStatus' => (int)($result['status'] ?? 0),
            'body' => mb_substr((string)($result['body'] ?? ''), 0, 1000),
        ];
    }
}

final class Repo
{
    public static function server(int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM dvr_servers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function camera(int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM cameras WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function all(string $table, string $order = 'id DESC'): array
    {
        return DB::pdo()->query("SELECT * FROM {$table} ORDER BY {$order}")->fetchAll();
    }

    public static function accessibleCameras(array $user, string $filter = 'all'): array
    {
        $pdo = DB::pdo();
        $params = [];
        $where = ['c.blocked = 0'];
        $join = 'LEFT JOIN dvr_servers s ON s.id = c.server_id';

        if ($user['role'] !== 'admin') {
            $join .= ' JOIN camera_groups cg_access ON cg_access.camera_id = c.id
                       JOIN user_groups ug_access ON ug_access.group_id = cg_access.group_id
                       JOIN portal_groups pg_access ON pg_access.id = cg_access.group_id';
            $where[] = 'ug_access.user_id = ?';
            $where[] = 'pg_access.blocked = 0';
            $params[] = $user['id'];
        }

        if ($filter === 'favorites') {
            $join .= ' JOIN favorites f ON f.camera_id = c.id AND f.user_id = ?';
            $params[] = $user['id'];
        } elseif (str_starts_with($filter, 'group:')) {
            $groupId = (int)substr($filter, 6);
            $join .= ' JOIN camera_groups cg_filter ON cg_filter.camera_id = c.id';
            $where[] = 'cg_filter.group_id = ?';
            $params[] = $groupId;
        }

        $sql = 'SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url
                FROM cameras c ' . $join . '
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.name ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function groupsForUser(array $user): array
    {
        if ($user['role'] === 'admin') {
            return self::all('portal_groups', 'name ASC');
        }

        $stmt = DB::pdo()->prepare(
            'SELECT g.* FROM portal_groups g
             JOIN user_groups ug ON ug.group_id = g.id
             WHERE ug.user_id = ? AND g.blocked = 0
             ORDER BY g.name ASC'
        );
        $stmt->execute([$user['id']]);
        return $stmt->fetchAll();
    }

    public static function favoritesMap(int $userId): array
    {
        $stmt = DB::pdo()->prepare('SELECT camera_id FROM favorites WHERE user_id = ?');
        $stmt->execute([$userId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['camera_id']] = true;
        }
        return $map;
    }

    public static function cameraAllowedForUser(array $user, int $cameraId): bool
    {
        if ($user['role'] === 'admin') {
            $stmt = DB::pdo()->prepare('SELECT id FROM cameras WHERE id = ? AND blocked = 0');
            $stmt->execute([$cameraId]);
            return (bool)$stmt->fetch();
        }

        $stmt = DB::pdo()->prepare(
            'SELECT c.id FROM cameras c
             JOIN camera_groups cg ON cg.camera_id = c.id
             JOIN user_groups ug ON ug.group_id = cg.group_id
             JOIN portal_groups g ON g.id = cg.group_id
             WHERE c.id = ? AND c.blocked = 0 AND g.blocked = 0 AND ug.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$cameraId, $user['id']]);
        return (bool)$stmt->fetch();
    }
}

final class App
{
    private static function t(string $key, string $fallback): string
    {
        return I18n::t($key, $fallback);
    }

    public static function run(): void
    {
        DB::migrate();
        Auth::start();
        Csrf::verify();
        I18n::bootstrap();

        $path = Util::path();
        match ($path) {
            '/login' => self::login(),
            '/logout' => self::logout(),
            '/admin/dashboard' => self::dashboard(),
            '/admin/users' => self::users(),
            '/admin/groups' => self::groups(),
            '/admin/servers' => self::servers(),
            '/admin/cameras' => self::cameras(),
            '/admin/audit' => self::audit(),
            '/viewer/map' => self::viewer('map'),
            '/viewer/player' => self::player(),
            '/favorite/toggle' => self::toggleFavorite(),
            '/api/sesamedvr/auth' => self::authBackend(),
            default => self::viewer('mosaic'),
        };
    }

    private static function dashboard(): void
    {
        Auth::requireAdmin();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            if ($action === 'refresh_server') {
                $result = DvrClient::fetchServerMetrics((int)Util::post('id'));
                $message = $result['message'];
            } elseif ($action === 'refresh_all') {
                $messages = [];
                foreach (Repo::all('dvr_servers', 'name ASC') as $server) {
                    if ((int)$server['blocked'] === 0) {
                        $result = DvrClient::fetchServerMetrics((int)$server['id']);
                        $messages[] = $server['name'] . ': ' . ($result['ok'] ? 'ok' : 'error');
                    }
                }
                $message = implode('; ', $messages);
            }
        }

        $counts = [
            self::t('dashboard.users', 'Пользователи') => (int)DB::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            self::t('dashboard.groups', 'Группы') => (int)DB::pdo()->query('SELECT COUNT(*) FROM portal_groups')->fetchColumn(),
            self::t('dashboard.cameras', 'Камеры') => (int)DB::pdo()->query('SELECT COUNT(*) FROM cameras')->fetchColumn(),
            self::t('dashboard.dvrServers', 'DVR серверы') => (int)DB::pdo()->query('SELECT COUNT(*) FROM dvr_servers')->fetchColumn(),
        ];
        $servers = Repo::all('dvr_servers', 'name ASC');
        $recentSync = DB::pdo()->query('SELECT c.*, s.name AS server_name FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id ORDER BY COALESCE(c.last_sync_at, "") DESC, c.name ASC LIMIT 12')->fetchAll();

        self::layout('Dashboard', function () use ($counts, $servers, $recentSync, $message) {
            self::notice($message);
            echo '<section class="summary-grid">';
            foreach ($counts as $label => $value) {
                echo '<div class="summary-card"><span>' . Util::h($label) . '</span><strong>' . Util::h($value) . '</strong></div>';
            }
            echo '</section>';
            echo '<section class="panel"><div class="section-head"><h2>' . self::t('dashboard.dvrServersTitle', 'SesameDVR серверы') . '</h2>';
            self::smallPost('/admin/dashboard', ['action' => 'refresh_all'], self::t('action.updateAll', 'Обновить все'), 'primary');
            echo '</div><div class="server-grid">';
            foreach ($servers as $server) {
                self::serverMetricCard($server);
            }
            echo '</div></section>';
            self::table(self::t('dashboard.recentSync', 'Последняя синхронизация камер'), ['name', 'server_name', 'last_sync_ok', 'last_sync_at', 'last_sync_message'], $recentSync, '/admin/cameras', true, null, false);
        });
    }

    private static function login(): void
    {
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (Auth::login((string)Util::post('login'), (string)Util::post('password'))) {
                Util::redirect('/');
            }
            $error = self::t('login.invalid', 'Неверный логин или пароль');
        }

        self::layout(self::t('login.title', 'Вход'), function () use ($error) {
            echo '<section class="login-visual"><div><img src="/assets/logo-sesameportal-inverse.svg" alt="SesamePortal"><p>' . self::t('login.subtitle', 'Портал видеонаблюдения SesameWare') . '</p></div>';
            echo '<div class="login-features"><span>Secure</span><span>Reliable</span><span>Efficient</span></div></section>';
            echo '<section class="login-panel login-card">';
            echo '<div class="login-brand"><img src="/assets/logo-sesameportal.svg" alt="SesamePortal"><img class="brand-mark-compat" src="/assets/brand-mark.svg" alt="" aria-hidden="true"></div>';
            if ($error) {
                echo '<div class="alert danger">' . Util::h($error) . '</div>';
            }
            echo '<form method="post" class="form">';
            echo Csrf::field();
            echo '<label>' . self::t('field.login', 'Логин') . '<input name="login" autocomplete="username" required></label>';
            echo '<label>' . self::t('field.password', 'Пароль') . '<input name="password" type="password" autocomplete="current-password" required></label>';
            echo '<button class="primary">' . self::t('action.login', 'Войти') . '</button>';
            echo '</form>' . I18n::languageLinks() . '</section>';
        }, null);
    }

    private static function logout(): void
    {
        Auth::logout();
        Util::redirect('/login');
    }

    private static function users(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        $staticToken = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);

            if ($action === 'save') {
                $login = trim((string)Util::post('login'));
                $password = (string)Util::post('password');
                $role = Util::post('role') === 'admin' ? 'admin' : 'user';
                $blocked = Util::checkbox('blocked');
                if ($login === '') {
                    $message = self::t('users.loginRequired', 'Логин обязателен');
                } elseif ($id === 0 && strlen($password) < 6) {
                    $message = self::t('users.passwordShort', 'Пароль должен быть не короче 6 символов');
                } else {
                    if ($id > 0) {
                        if ($password !== '') {
                            if (strlen($password) < 6) {
                                $message = self::t('users.passwordShort', 'Пароль должен быть не короче 6 символов');
                            } else {
                                $pdo->prepare('UPDATE users SET login=?, password_hash=?, role=?, blocked=? WHERE id=?')
                                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $id]);
                            }
                        } else {
                            $pdo->prepare('UPDATE users SET login=?, role=?, blocked=? WHERE id=?')
                                ->execute([$login, $role, $blocked, $id]);
                        }
                    } else {
                        $pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, daily_token, daily_token_date, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, Util::randomToken(), TokenService::today(), Util::now()]);
                    }
                    Audit::log('user.save', $login);
                }
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                Audit::log('user.delete', 'user_id=' . $id);
            } elseif ($action === 'issue_static' && $id > 0) {
                $staticToken = TokenService::issueStaticToken($id);
            } elseif ($action === 'revoke_static' && $id > 0) {
                TokenService::revokeStaticToken($id);
            }
        }

        $edit = self::rowById('users', (int)($_GET['edit'] ?? 0));
        $list = self::filteredRows('users', ['login', 'role'], 'login ASC');
        $users = $list['rows'];
        self::layout(self::t('users.title', 'Пользователи'), function () use ($users, $edit, $message, $staticToken, $list) {
            self::notice($message);
            if ($staticToken) {
                echo '<div class="alert">Static token: <code>' . Util::h($staticToken) . '</code></div>';
            }
            echo '<div class="admin-grid">';
            echo '<section class="panel"><h2>' . ($edit ? self::t('users.edit', 'Изменить пользователя') : self::t('users.new', 'Новый пользователь')) . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('field.login', 'Логин') . '<input name="login" value="' . Util::h($edit['login'] ?? '') . '" required></label>';
            echo '<label>' . self::t('field.password', 'Пароль') . '<input name="password" type="password" minlength="6" placeholder="' . ($edit ? self::t('users.passwordPlaceholderEdit', 'оставьте пустым, чтобы не менять') : self::t('users.passwordPlaceholderNew', 'минимум 6 символов')) . '"></label>';
            echo '<label>Роль<select name="role"><option value="user">user</option><option value="admin" ' . (($edit['role'] ?? '') === 'admin' ? 'selected' : '') . '>admin</option></select></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('users.blocked', 'Заблокирован') . '</label>';
            echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
            self::table(self::t('users.title', 'Пользователи'), ['login', 'role', 'blocked', 'last_login_at'], $users, '/admin/users', false, $list);
            echo '</div>';
        });
    }

    private static function groups(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $name = trim((string)Util::post('name'));
                if ($id > 0) {
                    $pdo->prepare('UPDATE portal_groups SET name=?, description=?, blocked=? WHERE id=?')
                        ->execute([$name, Util::post('description'), Util::checkbox('blocked'), $id]);
                } else {
                    $pdo->prepare('INSERT INTO portal_groups(name, description, blocked, created_at) VALUES(?, ?, ?, ?)')
                        ->execute([$name, Util::post('description'), Util::checkbox('blocked'), Util::now()]);
                    $id = DB::lastInsertId('portal_groups');
                }
                self::replaceLinks('user_groups', 'group_id', $id, 'user_id', $_POST['user_ids'] ?? []);
                self::replaceLinks('camera_groups', 'group_id', $id, 'camera_id', $_POST['camera_ids'] ?? []);
                Audit::log('group.save', $name);
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('DELETE FROM portal_groups WHERE id=?')->execute([$id]);
                Audit::log('group.delete', 'group_id=' . $id);
            }
        }

        $edit = self::rowById('portal_groups', (int)($_GET['edit'] ?? 0));
        $linkedUsers = $edit ? self::linkedIds('user_groups', 'group_id', (int)$edit['id'], 'user_id') : [];
        $linkedCameras = $edit ? self::linkedIds('camera_groups', 'group_id', (int)$edit['id'], 'camera_id') : [];
        $users = Repo::all('users', 'login ASC');
        $cameras = Repo::all('cameras', 'name ASC');
        $list = self::filteredRows('portal_groups', ['name', 'description'], 'name ASC');
        $groups = $list['rows'];
        self::layout(self::t('groups.title', 'Группы'), function () use ($edit, $users, $cameras, $linkedUsers, $linkedCameras, $groups, $list) {
            echo '<div class="admin-grid"><section class="panel"><h2>' . ($edit ? self::t('groups.edit', 'Изменить группу') : self::t('groups.new', 'Новая группа')) . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>Название<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>Описание<textarea name="description">' . Util::h($edit['description'] ?? '') . '</textarea></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> Заблокирована</label>';
            self::checkboxList('Пользователи', 'user_ids[]', $users, $linkedUsers, 'login');
            self::checkboxList('Камеры', 'camera_ids[]', $cameras, $linkedCameras, 'name');
            echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
            self::table(self::t('groups.title', 'Группы'), ['name', 'blocked', 'description'], $groups, '/admin/groups', false, $list);
            echo '</div>';
        });
    }

    private static function servers(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $token = trim((string)Util::post('management_token'));
                if ($id > 0) {
                    $current = Repo::server($id);
                    $enc = $token !== '' ? Crypto::encrypt($token) : ($current['management_token_enc'] ?? null);
                    $pdo->prepare('UPDATE dvr_servers SET name=?, base_url=?, management_token_enc=?, blocked=? WHERE id=?')
                        ->execute([Util::post('name'), rtrim((string)Util::post('base_url'), '/'), $enc, Util::checkbox('blocked'), $id]);
                } else {
                    $pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                        ->execute([Util::post('name'), rtrim((string)Util::post('base_url'), '/'), Crypto::encrypt($token), Util::checkbox('blocked'), Util::now()]);
                }
                Audit::log('server.save', (string)Util::post('name'));
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('DELETE FROM dvr_servers WHERE id=?')->execute([$id]);
                Audit::log('server.delete', 'server_id=' . $id);
            } elseif ($action === 'check' && $id > 0) {
                $result = DvrClient::checkServer($id);
                $message = $result['message'];
            }
        }

        $edit = self::rowById('dvr_servers', (int)($_GET['edit'] ?? 0));
        $list = self::filteredRows('dvr_servers', ['name', 'base_url', 'last_check_result'], 'name ASC');
        $servers = $list['rows'];
        self::layout(self::t('servers.title', 'Серверы SesameDVR'), function () use ($edit, $servers, $message, $list) {
            self::notice($message);
            echo '<div class="admin-grid"><section class="panel"><h2>' . ($edit ? self::t('servers.edit', 'Изменить сервер') : self::t('servers.new', 'Новый сервер')) . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>Название<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>URL<input name="base_url" value="' . Util::h($edit['base_url'] ?? '') . '" placeholder="https://dvr.example.com" required></label>';
            echo '<label>' . self::t('servers.managementKey', 'Management key') . '<input name="management_token" placeholder="' . ($edit ? self::t('users.passwordPlaceholderEdit', 'оставьте пустым, чтобы не менять') : '') . '"></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('servers.blocked', 'Заблокирован') . '</label>';
            echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
            self::table(self::t('servers.title', 'Серверы'), ['name', 'base_url', 'blocked', 'last_check_result'], $servers, '/admin/servers', true, $list);
            echo '</div>';
        });
    }

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
                $controlMode = Util::post('dvr_control_mode') === 'read_only' ? 'read_only' : 'managed';
                $serverId = (int)Util::post('server_id', 0) ?: null;
                if ($selection === 'auto' && !$serverId) {
                    $serverId = self::randomActiveServerId();
                }
                $name = trim((string)Util::post('name'));
                $sourceUrl = trim((string)Util::post('source_url'));
                $stream = trim((string)Util::post('dvr_stream_name')) ?: self::slug($name);
                if ($controlMode === 'managed' && $sourceUrl === '') {
                    $message = I18n::t('cameras.sourceRequired', 'Source URL is required for full DVR management mode');
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
                        $controlMode,
                        Util::checkbox('blocked'),
                        $stream,
                    ];
                    if ($id > 0) {
                        $pdo->prepare('UPDATE cameras SET name=?, source_url=?, server_id=?, server_selection=?, latitude=?, longitude=?, direction_deg=?, view_angle_deg=?, retention_days=?, dvr_control_mode=?, blocked=?, dvr_stream_name=?, updated_at=? WHERE id=?')
                            ->execute([...$values, Util::now(), $id]);
                    } else {
                        $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, dvr_control_mode, blocked, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([...$values, Util::now(), Util::now()]);
                        $id = DB::lastInsertId('cameras');
                    }
                    self::replaceLinks('camera_groups', 'camera_id', $id, 'group_id', $_POST['group_ids'] ?? []);
                    $sync = DvrClient::syncCamera($id);
                    $message = $sync['message'];
                    Audit::log('camera.save', $name . ' mode=' . $controlMode . ' sync=' . $sync['message']);
                }
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('DELETE FROM cameras WHERE id=?')->execute([$id]);
                Audit::log('camera.delete', 'camera_id=' . $id);
            } elseif ($action === 'sync' && $id > 0) {
                $result = DvrClient::syncCamera($id);
                $message = $result['message'];
            }
        }

        $edit = self::rowById('cameras', (int)($_GET['edit'] ?? 0));
        $linkedGroups = $edit ? self::linkedIds('camera_groups', 'camera_id', (int)$edit['id'], 'group_id') : [];
        $servers = Repo::all('dvr_servers', 'name ASC');
        $groups = Repo::all('portal_groups', 'name ASC');
        $list = self::filteredCameras();
        $cameras = $list['rows'];
        self::layout(self::t('cameras.title', 'Камеры'), function () use ($edit, $servers, $groups, $linkedGroups, $cameras, $message, $list) {
            self::notice($message);
            echo '<div class="admin-grid"><section class="panel"><h2>' . ($edit ? self::t('cameras.edit', 'Изменить камеру') : self::t('cameras.new', 'Новая камера')) . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('cameras.name', 'Имя') . '<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>' . self::t('cameras.mode', 'Режим камеры') . '<select name="dvr_control_mode">';
            echo '<option value="managed" ' . (($edit['dvr_control_mode'] ?? 'managed') === 'managed' ? 'selected' : '') . '>' . self::t('cameras.modeManaged', 'Полное управление на DVR') . '</option>';
            echo '<option value="read_only" ' . (($edit['dvr_control_mode'] ?? '') === 'read_only' ? 'selected' : '') . '>' . self::t('cameras.modeReadOnly', 'Read-only поток с DVR') . '</option></select></label>';
            echo '<label>' . self::t('cameras.sourceUrl', 'URL источника') . '<input name="source_url" value="' . Util::h($edit['source_url'] ?? '') . '"></label>';
            echo '<label>' . self::t('cameras.server', 'Сервер') . '<select name="server_id"><option value="">' . self::t('cameras.serverAutoNone', 'Авто/не выбран') . '</option>';
            foreach ($servers as $server) {
                echo '<option value="' . (int)$server['id'] . '" ' . (($edit['server_id'] ?? '') == $server['id'] ? 'selected' : '') . '>' . Util::h($server['name']) . '</option>';
            }
            echo '</select></label>';
            echo '<label>' . self::t('cameras.serverSelection', 'Выбор сервера') . '<select name="server_selection"><option value="manual">' . self::t('cameras.selectionManual', 'конкретный') . '</option><option value="auto" ' . (($edit['server_selection'] ?? '') === 'auto' ? 'selected' : '') . '>' . self::t('cameras.selectionAuto', 'автоматический случайный') . '</option></select></label>';
            echo '<label>' . self::t('cameras.streamName', 'Имя потока SesameDVR') . '<input name="dvr_stream_name" value="' . Util::h($edit['dvr_stream_name'] ?? '') . '"></label>';
            $lat = $edit['latitude'] ?? '';
            $lng = $edit['longitude'] ?? '';
            echo '<div class="form-row"><label>Latitude<input id="camera-latitude" name="latitude" value="' . Util::h($lat) . '"></label><label>Longitude<input id="camera-longitude" name="longitude" value="' . Util::h($lng) . '"></label></div>';
            echo '<div class="camera-position-field"><div class="camera-position-head"><strong>' . self::t('cameras.position', 'Положение на карте') . '</strong><button type="button" class="camera-map-clear">' . self::t('cameras.clearPosition', 'Очистить точку') . '</button></div>';
            echo '<div id="camera-position-map" class="camera-position-map" data-lat="' . Util::h($lat) . '" data-lng="' . Util::h($lng) . '"></div></div>';
            echo '<div class="form-row"><label>' . self::t('cameras.direction', 'Направление') . '<input id="camera-direction" name="direction_deg" type="number" min="0" max="359" value="' . Util::h($edit['direction_deg'] ?? 0) . '"></label><label>' . self::t('cameras.viewAngle', 'Угол обзора') . '<input name="view_angle_deg" type="number" min="1" max="180" value="' . Util::h($edit['view_angle_deg'] ?? 60) . '"></label></div>';
            echo '<label>' . self::t('cameras.retention', 'Глубина архива') . '<input name="retention_days" value="' . Util::h($edit['retention_days'] ?? '7d') . '"></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('cameras.blocked', 'Заблокирована') . '</label>';
            self::checkboxList(self::t('cameras.groups', 'Группы'), 'group_ids[]', $groups, $linkedGroups, 'name');
            echo '<button class="primary">' . self::t('action.saveSync', 'Сохранить и синхронизировать') . '</button></form></section>';
            self::table(self::t('cameras.title', 'Камеры'), ['name', 'server_name', 'dvr_control_mode', 'retention_days', 'blocked', 'last_sync_ok', 'last_sync_at', 'last_sync_message'], $cameras, '/admin/cameras', true, $list);
            echo '</div>';
        });
    }

    private static function audit(): void
    {
        Auth::requireAdmin();
        $list = self::filteredAudit();
        $actions = DB::pdo()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC')->fetchAll();
        $actors = DB::pdo()->query('SELECT DISTINCT u.id, u.login FROM audit_logs a JOIN users u ON u.id = a.actor_user_id ORDER BY u.login ASC')->fetchAll();

        self::layout(self::t('audit.title', 'Журнал действий'), function () use ($list, $actions, $actors) {
            echo '<section class="panel"><div class="section-head"><h2>' . self::t('audit.title', 'Журнал действий') . '</h2></div>';
            echo '<form method="get" action="/admin/audit" class="audit-filters">';
            echo '<input name="q" value="' . Util::h($list['q']) . '" placeholder="' . self::t('audit.search', 'Поиск по действию, пользователю или деталям') . '">';
            echo '<select name="action"><option value="">' . self::t('audit.allActions', 'Все действия') . '</option>';
            foreach ($actions as $action) {
                echo '<option value="' . Util::h($action['action']) . '" ' . ($list['action'] === $action['action'] ? 'selected' : '') . '>' . Util::h($action['action']) . '</option>';
            }
            echo '</select><select name="actor"><option value="">' . self::t('audit.allUsers', 'Все пользователи') . '</option>';
            foreach ($actors as $actor) {
                echo '<option value="' . (int)$actor['id'] . '" ' . ((int)$list['actor'] === (int)$actor['id'] ? 'selected' : '') . '>' . Util::h($actor['login']) . '</option>';
            }
            echo '</select><button>' . self::t('action.show', 'Показать') . '</button></form>';
            echo '<table><thead><tr><th>' . self::t('audit.time', 'Время') . '</th><th>' . self::t('audit.user', 'Пользователь') . '</th><th>' . self::t('audit.action', 'Действие') . '</th><th>' . self::t('audit.details', 'Детали') . '</th></tr></thead><tbody>';
            foreach ($list['rows'] as $row) {
                echo '<tr><td>' . Util::h($row['created_at']) . '</td><td>' . Util::h($row['login'] ?? '-') . '</td><td><code>' . Util::h($row['action']) . '</code></td><td>';
                self::auditDetails((string)$row['details']);
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            self::pager('/admin/audit', $list, ['action' => $list['action'], 'actor' => $list['actor']]);
            echo '</section>';
        });
    }

    private static function viewer(string $mode): void
    {
        $user = Auth::requireLogin();
        $filter = (string)($_GET['filter'] ?? 'all');
        $groups = Repo::groupsForUser($user);
        $cameras = Repo::accessibleCameras($user, $filter);
        $favorites = Repo::favoritesMap((int)$user['id']);
        $token = $user['daily_token'] ?? '';

        self::layout($mode === 'map' ? 'Карта' : 'Камеры', function () use ($mode, $groups, $filter, $cameras, $favorites, $token) {
            self::filters($mode, $groups, $filter);
            if ($mode === 'map') {
                self::map($cameras, $favorites, $token);
            } else {
                self::mosaic($cameras, $favorites, $token);
            }
        });
    }

    private static function mosaic(array $cameras, array $favorites, string $token): void
    {
        echo '<section class="camera-grid">';
        foreach ($cameras as $camera) {
            $player = self::playerUrl($camera);
            $preview = self::previewUrl($camera, $token);
            echo '<article class="camera-card">';
            echo '<a class="preview' . ($preview ? '' : ' no-preview') . '" href="' . Util::h($player) . '">';
            if ($preview) {
                echo '<img src="' . Util::h($preview) . '" data-preview-src="' . Util::h($preview) . '" data-preview-refresh-ms="30000" alt="" loading="lazy">';
            }
            echo '<span class="preview-label">' . self::t('viewer.openPlayer', 'Открыть плеер') . '</span></a><div class="camera-meta"><strong>' . Util::h($camera['name']) . '</strong><span>' . Util::h($camera['server_name'] ?? self::t('common.noServer', 'Без сервера')) . '</span></div>';
            self::favoriteButton((int)$camera['id'], isset($favorites[(int)$camera['id']]));
            echo '</article>';
        }
        echo '</section>';
    }

    private static function map(array $cameras, array $favorites, string $token): void
    {
        echo '<section class="panel map-panel"><div id="map" class="map"></div></section>';
        $payload = [];
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
                'favorite' => isset($favorites[(int)$camera['id']]),
                'player' => self::playerUrl($camera),
                'preview' => self::previewUrl($camera, $token),
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
        $embed = self::embedUrl($camera, (string)($user['daily_token'] ?? ''), $back, self::t('action.back', 'Назад'));
        self::layout(self::t('player.title', 'Плеер'), function () use ($embed) {
            echo '<section class="player-page">';
            echo '<div class="player-stage"><iframe class="player-frame" src="' . Util::h($embed) . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen webkitallowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>';
            echo '</div>';
            echo '</section>';
        }, [], 'player-view', false);
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

    private static function authBackend(): void
    {
        $token = self::playbackTokenFromAuthRequest();
        $cameraName = self::cameraNameFromAuthRequest();
        $user = TokenService::userByToken($token);
        if (!$user || $cameraName === '') {
            http_response_code(403);
            echo "denied\n";
            return;
        }

        $stmt = DB::pdo()->prepare('SELECT id FROM cameras WHERE (dvr_stream_name = ? OR name = ?) AND blocked = 0 LIMIT 1');
        $stmt->execute([$cameraName, $cameraName]);
        $camera = $stmt->fetch();
        if (!$camera || !Repo::cameraAllowedForUser($user, (int)$camera['id'])) {
            http_response_code(403);
            echo "denied\n";
            return;
        }

        echo "ok\n";
    }

    private static function layout(string $title, callable $body, ?array $userOverride = [], string $bodyClass = '', bool $showChrome = true): void
    {
        $user = $userOverride === null ? null : Auth::user();
        echo '<!doctype html><html lang="' . Util::h(I18n::locale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . Util::h($title) . ' - SesamePortal</title>';
        echo '<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">';
        echo '<link rel="stylesheet" href="/assets/styles.css">';
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
        echo '</head><body' . ($bodyClass !== '' ? ' class="' . Util::h($bodyClass) . '"' : '') . '>';
        if ($user && $showChrome) {
            echo '<div class="shell"><aside class="sidebar">';
            echo '<a class="brand-logo-link" href="/"><img class="brand-logo-full" src="/assets/logo-sesameportal-inverse.svg" alt="SesamePortal"></a>';
            echo '<div class="nav-section">View</div><nav class="nav">';
            $viewerFilter = (string)($_GET['filter'] ?? 'all');
            self::navLink('/', self::t('nav.mosaic', 'Мозаика'), 'grid', Util::path() === '/' && $viewerFilter !== 'favorites');
            self::navLink('/viewer/map', self::t('nav.map', 'Карта'), 'map');
            self::navLink('/?filter=favorites', self::t('filter.favorites', 'Избранное'), 'star', ($_GET['filter'] ?? '') === 'favorites' && Util::path() === '/');
            echo '</nav>';
            if ($user['role'] === 'admin') {
                echo '<div class="nav-section">Admin</div><nav class="nav">';
                self::navLink('/admin/dashboard', self::t('nav.dashboard', 'Dashboard'), 'dashboard');
                self::navLink('/admin/users', self::t('nav.users', 'Пользователи'), 'user');
                self::navLink('/admin/groups', self::t('nav.groups', 'Группы'), 'group');
                self::navLink('/admin/cameras', self::t('nav.cameras', 'Камеры'), 'camera');
                self::navLink('/admin/servers', self::t('nav.dvr', 'DVR'), 'server');
                self::navLink('/admin/audit', self::t('nav.audit', 'Журнал'), 'audit');
                echo '</nav>';
            }
            echo '<div class="sidebar-foot">' . I18n::languageLinks() . '<a class="logout-link" href="/logout">' . self::icon('logout') . self::t('nav.logout', 'Выход') . '</a></div></aside>';
            $initial = strtoupper(substr((string)$user['login'], 0, 1) ?: 'U');
            echo '<main class="main workspace"><div class="topbar"><div><div class="crumb">SesamePortal</div><h1>' . Util::h($title) . '</h1></div><div class="user">' . Util::h($initial) . '</div></div>';
            $body();
            echo '</main></div>';
        } else {
            echo '<main class="' . ($user ? 'workspace' : 'login-page') . '">';
            $body();
            echo '</main>';
        }
        echo '<script>window.SESAME_I18N = ' . json_encode(I18n::js(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="/assets/app.js"></script>';
        echo '</body></html>';
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
            'star' => '<path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3z"/>',
            'dashboard' => '<path d="M4 13h7V4H4v9zM13 20h7V4h-7v16zM4 20h7v-5H4v5z"/>',
            'user' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M4 20a8 8 0 0 1 16 0"/>',
            'group' => '<path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM17 12a3 3 0 1 0 0-6"/><path d="M2 21a7 7 0 0 1 14 0M14 20a5 5 0 0 1 8 0"/>',
            'camera' => '<path d="M4 7h11a3 3 0 0 1 3 3v7H4V7z"/><path d="m18 11 4-3v8l-4-3"/>',
            'server' => '<path d="M4 6h16v5H4zM4 13h16v5H4z"/><path d="M8 8h.01M8 15h.01"/>',
            'audit' => '<path d="M6 3h12v18H6z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
            'logout' => '<path d="M10 4H5v16h5"/><path d="M14 8l4 4-4 4M18 12H9"/>',
        ];
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
    }

    private static function filters(string $mode, array $groups, string $filter): void
    {
        $base = $mode === 'map' ? '/viewer/map' : '/';
        echo '<section class="filters"><a class="' . ($filter === 'all' ? 'active' : '') . '" href="' . $base . '">' . self::t('filter.all', 'Все') . '</a>';
        echo '<a class="' . ($filter === 'favorites' ? 'active' : '') . '" href="' . $base . '?filter=favorites">' . self::t('filter.favorites', 'Избранное') . '</a>';
        foreach ($groups as $group) {
            $value = 'group:' . $group['id'];
            echo '<a class="' . ($filter === $value ? 'active' : '') . '" href="' . $base . '?filter=' . rawurlencode($value) . '">' . Util::h($group['name']) . '</a>';
        }
        echo '</section>';
    }

    private static function serverMetricCard(array $server): void
    {
        $metrics = json_decode((string)($server['last_metrics_json'] ?? ''), true);
        $version = is_array($metrics['version'] ?? null) ? $metrics['version'] : [];
        $status = is_array($metrics['status'] ?? null) ? $metrics['status'] : [];
        $versionText = $version['version'] ?? $version['buildId'] ?? $version['sourceCommit'] ?? 'unknown';
        $cpu = self::firstMetric($status, ['cpu.totalPercent', 'cpu.percent', 'system.cpuPercent', 'cpu']);
        $memory = self::firstMetric($status, ['memory.usedPercent', 'system.memoryUsedPercent', 'ram.usedPercent']);
        $streams = self::firstMetric($status, ['streams.total', 'streamCount', 'cameras.total']);

        echo '<article class="server-card">';
        echo '<div><strong>' . Util::h($server['name']) . '</strong><span>' . Util::h($server['base_url']) . '</span></div>';
        echo '<dl>';
        echo '<dt>' . self::t('server.version', 'Версия') . '</dt><dd>' . Util::h($versionText) . '</dd>';
        echo '<dt>CPU</dt><dd>' . Util::h($cpu ?? '-') . '</dd>';
        echo '<dt>RAM</dt><dd>' . Util::h($memory ?? '-') . '</dd>';
        echo '<dt>' . self::t('server.streams', 'Потоки') . '</dt><dd>' . Util::h($streams ?? '-') . '</dd>';
        echo '<dt>' . self::t('server.check', 'Проверка') . '</dt><dd>' . Util::h($server['last_metrics_at'] ?: $server['last_check_at'] ?: '-') . '</dd>';
        echo '</dl>';
        if (!empty($server['last_check_result'])) {
            echo '<div class="server-check-result">';
            self::technicalResult((string)$server['last_check_result']);
            echo '</div>';
        }
        self::smallPost('/admin/dashboard', ['action' => 'refresh_server', 'id' => $server['id']], self::t('action.update', 'Обновить'));
        echo '</article>';
    }

    private static function firstMetric(array $data, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = self::arrayPath($data, $path);
            if ($value !== null && $value !== '') {
                return is_float($value) ? round($value, 2) : $value;
            }
        }
        return null;
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
                $likes[] = $column . ' LIKE ?';
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
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = ' WHERE c.name LIKE ? OR c.source_url LIKE ? OR c.dvr_stream_name LIKE ? OR c.dvr_control_mode LIKE ? OR s.name LIKE ? OR c.last_sync_message LIKE ?';
            $params = array_fill(0, 6, '%' . $q . '%');
        }

        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT c.*, s.name AS server_name FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id' . $where . ' ORDER BY c.name ASC LIMIT ? OFFSET ?');
        $bind = [...$params, $pageSize, ($page - 1) * $pageSize];
        foreach ($bind as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'q' => $q];
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
            $where[] = '(a.action LIKE ? OR a.details LIKE ? OR u.login LIKE ?)';
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

        preg_match_all('/(?:^|\\s)([A-Za-z0-9_.-]+)=([^\\s]+)/', $details, $matches, PREG_SET_ORDER);
        if (!$matches) {
            echo Util::h($details);
            return;
        }

        echo '<div class="audit-details">';
        foreach ($matches as $match) {
            echo '<span><strong>' . Util::h($match[1]) . '</strong> ' . Util::h($match[2]) . '</span>';
        }
        echo '<small>' . Util::h($details) . '</small></div>';
    }

    private static function table(string $title, array $columns, array $rows, string $base, bool $actions = false, ?array $pager = null, bool $showSearch = true): void
    {
        echo '<section class="panel"><div class="section-head"><h2>' . Util::h($title) . '</h2>';
        if ($showSearch) {
            echo '<form method="get" action="' . Util::h($base) . '" class="table-search">';
            echo '<input name="q" value="' . Util::h($pager['q'] ?? '') . '" placeholder="' . self::t('table.search', 'Поиск') . '">';
            echo '<button>' . self::t('action.find', 'Найти') . '</button>';
            echo '</form>';
        }
        $tableClass = 'data-table';
        if (str_starts_with($base, '/admin/')) {
            $tableClass .= ' table-' . str_replace(['/', '_'], '-', trim(substr($base, strlen('/admin/')), '/'));
        }

        echo '</div><div class="table-wrap"><table class="' . Util::h($tableClass) . '"><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . Util::h($column) . '</th>';
        }
        echo '<th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $column) {
                self::tableCell($column, $row[$column] ?? '');
            }
            echo '<td><div class="row-actions"><a href="' . $base . '?edit=' . (int)$row['id'] . '">' . self::t('action.edit', 'Изменить') . '</a>';
            if ($actions && str_contains($base, 'servers')) {
                self::smallPost($base, ['action' => 'check', 'id' => $row['id']], self::t('action.check', 'Проверить'));
            }
            if ($actions && str_contains($base, 'cameras')) {
                self::smallPost($base, ['action' => 'sync', 'id' => $row['id']], 'Sync');
            }
            self::smallPost($base, ['action' => 'delete', 'id' => $row['id']], self::t('action.delete', 'Удалить'), 'danger');
            if ($base === '/admin/users') {
                self::smallPost($base, ['action' => 'issue_static', 'id' => $row['id']], 'Static token');
                self::smallPost($base, ['action' => 'revoke_static', 'id' => $row['id']], 'Revoke');
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
        if ($pager) {
            self::pager($base, $pager);
        }
        echo '</section>';
    }

    private static function tableCell(string $column, mixed $value): void
    {
        if (in_array($column, ['last_check_result', 'last_sync_message'], true)) {
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

        echo '<td>' . Util::h($value) . '</td>';
    }

    private static function technicalResult(string $text): void
    {
        echo '<details class="technical-result"><summary>' . Util::h(self::technicalSummary($text)) . '</summary><pre>' . Util::h($text) . '</pre></details>';
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

    private static function pager(string $base, array $pager, array $extraParams = []): void
    {
        $pages = (int)ceil(max(1, (int)$pager['total']) / max(1, (int)$pager['pageSize']));
        if ($pages <= 1) {
            echo '<div class="pager-note">' . self::t('table.shown', 'Показано') . ' ' . Util::h(count($pager['rows'])) . ' ' . self::t('table.of', 'из') . ' ' . Util::h($pager['total']) . '</div>';
            return;
        }

        echo '<nav class="pager">';
        for ($page = 1; $page <= $pages; $page++) {
            $query = http_build_query(array_filter([
                'q' => $pager['q'] ?? '',
                'page' => $page,
                ...$extraParams,
            ], fn($value) => $value !== '' && $value !== null && $value !== 0));
            echo '<a class="' . ((int)$pager['page'] === $page ? 'active' : '') . '" href="' . Util::h($base . ($query ? '?' . $query : '')) . '">' . $page . '</a>';
        }
        echo '</nav>';
    }

    private static function smallPost(string $path, array $fields, string $label, string $class = ''): void
    {
        echo '<form method="post" action="' . Util::h($path) . '" class="inline-form">' . Csrf::field();
        foreach ($fields as $key => $value) {
            echo '<input type="hidden" name="' . Util::h($key) . '" value="' . Util::h($value) . '">';
        }
        echo '<button class="' . Util::h($class) . '">' . Util::h($label) . '</button></form>';
    }

    private static function checkboxList(string $title, string $name, array $rows, array $selected, string $labelKey): void
    {
        echo '<fieldset><legend>' . Util::h($title) . '</legend><div class="check-list">';
        foreach ($rows as $row) {
            echo '<label class="check"><input type="checkbox" name="' . Util::h($name) . '" value="' . (int)$row['id'] . '" ' . (in_array((int)$row['id'], $selected, true) ? 'checked' : '') . '> ' . Util::h($row[$labelKey]) . '</label>';
        }
        echo '</div></fieldset>';
    }

    private static function favoriteButton(int $cameraId, bool $isFavorite): void
    {
        echo '<form method="post" action="/favorite/toggle" class="favorite-form">' . Csrf::field();
        echo '<input type="hidden" name="camera_id" value="' . $cameraId . '">';
        echo '<button title="Избранное" class="' . ($isFavorite ? 'favorite active' : 'favorite') . '">' . ($isFavorite ? '★' : '☆') . '</button></form>';
    }

    private static function notice(string $message): void
    {
        if ($message !== '') {
            echo '<div class="alert">' . Util::h($message) . '</div>';
        }
    }

    private static function embedUrl(array $camera, string $token, string $back = '', string $backLabel = ''): string
    {
        if (empty($camera['server_url'])) {
            return '#';
        }

        $query = [
            'dvr' => 'true',
            'token' => $token,
        ];
        if ($back !== '') {
            $query['back_url'] = self::absolutePortalUrl($back);
            $query['back_label'] = $backLabel !== '' ? $backLabel : self::t('action.back', 'Назад');
        }

        return rtrim($camera['server_url'], '/') . '/' . rawurlencode($camera['dvr_stream_name']) . '/embed.html?' . http_build_query($query);
    }

    private static function playerUrl(array $camera): string
    {
        $back = self::safeBackPath((string)($_SERVER['REQUEST_URI'] ?? '/'));
        return '/viewer/player?' . http_build_query([
            'id' => (int)$camera['id'],
            'back' => $back,
        ]);
    }

    private static function safeBackPath(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parts = parse_url($path);
            $path = (string)($parts['path'] ?? '/');
            if (!empty($parts['query'])) {
                $path .= '?' . $parts['query'];
            }
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return parse_url($path, PHP_URL_PATH) === '/viewer/player' ? '/' : $path;
    }

    private static function absolutePortalUrl(string $path): string
    {
        $base = trim((string)Config::get('base_url', ''));
        if ($base === '') {
            $scheme = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
            if ($scheme === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            }
            $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
            if ($host === '') {
                return $path;
            }
            $base = $scheme . '://' . $host;
        }

        return rtrim($base, '/') . $path;
    }

    private static function previewUrl(array $camera, string $token): string
    {
        if (empty($camera['server_url'])) {
            return '';
        }
        return rtrim($camera['server_url'], '/') . '/' . rawurlencode($camera['dvr_stream_name']) . '/preview.jpg?token=' . rawurlencode($token);
    }

    private static function playbackTokenFromAuthRequest(): string
    {
        foreach (['token', 'auth_token', 'playback_token'] as $key) {
            $token = self::usableAuthValue($_GET[$key] ?? '');
            if ($token !== '') {
                return $token;
            }
        }

        $qs = self::usableAuthValue($_GET['qs'] ?? '');
        if ($qs !== '') {
            parse_str($qs, $params);
            foreach (['token', 'auth_token', 'playback_token'] as $key) {
                $token = self::usableAuthValue($params[$key] ?? '');
                if ($token !== '') {
                    return $token;
                }
            }
        }

        foreach (['uri', 'path', 'request_uri'] as $key) {
            $query = parse_url((string)($_GET[$key] ?? ''), PHP_URL_QUERY);
            if (!$query) {
                continue;
            }
            parse_str($query, $params);
            foreach (['token', 'auth_token', 'playback_token'] as $tokenKey) {
                $token = self::usableAuthValue($params[$tokenKey] ?? '');
                if ($token !== '') {
                    return $token;
                }
            }
        }

        return '';
    }

    private static function usableAuthValue(mixed $value): string
    {
        $value = trim((string)$value);
        return $value === '' || $value === 'NonAvailable' ? '' : $value;
    }

    private static function cameraNameFromAuthRequest(): string
    {
        foreach (['camera', 'stream', 'name'] as $key) {
            if (!empty($_GET[$key])) {
                return basename((string)$_GET[$key]);
            }
        }

        foreach (['uri', 'path', 'request_uri'] as $key) {
            if (!empty($_GET[$key])) {
                $path = trim((string)parse_url((string)$_GET[$key], PHP_URL_PATH), '/');
                return basename(explode('/', $path)[0] ?? '');
            }
        }

        return '';
    }

    private static function rowById(string $table, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = DB::pdo()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function replaceLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        $pdo = DB::pdo();
        $pdo->prepare("DELETE FROM {$table} WHERE {$ownerKey} = ?")->execute([$ownerId]);
        $stmt = $pdo->prepare(DB::insertIgnoreSql($table, [$ownerKey, $targetKey]));
        foreach ($values as $value) {
            $stmt->execute([$ownerId, (int)$value]);
        }
    }

    private static function linkedIds(string $table, string $ownerKey, int $ownerId, string $targetKey): array
    {
        $stmt = DB::pdo()->prepare("SELECT {$targetKey} FROM {$table} WHERE {$ownerKey} = ?");
        $stmt->execute([$ownerId]);
        return array_map('intval', array_column($stmt->fetchAll(), $targetKey));
    }

    private static function randomActiveServerId(): ?int
    {
        $servers = DB::pdo()->query('SELECT id FROM dvr_servers WHERE blocked = 0 ORDER BY ' . DB::randomOrderSql() . ' LIMIT 1')->fetchAll();
        return $servers ? (int)$servers[0]['id'] : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        $value = trim((string)$value);
        return $value === '' ? null : (float)$value;
    }

    private static function slug(string $value): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value));
        return trim((string)$slug, '_') ?: 'camera_' . random_int(1000, 9999);
    }
}

final class Cli
{
    private const BACKUP_TABLES = [
        'users',
        'portal_groups',
        'user_groups',
        'dvr_servers',
        'cameras',
        'camera_groups',
        'favorites',
        'audit_logs',
    ];

    public static function run(array $argv): void
    {
        DB::migrate();
        $command = $argv[1] ?? 'help';

        if ($command === 'migrate') {
            echo "migrated\n";
            return;
        }

        if ($command === 'create-admin') {
            $login = $argv[2] ?? '';
            $password = $argv[3] ?? '';
            if ($login === '' || strlen($password) < 6) {
                fwrite(STDERR, "usage: php bin/portal create-admin <login> <password-min-6>\n");
                exit(2);
            }
            $pdo = DB::pdo();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE login = ?');
            $stmt->execute([$login]);
            if ($stmt->fetch()) {
                $pdo->prepare('UPDATE users SET password_hash=?, role=?, blocked=0 WHERE login=?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), 'admin', $login]);
            } else {
                $pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, daily_token, daily_token_date, created_at) VALUES(?, ?, ?, 0, ?, ?, ?)')
                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), 'admin', Util::randomToken(), TokenService::today(), Util::now()]);
            }
            echo "admin ready: {$login}\n";
            return;
        }

        if ($command === 'rotate-tokens') {
            $count = TokenService::rotateAll();
            echo "rotated {$count} users\n";
            return;
        }

        if ($command === 'rotate-secrets') {
            $count = self::rotateSecrets();
            echo "rotated {$count} encrypted secrets\n";
            return;
        }

        if ($command === 'backup') {
            $path = $argv[2] ?? '';
            if ($path === '') {
                fwrite(STDERR, "usage: php bin/portal backup <out.json>\n");
                exit(2);
            }
            self::backup($path);
            echo "backup written: {$path}\n";
            return;
        }

        if ($command === 'restore') {
            $path = $argv[2] ?? '';
            if ($path === '' || !is_file($path)) {
                fwrite(STDERR, "usage: php bin/portal restore <in.json>\n");
                exit(2);
            }
            self::restore($path);
            echo "backup restored: {$path}\n";
            return;
        }

        echo "commands: migrate, create-admin, rotate-tokens, rotate-secrets, backup, restore\n";
    }

    private static function rotateSecrets(): int
    {
        $pdo = DB::pdo();
        $rows = $pdo->query('SELECT id, management_token_enc FROM dvr_servers WHERE management_token_enc IS NOT NULL AND management_token_enc != ""')->fetchAll();
        $stmt = $pdo->prepare('UPDATE dvr_servers SET management_token_enc = ? WHERE id = ?');
        $count = 0;
        foreach ($rows as $row) {
            $encoded = (string)$row['management_token_enc'];
            if (!Crypto::needsRotation($encoded)) {
                continue;
            }

            $plain = Crypto::decrypt($encoded);
            if ($plain === '') {
                continue;
            }

            $stmt->execute([Crypto::encrypt($plain), (int)$row['id']]);
            $count++;
        }

        return $count;
    }

    private static function backup(string $path): void
    {
        $pdo = DB::pdo();
        $data = [
            'format' => 'sesame-portal-backup-v1',
            'createdAt' => Util::now(),
            'tables' => [],
        ];
        foreach (self::BACKUP_TABLES as $table) {
            $data['tables'][$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
        }

        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        chmod($path, 0600);
    }

    private static function restore(string $path): void
    {
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'sesame-portal-backup-v1') {
            throw new RuntimeException('invalid_backup_format');
        }

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::setForeignKeys(false);
            foreach (array_reverse(self::BACKUP_TABLES) as $table) {
                $pdo->exec('DELETE FROM ' . $table);
            }
            foreach (self::BACKUP_TABLES as $table) {
                foreach (($data['tables'][$table] ?? []) as $row) {
                    if (!is_array($row) || $row === []) {
                        continue;
                    }
                    $columns = array_keys($row);
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                    $sql = 'INSERT INTO ' . $table . '(' . implode(', ', $columns) . ') VALUES(' . $placeholders . ')';
                    $pdo->prepare($sql)->execute(array_values($row));
                }
            }
            DB::setForeignKeys(true);
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            DB::setForeignKeys(true);
            throw $error;
        }
    }
}
