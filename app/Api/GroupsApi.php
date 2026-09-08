<?php

declare(strict_types=1);

namespace SesamePortal;

trait GroupsApi
{
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

        $method = self::apiMethod();
        $isUsers = $resource === 'users';
        $linkTable = $isUsers ? 'user_groups' : 'camera_groups';
        $targetKey = $isUsers ? 'user_id' : 'camera_id';
        $inputKey = $isUsers ? 'userIds' : 'cameraIds';
        $inputSnakeKey = $isUsers ? 'user_ids' : 'camera_ids';

        if ($method === 'GET') {
            self::apiJson(self::apiGroupMembersPayload($group, $resource));
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
                self::addLinks($linkTable, 'group_id', $groupId, $targetKey, $ids);
                Audit::log('group.members.add', 'group_id=' . $groupId . ' resource=' . $resource . ' count=' . count($ids));
            } elseif ($method === 'DELETE') {
                self::removeLinks($linkTable, 'group_id', $groupId, $targetKey, $ids);
                Audit::log('group.members.remove', 'group_id=' . $groupId . ' resource=' . $resource . ' count=' . count($ids));
            } else {
                self::replaceLinks($linkTable, 'group_id', $groupId, $targetKey, $ids);
                Audit::log('group.members.replace', 'group_id=' . $groupId . ' resource=' . $resource . ' count=' . count($ids));
            }

            self::apiJson(self::apiGroupMembersPayload(self::rowById('portal_groups', $groupId), $resource));
            return;
        }

        self::apiError(405, 'method_not_allowed', 'Method is not allowed');
    }

    private static function apiGroupMembersPayload(?array $group, string $resource): array
    {
        $isUsers = $resource === 'users';
        $linkTable = $isUsers ? 'user_groups' : 'camera_groups';
        $targetTable = $isUsers ? 'users' : 'cameras';
        $targetKey = $isUsers ? 'user_id' : 'camera_id';
        $idsKey = $isUsers ? 'userIds' : 'cameraIds';
        $rowsKey = $isUsers ? 'users' : 'cameras';
        $ids = $group ? self::linkedIds($linkTable, 'group_id', (int)$group['id'], $targetKey) : [];
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
        $userIdsProvided = array_key_exists('userIds', $input) || array_key_exists('user_ids', $input);
        $cameraIdsProvided = array_key_exists('cameraIds', $input) || array_key_exists('camera_ids', $input);
        $userIds = $userIdsProvided ? self::apiIntArray($input['userIds'] ?? $input['user_ids'] ?? []) : [];
        $cameraIds = $cameraIdsProvided ? self::apiIntArray($input['cameraIds'] ?? $input['camera_ids'] ?? []) : [];
        if ($userIdsProvided) {
            self::apiValidateExistingIds('userIds', 'users', $userIds);
        }
        if ($cameraIdsProvided) {
            self::apiValidateExistingIds('cameraIds', 'cameras', $cameraIds);
        }
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
            if ($userIdsProvided) {
                self::replaceLinks('user_groups', 'group_id', $id, 'user_id', $userIds);
            }
            if ($cameraIdsProvided) {
                self::replaceLinks('camera_groups', 'group_id', $id, 'camera_id', $cameraIds);
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
            $row['userIds'] = self::linkedIds('user_groups', 'group_id', $id, 'user_id');
            $row['cameraIds'] = self::linkedIds('camera_groups', 'group_id', $id, 'camera_id');
        }
        return $row;
    }
}
