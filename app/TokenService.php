<?php

declare(strict_types=1);

namespace SesamePortal;

use DateTimeImmutable;
use DateTimeZone;
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
        DB::pdo()->prepare('UPDATE users SET static_token_hash = ?, static_token_enc = ? WHERE id = ?')
            ->execute([password_hash($token, PASSWORD_DEFAULT), Crypto::encrypt($token), $userId]);
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
        DB::pdo()->prepare('UPDATE users SET static_token_hash = NULL, static_token_enc = NULL WHERE id = ?')->execute([$userId]);
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
        $stmt = DB::pdo()->prepare('SELECT id, login, static_token_hash, static_token_enc FROM users WHERE id = ?');
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

    public static function issueCameraToken(int $cameraId, ?array $actor = null): string
    {
        $row = self::cameraTokenRow($cameraId);
        $hadToken = !empty($row['permanent_token_hash']);
        $token = 'cam_' . Util::randomToken();
        DB::pdo()->prepare('UPDATE cameras SET permanent_token_hash = ?, permanent_token_enc = ? WHERE id = ?')
            ->execute([password_hash($token, PASSWORD_DEFAULT), Crypto::encrypt($token), $cameraId]);
        self::logCameraTokenEvent(
            $actor,
            $hadToken ? 'camera.permanent_token.replace' : 'camera.permanent_token.issue',
            $cameraId,
            $row,
            ['previous=' . ($hadToken ? 'yes' : 'no')]
        );
        return $token;
    }

    public static function revokeCameraToken(int $cameraId, ?array $actor = null): void
    {
        $row = self::cameraTokenRow($cameraId);
        $hadToken = !empty($row['permanent_token_hash']);
        DB::pdo()->prepare('UPDATE cameras SET permanent_token_hash = NULL, permanent_token_enc = NULL WHERE id = ?')->execute([$cameraId]);
        self::logCameraTokenEvent(
            $actor,
            'camera.permanent_token.revoke',
            $cameraId,
            $row,
            ['previous=' . ($hadToken ? 'yes' : 'no')]
        );
    }

    public static function cameraByPermanentToken(string $token, string $cameraName): ?array
    {
        if ($token === '') {
            return null;
        }

        $stmt = DB::pdo()->query('SELECT * FROM cameras WHERE blocked = 0 AND permanent_token_hash IS NOT NULL');
        foreach ($stmt->fetchAll() as $camera) {
            if ($camera['permanent_token_hash'] && password_verify($token, $camera['permanent_token_hash'])) {
                if ($cameraName === '') {
                    return $camera;
                }
                $stream = (string)($camera['dvr_stream_name'] ?? $camera['name'] ?? '');
                if ($stream === $cameraName || (string)$camera['name'] === $cameraName) {
                    return $camera;
                }
            }
        }

        return null;
    }

    private static function cameraTokenRow(int $cameraId): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT id, name, permanent_token_hash, permanent_token_enc FROM cameras WHERE id = ?');
        $stmt->execute([$cameraId]);
        return $stmt->fetch() ?: null;
    }

    private static function logCameraTokenEvent(?array $actor, string $action, int $cameraId, ?array $target, array $parts = []): void
    {
        $details = array_merge([
            'camera_id=' . $cameraId,
            'name=' . Audit::cleanValue((string)($target['name'] ?? '')),
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
