<?php

declare(strict_types=1);

namespace SesamePortal;

use DateTimeImmutable;
use DateTimeInterface;
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
            'portal_update_enabled' => getenv('SESAME_PORTAL_UPDATE_ENABLED') !== '0',
            'portal_update_github_repo' => getenv('SESAME_PORTAL_UPDATE_GITHUB_REPO') ?: 'rosteleset/SesamePortal',
            'portal_update_github_ref' => getenv('SESAME_PORTAL_UPDATE_GITHUB_REF') ?: 'main',
            'portal_update_github_token' => getenv('SESAME_PORTAL_GITHUB_TOKEN') ?: '',
            'portal_update_check_ttl_seconds' => (int)(getenv('SESAME_PORTAL_UPDATE_CHECK_TTL_SECONDS') ?: 600),
            'portal_update_auto_check' => getenv('SESAME_PORTAL_UPDATE_AUTO_CHECK') !== '0',
            'portal_update_command' => getenv('SESAME_PORTAL_UPDATE_COMMAND') ?: 'sudo -n /usr/local/sbin/sesame-portal-update',
            'portal_update_pass_args' => getenv('SESAME_PORTAL_UPDATE_PASS_ARGS') === '1',
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
            self::registerSqliteFunctions($pdo);
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    private static function registerSqliteFunctions(PDO $pdo): void
    {
        if (!method_exists($pdo, 'sqliteCreateFunction')) {
            return;
        }

        $flags = defined('PDO::SQLITE_DETERMINISTIC') ? PDO::SQLITE_DETERMINISTIC : 0;
        $pdo->sqliteCreateFunction(
            'sesame_portal_lower',
            static function (mixed $value): string {
                $text = (string)($value ?? '');
                return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
            },
            1,
            $flags
        );
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

        self::ensureColumn('portal_groups', 'parent_group_id', self::driver() === 'mysql' ? 'BIGINT NULL' : 'INTEGER');
        self::dropPortalGroupNameUniqueConstraint();
        self::ensureIndex('camera_groups', 'idx_camera_groups_group', 'group_id');
        self::ensureIndex('user_groups', 'idx_user_groups_group', 'group_id');
        self::ensureIndex('portal_groups', 'idx_portal_groups_parent', 'parent_group_id');
        self::ensureIndex('favorites', 'idx_favorites_user', 'user_id');
        self::ensureColumn('dvr_servers', 'last_metrics_at', 'TEXT');
        self::ensureColumn('dvr_servers', 'last_metrics_json', 'TEXT');
        self::ensureColumn('cameras', 'last_sync_at', 'TEXT');
        self::ensureColumn('cameras', 'last_sync_ok', 'INTEGER');
        self::ensureColumn('cameras', 'last_sync_message', 'TEXT');
        self::ensureColumn('cameras', 'dvr_control_mode', self::driver() === 'mysql' ? "VARCHAR(32) NOT NULL DEFAULT 'managed'" : "TEXT NOT NULL DEFAULT 'managed'");
        self::ensureColumn('cameras', 'agent_id', self::driver() === 'mysql' ? 'VARCHAR(255)' : 'TEXT');
        self::ensureColumn('cameras', 'agent_camera_id', self::driver() === 'mysql' ? 'VARCHAR(255)' : 'TEXT');
        self::ensureColumn('cameras', 'onvif_events_requested', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('cameras', 'watermark_enabled', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('cameras', 'watermark_intensity', 'INTEGER NOT NULL DEFAULT 16');
        self::ensureIndex('cameras', 'idx_cameras_agent_id', 'agent_id');
        self::ensureIndex('cameras', 'idx_cameras_agent_camera_id', 'agent_camera_id');
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

    public static function caseInsensitiveLike(string $column): string
    {
        return match (self::driver()) {
            'pgsql' => $column . ' ILIKE ?',
            'sqlite' => 'sesame_portal_lower(COALESCE(' . $column . ", '')) LIKE sesame_portal_lower(?)",
            default => 'LOWER(COALESCE(' . $column . ", '')) LIKE LOWER(?)",
        };
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
                parent_group_id INTEGER REFERENCES portal_groups(id) ON DELETE SET NULL,
                name TEXT NOT NULL,
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
                agent_id TEXT,
                agent_camera_id TEXT,
                onvif_events_requested INTEGER NOT NULL DEFAULT 0,
                watermark_enabled INTEGER NOT NULL DEFAULT 0,
                watermark_intensity INTEGER NOT NULL DEFAULT 16,
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
                parent_group_id BIGINT REFERENCES portal_groups(id) ON DELETE SET NULL,
                name TEXT NOT NULL,
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
                agent_id TEXT,
                agent_camera_id TEXT,
                onvif_events_requested INTEGER NOT NULL DEFAULT 0,
                watermark_enabled INTEGER NOT NULL DEFAULT 0,
                watermark_intensity INTEGER NOT NULL DEFAULT 16,
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
                parent_group_id BIGINT,
                name VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at VARCHAR(64) NOT NULL,
                CONSTRAINT fk_portal_groups_parent FOREIGN KEY (parent_group_id) REFERENCES portal_groups(id) ON DELETE SET NULL
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
                agent_id VARCHAR(255),
                agent_camera_id VARCHAR(255),
                onvif_events_requested INTEGER NOT NULL DEFAULT 0,
                watermark_enabled INTEGER NOT NULL DEFAULT 0,
                watermark_intensity INTEGER NOT NULL DEFAULT 16,
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

    private static function dropPortalGroupNameUniqueConstraint(): void
    {
        match (self::driver()) {
            'pgsql' => self::dropPgsqlPortalGroupNameUniqueConstraint(),
            'mysql' => self::dropMysqlPortalGroupNameUniqueConstraint(),
            default => self::dropSqlitePortalGroupNameUniqueConstraint(),
        };
    }

    private static function dropSqlitePortalGroupNameUniqueConstraint(): void
    {
        $pdo = self::pdo();
        if (!self::sqliteUniqueIndexOnColumns('portal_groups', ['name'])) {
            return;
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $pdo->beginTransaction();
            $pdo->exec('DROP TABLE IF EXISTS portal_groups_migration');
            $pdo->exec('CREATE TABLE portal_groups_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_group_id INTEGER REFERENCES portal_groups(id) ON DELETE SET NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )');
            $pdo->exec('INSERT INTO portal_groups_migration(id, parent_group_id, name, description, blocked, created_at)
                SELECT id, parent_group_id, name, description, blocked, created_at FROM portal_groups');
            $pdo->exec('DROP TABLE portal_groups');
            $pdo->exec('ALTER TABLE portal_groups_migration RENAME TO portal_groups');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    private static function sqliteUniqueIndexOnColumns(string $table, array $columns): bool
    {
        $pdo = self::pdo();
        $indexes = $pdo->query('PRAGMA index_list(' . self::quoteIdentifier($table) . ')')->fetchAll();
        foreach ($indexes as $index) {
            if ((int)($index['unique'] ?? 0) !== 1) {
                continue;
            }

            $indexName = (string)($index['name'] ?? '');
            if ($indexName === '') {
                continue;
            }

            $info = $pdo->query('PRAGMA index_info(' . self::quoteIdentifier($indexName) . ')')->fetchAll();
            $indexColumns = array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $info);
            if ($indexColumns === $columns) {
                return true;
            }
        }
        return false;
    }

    private static function dropPgsqlPortalGroupNameUniqueConstraint(): void
    {
        $pdo = self::pdo();
        $constraints = $pdo->query(
            "SELECT c.conname, string_agg(a.attname, ',' ORDER BY u.ord) AS columns
             FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN unnest(c.conkey) WITH ORDINALITY AS u(attnum, ord) ON true
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = u.attnum
             WHERE n.nspname = current_schema()
               AND t.relname = 'portal_groups'
               AND c.contype = 'u'
             GROUP BY c.conname"
        )->fetchAll();

        foreach ($constraints as $constraint) {
            if (($constraint['columns'] ?? '') === 'name') {
                $pdo->exec('ALTER TABLE portal_groups DROP CONSTRAINT ' . self::quoteIdentifier((string)$constraint['conname']));
            }
        }

        $indexes = $pdo->query(
            "SELECT i.relname AS index_name, string_agg(a.attname, ',' ORDER BY u.ord) AS columns
             FROM pg_index ix
             JOIN pg_class t ON t.oid = ix.indrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN unnest(ix.indkey) WITH ORDINALITY AS u(attnum, ord) ON true
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = u.attnum
             WHERE n.nspname = current_schema()
               AND t.relname = 'portal_groups'
               AND ix.indisunique
               AND NOT ix.indisprimary
             GROUP BY i.relname"
        )->fetchAll();

        foreach ($indexes as $index) {
            if (($index['columns'] ?? '') === 'name') {
                $pdo->exec('DROP INDEX IF EXISTS ' . self::quoteIdentifier((string)$index['index_name']));
            }
        }
    }

    private static function dropMysqlPortalGroupNameUniqueConstraint(): void
    {
        $stmt = self::pdo()->prepare(
            "SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'portal_groups'
               AND non_unique = 0
               AND index_name <> 'PRIMARY'
             GROUP BY index_name"
        );
        $stmt->execute();

        foreach ($stmt->fetchAll() as $index) {
            if (($index['columns'] ?? '') === 'name') {
                self::pdo()->exec('ALTER TABLE portal_groups DROP INDEX ' . self::quoteIdentifier((string)$index['index_name']));
            }
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new RuntimeException('Invalid database identifier');
        }

        $quote = self::driver() === 'mysql' ? '`' : '"';
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
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
    public const DVR_STREAM_NAME_HTML_PATTERN = '[A-Za-z0-9_-]+';
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
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = trim((string)$slug, '-_');

        if ($slug === '') {
            $slug = 'camera-' . substr(hash('sha256', $source), 0, 12);
        }

        if (strlen($slug) > self::DVR_STREAM_NAME_MAX_BYTES) {
            $slug = trim(substr($slug, 0, self::DVR_STREAM_NAME_MAX_BYTES), '-_');
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

final class I18n
{
    private const LOCALES = [
        'ru' => ['short' => 'RU', 'native' => 'Русский', 'locale' => 'ru-RU', 'dir' => 'ltr'],
        'en' => ['short' => 'EN', 'native' => 'English', 'locale' => 'en-US', 'dir' => 'ltr'],
        'de' => ['short' => 'DE', 'native' => 'Deutsch', 'locale' => 'de-DE', 'dir' => 'ltr'],
        'fr' => ['short' => 'FR', 'native' => 'Français', 'locale' => 'fr-FR', 'dir' => 'ltr'],
        'es' => ['short' => 'ES', 'native' => 'Español', 'locale' => 'es-ES', 'dir' => 'ltr'],
        'it' => ['short' => 'IT', 'native' => 'Italiano', 'locale' => 'it-IT', 'dir' => 'ltr'],
        'pt' => ['short' => 'PT', 'native' => 'Português', 'locale' => 'pt-PT', 'dir' => 'ltr'],
        'bg' => ['short' => 'BG', 'native' => 'Български', 'locale' => 'bg-BG', 'dir' => 'ltr'],
        'pl' => ['short' => 'PL', 'native' => 'Polski', 'locale' => 'pl-PL', 'dir' => 'ltr'],
        'zh' => ['short' => 'ZH', 'native' => '中文', 'locale' => 'zh-CN', 'dir' => 'ltr'],
        'ja' => ['short' => 'JA', 'native' => '日本語', 'locale' => 'ja-JP', 'dir' => 'ltr'],
        'ko' => ['short' => 'KO', 'native' => '한국어', 'locale' => 'ko-KR', 'dir' => 'ltr'],
        'ar' => ['short' => 'AR', 'native' => 'العربية', 'locale' => 'ar', 'dir' => 'rtl'],
        'hy' => ['short' => 'HY', 'native' => 'Հայերեն', 'locale' => 'hy-AM', 'dir' => 'ltr'],
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

    public static function htmlLocale(): string
    {
        return self::LOCALES[self::locale()]['locale'];
    }

    public static function dir(): string
    {
        return self::LOCALES[self::locale()]['dir'];
    }

    public static function t(string $key, string $fallback): string
    {
        return self::messages()[self::locale()][$key] ?? $fallback;
    }

    public static function js(): array
    {
        return [
            'openVideo' => self::t('js.openVideo', 'Открыть видео'),
            'openPlayer' => self::t('viewer.openPlayer', 'Открыть плеер'),
            'previewUnavailable' => self::t('js.previewUnavailable', 'Превью недоступно'),
            'streamUnavailable' => self::t('js.streamUnavailable', 'Поток недоступен'),
            'mapChangePending' => self::t('js.mapChangePending', 'Подтвердите изменение на карте'),
            'favorite' => self::t('filter.favorites', 'Избранное'),
            'addFavorite' => self::t('js.addFavorite', 'Добавить в избранное'),
            'removeFavorite' => self::t('js.removeFavorite', 'Удалить из избранного'),
            'selectedCount' => self::t('js.selectedCount', 'Выбрано'),
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
        unset($params['lang']);
        $labelText = self::t('language.label', 'Язык');
        $html = '<label class="locale-switch"><span class="sr-only">' . Util::h($labelText) . '</span><select name="lang" aria-label="' . Util::h($labelText) . '" onchange="if (this.value) window.location.href = this.value">';
        foreach (self::LOCALES as $locale => $meta) {
            $next = $params;
            $next['lang'] = $locale;
            $href = $path . '?' . http_build_query($next);
            $label = $meta['short'] . ' - ' . $meta['native'];
            $html .= '<option value="' . Util::h($href) . '"' . ($current === $locale ? ' selected' : '') . '>' . Util::h($label) . '</option>';
        }
        return $html . '</select></label>';
    }

    private static function messages(): array
    {
        $messages = [
            'ru' => [
                'language.label' => 'Язык',
                'login.feature.secure' => 'Безопасно',
                'login.feature.reliable' => 'Надежно',
                'login.feature.efficient' => 'Производительно',
                'groups.users' => 'Пользователи',
                'groups.cameras' => 'Камеры',
                'groups.parent' => 'Родительская группа',
                'groups.noParent' => 'Без родителя',
                'groups.nameRequired' => 'Название группы обязательно',
                'groups.parentNotFound' => 'Родительская группа не найдена',
                'groups.parentCycle' => 'Родительской группой нельзя выбрать саму группу или её подгруппу',
                'groups.selectAll' => 'Выбрать все',
                'groups.clearAll' => 'Снять все',
                'nav.agents' => 'Edge Agents',
                'nav.settings' => 'Настройки',
                'settings.title' => 'Настройки',
                'settings.portalUpdates' => 'Обновления Portal',
                'settings.updateHint' => 'Portal сравнивает текущую сборку с последним commit выбранной ветки GitHub.',
                'settings.currentVersion' => 'Текущая версия',
                'settings.githubVersion' => 'Доступная версия на GitHub',
                'settings.githubRepo' => 'GitHub repository',
                'settings.githubRef' => 'GitHub branch/ref',
                'settings.checkedAt' => 'Проверено',
                'settings.updateTool' => 'Update tool',
                'settings.toolInstalled' => 'установлен',
                'settings.toolMissing' => 'не установлен',
                'settings.checkError' => 'Ошибка проверки',
                'settings.checkUpdates' => 'Проверить обновления',
                'settings.installUpdate' => 'Обновить Portal',
                'settings.updateConfirm' => 'Обновить код Portal из GitHub и выполнить миграции?',
                'settings.updateDisabled' => 'обновления отключены',
                'settings.noUpdateAvailable' => 'нет доступного обновления',
                'settings.updateAvailable' => 'Доступно обновление',
                'settings.upToDate' => 'актуально',
                'settings.notChecked' => 'не проверено',
                'settings.versionUnknown' => 'неизвестно',
                'settings.checkDone' => 'Проверка обновлений выполнена',
                'settings.checkFailed' => 'Проверка обновлений не выполнена',
                'settings.updateDone' => 'Обновление Portal выполнено',
                'settings.updateFailed' => 'Обновление Portal не выполнено',
                'settings.updateOutputOk' => 'Вывод updater',
                'settings.updateOutputFailed' => 'Вывод updater с ошибкой',
                'column.id' => 'ID',
                'column.name' => 'Название',
                'column.parent_group_name' => 'Родитель',
                'column.server_name' => 'Сервер',
                'column.agent_id' => 'Agent',
                'column.agent_camera_id' => 'Камера агента',
                'column.last_sync_ok' => 'Синхронизация',
                'column.last_sync_at' => 'Время синхронизации',
                'column.last_sync_message' => 'Результат',
                'column.login' => 'Логин',
                'column.role' => 'Роль',
                'column.blocked' => 'Блокировка',
                'column.last_login_at' => 'Последний вход',
                'column.description' => 'Описание',
                'column.base_url' => 'URL',
                'column.last_check_result' => 'Проверка',
                'column.dvr_control_mode' => 'Режим',
                'column.retention_days' => 'Архив',
                'viewer.columnsPerRow' => 'Камер в ряду',
                'viewer.previewRefresh' => 'Обновление превью',
                'viewer.refreshOff' => 'Отключено',
                'viewer.refreshSeconds' => '%d сек.',
                'filter.expandGroup' => 'Раскрыть группу %s',
                'filter.collapseGroup' => 'Свернуть группу %s',
                'filter.cameraSearchPlaceholder' => 'Название камеры или потока',
                'filter.clearSearch' => 'Сбросить поиск',
                'action.find' => 'Найти',
                'js.streamUnavailable' => 'Поток недоступен',
                'cameras.name' => 'Имя',
                'cameras.displayName' => 'Название потока',
                'cameras.streamName' => 'Техническое имя потока',
                'cameras.nameOrStreamRequired' => 'Укажите название потока или техническое имя потока',
                'cameras.invalidStreamName' => 'Техническое имя потока может содержать только латинские буквы, цифры, дефис и подчёркивание, до 128 символов.',
                'cameras.streamNameHint' => 'Только A-Z, a-z, 0-9, дефис и подчёркивание. Если оставить пустым, портал создаст имя сам.',
                'cameras.saveDone' => 'Камера сохранена',
                'cameras.saveSyncFailed' => 'Камера сохранена, но синхронизация с DVR не выполнена',
                'cameras.syncDone' => 'Синхронизация выполнена',
                'cameras.syncFailed' => 'Синхронизация не выполнена',
                'cameras.deleteTitle' => 'Удалить камеру',
                'cameras.deleteMissing' => 'Камера уже удалена или не найдена',
                'cameras.deleteConfirmRequired' => 'Подтвердите удаление камеры',
                'cameras.deleteDvrFailed' => 'Поток на DVR не удалён',
                'cameras.deleteDone' => 'Камера удалена',
                'cameras.deleteWarning' => 'Это действие нельзя отменить.',
                'cameras.deleteWarningText' => 'Сначала подтвердите удаление камеры из портала. Отдельным флажком можно удалить связанный поток на DVR вместе с архивом.',
                'cameras.confirmDelete' => 'Подтверждаю удаление камеры из портала',
                'cameras.deleteDvrStream' => 'Также удалить поток на DVR и очистить архив, превью и индексы',
                'cameras.deleteDvrUnavailable' => 'Удаление потока на DVR недоступно для этой камеры: проверьте сервер, token управления и режим управления.',
                'cameras.deleteDvrReadOnly' => 'Read-only камера: удаление потока на DVR недоступно',
                'cameras.modeEdgeAgent' => 'Edge Agent push stream',
                'cameras.agentId' => 'Agent ID',
                'cameras.agentCameraId' => 'Agent camera ID',
                'cameras.onvifEvents' => 'Запускать ONVIF events через агента',
                'cameras.watermarkEnabled' => 'Показывать водяной знак с логином в плеере',
                'cameras.watermarkIntensity' => 'Интенсивность водяного знака, %',
                'cameras.agentRequired' => 'Для edge-agent режима нужны сервер, Agent ID и Agent camera ID',
                'cameras.agentOverwriteBlocked' => 'Поток на DVR уже управляется Edge Agent. Переключите камеру Portal в режим Edge Agent или read-only, чтобы не перезаписать push-конфиг.',
                'agents.title' => 'Edge-агенты',
                'agents.new' => 'Новый агент',
                'agents.serverRequired' => 'Выберите SesameDVR сервер',
                'agents.agentId' => 'Agent ID',
                'agents.agentName' => 'Название агента',
                'agents.password' => 'Пароль enrollment',
                'agents.capabilities' => 'Возможности',
                'agents.enabled' => 'Включён',
                'agents.create' => 'Создать агента',
                'agents.setPassword' => 'Задать пароль',
                'agents.revoke' => 'Отозвать секрет',
                'agents.rotateSecret' => 'Сменить секрет',
                'agents.scan' => 'Сканировать ONVIF',
                'agents.diagnostics' => 'Диагностика',
                'agents.logs' => 'Журнал',
                'agents.commands' => 'Команды',
                'agents.cameras' => 'Камеры агента',
                'agents.command' => 'Команда',
                'agents.payload' => 'Payload JSON',
                'agents.sendCommand' => 'Отправить команду',
                'agents.useCamera' => 'Создать камеру в Portal',
                'agents.snapshot' => 'Snapshot',
                'agents.noServer' => 'Сначала добавьте SesameDVR сервер с management token.',
                'agents.noAgents' => 'Агенты не найдены',
                'agents.newSecret' => 'Новый секрет агента',
                'agents.loaded' => 'Загружено агентов',
                'agents.details' => 'Технические детали',
                'agents.actions' => 'Действия',
                'agents.settings' => 'Настройки',
                'agents.enrollment' => 'Первичная привязка',
                'agents.commandConsole' => 'Консоль команд',
                'agents.lastCommands' => 'Последние команды',
                'agents.lastLogs' => 'Последние записи журнала',
                'agents.technicalData' => 'Технические данные',
                'agents.actionQueued' => 'Команда поставлена в очередь',
                'agents.actionCompleted' => 'Операция выполнена',
                'agents.actionFailed' => 'Операция не выполнена',
                'agents.createHint' => 'Создание агента нужно только перед первичной установкой edge-устройства.',
                'agents.unknown' => 'неизвестно',
                'agents.media' => 'Медиа',
                'agents.onvif' => 'ONVIF',
                'agents.version' => 'Версия',
                'agents.lastSeen' => 'Последняя связь',
                'agents.cameraCount' => 'Камеры',
                'agents.mediaSessions' => 'Медиа-сессии',
                'agents.source' => 'Источник',
                'agents.timeout' => 'Таймаут, мс',
                'agents.yes' => 'да',
                'agents.no' => 'нет',
                'agents.running' => 'работает',
                'agents.stopped' => 'остановлен',
                'agents.online' => 'online',
                'agents.offline' => 'offline',
            ],
            'en' => [
                'language.label' => 'Language',
                'nav.mosaic' => 'Mosaic',
                'nav.map' => 'Map',
                'nav.dashboard' => 'Dashboard',
                'nav.users' => 'Users',
                'nav.groups' => 'Groups',
                'nav.cameras' => 'Cameras',
                'nav.dvr' => 'DVR',
                'nav.agents' => 'Edge Agents',
                'nav.audit' => 'Audit',
                'nav.settings' => 'Settings',
                'nav.logout' => 'Logout',
                'login.title' => 'Sign In',
                'login.subtitle' => 'SesameWare video surveillance portal',
                'login.feature.secure' => 'Secure',
                'login.feature.reliable' => 'Reliable',
                'login.feature.efficient' => 'Efficient',
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
                'filter.groupSelect' => 'Group',
                'filter.groupSelectPlaceholder' => 'Select group',
                'filter.noGroups' => 'No groups found',
                'filter.expandGroup' => 'Expand group %s',
                'filter.collapseGroup' => 'Collapse group %s',
                'filter.cameraSearchPlaceholder' => 'Camera or stream name',
                'filter.clearSearch' => 'Clear search',
                'viewer.openPlayer' => 'Open player',
                'viewer.columnsPerRow' => 'Cameras per row',
                'viewer.previewRefresh' => 'Preview refresh',
                'viewer.refreshOff' => 'Off',
                'viewer.refreshSeconds' => '%d sec.',
                'player.title' => 'Player',
                'player.fullscreen' => 'Fullscreen',
                'player.collapse' => 'Exit fullscreen',
                'js.openVideo' => 'Open video',
                'js.previewUnavailable' => 'Preview unavailable',
                'js.streamUnavailable' => 'Stream unavailable',
                'js.mapChangePending' => 'Confirm map change',
                'js.addFavorite' => 'Add to favorites',
                'js.removeFavorite' => 'Remove from favorites',
                'js.selectedCount' => 'Selected',
                'assignment.selectedOnly' => 'Selected only',
                'assignment.empty' => 'Nothing found',
                'assignment.searchUsers' => 'Find user',
                'assignment.searchCameras' => 'Find camera',
                'dashboard.users' => 'Users',
                'dashboard.groups' => 'Groups',
                'dashboard.cameras' => 'Cameras',
                'dashboard.dvrServers' => 'DVR servers',
                'dashboard.dvrServersTitle' => 'SesameDVR servers',
                'dashboard.recentSync' => 'Recent camera sync',
                'dashboard.metricsUpdated' => 'Metrics refreshed',
                'dashboard.metricsRefreshFailed' => 'Metrics were not refreshed. Details are shown in the server card.',
                'dashboard.refreshFinished' => 'Refresh finished',
                'dashboard.refreshOk' => 'ok',
                'dashboard.refreshErrors' => 'errors',
                'server.checkOk' => 'Server check completed',
                'server.version' => 'Version',
                'server.streams' => 'Streams',
                'server.check' => 'Check',
                'server.managementTokenMissingNotice' => 'Management token is not configured. Portal cannot read /api/system/status and /api/streams from this SesameDVR server.',
                'server.managementTokenUnreadableNotice' => 'Management token cannot be decrypted. Save a new token in DVR server settings.',
                'server.managementUnauthorizedNotice' => 'SesameDVR returned HTTP 401. Check the Management token in DVR server settings.',
                'settings.title' => 'Settings',
                'settings.portalUpdates' => 'Portal updates',
                'settings.updateHint' => 'Portal compares the current build with the latest commit of the selected GitHub branch.',
                'settings.currentVersion' => 'Current version',
                'settings.githubVersion' => 'Available GitHub version',
                'settings.githubRepo' => 'GitHub repository',
                'settings.githubRef' => 'GitHub branch/ref',
                'settings.checkedAt' => 'Checked at',
                'settings.updateTool' => 'Update tool',
                'settings.toolInstalled' => 'installed',
                'settings.toolMissing' => 'not installed',
                'settings.checkError' => 'Check error',
                'settings.checkUpdates' => 'Check updates',
                'settings.installUpdate' => 'Update Portal',
                'settings.updateConfirm' => 'Update Portal code from GitHub and run migrations?',
                'settings.updateDisabled' => 'updates disabled',
                'settings.noUpdateAvailable' => 'no update available',
                'settings.updateAvailable' => 'Update available',
                'settings.upToDate' => 'up to date',
                'settings.notChecked' => 'not checked',
                'settings.versionUnknown' => 'unknown',
                'settings.checkDone' => 'Update check completed',
                'settings.checkFailed' => 'Update check failed',
                'settings.updateDone' => 'Portal update completed',
                'settings.updateFailed' => 'Portal update failed',
                'settings.updateOutputOk' => 'Updater output',
                'settings.updateOutputFailed' => 'Updater error output',
                'users.title' => 'Users',
                'users.new' => 'New user',
                'users.edit' => 'Edit user',
                'users.loginRequired' => 'Login is required',
                'users.passwordShort' => 'Password must be at least 6 characters',
                'users.passwordPlaceholderNew' => 'minimum 6 characters',
                'users.passwordPlaceholderEdit' => 'leave blank to keep unchanged',
                'users.blocked' => 'Blocked',
                'users.saveDone' => 'User saved',
                'users.saving' => 'Saving user...',
                'groups.title' => 'Groups',
                'groups.new' => 'New group',
                'groups.edit' => 'Edit group',
                'groups.users' => 'Users',
                'groups.cameras' => 'Cameras',
                'groups.parent' => 'Parent group',
                'groups.noParent' => 'No parent',
                'groups.nameRequired' => 'Group name is required',
                'groups.parentNotFound' => 'Parent group was not found',
                'groups.parentCycle' => 'Parent group cannot be this group or its subgroup',
                'groups.selectAll' => 'Select all',
                'groups.clearAll' => 'Clear all',
                'cameras.title' => 'Cameras',
                'cameras.new' => 'New camera',
                'cameras.edit' => 'Edit camera',
                'cameras.name' => 'Name',
                'cameras.displayName' => 'Stream title',
                'cameras.sourceUrl' => 'Source URL',
                'cameras.server' => 'Server',
                'cameras.serverAutoNone' => 'Auto/not selected',
                'cameras.serverSelection' => 'Server selection',
                'cameras.selectionManual' => 'specific',
                'cameras.selectionAuto' => 'automatic random',
                'cameras.streamName' => 'Technical stream name',
                'cameras.nameOrStreamRequired' => 'Stream title or technical stream name is required',
                'cameras.invalidStreamName' => 'Technical stream name can contain only Latin letters, digits, hyphen, and underscore, up to 128 characters.',
                'cameras.streamNameHint' => 'Only A-Z, a-z, 0-9, hyphen, and underscore. Leave empty to generate it.',
                'cameras.saveDone' => 'Camera saved',
                'cameras.saveSyncFailed' => 'Camera saved, but DVR sync failed',
                'cameras.syncDone' => 'Sync completed',
                'cameras.syncFailed' => 'Sync failed',
                'cameras.position' => 'Position on map',
                'cameras.clearPosition' => 'Clear point',
                'cameras.direction' => 'Direction',
                'cameras.viewAngle' => 'View angle',
                'cameras.retention' => 'Archive depth',
                'cameras.blocked' => 'Blocked',
                'cameras.groups' => 'Groups',
                'cameras.mode' => 'Camera mode',
                'cameras.modeManaged' => 'Full DVR management',
                'cameras.modeEdgeAgent' => 'Edge Agent push stream',
                'cameras.modeReadOnly' => 'Read-only DVR stream',
                'cameras.sourceRequired' => 'Source URL is required for full DVR management mode',
                'cameras.readOnlySyncSkipped' => 'Read-only mode: DVR management skipped',
                'cameras.agentId' => 'Agent ID',
                'cameras.agentCameraId' => 'Agent camera ID',
                'cameras.onvifEvents' => 'Start ONVIF events through the agent',
                'cameras.watermarkEnabled' => 'Show player watermark with user login',
                'cameras.watermarkIntensity' => 'Watermark intensity, %',
                'cameras.agentRequired' => 'Edge-agent mode requires server, Agent ID, and Agent camera ID',
                'cameras.agentOverwriteBlocked' => 'This DVR stream is already managed by Edge Agent. Switch the Portal camera to Edge Agent or read-only mode to avoid overwriting push configuration.',
                'cameras.deleteTitle' => 'Delete camera',
                'cameras.deleteMissing' => 'Camera is already deleted or missing',
                'cameras.deleteConfirmRequired' => 'Confirm camera deletion',
                'cameras.deleteDvrFailed' => 'DVR stream was not deleted',
                'cameras.deleteDone' => 'Camera deleted',
                'cameras.deleteWarning' => 'This action cannot be undone.',
                'cameras.deleteWarningText' => 'Confirm portal camera deletion first. A separate checkbox can also delete the linked DVR stream together with archive files.',
                'cameras.confirmDelete' => 'I confirm camera deletion from the portal',
                'cameras.deleteDvrStream' => 'Also delete the DVR stream and purge archive, previews, and indexes',
                'cameras.deleteDvrUnavailable' => 'DVR stream deletion is unavailable for this camera: check server, management token, and control mode.',
                'cameras.deleteDvrReadOnly' => 'Read-only camera: DVR stream deletion is unavailable',
                'servers.title' => 'SesameDVR servers',
                'servers.new' => 'New server',
                'servers.edit' => 'Edit server',
                'servers.managementKey' => 'Management key',
                'servers.blocked' => 'Blocked',
                'agents.title' => 'Edge Agents',
                'agents.new' => 'New agent',
                'agents.serverRequired' => 'Select a SesameDVR server',
                'agents.agentId' => 'Agent ID',
                'agents.agentName' => 'Agent name',
                'agents.password' => 'Enrollment password',
                'agents.capabilities' => 'Capabilities',
                'agents.enabled' => 'Enabled',
                'agents.create' => 'Create agent',
                'agents.setPassword' => 'Set password',
                'agents.revoke' => 'Revoke secret',
                'agents.rotateSecret' => 'Rotate secret',
                'agents.scan' => 'Scan ONVIF',
                'agents.diagnostics' => 'Diagnostics',
                'agents.logs' => 'Logs',
                'agents.commands' => 'Commands',
                'agents.cameras' => 'Agent cameras',
                'agents.command' => 'Command',
                'agents.payload' => 'Payload JSON',
                'agents.sendCommand' => 'Send command',
                'agents.useCamera' => 'Create Portal camera',
                'agents.snapshot' => 'Snapshot',
                'agents.noServer' => 'Add a SesameDVR server with a management token first.',
                'agents.noAgents' => 'No agents found',
                'agents.newSecret' => 'New agentSecret',
                'agents.loaded' => 'Agents loaded',
                'agents.details' => 'Technical details',
                'agents.actions' => 'Actions',
                'agents.settings' => 'Settings',
                'agents.enrollment' => 'Enrollment',
                'agents.commandConsole' => 'Command console',
                'agents.lastCommands' => 'Recent commands',
                'agents.lastLogs' => 'Recent logs',
                'agents.technicalData' => 'Technical data',
                'agents.actionQueued' => 'Command queued',
                'agents.actionCompleted' => 'Operation completed',
                'agents.actionFailed' => 'Operation failed',
                'agents.createHint' => 'Create an agent only before provisioning a new edge device.',
                'agents.unknown' => 'unknown',
                'agents.media' => 'Media',
                'agents.onvif' => 'ONVIF',
                'agents.version' => 'Version',
                'agents.lastSeen' => 'Last seen',
                'agents.cameraCount' => 'Cameras',
                'agents.mediaSessions' => 'Media sessions',
                'agents.source' => 'Source',
                'agents.timeout' => 'Timeout, ms',
                'agents.yes' => 'yes',
                'agents.no' => 'no',
                'agents.running' => 'running',
                'agents.stopped' => 'stopped',
                'agents.online' => 'online',
                'agents.offline' => 'offline',
                'audit.title' => 'Audit log',
                'audit.search' => 'Search action, user, or details',
                'audit.allActions' => 'All actions',
                'audit.allUsers' => 'All users',
                'audit.time' => 'Time',
                'audit.user' => 'User',
                'audit.action' => 'Action',
                'audit.details' => 'Details',
                'audit.raw' => 'Full text',
                'table.search' => 'Search',
                'table.shown' => 'Shown',
                'table.of' => 'of',
                'common.noServer' => 'No server',
                'column.id' => 'ID',
                'column.name' => 'Name',
                'column.parent_group_name' => 'Parent',
                'column.server_name' => 'Server',
                'column.agent_id' => 'Agent',
                'column.agent_camera_id' => 'Agent camera',
                'column.last_sync_ok' => 'Sync',
                'column.last_sync_at' => 'Synced at',
                'column.last_sync_message' => 'Sync result',
                'column.login' => 'Login',
                'column.role' => 'Role',
                'column.blocked' => 'Blocked',
                'column.last_login_at' => 'Last login',
                'column.description' => 'Description',
                'column.base_url' => 'URL',
                'column.last_check_result' => 'Check result',
                'column.dvr_control_mode' => 'Mode',
                'column.retention_days' => 'Archive',
            ],
            'de' => [
                'language.label' => 'Sprache',
                'nav.mosaic' => 'Mosaik',
                'nav.map' => 'Karte',
                'nav.dashboard' => 'Dashboard',
                'nav.users' => 'Benutzer',
                'nav.groups' => 'Gruppen',
                'nav.cameras' => 'Kameras',
                'nav.audit' => 'Audit',
                'nav.logout' => 'Abmelden',
                'login.title' => 'Anmelden',
                'login.subtitle' => 'SesameWare Videoüberwachungsportal',
                'login.feature.secure' => 'Sicher',
                'login.feature.reliable' => 'Zuverlässig',
                'login.feature.efficient' => 'Effizient',
                'login.invalid' => 'Ungültiger Benutzername oder Passwort',
                'field.login' => 'Login',
                'field.password' => 'Passwort',
                'action.login' => 'Anmelden',
                'action.save' => 'Speichern',
                'action.saveSync' => 'Speichern und synchronisieren',
                'action.find' => 'Suchen',
                'action.show' => 'Anzeigen',
                'action.edit' => 'Bearbeiten',
                'action.delete' => 'Löschen',
                'action.check' => 'Prüfen',
                'action.update' => 'Aktualisieren',
                'action.updateAll' => 'Alle aktualisieren',
                'action.back' => 'Zurück',
                'action.apply' => 'Anwenden',
                'action.cancel' => 'Abbrechen',
                'filter.all' => 'Alle',
                'filter.favorites' => 'Favoriten',
                'filter.groupSelect' => 'Gruppe',
                'filter.groupSelectPlaceholder' => 'Gruppe auswählen',
                'filter.noGroups' => 'Keine Gruppen gefunden',
                'viewer.openPlayer' => 'Player öffnen',
                'viewer.columnsPerRow' => 'Kameras pro Zeile',
                'player.title' => 'Player',
                'player.fullscreen' => 'Vollbild',
                'player.collapse' => 'Vollbild verlassen',
                'dashboard.users' => 'Benutzer',
                'dashboard.groups' => 'Gruppen',
                'dashboard.cameras' => 'Kameras',
                'dashboard.dvrServers' => 'DVR-Server',
                'dashboard.dvrServersTitle' => 'SesameDVR-Server',
                'dashboard.recentSync' => 'Letzte Kamerasynchronisierung',
                'server.version' => 'Version',
                'server.streams' => 'Streams',
                'server.check' => 'Prüfung',
                'server.managementTokenMissingNotice' => 'Management token ist nicht konfiguriert. Portal kann /api/system/status und /api/streams von diesem SesameDVR-Server nicht lesen.',
                'users.title' => 'Benutzer',
                'users.new' => 'Neuer Benutzer',
                'users.edit' => 'Benutzer bearbeiten',
                'groups.title' => 'Gruppen',
                'groups.new' => 'Neue Gruppe',
                'groups.edit' => 'Gruppe bearbeiten',
                'groups.users' => 'Benutzer',
                'groups.cameras' => 'Kameras',
                'cameras.title' => 'Kameras',
                'cameras.new' => 'Neue Kamera',
                'cameras.edit' => 'Kamera bearbeiten',
                'cameras.name' => 'Name',
                'cameras.displayName' => 'Stream-Titel',
                'cameras.sourceUrl' => 'Quell-URL',
                'cameras.server' => 'Server',
                'cameras.position' => 'Position auf der Karte',
                'cameras.direction' => 'Richtung',
                'cameras.retention' => 'Archivtiefe',
                'cameras.groups' => 'Gruppen',
                'servers.title' => 'SesameDVR-Server',
                'servers.new' => 'Neuer Server',
                'servers.edit' => 'Server bearbeiten',
                'audit.title' => 'Audit-Protokoll',
                'audit.time' => 'Zeit',
                'audit.user' => 'Benutzer',
                'audit.action' => 'Aktion',
                'audit.details' => 'Details',
                'table.search' => 'Suche',
                'table.shown' => 'Angezeigt',
                'table.of' => 'von',
                'common.noServer' => 'Kein Server',
                'column.name' => 'Name',
                'column.server_name' => 'Server',
                'column.last_sync_ok' => 'Sync',
                'column.last_sync_at' => 'Synchronisiert um',
                'column.last_sync_message' => 'Sync-Ergebnis',
                'column.login' => 'Login',
                'column.role' => 'Rolle',
                'column.blocked' => 'Gesperrt',
                'column.last_login_at' => 'Letzte Anmeldung',
                'column.description' => 'Beschreibung',
                'column.base_url' => 'URL',
                'column.last_check_result' => 'Prüfergebnis',
                'column.dvr_control_mode' => 'Modus',
                'column.retention_days' => 'Archiv',
            ],
            'fr' => [
                'language.label' => 'Langue',
                'nav.mosaic' => 'Mosaïque',
                'nav.map' => 'Carte',
                'nav.dashboard' => 'Tableau de bord',
                'nav.users' => 'Utilisateurs',
                'nav.groups' => 'Groupes',
                'nav.cameras' => 'Caméras',
                'nav.audit' => 'Journal',
                'nav.logout' => 'Déconnexion',
                'login.title' => 'Connexion',
                'login.subtitle' => 'Portail de vidéosurveillance SesameWare',
                'login.feature.secure' => 'Sécurisé',
                'login.feature.reliable' => 'Fiable',
                'login.feature.efficient' => 'Efficace',
                'login.invalid' => 'Identifiant ou mot de passe invalide',
                'field.login' => 'Identifiant',
                'field.password' => 'Mot de passe',
                'action.login' => 'Se connecter',
                'action.save' => 'Enregistrer',
                'action.saveSync' => 'Enregistrer et synchroniser',
                'action.find' => 'Rechercher',
                'action.show' => 'Afficher',
                'action.edit' => 'Modifier',
                'action.delete' => 'Supprimer',
                'action.check' => 'Vérifier',
                'action.update' => 'Actualiser',
                'action.updateAll' => 'Tout actualiser',
                'action.back' => 'Retour',
                'action.apply' => 'Appliquer',
                'action.cancel' => 'Annuler',
                'filter.all' => 'Tout',
                'filter.favorites' => 'Favoris',
                'filter.groupSelect' => 'Groupe',
                'filter.groupSelectPlaceholder' => 'Choisir un groupe',
                'filter.noGroups' => 'Aucun groupe trouvé',
                'viewer.openPlayer' => 'Ouvrir le lecteur',
                'viewer.columnsPerRow' => 'Caméras par ligne',
                'player.title' => 'Lecteur',
                'player.fullscreen' => 'Plein écran',
                'player.collapse' => 'Quitter le plein écran',
                'dashboard.users' => 'Utilisateurs',
                'dashboard.groups' => 'Groupes',
                'dashboard.cameras' => 'Caméras',
                'dashboard.dvrServers' => 'Serveurs DVR',
                'dashboard.dvrServersTitle' => 'Serveurs SesameDVR',
                'dashboard.recentSync' => 'Dernière synchronisation des caméras',
                'server.version' => 'Version',
                'server.streams' => 'Flux',
                'server.check' => 'Vérification',
                'server.managementTokenMissingNotice' => 'Le Management token n’est pas configuré. Portal ne peut pas lire /api/system/status et /api/streams depuis ce serveur SesameDVR.',
                'users.title' => 'Utilisateurs',
                'users.new' => 'Nouvel utilisateur',
                'users.edit' => 'Modifier l’utilisateur',
                'groups.title' => 'Groupes',
                'groups.new' => 'Nouveau groupe',
                'groups.edit' => 'Modifier le groupe',
                'groups.users' => 'Utilisateurs',
                'groups.cameras' => 'Caméras',
                'cameras.title' => 'Caméras',
                'cameras.new' => 'Nouvelle caméra',
                'cameras.edit' => 'Modifier la caméra',
                'cameras.name' => 'Nom',
                'cameras.sourceUrl' => 'URL source',
                'cameras.server' => 'Serveur',
                'cameras.position' => 'Position sur la carte',
                'cameras.direction' => 'Direction',
                'cameras.retention' => 'Profondeur d’archive',
                'cameras.groups' => 'Groupes',
                'servers.title' => 'Serveurs SesameDVR',
                'servers.new' => 'Nouveau serveur',
                'servers.edit' => 'Modifier le serveur',
                'audit.title' => 'Journal d’audit',
                'audit.time' => 'Heure',
                'audit.user' => 'Utilisateur',
                'audit.action' => 'Action',
                'audit.details' => 'Détails',
                'table.search' => 'Recherche',
                'table.shown' => 'Affiché',
                'table.of' => 'sur',
                'common.noServer' => 'Aucun serveur',
                'column.name' => 'Nom',
                'column.server_name' => 'Serveur',
                'column.last_sync_ok' => 'Sync',
                'column.last_sync_at' => 'Synchronisé à',
                'column.last_sync_message' => 'Résultat sync',
                'column.login' => 'Identifiant',
                'column.role' => 'Rôle',
                'column.blocked' => 'Bloqué',
                'column.last_login_at' => 'Dernière connexion',
                'column.description' => 'Description',
                'column.base_url' => 'URL',
                'column.last_check_result' => 'Résultat',
                'column.dvr_control_mode' => 'Mode',
                'column.retention_days' => 'Archive',
            ],
            'es' => [
                'language.label' => 'Idioma',
                'nav.mosaic' => 'Mosaico',
                'nav.map' => 'Mapa',
                'nav.dashboard' => 'Panel',
                'nav.users' => 'Usuarios',
                'nav.groups' => 'Grupos',
                'nav.cameras' => 'Cámaras',
                'nav.audit' => 'Auditoría',
                'nav.logout' => 'Salir',
                'login.title' => 'Iniciar sesión',
                'login.subtitle' => 'Portal de videovigilancia SesameWare',
                'login.feature.secure' => 'Seguro',
                'login.feature.reliable' => 'Fiable',
                'login.feature.efficient' => 'Eficiente',
                'field.login' => 'Usuario',
                'field.password' => 'Contraseña',
                'action.login' => 'Entrar',
                'action.save' => 'Guardar',
                'action.saveSync' => 'Guardar y sincronizar',
                'action.find' => 'Buscar',
                'action.show' => 'Mostrar',
                'action.edit' => 'Editar',
                'action.delete' => 'Eliminar',
                'action.check' => 'Comprobar',
                'action.update' => 'Actualizar',
                'action.updateAll' => 'Actualizar todo',
                'action.back' => 'Atrás',
                'action.apply' => 'Aplicar',
                'action.cancel' => 'Cancelar',
                'filter.all' => 'Todo',
                'filter.favorites' => 'Favoritos',
                'filter.groupSelectPlaceholder' => 'Seleccionar grupo',
                'viewer.openPlayer' => 'Abrir reproductor',
                'viewer.columnsPerRow' => 'Cámaras por fila',
                'player.title' => 'Reproductor',
                'dashboard.users' => 'Usuarios',
                'dashboard.groups' => 'Grupos',
                'dashboard.cameras' => 'Cámaras',
                'dashboard.dvrServers' => 'Servidores DVR',
                'dashboard.dvrServersTitle' => 'Servidores SesameDVR',
                'dashboard.recentSync' => 'Sincronización reciente de cámaras',
                'server.version' => 'Versión',
                'server.streams' => 'Flujos',
                'server.check' => 'Comprobación',
                'users.title' => 'Usuarios',
                'groups.title' => 'Grupos',
                'groups.users' => 'Usuarios',
                'groups.cameras' => 'Cámaras',
                'cameras.title' => 'Cámaras',
                'cameras.name' => 'Nombre',
                'cameras.server' => 'Servidor',
                'cameras.position' => 'Posición en el mapa',
                'servers.title' => 'Servidores SesameDVR',
                'audit.title' => 'Registro de auditoría',
                'audit.time' => 'Hora',
                'audit.user' => 'Usuario',
                'audit.action' => 'Acción',
                'audit.details' => 'Detalles',
                'table.search' => 'Buscar',
                'table.shown' => 'Mostrado',
                'table.of' => 'de',
                'common.noServer' => 'Sin servidor',
                'column.name' => 'Nombre',
                'column.server_name' => 'Servidor',
                'column.last_sync_at' => 'Sincronizado',
                'column.last_sync_message' => 'Resultado',
                'column.role' => 'Rol',
                'column.blocked' => 'Bloqueado',
                'column.description' => 'Descripción',
            ],
            'it' => [
                'language.label' => 'Lingua',
                'nav.mosaic' => 'Mosaico',
                'nav.map' => 'Mappa',
                'nav.dashboard' => 'Dashboard',
                'nav.users' => 'Utenti',
                'nav.groups' => 'Gruppi',
                'nav.cameras' => 'Telecamere',
                'nav.audit' => 'Audit',
                'nav.logout' => 'Esci',
                'login.title' => 'Accesso',
                'login.subtitle' => 'Portale di videosorveglianza SesameWare',
                'field.login' => 'Login',
                'field.password' => 'Password',
                'action.login' => 'Accedi',
                'action.save' => 'Salva',
                'action.find' => 'Cerca',
                'action.show' => 'Mostra',
                'action.edit' => 'Modifica',
                'action.delete' => 'Elimina',
                'action.update' => 'Aggiorna',
                'filter.all' => 'Tutte',
                'filter.favorites' => 'Preferiti',
                'viewer.openPlayer' => 'Apri player',
                'viewer.columnsPerRow' => 'Telecamere per riga',
                'dashboard.users' => 'Utenti',
                'dashboard.groups' => 'Gruppi',
                'dashboard.cameras' => 'Telecamere',
                'dashboard.dvrServersTitle' => 'Server SesameDVR',
                'users.title' => 'Utenti',
                'groups.title' => 'Gruppi',
                'cameras.title' => 'Telecamere',
                'servers.title' => 'Server SesameDVR',
                'audit.title' => 'Registro audit',
                'table.search' => 'Cerca',
                'common.noServer' => 'Nessun server',
                'column.name' => 'Nome',
                'column.server_name' => 'Server',
                'column.description' => 'Descrizione',
            ],
            'pt' => [
                'language.label' => 'Idioma',
                'nav.mosaic' => 'Mosaico',
                'nav.map' => 'Mapa',
                'nav.dashboard' => 'Painel',
                'nav.users' => 'Utilizadores',
                'nav.groups' => 'Grupos',
                'nav.cameras' => 'Câmaras',
                'nav.audit' => 'Auditoria',
                'nav.logout' => 'Sair',
                'login.title' => 'Entrar',
                'login.subtitle' => 'Portal de videovigilância SesameWare',
                'field.login' => 'Login',
                'field.password' => 'Palavra-passe',
                'action.login' => 'Entrar',
                'action.save' => 'Guardar',
                'action.find' => 'Procurar',
                'action.show' => 'Mostrar',
                'action.edit' => 'Editar',
                'action.delete' => 'Eliminar',
                'action.update' => 'Atualizar',
                'filter.all' => 'Tudo',
                'filter.favorites' => 'Favoritos',
                'viewer.openPlayer' => 'Abrir leitor',
                'viewer.columnsPerRow' => 'Câmaras por linha',
                'dashboard.users' => 'Utilizadores',
                'dashboard.groups' => 'Grupos',
                'dashboard.cameras' => 'Câmaras',
                'dashboard.dvrServersTitle' => 'Servidores SesameDVR',
                'users.title' => 'Utilizadores',
                'groups.title' => 'Grupos',
                'cameras.title' => 'Câmaras',
                'servers.title' => 'Servidores SesameDVR',
                'audit.title' => 'Registo de auditoria',
                'table.search' => 'Procurar',
                'common.noServer' => 'Sem servidor',
                'column.name' => 'Nome',
                'column.server_name' => 'Servidor',
            ],
            'bg' => [
                'language.label' => 'Език',
                'nav.mosaic' => 'Мозайка',
                'nav.map' => 'Карта',
                'nav.dashboard' => 'Табло',
                'nav.users' => 'Потребители',
                'nav.groups' => 'Групи',
                'nav.cameras' => 'Камери',
                'nav.audit' => 'Журнал',
                'nav.logout' => 'Изход',
                'login.title' => 'Вход',
                'login.subtitle' => 'Портал за видеонаблюдение SesameWare',
                'field.login' => 'Логин',
                'field.password' => 'Парола',
                'action.login' => 'Вход',
                'action.save' => 'Запази',
                'action.find' => 'Намери',
                'action.show' => 'Покажи',
                'action.edit' => 'Редактирай',
                'action.delete' => 'Изтрий',
                'action.update' => 'Обнови',
                'filter.all' => 'Всички',
                'filter.favorites' => 'Любими',
                'viewer.openPlayer' => 'Отвори плеър',
                'viewer.columnsPerRow' => 'Камери на ред',
                'dashboard.users' => 'Потребители',
                'dashboard.groups' => 'Групи',
                'dashboard.cameras' => 'Камери',
                'dashboard.dvrServersTitle' => 'SesameDVR сървъри',
                'users.title' => 'Потребители',
                'groups.title' => 'Групи',
                'cameras.title' => 'Камери',
                'servers.title' => 'SesameDVR сървъри',
                'audit.title' => 'Журнал на действията',
                'table.search' => 'Търсене',
                'common.noServer' => 'Няма сървър',
                'column.name' => 'Име',
                'column.server_name' => 'Сървър',
            ],
            'pl' => [
                'language.label' => 'Język',
                'nav.mosaic' => 'Mozaika',
                'nav.map' => 'Mapa',
                'nav.dashboard' => 'Panel',
                'nav.users' => 'Użytkownicy',
                'nav.groups' => 'Grupy',
                'nav.cameras' => 'Kamery',
                'nav.audit' => 'Audyt',
                'nav.logout' => 'Wyloguj',
                'login.title' => 'Logowanie',
                'login.subtitle' => 'Portal monitoringu wideo SesameWare',
                'field.login' => 'Login',
                'field.password' => 'Hasło',
                'action.login' => 'Zaloguj',
                'action.save' => 'Zapisz',
                'action.find' => 'Znajdź',
                'action.show' => 'Pokaż',
                'action.edit' => 'Edytuj',
                'action.delete' => 'Usuń',
                'action.update' => 'Odśwież',
                'filter.all' => 'Wszystkie',
                'filter.favorites' => 'Ulubione',
                'viewer.openPlayer' => 'Otwórz odtwarzacz',
                'viewer.columnsPerRow' => 'Kamery w wierszu',
                'dashboard.users' => 'Użytkownicy',
                'dashboard.groups' => 'Grupy',
                'dashboard.cameras' => 'Kamery',
                'dashboard.dvrServersTitle' => 'Serwery SesameDVR',
                'users.title' => 'Użytkownicy',
                'groups.title' => 'Grupy',
                'cameras.title' => 'Kamery',
                'servers.title' => 'Serwery SesameDVR',
                'audit.title' => 'Dziennik audytu',
                'table.search' => 'Szukaj',
                'common.noServer' => 'Brak serwera',
                'column.name' => 'Nazwa',
                'column.server_name' => 'Serwer',
            ],
            'zh' => [
                'language.label' => '语言',
                'nav.mosaic' => '宫格',
                'nav.map' => '地图',
                'nav.dashboard' => '仪表板',
                'nav.users' => '用户',
                'nav.groups' => '组',
                'nav.cameras' => '摄像机',
                'nav.audit' => '审计',
                'nav.logout' => '退出',
                'login.title' => '登录',
                'login.subtitle' => 'SesameWare 视频监控门户',
                'field.login' => '登录名',
                'field.password' => '密码',
                'action.login' => '登录',
                'action.save' => '保存',
                'action.find' => '查找',
                'action.show' => '显示',
                'action.edit' => '编辑',
                'action.delete' => '删除',
                'action.update' => '刷新',
                'filter.all' => '全部',
                'filter.favorites' => '收藏',
                'viewer.openPlayer' => '打开播放器',
                'viewer.columnsPerRow' => '每行摄像机',
                'dashboard.users' => '用户',
                'dashboard.groups' => '组',
                'dashboard.cameras' => '摄像机',
                'dashboard.dvrServersTitle' => 'SesameDVR 服务器',
                'users.title' => '用户',
                'groups.title' => '组',
                'cameras.title' => '摄像机',
                'servers.title' => 'SesameDVR 服务器',
                'audit.title' => '审计日志',
                'table.search' => '搜索',
                'common.noServer' => '无服务器',
                'column.name' => '名称',
                'column.server_name' => '服务器',
            ],
            'ja' => [
                'language.label' => '言語',
                'nav.mosaic' => 'モザイク',
                'nav.map' => '地図',
                'nav.dashboard' => 'ダッシュボード',
                'nav.users' => 'ユーザー',
                'nav.groups' => 'グループ',
                'nav.cameras' => 'カメラ',
                'nav.audit' => '監査',
                'nav.logout' => 'ログアウト',
                'login.title' => 'サインイン',
                'login.subtitle' => 'SesameWare ビデオ監視ポータル',
                'field.login' => 'ログイン',
                'field.password' => 'パスワード',
                'action.login' => 'サインイン',
                'action.save' => '保存',
                'action.find' => '検索',
                'action.show' => '表示',
                'action.edit' => '編集',
                'action.delete' => '削除',
                'action.update' => '更新',
                'filter.all' => 'すべて',
                'filter.favorites' => 'お気に入り',
                'viewer.openPlayer' => 'プレイヤーを開く',
                'viewer.columnsPerRow' => '1行あたりのカメラ数',
                'dashboard.users' => 'ユーザー',
                'dashboard.groups' => 'グループ',
                'dashboard.cameras' => 'カメラ',
                'dashboard.dvrServersTitle' => 'SesameDVR サーバー',
                'users.title' => 'ユーザー',
                'groups.title' => 'グループ',
                'cameras.title' => 'カメラ',
                'servers.title' => 'SesameDVR サーバー',
                'audit.title' => '監査ログ',
                'table.search' => '検索',
                'common.noServer' => 'サーバーなし',
                'column.name' => '名前',
                'column.server_name' => 'サーバー',
            ],
            'ko' => [
                'language.label' => '언어',
                'nav.mosaic' => '모자이크',
                'nav.map' => '지도',
                'nav.dashboard' => '대시보드',
                'nav.users' => '사용자',
                'nav.groups' => '그룹',
                'nav.cameras' => '카메라',
                'nav.audit' => '감사',
                'nav.logout' => '로그아웃',
                'login.title' => '로그인',
                'login.subtitle' => 'SesameWare 영상 관제 포털',
                'field.login' => '로그인',
                'field.password' => '비밀번호',
                'action.login' => '로그인',
                'action.save' => '저장',
                'action.find' => '찾기',
                'action.show' => '보기',
                'action.edit' => '편집',
                'action.delete' => '삭제',
                'action.update' => '새로고침',
                'filter.all' => '전체',
                'filter.favorites' => '즐겨찾기',
                'viewer.openPlayer' => '플레이어 열기',
                'viewer.columnsPerRow' => '행당 카메라',
                'dashboard.users' => '사용자',
                'dashboard.groups' => '그룹',
                'dashboard.cameras' => '카메라',
                'dashboard.dvrServersTitle' => 'SesameDVR 서버',
                'users.title' => '사용자',
                'groups.title' => '그룹',
                'cameras.title' => '카메라',
                'servers.title' => 'SesameDVR 서버',
                'audit.title' => '감사 로그',
                'table.search' => '검색',
                'common.noServer' => '서버 없음',
                'column.name' => '이름',
                'column.server_name' => '서버',
            ],
            'ar' => [
                'language.label' => 'اللغة',
                'nav.mosaic' => 'فسيفساء',
                'nav.map' => 'الخريطة',
                'nav.dashboard' => 'لوحة التحكم',
                'nav.users' => 'المستخدمون',
                'nav.groups' => 'المجموعات',
                'nav.cameras' => 'الكاميرات',
                'nav.audit' => 'السجل',
                'nav.logout' => 'خروج',
                'login.title' => 'تسجيل الدخول',
                'login.subtitle' => 'بوابة مراقبة الفيديو SesameWare',
                'field.login' => 'اسم الدخول',
                'field.password' => 'كلمة المرور',
                'action.login' => 'دخول',
                'action.save' => 'حفظ',
                'action.find' => 'بحث',
                'action.show' => 'عرض',
                'action.edit' => 'تعديل',
                'action.delete' => 'حذف',
                'action.update' => 'تحديث',
                'filter.all' => 'الكل',
                'filter.favorites' => 'المفضلة',
                'viewer.openPlayer' => 'فتح المشغل',
                'viewer.columnsPerRow' => 'كاميرات في كل صف',
                'dashboard.users' => 'المستخدمون',
                'dashboard.groups' => 'المجموعات',
                'dashboard.cameras' => 'الكاميرات',
                'dashboard.dvrServersTitle' => 'خوادم SesameDVR',
                'users.title' => 'المستخدمون',
                'groups.title' => 'المجموعات',
                'cameras.title' => 'الكاميرات',
                'servers.title' => 'خوادم SesameDVR',
                'audit.title' => 'سجل التدقيق',
                'table.search' => 'بحث',
                'common.noServer' => 'لا يوجد خادم',
                'column.name' => 'الاسم',
                'column.server_name' => 'الخادم',
            ],
            'hy' => [
                'language.label' => 'Լեզու',
                'nav.mosaic' => 'Խճանկար',
                'nav.map' => 'Քարտեզ',
                'nav.dashboard' => 'Վահանակ',
                'nav.users' => 'Օգտատերեր',
                'nav.groups' => 'Խմբեր',
                'nav.cameras' => 'Տեսախցիկներ',
                'nav.audit' => 'Աուդիտ',
                'nav.logout' => 'Ելք',
                'login.title' => 'Մուտք',
                'login.subtitle' => 'SesameWare տեսահսկման պորտալ',
                'field.login' => 'Լոգին',
                'field.password' => 'Գաղտնաբառ',
                'action.login' => 'Մուտք',
                'action.save' => 'Պահպանել',
                'action.find' => 'Գտնել',
                'action.show' => 'Ցույց տալ',
                'action.edit' => 'Խմբագրել',
                'action.delete' => 'Ջնջել',
                'action.update' => 'Թարմացնել',
                'filter.all' => 'Բոլորը',
                'filter.favorites' => 'Ընտրյալներ',
                'viewer.openPlayer' => 'Բացել նվագարկիչը',
                'viewer.columnsPerRow' => 'Տեսախցիկներ տողում',
                'dashboard.users' => 'Օգտատերեր',
                'dashboard.groups' => 'Խմբեր',
                'dashboard.cameras' => 'Տեսախցիկներ',
                'dashboard.dvrServersTitle' => 'SesameDVR սերվերներ',
                'users.title' => 'Օգտատերեր',
                'groups.title' => 'Խմբեր',
                'cameras.title' => 'Տեսախցիկներ',
                'servers.title' => 'SesameDVR սերվերներ',
                'audit.title' => 'Աուդիտի մատյան',
                'table.search' => 'Որոնում',
                'common.noServer' => 'Սերվեր չկա',
                'column.name' => 'Անուն',
                'column.server_name' => 'Սերվեր',
            ],
        ];

        foreach ([
            'de' => 'Kamera- oder Streamname',
            'fr' => 'Nom de caméra ou de flux',
            'es' => 'Nombre de cámara o stream',
            'it' => 'Nome camera o stream',
            'pt' => 'Nome da câmera ou do stream',
            'bg' => 'Име на камера или поток',
            'pl' => 'Nazwa kamery lub strumienia',
            'zh' => '摄像机或流名称',
            'ja' => 'カメラ名またはストリーム名',
            'ko' => '카메라 또는 스트림 이름',
            'ar' => 'اسم الكاميرا أو البث',
            'hy' => 'Տեսախցիկի կամ հոսքի անունը',
        ] as $locale => $label) {
            $messages[$locale]['filter.cameraSearchPlaceholder'] = $label;
        }

        foreach ([
            'de' => 'Suche zurücksetzen',
            'fr' => 'Effacer la recherche',
            'es' => 'Borrar búsqueda',
            'it' => 'Cancella ricerca',
            'pt' => 'Limpar pesquisa',
            'bg' => 'Изчисти търсенето',
            'pl' => 'Wyczyść wyszukiwanie',
            'zh' => '清除搜索',
            'ja' => '検索をクリア',
            'ko' => '검색 지우기',
            'ar' => 'مسح البحث',
            'hy' => 'Մաքրել որոնումը',
        ] as $locale => $label) {
            $messages[$locale]['filter.clearSearch'] = $label;
        }

        foreach ([
            'de' => ['Vorschau aktualisieren', 'Aus', 'Stream nicht verfügbar', '%d Sek.'],
            'fr' => ['Actualisation des aperçus', 'Désactivée', 'Flux indisponible', '%d s'],
            'es' => ['Actualizar vistas previas', 'Desactivado', 'Stream no disponible', '%d s'],
            'it' => ['Aggiornamento anteprime', 'Disattivato', 'Stream non disponibile', '%d s'],
            'pt' => ['Atualização de prévias', 'Desativado', 'Stream indisponível', '%d s'],
            'bg' => ['Обновяване на превюта', 'Изключено', 'Потокът е недостъпен', '%d сек.'],
            'pl' => ['Odświeżanie podglądu', 'Wyłączone', 'Strumień niedostępny', '%d s'],
            'zh' => ['预览刷新', '关闭', '流不可用', '%d 秒'],
            'ja' => ['プレビュー更新', 'オフ', 'ストリーム利用不可', '%d 秒'],
            'ko' => ['미리보기 새로고침', '꺼짐', '스트림을 사용할 수 없음', '%d초'],
            'ar' => ['تحديث المعاينة', 'متوقف', 'البث غير متاح', '%d ث'],
            'hy' => ['Նախադիտման թարմացում', 'Անջատված', 'Հոսքն անհասանելի է', '%d վրկ.'],
        ] as $locale => [$refreshLabel, $offLabel, $streamLabel, $secondsLabel]) {
            $messages[$locale]['viewer.previewRefresh'] = $refreshLabel;
            $messages[$locale]['viewer.refreshOff'] = $offLabel;
            $messages[$locale]['viewer.refreshSeconds'] = $secondsLabel;
            $messages[$locale]['js.streamUnavailable'] = $streamLabel;
        }

        foreach ([
            'de' => ['Kamera gespeichert', 'Kamera gespeichert, aber DVR-Synchronisierung fehlgeschlagen', 'Synchronisierung abgeschlossen', 'Synchronisierung fehlgeschlagen'],
            'fr' => ['Caméra enregistrée', 'Caméra enregistrée, mais la synchronisation DVR a échoué', 'Synchronisation terminée', 'Synchronisation échouée'],
            'es' => ['Cámara guardada', 'Cámara guardada, pero falló la sincronización con DVR', 'Sincronización completada', 'Error de sincronización'],
            'it' => ['Telecamera salvata', 'Telecamera salvata, ma la sincronizzazione DVR non è riuscita', 'Sincronizzazione completata', 'Sincronizzazione non riuscita'],
            'pt' => ['Câmara guardada', 'Câmara guardada, mas a sincronização DVR falhou', 'Sincronização concluída', 'Falha na sincronização'],
            'bg' => ['Камерата е запазена', 'Камерата е запазена, но синхронизацията с DVR не бе успешна', 'Синхронизацията е завършена', 'Синхронизацията не бе успешна'],
            'pl' => ['Kamera zapisana', 'Kamera zapisana, ale synchronizacja DVR nie powiodła się', 'Synchronizacja zakończona', 'Synchronizacja nie powiodła się'],
            'zh' => ['摄像机已保存', '摄像机已保存，但 DVR 同步失败', '同步完成', '同步失败'],
            'ja' => ['カメラを保存しました', 'カメラを保存しましたが、DVR 同期に失敗しました', '同期が完了しました', '同期に失敗しました'],
            'ko' => ['카메라가 저장되었습니다', '카메라가 저장되었지만 DVR 동기화에 실패했습니다', '동기화가 완료되었습니다', '동기화에 실패했습니다'],
            'ar' => ['تم حفظ الكاميرا', 'تم حفظ الكاميرا، لكن مزامنة DVR فشلت', 'اكتملت المزامنة', 'فشلت المزامنة'],
            'hy' => ['Տեսախցիկը պահպանվել է', 'Տեսախցիկը պահպանվել է, բայց DVR համաժամացումը ձախողվեց', 'Համաժամացումը ավարտվեց', 'Համաժամացումը ձախողվեց'],
        ] as $locale => [$saveDone, $saveSyncFailed, $syncDone, $syncFailed]) {
            $messages[$locale]['cameras.saveDone'] = $saveDone;
            $messages[$locale]['cameras.saveSyncFailed'] = $saveSyncFailed;
            $messages[$locale]['cameras.syncDone'] = $syncDone;
            $messages[$locale]['cameras.syncFailed'] = $syncFailed;
        }

        foreach ([
            'de' => ['Alle auswählen', 'Alle abwählen'],
            'fr' => ['Tout sélectionner', 'Tout désélectionner'],
            'es' => ['Seleccionar todo', 'Borrar selección'],
            'it' => ['Seleziona tutto', 'Deseleziona tutto'],
            'pt' => ['Selecionar tudo', 'Limpar seleção'],
            'bg' => ['Избери всички', 'Изчисти избора'],
            'pl' => ['Zaznacz wszystko', 'Wyczyść wybór'],
            'zh' => ['全选', '清除选择'],
            'ja' => ['すべて選択', '選択を解除'],
            'ko' => ['모두 선택', '선택 해제'],
            'ar' => ['تحديد الكل', 'إلغاء تحديد الكل'],
            'hy' => ['Ընտրել բոլորը', 'Մաքրել ընտրությունը'],
        ] as $locale => [$selectAll, $clearAll]) {
            $messages[$locale]['groups.selectAll'] = $selectAll;
            $messages[$locale]['groups.clearAll'] = $clearAll;
        }

        foreach ([
            'de' => ['Benutzer gespeichert', 'Benutzer wird gespeichert...'],
            'fr' => ['Utilisateur enregistré', 'Enregistrement de l’utilisateur...'],
            'es' => ['Usuario guardado', 'Guardando usuario...'],
            'it' => ['Utente salvato', 'Salvataggio utente...'],
            'pt' => ['Utilizador guardado', 'A guardar utilizador...'],
            'bg' => ['Потребителят е запазен', 'Запазване на потребителя...'],
            'pl' => ['Użytkownik zapisany', 'Zapisywanie użytkownika...'],
            'zh' => ['用户已保存', '正在保存用户...'],
            'ja' => ['ユーザーを保存しました', 'ユーザーを保存中...'],
            'ko' => ['사용자가 저장되었습니다', '사용자 저장 중...'],
            'ar' => ['تم حفظ المستخدم', 'جارٍ حفظ المستخدم...'],
            'hy' => ['Օգտատերը պահպանվել է', 'Օգտատերը պահպանվում է...'],
        ] as $locale => [$saveDone, $saving]) {
            $messages[$locale]['users.saveDone'] = $saveDone;
            $messages[$locale]['users.saving'] = $saving;
        }

        $messages['ru'] += [
            'nav.section.view' => 'Просмотр',
            'nav.section.admin' => 'Администрирование',
            'action.sync' => 'Синхронизировать',
            'action.revoke' => 'Отозвать',
            'column.static_token_hash' => 'Статический токен',
            'token.static' => 'Статический токен',
            'token.staticIssue' => 'Выпустить статический токен',
            'token.staticReplace' => 'Заменить статический токен',
            'token.staticReplaceConfirm' => 'Старый статический токен сразу перестанет работать. Выпустить новый токен?',
            'token.staticIssued' => 'Новый статический токен. Сохраните его сейчас: позже Portal покажет только наличие токена',
            'token.staticPresent' => 'есть',
            'token.staticMissing' => 'нет',
            'users.saveDone' => 'Пользователь сохранён',
            'users.saving' => 'Сохраняем пользователя...',
            'geo.latitude' => 'Широта',
            'geo.longitude' => 'Долгота',
        ];
        $messages['en'] += [
            'nav.section.view' => 'View',
            'nav.section.admin' => 'Admin',
            'action.sync' => 'Sync',
            'action.revoke' => 'Revoke',
            'column.static_token_hash' => 'Static token',
            'token.static' => 'Static token',
            'token.staticIssue' => 'Issue static token',
            'token.staticReplace' => 'Replace static token',
            'token.staticReplaceConfirm' => 'The old static token will stop working immediately. Issue a new token?',
            'token.staticIssued' => 'New static token. Save it now: later Portal will only show that a token exists',
            'token.staticPresent' => 'present',
            'token.staticMissing' => 'missing',
            'users.saveDone' => 'User saved',
            'users.saving' => 'Saving user...',
            'geo.latitude' => 'Latitude',
            'geo.longitude' => 'Longitude',
        ];
        $messages['de'] += [
            'nav.section.view' => 'Ansicht',
            'nav.section.admin' => 'Administration',
            'nav.dvr' => 'DVR',
            'js.openVideo' => 'Video öffnen',
            'js.previewUnavailable' => 'Vorschau nicht verfügbar',
            'js.mapChangePending' => 'Kartenänderung bestätigen',
            'js.addFavorite' => 'Zu Favoriten hinzufügen',
            'js.removeFavorite' => 'Aus Favoriten entfernen',
            'js.selectedCount' => 'Ausgewählt',
            'assignment.selectedOnly' => 'Nur ausgewählte',
            'assignment.empty' => 'Nichts gefunden',
            'assignment.searchUsers' => 'Benutzer suchen',
            'assignment.searchCameras' => 'Kamera suchen',
            'dashboard.metricsUpdated' => 'Statistik aktualisiert',
            'dashboard.metricsRefreshFailed' => 'Statistik wurde nicht aktualisiert. Details stehen in der Serverkarte.',
            'dashboard.refreshFinished' => 'Aktualisierung abgeschlossen',
            'dashboard.refreshOk' => 'erfolgreich',
            'dashboard.refreshErrors' => 'mit Fehlern',
            'server.checkOk' => 'Serverprüfung abgeschlossen',
            'server.managementTokenUnreadableNotice' => 'Management-Token kann nicht entschlüsselt werden. Speichern Sie ein neues Token in den DVR-Servereinstellungen.',
            'server.managementUnauthorizedNotice' => 'SesameDVR hat HTTP 401 zurückgegeben. Prüfen Sie das Management-Token in den DVR-Servereinstellungen.',
            'users.loginRequired' => 'Login ist erforderlich',
            'users.passwordShort' => 'Das Passwort muss mindestens 6 Zeichen lang sein',
            'users.passwordPlaceholderNew' => 'mindestens 6 Zeichen',
            'users.passwordPlaceholderEdit' => 'leer lassen, um nicht zu ändern',
            'users.blocked' => 'Gesperrt',
            'cameras.serverAutoNone' => 'Automatisch/nicht ausgewählt',
            'cameras.serverSelection' => 'Serverauswahl',
            'cameras.selectionManual' => 'konkret',
            'cameras.selectionAuto' => 'automatisch zufällig',
            'cameras.streamName' => 'Technischer Streamname',
            'cameras.nameOrStreamRequired' => 'Stream-Titel oder technischer Streamname ist erforderlich',
            'cameras.invalidStreamName' => 'Der technische Streamname darf nur lateinische Buchstaben, Ziffern, Bindestrich und Unterstrich enthalten, maximal 128 Zeichen.',
            'cameras.streamNameHint' => 'Nur A-Z, a-z, 0-9, Bindestrich und Unterstrich. Leer lassen, um den Namen automatisch zu erzeugen.',
            'cameras.clearPosition' => 'Punkt löschen',
            'cameras.viewAngle' => 'Blickwinkel',
            'cameras.blocked' => 'Gesperrt',
            'cameras.mode' => 'Kameramodus',
            'cameras.modeManaged' => 'Vollständige DVR-Verwaltung',
            'cameras.modeReadOnly' => 'Read-only DVR-Stream',
            'cameras.sourceRequired' => 'Quell-URL ist für vollständige DVR-Verwaltung erforderlich',
            'cameras.readOnlySyncSkipped' => 'Read-only-Modus: DVR-Verwaltung übersprungen',
            'servers.managementKey' => 'Management-Token',
            'servers.blocked' => 'Gesperrt',
            'audit.search' => 'Aktion, Benutzer oder Details suchen',
            'audit.allActions' => 'Alle Aktionen',
            'audit.allUsers' => 'Alle Benutzer',
            'audit.raw' => 'Volltext',
            'action.sync' => 'Synchronisieren',
            'action.revoke' => 'Widerrufen',
            'token.static' => 'Statisches Token',
            'geo.latitude' => 'Breite',
            'geo.longitude' => 'Länge',
        ];
        $messages['fr'] += [
            'nav.section.view' => 'Vue',
            'nav.section.admin' => 'Admin',
            'action.sync' => 'Synchroniser',
            'action.revoke' => 'Révoquer',
            'token.static' => 'Jeton statique',
            'geo.latitude' => 'Latitude',
            'geo.longitude' => 'Longitude',
        ];
        $messages['es'] += [
            'nav.section.view' => 'Vista',
            'nav.section.admin' => 'Admin',
            'action.sync' => 'Sincronizar',
            'action.revoke' => 'Revocar',
            'token.static' => 'Token estático',
            'geo.latitude' => 'Latitud',
            'geo.longitude' => 'Longitud',
        ];
        $messages['it'] += [
            'nav.section.view' => 'Vista',
            'nav.section.admin' => 'Admin',
            'action.sync' => 'Sincronizza',
            'action.revoke' => 'Revoca',
            'token.static' => 'Token statico',
            'geo.latitude' => 'Latitudine',
            'geo.longitude' => 'Longitudine',
        ];
        $messages['pt'] += [
            'nav.section.view' => 'Vista',
            'nav.section.admin' => 'Admin',
            'action.sync' => 'Sincronizar',
            'action.revoke' => 'Revogar',
            'token.static' => 'Token estático',
            'geo.latitude' => 'Latitude',
            'geo.longitude' => 'Longitude',
        ];
        $messages['bg'] += [
            'nav.section.view' => 'Изглед',
            'nav.section.admin' => 'Админ',
            'action.sync' => 'Синхронизирай',
            'action.revoke' => 'Отмени',
            'token.static' => 'Статичен token',
            'geo.latitude' => 'Ширина',
            'geo.longitude' => 'Дължина',
        ];
        $messages['pl'] += [
            'nav.section.view' => 'Widok',
            'nav.section.admin' => 'Admin',
            'action.sync' => 'Synchronizuj',
            'action.revoke' => 'Unieważnij',
            'token.static' => 'Token statyczny',
            'geo.latitude' => 'Szerokość',
            'geo.longitude' => 'Długość',
        ];
        $messages['zh'] += [
            'nav.section.view' => '查看',
            'nav.section.admin' => '管理',
            'action.sync' => '同步',
            'action.revoke' => '撤销',
            'token.static' => '静态令牌',
            'geo.latitude' => '纬度',
            'geo.longitude' => '经度',
        ];
        $messages['ja'] += [
            'nav.section.view' => '表示',
            'nav.section.admin' => '管理',
            'action.sync' => '同期',
            'action.revoke' => '取り消し',
            'token.static' => '静的トークン',
            'geo.latitude' => '緯度',
            'geo.longitude' => '経度',
        ];
        $messages['ko'] += [
            'nav.section.view' => '보기',
            'nav.section.admin' => '관리자',
            'action.sync' => '동기화',
            'action.revoke' => '취소',
            'token.static' => '정적 토큰',
            'geo.latitude' => '위도',
            'geo.longitude' => '경도',
        ];
        $messages['ar'] += [
            'nav.section.view' => 'العرض',
            'nav.section.admin' => 'الإدارة',
            'action.sync' => 'مزامنة',
            'action.revoke' => 'إلغاء',
            'token.static' => 'رمز ثابت',
            'geo.latitude' => 'خط العرض',
            'geo.longitude' => 'خط الطول',
        ];
        $messages['hy'] += [
            'nav.section.view' => 'Դիտում',
            'nav.section.admin' => 'Ադմին',
            'action.sync' => 'Համաժամացնել',
            'action.revoke' => 'Չեղարկել',
            'token.static' => 'Ստատիկ token',
            'geo.latitude' => 'Լայնություն',
            'geo.longitude' => 'Երկայնություն',
        ];

        foreach (array_keys(self::LOCALES) as $locale) {
            $messages[$locale] ??= [];
            if ($locale !== 'ru') {
                $messages[$locale] += $messages['en'];
            }
        }

        return $messages;
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
        self::logForUser($user['id'] ?? null, $action, $details);
    }

    public static function logForUser(int|string|null $userId, string $action, string $details = ''): void
    {
        DB::pdo()->prepare('INSERT INTO audit_logs(actor_user_id, action, details, created_at) VALUES(?, ?, ?, ?)')
            ->execute([$userId !== null ? (int)$userId : null, $action, $details, Util::now()]);
    }

    public static function clientIp(): string
    {
        $raw = (string)(
            $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? ''
        );
        $ip = trim(explode(',', $raw, 2)[0]);
        return substr(preg_replace('/[^\w:. -]/', '', $ip) ?: '', 0, 80);
    }

    public static function cleanValue(string $value, int $maxBytes = 160): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?: '';
        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
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

    public static function issueStaticToken(int $userId, ?array $actor = null): string
    {
        $user = self::staticTokenUser($userId);
        $hadToken = !empty($user['static_token_hash']);
        $token = 'sp_' . Util::randomToken();
        DB::pdo()->prepare('UPDATE users SET static_token_hash = ? WHERE id = ?')
            ->execute([password_hash($token, PASSWORD_DEFAULT), $userId]);
        self::logStaticTokenEvent(
            $actor,
            $hadToken ? 'user.static_token.replace' : 'user.static_token.issue',
            $userId,
            $user,
            ['previous=' . ($hadToken ? 'yes' : 'no')]
        );
        return $token;
    }

    public static function revokeStaticToken(int $userId, ?array $actor = null): void
    {
        $user = self::staticTokenUser($userId);
        $hadToken = !empty($user['static_token_hash']);
        DB::pdo()->prepare('UPDATE users SET static_token_hash = NULL WHERE id = ?')->execute([$userId]);
        self::logStaticTokenEvent(
            $actor,
            'user.static_token.revoke',
            $userId,
            $user,
            ['previous=' . ($hadToken ? 'yes' : 'no')]
        );
    }

    private static function staticTokenUser(int $userId): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT id, login, static_token_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    private static function logStaticTokenEvent(?array $actor, string $action, int $userId, ?array $target, array $parts = []): void
    {
        $details = array_merge([
            'user_id=' . $userId,
            'login=' . Audit::cleanValue((string)($target['login'] ?? '')),
            'ip=' . Audit::clientIp(),
        ], $parts);
        $actorId = $actor['id'] ?? null;
        if ($actorId !== null) {
            Audit::logForUser($actorId, $action, implode(' ', $details));
            return;
        }
        Audit::log($action, implode(' ', $details));
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

    public static function userByStaticToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $stmt = DB::pdo()->query('SELECT * FROM users WHERE blocked = 0 AND static_token_hash IS NOT NULL');
        foreach ($stmt->fetchAll() as $user) {
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
            Audit::logForUser($user['id'] ?? null, 'auth.login_failed', 'login=' . Audit::cleanValue($login) . ' ip=' . Audit::clientIp());
            return false;
        }

        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        TokenService::ensureUserTokens((int)$user['id']);
        DB::pdo()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Util::now(), $user['id']]);
        Audit::logForUser((int)$user['id'], 'auth.login', 'login=' . Audit::cleanValue((string)$user['login']) . ' ip=' . Audit::clientIp());
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
        $path = Util::path();
        if ($path === '/api/portal/v1' || str_starts_with($path, '/api/portal/v1/')) {
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

        $controlMode = (string)($camera['dvr_control_mode'] ?? 'managed');
        if ($controlMode === 'read_only') {
            return self::storeCameraSync($cameraId, true, I18n::t('cameras.readOnlySyncSkipped', 'Read-only mode: DVR management skipped'));
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        if ($token === '') {
            return self::storeCameraSync($cameraId, false, 'SesameDVR management token is missing');
        }

        $name = trim((string)($camera['dvr_stream_name'] ?: $camera['name']));
        $displayName = trim((string)($camera['name'] ?? ''));
        $displayName = $displayName !== '' ? $displayName : $name;
        if (!Util::isDvrStreamName($name)) {
            return self::storeCameraSync($cameraId, false, I18n::t('cameras.invalidStreamName', 'Technical stream name can contain only Latin letters, digits, hyphen, and underscore, up to 128 characters.'));
        }

        if ($controlMode === 'edge_agent') {
            $agentId = trim((string)($camera['agent_id'] ?? ''));
            $agentCameraId = trim((string)($camera['agent_camera_id'] ?? ''));
            if ($name === '' || $agentId === '' || $agentCameraId === '') {
                return self::storeCameraSync($cameraId, false, I18n::t('cameras.agentRequired', 'Edge-agent mode requires server, Agent ID, and Agent camera ID'));
            }

            $payload = [
                'name' => $name,
                'displayName' => $displayName,
                'sourceType' => 'push',
                'source' => 'push://' . $name,
                'enabled' => ((int)$camera['blocked'] === 0),
                'retentionDays' => $camera['retention_days'],
                'authMode' => 'authBackend',
                'push' => [
                    'transport' => 'rtmp',
                    'publisherKind' => 'agent',
                    'streamName' => $name,
                    'requested' => true,
                    'agentId' => $agentId,
                    'agentCameraId' => $agentCameraId,
                    'onvifEventsRequested' => (int)($camera['onvif_events_requested'] ?? 0) === 1,
                ],
            ];
        } else {
            $existing = self::fetchStream($server, $token, $name);
            if (is_array($existing) && self::streamUsesAgentPublisher($existing)) {
                return self::storeCameraSync($cameraId, false, I18n::t('cameras.agentOverwriteBlocked', 'This DVR stream is already managed by Edge Agent. Switch the Portal camera to Edge Agent or read-only mode to avoid overwriting push configuration.'));
            }

            $payload = [
                'name' => $name,
                'displayName' => $displayName,
                'sourceType' => 'direct',
                'source' => $camera['source_url'],
                'push' => null,
                'enabled' => ((int)$camera['blocked'] === 0),
                'retentionDays' => $camera['retention_days'],
                'authMode' => 'authBackend',
            ];
        }

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

    public static function deleteCameraStream(int $cameraId, bool $purgeArchive): array
    {
        $camera = Repo::camera($cameraId);
        if (!$camera) {
            return ['ok' => false, 'message' => 'camera_not_found'];
        }
        if (!$camera['server_id']) {
            return ['ok' => false, 'message' => 'No SesameDVR server selected'];
        }
        if (($camera['dvr_control_mode'] ?? 'managed') === 'read_only') {
            return ['ok' => false, 'message' => I18n::t('cameras.deleteDvrReadOnly', 'Read-only camera: DVR stream deletion is unavailable')];
        }

        $server = Repo::server((int)$camera['server_id']);
        if (!$server || (int)$server['blocked'] === 1) {
            return ['ok' => false, 'message' => 'SesameDVR server is unavailable or blocked'];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $tokenIssue = self::managementTokenIssue($server, $token);
        if ($tokenIssue !== null) {
            return ['ok' => false, 'message' => self::managementTokenIssueMessage($tokenIssue), 'reason' => $tokenIssue];
        }

        $name = trim((string)($camera['dvr_stream_name'] ?: $camera['name']));
        if ($name === '') {
            return ['ok' => false, 'message' => 'DVR stream name is empty'];
        }
        if (!Util::isDvrStreamName($name)) {
            return ['ok' => false, 'message' => I18n::t('cameras.invalidStreamName', 'Technical stream name can contain only Latin letters, digits, hyphen, and underscore, up to 128 characters.')];
        }

        $endpoint = rtrim($server['base_url'], '/') . '/api/streams/' . rawurlencode($name);
        if ($purgeArchive) {
            $endpoint .= '?purge=true';
        }
        $result = self::request('DELETE', $endpoint, $token, null, $purgeArchive ? 300 : 12);
        $message = self::responseSummary($result, $endpoint);
        if ((int)$result['status'] === 404) {
            return ['ok' => true, 'message' => $message . ' stream already absent'];
        }

        return [
            'ok' => $result['status'] >= 200 && $result['status'] < 300,
            'message' => $message,
        ];
    }

    public static function checkServer(int $serverId): array
    {
        $server = Repo::server($serverId);
        if (!$server) {
            return ['ok' => false, 'message' => 'server_not_found'];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $tokenIssue = self::managementTokenIssue($server, $token);
        if ($tokenIssue !== null) {
            $message = self::managementTokenIssueMessage($tokenIssue);
            DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ? WHERE id = ?')
                ->execute([Util::now(), $message, $serverId]);
            return ['ok' => false, 'message' => $message, 'reason' => $tokenIssue];
        }

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
        $tokenIssue = self::managementTokenIssue($server, $token);
        if ($tokenIssue !== null) {
            $message = self::managementTokenIssueMessage($tokenIssue);
            $payload = [
                'version' => ['error' => $tokenIssue],
                'status' => ['error' => $tokenIssue],
                'streams' => ['error' => $tokenIssue],
                'fetchedAt' => Util::now(),
            ];
            DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ?, last_metrics_at = ?, last_metrics_json = ? WHERE id = ?')
                ->execute([Util::now(), $message, Util::now(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $serverId]);
            return ['ok' => false, 'message' => $message, 'metrics' => $payload, 'reason' => $tokenIssue];
        }

        $base = rtrim($server['base_url'], '/');
        $version = self::request('GET', $base . '/api/system/version', $token, null);
        $status = self::request('GET', $base . '/api/system/status', $token, null);
        $streams = self::request('GET', $base . '/api/streams', $token, null);
        $ok = $version['status'] >= 200 && $version['status'] < 300
            && $status['status'] >= 200 && $status['status'] < 300
            && $streams['status'] >= 200 && $streams['status'] < 300;
        $payload = [
            'version' => self::jsonOrBody($version),
            'status' => self::jsonOrBody($status),
            'streams' => self::jsonOrBody($streams),
            'fetchedAt' => Util::now(),
        ];
        $message = self::responseSummary($version, $base . '/api/system/version') . '; ' .
            self::responseSummary($status, $base . '/api/system/status') . '; ' .
            self::responseSummary($streams, $base . '/api/streams');
        DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ?, last_metrics_at = ?, last_metrics_json = ? WHERE id = ?')
            ->execute([Util::now(), $message, Util::now(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $serverId]);
        return ['ok' => $ok, 'message' => $message, 'metrics' => $payload];
    }

    public static function listAgents(int $serverId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/agents');
    }

    public static function createAgent(int $serverId, array $payload): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents', $payload);
    }

    public static function updateAgent(int $serverId, string $agentId, array $payload): array
    {
        return self::apiRequest($serverId, 'PATCH', '/api/agents/' . rawurlencode($agentId), $payload);
    }

    public static function deleteAgent(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'DELETE', '/api/agents/' . rawurlencode($agentId));
    }

    public static function setAgentEnrollmentPassword(int $serverId, string $agentId, string $password): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/enrollment-password', ['password' => $password]);
    }

    public static function revokeAgent(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/revoke');
    }

    public static function rotateAgentSecret(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/rotate-secret');
    }

    public static function agentCameras(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/agents/' . rawurlencode($agentId) . '/cameras');
    }

    public static function scanAgentCameras(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/cameras/scan');
    }

    public static function agentDiagnostics(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/diagnostics');
    }

    public static function agentCommands(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/agents/' . rawurlencode($agentId) . '/commands');
    }

    public static function agentCommand(int $serverId, string $agentId, string $command, array $payload, ?int $timeoutMs = null): array
    {
        $body = [
            'command' => $command,
            'payload' => $payload === [] ? new \stdClass() : $payload,
        ];
        if ($timeoutMs !== null && $timeoutMs > 0) {
            $body['timeoutMs'] = $timeoutMs;
        }
        return self::apiRequest($serverId, 'POST', '/api/agents/' . rawurlencode($agentId) . '/commands', $body);
    }

    public static function agentLogs(int $serverId, string $agentId): array
    {
        return self::apiRequest($serverId, 'GET', '/api/agents/' . rawurlencode($agentId) . '/logs');
    }

    public static function agentSnapshot(int $serverId, string $agentId, string $cameraId, bool $fresh = false): array
    {
        $path = '/api/agents/' . rawurlencode($agentId) . '/cameras/' . rawurlencode($cameraId) . '/snapshot.jpg';
        $path .= '?timeoutMs=2500' . ($fresh ? '&fresh=true' : '');
        return self::apiRequest($serverId, 'GET', $path, null, 5, false);
    }

    private static function managementTokenIssue(array $server, string $token): ?string
    {
        if ($token !== '') {
            return null;
        }

        $encoded = trim((string)($server['management_token_enc'] ?? ''));
        return $encoded === '' ? 'management_token_missing' : 'management_token_unreadable';
    }

    private static function managementTokenIssueMessage(string $issue): string
    {
        return $issue === 'management_token_unreadable'
            ? 'Management token cannot be decrypted'
            : 'Management token is not configured';
    }

    private static function apiRequest(int $serverId, string $method, string $path, ?array $payload = null, int $timeout = 12, bool $decodeJson = true): array
    {
        $server = Repo::server($serverId);
        if (!$server || (int)$server['blocked'] === 1) {
            return ['ok' => false, 'status' => 0, 'message' => 'SesameDVR server is unavailable or blocked', 'data' => null];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        $tokenIssue = self::managementTokenIssue($server, $token);
        if ($tokenIssue !== null) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => self::managementTokenIssueMessage($tokenIssue),
                'reason' => $tokenIssue,
                'data' => null,
            ];
        }

        $endpoint = rtrim($server['base_url'], '/') . $path;
        $result = self::request($method, $endpoint, $token, $payload, $timeout);
        $ok = $result['status'] >= 200 && $result['status'] < 300;
        return [
            'ok' => $ok,
            'status' => (int)$result['status'],
            'message' => self::responseSummary($result, $endpoint),
            'data' => $decodeJson ? self::jsonOrBody($result) : $result['body'],
            'contentType' => (string)($result['content_type'] ?? ''),
        ];
    }

    private static function fetchStream(array $server, string $token, string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        $endpoint = rtrim($server['base_url'], '/') . '/api/streams/' . rawurlencode($name);
        $result = self::request('GET', $endpoint, $token, null, 8);
        if ((int)$result['status'] < 200 || (int)$result['status'] >= 300) {
            return null;
        }

        $decoded = json_decode((string)$result['body'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function streamUsesAgentPublisher(array $stream): bool
    {
        $push = $stream['push'] ?? null;
        if (!is_array($push)) {
            return false;
        }

        return ($stream['sourceType'] ?? $stream['source_type'] ?? '') === 'push'
            && ($push['publisherKind'] ?? $push['publisher_kind'] ?? '') === 'agent';
    }

    private static function request(string $method, string $url, string $token, ?array $payload, int $timeout = 12): array
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
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => $body === false ? $error : (string)$body, 'content_type' => (string)$contentType];
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

final class PortalUpdateService
{
    public static function status(bool $force = false, bool $allowRefresh = true): array
    {
        $enabled = (bool)Config::get('portal_update_enabled', true);
        $current = self::currentRelease();
        $cache = self::readCache();
        $latest = is_array($cache['latest'] ?? null) ? $cache['latest'] : null;
        $checkedAt = is_string($cache['checkedAt'] ?? null) ? $cache['checkedAt'] : null;
        $checkError = is_string($cache['error'] ?? null) ? $cache['error'] : null;

        if ($enabled && $allowRefresh && self::shouldRefresh($cache, $force)) {
            try {
                $latest = self::fetchLatest();
                $checkedAt = Util::now();
                $checkError = null;
                self::writeCache([
                    'checkedAt' => $checkedAt,
                    'latest' => $latest,
                    'error' => null,
                ]);
            } catch (\Throwable $error) {
                $checkedAt = Util::now();
                $checkError = $error->getMessage();
                self::writeCache([
                    'checkedAt' => $checkedAt,
                    'latest' => $latest,
                    'error' => $checkError,
                ]);
            }
        }

        $currentCommit = self::normalizeSha((string)($current['sourceCommit'] ?? ''));
        $latestCommit = self::normalizeSha((string)($latest['sourceCommit'] ?? ''));
        $updateAvailable = $enabled && $currentCommit !== '' && $latestCommit !== '' && !hash_equals($currentCommit, $latestCommit);

        return [
            'enabled' => $enabled,
            'repo' => self::githubRepo(),
            'ref' => self::githubRef(),
            'current' => $current,
            'latest' => $latest,
            'checkedAt' => $checkedAt,
            'checkError' => $checkError,
            'stale' => self::cacheIsStale($cache),
            'updateAvailable' => $updateAvailable,
            'upToDate' => $enabled && $currentCommit !== '' && $latestCommit !== '' && hash_equals($currentCommit, $latestCommit),
            'toolInstalled' => self::toolInstalled(),
            'command' => (string)Config::get('portal_update_command', ''),
        ];
    }

    public static function cachedStatus(): array
    {
        return self::status(false, false);
    }

    public static function run(): array
    {
        if (!(bool)Config::get('portal_update_enabled', true)) {
            return ['ok' => false, 'status' => 1, 'output' => 'Portal updates are disabled'];
        }

        $command = trim((string)Config::get('portal_update_command', 'sudo -n /usr/local/sbin/sesame-portal-update'));
        if ($command === '') {
            return ['ok' => false, 'status' => 1, 'output' => 'Portal update command is not configured'];
        }
        if (!function_exists('exec')) {
            return ['ok' => false, 'status' => 1, 'output' => 'PHP exec() is disabled'];
        }

        $repo = self::githubRepo();
        $ref = self::githubRef();
        $fullCommand = $command;
        if ((bool)Config::get('portal_update_pass_args', false)) {
            $args = [
                '--repo', $repo,
                '--ref', $ref,
                '--install-link', self::installLink(),
                '--state-dir', Config::stateDir(),
                '--php-bin', PHP_BINARY ?: 'php',
            ];
            foreach ($args as $arg) {
                $fullCommand .= ' ' . escapeshellarg((string)$arg);
            }
        }
        $fullCommand .= ' 2>&1';

        Audit::log('portal.update.start', 'repo=' . Audit::cleanValue($repo) . ' ref=' . Audit::cleanValue($ref) . ' ip=' . Audit::clientIp());
        $lines = [];
        $status = 1;
        exec($fullCommand, $lines, $status);
        $output = mb_strcut(implode("\n", $lines), 0, 5000, 'UTF-8');
        $details = 'repo=' . Audit::cleanValue($repo) . ' ref=' . Audit::cleanValue($ref) . ' rc=' . $status . ' ip=' . Audit::clientIp();
        Audit::log($status === 0 ? 'portal.update.complete' : 'portal.update.failed', $details);
        if ($status === 0) {
            @unlink(self::cachePath());
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        }

        return [
            'ok' => $status === 0,
            'status' => $status,
            'output' => $output,
        ];
    }

    public static function currentRelease(): array
    {
        $root = Config::root();
        $releaseFile = $root . '/RELEASE.json';
        $data = is_file($releaseFile) ? json_decode((string)file_get_contents($releaseFile), true) : [];
        $data = is_array($data) ? $data : [];

        $deployedRevision = $root . '/.deployed-revision';
        if (empty($data['sourceCommit']) && is_file($deployedRevision)) {
            $data['sourceCommit'] = trim((string)file_get_contents($deployedRevision));
        }
        if (empty($data['sourceCommit']) && is_dir($root . '/.git')) {
            $lines = [];
            $status = 1;
            if (function_exists('exec')) {
                exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null', $lines, $status);
                if ($status === 0 && !empty($lines[0])) {
                    $data['sourceCommit'] = trim((string)$lines[0]);
                }
                $lines = [];
                exec('git -C ' . escapeshellarg($root) . ' describe --tags --always --dirty 2>/dev/null', $lines, $status);
                if ($status === 0 && !empty($lines[0]) && empty($data['version'])) {
                    $data['version'] = trim((string)$lines[0]);
                }
            }
        }

        $data += [
            'name' => 'SesamePortal',
            'version' => 'dev',
            'sourceCommit' => '',
            'dirty' => null,
            'builtAt' => null,
        ];
        $data['root'] = $root;
        return $data;
    }

    private static function fetchLatest(): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl extension is not available');
        }

        $repo = self::githubRepo();
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
            throw new RuntimeException('Invalid GitHub repo format');
        }

        $url = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode(self::githubRef());
        $ch = curl_init($url);
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: SesamePortal',
        ];
        $token = trim((string)Config::get('portal_update_github_token', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int)(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('GitHub check failed: HTTP ' . $status . ($error !== '' ? ' ' . $error : ''));
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data) || empty($data['sha'])) {
            throw new RuntimeException('GitHub response does not contain commit sha');
        }

        $message = (string)($data['commit']['message'] ?? '');
        $message = strtok($message, "\n") ?: $message;
        $sha = (string)$data['sha'];
        return [
            'version' => substr($sha, 0, 12),
            'sourceCommit' => $sha,
            'commitDate' => (string)($data['commit']['committer']['date'] ?? $data['commit']['author']['date'] ?? ''),
            'message' => $message,
            'url' => (string)($data['html_url'] ?? ''),
        ];
    }

    private static function shouldRefresh(array $cache, bool $force): bool
    {
        if ($force) {
            return true;
        }
        if (!(bool)Config::get('portal_update_auto_check', true)) {
            return false;
        }
        if (!is_array($cache['latest'] ?? null)) {
            return true;
        }
        return self::cacheIsStale($cache);
    }

    private static function cacheIsStale(array $cache): bool
    {
        $checkedAt = strtotime((string)($cache['checkedAt'] ?? ''));
        if (!$checkedAt) {
            return true;
        }
        $ttl = max(60, (int)Config::get('portal_update_check_ttl_seconds', 600));
        return time() - $checkedAt > $ttl;
    }

    private static function readCache(): array
    {
        $path = self::cachePath();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private static function writeCache(array $data): void
    {
        $path = self::cachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function cachePath(): string
    {
        return rtrim(Config::stateDir(), '/') . '/portal-update-status.json';
    }

    private static function githubRepo(): string
    {
        return trim((string)Config::get('portal_update_github_repo', 'rosteleset/SesamePortal'));
    }

    private static function githubRef(): string
    {
        return trim((string)Config::get('portal_update_github_ref', 'main')) ?: 'main';
    }

    private static function installLink(): string
    {
        return trim((string)Config::get('portal_update_install_link', '')) ?: Config::root();
    }

    private static function toolInstalled(): bool
    {
        $command = (string)Config::get('portal_update_command', '');
        if (preg_match('/(?:^|\s)(\/[^\s]+sesame-portal-update)(?:\s|$)/', $command, $match)) {
            return is_executable($match[1]);
        }
        return trim($command) !== '';
    }

    private static function normalizeSha(string $sha): string
    {
        $sha = strtolower(trim($sha));
        return preg_match('/^[a-f0-9]{7,40}$/', $sha) ? $sha : '';
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

    public static function groupBranchIds(array $rootIds, bool $includeRoots = true, bool $includeBlocked = true): array
    {
        $groups = self::all('portal_groups', 'name ASC');
        $byId = [];
        $children = [];
        foreach ($groups as $group) {
            $id = (int)$group['id'];
            $byId[$id] = $group;
            $parentId = (int)($group['parent_group_id'] ?? 0);
            if ($parentId > 0) {
                $children[$parentId][] = $id;
            }
        }

        $result = [];
        $seen = [];
        $queue = [];
        foreach ($rootIds as $rootId) {
            $rootId = (int)$rootId;
            if ($rootId <= 0 || !isset($byId[$rootId])) {
                continue;
            }
            if (!$includeBlocked && (int)($byId[$rootId]['blocked'] ?? 0) === 1) {
                continue;
            }
            if ($includeRoots) {
                $result[] = $rootId;
                $seen[$rootId] = true;
            }
            $queue[] = $rootId;
        }

        while ($queue) {
            $parentId = array_shift($queue);
            foreach ($children[$parentId] ?? [] as $childId) {
                if (isset($seen[$childId])) {
                    continue;
                }
                if (!$includeBlocked && (int)($byId[$childId]['blocked'] ?? 0) === 1) {
                    continue;
                }
                $seen[$childId] = true;
                $result[] = $childId;
                $queue[] = $childId;
            }
        }

        return array_values(array_unique($result));
    }

    public static function directUserGroupIds(int $userId): array
    {
        $stmt = DB::pdo()->prepare('SELECT group_id FROM user_groups WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'group_id'));
    }

    public static function userAccessibleGroupIds(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return array_map('intval', array_column(self::all('portal_groups', 'name ASC'), 'id'));
        }
        return self::groupBranchIds(self::directUserGroupIds((int)$user['id']), true, false);
    }

    public static function accessibleCameras(array $user, string $filter = 'all', string $query = ''): array
    {
        [$join, $where, $params] = self::accessibleCameraScope($user, $filter, $query);
        $sql = 'SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url, s.last_metrics_json AS server_metrics_json
                FROM cameras c ' . $join . '
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.name ASC';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function accessibleCamerasPage(array $user, string $filter, string $query, int $page, int $pageSize): array
    {
        [$join, $where, $params] = self::accessibleCameraScope($user, $filter, $query);
        $pdo = DB::pdo();
        $pageSize = max(1, $pageSize);
        $count = $pdo->prepare('SELECT COUNT(DISTINCT c.id) FROM cameras c ' . $join . ' WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);

        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url, s.last_metrics_json AS server_metrics_json
             FROM cameras c ' . $join . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.name ASC
             LIMIT ? OFFSET ?'
        );
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
            'filter' => $filter,
            'q' => $query,
        ];
    }

    private static function accessibleCameraScope(array $user, string $filter, string $query = ''): array
    {
        $params = [];
        $where = ['c.blocked = 0'];
        $join = 'LEFT JOIN dvr_servers s ON s.id = c.server_id';

        if ($user['role'] !== 'admin') {
            $accessGroupIds = self::userAccessibleGroupIds($user);
            if (!$accessGroupIds) {
                $where[] = '1 = 0';
            } else {
                $join .= ' JOIN camera_groups cg_access ON cg_access.camera_id = c.id';
                $where[] = 'cg_access.group_id IN (' . self::placeholders($accessGroupIds) . ')';
                array_push($params, ...$accessGroupIds);
            }
        }

        if ($filter === 'favorites') {
            $join .= ' JOIN favorites f ON f.camera_id = c.id AND f.user_id = ?';
            $params[] = (int)$user['id'];
        } elseif (str_starts_with($filter, 'group:')) {
            $groupId = (int)substr($filter, 6);
            $filterGroupIds = self::groupBranchIds([$groupId], true, $user['role'] === 'admin');
            $join .= ' JOIN camera_groups cg_filter ON cg_filter.camera_id = c.id';
            if (!$filterGroupIds) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'cg_filter.group_id IN (' . self::placeholders($filterGroupIds) . ')';
                array_push($params, ...$filterGroupIds);
            }
            if ($user['role'] !== 'admin') {
                $allowedGroupIds = self::userAccessibleGroupIds($user);
                if (!$allowedGroupIds || !array_intersect($filterGroupIds, $allowedGroupIds)) {
                    $where[] = '1 = 0';
                }
            }
        }

        $query = trim($query);
        if ($query !== '') {
            $where[] = '(' . DB::caseInsensitiveLike('c.name') . ' OR ' . DB::caseInsensitiveLike('c.dvr_stream_name') . ')';
            $needle = '%' . $query . '%';
            $params[] = $needle;
            $params[] = $needle;
        }

        return [$join, $where, $params];
    }

    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    public static function groupsForUser(array $user): array
    {
        if ($user['role'] === 'admin') {
            return self::all('portal_groups', 'name ASC');
        }

        $ids = self::userAccessibleGroupIds($user);
        if (!$ids) {
            return [];
        }
        $stmt = DB::pdo()->prepare('SELECT * FROM portal_groups WHERE id IN (' . self::placeholders($ids) . ') ORDER BY name ASC');
        $stmt->execute($ids);
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

        $groupIds = self::userAccessibleGroupIds($user);
        if (!$groupIds) {
            return false;
        }
        $stmt = DB::pdo()->prepare(
            'SELECT c.id FROM cameras c
             JOIN camera_groups cg ON cg.camera_id = c.id
             WHERE c.id = ? AND c.blocked = 0 AND cg.group_id IN (' . self::placeholders($groupIds) . ')
             LIMIT 1'
        );
        $stmt->execute([$cameraId, ...$groupIds]);
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
        if ($path === '/api/portal/v1' || str_starts_with($path, '/api/portal/v1/')) {
            self::apiPortalV1();
            return;
        }

        match ($path) {
            '/login' => self::login(),
            '/logout' => self::logout(),
            '/admin/dashboard' => self::dashboard(),
            '/admin/users' => self::users(),
            '/admin/groups' => self::groups(),
            '/admin/servers' => self::servers(),
            '/admin/agents/snapshot' => self::agentSnapshotProxy(),
            '/admin/agents' => self::agents(),
            '/admin/cameras' => self::cameras(),
            '/admin/audit' => self::audit(),
            '/admin/settings' => self::settings(),
            '/viewer/map' => self::viewer('map'),
            '/viewer/preview' => self::previewProxy(),
            '/viewer/player' => self::player(),
            '/favorite/toggle' => self::toggleFavorite(),
            '/api/sesamedvr/auth' => self::authBackend(),
            default => self::viewer('mosaic'),
        };
    }

    private static function apiPortalV1(): void
    {
        try {
            $parts = self::apiPathParts();
            $resource = $parts[0] ?? '';
            match ($resource) {
                '' => self::apiJson([
                    'name' => 'SesamePortal API',
                    'version' => 'v1',
                    'resources' => [
                        'me',
                        'dashboard',
                        'users',
                        'groups',
                        'servers',
                        'cameras',
                        'favorites',
                        'agents',
                        'audit',
                    ],
                ]),
                'me' => self::apiMe($parts),
                'dashboard' => self::apiDashboard($parts),
                'users' => self::apiUsers($parts),
                'groups' => self::apiGroups($parts),
                'servers' => self::apiServers($parts),
                'cameras' => self::apiCameras($parts),
                'favorites' => self::apiFavorites($parts),
                'agents' => self::apiAgents($parts),
                'audit' => self::apiAudit($parts),
                default => self::apiError(404, 'not_found', 'Unknown API endpoint'),
            };
        } catch (\Throwable $error) {
            self::apiError(500, 'internal_error', $error->getMessage());
        }
    }

    private static function apiPathParts(): array
    {
        $path = trim(substr(Util::path(), strlen('/api/portal/v1')), '/');
        if ($path === '') {
            return [];
        }
        return array_map('rawurldecode', array_values(array_filter(explode('/', $path), static fn($part) => $part !== '')));
    }

    private static function apiMethod(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    private static function apiInput(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($raw !== '' && (str_contains($contentType, 'application/json') || str_starts_with(trim($raw), '{'))) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                self::apiError(400, 'invalid_json', 'JSON request body must be an object');
                exit;
            }
            return $decoded;
        }
        return $_POST;
    }

    private static function apiJson(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit;
    }

    private static function apiError(int $status, string $code, string $message, array $extra = []): void
    {
        self::apiJson(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }

    private static function apiUser(): ?array
    {
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
            return TokenService::userByStaticToken(trim($match[1]));
        }
        $headerToken = trim((string)($_SERVER['HTTP_X_PORTAL_TOKEN'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? ''));
        if ($headerToken !== '') {
            return TokenService::userByStaticToken($headerToken);
        }
        return Auth::user();
    }

    private static function apiRequireUser(): array
    {
        $user = self::apiUser();
        if (!$user) {
            self::apiError(401, 'unauthorized', 'A valid session cookie or static Authorization: Bearer token is required');
            exit;
        }
        return $user;
    }

    private static function apiRequireAdmin(): array
    {
        $user = self::apiRequireUser();
        if (($user['role'] ?? '') !== 'admin') {
            self::apiError(403, 'forbidden', 'Admin role is required');
            exit;
        }
        return $user;
    }

    private static function apiBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function apiBlockedValue(array $input, ?array $current = null): int
    {
        if (array_key_exists('blocked', $input)) {
            return self::apiBool($input['blocked']) ? 1 : 0;
        }
        return $current ? (int)($current['blocked'] ?? 0) : 0;
    }

    private static function apiIntArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            $value = preg_split('/[\s,]+/', trim((string)$value)) ?: [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = (int)$item;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private static function apiValidateExistingIds(string $field, string $table, array $ids): void
    {
        $missing = self::missingIds($table, $ids);
        if ($missing === []) {
            return;
        }
        self::apiError(422, 'validation_failed', $field . ' contains unknown id(s): ' . implode(', ', $missing), [
            'field' => $field,
            'missingIds' => $missing,
        ]);
    }

    private static function apiPagination(array $pager): array
    {
        return [
            'total' => (int)($pager['total'] ?? 0),
            'page' => (int)($pager['page'] ?? 1),
            'pageSize' => (int)($pager['pageSize'] ?? 0),
        ];
    }

    private static function apiPageSize(int $default = 25, int $max = 200): int
    {
        $value = (int)($_GET['pageSize'] ?? $_GET['page_size'] ?? $default);
        return min($max, max(1, $value));
    }

    private static function apiMe(array $parts): void
    {
        if (count($parts) !== 1 || self::apiMethod() !== 'GET') {
            self::apiError(404, 'not_found', 'Unknown me endpoint');
            return;
        }
        self::apiJson(['user' => self::apiUserRow(self::apiRequireUser(), true)]);
    }

    private static function apiDashboard(array $parts): void
    {
        self::apiRequireAdmin();
        if (count($parts) !== 1) {
            self::apiError(404, 'not_found', 'Unknown dashboard endpoint');
            return;
        }
        $method = self::apiMethod();
        if ($method === 'GET') {
            $servers = array_map(fn($server) => self::apiServerRow($server, true), Repo::all('dvr_servers', 'name ASC'));
            self::apiJson([
                'counts' => [
                    'users' => (int)DB::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                    'groups' => (int)DB::pdo()->query('SELECT COUNT(*) FROM portal_groups')->fetchColumn(),
                    'cameras' => (int)DB::pdo()->query('SELECT COUNT(*) FROM cameras')->fetchColumn(),
                    'servers' => (int)DB::pdo()->query('SELECT COUNT(*) FROM dvr_servers')->fetchColumn(),
                ],
                'servers' => $servers,
            ]);
            return;
        }
        if ($method === 'POST') {
            $input = self::apiInput();
            $serverId = (int)($input['serverId'] ?? $input['server_id'] ?? 0);
            if ($serverId > 0) {
                self::apiJson(DvrClient::fetchServerMetrics($serverId));
                return;
            }
            $results = [];
            foreach (Repo::all('dvr_servers', 'name ASC') as $server) {
                if ((int)$server['blocked'] === 0) {
                    $results[] = ['serverId' => (int)$server['id']] + DvrClient::fetchServerMetrics((int)$server['id']);
                }
            }
            self::apiJson(['results' => $results]);
            return;
        }
        self::apiError(405, 'method_not_allowed', 'Method is not allowed');
    }

    private static function apiUsers(array $parts): void
    {
        $actor = self::apiRequireAdmin();
        $method = self::apiMethod();
        $id = isset($parts[1]) ? (int)$parts[1] : 0;

        if (count($parts) === 1) {
            if ($method === 'GET') {
                $list = self::filteredRows('users', ['login', 'role'], 'login ASC', self::apiPageSize());
                self::apiJson(['users' => array_map([self::class, 'apiUserRow'], $list['rows']), 'pagination' => self::apiPagination($list)]);
                return;
            }
            if ($method === 'POST') {
                self::apiSaveUser(0, self::apiInput());
                return;
            }
        }

        if ($id <= 0) {
            self::apiError(404, 'not_found', 'User not found');
            return;
        }

        if (count($parts) === 2) {
            if ($method === 'GET') {
                $user = self::rowById('users', $id);
                $user ? self::apiJson(['user' => self::apiUserRow($user, true)]) : self::apiError(404, 'not_found', 'User not found');
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveUser($id, self::apiInput());
                return;
            }
            if ($method === 'DELETE') {
                DB::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                Audit::log('user.delete', 'user_id=' . $id);
                self::apiJson(['ok' => true]);
                return;
            }
        }

        if (($parts[2] ?? '') === 'static-token') {
            if (!self::rowById('users', $id)) {
                self::apiError(404, 'not_found', 'User not found');
                return;
            }
            if ($method === 'POST') {
                self::apiJson(['token' => TokenService::issueStaticToken($id, $actor)]);
                return;
            }
            if ($method === 'DELETE') {
                TokenService::revokeStaticToken($id, $actor);
                self::apiJson(['ok' => true]);
                return;
            }
        }

        self::apiError(404, 'not_found', 'Unknown users endpoint');
    }

    private static function apiSaveUser(int $id, array $input): void
    {
        $current = $id > 0 ? self::rowById('users', $id) : null;
        if ($id > 0 && !$current) {
            self::apiError(404, 'not_found', 'User not found');
            return;
        }

        $login = trim((string)($input['login'] ?? ($current['login'] ?? '')));
        $password = (string)($input['password'] ?? '');
        $role = ($input['role'] ?? ($current['role'] ?? 'user')) === 'admin' ? 'admin' : 'user';
        $blocked = self::apiBlockedValue($input, $current);
        if ($login === '') {
            self::apiError(422, 'validation_failed', 'login is required');
            return;
        }
        if ($id === 0 && strlen($password) < 6) {
            self::apiError(422, 'validation_failed', 'password must be at least 6 characters');
            return;
        }

        $pdo = DB::pdo();
        if ($id > 0) {
            if ($password !== '') {
                if (strlen($password) < 6) {
                    self::apiError(422, 'validation_failed', 'password must be at least 6 characters');
                    return;
                }
                $pdo->prepare('UPDATE users SET login=?, password_hash=?, role=?, blocked=? WHERE id=?')
                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $id]);
            } else {
                $pdo->prepare('UPDATE users SET login=?, role=?, blocked=? WHERE id=?')
                    ->execute([$login, $role, $blocked, $id]);
            }
        } else {
            $pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, daily_token, daily_token_date, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)')
                ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, Util::randomToken(), TokenService::today(), Util::now()]);
            $id = DB::lastInsertId('users');
        }
        Audit::log('user.save', $login);
        self::apiJson(['user' => self::apiUserRow(self::rowById('users', $id), true)], $current ? 200 : 201);
    }

    private static function apiGroups(array $parts): void
    {
        self::apiRequireAdmin();
        $method = self::apiMethod();
        $id = isset($parts[1]) ? (int)$parts[1] : 0;

        if (count($parts) === 1) {
            if ($method === 'GET') {
                $list = self::filteredRows('portal_groups', ['name', 'description'], 'name ASC', self::apiPageSize());
                self::apiJson(['groups' => array_map(fn($row) => self::apiGroupRow($row), $list['rows']), 'pagination' => self::apiPagination($list)]);
                return;
            }
            if ($method === 'POST') {
                self::apiSaveGroup(0, self::apiInput());
                return;
            }
        }
        if ($id <= 0) {
            self::apiError(404, 'not_found', 'Group not found');
            return;
        }
        if (count($parts) === 2) {
            if ($method === 'GET') {
                $group = self::rowById('portal_groups', $id);
                $group ? self::apiJson(['group' => self::apiGroupRow($group, true)]) : self::apiError(404, 'not_found', 'Group not found');
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveGroup($id, self::apiInput());
                return;
            }
            if ($method === 'DELETE') {
                DB::pdo()->prepare('UPDATE portal_groups SET parent_group_id = NULL WHERE parent_group_id = ?')->execute([$id]);
                DB::pdo()->prepare('DELETE FROM portal_groups WHERE id=?')->execute([$id]);
                Audit::log('group.delete', 'group_id=' . $id);
                self::apiJson(['ok' => true]);
                return;
            }
        }
        if (count($parts) === 3 && $parts[2] === 'children') {
            self::apiGroupChildren($id);
            return;
        }
        if (count($parts) === 3 && in_array($parts[2], ['users', 'cameras'], true)) {
            self::apiGroupMembers($id, $parts[2]);
            return;
        }
        self::apiError(404, 'not_found', 'Unknown groups endpoint');
    }

    private static function apiGroupChildren(int $groupId): void
    {
        $group = self::rowById('portal_groups', $groupId);
        if (!$group) {
            self::apiError(404, 'not_found', 'Group not found');
            return;
        }

        $method = self::apiMethod();
        if ($method === 'GET') {
            $children = self::groupChildren($groupId);
            self::apiJson([
                'group' => self::apiGroupRow($group),
                'childGroupIds' => array_map('intval', array_column($children, 'id')),
                'children' => array_map(fn($row) => self::apiGroupRow($row), $children),
            ]);
            return;
        }

        if ($method === 'POST') {
            $input = self::apiInput();
            $input['parentGroupId'] = $groupId;
            self::apiSaveGroup(0, $input);
            return;
        }

        self::apiError(405, 'method_not_allowed', 'Method is not allowed');
    }

    private static function apiGroupMembers(int $groupId, string $resource): void
    {
        $group = self::rowById('portal_groups', $groupId);
        if (!$group) {
            self::apiError(404, 'not_found', 'Group not found');
            return;
        }

        $method = self::apiMethod();
        $isUsers = $resource === 'users';
        $linkTable = $isUsers ? 'user_groups' : 'camera_groups';
        $targetKey = $isUsers ? 'user_id' : 'camera_id';
        $inputKey = $isUsers ? 'userIds' : 'cameraIds';
        $inputSnakeKey = $isUsers ? 'user_ids' : 'camera_ids';

        if ($method === 'GET') {
            self::apiJson(self::apiGroupMembersPayload($group, $resource));
            return;
        }

        if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            $input = self::apiInput();
            $ids = self::apiIntArray($input[$inputKey] ?? $input[$inputSnakeKey] ?? $input['ids'] ?? []);
            if ($method !== 'PUT' && $method !== 'PATCH' && $ids === []) {
                self::apiError(422, 'validation_failed', $inputKey . ' is required');
                return;
            }
            if ($method !== 'DELETE') {
                self::apiValidateExistingIds($inputKey, $isUsers ? 'users' : 'cameras', $ids);
            }

            if ($method === 'POST') {
                self::addLinks($linkTable, 'group_id', $groupId, $targetKey, $ids);
                Audit::log('group.members.add', 'group_id=' . $groupId . ' resource=' . $resource . ' count=' . count($ids));
            } elseif ($method === 'DELETE') {
                self::removeLinks($linkTable, 'group_id', $groupId, $targetKey, $ids);
                Audit::log('group.members.remove', 'group_id=' . $groupId . ' resource=' . $resource . ' count=' . count($ids));
            } else {
                self::replaceLinks($linkTable, 'group_id', $groupId, $targetKey, $ids);
                Audit::log('group.members.replace', 'group_id=' . $groupId . ' resource=' . $resource . ' count=' . count($ids));
            }

            self::apiJson(self::apiGroupMembersPayload(self::rowById('portal_groups', $groupId), $resource));
            return;
        }

        self::apiError(405, 'method_not_allowed', 'Method is not allowed');
    }

    private static function apiGroupMembersPayload(?array $group, string $resource): array
    {
        $isUsers = $resource === 'users';
        $linkTable = $isUsers ? 'user_groups' : 'camera_groups';
        $targetTable = $isUsers ? 'users' : 'cameras';
        $targetKey = $isUsers ? 'user_id' : 'camera_id';
        $idsKey = $isUsers ? 'userIds' : 'cameraIds';
        $rowsKey = $isUsers ? 'users' : 'cameras';
        $ids = $group ? self::linkedIds($linkTable, 'group_id', (int)$group['id'], $targetKey) : [];
        $rows = array_map(
            $isUsers ? [self::class, 'apiUserRow'] : [self::class, 'apiCameraRow'],
            self::rowsByIds($targetTable, $ids)
        );

        return [
            'group' => self::apiGroupRow($group, true),
            $idsKey => $ids,
            $rowsKey => $rows,
        ];
    }

    private static function apiSaveGroup(int $id, array $input): void
    {
        [$explicitId, $explicitIdError] = self::explicitGroupIdFromInput($input);
        if ($explicitIdError !== '') {
            self::apiError(422, 'validation_failed', $explicitIdError);
            return;
        }
        if ($id > 0 && $explicitId !== null && $explicitId !== $id) {
            self::apiError(422, 'validation_failed', 'id cannot be changed');
            return;
        }
        if ($id === 0 && $explicitId !== null && self::rowById('portal_groups', $explicitId)) {
            self::apiError(409, 'group_id_exists', 'group id already exists');
            return;
        }

        $current = $id > 0 ? self::rowById('portal_groups', $id) : null;
        if ($id > 0 && !$current) {
            self::apiError(404, 'not_found', 'Group not found');
            return;
        }
        $name = trim((string)($input['name'] ?? ($current['name'] ?? '')));
        if ($name === '') {
            self::apiError(422, 'validation_failed', 'name is required');
            return;
        }
        $description = (string)($input['description'] ?? ($current['description'] ?? ''));
        $blocked = self::apiBlockedValue($input, $current);
        $parentId = self::groupParentIdFromInput($input, $current);
        $parentError = self::groupParentValidationError($id, $parentId);
        if ($parentError !== '') {
            self::apiError(422, 'validation_failed', $parentError);
            return;
        }
        $userIdsProvided = array_key_exists('userIds', $input) || array_key_exists('user_ids', $input);
        $cameraIdsProvided = array_key_exists('cameraIds', $input) || array_key_exists('camera_ids', $input);
        $userIds = $userIdsProvided ? self::apiIntArray($input['userIds'] ?? $input['user_ids'] ?? []) : [];
        $cameraIds = $cameraIdsProvided ? self::apiIntArray($input['cameraIds'] ?? $input['camera_ids'] ?? []) : [];
        if ($userIdsProvided) {
            self::apiValidateExistingIds('userIds', 'users', $userIds);
        }
        if ($cameraIdsProvided) {
            self::apiValidateExistingIds('cameraIds', 'cameras', $cameraIds);
        }
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE portal_groups SET parent_group_id=?, name=?, description=?, blocked=? WHERE id=?')
                    ->execute([$parentId, $name, $description, $blocked, $id]);
            } elseif ($explicitId !== null) {
                $pdo->prepare('INSERT INTO portal_groups(id, parent_group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?, ?)')
                    ->execute([$explicitId, $parentId, $name, $description, $blocked, Util::now()]);
                self::syncPortalGroupIdentityAfterExplicitInsert();
                $id = $explicitId;
            } else {
                $pdo->prepare('INSERT INTO portal_groups(parent_group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                    ->execute([$parentId, $name, $description, $blocked, Util::now()]);
                $id = DB::lastInsertId('portal_groups');
            }
            if ($userIdsProvided) {
                self::replaceLinks('user_groups', 'group_id', $id, 'user_id', $userIds);
            }
            if ($cameraIdsProvided) {
                self::replaceLinks('camera_groups', 'group_id', $id, 'camera_id', $cameraIds);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        Audit::log('group.save', $name);
        self::apiJson(['group' => self::apiGroupRow(self::rowById('portal_groups', $id), true)], $current ? 200 : 201);
    }

    private static function apiServers(array $parts): void
    {
        self::apiRequireAdmin();
        $method = self::apiMethod();
        $id = isset($parts[1]) ? (int)$parts[1] : 0;

        if (count($parts) === 1) {
            if ($method === 'GET') {
                $list = self::filteredRows('dvr_servers', ['name', 'base_url', 'last_check_result'], 'name ASC', self::apiPageSize());
                self::apiJson(['servers' => array_map([self::class, 'apiServerRow'], $list['rows']), 'pagination' => self::apiPagination($list)]);
                return;
            }
            if ($method === 'POST') {
                self::apiSaveServer(0, self::apiInput());
                return;
            }
        }
        if ($id <= 0) {
            self::apiError(404, 'not_found', 'Server not found');
            return;
        }
        if (count($parts) === 2) {
            if ($method === 'GET') {
                $server = Repo::server($id);
                $server ? self::apiJson(['server' => self::apiServerRow($server, true)]) : self::apiError(404, 'not_found', 'Server not found');
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveServer($id, self::apiInput());
                return;
            }
            if ($method === 'DELETE') {
                DB::pdo()->prepare('DELETE FROM dvr_servers WHERE id=?')->execute([$id]);
                Audit::log('server.delete', 'server_id=' . $id);
                self::apiJson(['ok' => true]);
                return;
            }
        }
        if (count($parts) === 3 && $method === 'POST') {
            if ($parts[2] === 'check') {
                self::apiJson(DvrClient::checkServer($id));
                return;
            }
            if ($parts[2] === 'refresh') {
                self::apiJson(DvrClient::fetchServerMetrics($id));
                return;
            }
        }
        self::apiError(404, 'not_found', 'Unknown servers endpoint');
    }

    private static function apiSaveServer(int $id, array $input): void
    {
        $current = $id > 0 ? Repo::server($id) : null;
        if ($id > 0 && !$current) {
            self::apiError(404, 'not_found', 'Server not found');
            return;
        }
        $name = trim((string)($input['name'] ?? ($current['name'] ?? '')));
        $baseUrl = rtrim(trim((string)($input['baseUrl'] ?? $input['base_url'] ?? ($current['base_url'] ?? ''))), '/');
        if ($name === '' || $baseUrl === '') {
            self::apiError(422, 'validation_failed', 'name and baseUrl are required');
            return;
        }
        $blocked = self::apiBlockedValue($input, $current);
        $tokenKeyExists = array_key_exists('managementToken', $input) || array_key_exists('management_token', $input);
        $token = $input['managementToken'] ?? $input['management_token'] ?? null;
        $enc = $current['management_token_enc'] ?? null;
        if ($tokenKeyExists) {
            $token = trim((string)$token);
            $enc = $token === '' ? null : Crypto::encrypt($token);
        }
        $pdo = DB::pdo();
        if ($id > 0) {
            $pdo->prepare('UPDATE dvr_servers SET name=?, base_url=?, management_token_enc=?, blocked=? WHERE id=?')
                ->execute([$name, $baseUrl, $enc, $blocked, $id]);
        } else {
            $pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                ->execute([$name, $baseUrl, $enc, $blocked, Util::now()]);
            $id = DB::lastInsertId('dvr_servers');
        }
        Audit::log('server.save', $name);
        self::apiJson(['server' => self::apiServerRow(Repo::server($id), true)], $current ? 200 : 201);
    }

    private static function apiCameras(array $parts): void
    {
        $user = self::apiRequireUser();
        $method = self::apiMethod();
        $id = isset($parts[1]) ? (int)$parts[1] : 0;
        $admin = ($user['role'] ?? '') === 'admin';

        if (count($parts) === 1) {
            if ($method === 'GET') {
                if ($admin && (string)($_GET['scope'] ?? 'all') !== 'accessible') {
                    $list = self::filteredCameras(self::apiPageSize(25, 500));
                } else {
                    $list = Repo::accessibleCamerasPage($user, (string)($_GET['filter'] ?? 'all'), self::viewerSearchQuery(), (int)($_GET['page'] ?? 1), self::apiPageSize(25, 500));
                }
                self::apiJson(['cameras' => array_map([self::class, 'apiCameraRow'], $list['rows']), 'pagination' => self::apiPagination($list)]);
                return;
            }
            if ($method === 'POST') {
                self::apiRequireAdmin();
                self::apiSaveCamera(0, self::apiInput());
                return;
            }
        }
        if ($id <= 0) {
            self::apiError(404, 'not_found', 'Camera not found');
            return;
        }
        if (count($parts) === 2) {
            if ($method === 'GET') {
                $camera = self::apiCameraById($id);
                if (!$camera || (!$admin && !Repo::cameraAllowedForUser($user, $id))) {
                    self::apiError(404, 'not_found', 'Camera not found');
                    return;
                }
                self::apiJson(['camera' => self::apiCameraRow($camera, true)]);
                return;
            }
            self::apiRequireAdmin();
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveCamera($id, self::apiInput());
                return;
            }
            if ($method === 'DELETE') {
                $input = self::apiInput();
                $purge = self::apiBool($input['purge'] ?? $input['deleteDvrStream'] ?? $input['delete_dvr_stream'] ?? $_GET['purge'] ?? false);
                $dvrResult = null;
                if ($purge) {
                    $dvrResult = DvrClient::deleteCameraStream($id, true);
                    if (empty($dvrResult['ok'])) {
                        self::apiError(502, 'dvr_delete_failed', (string)($dvrResult['message'] ?? 'DVR stream delete failed'));
                        return;
                    }
                }
                DB::pdo()->prepare('DELETE FROM camera_groups WHERE camera_id=?')->execute([$id]);
                DB::pdo()->prepare('DELETE FROM cameras WHERE id=?')->execute([$id]);
                Audit::log('camera.delete', 'camera_id=' . $id . ' dvr=' . ($purge ? 'yes' : 'no'));
                self::apiJson(['ok' => true, 'dvr' => $dvrResult]);
                return;
            }
        }
        if (count($parts) === 3 && $parts[2] === 'sync' && $method === 'POST') {
            self::apiRequireAdmin();
            self::apiJson(DvrClient::syncCamera($id));
            return;
        }
        self::apiError(404, 'not_found', 'Unknown cameras endpoint');
    }

    private static function apiSaveCamera(int $id, array $input): void
    {
        $current = $id > 0 ? Repo::camera($id) : null;
        if ($id > 0 && !$current) {
            self::apiError(404, 'not_found', 'Camera not found');
            return;
        }
        $controlMode = self::cameraControlMode($input['dvrControlMode'] ?? $input['dvr_control_mode'] ?? ($current['dvr_control_mode'] ?? 'managed'));
        $sourceUrl = trim((string)($input['sourceUrl'] ?? $input['source_url'] ?? ($current['source_url'] ?? '')));
        $serverId = (int)($input['serverId'] ?? $input['server_id'] ?? ($current['server_id'] ?? 0)) ?: null;
        $selection = ($input['serverSelection'] ?? $input['server_selection'] ?? ($current['server_selection'] ?? 'manual')) === 'auto' ? 'auto' : 'manual';
        if ($controlMode === 'edge_agent') {
            $selection = 'manual';
        }
        if ($selection === 'auto' && !$serverId) {
            $serverId = self::randomActiveServerId();
        }
        [$name, $stream] = self::cameraNamesFromInput($input, $current);
        if ($name === '' || $stream === '') {
            self::apiError(422, 'validation_failed', 'displayName or dvrStreamName is required');
            return;
        }
        if (!Util::isDvrStreamName($stream)) {
            self::apiError(422, 'invalid_stream_name', I18n::t('cameras.invalidStreamName', 'Technical stream name can contain only Latin letters, digits, hyphen, and underscore, up to 128 characters.'), [
                'field' => 'dvrStreamName',
                'pattern' => Util::DVR_STREAM_NAME_HTML_PATTERN,
                'maxBytes' => Util::DVR_STREAM_NAME_MAX_BYTES,
            ]);
            return;
        }
        $agentId = trim((string)($input['agentId'] ?? $input['agent_id'] ?? ($current['agent_id'] ?? '')));
        $agentCameraId = trim((string)($input['agentCameraId'] ?? $input['agent_camera_id'] ?? ($current['agent_camera_id'] ?? '')));
        if ($controlMode === 'managed' && $sourceUrl === '') {
            self::apiError(422, 'validation_failed', 'sourceUrl is required for managed cameras');
            return;
        }
        if ($controlMode === 'edge_agent' && (!$serverId || $agentId === '' || $agentCameraId === '')) {
            self::apiError(422, 'validation_failed', 'serverId, agentId, and agentCameraId are required for edge_agent cameras');
            return;
        }

        $values = [
            $name,
            $sourceUrl,
            $serverId,
            $selection,
            self::nullableFloat($input['latitude'] ?? ($current['latitude'] ?? null)),
            self::nullableFloat($input['longitude'] ?? ($current['longitude'] ?? null)),
            (int)($input['directionDeg'] ?? $input['direction_deg'] ?? ($current['direction_deg'] ?? 0)),
            (int)($input['viewAngleDeg'] ?? $input['view_angle_deg'] ?? ($current['view_angle_deg'] ?? 60)),
            (string)($input['retentionDays'] ?? $input['retention_days'] ?? ($current['retention_days'] ?? '7d')),
            $controlMode,
            $agentId !== '' ? $agentId : null,
            $agentCameraId !== '' ? $agentCameraId : null,
            array_key_exists('onvifEventsRequested', $input) || array_key_exists('onvif_events_requested', $input)
                ? (self::apiBool($input['onvifEventsRequested'] ?? $input['onvif_events_requested']) ? 1 : 0)
                : (int)($current['onvif_events_requested'] ?? 0),
            array_key_exists('watermarkEnabled', $input) || array_key_exists('watermark_enabled', $input)
                ? (self::apiBool($input['watermarkEnabled'] ?? $input['watermark_enabled']) ? 1 : 0)
                : (int)($current['watermark_enabled'] ?? 0),
            array_key_exists('watermarkIntensity', $input) || array_key_exists('watermark_intensity', $input)
                ? self::watermarkIntensity($input['watermarkIntensity'] ?? $input['watermark_intensity'])
                : self::watermarkIntensity($current['watermark_intensity'] ?? 16),
            self::apiBlockedValue($input, $current),
            $stream,
        ];

        if (array_key_exists('groupIds', $input) || array_key_exists('group_ids', $input)) {
            $groupIds = self::apiIntArray($input['groupIds'] ?? $input['group_ids'] ?? []);
            self::apiValidateExistingIds('groupIds', 'portal_groups', $groupIds);
        } else {
            $groupIds = null;
        }
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE cameras SET name=?, source_url=?, server_id=?, server_selection=?, latitude=?, longitude=?, direction_deg=?, view_angle_deg=?, retention_days=?, dvr_control_mode=?, agent_id=?, agent_camera_id=?, onvif_events_requested=?, watermark_enabled=?, watermark_intensity=?, blocked=?, dvr_stream_name=?, updated_at=? WHERE id=?')
                    ->execute([...$values, Util::now(), $id]);
            } else {
                $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, dvr_control_mode, agent_id, agent_camera_id, onvif_events_requested, watermark_enabled, watermark_intensity, blocked, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$values, Util::now(), Util::now()]);
                $id = DB::lastInsertId('cameras');
            }
            if ($groupIds !== null) {
                self::replaceLinks('camera_groups', 'camera_id', $id, 'group_id', $groupIds);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        $syncRequested = array_key_exists('sync', $input)
            ? self::apiBool($input['sync'])
            : empty($input['skipSync']);
        $sync = $syncRequested ? DvrClient::syncCamera($id) : ['ok' => true, 'message' => 'sync skipped'];
        Audit::log('camera.save', $name . ' mode=' . $controlMode . ' sync=' . ($sync['message'] ?? ''));
        self::apiJson(['camera' => self::apiCameraRow(self::apiCameraById($id), true), 'sync' => $sync], $current ? 200 : 201);
    }

    private static function apiFavorites(array $parts): void
    {
        $user = self::apiRequireUser();
        $method = self::apiMethod();
        if (count($parts) === 1 && $method === 'GET') {
            $list = Repo::accessibleCamerasPage($user, 'favorites', self::viewerSearchQuery(), (int)($_GET['page'] ?? 1), self::apiPageSize(25, 500));
            self::apiJson([
                'cameraIds' => array_map(static fn($row) => (int)$row['id'], $list['rows']),
                'cameras' => array_map([self::class, 'apiCameraRow'], $list['rows']),
                'pagination' => self::apiPagination($list),
            ]);
            return;
        }
        $cameraId = isset($parts[1]) ? (int)$parts[1] : 0;
        if ($cameraId <= 0 || !Repo::cameraAllowedForUser($user, $cameraId)) {
            self::apiError(404, 'not_found', 'Camera not found');
            return;
        }
        if ($method === 'PUT' || $method === 'POST') {
            DB::pdo()->prepare(DB::insertIgnoreSql('favorites', ['user_id', 'camera_id', 'created_at']))
                ->execute([(int)$user['id'], $cameraId, Util::now()]);
            self::apiJson(['ok' => true, 'favorite' => true]);
            return;
        }
        if ($method === 'DELETE') {
            DB::pdo()->prepare('DELETE FROM favorites WHERE user_id = ? AND camera_id = ?')->execute([(int)$user['id'], $cameraId]);
            self::apiJson(['ok' => true, 'favorite' => false]);
            return;
        }
        self::apiError(405, 'method_not_allowed', 'Method is not allowed');
    }

    private static function apiAgents(array $parts): void
    {
        self::apiRequireAdmin();
        $method = self::apiMethod();
        $input = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? self::apiInput() : [];
        $serverId = (int)($_GET['server_id'] ?? $_GET['serverId'] ?? $input['server_id'] ?? $input['serverId'] ?? 0);
        if ($serverId <= 0) {
            self::apiError(422, 'validation_failed', 'serverId is required');
            return;
        }
        $agentId = isset($parts[1]) ? trim((string)$parts[1]) : '';

        if (count($parts) === 1) {
            if ($method === 'GET') {
                self::apiJson(DvrClient::listAgents($serverId));
                return;
            }
            if ($method === 'POST') {
                $payload = [
                    'id' => trim((string)($input['id'] ?? $input['agent_id'] ?? $input['agentId'] ?? '')),
                    'name' => trim((string)($input['name'] ?? '')),
                    'enabled' => array_key_exists('enabled', $input) ? self::apiBool($input['enabled']) : true,
                    'capabilities' => is_array($input['capabilities'] ?? null)
                        ? array_values($input['capabilities'])
                        : self::agentCapabilitiesFromText((string)($input['capabilities'] ?? '')),
                ];
                if ($payload['id'] === '') {
                    self::apiError(422, 'validation_failed', 'agent id is required');
                    return;
                }
                if ($payload['name'] === '') {
                    $payload['name'] = $payload['id'];
                }
                if (!empty($input['password'])) {
                    $payload['password'] = (string)$input['password'];
                }
                self::apiJson(DvrClient::createAgent($serverId, $payload), 201);
                return;
            }
        }
        if ($agentId === '') {
            self::apiError(404, 'not_found', 'Agent not found');
            return;
        }
        if (count($parts) === 2) {
            if ($method === 'GET') {
                self::apiJson([
                    'agentId' => $agentId,
                    'cameras' => DvrClient::agentCameras($serverId, $agentId),
                    'commands' => DvrClient::agentCommands($serverId, $agentId),
                    'logs' => DvrClient::agentLogs($serverId, $agentId),
                ]);
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                $payload = [];
                if (array_key_exists('name', $input)) {
                    $payload['name'] = trim((string)$input['name']) ?: $agentId;
                }
                if (array_key_exists('enabled', $input)) {
                    $payload['enabled'] = self::apiBool($input['enabled']);
                }
                if (array_key_exists('capabilities', $input)) {
                    $payload['capabilities'] = is_array($input['capabilities'])
                        ? array_values($input['capabilities'])
                        : self::agentCapabilitiesFromText((string)$input['capabilities']);
                }
                self::apiJson(DvrClient::updateAgent($serverId, $agentId, $payload));
                return;
            }
            if ($method === 'DELETE') {
                self::apiJson(DvrClient::deleteAgent($serverId, $agentId));
                return;
            }
        }
        $tail = array_slice($parts, 2);
        if ($method === 'GET' && $tail === ['cameras']) {
            self::apiJson(DvrClient::agentCameras($serverId, $agentId));
            return;
        }
        if ($method === 'GET' && $tail === ['commands']) {
            self::apiJson(DvrClient::agentCommands($serverId, $agentId));
            return;
        }
        if ($method === 'GET' && $tail === ['logs']) {
            self::apiJson(DvrClient::agentLogs($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['enrollment-password']) {
            self::apiJson(DvrClient::setAgentEnrollmentPassword($serverId, $agentId, (string)($input['password'] ?? '')));
            return;
        }
        if ($method === 'POST' && $tail === ['revoke']) {
            self::apiJson(DvrClient::revokeAgent($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['rotate-secret']) {
            self::apiJson(DvrClient::rotateAgentSecret($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['cameras', 'scan']) {
            self::apiJson(DvrClient::scanAgentCameras($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['diagnostics']) {
            self::apiJson(DvrClient::agentDiagnostics($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['commands']) {
            $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
            self::apiJson(DvrClient::agentCommand($serverId, $agentId, trim((string)($input['command'] ?? 'test_camera')) ?: 'test_camera', $payload, isset($input['timeoutMs']) ? (int)$input['timeoutMs'] : null));
            return;
        }
        self::apiError(404, 'not_found', 'Unknown agents endpoint');
    }

    private static function apiAudit(array $parts): void
    {
        self::apiRequireAdmin();
        if (count($parts) !== 1 || self::apiMethod() !== 'GET') {
            self::apiError(404, 'not_found', 'Unknown audit endpoint');
            return;
        }
        $list = self::filteredAudit(self::apiPageSize(50, 500));
        self::apiJson(['events' => array_map([self::class, 'apiAuditRow'], $list['rows']), 'pagination' => self::apiPagination($list)]);
    }

    private static function apiUserRow(?array $user, bool $detailed = false): array
    {
        if (!$user) {
            return [];
        }
        $row = [
            'id' => (int)$user['id'],
            'login' => (string)$user['login'],
            'role' => (string)$user['role'],
            'blocked' => (int)($user['blocked'] ?? 0) === 1,
            'hasStaticToken' => !empty($user['static_token_hash']),
            'createdAt' => $user['created_at'] ?? null,
            'lastLoginAt' => $user['last_login_at'] ?? null,
        ];
        if ($detailed) {
            $row['groupIds'] = self::linkedIds('user_groups', 'user_id', (int)$user['id'], 'group_id');
        }
        return $row;
    }

    private static function apiGroupRow(?array $group, bool $detailed = false): array
    {
        if (!$group) {
            return [];
        }
        $row = [
            'id' => (int)$group['id'],
            'parentGroupId' => self::nullableInt($group['parent_group_id'] ?? null),
            'parentGroupName' => self::groupName((int)($group['parent_group_id'] ?? 0)),
            'name' => (string)$group['name'],
            'description' => (string)($group['description'] ?? ''),
            'blocked' => (int)($group['blocked'] ?? 0) === 1,
            'createdAt' => $group['created_at'] ?? null,
        ];
        if ($detailed) {
            $id = (int)$group['id'];
            $children = self::groupChildren($id);
            $row['childGroupIds'] = array_map('intval', array_column($children, 'id'));
            $row['children'] = array_map(fn($child) => self::apiGroupRow($child), $children);
            $row['userIds'] = self::linkedIds('user_groups', 'group_id', $id, 'user_id');
            $row['cameraIds'] = self::linkedIds('camera_groups', 'group_id', $id, 'camera_id');
        }
        return $row;
    }

    private static function apiServerRow(?array $server, bool $detailed = false): array
    {
        if (!$server) {
            return [];
        }
        $row = [
            'id' => (int)$server['id'],
            'name' => (string)$server['name'],
            'baseUrl' => (string)$server['base_url'],
            'blocked' => (int)($server['blocked'] ?? 0) === 1,
            'hasManagementToken' => trim((string)($server['management_token_enc'] ?? '')) !== '',
            'lastCheckAt' => $server['last_check_at'] ?? null,
            'lastCheckResult' => $server['last_check_result'] ?? null,
            'lastMetricsAt' => $server['last_metrics_at'] ?? null,
            'createdAt' => $server['created_at'] ?? null,
        ];
        if ($detailed) {
            $metrics = json_decode((string)($server['last_metrics_json'] ?? ''), true);
            $row['lastMetrics'] = is_array($metrics) ? $metrics : null;
        }
        return $row;
    }

    private static function apiCameraById(int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT c.*, s.name AS server_name, s.base_url AS server_url, s.last_metrics_json AS server_metrics_json FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id WHERE c.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function apiCameraRow(?array $camera, bool $detailed = false): array
    {
        if (!$camera) {
            return [];
        }
        $row = [
            'id' => (int)$camera['id'],
            'name' => (string)$camera['name'],
            'displayName' => (string)$camera['name'],
            'sourceUrl' => (string)($camera['source_url'] ?? ''),
            'serverId' => $camera['server_id'] !== null ? (int)$camera['server_id'] : null,
            'serverName' => $camera['server_name'] ?? null,
            'serverSelection' => (string)($camera['server_selection'] ?? 'manual'),
            'latitude' => $camera['latitude'] !== null ? (float)$camera['latitude'] : null,
            'longitude' => $camera['longitude'] !== null ? (float)$camera['longitude'] : null,
            'directionDeg' => (int)($camera['direction_deg'] ?? 0),
            'viewAngleDeg' => (int)($camera['view_angle_deg'] ?? 60),
            'retentionDays' => (string)($camera['retention_days'] ?? '7d'),
            'dvrControlMode' => (string)($camera['dvr_control_mode'] ?? 'managed'),
            'agentId' => $camera['agent_id'] ?? null,
            'agentCameraId' => $camera['agent_camera_id'] ?? null,
            'onvifEventsRequested' => (int)($camera['onvif_events_requested'] ?? 0) === 1,
            'watermarkEnabled' => (int)($camera['watermark_enabled'] ?? 0) === 1,
            'watermarkIntensity' => self::watermarkIntensity($camera['watermark_intensity'] ?? 16),
            'blocked' => (int)($camera['blocked'] ?? 0) === 1,
            'dvrStreamName' => (string)($camera['dvr_stream_name'] ?? ''),
            'streamUnavailable' => self::cameraStreamUnavailable($camera),
            'lastSyncAt' => $camera['last_sync_at'] ?? null,
            'lastSyncOk' => $camera['last_sync_ok'] === null ? null : (int)$camera['last_sync_ok'] === 1,
            'lastSyncMessage' => $camera['last_sync_message'] ?? null,
            'createdAt' => $camera['created_at'] ?? null,
            'updatedAt' => $camera['updated_at'] ?? null,
        ];
        if ($detailed) {
            $row['groupIds'] = self::linkedIds('camera_groups', 'camera_id', (int)$camera['id'], 'group_id');
        }
        return $row;
    }

    private static function apiAuditRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'actorUserId' => $row['actor_user_id'] !== null ? (int)$row['actor_user_id'] : null,
            'actorLogin' => $row['login'] ?? null,
            'action' => (string)$row['action'],
            'details' => (string)$row['details'],
            'createdAt' => (string)$row['created_at'],
        ];
    }

    private static function dashboard(): void
    {
        Auth::requireAdmin();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            if ($action === 'refresh_server') {
                $server = Repo::server((int)Util::post('id'));
                $result = DvrClient::fetchServerMetrics((int)Util::post('id'));
                $message = self::dashboardRefreshNotice($result, $server['name'] ?? '');
            } elseif ($action === 'refresh_all') {
                $okCount = 0;
                $errorCount = 0;
                foreach (Repo::all('dvr_servers', 'name ASC') as $server) {
                    if ((int)$server['blocked'] === 0) {
                        $result = DvrClient::fetchServerMetrics((int)$server['id']);
                        $result['ok'] ? $okCount++ : $errorCount++;
                    }
                }
                $message = self::dashboardRefreshAllNotice($okCount, $errorCount);
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

        self::layout(self::t('nav.dashboard', 'Dashboard'), function () use ($counts, $servers, $recentSync, $message) {
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

    private static function dashboardRefreshNotice(array $result, string $serverName = ''): string
    {
        $prefix = $serverName !== '' ? $serverName . ': ' : '';
        if (!empty($result['ok'])) {
            return $prefix . self::t('dashboard.metricsUpdated', 'Статистика обновлена');
        }

        return $prefix . self::metricFailureNotice((string)($result['reason'] ?? ''), (string)($result['message'] ?? ''));
    }

    private static function dashboardRefreshAllNotice(int $okCount, int $errorCount): string
    {
        return self::t('dashboard.refreshFinished', 'Обновление завершено') . ': '
            . $okCount . ' ' . self::t('dashboard.refreshOk', 'успешно') . ', '
            . $errorCount . ' ' . self::t('dashboard.refreshErrors', 'с ошибкой');
    }

    private static function settings(): void
    {
        Auth::requireAdmin();
        $message = '';
        $messageClass = '';
        $updateResult = null;
        $forceCheck = false;

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
            }
        }

        $status = PortalUpdateService::status($forceCheck, true);
        if ($forceCheck && $updateResult === null) {
            $message = empty($status['checkError'])
                ? self::t('settings.checkDone', 'Проверка обновлений выполнена')
                : self::t('settings.checkFailed', 'Проверка обновлений не выполнена');
            $messageClass = empty($status['checkError']) ? 'success' : 'danger';
        }

        self::layout(self::t('settings.title', 'Настройки'), function () use ($message, $messageClass, $status, $updateResult) {
            self::notice($message, $messageClass);
            self::portalUpdatePanel($status, $updateResult);
        });
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

    private static function serverCheckNotice(array $result, string $serverName = ''): string
    {
        $prefix = $serverName !== '' ? $serverName . ': ' : '';
        if (!empty($result['ok'])) {
            return $prefix . self::t('server.checkOk', 'Проверка сервера выполнена');
        }

        return $prefix . self::metricFailureNotice((string)($result['reason'] ?? ''), (string)($result['message'] ?? ''));
    }

    private static function metricFailureNotice(string $reason, string $message): string
    {
        if ($reason === 'management_token_missing') {
            return self::t('server.managementTokenMissingNotice', 'Management token не указан. Portal не может прочитать /api/system/status и /api/streams этого SesameDVR сервера.');
        }
        if ($reason === 'management_token_unreadable') {
            return self::t('server.managementTokenUnreadableNotice', 'Management token не удалось расшифровать. Сохраните новый token в настройках DVR сервера.');
        }
        if (preg_match('/^HTTP\s+401\b/', $message) || str_contains($message, 'HTTP 401')) {
            return self::t('server.managementUnauthorizedNotice', 'SesameDVR вернул HTTP 401. Проверьте Management token в настройках DVR сервера.');
        }

        return self::t('dashboard.metricsRefreshFailed', 'Статистика не обновлена. Подробности показаны в карточке сервера.');
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
            echo '<div class="login-features"><span>' . Util::h(self::t('login.feature.secure', 'Безопасно')) . '</span><span>' . Util::h(self::t('login.feature.reliable', 'Надежно')) . '</span><span>' . Util::h(self::t('login.feature.efficient', 'Производительно')) . '</span></div></section>';
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
        $messageClass = '';
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
                        $id = DB::lastInsertId('users');
                    }
                    if ($message === '') {
                        $groupIds = (array)($_POST['group_ids'] ?? []);
                        self::replaceLinks('user_groups', 'user_id', $id, 'group_id', $groupIds);
                        Audit::log('user.save', $login . ' groups=' . count($groupIds));
                        $message = self::t('users.saveDone', 'Пользователь сохранён');
                        $messageClass = 'success';
                    }
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
        $linkedGroups = $edit ? self::linkedIds('user_groups', 'user_id', (int)$edit['id'], 'group_id') : [];
        $groups = self::groupRowsWithDisplayLabels(Repo::all('portal_groups', 'name ASC'));
        $list = self::filteredRows('users', ['login', 'role'], 'login ASC');
        $users = $list['rows'];
        self::layout(self::t('users.title', 'Пользователи'), function () use ($users, $edit, $groups, $linkedGroups, $message, $messageClass, $staticToken, $list) {
            self::notice($message, $messageClass);
            if ($staticToken) {
                echo '<div class="alert"><strong>' . self::t('token.staticIssued', 'Новый static token. Сохраните его сейчас: позже Portal покажет только наличие token') . '</strong><br><code>' . Util::h($staticToken) . '</code></div>';
            }
            echo '<div class="admin-grid">';
            echo '<section class="panel"><h2>' . ($edit ? self::t('users.edit', 'Изменить пользователя') : self::t('users.new', 'Новый пользователь')) . '</h2>';
            $savingLabel = self::t('users.saving', 'Сохраняем пользователя...');
            echo '<form method="post" class="form" data-submit-progress="' . Util::h($savingLabel) . '">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('field.login', 'Логин') . '<input name="login" value="' . Util::h($edit['login'] ?? '') . '" required></label>';
            echo '<label>' . self::t('field.password', 'Пароль') . '<input name="password" type="password" minlength="6" placeholder="' . ($edit ? self::t('users.passwordPlaceholderEdit', 'оставьте пустым, чтобы не менять') : self::t('users.passwordPlaceholderNew', 'минимум 6 символов')) . '"></label>';
            echo '<label>' . self::t('column.role', 'Роль') . '<select name="role"><option value="user">user</option><option value="admin" ' . (($edit['role'] ?? '') === 'admin' ? 'selected' : '') . '>admin</option></select></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('users.blocked', 'Заблокирован') . '</label>';
            self::groupCheckboxTree(self::t('groups.title', 'Группы'), 'group_ids[]', $groups, $linkedGroups);
            echo '<div class="form-submit-row"><button type="submit" class="primary" data-submit-button>' . self::t('action.save', 'Сохранить') . '</button><div class="submit-progress" data-submit-status hidden role="status" aria-live="polite">' . Util::h($savingLabel) . '</div></div></form></section>';
            self::table(self::t('users.title', 'Пользователи'), ['login', 'role', 'blocked', 'static_token_hash', 'last_login_at'], $users, '/admin/users', false, $list);
            echo '</div>';
        });
    }

    private static function groups(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $name = trim((string)Util::post('name'));
                $current = $id > 0 ? self::rowById('portal_groups', $id) : null;
                $parentId = self::groupParentIdFromInput(['parent_group_id' => Util::post('parent_group_id')], $current);
                $parentError = self::groupParentValidationError($id, $parentId);
                if ($name === '') {
                    $message = self::t('groups.nameRequired', 'Название группы обязательно');
                } elseif ($parentError !== '') {
                    $message = $parentError;
                } elseif ($id > 0) {
                    $pdo->prepare('UPDATE portal_groups SET parent_group_id=?, name=?, description=?, blocked=? WHERE id=?')
                        ->execute([$parentId, $name, Util::post('description'), Util::checkbox('blocked'), $id]);
                } else {
                    $pdo->prepare('INSERT INTO portal_groups(parent_group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                        ->execute([$parentId, $name, Util::post('description'), Util::checkbox('blocked'), Util::now()]);
                    $id = DB::lastInsertId('portal_groups');
                }
                if ($message === '') {
                    self::replaceLinks('user_groups', 'group_id', $id, 'user_id', $_POST['user_ids'] ?? []);
                    self::replaceLinks('camera_groups', 'group_id', $id, 'camera_id', $_POST['camera_ids'] ?? []);
                    Audit::log('group.save', $name . ' parent_group_id=' . ($parentId ?? 'none'));
                }
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('UPDATE portal_groups SET parent_group_id = NULL WHERE parent_group_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM portal_groups WHERE id=?')->execute([$id]);
                Audit::log('group.delete', 'group_id=' . $id);
            }
        }

        $edit = self::rowById('portal_groups', (int)($_GET['edit'] ?? 0));
        $linkedUsers = $edit ? self::linkedIds('user_groups', 'group_id', (int)$edit['id'], 'user_id') : [];
        $linkedCameras = $edit ? self::linkedIds('camera_groups', 'group_id', (int)$edit['id'], 'camera_id') : [];
        $users = Repo::all('users', 'login ASC');
        $cameras = Repo::all('cameras', 'name ASC');
        $allGroups = Repo::all('portal_groups', 'name ASC');
        $list = self::filteredRows('portal_groups', ['name', 'description'], 'name ASC');
        $groups = self::groupRowsWithDisplayLabels($list['rows'], $allGroups);
        $parentGroups = self::groupParentOptions($allGroups, $edit ? (int)$edit['id'] : 0);
        self::layout(self::t('groups.title', 'Группы'), function () use ($edit, $users, $cameras, $linkedUsers, $linkedCameras, $groups, $parentGroups, $message, $list) {
            self::notice($message);
            echo '<div class="admin-grid group-admin-grid"><section class="panel"><h2>' . ($edit ? self::t('groups.edit', 'Изменить группу') : self::t('groups.new', 'Новая группа')) . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('column.name', 'Название') . '<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            $currentParent = (int)($edit['parent_group_id'] ?? 0);
            self::groupParentTreePicker(self::t('groups.parent', 'Родительская группа'), $parentGroups, $currentParent > 0 ? $currentParent : null);
            echo '<label>' . self::t('column.description', 'Описание') . '<textarea name="description">' . Util::h($edit['description'] ?? '') . '</textarea></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('column.blocked', 'Заблокирована') . '</label>';
            self::assignmentPicker(self::t('groups.users', 'Пользователи'), 'user_ids[]', $users, $linkedUsers, 'login', self::t('assignment.searchUsers', 'Найти пользователя'));
            self::assignmentPicker(self::t('groups.cameras', 'Камеры'), 'camera_ids[]', $cameras, $linkedCameras, 'name', self::t('assignment.searchCameras', 'Найти камеру'));
            echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
            self::table(self::t('groups.title', 'Группы'), ['id', 'parent_group_name', 'name', 'blocked', 'description'], $groups, '/admin/groups', false, $list);
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
                $server = Repo::server($id);
                $result = DvrClient::checkServer($id);
                $message = self::serverCheckNotice($result, $server['name'] ?? '');
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
            echo '<label>' . self::t('column.name', 'Название') . '<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>URL<input name="base_url" value="' . Util::h($edit['base_url'] ?? '') . '" placeholder="https://dvr.example.com" required></label>';
            echo '<label>' . self::t('servers.managementKey', 'Management key') . '<input name="management_token" placeholder="' . ($edit ? self::t('users.passwordPlaceholderEdit', 'оставьте пустым, чтобы не менять') : '') . '"></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('servers.blocked', 'Заблокирован') . '</label>';
            echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
            self::table(self::t('servers.title', 'Серверы'), ['name', 'base_url', 'blocked', 'last_check_result'], $servers, '/admin/servers', true, $list);
            echo '</div>';
        });
    }

    private static function agents(): void
    {
        Auth::requireAdmin();
        $servers = Repo::all('dvr_servers', 'name ASC');
        $selectedServerId = self::selectedServerId($servers);
        $selectedAgentId = trim((string)($_GET['agent_id'] ?? ''));
        $message = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $selectedServerId = (int)Util::post('server_id', $selectedServerId);
            $selectedAgentId = trim((string)Util::post('agent_id', $selectedAgentId));
            $result = null;

            if ($selectedServerId <= 0) {
                $message = self::t('agents.serverRequired', 'Выберите SesameDVR сервер');
            } elseif ($action === 'create') {
                $agentId = trim((string)Util::post('agent_id'));
                $name = trim((string)Util::post('name')) ?: $agentId;
                if ($agentId === '') {
                    $message = self::t('agents.agentId', 'Agent ID') . ' required';
                } else {
                    $payload = [
                        'id' => $agentId,
                        'name' => $name,
                        'enabled' => Util::checkbox('enabled') === 1,
                        'capabilities' => self::agentCapabilitiesFromText((string)Util::post('capabilities')),
                    ];
                    $password = trim((string)Util::post('password'));
                    if ($password !== '') {
                        $payload['password'] = $password;
                    }
                    $result = DvrClient::createAgent($selectedServerId, $payload);
                    $selectedAgentId = $agentId;
                }
            } elseif ($selectedAgentId === '') {
                $message = self::t('agents.agentId', 'Agent ID') . ' required';
            } elseif ($action === 'update') {
                $result = DvrClient::updateAgent($selectedServerId, $selectedAgentId, [
                    'name' => trim((string)Util::post('name')) ?: $selectedAgentId,
                    'enabled' => Util::checkbox('enabled') === 1,
                    'capabilities' => self::agentCapabilitiesFromText((string)Util::post('capabilities')),
                ]);
            } elseif ($action === 'delete') {
                $result = DvrClient::deleteAgent($selectedServerId, $selectedAgentId);
                if (!empty($result['ok'])) {
                    $selectedAgentId = '';
                }
            } elseif ($action === 'password') {
                $password = trim((string)Util::post('password'));
                $result = $password === ''
                    ? ['ok' => false, 'message' => self::t('agents.password', 'Enrollment password') . ' required']
                    : DvrClient::setAgentEnrollmentPassword($selectedServerId, $selectedAgentId, $password);
            } elseif ($action === 'revoke') {
                $result = DvrClient::revokeAgent($selectedServerId, $selectedAgentId);
            } elseif ($action === 'rotate') {
                $result = DvrClient::rotateAgentSecret($selectedServerId, $selectedAgentId);
            } elseif ($action === 'scan') {
                $result = DvrClient::scanAgentCameras($selectedServerId, $selectedAgentId);
            } elseif ($action === 'diagnostics') {
                $result = DvrClient::agentDiagnostics($selectedServerId, $selectedAgentId);
            } elseif ($action === 'command') {
                [$payload, $payloadError] = self::agentCommandPayload((string)Util::post('payload'), (string)Util::post('agent_camera_id'));
                if ($payloadError !== null) {
                    $result = ['ok' => false, 'message' => $payloadError];
                } else {
                    $timeout = (int)Util::post('timeout_ms', 0);
                    $result = DvrClient::agentCommand($selectedServerId, $selectedAgentId, trim((string)Util::post('command')) ?: 'test_camera', $payload, $timeout > 0 ? $timeout : null);
                }
            }

            if (is_array($result)) {
                $message = self::agentActionMessage($action, $result);
            }
        }

        $agentsResult = $selectedServerId > 0 ? DvrClient::listAgents($selectedServerId) : ['ok' => false, 'message' => self::t('agents.noServer', 'Сначала добавьте SesameDVR сервер с management token.'), 'data' => ['agents' => []]];
        $agents = is_array($agentsResult['data'] ?? null) && is_array(($agentsResult['data']['agents'] ?? null)) ? $agentsResult['data']['agents'] : [];
        if ($selectedAgentId === '' && $agents) {
            $selectedAgentId = (string)($agents[0]['id'] ?? '');
        }
        $selectedAgent = self::findAgentRow($agents, $selectedAgentId);
        $agentCamerasResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentCameras($selectedServerId, $selectedAgentId) : null;
        $agentCommandsResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentCommands($selectedServerId, $selectedAgentId) : null;
        $agentLogsResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentLogs($selectedServerId, $selectedAgentId) : null;

        self::layout(self::t('agents.title', 'Edge-агенты'), function () use ($servers, $selectedServerId, $selectedAgentId, $selectedAgent, $agentsResult, $agents, $agentCamerasResult, $agentCommandsResult, $agentLogsResult, $message) {
            self::notice($message);
            if (!$servers) {
                self::notice(self::t('agents.noServer', 'Сначала добавьте SesameDVR сервер с management token.'));
                return;
            }

            echo '<section class="panel agents-toolbar"><form method="get" action="/admin/agents" class="filters">';
            echo '<label>' . self::t('cameras.server', 'Сервер') . '<select name="server_id" onchange="this.form.submit()">';
            foreach ($servers as $server) {
                echo '<option value="' . (int)$server['id'] . '" ' . ($selectedServerId === (int)$server['id'] ? 'selected' : '') . '>' . Util::h($server['name']) . '</option>';
            }
            echo '</select></label><button>' . self::t('action.update', 'Обновить') . '</button></form></section>';

            if ($selectedServerId <= 0) {
                return;
            }

            echo '<div class="admin-grid agents-admin-grid"><details class="panel agent-create-panel"><summary><span><strong>' . self::t('agents.new', 'Новый агент') . '</strong><small>' . self::t('agents.createHint', 'Создание агента нужно только перед первичной установкой edge-устройства.') . '</small></span></summary>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="create"><input type="hidden" name="server_id" value="' . (int)$selectedServerId . '">';
            echo '<label>' . self::t('agents.agentId', 'Agent ID') . '<input name="agent_id" placeholder="agt-office-1" required></label>';
            echo '<label>' . self::t('agents.agentName', 'Название агента') . '<input name="name" placeholder="Office NanoPi"></label>';
            echo '<label>' . self::t('agents.password', 'Пароль enrollment') . '<input name="password" autocomplete="new-password"></label>';
            echo '<label>' . self::t('agents.capabilities', 'Возможности') . '<input name="capabilities" value="rtmp_push,onvif_events"></label>';
            echo '<label class="check"><input type="checkbox" name="enabled" checked> ' . self::t('agents.enabled', 'Включён') . '</label>';
            echo '<button class="primary">' . self::t('agents.create', 'Создать агента') . '</button></form></details>';

            $agentsSummary = !empty($agentsResult['ok'])
                ? self::t('agents.loaded', 'Загружено агентов') . ': ' . count($agents)
                : self::agentResultSummary($agentsResult, self::t('agents.noAgents', 'Агенты не найдены'));
            echo '<section class="panel"><div class="section-head"><h2>' . self::t('agents.title', 'Edge-агенты') . '</h2><span class="muted">' . Util::h($agentsSummary) . '</span></div>';
            if (!empty($agentsResult['message'])) {
                self::technicalResult((string)$agentsResult['message'], self::t('agents.details', 'Технические детали'));
            }
            if (!$agents) {
                echo '<p class="muted">' . self::t('agents.noAgents', 'Агенты не найдены') . '</p>';
            }
            echo '<div class="agent-list">';
            foreach ($agents as $agent) {
                self::agentCard($selectedServerId, $agent, $selectedAgentId);
            }
            echo '</div></section></div>';

            if ($selectedAgentId !== '') {
                self::agentDetails($selectedServerId, $selectedAgentId, $selectedAgent, $agentCamerasResult, $agentCommandsResult, $agentLogsResult);
            }
        });
    }

    private static function agentSnapshotProxy(): void
    {
        Auth::requireAdmin();
        $serverId = (int)($_GET['server_id'] ?? 0);
        $agentId = trim((string)($_GET['agent_id'] ?? ''));
        $cameraId = trim((string)($_GET['camera_id'] ?? ''));
        if ($serverId <= 0 || $agentId === '' || $cameraId === '') {
            http_response_code(400);
            echo 'missing snapshot parameters';
            return;
        }

        $result = DvrClient::agentSnapshot($serverId, $agentId, $cameraId, !empty($_GET['fresh']));
        if (empty($result['ok'])) {
            http_response_code((int)($result['status'] ?? 502) ?: 502);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)($result['message'] ?? 'snapshot failed');
            return;
        }

        $contentType = (string)($result['contentType'] ?? 'image/jpeg');
        if (!str_starts_with(strtolower($contentType), 'image/')) {
            $contentType = 'image/jpeg';
        }
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store');
        echo (string)($result['data'] ?? '');
    }

    private static function selectedServerId(array $servers): int
    {
        $requested = (int)($_GET['server_id'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }
        foreach ($servers as $server) {
            if ((int)($server['blocked'] ?? 0) === 0) {
                return (int)$server['id'];
            }
        }
        return $servers ? (int)$servers[0]['id'] : 0;
    }

    private static function agentCapabilitiesFromText(string $text): array
    {
        $parts = preg_split('/[\s,]+/', trim($text)) ?: [];
        $capabilities = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $capabilities[$part] = true;
            }
        }
        return array_keys($capabilities);
    }

    private static function agentCommandPayload(string $text, string $agentCameraId): array
    {
        $text = trim($text);
        $payload = [];
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                return [[], 'Payload JSON must be an object'];
            }
            $payload = $decoded;
        }

        $agentCameraId = trim($agentCameraId);
        if ($agentCameraId !== '') {
            $payload += [
                'agentCameraId' => $agentCameraId,
                'cameraId' => $agentCameraId,
            ];
        }
        return [$payload, null];
    }

    private static function agentActionMessage(string $action, array $result): string
    {
        $data = $result['data'] ?? null;
        if (!empty($result['ok']) && is_array($data) && !empty($data['agentSecret'])) {
            return self::t('agents.newSecret', 'Новый секрет агента') . ': ' . $data['agentSecret'];
        }

        if (empty($result['ok'])) {
            return self::agentResultSummary($result, self::t('agents.actionFailed', 'Операция не выполнена'));
        }

        return match ($action) {
            'scan', 'diagnostics', 'command' => self::t('agents.actionQueued', 'Команда поставлена в очередь'),
            default => self::t('agents.actionCompleted', 'Операция выполнена'),
        };
    }

    private static function agentResultSummary(?array $result, string $okText): string
    {
        if (!$result) {
            return '';
        }
        if (!empty($result['ok'])) {
            return $okText;
        }

        $status = (int)($result['status'] ?? 0);
        $prefix = self::t('agents.actionFailed', 'Операция не выполнена');
        if ($status > 0) {
            return $prefix . ' · HTTP ' . $status;
        }

        $message = trim((string)($result['message'] ?? ''));
        return $message !== '' && !str_starts_with($message, 'HTTP ')
            ? $prefix . ' · ' . $message
            : $prefix;
    }

    private static function findAgentRow(array $agents, string $agentId): ?array
    {
        foreach ($agents as $agent) {
            if (is_array($agent) && (string)($agent['id'] ?? '') === $agentId) {
                return $agent;
            }
        }
        return null;
    }

    private static function agentCard(int $serverId, array $agent, string $selectedAgentId): void
    {
        $id = (string)($agent['id'] ?? '');
        if ($id === '') {
            return;
        }
        $name = (string)($agent['name'] ?? $id);
        $status = (string)($agent['status'] ?? 'offline');
        $capabilities = is_array($agent['capabilities'] ?? null) ? implode(',', $agent['capabilities']) : '';
        $active = $id === $selectedAgentId ? ' active' : '';
        $href = '/admin/agents?' . http_build_query(['server_id' => $serverId, 'agent_id' => $id]);

        echo '<article class="agent-card' . $active . '">';
        echo '<div class="agent-card-head"><div><a class="agent-card-title" href="' . Util::h($href) . '">' . Util::h($name) . '</a><code>' . Util::h($id) . '</code></div>';
        echo self::statusPill($status) . '</div>';
        echo '<dl class="agent-meta">';
        echo '<dt>' . self::t('agents.version', 'Версия') . '</dt><dd>' . Util::h($agent['version'] ?? '-') . '</dd>';
        echo '<dt>' . self::t('agents.lastSeen', 'Последняя связь') . '</dt><dd>' . self::localTime($agent['lastSeenAt'] ?? '') . '</dd>';
        echo '<dt>' . self::t('agents.cameraCount', 'Камеры') . '</dt><dd>' . Util::h($agent['cameraCount'] ?? 0) . '</dd>';
        echo '<dt>' . self::t('agents.mediaSessions', 'Медиа-сессии') . '</dt><dd>' . Util::h($agent['activeMediaSessions'] ?? 0) . '</dd>';
        echo '</dl>';
        echo '<details class="agent-settings-details" open><summary>' . self::t('agents.settings', 'Настройки') . '</summary>';
        echo '<form method="post" class="agent-edit-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="update"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($id) . '">';
        echo '<label>' . self::t('agents.agentName', 'Название агента') . '<input name="name" value="' . Util::h($name) . '"></label>';
        echo '<label>' . self::t('agents.capabilities', 'Возможности') . '<input name="capabilities" value="' . Util::h($capabilities) . '"></label>';
        echo '<label class="check"><input type="checkbox" name="enabled" ' . (!empty($agent['enabled']) ? 'checked' : '') . '> ' . self::t('agents.enabled', 'Включён') . '</label>';
        echo '<button>' . self::t('action.save', 'Сохранить') . '</button></form>';
        echo '</details>';
        echo '<div class="agent-action-block"><strong>' . self::t('agents.actions', 'Действия') . '</strong>';
        echo '<div class="row-actions row-actions-icons agent-actions">';
        self::smallPost('/admin/agents', ['action' => 'scan', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.scan', 'Сканировать ONVIF'), '', '', 'scan');
        self::smallPost('/admin/agents', ['action' => 'diagnostics', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.diagnostics', 'Диагностика'), '', '', 'diagnostics');
        self::smallPost('/admin/agents', ['action' => 'revoke', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.revoke', 'Отозвать секрет'), '', '', 'ban');
        self::smallPost('/admin/agents', ['action' => 'rotate', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.rotateSecret', 'Сменить секрет'), '', '', 'key');
        self::smallPost('/admin/agents', ['action' => 'delete', 'server_id' => $serverId, 'agent_id' => $id], self::t('action.delete', 'Удалить'), 'danger', '', 'trash');
        echo '</div></div>';
        echo '<details class="agent-settings-details"><summary>' . self::t('agents.enrollment', 'Enrollment') . '</summary>';
        echo '<form method="post" class="agent-password-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="password"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($id) . '">';
        echo '<label>' . self::t('agents.password', 'Пароль enrollment') . '<input name="password" autocomplete="new-password"></label><button>' . self::t('agents.setPassword', 'Задать пароль') . '</button></form>';
        echo '</details>';
        echo '</article>';
    }

    private static function agentDetails(int $serverId, string $agentId, ?array $agent, ?array $camerasResult, ?array $commandsResult, ?array $logsResult): void
    {
        echo '<details class="panel agent-command-panel"><summary><span><strong>' . self::t('agents.commandConsole', 'Консоль команд') . '</strong><small>' . Util::h($agent['name'] ?? $agentId) . ' · ' . Util::h($agentId) . '</small></span></summary>';
        echo '<form method="post" class="form agent-command-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="command"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($agentId) . '">';
        echo '<div class="form-row"><label>' . self::t('agents.command', 'Команда') . '<input name="command" value="test_camera"></label>';
        echo '<label>' . self::t('cameras.agentCameraId', 'Agent camera ID') . '<input name="agent_camera_id"></label></div>';
        echo '<label>' . self::t('agents.payload', 'Payload JSON') . '<textarea name="payload" placeholder="{&quot;agentCameraId&quot;:&quot;cam1&quot;}"></textarea></label>';
        echo '<label>' . self::t('agents.timeout', 'Таймаут, мс') . '<input name="timeout_ms" type="number" min="1000" step="1000" placeholder="30000"></label>';
        echo '<button class="primary">' . self::t('agents.sendCommand', 'Отправить команду') . '</button></form></details>';

        $cameras = is_array($camerasResult['data'] ?? null) && is_array(($camerasResult['data']['cameras'] ?? null)) ? $camerasResult['data']['cameras'] : [];
        $cameraSummary = !empty($camerasResult['ok'])
            ? count($cameras)
            : self::agentResultSummary($camerasResult, '0');
        echo '<section class="panel"><div class="section-head"><h2>' . self::t('agents.cameras', 'Камеры агента') . '</h2><span class="muted">' . Util::h((string)$cameraSummary) . '</span></div>';
        if (!empty($camerasResult['message'])) {
            self::technicalResult((string)$camerasResult['message'], self::t('agents.details', 'Технические детали'));
        }
        if (!$cameras) {
            echo '<p class="muted">-</p>';
        }
        echo '<div class="agent-camera-grid">';
        foreach ($cameras as $camera) {
            if (is_array($camera)) {
                self::agentCameraCard($serverId, $agentId, $camera);
            }
        }
        echo '</div></section>';

        echo '<div class="grid cols-2">';
        self::jsonDetailsPanel(self::t('agents.lastCommands', 'Последние команды'), $commandsResult['data'] ?? $commandsResult);
        self::jsonDetailsPanel(self::t('agents.lastLogs', 'Последние записи журнала'), $logsResult['data'] ?? $logsResult);
        echo '</div>';
    }

    private static function agentCameraCard(int $serverId, string $agentId, array $camera): void
    {
        $cameraId = (string)($camera['agentCameraId'] ?? $camera['id'] ?? '');
        if ($cameraId === '') {
            return;
        }
        $name = (string)($camera['name'] ?? $cameraId);
        $stream = Util::dvrStreamSlug($name);
        $snapshotUrl = '/admin/agents/snapshot?' . http_build_query(['server_id' => $serverId, 'agent_id' => $agentId, 'camera_id' => $cameraId]);
        $createUrl = '/admin/cameras?' . http_build_query([
            'mode' => 'edge_agent',
            'server_id' => $serverId,
            'agent_id' => $agentId,
            'agent_camera_id' => $cameraId,
            'name' => $name,
            'stream' => $stream,
            'onvif_events_requested' => !empty($camera['onvifStatus']) ? 1 : 0,
        ]);

        echo '<article class="agent-camera-card">';
        echo '<div class="agent-snapshot"><img src="' . Util::h($snapshotUrl) . '" alt=""></div>';
        echo '<div><strong>' . Util::h($name) . '</strong><code>' . Util::h($cameraId) . '</code></div>';
        echo '<dl class="agent-meta">';
        echo '<dt>' . self::t('agents.source', 'Источник') . '</dt><dd>' . Util::h($camera['sourceKind'] ?? '-') . '</dd>';
        echo '<dt>RTSP</dt><dd>' . Util::h($camera['rtspUrlRedacted'] ?? '-') . '</dd>';
        echo '<dt>' . self::t('agents.media', 'Медиа') . '</dt><dd>' . Util::h(self::agentValueSummary($camera['mediaStatus'] ?? null)) . '</dd>';
        echo '<dt>' . self::t('agents.onvif', 'ONVIF') . '</dt><dd>' . Util::h(self::agentValueSummary($camera['onvifStatus'] ?? null)) . '</dd>';
        echo '<dt>' . self::t('agents.lastSeen', 'Последняя связь') . '</dt><dd>' . self::localTime($camera['lastSeenAt'] ?? '') . '</dd>';
        echo '</dl>';
        echo '<a class="btn" href="' . Util::h($createUrl) . '">' . self::t('agents.useCamera', 'Создать камеру в Portal') . '</a>';
        echo '</article>';
    }

    private static function agentValueSummary(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет');
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (!is_array($value)) {
            return self::t('agents.unknown', 'неизвестно');
        }

        $parts = [];
        foreach (['status', 'state', 'backend'] as $key) {
            if (!empty($value[$key]) && is_scalar($value[$key])) {
                $parts[] = (string)$value[$key];
            }
        }
        if (array_key_exists('running', $value)) {
            $parts[] = self::truthyMetricValue($value['running']) ? self::t('agents.running', 'работает') : self::t('agents.stopped', 'остановлен');
        }
        if (array_key_exists('online', $value)) {
            $parts[] = self::truthyMetricValue($value['online']) ? self::t('agents.online', 'online') : self::t('agents.offline', 'offline');
        }
        $error = $value['lastError'] ?? $value['error'] ?? null;
        if (is_scalar($error) && trim((string)$error) !== '') {
            $parts[] = 'error: ' . mb_substr(trim((string)$error), 0, 120);
        }

        $parts = array_values(array_unique(array_filter($parts, static fn($part) => $part !== '')));
        return $parts ? implode(' · ', $parts) : self::t('agents.technicalData', 'Технические данные');
    }

    private static function jsonDetailsPanel(string $title, mixed $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo '<details class="panel json-details"><summary><span><strong>' . Util::h($title) . '</strong><small>' . self::t('agents.details', 'Технические детали') . '</small></span></summary><pre class="json-panel">' . Util::h($json === false ? '' : $json) . '</pre></details>';
    }

    private static function statusPill(string $status): string
    {
        $class = match ($status) {
            'online' => 'success',
            'disabled' => 'warn',
            'offline' => 'danger',
            default => 'info',
        };
        return '<span class="pill ' . $class . '">' . Util::h($status) . '</span>';
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
                $controlMode = self::cameraControlMode(Util::post('dvr_control_mode'));
                $serverId = (int)Util::post('server_id', 0) ?: null;
                if ($controlMode === 'edge_agent') {
                    $selection = 'manual';
                }
                if ($selection === 'auto' && !$serverId) {
                    $serverId = self::randomActiveServerId();
                }
                $sourceUrl = trim((string)Util::post('source_url'));
                [$name, $stream] = self::cameraNamesFromInput(
                    [
                        'display_name' => Util::post('display_name', Util::post('name')),
                        'dvr_stream_name' => Util::post('dvr_stream_name'),
                    ],
                    $id > 0 ? Repo::camera($id) : null
                );
                $agentId = trim((string)Util::post('agent_id'));
                $agentCameraId = trim((string)Util::post('agent_camera_id'));
                if ($name === '' || $stream === '') {
                    $message = I18n::t('cameras.nameOrStreamRequired', 'Stream title or technical stream name is required');
                } elseif (!Util::isDvrStreamName($stream)) {
                    $message = I18n::t('cameras.invalidStreamName', 'Technical stream name can contain only Latin letters, digits, hyphen, and underscore, up to 128 characters.');
                } elseif ($controlMode === 'managed' && $sourceUrl === '') {
                    $message = I18n::t('cameras.sourceRequired', 'Source URL is required for full DVR management mode');
                } elseif ($controlMode === 'edge_agent' && (!$serverId || $agentId === '' || $agentCameraId === '')) {
                    $message = I18n::t('cameras.agentRequired', 'Edge-agent mode requires server, Agent ID, and Agent camera ID');
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
                        $agentId !== '' ? $agentId : null,
                        $agentCameraId !== '' ? $agentCameraId : null,
                        Util::checkbox('onvif_events_requested'),
                        Util::checkbox('watermark_enabled'),
                        self::watermarkIntensity(Util::post('watermark_intensity', 16)),
                        Util::checkbox('blocked'),
                        $stream,
                    ];
                    if ($id > 0) {
                        $pdo->prepare('UPDATE cameras SET name=?, source_url=?, server_id=?, server_selection=?, latitude=?, longitude=?, direction_deg=?, view_angle_deg=?, retention_days=?, dvr_control_mode=?, agent_id=?, agent_camera_id=?, onvif_events_requested=?, watermark_enabled=?, watermark_intensity=?, blocked=?, dvr_stream_name=?, updated_at=? WHERE id=?')
                            ->execute([...$values, Util::now(), $id]);
                    } else {
                        $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, dvr_control_mode, agent_id, agent_camera_id, onvif_events_requested, watermark_enabled, watermark_intensity, blocked, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([...$values, Util::now(), Util::now()]);
                        $id = DB::lastInsertId('cameras');
                    }
                    self::replaceLinks('camera_groups', 'camera_id', $id, 'group_id', $_POST['group_ids'] ?? []);
                    $sync = DvrClient::syncCamera($id);
                    $message = self::cameraSaveNotice($sync);
                    Audit::log('camera.save', $name . ' mode=' . $controlMode . ' sync=' . $sync['message']);
                }
            } elseif ($action === 'delete' && $id > 0) {
                $camera = Repo::camera($id);
                if (!$camera) {
                    $message = self::t('cameras.deleteMissing', 'Камера уже удалена или не найдена');
                } elseif (Util::checkbox('confirm_delete') !== 1) {
                    $message = self::t('cameras.deleteConfirmRequired', 'Подтвердите удаление камеры');
                } else {
                    $deleteDvrStream = Util::checkbox('delete_dvr_stream') === 1;
                    $dvrMessage = '';
                    if ($deleteDvrStream) {
                        $deleteResult = DvrClient::deleteCameraStream($id, true);
                        $dvrMessage = $deleteResult['message'];
                        if (!$deleteResult['ok']) {
                            $message = self::t('cameras.deleteDvrFailed', 'Поток на DVR не удалён') . ': ' . $dvrMessage;
                            Audit::log('camera.delete_failed', 'camera_id=' . $id . ' dvr=yes result=' . $dvrMessage);
                        }
                    }

                    if ($message === '') {
                        $pdo->prepare('DELETE FROM camera_groups WHERE camera_id=?')->execute([$id]);
                        $pdo->prepare('DELETE FROM cameras WHERE id=?')->execute([$id]);
                        $message = self::t('cameras.deleteDone', 'Камера удалена');
                        if ($dvrMessage !== '') {
                            $message .= ': ' . $dvrMessage;
                        }
                        Audit::log('camera.delete', 'camera_id=' . $id . ' dvr=' . ($deleteDvrStream ? 'yes' : 'no') . ' result=' . $dvrMessage);
                    }
                }
            } elseif ($action === 'sync' && $id > 0) {
                $result = DvrClient::syncCamera($id);
                $message = self::cameraSyncNotice($result);
            }
        }

        $edit = self::rowById('cameras', (int)($_GET['edit'] ?? 0));
        $form = self::cameraFormDefaults($edit);
        $delete = self::cameraDeleteCandidate((int)($_GET['delete'] ?? 0));
        $linkedGroups = $edit ? self::linkedIds('camera_groups', 'camera_id', (int)$edit['id'], 'group_id') : [];
        $servers = Repo::all('dvr_servers', 'name ASC');
        $groups = self::groupRowsWithDisplayLabels(Repo::all('portal_groups', 'name ASC'));
        $list = self::filteredCameras();
        $cameras = $list['rows'];
        self::layout(self::t('cameras.title', 'Камеры'), function () use ($edit, $form, $delete, $servers, $groups, $linkedGroups, $cameras, $message, $list) {
            self::notice($message);
            if ($delete) {
                self::cameraDeletePanel($delete);
            }
            echo '<div class="admin-grid"><section class="panel"><div class="section-head"><h2>' . ($edit ? self::t('cameras.edit', 'Изменить камеру') : self::t('cameras.new', 'Новая камера')) . '</h2>';
            if ($edit) {
                echo '<a class="btn" href="' . Util::h(self::tableActionUrl('/admin/cameras', [], $list)) . '">' . self::t('cameras.new', 'Новая камера') . '</a>';
            }
            echo '</div>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('cameras.displayName', 'Название потока') . '<input name="display_name" value="' . Util::h($form['name'] ?? '') . '"></label>';
            echo '<label>' . self::t('cameras.mode', 'Режим камеры') . '<select name="dvr_control_mode">';
            echo '<option value="managed" ' . (($form['dvr_control_mode'] ?? 'managed') === 'managed' ? 'selected' : '') . '>' . self::t('cameras.modeManaged', 'Полное управление на DVR') . '</option>';
            echo '<option value="edge_agent" ' . (($form['dvr_control_mode'] ?? '') === 'edge_agent' ? 'selected' : '') . '>' . self::t('cameras.modeEdgeAgent', 'Edge Agent push stream') . '</option>';
            echo '<option value="read_only" ' . (($form['dvr_control_mode'] ?? '') === 'read_only' ? 'selected' : '') . '>' . self::t('cameras.modeReadOnly', 'Read-only поток с DVR') . '</option></select></label>';
            echo '<label>' . self::t('cameras.sourceUrl', 'URL источника') . '<input name="source_url" value="' . Util::h($form['source_url'] ?? '') . '"></label>';
            echo '<label>' . self::t('cameras.server', 'Сервер') . '<select name="server_id"><option value="">' . self::t('cameras.serverAutoNone', 'Авто/не выбран') . '</option>';
            foreach ($servers as $server) {
                echo '<option value="' . (int)$server['id'] . '" ' . (($form['server_id'] ?? '') == $server['id'] ? 'selected' : '') . '>' . Util::h($server['name']) . '</option>';
            }
            echo '</select></label>';
            echo '<label>' . self::t('cameras.serverSelection', 'Выбор сервера') . '<select name="server_selection"><option value="manual">' . self::t('cameras.selectionManual', 'конкретный') . '</option><option value="auto" ' . (($form['server_selection'] ?? '') === 'auto' ? 'selected' : '') . '>' . self::t('cameras.selectionAuto', 'автоматический случайный') . '</option></select></label>';
            $streamNameHint = self::t('cameras.streamNameHint', 'Only A-Z, a-z, 0-9, hyphen, and underscore. Leave empty to generate it.');
            echo '<label>' . self::t('cameras.streamName', 'Техническое имя потока') . '<input name="dvr_stream_name" value="' . Util::h($form['dvr_stream_name'] ?? '') . '" maxlength="' . Util::DVR_STREAM_NAME_MAX_BYTES . '" pattern="' . Util::DVR_STREAM_NAME_HTML_PATTERN . '" placeholder="domofon-g-sukhum-ul-kiaraz-9-p1" autocomplete="off" autocapitalize="none" spellcheck="false" title="' . Util::h($streamNameHint) . '"></label>';
            echo '<div class="form-row"><label>' . self::t('cameras.agentId', 'Agent ID') . '<input name="agent_id" value="' . Util::h($form['agent_id'] ?? '') . '"></label><label>' . self::t('cameras.agentCameraId', 'Agent camera ID') . '<input name="agent_camera_id" value="' . Util::h($form['agent_camera_id'] ?? '') . '"></label></div>';
            echo '<label class="check"><input type="checkbox" name="onvif_events_requested" ' . (!empty($form['onvif_events_requested']) ? 'checked' : '') . '> ' . self::t('cameras.onvifEvents', 'Запускать ONVIF events через агента') . '</label>';
            echo '<div class="form-row"><label class="check"><input type="checkbox" name="watermark_enabled" ' . (!empty($form['watermark_enabled']) ? 'checked' : '') . '> ' . self::t('cameras.watermarkEnabled', 'Показывать водяной знак с логином в плеере') . '</label>';
            echo '<label>' . self::t('cameras.watermarkIntensity', 'Интенсивность водяного знака, %') . '<input name="watermark_intensity" type="number" min="1" max="100" value="' . Util::h(self::watermarkIntensity($form['watermark_intensity'] ?? 16)) . '"></label></div>';
            $lat = $form['latitude'] ?? '';
            $lng = $form['longitude'] ?? '';
            echo '<div class="form-row"><label>' . self::t('geo.latitude', 'Широта') . '<input id="camera-latitude" name="latitude" value="' . Util::h($lat) . '"></label><label>' . self::t('geo.longitude', 'Долгота') . '<input id="camera-longitude" name="longitude" value="' . Util::h($lng) . '"></label></div>';
            echo '<div class="camera-position-field"><div class="camera-position-head"><strong>' . self::t('cameras.position', 'Положение на карте') . '</strong><button type="button" class="camera-map-clear">' . self::t('cameras.clearPosition', 'Очистить точку') . '</button></div>';
            echo '<div id="camera-position-map" class="camera-position-map" data-lat="' . Util::h($lat) . '" data-lng="' . Util::h($lng) . '"></div></div>';
            echo '<div class="form-row"><label>' . self::t('cameras.direction', 'Направление') . '<input id="camera-direction" name="direction_deg" type="number" min="0" max="359" value="' . Util::h($form['direction_deg'] ?? 0) . '"></label><label>' . self::t('cameras.viewAngle', 'Угол обзора') . '<input name="view_angle_deg" type="number" min="1" max="180" value="' . Util::h($form['view_angle_deg'] ?? 60) . '"></label></div>';
            echo '<label>' . self::t('cameras.retention', 'Глубина архива') . '<input name="retention_days" value="' . Util::h($form['retention_days'] ?? '7d') . '"></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($form['blocked']) ? 'checked' : '') . '> ' . self::t('cameras.blocked', 'Заблокирована') . '</label>';
            self::groupCheckboxTree(self::t('cameras.groups', 'Группы'), 'group_ids[]', $groups, $linkedGroups);
            echo '<button class="primary">' . self::t('action.saveSync', 'Сохранить и синхронизировать') . '</button></form></section>';
            self::table(self::t('cameras.title', 'Камеры'), ['name', 'server_name', 'dvr_control_mode', 'agent_id', 'agent_camera_id', 'retention_days', 'last_sync_message'], $cameras, '/admin/cameras', true, $list);
            echo '</div>';
        });
    }

    private static function cameraControlMode(mixed $value): string
    {
        $mode = trim((string)$value);
        return in_array($mode, ['managed', 'edge_agent', 'read_only'], true) ? $mode : 'managed';
    }

    private static function watermarkIntensity(mixed $value): int
    {
        $intensity = (int)$value;
        return max(1, min(100, $intensity > 0 ? $intensity : 16));
    }

    private static function cameraNamesFromInput(array $input, ?array $current): array
    {
        $displayValue = self::firstInputValue(
            $input,
            ['displayName', 'display_name', 'name'],
            $current['name'] ?? ''
        );
        $streamValue = self::firstInputValue(
            $input,
            ['dvrStreamName', 'dvr_stream_name', 'streamName', 'stream_name'],
            $current['dvr_stream_name'] ?? ''
        );

        $displayName = trim((string)$displayValue);
        $streamName = trim((string)$streamValue);
        if ($streamName === '' && $displayName !== '') {
            $streamName = Util::dvrStreamSlug($displayName);
        }
        if ($displayName === '' && $streamName !== '') {
            $displayName = $streamName;
        }

        return [$displayName, $streamName];
    }

    private static function firstInputValue(array $input, array $keys, mixed $fallback = ''): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }
        }
        return $fallback;
    }

    private static function cameraSaveNotice(array $sync): string
    {
        return !empty($sync['ok'])
            ? self::t('cameras.saveDone', 'Камера сохранена')
            : self::t('cameras.saveSyncFailed', 'Камера сохранена, но синхронизация с DVR не выполнена');
    }

    private static function cameraSyncNotice(array $sync): string
    {
        return !empty($sync['ok'])
            ? self::t('cameras.syncDone', 'Синхронизация выполнена')
            : self::t('cameras.syncFailed', 'Синхронизация не выполнена');
    }

    private static function cameraFormDefaults(?array $edit): array
    {
        if ($edit) {
            return $edit;
        }

        [$name, $stream] = self::cameraNamesFromInput([
            'display_name' => $_GET['display_name'] ?? $_GET['displayName'] ?? $_GET['name'] ?? '',
            'dvr_stream_name' => $_GET['stream'] ?? $_GET['dvr_stream_name'] ?? $_GET['dvrStreamName'] ?? '',
        ], null);

        return [
            'name' => $name,
            'source_url' => (string)($_GET['source_url'] ?? ''),
            'server_id' => (int)($_GET['server_id'] ?? 0) ?: '',
            'server_selection' => 'manual',
            'latitude' => '',
            'longitude' => '',
            'direction_deg' => 0,
            'view_angle_deg' => 60,
            'retention_days' => (string)($_GET['retention_days'] ?? '7d'),
            'dvr_control_mode' => self::cameraControlMode($_GET['mode'] ?? $_GET['dvr_control_mode'] ?? 'managed'),
            'agent_id' => (string)($_GET['agent_id'] ?? ''),
            'agent_camera_id' => (string)($_GET['agent_camera_id'] ?? ''),
            'onvif_events_requested' => !empty($_GET['onvif_events_requested']) ? 1 : 0,
            'watermark_enabled' => !empty($_GET['watermark_enabled']) ? 1 : 0,
            'watermark_intensity' => self::watermarkIntensity($_GET['watermark_intensity'] ?? 16),
            'blocked' => 0,
            'dvr_stream_name' => $stream,
        ];
    }

    private static function cameraDeleteCandidate(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = DB::pdo()->prepare('SELECT c.*, s.name AS server_name, s.base_url AS server_base_url, s.blocked AS server_blocked, s.management_token_enc AS server_management_token_enc
            FROM cameras c
            LEFT JOIN dvr_servers s ON s.id = c.server_id
            WHERE c.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function cameraDeletePanel(array $camera): void
    {
        $stream = trim((string)($camera['dvr_stream_name'] ?: $camera['name']));
        $canDeleteDvr = !empty($camera['server_id'])
            && (int)($camera['server_blocked'] ?? 0) === 0
            && ($camera['dvr_control_mode'] ?? 'managed') !== 'read_only'
            && trim((string)($camera['server_management_token_enc'] ?? '')) !== ''
            && $stream !== '';

        echo '<section class="panel delete-confirm"><div class="section-head"><h2>' . self::t('cameras.deleteTitle', 'Удалить камеру') . '</h2><a href="/admin/cameras">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '<div class="alert warn">';
        echo '<strong>' . self::t('cameras.deleteWarning', 'Это действие нельзя отменить.') . '</strong> ';
        echo self::t('cameras.deleteWarningText', 'Сначала подтвердите удаление камеры из портала. Отдельным флажком можно удалить связанный поток на DVR вместе с архивом.');
        echo '</div>';
        echo '<dl class="delete-meta">';
        echo '<dt>' . self::t('cameras.name', 'Имя') . '</dt><dd>' . Util::h($camera['name']) . '</dd>';
        echo '<dt>' . self::t('cameras.streamName', 'Имя потока SesameDVR') . '</dt><dd>' . Util::h($stream ?: '-') . '</dd>';
        echo '<dt>' . self::t('cameras.server', 'Сервер') . '</dt><dd>' . Util::h($camera['server_name'] ?: '-') . '</dd>';
        echo '</dl>';
        echo '<form method="post" action="/admin/cameras" class="form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$camera['id'] . '">';
        echo '<label class="check"><input type="checkbox" name="confirm_delete" required> ' . self::t('cameras.confirmDelete', 'Подтверждаю удаление камеры из портала') . '</label>';
        if ($canDeleteDvr) {
            echo '<label class="check"><input type="checkbox" name="delete_dvr_stream"> ' . self::t('cameras.deleteDvrStream', 'Также удалить поток на DVR и очистить архив, превью и индексы') . '</label>';
        } else {
            echo '<p class="muted">' . self::t('cameras.deleteDvrUnavailable', 'Удаление потока на DVR недоступно для этой камеры: проверьте сервер, token управления и режим управления.') . '</p>';
        }
        echo '<div class="form-actions"><button class="danger">' . self::t('action.delete', 'Удалить') . '</button><a href="/admin/cameras">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '</form></section>';
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
            echo '<div class="table-wrap"><table class="data-table table-audit"><thead><tr><th>' . self::t('audit.time', 'Время') . '</th><th>' . self::t('audit.user', 'Пользователь') . '</th><th>' . self::t('audit.action', 'Действие') . '</th><th>' . self::t('audit.details', 'Детали') . '</th></tr></thead><tbody>';
            foreach ($list['rows'] as $row) {
                echo '<tr><td>' . self::localTime($row['created_at'] ?? '') . '</td><td>' . Util::h($row['login'] ?? '-') . '</td><td><code class="audit-action">' . Util::h($row['action']) . '</code></td><td>';
                self::auditDetails((string)$row['details']);
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
            self::pager('/admin/audit', $list, ['action' => $list['action'], 'actor' => $list['actor']]);
            echo '</section>';
        });
    }

    private static function viewer(string $mode): void
    {
        $user = Auth::requireLogin();
        $filter = (string)($_GET['filter'] ?? 'all');
        $searchQuery = self::viewerSearchQuery();
        $groups = self::groupRowsWithTreeLabels(Repo::groupsForUser($user));
        $cols = self::viewerColumns();
        $previewRefresh = self::viewerPreviewRefresh();
        $cameraPager = null;
        if ($mode === 'map') {
            $cameras = Repo::accessibleCameras($user, $filter, $searchQuery);
        } else {
            $cameraPager = Repo::accessibleCamerasPage($user, $filter, $searchQuery, (int)($_GET['page'] ?? 1), self::viewerPageSize($cols));
            $cameras = $cameraPager['rows'];
        }
        $favorites = Repo::favoritesMap((int)$user['id']);

        $title = $mode === 'map' ? self::t('nav.map', 'Карта') : self::t('cameras.title', 'Камеры');
        self::layout($title, function () use ($mode, $groups, $filter, $searchQuery, $cameras, $favorites, $cameraPager, $cols, $previewRefresh) {
            self::filters($mode, $groups, $filter, $searchQuery, $cols, $previewRefresh);
            if ($mode === 'map') {
                self::map($cameras, $favorites);
            } else {
                self::mosaic($cameras, $favorites, $cameraPager ?? [], $cols, $previewRefresh);
            }
        });
    }

    private static function viewerColumns(): int
    {
        $cols = (int)($_GET['cols'] ?? 3);
        return min(6, max(2, $cols));
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

    private static function mosaic(array $cameras, array $favorites, array $pager, int $cols, string $previewRefresh): void
    {
        echo '<section class="camera-grid cols-' . Util::h($cols) . '">';
        foreach ($cameras as $camera) {
            $player = self::playerUrl($camera);
            $preview = self::previewUrl($camera);
            $streamUnavailable = self::cameraStreamUnavailable($camera);
            $stateText = $streamUnavailable
                ? self::t('js.streamUnavailable', 'Поток недоступен')
                : self::t('js.previewUnavailable', 'Превью недоступно');
            $openPlayerLabel = self::t('viewer.openPlayer', 'Открыть плеер');
            $previewClass = 'preview' . ($preview ? ' is-loading' : ' no-preview') . ($streamUnavailable ? ' stream-unavailable' : '');
            echo '<article class="camera-card">';
            echo '<a class="' . Util::h($previewClass) . '" href="' . Util::h($player) . '" aria-label="' . Util::h($openPlayerLabel) . '">';
            if ($preview) {
                echo '<img data-preview-src="' . Util::h($preview) . '" data-preview-refresh="' . Util::h($previewRefresh) . '"';
                if ($previewRefresh !== 'off') {
                    echo ' data-preview-refresh-ms="' . Util::h((string)((int)$previewRefresh * 1000)) . '"';
                }
                echo ' alt="" loading="lazy" decoding="async" hidden>';
            }
            echo '<span class="preview-spinner" aria-hidden="true"></span><span class="preview-state">' . Util::h($stateText) . '</span><span class="preview-play" aria-hidden="true"></span><span class="sr-only">' . Util::h($openPlayerLabel) . '</span></a><div class="camera-meta"><strong>' . Util::h($camera['name']) . '</strong><span>' . Util::h($camera['server_name'] ?? self::t('common.noServer', 'Без сервера')) . '</span></div>';
            self::favoriteButton((int)$camera['id'], isset($favorites[(int)$camera['id']]));
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
                'streamUnavailable' => self::cameraStreamUnavailable($camera),
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

        $stmt = DB::pdo()->prepare('SELECT id, name, dvr_stream_name FROM cameras WHERE (dvr_stream_name = ? OR name = ?) AND blocked = 0 LIMIT 1');
        $stmt->execute([$cameraName, $cameraName]);
        $camera = $stmt->fetch();
        if (!$camera || !Repo::cameraAllowedForUser($user, (int)$camera['id'])) {
            http_response_code(403);
            echo "denied\n";
            return;
        }

        $audit = self::authBackendAuditEvent($camera);
        if ($audit !== null) {
            Audit::logForUser((int)$user['id'], $audit['action'], $audit['details']);
        }

        echo "ok\n";
    }

    private static function authBackendAuditEvent(array $camera): ?array
    {
        $target = self::authRequestTarget();
        $path = (string)(parse_url($target, PHP_URL_PATH) ?: '');
        $file = basename($path);
        if (!preg_match('/^archive-(\d+)-(\d+)\.mp4$/', $file, $match)) {
            return null;
        }

        $stream = (string)($camera['dvr_stream_name'] ?? $camera['name'] ?? '');
        return [
            'action' => 'archive.download',
            'details' => implode(' ', [
                'camera_id=' . (int)$camera['id'],
                'stream=' . Audit::cleanValue($stream),
                'from=' . $match[1],
                'duration=' . $match[2],
                'method=' . self::authRequestMethod(),
                'path=' . Audit::cleanValue($path, 240),
                'ip=' . Audit::clientIp(),
            ]),
        ];
    }

    private static function layout(string $title, callable $body, ?array $userOverride = [], string $bodyClass = '', bool $showChrome = true): void
    {
        $user = $userOverride === null ? null : Auth::user();
        echo '<!doctype html><html lang="' . Util::h(I18n::htmlLocale()) . '" dir="' . Util::h(I18n::dir()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . Util::h($title) . ' - SesamePortal</title>';
        echo '<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">';
        echo '<link rel="stylesheet" href="' . Util::h(self::assetUrl('/assets/styles.css')) . '">';
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">';
        echo '</head><body' . ($bodyClass !== '' ? ' class="' . Util::h($bodyClass) . '"' : '') . '>';
        if ($user && $showChrome) {
            echo '<div class="shell"><aside class="sidebar">';
            echo '<a class="brand-logo-link" href="/"><img class="brand-logo-full" src="/assets/logo-sesameportal-inverse.svg" alt="SesamePortal"></a>';
            echo '<div class="nav-section">' . Util::h(self::t('nav.section.view', 'Просмотр')) . '</div><nav class="nav">';
            $viewerFilter = (string)($_GET['filter'] ?? 'all');
            self::navLink('/', self::t('nav.mosaic', 'Мозаика'), 'grid', Util::path() === '/' && $viewerFilter !== 'favorites');
            self::navLink('/viewer/map', self::t('nav.map', 'Карта'), 'map');
            self::navLink('/?filter=favorites', self::t('filter.favorites', 'Избранное'), 'star', ($_GET['filter'] ?? '') === 'favorites' && Util::path() === '/');
            echo '</nav>';
            if ($user['role'] === 'admin') {
                echo '<div class="nav-section">' . Util::h(self::t('nav.section.admin', 'Администрирование')) . '</div><nav class="nav">';
                self::navLink('/admin/dashboard', self::t('nav.dashboard', 'Dashboard'), 'dashboard');
                self::navLink('/admin/users', self::t('nav.users', 'Пользователи'), 'user');
                self::navLink('/admin/groups', self::t('nav.groups', 'Группы'), 'group');
                self::navLink('/admin/cameras', self::t('nav.cameras', 'Камеры'), 'camera');
                self::navLink('/admin/servers', self::t('nav.dvr', 'DVR'), 'server');
                self::navLink('/admin/agents', self::t('nav.agents', 'Edge Agents'), 'agent');
                self::navLink('/admin/audit', self::t('nav.audit', 'Журнал'), 'audit');
                self::navLink('/admin/settings', self::t('nav.settings', 'Настройки'), 'settings');
                echo '</nav>';
            }
            echo '<div class="sidebar-foot">' . I18n::languageLinks() . '<a class="logout-link" href="/logout">' . self::icon('logout') . self::t('nav.logout', 'Выход') . '</a></div></aside>';
            $initial = strtoupper(substr((string)$user['login'], 0, 1) ?: 'U');
            echo '<main class="main workspace"><div class="topbar"><div><h1>' . Util::h($title) . '</h1></div><div class="user">' . Util::h($initial) . '</div></div>';
            if ($user['role'] === 'admin') {
                self::portalUpdateBanner();
            }
            $body();
            echo '</main></div>';
        } else {
            echo '<main class="' . ($user ? 'workspace' : 'login-page') . '">';
            $body();
            echo '</main>';
        }
        echo '<script>window.SESAME_I18N = ' . json_encode(I18n::js(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '; window.SESAME_CSRF = ' . json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script><script src="' . Util::h(self::assetUrl('/assets/app.js')) . '"></script>';
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
            'token-issue' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M14 7a4 4 0 1 0 2.8 1.2L21 4l-2-2-4.2 4.2M9 13l-6 6m3-3 2 2M18 14v6M15 17h6"/>',
            'token-refresh' => '<path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M20 7v5h-5M4 17v-5h5M6.2 8.6a7 7 0 0 1 11.4-1.9L20 12M4 12l2.4 5.3a7 7 0 0 0 11.4-1.9"/>',
            'token-revoke' => '<path fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" d="M14 7a4 4 0 1 0 2.8 1.2L21 4l-2-2-4.2 4.2M9 13l-6 6m3-3 2 2M15 15l6 6M21 15l-6 6"/>',
            'ban' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M5 5a10 10 0 0 1 14 14M19 5A10 10 0 0 0 5 19M5 5l14 14"/>',
            'scan' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M8 12h8M12 8v8"/>',
            'diagnostics' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l2-6 4 12 2-6h6"/>',
            'download' => '<path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
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
        echo '<input type="hidden" name="filter" value="' . Util::h(str_starts_with($filter, 'group:') ? $filter : '') . '">';
        self::groupTreeFilter($groups, $filter, function (int $groupId) use ($url, $viewParams, $queryParam): string {
            return $url($viewParams(['filter' => 'group:' . $groupId, ...$queryParam]));
        });
        echo '<input class="camera-search-input" name="q" value="' . Util::h($searchQuery) . '" placeholder="' . Util::h(self::t('filter.cameraSearchPlaceholder', 'Название камеры')) . '">';
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
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $where = '';
        $params = [];
        if ($q !== '') {
            $columns = ['c.name', 'c.source_url', 'c.dvr_stream_name', 'c.dvr_control_mode', 'c.agent_id', 'c.agent_camera_id', 's.name', 'c.last_sync_message'];
            $where = ' WHERE ' . implode(' OR ', array_map([DB::class, 'caseInsensitiveLike'], $columns));
            $params = array_fill(0, count($columns), '%' . $q . '%');
        }

        $pdo = DB::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare('SELECT c.*, s.name AS server_name, s.base_url AS server_url, s.last_metrics_json AS server_metrics_json FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id' . $where . ' ORDER BY c.name ASC LIMIT ? OFFSET ?');
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
            echo '<th>' . Util::h(self::columnLabel($column)) . '</th>';
        }
        $actionUrl = self::tableActionUrl($base, [], $pager);
        echo '<th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
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
            if ($base === '/admin/cameras') {
                self::iconActionLink(self::tableActionUrl($base, ['delete' => (int)$row['id']], $pager), self::t('action.delete', 'Удалить'), 'trash', 'danger');
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
                    self::smallPost($actionUrl, ['action' => 'revoke_static', 'id' => $row['id']], self::t('action.revoke', 'Отозвать'), '', '', 'token-revoke');
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

    private static function columnLabel(string $column): string
    {
        return self::t('column.' . $column, $column);
    }

    private static function tableCell(string $column, mixed $value): void
    {
        if ($column === 'static_token_hash') {
            $hasToken = trim((string)$value) !== '';
            $label = $hasToken
                ? self::t('token.staticPresent', 'есть')
                : self::t('token.staticMissing', 'нет');
            echo '<td><span class="pill ' . ($hasToken ? 'success' : 'danger') . '">' . Util::h($label) . '</span></td>';
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

        if (preg_match('/\\bHTTP\\s+(\\d{3})\\b/i', $text, $match) && (int)$match[1] >= 400) {
            return 'bad';
        }

        $badMarkers = [
            'error',
            'failed',
            'failure',
            'timeout',
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
            $query = http_build_query(array_filter([
                'q' => $pager['q'] ?? '',
                'page' => $page,
                ...$extraParams,
            ], fn($value) => $value !== '' && $value !== null && $value !== 0));
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
            $q = trim((string)($pager['q'] ?? ''));
            if ($q !== '') {
                $query['q'] = $q;
            }
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

    private static function iconActionLink(string $href, string $label, string $icon, string $class = ''): void
    {
        $classes = trim('icon-action ' . $class);
        echo '<a href="' . Util::h($href) . '" class="' . Util::h($classes) . '" title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '">' . self::icon($icon) . '<span class="sr-only">' . Util::h($label) . '</span></a>';
    }

    private static function smallPost(string $path, array $fields, string $label, string $class = '', string $confirm = '', string $icon = ''): void
    {
        echo '<form method="post" action="' . Util::h($path) . '" class="inline-form"';
        if ($confirm !== '') {
            $confirmJson = json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
            echo ' onsubmit="return confirm(' . Util::h($confirmJson === false ? '""' : $confirmJson) . ')"';
        }
        echo '>' . Csrf::field();
        foreach ($fields as $key => $value) {
            echo '<input type="hidden" name="' . Util::h($key) . '" value="' . Util::h($value) . '">';
        }
        $buttonClass = trim($class . ($icon !== '' ? ' icon-action' : ''));
        echo '<button class="' . Util::h($buttonClass) . '"';
        if ($icon !== '') {
            echo ' title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '"';
        }
        echo '>';
        if ($icon !== '') {
            echo self::icon($icon) . '<span class="sr-only">' . Util::h($label) . '</span>';
        } else {
            echo Util::h($label);
        }
        echo '</button></form>';
    }

    private static function checkboxList(string $title, string $name, array $rows, array $selected, string $labelKey): void
    {
        echo '<fieldset><legend>' . Util::h($title) . '</legend><div class="check-list">';
        foreach ($rows as $row) {
            echo '<label class="check"><input type="checkbox" name="' . Util::h($name) . '" value="' . (int)$row['id'] . '" ' . (in_array((int)$row['id'], $selected, true) ? 'checked' : '') . '> ' . Util::h($row['display_name'] ?? $row[$labelKey]) . '</label>';
        }
        echo '</div></fieldset>';
    }

    private static function groupCheckboxTree(string $title, string $name, array $groups, array $selected): void
    {
        [$byId, $children] = self::groupTreeStructure($groups);
        $selectedSet = array_flip(array_map('intval', $selected));
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
        echo '<div class="group-tree-list group-tree-checkbox-list" role="tree" aria-label="' . Util::h($title) . '">';
        self::renderGroupTreeNodes($byId, $children, $expanded, static function (array $group, int $depth, bool $hasChildren, bool $isExpanded, callable $renderToggle) use ($name, $selectedSet): void {
            $id = (int)$group['id'];
            echo '<div class="group-tree-row" style="--depth: ' . (int)$depth . '">';
            $renderToggle();
            echo '<label class="group-tree-check" role="treeitem" aria-level="' . (int)($depth + 1) . '"><input type="checkbox" name="' . Util::h($name) . '" value="' . $id . '" ' . (isset($selectedSet[$id]) ? 'checked' : '') . '> <span>' . Util::h((string)$group['name']) . '</span></label>';
            echo '</div>';
        });
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

    private static function notice(string $message, string $class = ''): void
    {
        if ($message !== '') {
            $classes = trim('alert ' . $class);
            echo '<div class="' . Util::h($classes) . '">' . Util::h($message) . '</div>';
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

    private static function previewUrl(array $camera): string
    {
        if (empty($camera['server_url']) || empty($camera['dvr_stream_name'])) {
            return '';
        }

        return '/viewer/preview?' . http_build_query(['id' => (int)$camera['id']]);
    }

    private static function externalPreviewUrl(array $camera, string $token, string $cacheBust = ''): string
    {
        $query = ['token' => $token];
        if ($cacheBust !== '') {
            $query['_'] = $cacheBust;
        }

        return rtrim((string)$camera['server_url'], '/') . '/' . rawurlencode((string)$camera['dvr_stream_name']) . '/preview.jpg?' . http_build_query($query);
    }

    private static function cameraStreamUnavailable(array $camera): bool
    {
        $metrics = json_decode((string)($camera['server_metrics_json'] ?? ''), true);
        if (!is_array($metrics)) {
            return false;
        }

        $streams = $metrics['streams']['streams'] ?? null;
        if (!is_array($streams)) {
            return false;
        }

        $streamName = (string)($camera['dvr_stream_name'] ?: $camera['name']);
        if ($streamName === '') {
            return false;
        }

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $name = (string)($stream['name'] ?? '');
            $displayName = (string)($stream['displayName'] ?? $stream['title'] ?? '');
            if ($name === $streamName || $name === (string)$camera['name'] || $displayName === (string)$camera['name']) {
                return self::streamMetricUnavailable($stream);
            }
        }

        return false;
    }

    private static function streamMetricUnavailable(array $stream): bool
    {
        if (array_key_exists('running', $stream)) {
            return !self::truthyMetricValue($stream['running']);
        }

        $problemCode = $stream['archiveStatus']['problem']['code'] ?? null;
        if ($problemCode === 'ingest_not_running') {
            return true;
        }

        if (array_key_exists('runtimeDesired', $stream) && !self::truthyMetricValue($stream['runtimeDesired'])) {
            return true;
        }

        return false;
    }

    private static function truthyMetricValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'running'], true);
        }

        return false;
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

        foreach (self::authRequestTargets() as $target) {
            $query = parse_url($target, PHP_URL_QUERY);
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

        foreach (self::authRequestTargets() as $target) {
            $path = trim((string)parse_url($target, PHP_URL_PATH), '/');
            if ($path !== '') {
                return basename(explode('/', $path)[0] ?? '');
            }
        }

        return '';
    }

    private static function authRequestTarget(): string
    {
        return self::authRequestTargets()[0] ?? '';
    }

    private static function authRequestTargets(): array
    {
        $values = [];
        foreach (['uri', 'path', 'request_uri', 'original_uri'] as $key) {
            if (!empty($_GET[$key])) {
                $values[] = (string)$_GET[$key];
            }
        }
        foreach (['HTTP_X_ORIGINAL_URI', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_FORWARDED_URI', 'HTTP_X_REQUEST_URI'] as $key) {
            if (!empty($_SERVER[$key])) {
                $values[] = (string)$_SERVER[$key];
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $values), static fn(string $value): bool => $value !== '')));
    }

    private static function authRequestMethod(): string
    {
        $method = (string)(
            $_GET['method']
            ?? $_GET['request_method']
            ?? $_SERVER['HTTP_X_ORIGINAL_METHOD']
            ?? $_SERVER['HTTP_X_FORWARDED_METHOD']
            ?? 'GET'
        );
        $method = strtoupper(preg_replace('/[^A-Z]/i', '', $method) ?: 'GET');
        return substr($method, 0, 12);
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

    private static function rowById(string $table, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = DB::pdo()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function rowsByIds(string $table, array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = $table === 'cameras' ? self::apiCameraById($id) : self::rowById($table, $id);
            if ($row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function missingIds(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = DB::pdo()->prepare('SELECT id FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $existing = array_fill_keys(array_map('intval', array_column($stmt->fetchAll(), 'id')), true);
        return array_values(array_filter($ids, static fn(int $id): bool => !isset($existing[$id])));
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private static function explicitGroupIdFromInput(array $input): array
    {
        if (!array_key_exists('id', $input)) {
            return [null, ''];
        }

        $value = $input['id'];
        if (is_int($value)) {
            return $value > 0 ? [$value, ''] : [null, 'id must be a positive integer'];
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '' && ctype_digit($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (is_int($parsed)) {
                    return [$parsed, ''];
                }
            }
        }

        return [null, 'id must be a positive integer'];
    }

    private static function syncPortalGroupIdentityAfterExplicitInsert(): void
    {
        if (DB::driver() !== 'pgsql') {
            return;
        }

        $pdo = DB::pdo();
        $sequence = (string)$pdo->query("SELECT pg_get_serial_sequence('portal_groups', 'id')")->fetchColumn();
        if ($sequence === '') {
            return;
        }

        $current = (int)$pdo->query('SELECT last_value FROM ' . self::quoteQualifiedIdentifier($sequence))->fetchColumn();
        $max = (int)$pdo->query('SELECT COALESCE(MAX(id), 1) FROM portal_groups')->fetchColumn();
        $pdo->prepare('SELECT setval(?::regclass, ?, true)')->execute([$sequence, max($current, $max)]);
    }

    private static function quoteQualifiedIdentifier(string $name): string
    {
        $parts = array_filter(explode('.', $name), static fn(string $part): bool => $part !== '');
        return implode('.', array_map(static fn(string $part): string => '"' . str_replace('"', '""', $part) . '"', $parts));
    }

    private static function groupName(int $id): ?string
    {
        $group = self::rowById('portal_groups', $id);
        return $group ? (string)$group['name'] : null;
    }

    private static function groupChildren(int $groupId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM portal_groups WHERE parent_group_id = ? ORDER BY name ASC');
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    private static function groupParentIdFromInput(array $input, ?array $current): ?int
    {
        if (array_key_exists('parentGroupId', $input)) {
            return self::nullableInt($input['parentGroupId']);
        }
        if (array_key_exists('parent_group_id', $input)) {
            return self::nullableInt($input['parent_group_id']);
        }
        return self::nullableInt($current['parent_group_id'] ?? null);
    }

    private static function groupParentValidationError(int $groupId, ?int $parentId): string
    {
        if ($parentId === null) {
            return '';
        }
        if (!self::rowById('portal_groups', $parentId)) {
            return self::t('groups.parentNotFound', 'Родительская группа не найдена');
        }
        if ($groupId > 0 && in_array($parentId, Repo::groupBranchIds([$groupId], true, true), true)) {
            return self::t('groups.parentCycle', 'Родительской группой нельзя выбрать саму группу или её подгруппу');
        }
        return '';
    }

    private static function groupRowsWithDisplayLabels(array $groups, ?array $contextGroups = null): array
    {
        $contextGroups ??= $groups;
        $labels = self::groupPathLabels($contextGroups);
        $byId = [];
        foreach ($contextGroups as $group) {
            $byId[(int)$group['id']] = $group;
        }

        foreach ($groups as &$group) {
            $id = (int)$group['id'];
            $parentId = (int)($group['parent_group_id'] ?? 0);
            $group['display_name'] = $labels[$id] ?? (string)$group['name'];
            $group['parent_group_name'] = $parentId > 0 && isset($byId[$parentId])
                ? ($labels[$parentId] ?? (string)$byId[$parentId]['name'])
                : self::t('groups.noParent', 'Без родителя');
        }
        unset($group);

        usort($groups, static fn(array $left, array $right): int => strnatcasecmp((string)$left['display_name'], (string)$right['display_name']));
        return $groups;
    }

    private static function groupRowsWithTreeLabels(array $groups): array
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

        $rows = [];
        $seen = [];
        $append = function (int $id, int $depth) use (&$append, &$rows, &$seen, $children, $byId): void {
            if (isset($seen[$id]) || !isset($byId[$id])) {
                return;
            }
            $seen[$id] = true;
            $row = $byId[$id];
            $parentId = (int)($row['parent_group_id'] ?? 0);
            $row['display_name'] = str_repeat('  ', $depth) . ($depth > 0 ? '↳ ' : '') . (string)$row['name'];
            $row['parent_group_name'] = $parentId > 0 && isset($byId[$parentId])
                ? (string)$byId[$parentId]['name']
                : self::t('groups.noParent', 'Без родителя');
            $row['tree_depth'] = $depth;
            $rows[] = $row;

            foreach ($children[$id] ?? [] as $childId) {
                $append((int)$childId, $depth + 1);
            }
        };

        foreach ($children[0] ?? [] as $rootId) {
            $append((int)$rootId, 0);
        }
        foreach (array_keys($byId) as $id) {
            $append((int)$id, 0);
        }

        return $rows;
    }

    private static function groupParentOptions(array $groups, int $editedGroupId): array
    {
        $excluded = $editedGroupId > 0 ? array_flip(Repo::groupBranchIds([$editedGroupId], true, true)) : [];
        $options = [];
        foreach (self::groupRowsWithDisplayLabels($groups) as $group) {
            if (!isset($excluded[(int)$group['id']])) {
                $options[] = $group;
            }
        }
        return $options;
    }

    private static function groupPathLabels(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            $byId[(int)$group['id']] = $group;
        }

        $labels = [];
        $build = static function (int $id, array $stack = []) use (&$build, &$labels, $byId): string {
            if (isset($labels[$id])) {
                return $labels[$id];
            }
            if (!isset($byId[$id])) {
                return '';
            }
            if (isset($stack[$id])) {
                $labels[$id] = (string)$byId[$id]['name'];
                return $labels[$id];
            }

            $parentId = (int)($byId[$id]['parent_group_id'] ?? 0);
            $name = (string)$byId[$id]['name'];
            if ($parentId > 0 && isset($byId[$parentId])) {
                $parentLabel = $build($parentId, $stack + [$id => true]);
                $labels[$id] = $parentLabel !== '' ? $parentLabel . ' / ' . $name : $name;
            } else {
                $labels[$id] = $name;
            }
            return $labels[$id];
        };

        foreach (array_keys($byId) as $id) {
            $build((int)$id);
        }
        return $labels;
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

    private static function addLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        $stmt = DB::pdo()->prepare(DB::insertIgnoreSql($table, [$ownerKey, $targetKey]));
        foreach ($values as $value) {
            $stmt->execute([$ownerId, (int)$value]);
        }
    }

    private static function removeLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        if ($values === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $stmt = DB::pdo()->prepare("DELETE FROM {$table} WHERE {$ownerKey} = ? AND {$targetKey} IN ({$placeholders})");
        $stmt->execute([$ownerId, ...array_map('intval', $values)]);
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
