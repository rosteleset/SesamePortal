<?php

declare(strict_types=1);

namespace SesamePortal;

use InvalidArgumentException;
use RuntimeException;

final class RbtAuthBackend
{
    public const PREFIX = 'R1.';
    public const REGISTRATION_PATH = '/api/rbt/v1/secrets';
    private const TABLE = 'rbt_authbackend_secrets';

    public static function registerRequest(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Allow: POST');
            return self::registrationError(405, 'method_not_allowed', 'Use POST');
        }
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = preg_match('/\ABearer ([^\s]+)\z/i', $authorization, $matches) ? $matches[1] : '';
        $client = self::managementClient($token);
        if ($client === null) {
            return self::registrationError(401, 'unauthorized', 'A valid RBT management token is required');
        }
        if (strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
            return self::registrationError(415, 'unsupported_media_type', 'Use application/json');
        }
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if ($raw === false || strlen($raw) > 4096) {
            return self::registrationError(413, 'request_too_large', 'Maximum request size is 4096 bytes');
        }
        $input = json_decode($raw, true);
        if (!is_array($input) || array_is_list($input)) {
            return self::registrationError(400, 'invalid_json', 'Expected a JSON object');
        }
        try {
            return [200, self::register($client, $input)];
        } catch (InvalidArgumentException $error) {
            return self::registrationError(422, 'invalid_registration', $error->getMessage());
        } catch (\Throwable $error) {
            if ($error->getCode() === 409) {
                return self::registrationError(409, 'secret_id_conflict', 'This secret_id is already registered with different parameters');
            }
            // Never expose SQL values, request bodies or credentials in errors.
            error_log('SesamePortal RBT secret registration failed');
            return self::registrationError(503, 'registration_unavailable', 'Secret registration is temporarily unavailable');
        }
    }

    public static function playbackResponse(string $token, bool $ptz): array
    {
        // Use callback metadata, never a URL/hostname nested in the client's qs.
        $host = is_string($_GET['host'] ?? null) ? $_GET['host'] : '';
        $name = is_string($_GET['name'] ?? null) ? $_GET['name'] : '';
        try {
            $allowed = self::authorize($token, $host, $name);
        } catch (\Throwable $error) {
            error_log('SesamePortal RBT playback authorization unavailable');
            $allowed = false;
        }
        if (!$allowed) {
            return [403, "denied\n"];
        }
        // A playback token does not grant PTZ capabilities.
        return $ptz ? [403, "ptz_denied\n"] : [200, "ok\n"];
    }

    private static function registrationError(int $status, string $code, string $message): array
    {
        return [$status, ['error' => ['code' => $code, 'message' => $message]]];
    }

    private static function managementClient(string $token): ?string
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
        // Keep the wire format in sync with RBT/server/backends/dvr/internal/RbtToken.php.
        if (preg_match('/\AR1\.([A-Za-z0-9_-]{38})\z/', $token, $matches) !== 1
            || strlen($streamName) > 128 || preg_match('/\A[\p{L}\p{N}][\p{L}\p{N}._-]*\z/u', $streamName) !== 1) {
            return false;
        }
        $raw = base64_decode(strtr($matches[1], '-_', '+/') . '==', true);
        if ($raw === false || strlen($raw) !== 28
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $matches[1]) {
            return false;
        }
        $secretId = unpack('Nid', substr($raw, 0, 4))['id'];
        $hostname = self::hostnameFromRequest($host);
        if ($secretId < 1 || $hostname === null) {
            return false;
        }
        $stored = self::findSecret($secretId);
        $now = time();
        if (!$stored || (int)$stored['valid_from'] > $now || (int)$stored['expires_at'] <= $now) {
            return false;
        }
        $config = Config::get('rbt_authbackend', []);
        if (!self::clientEnabled($config['clients'][$stored['rbt_id']] ?? null)) {
            return false;
        }
        $secret = base64_decode($stored['secret'], true);
        if ($secret === false || strlen($secret) !== 32) {
            return false;
        }
        // The 12-byte header contains secret_id and subscriberId. Bind it to the stream.
        $message = self::PREFIX . substr($raw, 0, 12)
            . pack('n', strlen($hostname)) . $hostname
            . pack('n', strlen($streamName)) . $streamName;
        return hash_equals(substr(hash_hmac('sha256', $message, $secret, true), 0, 16), substr($raw, 12));
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

    private static function hostnameFromRequest(string $host): ?string
    {
        if ($host === '' || strlen($host) > 259) {
            return null;
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = parse_url('http://' . $host);
            if (!$parts || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
                return null;
            }
            $host = $parts['host'] ?? '';
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return inet_ntop(inet_pton($host));
        }
        $host = strtolower(rtrim($host, '.'));
        return strlen($host) <= 253 && preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $host)
            ? $host : null;
    }

    private static function clientEnabled(mixed $client): bool
    {
        return is_array($client) && empty($client['disabled'])
            && is_string($client['management_token'] ?? null)
            && $client['management_token'] !== ''
            && preg_match('/[\x00-\x20\x7f]/', $client['management_token']) === 0;
    }
}
