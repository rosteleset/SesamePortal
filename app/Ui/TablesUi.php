<?php

declare(strict_types=1);

namespace SesamePortal;

use DateTimeImmutable;
use DateTimeInterface;

trait TablesUi
{
    private static function table(string $title, array $columns, array $rows, string $base, bool $actions = false, ?array $pager = null, bool $showSearch = true): void
    {
        echo '<section class="panel"><div class="section-head"><h2>' . Util::h($title) . '</h2>';
        if ($showSearch) {
            if ($base === '/admin/cameras') {
                self::cameraTableFilters($pager ?? []);
            } elseif ($base === '/admin/users') {
                self::userTableFilters($pager ?? []);
            } else {
                echo '<form method="get" action="' . Util::h($base) . '" class="table-search">';
                echo '<input name="q" value="' . Util::h($pager['q'] ?? '') . '" placeholder="' . self::t('table.search', 'Поиск') . '">';
                echo '<button>' . self::t('action.find', 'Найти') . '</button>';
                echo '</form>';
            }
        }
        $tableClass = 'data-table';
        if (str_starts_with($base, '/admin/')) {
            $tableClass .= ' table-' . str_replace(['/', '_'], '-', trim(substr($base, strlen('/admin/')), '/'));
        }

        echo '</div><div class="table-wrap"><table class="' . Util::h($tableClass) . '"><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . Util::h(self::columnLabel($column)) . '</th>';
        }
        $actionUrl = self::tableActionUrl($base, [], $pager);
        echo '<th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $column) {
                self::tableCell($column, $row[$column] ?? '');
            }
            echo '<td><div class="row-actions row-actions-icons">';
            self::iconActionLink(self::tableActionUrl($base, ['edit' => (int)$row['id']], $pager), self::t('action.edit', 'Изменить'), 'edit');
            if ($actions && str_contains($base, 'servers')) {
                self::smallPost($actionUrl, ['action' => 'check', 'id' => $row['id']], self::t('action.check', 'Проверить'), '', '', 'check');
            }
            if ($actions && str_contains($base, 'cameras')) {
                self::smallPost($actionUrl, ['action' => 'sync', 'id' => $row['id']], self::t('action.sync', 'Синхронизировать'), '', '', 'sync');
            }
            if ($base === '/admin/cameras' || $base === '/admin/groups') {
                self::iconActionLink(self::tableActionUrl($base, ['delete' => (int)$row['id']], $pager), self::t('action.delete', 'Удалить'), 'trash', 'danger');
            } else {
                self::smallPost($actionUrl, ['action' => 'delete', 'id' => $row['id']], self::t('action.delete', 'Удалить'), 'danger', '', 'trash');
            }
            if ($base === '/admin/users') {
                $hasStaticToken = trim((string)($row['static_token_hash'] ?? '')) !== '';
                self::smallPost(
                    $actionUrl,
                    ['action' => 'issue_static', 'id' => $row['id']],
                    $hasStaticToken ? self::t('token.staticReplace', 'Заменить статический токен') : self::t('token.staticIssue', 'Выпустить статический токен'),
                    '',
                    $hasStaticToken ? self::t('token.staticReplaceConfirm', 'Старый статический токен сразу перестанет работать. Выпустить новый токен?') : '',
                    $hasStaticToken ? 'token-refresh' : 'token-issue'
                );
                if ($hasStaticToken) {
                    self::smallPost($actionUrl, ['action' => 'revoke_static', 'id' => $row['id']], self::t('action.revoke', 'Отозвать'), 'danger', '', 'token-revoke');
                }
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
        if ($pager) {
            self::pager($base, $pager);
        }
        echo '</section>';
    }

    private static function columnLabel(string $column): string
    {
        return self::t('column.' . $column, $column);
    }

    private static function tableCell(string $column, mixed $value): void
    {
        if ($column === 'archive_enabled') {
            $enabled = (int)$value === 1;
            $label = $enabled ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет');
            echo '<td><span class="pill ' . ($enabled ? 'success' : 'danger') . '">' . Util::h($label) . '</span></td>';
            return;
        }

        if ($column === 'hide_archive') {
            $enabled = (int)$value === 1;
            $label = $enabled ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет');
            echo '<td><span class="pill ' . ($enabled ? 'warn' : 'success') . '">' . Util::h($label) . '</span></td>';
            return;
        }

        if ($column === 'static_token_hash') {
            $hasToken = trim((string)$value) !== '';
            $label = $hasToken
                ? self::t('token.staticPresent', 'есть')
                : self::t('token.staticMissing', 'нет');
            echo '<td><span class="pill ' . ($hasToken ? 'success' : 'danger') . '">' . Util::h($label) . '</span></td>';
            return;
        }

        if ($column === 'admin_comment') {
            $text = trim((string)$value);
            if ($text === '') {
                echo '<td class="muted">-</td>';
                return;
            }
            echo '<td class="table-comment" title="' . Util::h($text) . '">' . Util::h(self::technicalSummary($text)) . '</td>';
            return;
        }

        if ($column === 'last_sync_message') {
            self::syncResultCell(trim((string)$value));
            return;
        }

        if ($column === 'last_check_result') {
            $text = trim((string)$value);
            if ($text === '') {
                echo '<td class="muted">-</td>';
                return;
            }
            echo '<td class="table-technical">';
            self::technicalResult($text);
            echo '</td>';
            return;
        }

        if (str_ends_with($column, '_at')) {
            echo '<td class="time-cell">' . self::localTime($value) . '</td>';
            return;
        }

        echo '<td>' . Util::h($value) . '</td>';
    }

    private static function localTime(mixed $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '<span class="muted">-</span>';
        }

        try {
            $time = new DateTimeImmutable($text);
        } catch (\Throwable) {
            return Util::h($text);
        }

        return '<time class="local-time" datetime="' . Util::h($time->format(DateTimeInterface::ATOM)) . '">' . Util::h($text) . '</time>';
    }

    private static function technicalResult(string $text, ?string $summary = null): void
    {
        echo '<details class="technical-result"><summary>' . Util::h($summary ?? self::technicalSummary($text)) . '</summary><pre>' . Util::h($text) . '</pre></details>';
    }

    private static function technicalSummary(string $text): string
    {
        if (preg_match('/^HTTP\\s+\\d+/', $text, $match)) {
            return $match[0];
        }
        if (strlen($text) <= 80) {
            return $text;
        }
        return rtrim(substr($text, 0, 77)) . '...';
    }

    private static function syncResultCell(string $text): void
    {
        if ($text === '') {
            echo '<td class="muted">-</td>';
            return;
        }

        $status = self::syncResultStatus($text);
        echo '<td class="table-result"><span class="sync-result-dot sync-result-dot-' . Util::h($status) . '" title="' . Util::h($text) . '" aria-label="' . Util::h($text) . '" role="img"></span></td>';
    }

    private static function syncResultStatus(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        if (str_contains($lower, 'read-only') || str_contains($lower, 'read_only') || str_contains($lower, 'readonly') || str_contains($lower, 'только чт')) {
            return 'readonly';
        }

        if (preg_match('/\\bHTTP\\s+(\\d{3})\\b/i', $text, $match)) {
            return (int)$match[1] >= 400 ? 'bad' : 'ok';
        }

        $badMarkers = [
            'error',
            'failed',
            'failure',
            'timeout',
            'timed out',
            'unavailable',
            'blocked',
            'missing',
            'invalid',
            'denied',
            'forbidden',
            'unauthorized',
            'cannot',
            'refused',
            'mismatch',
            'not_found',
            'not configured',
            'no sesamedvr',
            'ошиб',
            'не выполн',
            'недоступ',
            'заблок',
            'не указан',
            'не настро',
            'нельзя',
            'отказ',
            'таймаут',
        ];
        foreach ($badMarkers as $marker) {
            if (str_contains($lower, $marker)) {
                return 'bad';
            }
        }

        return 'ok';
    }

    private static function pager(string $base, array $pager, array $extraParams = []): void
    {
        if (!$pager) {
            return;
        }
        $total = (int)($pager['total'] ?? 0);
        $pageSize = max(1, (int)($pager['pageSize'] ?? 1));
        $currentPage = max(1, (int)($pager['page'] ?? 1));
        $rowCount = count($pager['rows'] ?? []);
        $from = $total === 0 ? 0 : (($currentPage - 1) * $pageSize) + 1;
        $to = $total === 0 ? 0 : min($total, $from + $rowCount - 1);
        $shown = $total === 0 ? '0' : $from . '-' . $to;

        echo '<div class="pager-note">' . self::t('table.shown', 'Показано') . ' ' . Util::h($shown) . ' ' . self::t('table.of', 'из') . ' ' . Util::h($total) . '</div>';
        $pages = (int)ceil(max(1, (int)$pager['total']) / max(1, (int)$pager['pageSize']));
        if ($pages <= 1) {
            return;
        }

        $pageHref = static function (int $page) use ($base, $pager, $extraParams): string {
            $queryParams = self::pagerQueryParams($pager);
            $queryParams['page'] = $page;
            foreach ($extraParams as $key => $value) {
                $queryParams[$key] = $value;
            }
            $query = http_build_query(array_filter($queryParams, fn($value) => $value !== '' && $value !== null && $value !== 0));
            return $base . ($query ? '?' . $query : '');
        };
        $visible = [1, $pages];
        for ($page = $currentPage - 2; $page <= $currentPage + 2; $page++) {
            if ($page >= 1 && $page <= $pages) {
                $visible[] = $page;
            }
        }
        $visible = array_values(array_unique($visible));
        sort($visible);

        echo '<nav class="pager">';
        if ($currentPage > 1) {
            echo '<a href="' . Util::h($pageHref($currentPage - 1)) . '">&lsaquo;</a>';
        }
        $previous = 0;
        foreach ($visible as $page) {
            if ($previous > 0 && $page > $previous + 1) {
                echo '<span class="pager-gap">...</span>';
            }
            echo '<a class="' . ($currentPage === $page ? 'active' : '') . '" href="' . Util::h($pageHref($page)) . '">' . $page . '</a>';
            $previous = $page;
        }
        if ($currentPage < $pages) {
            echo '<a href="' . Util::h($pageHref($currentPage + 1)) . '">&rsaquo;</a>';
        }
        echo '</nav>';
    }

    private static function tableActionUrl(string $base, array $params = [], ?array $pager = null): string
    {
        $query = [];
        if ($pager) {
            $query = self::pagerQueryParams($pager);
            $page = (int)($pager['page'] ?? 1);
            if ($page > 1) {
                $query['page'] = $page;
            }
        }

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || $value === 0) {
                continue;
            }
            $query[$key] = $value;
        }

        $encoded = http_build_query($query);
        return $base . ($encoded !== '' ? '?' . $encoded : '');
    }

    private static function pagerQueryParams(array $pager): array
    {
        $query = [];
        foreach (['q', 'server_id', 'mode', 'archive', 'sync'] as $key) {
            $value = trim((string)($pager[$key] ?? ''));
            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        $groupId = (int)($pager['group_id'] ?? 0);
        if ($groupId > 0) {
            $query['group_id'] = $groupId;
        }

        $sort = trim((string)($pager['sort'] ?? ''));
        $dir = strtolower(trim((string)($pager['dir'] ?? 'asc')));
        if ($sort !== '' && ($sort !== 'name' || $dir === 'desc')) {
            $query['sort'] = $sort;
        }
        if ($dir === 'desc') {
            $query['dir'] = 'desc';
        }

        return $query;
    }
}
