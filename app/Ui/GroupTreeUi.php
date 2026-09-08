<?php

declare(strict_types=1);

namespace SesamePortal;

trait GroupTreeUi
{
    private static function groupTreeFilter(array $groups, string $filter, callable $hrefFor): void
    {
        $selectedId = str_starts_with($filter, 'group:') ? (int)substr($filter, 6) : 0;
        [$byId, $children] = self::groupTreeStructure($groups);

        $pathLabels = self::groupPathLabels($groups);
        $placeholder = self::t('filter.groupSelectPlaceholder', 'Выбрать группу');
        $selectedLabel = $selectedId > 0 && isset($byId[$selectedId])
            ? ($pathLabels[$selectedId] ?? (string)$byId[$selectedId]['name'])
            : $placeholder;
        $expanded = self::groupTreeExpandedAncestors($byId, $selectedId > 0 ? [$selectedId] : []);

        echo '<div class="group-tree-picker" data-group-tree-picker>';
        echo '<button type="button" class="group-tree-trigger" aria-haspopup="true" aria-expanded="false"><span>' . Util::h($selectedLabel) . '</span><span class="group-tree-caret" aria-hidden="true"></span></button>';
        echo '<div class="group-tree-menu" data-group-tree-menu hidden>';
        if (!$groups) {
            echo '<div class="group-tree-empty">' . Util::h(self::t('filter.noGroups', 'Группы не найдены')) . '</div>';
        } else {
            echo '<div class="group-tree-list" role="tree" aria-label="' . Util::h(self::t('filter.groupSelect', 'Группа')) . '">';
            self::renderGroupTreeNodes($byId, $children, $expanded, function (array $group, int $depth, bool $hasChildren, bool $isExpanded, callable $renderToggle) use ($selectedId, $hrefFor): void {
                $id = (int)$group['id'];
                $isActive = $selectedId === $id;
                echo '<div class="group-tree-row" style="--depth: ' . (int)$depth . '">';
                $renderToggle();
                echo '<a class="group-tree-option' . ($isActive ? ' active' : '') . '" href="' . Util::h($hrefFor($id)) . '" role="treeitem" aria-level="' . (int)($depth + 1) . '"' . ($isActive ? ' aria-current="true"' : '') . '>' . Util::h((string)$group['name']) . '</a>';
                echo '</div>';
            });
            echo '</div>';
        }
        echo '</div></div>';
    }

    private static function groupParentTreePicker(string $label, array $groups, ?int $selectedId): void
    {
        [$byId, $children] = self::groupTreeStructure($groups);
        $pathLabels = self::groupPathLabels($groups);
        $selectedId = $selectedId !== null && isset($byId[$selectedId]) ? $selectedId : null;
        $selectedLabel = $selectedId !== null
            ? ($pathLabels[$selectedId] ?? (string)$byId[$selectedId]['name'])
            : self::t('groups.noParent', 'Без родителя');
        $expanded = self::groupTreeExpandedAncestors($byId, $selectedId !== null ? [$selectedId] : []);

        echo '<div class="form-field group-parent-field"><span class="form-field-label">' . Util::h($label) . '</span>';
        echo '<div class="group-tree-picker group-tree-select" data-group-tree-picker data-group-tree-select>';
        echo '<input type="hidden" name="parent_group_id" value="' . Util::h((string)($selectedId ?? '')) . '">';
        echo '<button type="button" class="group-tree-trigger" aria-haspopup="true" aria-expanded="false"><span data-group-tree-trigger-label>' . Util::h($selectedLabel) . '</span><span class="group-tree-caret" aria-hidden="true"></span></button>';
        echo '<div class="group-tree-menu" data-group-tree-menu hidden><div class="group-tree-list" role="tree" aria-label="' . Util::h($label) . '">';
        echo '<div class="group-tree-row" style="--depth: 0"><span class="group-tree-spacer" aria-hidden="true"></span><button type="button" class="group-tree-option group-tree-select-option' . ($selectedId === null ? ' active' : '') . '" data-group-tree-select-value="" data-group-tree-select-label="' . Util::h(self::t('groups.noParent', 'Без родителя')) . '" role="treeitem" aria-level="1"' . ($selectedId === null ? ' aria-current="true"' : '') . '>' . Util::h(self::t('groups.noParent', 'Без родителя')) . '</button></div>';
        if (!$groups) {
            echo '<div class="group-tree-empty">' . Util::h(self::t('filter.noGroups', 'Группы не найдены')) . '</div>';
        } else {
            self::renderGroupTreeNodes($byId, $children, $expanded, function (array $group, int $depth, bool $hasChildren, bool $isExpanded, callable $renderToggle) use ($selectedId, $pathLabels): void {
                $id = (int)$group['id'];
                $isActive = $selectedId === $id;
                $label = $pathLabels[$id] ?? (string)$group['name'];
                echo '<div class="group-tree-row" style="--depth: ' . (int)$depth . '">';
                $renderToggle();
                echo '<button type="button" class="group-tree-option group-tree-select-option' . ($isActive ? ' active' : '') . '" data-group-tree-select-value="' . $id . '" data-group-tree-select-label="' . Util::h($label) . '" role="treeitem" aria-level="' . (int)($depth + 1) . '"' . ($isActive ? ' aria-current="true"' : '') . '>' . Util::h((string)$group['name']) . '</button>';
                echo '</div>';
            });
        }
        echo '</div></div></div></div>';
    }

    private static function groupTreeStructure(array $groups): array
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

        return [$byId, $children];
    }

    private static function sortGroupTreeSelectedFirst(array &$children, array $byId, array $selectedSet): void
    {
        $branchSelected = [];
        $hasSelected = static function (int $id) use (&$hasSelected, &$branchSelected, $children, $selectedSet): bool {
            if (array_key_exists($id, $branchSelected)) {
                return $branchSelected[$id];
            }
            if (isset($selectedSet[$id])) {
                $branchSelected[$id] = true;
                return true;
            }
            foreach ($children[$id] ?? [] as $childId) {
                if ($hasSelected((int)$childId)) {
                    $branchSelected[$id] = true;
                    return true;
                }
            }
            $branchSelected[$id] = false;
            return false;
        };

        foreach ($children as &$ids) {
            usort($ids, static function (int $left, int $right) use ($byId, $selectedSet, $hasSelected): int {
                $leftSelected = isset($selectedSet[$left]) ? 0 : 1;
                $rightSelected = isset($selectedSet[$right]) ? 0 : 1;
                if ($leftSelected !== $rightSelected) {
                    return $leftSelected <=> $rightSelected;
                }

                $leftBranchSelected = $hasSelected($left) ? 0 : 1;
                $rightBranchSelected = $hasSelected($right) ? 0 : 1;
                if ($leftBranchSelected !== $rightBranchSelected) {
                    return $leftBranchSelected <=> $rightBranchSelected;
                }

                return strnatcasecmp((string)$byId[$left]['name'], (string)$byId[$right]['name']);
            });
        }
        unset($ids);
    }

    private static function groupTreeExpandedAncestors(array $byId, array $selectedIds): array
    {
        $expanded = [];
        foreach ($selectedIds as $selectedId) {
            $selectedId = (int)$selectedId;
            if ($selectedId <= 0 || !isset($byId[$selectedId])) {
                continue;
            }
            $parentId = (int)($byId[$selectedId]['parent_group_id'] ?? 0);
            $guard = [];
            while ($parentId > 0 && isset($byId[$parentId]) && !isset($guard[$parentId])) {
                $guard[$parentId] = true;
                $expanded[$parentId] = true;
                $parentId = (int)($byId[$parentId]['parent_group_id'] ?? 0);
            }
        }
        return $expanded;
    }

    private static function renderGroupTreeNodes(array $byId, array $children, array $expanded, callable $renderRow): void
    {
        $rendered = [];
        $renderNode = function (int $id, int $depth) use (&$renderNode, &$rendered, $byId, $children, $expanded, $renderRow): void {
            if (isset($rendered[$id]) || !isset($byId[$id])) {
                return;
            }
            $rendered[$id] = true;
            $group = $byId[$id];
            $name = (string)$group['name'];
            $hasChildren = !empty($children[$id]);
            $isExpanded = $hasChildren && isset($expanded[$id]);
            $expandLabel = sprintf(self::t('filter.expandGroup', 'Раскрыть группу %s'), $name);
            $collapseLabel = sprintf(self::t('filter.collapseGroup', 'Свернуть группу %s'), $name);
            $renderToggle = static function () use ($hasChildren, $isExpanded, $expandLabel, $collapseLabel): void {
                if ($hasChildren) {
                    echo '<button type="button" class="group-tree-toggle" data-group-tree-toggle aria-expanded="' . ($isExpanded ? 'true' : 'false') . '" aria-label="' . Util::h($isExpanded ? $collapseLabel : $expandLabel) . '" data-expand-label="' . Util::h($expandLabel) . '" data-collapse-label="' . Util::h($collapseLabel) . '">' . ($isExpanded ? '-' : '+') . '</button>';
                } else {
                    echo '<span class="group-tree-spacer" aria-hidden="true"></span>';
                }
            };

            echo '<div class="group-tree-node' . ($isExpanded ? ' is-expanded' : '') . '" data-group-tree-node>';
            $renderRow($group, $depth, $hasChildren, $isExpanded, $renderToggle);
            if ($hasChildren) {
                echo '<div class="group-tree-children" data-group-tree-children' . ($isExpanded ? '' : ' hidden') . '>';
                foreach ($children[$id] ?? [] as $childId) {
                    $renderNode((int)$childId, $depth + 1);
                }
                echo '</div>';
            }
            echo '</div>';
        };

        foreach ($children[0] ?? [] as $rootId) {
            $renderNode((int)$rootId, 0);
        }
        foreach (array_keys($byId) as $id) {
            if (!isset($rendered[$id])) {
                $renderNode((int)$id, 0);
            }
        }
    }

    private static function groupCheckboxTree(string $title, string $name, array $groups, array $selected, string $jsonName = ''): void
    {
        [$byId, $children] = self::groupTreeStructure($groups);
        $selectedIds = self::apiIntArray($selected);
        $selectedSet = array_flip($selectedIds);
        self::sortGroupTreeSelectedFirst($children, $byId, $selectedSet);
        $expanded = self::groupTreeExpandedAncestors($byId, array_keys($selectedSet));

        echo '<fieldset class="group-tree-field"><legend>' . Util::h($title) . '</legend>';
        if (!$groups) {
            echo '<div class="group-tree-empty">' . Util::h(self::t('filter.noGroups', 'Группы не найдены')) . '</div></fieldset>';
            return;
        }

        echo '<div class="group-tree-actions">';
        echo '<button type="button" data-group-tree-check-all>' . Util::h(self::t('groups.selectAll', 'Выбрать все')) . '</button>';
        echo '<button type="button" data-group-tree-clear-all>' . Util::h(self::t('groups.clearAll', 'Снять все')) . '</button>';
        echo '</div>';
        if ($jsonName !== '') {
            echo '<input type="hidden" name="' . Util::h($jsonName) . '" value="' . Util::h(json_encode($selectedIds)) . '" data-group-tree-json>';
        }
        echo '<div class="group-tree-list group-tree-checkbox-list" role="tree" aria-label="' . Util::h($title) . '">';
        self::renderGroupTreeNodes($byId, $children, $expanded, static function (array $group, int $depth, bool $hasChildren, bool $isExpanded, callable $renderToggle) use ($name, $jsonName, $selectedSet): void {
            $id = (int)$group['id'];
            $inputName = $jsonName === '' ? ' name="' . Util::h($name) . '"' : '';
            echo '<div class="group-tree-row" style="--depth: ' . (int)$depth . '">';
            $renderToggle();
            echo '<label class="group-tree-check" role="treeitem" aria-level="' . (int)($depth + 1) . '"><input type="checkbox"' . $inputName . ' value="' . $id . '" ' . (isset($selectedSet[$id]) ? 'checked' : '') . '> <span>' . Util::h((string)$group['name']) . '</span></label>';
            echo '</div>';
        });
        echo '</div></fieldset>';
    }
}
