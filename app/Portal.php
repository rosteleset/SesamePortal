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

        self::$config = array_replace([
            'state_dir' => $stateDir,
            'db_path' => $stateDir . '/portal.sqlite',
            'app_secret' => getenv('SESAME_PORTAL_SECRET') ?: 'dev-insecure-change-me',
            'timezone' => getenv('SESAME_PORTAL_TIMEZONE') ?: 'UTC',
            'base_url' => getenv('SESAME_PORTAL_BASE_URL') ?: '',
            'auth_backend_path' => '/api/sesamedvr/auth',
        ], is_array($loaded) ? $loaded : []);

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

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $stateDir = Config::stateDir();
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0750, true);
        }

        $pdo = new PDO('sqlite:' . Config::get('db_path'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function migrate(): void
    {
        $pdo = self::pdo();
        $sql = [
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
                blocked INTEGER NOT NULL DEFAULT 0,
                dvr_stream_name TEXT NOT NULL,
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
            'CREATE INDEX IF NOT EXISTS idx_camera_groups_group ON camera_groups(group_id)',
            'CREATE INDEX IF NOT EXISTS idx_user_groups_group ON user_groups(group_id)',
            'CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites(user_id)',
        ];

        foreach ($sql as $statement) {
            $pdo->exec($statement);
        }
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

final class Crypto
{
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $key = hash('sha256', (string)Config::get('app_secret'), true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('encrypt_failed');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $encoded): string
    {
        if (!$encoded) {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }

        $key = hash('sha256', (string)Config::get('app_secret'), true);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
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
            return ['ok' => false, 'message' => 'No SesameDVR server selected'];
        }

        $server = Repo::server((int)$camera['server_id']);
        if (!$server || (int)$server['blocked'] === 1) {
            return ['ok' => false, 'message' => 'SesameDVR server is unavailable or blocked'];
        }

        $token = Crypto::decrypt($server['management_token_enc'] ?? null);
        if ($token === '') {
            return ['ok' => false, 'message' => 'SesameDVR management token is missing'];
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
        $result = self::request('PUT', $base . '/api/streams/' . rawurlencode($name), $token, $payload);
        if ($result['status'] === 404) {
            $result = self::request('POST', $base . '/api/streams', $token, $payload);
        }

        return [
            'ok' => $result['status'] >= 200 && $result['status'] < 300,
            'message' => 'HTTP ' . $result['status'] . ' ' . substr($result['body'], 0, 180),
        ];
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
        $message = 'HTTP ' . $result['status'] . ' ' . substr($result['body'], 0, 180);
        DB::pdo()->prepare('UPDATE dvr_servers SET last_check_at = ?, last_check_result = ? WHERE id = ?')
            ->execute([Util::now(), $message, $serverId]);
        return ['ok' => $ok, 'message' => $message];
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
    public static function run(): void
    {
        DB::migrate();
        Auth::start();
        Csrf::verify();

        $path = Util::path();
        match ($path) {
            '/login' => self::login(),
            '/logout' => self::logout(),
            '/admin/users' => self::users(),
            '/admin/groups' => self::groups(),
            '/admin/servers' => self::servers(),
            '/admin/cameras' => self::cameras(),
            '/admin/audit' => self::audit(),
            '/viewer/map' => self::viewer('map'),
            '/favorite/toggle' => self::toggleFavorite(),
            '/api/sesamedvr/auth' => self::authBackend(),
            default => self::viewer('mosaic'),
        };
    }

    private static function login(): void
    {
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (Auth::login((string)Util::post('login'), (string)Util::post('password'))) {
                Util::redirect('/');
            }
            $error = 'Неверный логин или пароль';
        }

        self::layout('Вход', function () use ($error) {
            echo '<section class="login-panel">';
            echo '<h1>SesamePortal</h1><p>Портал видеонаблюдения SesameWare</p>';
            if ($error) {
                echo '<div class="alert danger">' . Util::h($error) . '</div>';
            }
            echo '<form method="post" class="form">';
            echo Csrf::field();
            echo '<label>Логин<input name="login" autocomplete="username" required></label>';
            echo '<label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>';
            echo '<button class="primary">Войти</button>';
            echo '</form></section>';
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
                    $message = 'Логин обязателен';
                } elseif ($id === 0 && strlen($password) < 6) {
                    $message = 'Пароль должен быть не короче 6 символов';
                } else {
                    if ($id > 0) {
                        if ($password !== '') {
                            if (strlen($password) < 6) {
                                $message = 'Пароль должен быть не короче 6 символов';
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
        $users = Repo::all('users', 'login ASC');
        self::layout('Пользователи', function () use ($users, $edit, $message, $staticToken) {
            self::notice($message);
            if ($staticToken) {
                echo '<div class="alert">Static token: <code>' . Util::h($staticToken) . '</code></div>';
            }
            echo '<div class="admin-grid">';
            echo '<section class="panel"><h2>' . ($edit ? 'Изменить пользователя' : 'Новый пользователь') . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>Логин<input name="login" value="' . Util::h($edit['login'] ?? '') . '" required></label>';
            echo '<label>Пароль<input name="password" type="password" minlength="6" placeholder="' . ($edit ? 'оставьте пустым, чтобы не менять' : 'минимум 6 символов') . '"></label>';
            echo '<label>Роль<select name="role"><option value="user">user</option><option value="admin" ' . (($edit['role'] ?? '') === 'admin' ? 'selected' : '') . '>admin</option></select></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> Заблокирован</label>';
            echo '<button class="primary">Сохранить</button></form></section>';
            self::table('Пользователи', ['login', 'role', 'blocked', 'last_login_at'], $users, '/admin/users');
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
                    $id = (int)$pdo->lastInsertId();
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
        $groups = Repo::all('portal_groups', 'name ASC');
        self::layout('Группы', function () use ($edit, $users, $cameras, $linkedUsers, $linkedCameras, $groups) {
            echo '<div class="admin-grid"><section class="panel"><h2>' . ($edit ? 'Изменить группу' : 'Новая группа') . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>Название<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>Описание<textarea name="description">' . Util::h($edit['description'] ?? '') . '</textarea></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> Заблокирована</label>';
            self::checkboxList('Пользователи', 'user_ids[]', $users, $linkedUsers, 'login');
            self::checkboxList('Камеры', 'camera_ids[]', $cameras, $linkedCameras, 'name');
            echo '<button class="primary">Сохранить</button></form></section>';
            self::table('Группы', ['name', 'blocked', 'description'], $groups, '/admin/groups');
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
        $servers = Repo::all('dvr_servers', 'name ASC');
        self::layout('Серверы SesameDVR', function () use ($edit, $servers, $message) {
            self::notice($message);
            echo '<div class="admin-grid"><section class="panel"><h2>' . ($edit ? 'Изменить сервер' : 'Новый сервер') . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>Название<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>URL<input name="base_url" value="' . Util::h($edit['base_url'] ?? '') . '" placeholder="https://dvr.example.com" required></label>';
            echo '<label>Management key<input name="management_token" placeholder="' . ($edit ? 'оставьте пустым, чтобы не менять' : '') . '"></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> Заблокирован</label>';
            echo '<button class="primary">Сохранить</button></form></section>';
            self::table('Серверы', ['name', 'base_url', 'blocked', 'last_check_result'], $servers, '/admin/servers', true);
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
                $serverId = (int)Util::post('server_id', 0) ?: null;
                if ($selection === 'auto' && !$serverId) {
                    $serverId = self::randomActiveServerId();
                }
                $name = trim((string)Util::post('name'));
                $stream = trim((string)Util::post('dvr_stream_name')) ?: self::slug($name);
                $values = [
                    $name,
                    Util::post('source_url'),
                    $serverId,
                    $selection,
                    self::nullableFloat(Util::post('latitude')),
                    self::nullableFloat(Util::post('longitude')),
                    (int)Util::post('direction_deg', 0),
                    (int)Util::post('view_angle_deg', 60),
                    Util::post('retention_days', '7d'),
                    Util::checkbox('blocked'),
                    $stream,
                ];
                if ($id > 0) {
                    $pdo->prepare('UPDATE cameras SET name=?, source_url=?, server_id=?, server_selection=?, latitude=?, longitude=?, direction_deg=?, view_angle_deg=?, retention_days=?, blocked=?, dvr_stream_name=?, updated_at=? WHERE id=?')
                        ->execute([...$values, Util::now(), $id]);
                } else {
                    $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, blocked, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([...$values, Util::now(), Util::now()]);
                    $id = (int)$pdo->lastInsertId();
                }
                self::replaceLinks('camera_groups', 'camera_id', $id, 'group_id', $_POST['group_ids'] ?? []);
                $sync = DvrClient::syncCamera($id);
                $message = $sync['message'];
                Audit::log('camera.save', $name . ' sync=' . $sync['message']);
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
        $cameras = DB::pdo()->query('SELECT c.*, s.name AS server_name FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id ORDER BY c.name ASC')->fetchAll();
        self::layout('Камеры', function () use ($edit, $servers, $groups, $linkedGroups, $cameras, $message) {
            self::notice($message);
            echo '<div class="admin-grid"><section class="panel"><h2>' . ($edit ? 'Изменить камеру' : 'Новая камера') . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>Имя<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>URL источника<input name="source_url" value="' . Util::h($edit['source_url'] ?? '') . '" required></label>';
            echo '<label>Сервер<select name="server_id"><option value="">Авто/не выбран</option>';
            foreach ($servers as $server) {
                echo '<option value="' . (int)$server['id'] . '" ' . (($edit['server_id'] ?? '') == $server['id'] ? 'selected' : '') . '>' . Util::h($server['name']) . '</option>';
            }
            echo '</select></label>';
            echo '<label>Выбор сервера<select name="server_selection"><option value="manual">конкретный</option><option value="auto" ' . (($edit['server_selection'] ?? '') === 'auto' ? 'selected' : '') . '>автоматический случайный</option></select></label>';
            echo '<label>Имя потока SesameDVR<input name="dvr_stream_name" value="' . Util::h($edit['dvr_stream_name'] ?? '') . '"></label>';
            echo '<div class="form-row"><label>Latitude<input name="latitude" value="' . Util::h($edit['latitude'] ?? '') . '"></label><label>Longitude<input name="longitude" value="' . Util::h($edit['longitude'] ?? '') . '"></label></div>';
            echo '<div class="form-row"><label>Направление<input name="direction_deg" type="number" min="0" max="359" value="' . Util::h($edit['direction_deg'] ?? 0) . '"></label><label>Угол обзора<input name="view_angle_deg" type="number" min="1" max="180" value="' . Util::h($edit['view_angle_deg'] ?? 60) . '"></label></div>';
            echo '<label>Глубина архива<input name="retention_days" value="' . Util::h($edit['retention_days'] ?? '7d') . '"></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> Заблокирована</label>';
            self::checkboxList('Группы', 'group_ids[]', $groups, $linkedGroups, 'name');
            echo '<button class="primary">Сохранить и синхронизировать</button></form></section>';
            self::table('Камеры', ['name', 'server_name', 'retention_days', 'blocked'], $cameras, '/admin/cameras', true);
            echo '</div>';
        });
    }

    private static function audit(): void
    {
        Auth::requireAdmin();
        $rows = DB::pdo()->query('SELECT a.*, u.login FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
        self::layout('Журнал действий', function () use ($rows) {
            echo '<section class="panel"><h2>Последние действия</h2><table><thead><tr><th>Время</th><th>Пользователь</th><th>Действие</th><th>Детали</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td>' . Util::h($row['created_at']) . '</td><td>' . Util::h($row['login'] ?? '-') . '</td><td>' . Util::h($row['action']) . '</td><td>' . Util::h($row['details']) . '</td></tr>';
            }
            echo '</tbody></table></section>';
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
            $embed = self::embedUrl($camera, $token);
            $preview = self::previewUrl($camera, $token);
            echo '<article class="camera-card">';
            echo '<a class="preview" href="' . Util::h($embed) . '">';
            if ($preview) {
                echo '<img src="' . Util::h($preview) . '" alt="">';
            } else {
                echo '<span>Нет preview</span>';
            }
            echo '</a><div class="camera-meta"><strong>' . Util::h($camera['name']) . '</strong><span>' . Util::h($camera['server_name'] ?? 'Без сервера') . '</span></div>';
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
                'embed' => self::embedUrl($camera, $token),
            ];
        }
        echo '<script>window.SESAME_CAMERAS = ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
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
        $token = (string)($_GET['token'] ?? $_GET['auth_token'] ?? $_GET['playback_token'] ?? '');
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

    private static function layout(string $title, callable $body, ?array $userOverride = []): void
    {
        $user = $userOverride === null ? null : Auth::user();
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . Util::h($title) . ' - SesamePortal</title>';
        echo '<link rel="stylesheet" href="/assets/styles.css">';
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
        echo '</head><body>';
        if ($user) {
            echo '<header class="topbar"><div class="brand"><span class="brand-mark">S</span><div><strong>SesamePortal</strong><small>' . Util::h($title) . '</small></div></div><nav>';
            echo '<a href="/">Мозаика</a><a href="/viewer/map">Карта</a>';
            if ($user['role'] === 'admin') {
                echo '<a href="/admin/users">Пользователи</a><a href="/admin/groups">Группы</a><a href="/admin/cameras">Камеры</a><a href="/admin/servers">DVR</a><a href="/admin/audit">Журнал</a>';
            }
            echo '<a href="/logout">Выход</a></nav></header>';
        }
        echo '<main class="' . ($user ? 'workspace' : 'login-page') . '">';
        $body();
        echo '</main>';
        echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="/assets/app.js"></script>';
        echo '</body></html>';
    }

    private static function filters(string $mode, array $groups, string $filter): void
    {
        $base = $mode === 'map' ? '/viewer/map' : '/';
        echo '<section class="filters"><a class="' . ($filter === 'all' ? 'active' : '') . '" href="' . $base . '">Все</a>';
        echo '<a class="' . ($filter === 'favorites' ? 'active' : '') . '" href="' . $base . '?filter=favorites">Избранное</a>';
        foreach ($groups as $group) {
            $value = 'group:' . $group['id'];
            echo '<a class="' . ($filter === $value ? 'active' : '') . '" href="' . $base . '?filter=' . rawurlencode($value) . '">' . Util::h($group['name']) . '</a>';
        }
        echo '</section>';
    }

    private static function table(string $title, array $columns, array $rows, string $base, bool $actions = false): void
    {
        echo '<section class="panel"><h2>' . Util::h($title) . '</h2><table><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . Util::h($column) . '</th>';
        }
        echo '<th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $column) {
                echo '<td>' . Util::h($row[$column] ?? '') . '</td>';
            }
            echo '<td class="row-actions"><a href="' . $base . '?edit=' . (int)$row['id'] . '">Изменить</a>';
            if ($actions && str_contains($base, 'servers')) {
                self::smallPost($base, ['action' => 'check', 'id' => $row['id']], 'Проверить');
            }
            if ($actions && str_contains($base, 'cameras')) {
                self::smallPost($base, ['action' => 'sync', 'id' => $row['id']], 'Sync');
            }
            self::smallPost($base, ['action' => 'delete', 'id' => $row['id']], 'Удалить', 'danger');
            if ($base === '/admin/users') {
                self::smallPost($base, ['action' => 'issue_static', 'id' => $row['id']], 'Static token');
                self::smallPost($base, ['action' => 'revoke_static', 'id' => $row['id']], 'Revoke');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></section>';
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

    private static function embedUrl(array $camera, string $token): string
    {
        if (empty($camera['server_url'])) {
            return '#';
        }
        return rtrim($camera['server_url'], '/') . '/' . rawurlencode($camera['dvr_stream_name']) . '/embed.html?dvr=true&token=' . rawurlencode($token);
    }

    private static function previewUrl(array $camera, string $token): string
    {
        if (empty($camera['server_url'])) {
            return '';
        }
        return rtrim($camera['server_url'], '/') . '/' . rawurlencode($camera['dvr_stream_name']) . '/preview.jpg?token=' . rawurlencode($token);
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
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO {$table}({$ownerKey}, {$targetKey}) VALUES(?, ?)");
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
        $servers = DB::pdo()->query('SELECT id FROM dvr_servers WHERE blocked = 0 ORDER BY RANDOM() LIMIT 1')->fetchAll();
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
                $pdo->prepare('UPDATE users SET password_hash=?, role="admin", blocked=0 WHERE login=?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $login]);
            } else {
                $pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, daily_token, daily_token_date, created_at) VALUES(?, ?, "admin", 0, ?, ?, ?)')
                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), Util::randomToken(), TokenService::today(), Util::now()]);
            }
            echo "admin ready: {$login}\n";
            return;
        }

        if ($command === 'rotate-tokens') {
            $count = TokenService::rotateAll();
            echo "rotated {$count} users\n";
            return;
        }

        echo "commands: migrate, create-admin, rotate-tokens\n";
    }
}
