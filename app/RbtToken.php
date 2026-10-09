<?php

namespace SesamePortal;

/** R1 wire protocol. Keep in sync with RBT/server/backends/dvr/internal/RbtToken.php. */
final class RbtToken
{
    const PREFIX = 'R1.';

    public static function dvrRef($url)
    {
        $parts = is_string($url) ? parse_url($url) : false;
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!$parts || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new \InvalidArgumentException('invalid_dvr_url');
        }
        $host = strtolower($parts['host']);
        if (substr($host, 0, 1) === '[') {
            $ip = substr($host, 1, -1);
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                throw new \InvalidArgumentException('invalid_dvr_host');
            }
            $host = '[' . inet_ntop(inet_pton($ip)) . ']';
        } else {
            $host = rtrim($host, '.');
            if (strlen($host) > 253 || !preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $host)) {
                throw new \InvalidArgumentException('invalid_dvr_host');
            }
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('invalid_dvr_port');
        }
        return $host . ':' . $port;
    }

    public static function canonicalDvrRef($ref)
    {
        if (!is_string($ref) || strlen($ref) > 259 || $ref !== self::dvrRef('http://' . $ref)) {
            throw new \InvalidArgumentException('dvr_ref_must_be_canonical_hostname_port');
        }
        return $ref;
    }

    public static function validStreamName($name)
    {
        return is_string($name) && strlen($name) <= 128
            && preg_match('/\A[\p{L}\p{N}][\p{L}\p{N}._-]*\z/u', $name) === 1;
    }

    public static function streamFromUrl($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || preg_match('/%(?![0-9a-f]{2})/i', $path)) {
            throw new \InvalidArgumentException('invalid_stream_url');
        }
        $name = rawurldecode(explode('/', ltrim($path, '/'))[0]);
        if (!self::validStreamName($name)) {
            throw new \InvalidArgumentException('invalid_stream_name');
        }
        return $name;
    }

    public static function create($secret, $secretId, $subscriberId, $streamName)
    {
        if (PHP_INT_SIZE < 8 || !is_string($secret) || strlen($secret) !== 32
            || !is_int($secretId) || $secretId < 1 || $secretId > 4294967295
            || !self::validStreamName($streamName)) {
            throw new \InvalidArgumentException('invalid_rbt_token_parameters');
        }
        // All ones (uint64) is reserved for RBT's existing service subscriberId=-1.
        $uid = filter_var($subscriberId, FILTER_VALIDATE_INT);
        if ($uid === false || $uid < -1) {
            throw new \InvalidArgumentException('invalid_subscriber_id');
        }
        $header = pack('N', $secretId) . pack('J', $uid);
        $mac = self::mac($secret, $header, $streamName);
        return self::PREFIX . self::base64Url($header . $mac);
    }

    public static function parse($token)
    {
        if (!is_string($token) || preg_match('/\AR1\.([A-Za-z0-9_-]{38})\z/', $token, $matches) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($matches[1], '-_', '+/') . '==', true);
        if ($raw === false || strlen($raw) !== 28 || self::base64Url($raw) !== $matches[1]) {
            return null;
        }
        $id = unpack('Nid', substr($raw, 0, 4))['id'];
        if ($id < 1) {
            return null;
        }
        return ['secret_id' => $id, 'header' => substr($raw, 0, 12), 'mac' => substr($raw, 12)];
    }

    public static function verify($parsed, $secret, $streamName)
    {
        return is_array($parsed) && is_string($secret) && strlen($secret) === 32
            && self::validStreamName($streamName)
            && hash_equals(self::mac($secret, $parsed['header'], $streamName), $parsed['mac']);
    }

    private static function mac($secret, $header, $streamName)
    {
        return substr(hash_hmac('sha256', self::PREFIX . $header . pack('n', strlen($streamName)) . $streamName, $secret, true), 0, 16);
    }

    private static function base64Url($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
