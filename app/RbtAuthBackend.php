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
        $hash = hash('sha256', $token);
        $found = null;
        foreach ($clients as $id => $client) {
            if (!self::clientEnabled($client)) {
                continue;
            }
            if (hash_equals($client['management_token_hash'], $hash)) {
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
        $ref = RbtToken::canonicalDvrRef($input['dvr_ref'] ?? null);
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
            'dvr_ref', 'secret_id', 'rbt_id', 'secret_enc', 'valid_from', 'expires_at',
        ]));
        $stmt->execute([$ref, $id, $clientId, Crypto::encrypt($secret), $from, $expires]);
        $stored = self::findSecret($ref, $id);
        if (!$stored || $stored['rbt_id'] !== $clientId
            || (int)$stored['valid_from'] !== $from || (int)$stored['expires_at'] !== $expires
            || !hash_equals(Crypto::decrypt($stored['secret_enc']), $secret)) {
            throw new RuntimeException('secret_id_conflict', 409);
        }
        return [
            'registered' => true,
            'dvr_ref' => $ref,
            'secret_id' => $id,
            'valid_from' => $from,
            'expires_at' => $expires,
        ];
    }

    public static function authorize(string $token, string $dvrToken, string $streamName): bool
    {
        $parsed = RbtToken::parse($token);
        if (!$parsed || !RbtToken::validStreamName($streamName)) {
            return false;
        }
        $ref = self::dvrForToken($dvrToken);
        if ($ref === null) {
            return false;
        }
        $stored = self::findSecret($ref, $parsed['secret_id']);
        $now = time();
        if (!$stored || (int)$stored['valid_from'] > $now || (int)$stored['expires_at'] <= $now) {
            return false;
        }
        $config = Config::get('rbt_authbackend', []);
        if (!self::clientEnabled($config['clients'][$stored['rbt_id']] ?? null)) {
            return false;
        }
        // The stream name comes from the DVR request, not from token contents
        // or a lookup in Portal's camera/user database. RBT owns those ACLs.
        return RbtToken::verify($parsed, Crypto::decrypt($stored['secret_enc']), $streamName);
    }

    public static function cleanup(): int
    {
        $stmt = DB::pdo()->prepare('DELETE FROM ' . self::TABLE . ' WHERE expires_at <= ?');
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }

    public static function rotateEncryption(): int
    {
        $rows = DB::pdo()->query('SELECT dvr_ref, secret_id, secret_enc FROM ' . self::TABLE)->fetchAll();
        $update = DB::pdo()->prepare('UPDATE ' . self::TABLE . ' SET secret_enc = ? WHERE dvr_ref = ? AND secret_id = ?');
        $count = 0;
        foreach ($rows as $row) {
            if (!Crypto::needsRotation($row['secret_enc'])) {
                continue;
            }
            $plain = Crypto::decrypt($row['secret_enc']);
            if (strlen($plain) === 32) {
                $update->execute([Crypto::encrypt($plain), $row['dvr_ref'], $row['secret_id']]);
                $count++;
            }
        }
        return $count;
    }

    private static function findSecret(string $ref, int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE dvr_ref = ? AND secret_id = ?');
        $stmt->execute([$ref, $id]);
        return $stmt->fetch() ?: null;
    }

    private static function clientEnabled(mixed $client): bool
    {
        return is_array($client) && empty($client['disabled'])
            && self::validHash($client['management_token_hash'] ?? null);
    }

    private static function validHash(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1;
    }

    private static function dvrForToken(string $token): ?string
    {
        $config = Config::get('rbt_authbackend', []);
        $dvrs = is_array($config) ? ($config['dvrs'] ?? []) : [];
        if ($token === '' || !is_array($dvrs)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $found = null;
        foreach ($dvrs as $ref => $dvr) {
            if (!is_array($dvr) || !empty($dvr['disabled']) || !self::validHash($dvr['token_hash'] ?? null)) {
                continue;
            }
            if (hash_equals($dvr['token_hash'], $hash)) {
                if ($found !== null) {
                    return null;
                }
                $found = RbtToken::canonicalDvrRef($ref);
            }
        }
        return $found;
    }
}
