<?php

declare(strict_types=1);

namespace SesamePortal;

trait AppDataTrait
{
    private static function rowById(string $table, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = DB::pdo()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function userByLogin(string $login): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE login = ?');
        $stmt->execute([$login]);
        return $stmt->fetch() ?: null;
    }

    private static function rowsByIds(string $table, array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = $table === 'cameras' ? self::apiCameraById($id) : self::rowById($table, $id);
            if ($row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function missingIds(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = DB::pdo()->prepare('SELECT id FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $existing = array_fill_keys(array_map('intval', array_column($stmt->fetchAll(), 'id')), true);
        return array_values(array_filter($ids, static fn(int $id): bool => !isset($existing[$id])));
    }

    private static function emailTakenByOther(string $email, int $id): bool
    {
        $stmt = DB::pdo()->prepare('SELECT id FROM users WHERE email = ? AND id != ? AND blocked = 0');
        $stmt->execute([$email, $id]);
        return $stmt->fetch() !== false;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private static function explicitGroupIdFromInput(array $input): array
    {
        if (!array_key_exists('id', $input)) {
            return [null, ''];
        }

        $value = $input['id'];
        if (is_int($value)) {
            return $value > 0 ? [$value, ''] : [null, 'id must be a positive integer'];
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '' && ctype_digit($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (is_int($parsed)) {
                    return [$parsed, ''];
                }
            }
        }

        return [null, 'id must be a positive integer'];
    }

    private static function syncPortalGroupIdentityAfterExplicitInsert(): void
    {
        DB::syncIdentity('portal_groups');
    }

    private static function groupName(int $id): ?string
    {
        $group = self::rowById('portal_groups', $id);
        return $group ? (string)$group['name'] : null;
    }

    private static function groupChildren(int $groupId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM portal_groups WHERE parent_group_id = ? ORDER BY name ASC');
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    /**
     * Извлекает parent_group_id из входных данных.
     * Родительская группа определяет положение группы в дереве:
     *   null = корневая, int = ID родительской группы.
     */
    private static function groupParentIdFromInput(array $input, ?array $current): ?int
    {
        if (array_key_exists('parentGroupId', $input)) {
            return self::nullableInt($input['parentGroupId']);
        }
        if (array_key_exists('parent_group_id', $input)) {
            return self::nullableInt($input['parent_group_id']);
        }
        return self::nullableInt($current['parent_group_id'] ?? null);
    }

    /**
     * Валидация выбора родительской группы: запрещает циклы (группа не может быть
     * собственным предком) и несуществующие ID. Родитель определяет визуальный
     * путь в дереве («Родитель / Группа»), но не влияет на права доступа —
     * каждая папка выдаётся явно через user_folders.
     */
    private static function groupParentValidationError(int $groupId, ?int $parentId): string
    {
        if ($parentId === null) {
            return '';
        }
        if (!self::rowById('portal_groups', $parentId)) {
            return self::t('groups.parentNotFound', 'Родительская группа не найдена');
        }
        if ($groupId > 0 && in_array($parentId, Repo::groupBranchIds([$groupId], true, true), true)) {
            return self::t('groups.parentCycle', 'Родительской группой нельзя выбрать саму группу или её подгруппу');
        }
        return '';
    }

    private static function groupRowsWithDisplayLabels(array $groups, ?array $contextGroups = null): array
    {
        $contextGroups ??= $groups;
        $labels = self::groupPathLabels($contextGroups);
        $byId = [];
        foreach ($contextGroups as $group) {
            $byId[(int)$group['id']] = $group;
        }

        foreach ($groups as &$group) {
            $id = (int)$group['id'];
            $parentId = (int)($group['parent_group_id'] ?? 0);
            $group['display_name'] = $labels[$id] ?? (string)$group['name'];
            $group['parent_group_name'] = $parentId > 0 && isset($byId[$parentId])
                ? ($labels[$parentId] ?? (string)$byId[$parentId]['name'])
                : self::t('groups.noParent', 'Без родителя');
        }
        unset($group);

        usort($groups, static fn(array $left, array $right): int => strnatcasecmp((string)$left['display_name'], (string)$right['display_name']));
        return $groups;
    }

    private static function groupRowsWithTreeLabels(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            $byId[(int)$group['id']] = $group;
        }

        $children = [0 => []];
        foreach ($byId as $id => $group) {
            $parentId = (int)($group['parent_group_id'] ?? 0);
            $children[($parentId > 0 && isset($byId[$parentId])) ? $parentId : 0][] = $id;
        }

        foreach ($children as &$ids) {
            usort($ids, static function (int $left, int $right) use ($byId): int {
                return strnatcasecmp((string)$byId[$left]['name'], (string)$byId[$right]['name']);
            });
        }
        unset($ids);

        $rows = [];
        $seen = [];
        $append = function (int $id, int $depth) use (&$append, &$rows, &$seen, $children, $byId): void {
            if (isset($seen[$id]) || !isset($byId[$id])) {
                return;
            }
            $seen[$id] = true;
            $row = $byId[$id];
            $parentId = (int)($row['parent_group_id'] ?? 0);
            $row['display_name'] = str_repeat('  ', $depth) . ($depth > 0 ? '↳ ' : '') . (string)$row['name'];
            $row['parent_group_name'] = $parentId > 0 && isset($byId[$parentId])
                ? (string)$byId[$parentId]['name']
                : self::t('groups.noParent', 'Без родителя');
            $row['tree_depth'] = $depth;
            $rows[] = $row;

            foreach ($children[$id] ?? [] as $childId) {
                $append((int)$childId, $depth + 1);
            }
        };

        foreach ($children[0] ?? [] as $rootId) {
            $append((int)$rootId, 0);
        }
        foreach (array_keys($byId) as $id) {
            $append((int)$id, 0);
        }

        return $rows;
    }

    /**
     * Список доступных родительских групп для пикера. Исключает саму редактируемую
     * группу и всех её потомков (защита от циклов). Каждая опция показывает путь
     * от корня: «Родитель / Группа».
     */
    private static function groupParentOptions(array $groups, int $editedGroupId): array
    {
        $excluded = $editedGroupId > 0 ? array_flip(Repo::groupBranchIds([$editedGroupId], true, true)) : [];
        $options = [];
        foreach (self::groupRowsWithDisplayLabels($groups) as $group) {
            if (!isset($excluded[(int)$group['id']])) {
                $options[] = $group;
            }
        }
        return $options;
    }

    private static function groupPathLabels(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            $byId[(int)$group['id']] = $group;
        }

        $labels = [];
        $build = static function (int $id, array $stack = []) use (&$build, &$labels, $byId): string {
            if (isset($labels[$id])) {
                return $labels[$id];
            }
            if (!isset($byId[$id])) {
                return '';
            }
            if (isset($stack[$id])) {
                $labels[$id] = (string)$byId[$id]['name'];
                return $labels[$id];
            }

            $parentId = (int)($byId[$id]['parent_group_id'] ?? 0);
            $name = (string)$byId[$id]['name'];
            if ($parentId > 0 && isset($byId[$parentId])) {
                $parentLabel = $build($parentId, $stack + [$id => true]);
                $labels[$id] = $parentLabel !== '' ? $parentLabel . ' / ' . $name : $name;
            } else {
                $labels[$id] = $name;
            }
            return $labels[$id];
        };

        foreach (array_keys($byId) as $id) {
            $build((int)$id);
        }
        return $labels;
    }

    private static function folderRowsWithGroupLabels(array $folders): array
    {
        $groups = Repo::all('portal_groups', 'name ASC');
        $groupLabels = self::groupPathLabels($groups);
        $byId = [];
        foreach ($groups as $group) {
            $byId[(int)$group['id']] = $group;
        }

        foreach ($folders as &$folder) {
            $groupId = (int)($folder['group_id'] ?? 0);
            $folder['group_label'] = $groupLabels[$groupId] ?? (string)($byId[$groupId]['name'] ?? '');
            $folder['display_name'] = ($folder['group_label'] !== '' ? $folder['group_label'] . ' / ' : '') . (string)$folder['name'];
        }
        unset($folder);

        usort($folders, static fn(array $left, array $right): int => strnatcasecmp((string)$left['display_name'], (string)$right['display_name']));
        return $folders;
    }

    private static function folderTreeStructure(array $folders, array $groups): array
    {
        $groupById = [];
        foreach ($groups as $group) {
            $groupById[(int)$group['id']] = $group;
        }

        $children = [0 => []];
        foreach ($groupById as $id => $group) {
            $parentId = (int)($group['parent_group_id'] ?? 0);
            $children[($parentId > 0 && isset($groupById[$parentId])) ? $parentId : 0][] = $id;
        }
        foreach ($children as &$ids) {
            usort($ids, static function (int $left, int $right) use ($groupById): int {
                return strnatcasecmp((string)$groupById[$left]['name'], (string)$groupById[$right]['name']);
            });
        }
        unset($ids);

        $foldersByGroup = [];
        foreach ($folders as $folder) {
            $foldersByGroup[(int)$folder['group_id']][] = $folder;
        }
        foreach ($foldersByGroup as &$list) {
            usort($list, static fn(array $left, array $right): int => strnatcasecmp((string)$left['name'], (string)$right['name']));
        }
        unset($list);

        return [$groupById, $children, $foldersByGroup];
    }

    private static function replaceLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        $pdo = DB::pdo();
        $pdo->prepare("DELETE FROM {$table} WHERE {$ownerKey} = ?")->execute([$ownerId]);
        $stmt = $pdo->prepare(DB::insertIgnoreSql($table, [$ownerKey, $targetKey]));
        foreach ($values as $value) {
            $stmt->execute([$ownerId, (int)$value]);
        }
    }

    private static function addLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        $stmt = DB::pdo()->prepare(DB::insertIgnoreSql($table, [$ownerKey, $targetKey]));
        foreach ($values as $value) {
            $stmt->execute([$ownerId, (int)$value]);
        }
    }

    private static function removeLinks(string $table, string $ownerKey, int $ownerId, string $targetKey, array $values): void
    {
        if ($values === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $stmt = DB::pdo()->prepare("DELETE FROM {$table} WHERE {$ownerKey} = ? AND {$targetKey} IN ({$placeholders})");
        $stmt->execute([$ownerId, ...array_map('intval', $values)]);
    }

    private static function linkedIds(string $table, string $ownerKey, int $ownerId, string $targetKey): array
    {
        $stmt = DB::pdo()->prepare("SELECT {$targetKey} FROM {$table} WHERE {$ownerKey} = ?");
        $stmt->execute([$ownerId]);
        return array_map('intval', array_column($stmt->fetchAll(), $targetKey));
    }

    private static function randomActiveServerId(): ?int
    {
        $servers = DB::pdo()->query('SELECT id FROM dvr_servers WHERE blocked = 0 ORDER BY ' . DB::randomOrderSql() . ' LIMIT 1')->fetchAll();
        return $servers ? (int)$servers[0]['id'] : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        $value = trim((string)$value);
        return $value === '' ? null : (float)$value;
    }
}
