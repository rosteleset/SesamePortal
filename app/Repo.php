<?php

declare(strict_types=1);

namespace SesamePortal;

use PDO;
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

    public static function groupBranchIds(array $rootIds, bool $includeRoots = true, bool $includeBlocked = true): array
    {
        $groups = self::all('portal_groups', 'name ASC');
        $byId = [];
        $children = [];
        foreach ($groups as $group) {
            $id = (int)$group['id'];
            $byId[$id] = $group;
            $parentId = (int)($group['parent_group_id'] ?? 0);
            if ($parentId > 0) {
                $children[$parentId][] = $id;
            }
        }

        $result = [];
        $seen = [];
        $queue = [];
        foreach ($rootIds as $rootId) {
            $rootId = (int)$rootId;
            if ($rootId <= 0 || !isset($byId[$rootId])) {
                continue;
            }
            if (!$includeBlocked && (int)($byId[$rootId]['blocked'] ?? 0) === 1) {
                continue;
            }
            if ($includeRoots) {
                $result[] = $rootId;
                $seen[$rootId] = true;
            }
            $queue[] = $rootId;
        }

        while ($queue) {
            $parentId = array_shift($queue);
            foreach ($children[$parentId] ?? [] as $childId) {
                if (isset($seen[$childId])) {
                    continue;
                }
                if (!$includeBlocked && (int)($byId[$childId]['blocked'] ?? 0) === 1) {
                    continue;
                }
                $seen[$childId] = true;
                $result[] = $childId;
                $queue[] = $childId;
            }
        }

        return array_values(array_unique($result));
    }

    public static function directUserFolderIds(int $userId): array
    {
        $stmt = DB::pdo()->prepare('SELECT folder_id FROM user_folders WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'folder_id'));
    }

    public static function userAccessibleFolderIds(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return array_map('intval', array_column(self::all('group_folders', 'id ASC'), 'id'));
        }

        $folderIds = self::directUserFolderIds((int)$user['id']);
        if (!$folderIds) {
            return [];
        }

        // Отсечь заблокированные папки и папки заблокированных групп.
        $stmt = DB::pdo()->prepare(
            'SELECT gf.id FROM group_folders gf
             JOIN portal_groups g ON g.id = gf.group_id
             WHERE gf.blocked = 0 AND g.blocked = 0 AND gf.id IN (' . self::placeholders($folderIds) . ')'
        );
        $stmt->execute($folderIds);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    public static function accessibleCameras(array $user, string $filter = 'all', string $query = ''): array
    {
        [$join, $where, $params] = self::accessibleCameraScope($user, $filter, $query);
        $sql = 'SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url
                FROM cameras c ' . $join . '
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.name ASC';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function accessibleMapCameras(array $user, string $filter = 'all', string $query = ''): array
    {
        [$join, $where, $params] = self::accessibleCameraScope($user, $filter, $query);
        $where[] = 'c.latitude IS NOT NULL';
        $where[] = 'c.longitude IS NOT NULL';

        $sql = 'SELECT DISTINCT
                    c.id,
                    c.name,
                    c.dvr_stream_name,
                    c.latitude,
                    c.longitude,
                    c.direction_deg,
                    c.view_angle_deg,
                    c.server_id,
                    s.name AS server_name,
                    s.base_url AS server_url
                FROM cameras c ' . $join . '
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.name ASC';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function accessibleCamerasPage(array $user, string $filter, string $query, int $page, int $pageSize): array
    {
        [$join, $where, $params] = self::accessibleCameraScope($user, $filter, $query);
        $pdo = DB::pdo();
        $pageSize = max(1, $pageSize);
        $count = $pdo->prepare('SELECT COUNT(DISTINCT c.id) FROM cameras c ' . $join . ' WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $pages);

        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url
             FROM cameras c ' . $join . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.name ASC
             LIMIT ? OFFSET ?'
        );
        $bind = [...$params, $pageSize, ($page - 1) * $pageSize];
        foreach ($bind as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'filter' => $filter,
            'q' => $query,
        ];
    }

    public static function accessibleCamerasByIds(array $user, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }

        [$join, $where, $params] = self::accessibleCameraScope($user, 'all', '');
        $where[] = 'c.id IN (' . self::placeholders($ids) . ')';

        $sql = 'SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url
                FROM cameras c ' . $join . '
                WHERE ' . implode(' AND ', $where);
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute([...$params, ...$ids]);
        return $stmt->fetchAll();
    }

    private static function accessibleCameraScope(array $user, string $filter, string $query = ''): array
    {
        $joinParams = [];
        $whereParams = [];
        $where = ['c.blocked = 0'];
        $join = 'LEFT JOIN dvr_servers s ON s.id = c.server_id';

        if ($user['role'] !== 'admin') {
            $accessFolderIds = self::userAccessibleFolderIds($user);
            if (!$accessFolderIds) {
                $where[] = '1 = 0';
            } else {
                $join .= ' JOIN camera_folders cf_access ON cf_access.camera_id = c.id';
                $where[] = 'cf_access.folder_id IN (' . self::placeholders($accessFolderIds) . ')';
                array_push($whereParams, ...$accessFolderIds);
            }
        }

        if ($filter === 'favorites') {
            $join .= ' JOIN favorites f ON f.camera_id = c.id AND f.user_id = ?';
            $joinParams[] = (int)$user['id'];
        } elseif (str_starts_with($filter, 'folder:')) {
            $folderIds = self::folderFilterIds($filter);
            $join .= ' JOIN camera_folders cf_filter ON cf_filter.camera_id = c.id';
            if (!$folderIds) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'cf_filter.folder_id IN (' . self::placeholders($folderIds) . ')';
                array_push($whereParams, ...$folderIds);
            }
            if ($user['role'] !== 'admin') {
                $allowedFolderIds = self::userAccessibleFolderIds($user);
                if (!$allowedFolderIds || !array_intersect($folderIds, $allowedFolderIds)) {
                    $where[] = '1 = 0';
                }
            }
        } elseif (str_starts_with($filter, 'group:')) {
            // Legacy group filter: expand to all folders of the group branch.
            $groupIds = self::groupFilterRootIds($filter);
            $filterGroupIds = self::groupBranchIds($groupIds, true, $user['role'] === 'admin');
            $folderIds = self::folderIdsForGroups($filterGroupIds);
            $join .= ' JOIN camera_folders cf_filter ON cf_filter.camera_id = c.id';
            if (!$folderIds) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'cf_filter.folder_id IN (' . self::placeholders($folderIds) . ')';
                array_push($whereParams, ...$folderIds);
            }
            if ($user['role'] !== 'admin') {
                $allowedFolderIds = self::userAccessibleFolderIds($user);
                if (!$allowedFolderIds || !array_intersect($folderIds, $allowedFolderIds)) {
                    $where[] = '1 = 0';
                }
            }
        }

        $query = trim($query);
        if ($query !== '') {
            $searchColumns = ['c.name', 'c.dvr_stream_name', 'c.source_url'];
            $where[] = '(' . implode(' OR ', array_map([DB::class, 'caseInsensitiveLike'], $searchColumns)) . ')';
            $needle = '%' . $query . '%';
            array_push($whereParams, ...array_fill(0, count($searchColumns), $needle));
        }

        return [$join, $where, [...$joinParams, ...$whereParams]];
    }

    private static function groupFilterRootIds(string $filter): array
    {
        $raw = substr($filter, 6);
        $ids = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    private static function folderFilterIds(string $filter): array
    {
        $raw = substr($filter, 7);
        $ids = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    public static function folderIdsForGroups(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
        if (!$groupIds) {
            return [];
        }
        $stmt = DB::pdo()->prepare('SELECT id FROM group_folders WHERE group_id IN (' . self::placeholders($groupIds) . ')');
        $stmt->execute($groupIds);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    public static function foldersForUser(array $user): array
    {
        if ($user['role'] === 'admin') {
            $stmt = DB::pdo()->query(
                'SELECT gf.*, g.name AS group_name, g.parent_group_id AS group_parent_id
                 FROM group_folders gf
                 JOIN portal_groups g ON g.id = gf.group_id
                 ORDER BY g.name ASC, gf.name ASC'
            );
            return $stmt ? $stmt->fetchAll() : [];
        }

        $folderIds = self::userAccessibleFolderIds($user);
        if (!$folderIds) {
            return [];
        }
        $stmt = DB::pdo()->prepare(
            'SELECT gf.*, g.name AS group_name, g.parent_group_id AS group_parent_id
             FROM group_folders gf
             JOIN portal_groups g ON g.id = gf.group_id
             WHERE gf.id IN (' . self::placeholders($folderIds) . ')
             ORDER BY g.name ASC, gf.name ASC'
        );
        $stmt->execute($folderIds);
        return $stmt->fetchAll();
    }

    public static function allFolders(): array
    {
        return DB::pdo()->query(
            'SELECT gf.*, g.name AS group_name, g.parent_group_id AS group_parent_id
             FROM group_folders gf
             JOIN portal_groups g ON g.id = gf.group_id
             ORDER BY g.name ASC, gf.name ASC'
        )->fetchAll();
    }

    public static function foldersForGroup(int $groupId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM group_folders WHERE group_id = ? ORDER BY name ASC');
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    public static function folder(int $id): ?array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT gf.*, g.name AS group_name FROM group_folders gf
             JOIN portal_groups g ON g.id = gf.group_id WHERE gf.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function camerasInFolder(int $folderId): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT c.id, c.name, c.dvr_stream_name, c.blocked, c.source_url, c.dvr_control_mode, c.last_sync_message, c.last_sync_at
             FROM cameras c
             JOIN camera_folders cf ON cf.camera_id = c.id
             WHERE cf.folder_id = ?
             ORDER BY c.name ASC'
        );
        $stmt->execute([$folderId]);
        return $stmt->fetchAll();
    }

    public static function camerasNotInFolder(int $folderId): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT c.id, c.name, c.dvr_stream_name, c.blocked
             FROM cameras c
             WHERE c.id NOT IN (SELECT camera_id FROM camera_folders WHERE folder_id = ?)
             ORDER BY c.name ASC'
        );
        $stmt->execute([$folderId]);
        return $stmt->fetchAll();
    }

    public static function usersForGroup(int $groupId): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT DISTINCT u.id, u.login, u.role, u.blocked, u.email
             FROM users u
             JOIN user_folders uf ON uf.user_id = u.id
             JOIN group_folders gf ON gf.id = uf.folder_id
             WHERE gf.group_id = ?
             ORDER BY u.login ASC'
        );
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    public static function folderNamesForUserInGroup(int $userId, int $groupId): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT gf.name
             FROM group_folders gf
             JOIN user_folders uf ON uf.folder_id = gf.id
             WHERE gf.group_id = ? AND uf.user_id = ?
             ORDER BY gf.name ASC'
        );
        $stmt->execute([$groupId, $userId]);
        return array_column($stmt->fetchAll(), 'name');
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

    public static function serverMetricsJsonByIds(array $serverIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $serverIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }

        $stmt = DB::pdo()->prepare(
            'SELECT id, last_metrics_json FROM dvr_servers WHERE id IN (' . self::placeholders($ids) . ')'
        );
        $stmt->execute($ids);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int)$row['id']] = (string)($row['last_metrics_json'] ?? '');
        }
        return $result;
    }

    public static function cameraAllowedForUser(array $user, int $cameraId): bool
    {
        if ($user['role'] === 'admin') {
            $stmt = DB::pdo()->prepare('SELECT id FROM cameras WHERE id = ? AND blocked = 0');
            $stmt->execute([$cameraId]);
            return (bool)$stmt->fetch();
        }

        $folderIds = self::userAccessibleFolderIds($user);
        if (!$folderIds) {
            return false;
        }
        $stmt = DB::pdo()->prepare(
            'SELECT c.id FROM cameras c
             JOIN camera_folders cf ON cf.camera_id = c.id
             WHERE c.id = ? AND c.blocked = 0 AND cf.folder_id IN (' . self::placeholders($folderIds) . ')
             LIMIT 1'
        );
        $stmt->execute([$cameraId, ...$folderIds]);
        return (bool)$stmt->fetch();
    }

    public static function mosaicsForUser(array $user): array
    {
        if ($user['role'] === 'admin') {
            $stmt = DB::pdo()->prepare(
                'SELECT m.*, u.login AS owner_login
                 FROM cameras_mosaic m
                 JOIN users u ON u.id = m.user_id
                 ORDER BY m.created_at DESC'
            );
        } else {
            $stmt = DB::pdo()->prepare(
                'SELECT m.*, u.login AS owner_login
                 FROM cameras_mosaic m
                 JOIN users u ON u.id = m.user_id
                 WHERE m.user_id = ?
                 ORDER BY m.created_at DESC'
            );
            $stmt->execute([(int)$user['id']]);
            return $stmt->fetchAll();
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function mosaicById(int $id): ?array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT m.*, u.login AS owner_login
             FROM cameras_mosaic m
             JOIN users u ON u.id = m.user_id
             WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function mosaicAllowedForUser(array $user, int $mosaicId): bool
    {
        $mosaic = self::mosaicById($mosaicId);
        if (!$mosaic) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        return (int)$mosaic['user_id'] === (int)$user['id'];
    }

    public static function mosaicCameraIds(array $mosaic): array
    {
        $ids = json_decode((string)($mosaic['cameras_json'] ?? '[]'), true);
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    public static function camerasByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }
        $stmt = DB::pdo()->prepare(
            'SELECT c.*, s.name AS server_name, s.base_url AS server_url
             FROM cameras c
             LEFT JOIN dvr_servers s ON s.id = c.server_id
             WHERE c.id IN (' . self::placeholders($ids) . ') AND c.blocked = 0'
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }
}
