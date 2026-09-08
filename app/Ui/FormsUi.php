<?php

declare(strict_types=1);

namespace SesamePortal;

trait FormsUi
{
    private static function jsonDetailsPanel(string $title, mixed $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo '<details class="panel json-details"><summary><span><strong>' . Util::h($title) . '</strong><small>' . self::t('agents.details', 'Технические детали') . '</small></span></summary><pre class="json-panel">' . Util::h($json === false ? '' : $json) . '</pre></details>';
    }

    private static function statusPill(string $status): string
    {
        $class = match ($status) {
            'online' => 'success',
            'disabled' => 'warn',
            'offline' => 'danger',
            default => 'info',
        };
        return '<span class="pill ' . $class . '">' . Util::h($status) . '</span>';
    }

    private static function selectOption(string $value, string $label, string $selected): void
    {
        echo '<option value="' . Util::h($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . Util::h($label) . '</option>';
    }

    private static function iconActionLink(string $href, string $label, string $icon, string $class = ''): void
    {
        $classes = trim('icon-action ' . $class);
        echo '<a href="' . Util::h($href) . '" class="' . Util::h($classes) . '" title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '">' . self::icon($icon) . '<span class="sr-only">' . Util::h($label) . '</span></a>';
    }

    private static function smallPost(string $path, array $fields, string $label, string $class = '', string $confirm = '', string $icon = ''): void
    {
        echo '<form method="post" action="' . Util::h($path) . '" class="inline-form"';
        if ($confirm !== '') {
            $confirmJson = json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
            echo ' onsubmit="return confirm(' . Util::h($confirmJson === false ? '""' : $confirmJson) . ')"';
        }
        echo '>' . Csrf::field();
        foreach ($fields as $key => $value) {
            echo '<input type="hidden" name="' . Util::h($key) . '" value="' . Util::h($value) . '">';
        }
        $buttonClass = trim($class . ($icon !== '' ? ' icon-action' : ''));
        echo '<button class="' . Util::h($buttonClass) . '"';
        if ($icon !== '') {
            echo ' title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '"';
        }
        echo '>';
        if ($icon !== '') {
            echo self::icon($icon) . '<span class="sr-only">' . Util::h($label) . '</span>';
        } else {
            echo Util::h($label);
        }
        echo '</button></form>';
    }

    private static function checkboxList(string $title, string $name, array $rows, array $selected, string $labelKey): void
    {
        echo '<fieldset><legend>' . Util::h($title) . '</legend><div class="check-list">';
        foreach ($rows as $row) {
            echo '<label class="check"><input type="checkbox" name="' . Util::h($name) . '" value="' . (int)$row['id'] . '" ' . (in_array((int)$row['id'], $selected, true) ? 'checked' : '') . '> ' . Util::h($row['display_name'] ?? $row[$labelKey]) . '</label>';
        }
        echo '</div></fieldset>';
    }

    private static function assignmentPicker(string $title, string $name, array $rows, array $selected, string $labelKey, string $searchPlaceholder): void
    {
        $selectedSet = array_flip(array_map('intval', $selected));
        $ordered = $rows;
        usort($ordered, static function (array $left, array $right) use ($selectedSet, $labelKey): int {
            $leftSelected = isset($selectedSet[(int)$left['id']]) ? 0 : 1;
            $rightSelected = isset($selectedSet[(int)$right['id']]) ? 0 : 1;
            if ($leftSelected !== $rightSelected) {
                return $leftSelected <=> $rightSelected;
            }

            return strnatcasecmp((string)$left[$labelKey], (string)$right[$labelKey]);
        });

        $selectedCount = 0;
        foreach ($rows as $row) {
            if (isset($selectedSet[(int)$row['id']])) {
                $selectedCount++;
            }
        }

        echo '<fieldset class="assignment-picker" data-assignment-picker><legend>' . Util::h($title) . '</legend>';
        echo '<div class="assignment-toolbar">';
        echo '<input type="search" class="assignment-search" placeholder="' . Util::h($searchPlaceholder) . '" autocomplete="off">';
        echo '<button type="button" class="assignment-selected-only" aria-pressed="false">' . self::t('assignment.selectedOnly', 'Только выбранные') . '</button>';
        echo '<span class="assignment-count" data-total="' . count($rows) . '">' . self::t('js.selectedCount', 'Выбрано') . ': ' . $selectedCount . ' / ' . count($rows) . '</span>';
        echo '</div>';
        echo '<div class="assignment-list">';
        foreach ($ordered as $row) {
            $checked = isset($selectedSet[(int)$row['id']]);
            echo '<label class="assignment-row"><input type="checkbox" name="' . Util::h($name) . '" value="' . (int)$row['id'] . '" ' . ($checked ? 'checked' : '') . '><span>' . Util::h($row[$labelKey]) . '</span></label>';
        }
        echo '<div class="assignment-empty" hidden>' . self::t('assignment.empty', 'Ничего не найдено') . '</div>';
        echo '</div></fieldset>';
    }

    private static function favoriteButton(int $cameraId, bool $isFavorite): void
    {
        echo '<form method="post" action="/favorite/toggle" class="favorite-form">' . Csrf::field();
        echo '<input type="hidden" name="camera_id" value="' . $cameraId . '">';
        $label = self::t('filter.favorites', 'Избранное');
        echo '<button title="' . Util::h($label) . '" aria-label="' . Util::h($label) . '" class="' . ($isFavorite ? 'favorite active' : 'favorite') . '">' . ($isFavorite ? '★' : '☆') . '</button></form>';
    }

    private static function notice(string $message, string $class = ''): void
    {
        if ($message !== '') {
            $classes = trim('alert ' . $class);
            echo '<div class="' . Util::h($classes) . '">' . Util::h($message) . '</div>';
        }
    }
}
