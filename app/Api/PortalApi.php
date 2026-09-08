<?php

declare(strict_types=1);

namespace SesamePortal;

trait PortalApi
{
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
                        'video-walls',
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
                'video-walls' => self::apiVideoWalls($parts),
                'agents' => self::apiAgents($parts),
                'audit' => self::apiAudit($parts),
                default => self::apiError(404, 'not_found', 'Unknown API endpoint'),
            };
        } catch (\Throwable $error) {
            error_log('SesamePortal API internal_error method=' . self::apiMethod() . ' path=' . Util::path() . ' ip=' . Util::clientIp() . ' message=' . $error->getMessage());
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

    private static function apiOptionalString(array $input, array $keys, ?array $current, string $currentKey): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $value = trim((string)$input[$key]);
                return $value !== '' ? $value : null;
            }
        }
        if ($current && array_key_exists($currentKey, $current)) {
            $value = trim((string)($current[$currentKey] ?? ''));
            return $value !== '' ? $value : null;
        }
        return null;
    }

    private static function apiOptionalNonNegativeInt(array $input, array $keys, ?array $current, string $currentKey): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                if ($input[$key] === null || trim((string)$input[$key]) === '') {
                    return null;
                }
                return max(0, (int)$input[$key]);
            }
        }
        if ($current && array_key_exists($currentKey, $current)) {
            if ($current[$currentKey] === null || trim((string)$current[$currentKey]) === '') {
                return null;
            }
            return max(0, (int)$current[$currentKey]);
        }
        return null;
    }

    private static function apiOptionalMegabytesAsBytes(array $input, array $megabyteKeys, array $byteKeys, ?array $current, string $currentKey): ?int
    {
        foreach ($megabyteKeys as $key) {
            if (array_key_exists($key, $input)) {
                return self::cameraOptionalMegabytesAsBytes($input[$key]);
            }
        }
        return self::apiOptionalNonNegativeInt($input, $byteKeys, $current, $currentKey);
    }

    private static function apiPositiveInt(array $input, array $keys, ?array $current, string $currentKey, int $default): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $value = (int)$input[$key];
                return $value > 0 ? $value : $default;
            }
        }
        $value = (int)($current[$currentKey] ?? $default);
        return $value > 0 ? $value : $default;
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

    private static function formIntArray(string $jsonKey, string $fallbackKey): array
    {
        if (array_key_exists($jsonKey, $_POST)) {
            $raw = trim((string)$_POST[$jsonKey]);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::apiIntArray($decoded);
            }
        }

        return self::apiIntArray($_POST[$fallbackKey] ?? []);
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

    private static function apiFavorites(array $parts): void
    {
        $user = self::apiRequireUser();
        $method = self::apiMethod();
        if (count($parts) === 1 && $method === 'GET') {
            $list = Repo::accessibleCamerasPage($user, 'favorites', self::viewerSearchQuery(), (int)($_GET['page'] ?? 1), self::apiPageSize(25, 500));
            self::apiJson([
                'cameraIds' => array_map(static fn($row) => (int)$row['id'], $list['rows']),
                'cameras' => self::apiCameraRows($list['rows']),
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
}
