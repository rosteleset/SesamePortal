<?php

declare(strict_types=1);

namespace SesamePortal;

trait GroupsPages
{
    private static function groups(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $name = trim((string)Util::post('name'));
                $current = $id > 0 ? self::rowById('portal_groups', $id) : null;
                $parentId = self::groupParentIdFromInput(['parent_group_id' => Util::post('parent_group_id')], $current);
                $parentError = self::groupParentValidationError($id, $parentId);
                if ($name === '') {
                    $message = self::t('groups.nameRequired', 'Название группы обязательно');
                } elseif ($parentError !== '') {
                    $message = $parentError;
                } elseif ($id > 0) {
                    $pdo->prepare('UPDATE portal_groups SET parent_group_id=?, name=?, description=?, blocked=? WHERE id=?')
                        ->execute([$parentId, $name, Util::post('description'), Util::checkbox('blocked'), $id]);
                } else {
                    $pdo->prepare('INSERT INTO portal_groups(parent_group_id, name, description, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                        ->execute([$parentId, $name, Util::post('description'), Util::checkbox('blocked'), Util::now()]);
                    $id = DB::lastInsertId('portal_groups');
                }
                if ($message === '') {
                    self::replaceLinks('user_groups', 'group_id', $id, 'user_id', $_POST['user_ids'] ?? []);
                    self::replaceLinks('camera_groups', 'group_id', $id, 'camera_id', $_POST['camera_ids'] ?? []);
                    Audit::log('group.save', $name . ' parent_group_id=' . ($parentId ?? 'none'));
                }
            } elseif ($action === 'delete' && $id > 0) {
                $group = self::rowById('portal_groups', $id);
                if (!$group) {
                    $message = self::t('groups.deleteMissing', 'Группа уже удалена или не найдена');
                } elseif (Util::checkbox('confirm_delete') !== 1) {
                    $message = self::t('groups.deleteConfirmRequired', 'Подтвердите удаление группы');
                } else {
                    $pdo->prepare('UPDATE portal_groups SET parent_group_id = NULL WHERE parent_group_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM portal_groups WHERE id=?')->execute([$id]);
                    $message = self::t('groups.deleteDone', 'Группа удалена');
                    Audit::log('group.delete', 'group_id=' . $id . ' name=' . (string)$group['name']);
                }
            }
        }

        $edit = self::rowById('portal_groups', (int)($_GET['edit'] ?? 0));
        $delete = self::groupDeleteCandidate((int)($_GET['delete'] ?? 0));
        $linkedUsers = $edit ? self::linkedIds('user_groups', 'group_id', (int)$edit['id'], 'user_id') : [];
        $linkedCameras = $edit ? self::linkedIds('camera_groups', 'group_id', (int)$edit['id'], 'camera_id') : [];
        $users = Repo::all('users', 'login ASC');
        $cameras = Repo::all('cameras', 'name ASC');
        $allGroups = Repo::all('portal_groups', 'name ASC');
        $list = self::filteredRows('portal_groups', ['name', 'description'], 'name ASC');
        $groups = self::groupRowsWithDisplayLabels($list['rows'], $allGroups);
        $parentGroups = self::groupParentOptions($allGroups, $edit ? (int)$edit['id'] : 0);
        self::layout(self::t('groups.title', 'Группы'), function () use ($edit, $delete, $users, $cameras, $linkedUsers, $linkedCameras, $groups, $parentGroups, $message, $list) {
            self::notice($message);
            if ($delete) {
                self::groupDeletePanel($delete);
            }
            echo '<div class="admin-grid group-admin-grid"><section class="panel"><h2>' . ($edit ? self::t('groups.edit', 'Изменить группу') : self::t('groups.new', 'Новая группа')) . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('column.name', 'Название') . '<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            $currentParent = (int)($edit['parent_group_id'] ?? 0);
            self::groupParentTreePicker(self::t('groups.parent', 'Родительская группа'), $parentGroups, $currentParent > 0 ? $currentParent : null);
            echo '<label>' . self::t('column.description', 'Описание') . '<textarea name="description">' . Util::h($edit['description'] ?? '') . '</textarea></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('column.blocked', 'Заблокирована') . '</label>';
            self::assignmentPicker(self::t('groups.users', 'Пользователи'), 'user_ids[]', $users, $linkedUsers, 'login', self::t('assignment.searchUsers', 'Найти пользователя'));
            self::assignmentPicker(self::t('groups.cameras', 'Камеры'), 'camera_ids[]', $cameras, $linkedCameras, 'name', self::t('assignment.searchCameras', 'Найти камеру'));
            echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
            self::table(self::t('groups.title', 'Группы'), ['id', 'parent_group_name', 'name', 'blocked', 'description'], $groups, '/admin/groups', false, $list);
            echo '</div>';
        });
    }

    private static function groupDeleteCandidate(int $id): ?array
    {
        return $id > 0 ? self::rowById('portal_groups', $id) : null;
    }

    private static function groupDeletePanel(array $group): void
    {
        $id = (int)$group['id'];
        $childCount = self::countRowsByColumn('portal_groups', 'parent_group_id', $id);
        $userCount = self::countRowsByColumn('user_groups', 'group_id', $id);
        $cameraCount = self::countRowsByColumn('camera_groups', 'group_id', $id);

        echo '<section class="panel delete-confirm" role="dialog" aria-labelledby="group-delete-title">';
        echo '<div class="section-head"><h2 id="group-delete-title">' . self::t('groups.deleteTitle', 'Удалить группу') . '</h2><a href="/admin/groups">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '<div class="alert warn">';
        echo '<strong>' . self::t('groups.deleteWarning', 'Это действие нельзя отменить.') . '</strong> ';
        echo self::t('groups.deleteWarningText', 'Удаление группы отвяжет её от пользователей и камер. Дочерние группы будут перенесены на верхний уровень.');
        echo '</div>';
        echo '<dl class="delete-meta">';
        echo '<dt>' . self::t('column.id', 'ID') . '</dt><dd>' . $id . '</dd>';
        echo '<dt>' . self::t('column.name', 'Название') . '</dt><dd>' . Util::h($group['name']) . '</dd>';
        echo '<dt>' . self::t('groups.linkedUsers', 'Пользователей') . '</dt><dd>' . $userCount . '</dd>';
        echo '<dt>' . self::t('groups.linkedCameras', 'Камер') . '</dt><dd>' . $cameraCount . '</dd>';
        echo '<dt>' . self::t('groups.childGroups', 'Дочерних групп') . '</dt><dd>' . $childCount . '</dd>';
        echo '</dl>';
        echo '<form method="post" action="/admin/groups" class="form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '">';
        echo '<label class="check"><input type="checkbox" name="confirm_delete" required> ' . self::t('groups.confirmDelete', 'Подтверждаю удаление группы из портала') . '</label>';
        echo '<div class="form-actions"><button class="danger">' . self::t('action.delete', 'Удалить') . '</button><a href="/admin/groups">' . self::t('action.cancel', 'Отмена') . '</a></div>';
        echo '</form></section>';
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
        if (DB::driver() !== 'pgsql') {
            return;
        }

        $pdo = DB::pdo();
        $sequence = (string)$pdo->query("SELECT pg_get_serial_sequence('portal_groups', 'id')")->fetchColumn();
        if ($sequence === '') {
            return;
        }

        $current = (int)$pdo->query('SELECT last_value FROM ' . self::quoteQualifiedIdentifier($sequence))->fetchColumn();
        $max = (int)$pdo->query('SELECT COALESCE(MAX(id), 1) FROM portal_groups')->fetchColumn();
        $pdo->prepare('SELECT setval(?::regclass, ?, true)')->execute([$sequence, max($current, $max)]);
    }

    private static function quoteQualifiedIdentifier(string $name): string
    {
        $parts = array_filter(explode('.', $name), static fn(string $part): bool => $part !== '');
        return implode('.', array_map(static fn(string $part): string => '"' . str_replace('"', '""', $part) . '"', $parts));
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
}
