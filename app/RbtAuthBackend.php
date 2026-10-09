<?php

declare(strict_types=1);

namespace SesamePortal;

use InvalidArgumentException;
use RuntimeException;

final class RbtAuthBackend
{
    public const REGISTRATION_PATH = '/api/rbt/v1/secrets';
    public const TABLE = 'rbt_authbackend_secrets';

    public static function managementClient(string $token): ?string
    {
        $config = Config::get('rbt_authbackend', []);
        $clients = is_array($config) ? ($config['clients'] ?? []) : [];
        if ($token === '' || !is_array($clients)) {
            return null;
        }
        $found = null;
        foreach ($clients as $id => $client) {
            if (!self::clientEnabled($client)) {
                continue;
            }
            if (hash_equals($client['management_token'], $token)) {
                // A credential must identify exactly one RBT instance.
                if ($found !== null) {
                    return null;
                }
                $found = (string)$id;
            }
        }
        return $found !== '' && strlen((string)$found) <= 128 ? $found : null;
    }

    public static function register(string $clientId, array $input): array
    {
        $id = $input['secret_id'] ?? null;
        $from = $input['valid_from'] ?? null;
        $ttl = $input['ttl'] ?? null;
        $encoded = $input['secret'] ?? null;
        $secret = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_int($id) || $id < 1 || $id > 4294967295
            || !is_int($from) || $from < 1 || !is_int($ttl) || $ttl < 1
            || $ttl > PHP_INT_MAX - $from
            || $secret === false || strlen($secret) !== 32 || base64_encode($secret) !== $encoded) {
            throw new InvalidArgumentException('invalid_secret_registration');
        }
        $expires = $from + $ttl;
        if ($expires <= time()) {
            throw new InvalidArgumentException('secret_already_expired');
        }

        // INSERT-if-absent followed by comparison makes retries and concurrent
        // registration safe without renewing expiry or replacing another key.
        $stmt = DB::pdo()->prepare(DB::insertIgnoreSql(self::TABLE, [
            'secret_id', 'rbt_id', 'secret', 'valid_from', 'expires_at',
        ]));
        $stmt->execute([$id, $clientId, $encoded, $from, $expires]);
        $stored = self::findSecret($id);
        if (!$stored || $stored['rbt_id'] !== $clientId
            || (int)$stored['valid_from'] !== $from || (int)$stored['expires_at'] !== $expires
            || !hash_equals($stored['secret'], $encoded)) {
            throw new RuntimeException('secret_id_conflict', 409);
        }
        return [
            'registered' => true,
            'secret_id' => $id,
            'valid_from' => $from,
            'expires_at' => $expires,
        ];
    }

    public static function authorize(string $token, string $host, string $streamName): bool
    {
        $parsed = RbtToken::parse($token);
        if (!$parsed || !RbtToken::validStreamName($streamName)) {
            return false;
        }
        $hostname = RbtToken::hostnameFromRequest($host);
        $stored = self::findSecret($parsed['secret_id']);
        $now = time();
        if (!$stored || (int)$stored['valid_from'] > $now || (int)$stored['expires_at'] <= $now) {
            return false;
        }
        $config = Config::get('rbt_authbackend', []);
        if (!self::clientEnabled($config['clients'][$stored['rbt_id']] ?? null)) {
            return false;
        }
        // Bind the MAC to the callback's hostname and stream name. Portal does
        // not maintain an inventory of RBT's DVRs, cameras or subscribers.
        return RbtToken::verify($parsed, base64_decode($stored['secret'], true), $hostname, $streamName);
    }

    public static function cleanup(): int
    {
        $stmt = DB::pdo()->prepare('DELETE FROM ' . self::TABLE . ' WHERE expires_at <= ?');
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }

    private static function findSecret(int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE secret_id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function clientEnabled(mixed $client): bool
    {
        return is_array($client) && empty($client['disabled'])
            && is_string($client['management_token'] ?? null)
            && $client['management_token'] !== ''
            && preg_match('/[\x00-\x20\x7f]/', $client['management_token']) === 0;
    }
}
