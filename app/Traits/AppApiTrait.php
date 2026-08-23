<?php

declare(strict_types=1);

namespace SesamePortal;

trait AppApiTrait
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
                        'folders',
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
                'folders' => self::apiFolders($parts),
                'servers' => self::apiServers($parts),
                'cameras' => self::apiCameras($parts),
                'favorites' => self::apiFavorites($parts),
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
                $list = self::filteredUsers(self::apiPageSize());
                self::apiJson([
                    'users' => array_map(static fn(array $row): array => self::apiUserRow($row, false, true), $list['rows']),
                    'pagination' => self::apiPagination($list),
                ]);
                return;
            }
            if ($method === 'POST') {
                self::apiSaveUser(0, self::apiInput(), $actor);
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
                $user ? self::apiJson(['user' => self::apiUserRow($user, true, true)]) : self::apiError(404, 'not_found', 'User not found');
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveUser($id, self::apiInput(), $actor);
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
            if ($method === 'GET') {
                $user = self::rowById('users', $id);
                $encoded = trim((string)($user['static_token_enc'] ?? ''));
                self::apiJson(['token' => $encoded === '' ? null : Crypto::decrypt($encoded)]);
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

    private static function apiSaveUser(int $id, array $input, ?array $actor = null): void
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
        $hideArchive =
            array_key_exists('hideArchive', $input) || array_key_exists('hide_archive', $input)
                ? (self::apiBool($input['hideArchive'] ?? $input['hide_archive']) ? 1 : 0)
                : (int)($current['hide_archive'] ?? 0);
        $canRenameCameras =
            array_key_exists('canRenameCameras', $input) || array_key_exists('can_rename_cameras', $input)
                ? (self::apiBool($input['canRenameCameras'] ?? $input['can_rename_cameras']) ? 1 : 0)
                : (int)($current['can_rename_cameras'] ?? 0);
        $adminComment = trim((string)($input['adminComment'] ?? $input['admin_comment'] ?? ($current['admin_comment'] ?? '')));
        if ($login === '') {
            self::apiError(422, 'validation_failed', 'login is required');
            return;
        }
        if ($id === 0 && strlen($password) < 6) {
            self::apiError(422, 'validation_failed', 'password must be at least 6 characters');
            return;
        }
        $existing = self::userByLogin($login);
        if ($existing && (int)$existing['id'] !== $id) {
            self::apiUserLoginExists($existing);
            return;
        }
        if (array_key_exists('folderIds', $input) || array_key_exists('folder_ids', $input)) {
            $folderIds = self::apiIntArray($input['folderIds'] ?? $input['folder_ids'] ?? []);
            self::apiValidateExistingIds('folderIds', 'group_folders', $folderIds);
        } else {
            $folderIds = null;
        }

        $beforeFolderIds = $id > 0 ? self::linkedIds('user_folders', 'user_id', $id, 'folder_id') : [];
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                if ($password !== '') {
                    if (strlen($password) < 6) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        self::apiError(422, 'validation_failed', 'password must be at least 6 characters');
                        return;
                    }
                    $pdo->prepare('UPDATE users SET login=?, password_hash=?, role=?, blocked=?, hide_archive=?, can_rename_cameras=?, admin_comment=? WHERE id=?')
                        ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $hideArchive, $canRenameCameras, $adminComment, $id]);
                } else {
                    $pdo->prepare('UPDATE users SET login=?, role=?, blocked=?, hide_archive=?, can_rename_cameras=?, admin_comment=? WHERE id=?')
                        ->execute([$login, $role, $blocked, $hideArchive, $canRenameCameras, $adminComment, $id]);
                }
            } else {
                $pdo->prepare('INSERT INTO users(login, password_hash, role, blocked, hide_archive, can_rename_cameras, admin_comment, daily_token, daily_token_date, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$login, password_hash($password, PASSWORD_DEFAULT), $role, $blocked, $hideArchive, $canRenameCameras, $adminComment, Util::randomToken(), TokenService::today(), Util::now()]);
                $id = DB::lastInsertId('users');
            }
            if ($folderIds !== null) {
                self::replaceLinks('user_folders', 'user_id', $id, 'folder_id', $folderIds);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof \PDOException && self::isUserLoginUniqueConstraint($error)) {
                self::apiUserLoginExists(self::userByLogin($login));
                return;
            }
            throw $error;
        }
        $after = self::rowById('users', $id) ?: ['login' => $login, 'role' => $role, 'blocked' => $blocked, 'hide_archive' => $hideArchive];
        $afterFolderIds = self::linkedIds('user_folders', 'user_id', $id, 'folder_id');
        self::logUserSaveAudit($actor, $id, $current, $after, $beforeFolderIds, $afterFolderIds);
        self::apiJson(['user' => self::apiUserRow($after, true, true)], $current ? 200 : 201);
    }

    private static function apiUserLoginExists(?array $existing): void
    {
        self::apiError(409, 'login_exists', 'login already exists', [
            'existingId' => (int)($existing['id'] ?? 0),
        ]);
    }

    private static function isUserLoginUniqueConstraint(\PDOException $error): bool
    {
        $code = (string)$error->getCode();
        $message = strtolower($error->getMessage());
        return in_array($code, ['23000', '23505'], true)
            && (str_contains($message, 'users.login')
                || str_contains($message, 'users_login')
                || str_contains($message, 'for key \'login\'')
                || str_contains($message, 'for key "login"'));
    }

    private static function logUserSaveAudit(?array $actor, int $userId, ?array $before, array $after, array $beforeGroupIds, array $afterGroupIds): void
    {
        $details = self::userSaveAuditDetails($userId, $before, $after, $beforeGroupIds, $afterGroupIds);
        $actorId = $actor['id'] ?? null;
        if ($actorId !== null) {
            Audit::logForUser($actorId, 'user.save', $details);
            return;
        }
        Audit::log('user.save', $details);
    }

    private static function userSaveAuditDetails(int $userId, ?array $before, array $after, array $beforeGroupIds, array $afterGroupIds): string
    {
        return implode(' ', [
            'user_id=' . $userId,
            'login=' . self::auditFieldTransition($before, $after, 'login'),
            'role=' . self::auditFieldTransition($before, $after, 'role'),
            'blocked=' . self::auditFieldTransition($before, $after, 'blocked', true),
            'hide_archive=' . self::auditFieldTransition($before, $after, 'hide_archive', true),
            'groups=' . self::auditIdList($beforeGroupIds) . '->' . self::auditIdList($afterGroupIds),
            'ip=' . Audit::clientIp(),
        ]);
    }

    private static function auditFieldTransition(?array $before, array $after, string $key, bool $intValue = false): string
    {
        $old = $before ? ($before[$key] ?? '') : 'new';
        $new = $after[$key] ?? '';
        if ($intValue && $before) {
            $old = (int)$old;
        }
        if ($intValue) {
            $new = (int)$new;
        }
        return Audit::cleanValue((string)$old, 80) . '->' . Audit::cleanValue((string)$new, 80);
    }

    private static function auditIdList(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return '[' . implode(',', $ids) . ']';
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

        if (self::apiMethod() === 'GET') {
            self::apiJson(self::apiGroupMembersPayload($group, $resource));
            return;
        }

        self::apiError(405, 'method_not_allowed', 'Use /folders endpoints to manage group members');
    }

    private static function apiGroupMembersPayload(?array $group, string $resource): array
    {
        $isUsers = $resource === 'users';
        $targetTable = $isUsers ? 'users' : 'cameras';
        $targetKey = $isUsers ? 'user_id' : 'camera_id';
        $idsKey = $isUsers ? 'userIds' : 'cameraIds';
        $rowsKey = $isUsers ? 'users' : 'cameras';
        $linkTable = $isUsers ? 'user_folders' : 'camera_folders';

        $ids = [];
        if ($group) {
            $folderIds = array_map('intval', array_column(Repo::foldersForGroup((int)$group['id']), 'id'));
            if ($folderIds) {
                $placeholders = implode(', ', array_fill(0, count($folderIds), '?'));
                $stmt = DB::pdo()->prepare("SELECT DISTINCT {$targetKey} FROM {$linkTable} WHERE folder_id IN ({$placeholders})");
                $stmt->execute($folderIds);
                $ids = array_map('intval', array_column($stmt->fetchAll(), $targetKey));
            }
        }
        $memberRows = self::rowsByIds($targetTable, $ids);
        $rows = $isUsers
            ? array_map(static fn(array $row): array => self::apiUserRow($row, false, true), $memberRows)
            : self::apiCameraRows($memberRows);

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
        $userIdsProvided = false;
        $cameraIdsProvided = false;
        $userIds = [];
        $cameraIds = [];
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

    private static function apiFolders(array $parts): void
    {
        self::apiRequireAdmin();
        $method = self::apiMethod();
        $id = isset($parts[1]) ? (int)$parts[1] : 0;

        if (count($parts) === 1) {
            if ($method === 'GET') {
                $list = self::filteredRows('group_folders', ['name', 'description'], 'name ASC', self::apiPageSize());
                self::apiJson(['folders' => array_map(fn($row) => self::apiFolderRow($row), $list['rows']), 'pagination' => self::apiPagination($list)]);
                return;
            }
            if ($method === 'POST') {
                self::apiSaveFolder(0, self::apiInput());
                return;
            }
        }
        if ($id <= 0) {
            self::apiError(404, 'not_found', 'Folder not found');
            return;
        }
        if (count($parts) === 2) {
            if ($method === 'GET') {
                $folder = Repo::folder($id);
                $folder ? self::apiJson(['folder' => self::apiFolderRow($folder, true)]) : self::apiError(404, 'not_found', 'Folder not found');
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveFolder($id, self::apiInput());
                return;
            }
            if ($method === 'DELETE') {
                DB::pdo()->prepare('DELETE FROM group_folders WHERE id=?')->execute([$id]);
                Audit::log('folder.delete', 'folder_id=' . $id);
                self::apiJson(['ok' => true]);
                return;
            }
        }
        if (count($parts) === 3 && in_array($parts[2], ['users', 'cameras'], true)) {
            self::apiFolderMembers($id, $parts[2]);
            return;
        }
        self::apiError(404, 'not_found', 'Unknown folders endpoint');
    }

    private static function apiFolderRow(?array $folder, bool $detailed = false): array
    {
        if (!$folder) {
            return [];
        }
        $row = [
            'id' => (int)$folder['id'],
            'groupId' => (int)$folder['group_id'],
            'groupName' => (string)($folder['group_name'] ?? ''),
            'name' => (string)$folder['name'],
            'description' => (string)($folder['description'] ?? ''),
            'blocked' => (int)($folder['blocked'] ?? 0) === 1,
            'createdAt' => $folder['created_at'] ?? null,
        ];
        if ($detailed) {
            $id = (int)$folder['id'];
            $row['userIds'] = self::linkedIds('user_folders', 'folder_id', $id, 'user_id');
            $row['cameraIds'] = self::linkedIds('camera_folders', 'folder_id', $id, 'camera_id');
        }
        return $row;
    }

    private static function apiSaveFolder(int $id, array $input): void
    {
        $current = $id > 0 ? Repo::folder($id) : null;
        if ($id > 0 && !$current) {
            self::apiError(404, 'not_found', 'Folder not found');
            return;
        }
        $groupId = (int)($input['groupId'] ?? $input['group_id'] ?? ($current['group_id'] ?? 0));
        if ($groupId <= 0 || !self::rowById('portal_groups', $groupId)) {
            self::apiError(422, 'validation_failed', 'groupId is required and must reference an existing group');
            return;
        }
        $name = trim((string)($input['name'] ?? ($current['name'] ?? '')));
        if ($name === '') {
            self::apiError(422, 'validation_failed', 'name is required');
            return;
        }
        $description = (string)($input['description'] ?? ($current['description'] ?? ''));
        $blocked = self::apiBlockedValue($input, $current);
        $pdo = DB::pdo();
        if ($id > 0) {
            $pdo->prepare('UPDATE group_folders SET name=?, description=?, blocked=? WHERE id=?')
                ->execute([$name, $description, $blocked, $id]);
        } else {
            $pdo->prepare('INSERT INTO group_folders(group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                ->execute([$groupId, $name, $description, $blocked, Util::now()]);
            $id = DB::lastInsertId('group_folders');
        }
        Audit::log('folder.save', 'folder_id=' . $id . ' group_id=' . $groupId . ' name=' . $name);
        self::apiJson(['folder' => self::apiFolderRow(Repo::folder($id), true)], $current ? 200 : 201);
    }

    private static function apiFolderMembers(int $folderId, string $resource): void
    {
        $folder = Repo::folder($folderId);
        if (!$folder) {
            self::apiError(404, 'not_found', 'Folder not found');
            return;
        }

        $method = self::apiMethod();
        $isUsers = $resource === 'users';
        $linkTable = $isUsers ? 'user_folders' : 'camera_folders';
        $targetKey = $isUsers ? 'user_id' : 'camera_id';
        $inputKey = $isUsers ? 'userIds' : 'cameraIds';
        $inputSnakeKey = $isUsers ? 'user_ids' : 'camera_ids';

        if ($method === 'GET') {
            self::apiJson(self::apiFolderMembersPayload($folder, $resource));
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
                self::addLinks($linkTable, 'folder_id', $folderId, $targetKey, $ids);
                Audit::log('folder.members.add', 'folder_id=' . $folderId . ' resource=' . $resource . ' count=' . count($ids));
            } elseif ($method === 'DELETE') {
                self::removeLinks($linkTable, 'folder_id', $folderId, $targetKey, $ids);
                Audit::log('folder.members.remove', 'folder_id=' . $folderId . ' resource=' . $resource . ' count=' . count($ids));
            } else {
                self::replaceLinks($linkTable, 'folder_id', $folderId, $targetKey, $ids);
                Audit::log('folder.members.replace', 'folder_id=' . $folderId . ' resource=' . $resource . ' count=' . count($ids));
            }

            self::apiJson(self::apiFolderMembersPayload(Repo::folder($folderId), $resource));
            return;
        }

        self::apiError(405, 'method_not_allowed', 'Method is not allowed');
    }

    private static function apiFolderMembersPayload(?array $folder, string $resource): array
    {
        $isUsers = $resource === 'users';
        $linkTable = $isUsers ? 'user_folders' : 'camera_folders';
        $targetTable = $isUsers ? 'users' : 'cameras';
        $targetKey = $isUsers ? 'user_id' : 'camera_id';
        $idsKey = $isUsers ? 'userIds' : 'cameraIds';
        $rowsKey = $isUsers ? 'users' : 'cameras';
        $ids = $folder ? self::linkedIds($linkTable, 'folder_id', (int)$folder['id'], $targetKey) : [];
        $memberRows = self::rowsByIds($targetTable, $ids);
        $rows = $isUsers
            ? array_map(static fn(array $row): array => self::apiUserRow($row, false, true), $memberRows)
            : self::apiCameraRows($memberRows);

        return [
            'folder' => self::apiFolderRow($folder, true),
            $idsKey => $ids,
            $rowsKey => $rows,
        ];
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
        $identifier = isset($parts[1]) ? trim((string)$parts[1]) : '';
        $camera = $identifier !== '' ? self::apiCameraByIdentifier($identifier) : null;
        $id = $camera ? (int)$camera['id'] : 0;
        $admin = ($user['role'] ?? '') === 'admin';

        if (count($parts) === 1) {
            if ($method === 'GET') {
                $filter = self::apiCameraListFilter();
                if ($admin && (string)($_GET['scope'] ?? 'all') !== 'accessible' && $filter === 'all') {
                    $list = self::filteredCameras(self::apiPageSize(25, 500));
                } else {
                    $list = Repo::accessibleCamerasPage($user, $filter, self::viewerSearchQuery(), (int)($_GET['page'] ?? 1), self::apiPageSize(25, 500));
                }
                self::apiJson(['cameras' => self::apiCameraRows($list['rows']), 'pagination' => self::apiPagination($list)]);
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
                DB::pdo()->prepare('DELETE FROM camera_folders WHERE camera_id=?')->execute([$id]);
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
        if (count($parts) === 3 && $parts[2] === 'permanent-token') {
            self::apiRequireAdmin();
            if ($method === 'POST') {
                self::apiJson(['token' => TokenService::issueCameraToken($id, $user)]);
                return;
            }
            if ($method === 'GET') {
                $cam = self::rowById('cameras', $id);
                $encoded = trim((string)($cam['permanent_token_enc'] ?? ''));
                self::apiJson(['token' => $encoded === '' ? null : Crypto::decrypt($encoded)]);
                return;
            }
            if ($method === 'DELETE') {
                TokenService::revokeCameraToken($id, $user);
                self::apiJson(['ok' => true]);
                return;
            }
        }
        self::apiError(404, 'not_found', 'Unknown cameras endpoint');
    }

    private static function apiCameraListFilter(): string
    {
        foreach (['folderIds', 'folderIDs', 'folder_ids'] as $key) {
            if (array_key_exists($key, $_GET)) {
                $folderIds = self::apiGroupIdsQueryValue($_GET[$key]);
                return 'folder:' . implode(',', $folderIds);
            }
        }

        $folderId = (int)($_GET['folderId'] ?? $_GET['folderID'] ?? $_GET['folder_id'] ?? 0);
        if ($folderId > 0) {
            return 'folder:' . $folderId;
        }

        foreach (['groupIds', 'groupIDs', 'group_ids'] as $key) {
            if (array_key_exists($key, $_GET)) {
                $groupIds = self::apiGroupIdsQueryValue($_GET[$key]);
                return 'group:' . implode(',', $groupIds);
            }
        }

        $groupId = (int)($_GET['groupId'] ?? $_GET['groupID'] ?? $_GET['group_id'] ?? 0);
        if ($groupId > 0) {
            return 'group:' . $groupId;
        }

        $filter = trim((string)($_GET['filter'] ?? 'all'));
        if ($filter === '') {
            return 'all';
        }
        if (preg_match('/^folder(?:id)?[:=](\d+)$/i', $filter, $matches) === 1) {
            return 'folder:' . (int)$matches[1];
        }
        if (preg_match('/^group(?:id)?[:=](\d+)$/i', $filter, $matches) === 1) {
            return 'group:' . (int)$matches[1];
        }
        if (ctype_digit($filter) && (int)$filter > 0) {
            return 'group:' . (int)$filter;
        }

        return $filter;
    }

    private static function apiGroupIdsQueryValue(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[,\s]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
        $ids = array_map('intval', $values ?: []);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        return array_values(array_unique($ids));
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
        } elseif (!$serverId) {
            $selection = 'auto';
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
            self::apiError(422, 'invalid_stream_name', I18n::t('cameras.invalidStreamName', 'Technical stream name must start with a Latin letter or digit and can contain only Latin letters, digits, dot, hyphen, and underscore, up to 128 characters.'), [
                'field' => 'dvrStreamName',
                'pattern' => Util::DVR_STREAM_NAME_HTML_PATTERN,
                'maxBytes' => Util::DVR_STREAM_NAME_MAX_BYTES,
            ]);
            return;
        }
        $existingCamera = self::cameraByName($name);
        if ($existingCamera && (int)$existingCamera['id'] !== $id) {
            self::apiCameraNameExists($existingCamera);
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
            array_key_exists('archiveEnabled', $input) || array_key_exists('archive_enabled', $input)
                ? (self::apiBool($input['archiveEnabled'] ?? $input['archive_enabled']) ? 1 : 0)
                : (int)($current['archive_enabled'] ?? 1),
            array_key_exists('webrtcFastStart', $input) || array_key_exists('webrtc_fast_start', $input)
                ? (self::apiBool($input['webrtcFastStart'] ?? $input['webrtc_fast_start']) ? 1 : 0)
                : (int)($current['webrtc_fast_start'] ?? 0),
            array_key_exists('eventArchiveRetentionEnabled', $input) || array_key_exists('event_archive_retention_enabled', $input)
                ? (self::apiBool($input['eventArchiveRetentionEnabled'] ?? $input['event_archive_retention_enabled']) ? 1 : 0)
                : (int)($current['event_archive_retention_enabled'] ?? 0),
            self::apiOptionalMegabytesAsBytes($input, ['eventArchiveMaxMb', 'event_archive_max_mb'], ['eventArchiveMaxBytes', 'event_archive_max_bytes'], $current, 'event_archive_max_bytes'),
            self::apiOptionalString($input, ['eventArchiveMaxDuration', 'event_archive_max_duration'], $current, 'event_archive_max_duration'),
            self::apiOptionalString($input, ['eventArchiveMaxAge', 'event_archive_max_age'], $current, 'event_archive_max_age'),
            array_key_exists('timelapseEnabled', $input) || array_key_exists('timelapse_enabled', $input)
                ? (self::apiBool($input['timelapseEnabled'] ?? $input['timelapse_enabled']) ? 1 : 0)
                : (int)($current['timelapse_enabled'] ?? 0),
            self::apiPositiveInt($input, ['timelapseFramesPerHour', 'timelapse_frames_per_hour'], $current, 'timelapse_frames_per_hour', 60),
            self::apiOptionalString($input, ['timelapseRetentionDays', 'timelapse_retention_days'], $current, 'timelapse_retention_days'),
            self::apiPositiveInt($input, ['timelapsePlaybackFps', 'timelapse_playback_fps'], $current, 'timelapse_playback_fps', 25),
            self::cameraTimelineRepairMode(self::apiOptionalString($input, ['directArchiveVideoTimelineRepairMode', 'direct_archive_video_timeline_repair_mode'], $current, 'direct_archive_video_timeline_repair_mode')),
            array_key_exists('audioCodec', $input) || array_key_exists('audio_codec', $input)
                ? self::cameraAudioCodec($input['audioCodec'] ?? $input['audio_codec'])
                : self::cameraAudioCodec($current['audio_codec'] ?? 'copy'),
            $controlMode,
            $agentId !== '' ? $agentId : null,
            $agentCameraId !== '' ? $agentCameraId : null,
            array_key_exists('onvifEventsRequested', $input) || array_key_exists('onvif_events_requested', $input)
                ? (self::apiBool($input['onvifEventsRequested'] ?? $input['onvif_events_requested']) ? 1 : 0)
                : (int)($current['onvif_events_requested'] ?? 0),
            trim((string)self::apiOptionalString($input, ['onvifHost', 'onvif_host'], $current, 'onvif_host')),
            max(1, min(65535, self::apiPositiveInt($input, ['onvifPort', 'onvif_port'], $current, 'onvif_port', 80))),
            trim((string)self::apiOptionalString($input, ['onvifUsername', 'onvif_username'], $current, 'onvif_username')),
            trim((string)self::apiOptionalString($input, ['onvifPassword', 'onvif_password'], $current, 'onvif_password')),
            array_key_exists('watermarkEnabled', $input) || array_key_exists('watermark_enabled', $input)
                ? (self::apiBool($input['watermarkEnabled'] ?? $input['watermark_enabled']) ? 1 : 0)
                : (int)($current['watermark_enabled'] ?? 0),
            array_key_exists('watermarkIntensity', $input) || array_key_exists('watermark_intensity', $input)
                ? self::watermarkIntensity($input['watermarkIntensity'] ?? $input['watermark_intensity'])
                : self::watermarkIntensity($current['watermark_intensity'] ?? 16),
            self::apiBlockedValue($input, $current),
            $stream,
        ];

        if (array_key_exists('folderIds', $input) || array_key_exists('folder_ids', $input)) {
            $folderIds = self::apiIntArray($input['folderIds'] ?? $input['folder_ids'] ?? []);
            self::apiValidateExistingIds('folderIds', 'group_folders', $folderIds);
        } else {
            $folderIds = null;
        }
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE cameras SET name=?, source_url=?, server_id=?, server_selection=?, latitude=?, longitude=?, direction_deg=?, view_angle_deg=?, retention_days=?, archive_enabled=?, webrtc_fast_start=?, event_archive_retention_enabled=?, event_archive_max_bytes=?, event_archive_max_duration=?, event_archive_max_age=?, timelapse_enabled=?, timelapse_frames_per_hour=?, timelapse_retention_days=?, timelapse_playback_fps=?, direct_archive_video_timeline_repair_mode=?, audio_codec=?, dvr_control_mode=?, agent_id=?, agent_camera_id=?, onvif_events_requested=?, onvif_host=?, onvif_port=?, onvif_username=?, onvif_password=?, watermark_enabled=?, watermark_intensity=?, blocked=?, dvr_stream_name=?, updated_at=? WHERE id=?')
                    ->execute([...$values, Util::now(), $id]);
            } else {
                $pdo->prepare('INSERT INTO cameras(name, source_url, server_id, server_selection, latitude, longitude, direction_deg, view_angle_deg, retention_days, archive_enabled, webrtc_fast_start, event_archive_retention_enabled, event_archive_max_bytes, event_archive_max_duration, event_archive_max_age, timelapse_enabled, timelapse_frames_per_hour, timelapse_retention_days, timelapse_playback_fps, direct_archive_video_timeline_repair_mode, audio_codec, dvr_control_mode, agent_id, agent_camera_id, onvif_events_requested, onvif_host, onvif_port, onvif_username, onvif_password, watermark_enabled, watermark_intensity, blocked, dvr_stream_name, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$values, Util::now(), Util::now()]);
                $id = DB::lastInsertId('cameras');
            }
            if ($folderIds !== null) {
                self::replaceLinks('camera_folders', 'camera_id', $id, 'folder_id', $folderIds);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof \PDOException && self::isCameraNameUniqueConstraint($error)) {
                self::apiCameraNameExists(self::cameraByName($name));
                return;
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

    private static function apiCameraNameExists(?array $existing): void
    {
        self::apiError(409, 'camera_name_exists', 'camera name already exists', [
            'existingId' => (int)($existing['id'] ?? 0),
        ]);
    }

    private static function isCameraNameUniqueConstraint(\PDOException $error): bool
    {
        $code = (string)$error->getCode();
        $message = strtolower($error->getMessage());
        return in_array($code, ['23000', '23505'], true)
            && (str_contains($message, 'cameras.name')
                || str_contains($message, 'cameras_name')
                || str_contains($message, 'for key \'name\'')
                || str_contains($message, 'for key "name"'));
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

    private static function apiUserRow(?array $user, bool $detailed = false, bool $includeAdminComment = false): array
    {
        if (!$user) {
            return [];
        }
        $row = [
            'id' => (int)$user['id'],
            'login' => (string)$user['login'],
            'role' => (string)$user['role'],
            'blocked' => (int)($user['blocked'] ?? 0) === 1,
            'hideArchive' => (int)($user['hide_archive'] ?? 0) === 1,
            'hasStaticToken' => !empty($user['static_token_hash']),
            'createdAt' => $user['created_at'] ?? null,
            'lastLoginAt' => $user['last_login_at'] ?? null,
        ];
        if ($includeAdminComment) {
            $row['adminComment'] = (string)($user['admin_comment'] ?? '');
        }
        if ($detailed) {
            $row['folderIds'] = self::linkedIds('user_folders', 'user_id', (int)$user['id'], 'folder_id');
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
            $folders = Repo::foldersForGroup($id);
            $row['folderIds'] = array_map('intval', array_column($folders, 'id'));
            $row['folders'] = array_map(fn($f) => self::apiFolderRow($f), $folders);
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

    private static function cameraByName(string $name): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM cameras WHERE name = ?');
        $stmt->execute([$name]);
        return $stmt->fetch() ?: null;
    }

    private static function apiCameraByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (preg_match('/^[1-9][0-9]*$/', $identifier) === 1) {
            $camera = self::apiCameraById((int)$identifier);
            if ($camera) {
                return $camera;
            }
        }

        $stmt = DB::pdo()->prepare(
            'SELECT c.*, s.name AS server_name, s.base_url AS server_url, s.last_metrics_json AS server_metrics_json
             FROM cameras c
             LEFT JOIN dvr_servers s ON s.id = c.server_id
             WHERE c.dvr_stream_name = ? OR c.name = ?
             ORDER BY CASE WHEN c.dvr_stream_name = ? THEN 0 ELSE 1 END, c.id
             LIMIT 1'
        );
        $stmt->execute([$identifier, $identifier, $identifier]);
        return $stmt->fetch() ?: null;
    }

    private static function apiCameraRows(array $cameras): array
    {
        $streamUnavailableByServer = self::mapStreamUnavailableByServer($cameras);
        return array_map(
            static fn(array $camera): array => self::apiCameraRow($camera, false, $streamUnavailableByServer),
            $cameras
        );
    }

    private static function apiCameraRow(?array $camera, bool $detailed = false, ?array $streamUnavailableByServer = null): array
    {
        if (!$camera) {
            return [];
        }
        $streamUnavailable = $streamUnavailableByServer === null
            ? self::cameraStreamUnavailable($camera)
            : self::cameraStreamUnavailableFromMapMetrics($camera, $streamUnavailableByServer);
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
            'archiveEnabled' => (int)($camera['archive_enabled'] ?? 1) === 1,
            'webrtcFastStart' => (int)($camera['webrtc_fast_start'] ?? 0) === 1,
            'eventArchiveRetentionEnabled' => (int)($camera['event_archive_retention_enabled'] ?? 0) === 1,
            'eventArchiveMaxBytes' => ($camera['event_archive_max_bytes'] ?? null) !== null ? (int)$camera['event_archive_max_bytes'] : null,
            'eventArchiveMaxDuration' => $camera['event_archive_max_duration'] ?? null,
            'eventArchiveMaxAge' => $camera['event_archive_max_age'] ?? null,
            'timelapseEnabled' => (int)($camera['timelapse_enabled'] ?? 0) === 1,
            'timelapseFramesPerHour' => self::cameraPositiveInt($camera['timelapse_frames_per_hour'] ?? 60, 60),
            'timelapseRetentionDays' => $camera['timelapse_retention_days'] ?? null,
            'timelapsePlaybackFps' => self::cameraPositiveInt($camera['timelapse_playback_fps'] ?? 25, 25),
            'directArchiveVideoTimelineRepairMode' => self::cameraTimelineRepairMode($camera['direct_archive_video_timeline_repair_mode'] ?? null),
            'audioCodec' => self::cameraAudioCodec($camera['audio_codec'] ?? 'copy'),
            'dvrControlMode' => (string)($camera['dvr_control_mode'] ?? 'managed'),
            'agentId' => $camera['agent_id'] ?? null,
            'agentCameraId' => $camera['agent_camera_id'] ?? null,
            'onvifEventsRequested' => (int)($camera['onvif_events_requested'] ?? 0) === 1,
            'watermarkEnabled' => (int)($camera['watermark_enabled'] ?? 0) === 1,
            'watermarkIntensity' => self::watermarkIntensity($camera['watermark_intensity'] ?? 16),
            'blocked' => (int)($camera['blocked'] ?? 0) === 1,
            'dvrStreamName' => (string)($camera['dvr_stream_name'] ?? ''),
            'streamUnavailable' => $streamUnavailable,
            'lastSyncAt' => $camera['last_sync_at'] ?? null,
            'lastSyncOk' => $camera['last_sync_ok'] === null ? null : (int)$camera['last_sync_ok'] === 1,
            'lastSyncMessage' => $camera['last_sync_message'] ?? null,
            'createdAt' => $camera['created_at'] ?? null,
            'updatedAt' => $camera['updated_at'] ?? null,
        ];
        if ($detailed) {
            $row['folderIds'] = self::linkedIds('camera_folders', 'camera_id', (int)$camera['id'], 'folder_id');
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
}
