<?php

declare(strict_types=1);

namespace SesamePortal;

use RuntimeException;

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
