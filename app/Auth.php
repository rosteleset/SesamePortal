<?php

declare(strict_types=1);

namespace SesamePortal;

final class Auth
{
    private const REMEMBER_COOKIE = 'sesame_remember';
    private const REMEMBER_LIFETIME = 30 * 24 * 3600; // 30 days

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('sesame_portal');
            session_start();
        }

        if (empty($_SESSION['user_id']) && isset($_COOKIE[self::REMEMBER_COOKIE])) {
            $userId = self::validateRememberToken((string)$_COOKIE[self::REMEMBER_COOKIE]);
            if ($userId !== null) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
            }
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
        if (($user['role'] ?? '') !== 'admin' && (int)($user['must_change_password'] ?? 0) === 1) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if ($path !== '/onboarding' && $path !== '/logout') {
                Util::redirect('/onboarding');
            }
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

    public static function login(string $login, string $password, bool $rememberMe = false): bool
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

        if ($rememberMe) {
            self::setRememberMeCookie((int)$user['id']);
        }

        return true;
    }

    public static function logout(): void
    {
        self::start();
        if (!empty($_SESSION['user_id'])) {
            self::clearRememberMeCookie((int)$_SESSION['user_id']);
        }
        $_SESSION = [];
        session_destroy();
    }

    private static function setRememberMeCookie(int $userId): void
    {
        $token = Util::randomToken(32);
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $expires = gmdate('c', time() + self::REMEMBER_LIFETIME);

        DB::pdo()->prepare('UPDATE users SET remember_me_token_hash = ?, remember_me_expires = ? WHERE id = ?')
            ->execute([$hash, $expires, $userId]);

        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + self::REMEMBER_LIFETIME,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberMeCookie(int $userId): void
    {
        DB::pdo()->prepare('UPDATE users SET remember_me_token_hash = NULL, remember_me_expires = NULL WHERE id = ?')
            ->execute([$userId]);

        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', [
                'expires' => 1,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    private static function validateRememberToken(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        $now = Util::now();
        $stmt = DB::pdo()->prepare('SELECT id, remember_me_token_hash, remember_me_expires FROM users WHERE remember_me_token_hash IS NOT NULL AND blocked = 0');
        $stmt->execute();
        $users = $stmt->fetchAll();

        foreach ($users as $user) {
            if ($user['remember_me_token_hash'] === null) {
                continue;
            }
            if (strtotime((string)$user['remember_me_expires']) < strtotime($now)) {
                continue;
            }
            if (password_verify($token, $user['remember_me_token_hash'])) {
                return (int)$user['id'];
            }
        }

        return null;
    }
}
