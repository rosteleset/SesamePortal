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

    public static function directUserGroupIds(int $userId): array
    {
        $stmt = DB::pdo()->prepare('SELECT group_id FROM user_groups WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'group_id'));
    }

    public static function userAccessibleGroupIds(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return array_map('intval', array_column(self::all('portal_groups', 'name ASC'), 'id'));
        }
        return self::groupBranchIds(self::directUserGroupIds((int)$user['id']), true, false);
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

    public static function accessibleCamerasByIds(array $user, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }
        [$join, $where, $params] = self::accessibleCameraScope($user, 'all');
        $where[] = 'c.id IN (' . self::placeholders($ids) . ')';
        $where[] = 's.blocked = 0';
        $stmt = DB::pdo()->prepare('SELECT DISTINCT c.*, s.name AS server_name, s.base_url AS server_url FROM cameras c ' . $join . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute([...$params, ...$ids]);
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

    private static function accessibleCameraScope(array $user, string $filter, string $query = ''): array
    {
        $joinParams = [];
        $whereParams = [];
        $where = ['c.blocked = 0'];
        $join = 'LEFT JOIN dvr_servers s ON s.id = c.server_id';

        if ($user['role'] !== 'admin') {
            $accessGroupIds = self::userAccessibleGroupIds($user);
            if (!$accessGroupIds) {
                $where[] = '1 = 0';
            } else {
                $join .= ' JOIN camera_groups cg_access ON cg_access.camera_id = c.id';
                $where[] = 'cg_access.group_id IN (' . self::placeholders($accessGroupIds) . ')';
                array_push($whereParams, ...$accessGroupIds);
            }
        }

        if ($filter === 'favorites') {
            $join .= ' JOIN favorites f ON f.camera_id = c.id AND f.user_id = ?';
            $joinParams[] = (int)$user['id'];
        } elseif (str_starts_with($filter, 'group:')) {
            $groupIds = self::groupFilterRootIds($filter);
            $filterGroupIds = self::groupBranchIds($groupIds, true, $user['role'] === 'admin');
            $join .= ' JOIN camera_groups cg_filter ON cg_filter.camera_id = c.id';
            if (!$filterGroupIds) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'cg_filter.group_id IN (' . self::placeholders($filterGroupIds) . ')';
                array_push($whereParams, ...$filterGroupIds);
            }
            if ($user['role'] !== 'admin') {
                $allowedGroupIds = self::userAccessibleGroupIds($user);
                if (!$allowedGroupIds || !array_intersect($filterGroupIds, $allowedGroupIds)) {
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

    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    public static function groupsForUser(array $user): array
    {
        if ($user['role'] === 'admin') {
            return self::all('portal_groups', 'name ASC');
        }

        $ids = self::userAccessibleGroupIds($user);
        if (!$ids) {
            return [];
        }
        $stmt = DB::pdo()->prepare('SELECT * FROM portal_groups WHERE id IN (' . self::placeholders($ids) . ') ORDER BY name ASC');
        $stmt->execute($ids);
        return $stmt->fetchAll();
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

        $groupIds = self::userAccessibleGroupIds($user);
        if (!$groupIds) {
            return false;
        }
        $stmt = DB::pdo()->prepare(
            'SELECT c.id FROM cameras c
             JOIN camera_groups cg ON cg.camera_id = c.id
             WHERE c.id = ? AND c.blocked = 0 AND cg.group_id IN (' . self::placeholders($groupIds) . ')
             LIMIT 1'
        );
        $stmt->execute([$cameraId, ...$groupIds]);
        return (bool)$stmt->fetch();
    }
}
