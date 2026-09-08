<?php

declare(strict_types=1);

namespace SesamePortal;

trait DvrPages
{
    private static function serverCheckNotice(array $result, string $serverName = ''): string
    {
        $prefix = $serverName !== '' ? $serverName . ': ' : '';
        if (!empty($result['ok'])) {
            return $prefix . self::t('server.checkOk', 'Проверка сервера выполнена');
        }

        return $prefix . self::metricFailureNotice((string)($result['reason'] ?? ''), (string)($result['message'] ?? ''));
    }

    private static function metricFailureNotice(string $reason, string $message): string
    {
        if ($reason === 'management_token_missing') {
            return self::t('server.managementTokenMissingNotice', 'Management token не указан. Portal не может прочитать /api/system/status и /api/streams этого SesameDVR сервера.');
        }
        if ($reason === 'management_token_unreadable') {
            return self::t('server.managementTokenUnreadableNotice', 'Management token не удалось расшифровать. Сохраните новый token в настройках DVR сервера.');
        }
        if (preg_match('/^HTTP\s+401\b/', $message) || str_contains($message, 'HTTP 401')) {
            return self::t('server.managementUnauthorizedNotice', 'SesameDVR вернул HTTP 401. Проверьте Management token в настройках DVR сервера.');
        }

        return self::t('dashboard.metricsRefreshFailed', 'Статистика не обновлена. Подробности показаны в карточке сервера.');
    }

    private static function servers(): void
    {
        Auth::requireAdmin();
        $pdo = DB::pdo();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $id = (int)Util::post('id', 0);
            if ($action === 'save') {
                $token = trim((string)Util::post('management_token'));
                if ($id > 0) {
                    $current = Repo::server($id);
                    $enc = $token !== '' ? Crypto::encrypt($token) : ($current['management_token_enc'] ?? null);
                    $pdo->prepare('UPDATE dvr_servers SET name=?, base_url=?, management_token_enc=?, blocked=? WHERE id=?')
                        ->execute([Util::post('name'), rtrim((string)Util::post('base_url'), '/'), $enc, Util::checkbox('blocked'), $id]);
                } else {
                    $pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                        ->execute([Util::post('name'), rtrim((string)Util::post('base_url'), '/'), Crypto::encrypt($token), Util::checkbox('blocked'), Util::now()]);
                }
                Audit::log('server.save', (string)Util::post('name'));
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->prepare('DELETE FROM dvr_servers WHERE id=?')->execute([$id]);
                Audit::log('server.delete', 'server_id=' . $id);
            } elseif ($action === 'check' && $id > 0) {
                $server = Repo::server($id);
                $result = DvrClient::checkServer($id);
                $message = self::serverCheckNotice($result, $server['name'] ?? '');
            }
        }

        $edit = self::rowById('dvr_servers', (int)($_GET['edit'] ?? 0));
        $list = self::filteredRows('dvr_servers', ['name', 'base_url', 'last_check_result'], 'name ASC');
        $servers = $list['rows'];
        self::layout(self::t('servers.title', 'Серверы SesameDVR'), function () use ($edit, $servers, $message, $list) {
            self::notice($message);
            echo '<div class="admin-grid"><section class="panel"><h2>' . ($edit ? self::t('servers.edit', 'Изменить сервер') : self::t('servers.new', 'Новый сервер')) . '</h2>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . Util::h($edit['id'] ?? 0) . '">';
            echo '<label>' . self::t('column.name', 'Название') . '<input name="name" value="' . Util::h($edit['name'] ?? '') . '" required></label>';
            echo '<label>URL<input name="base_url" value="' . Util::h($edit['base_url'] ?? '') . '" placeholder="https://dvr.example.com" required></label>';
            echo '<label>' . self::t('servers.managementKey', 'Management key') . '<input name="management_token" placeholder="' . ($edit ? self::t('users.passwordPlaceholderEdit', 'оставьте пустым, чтобы не менять') : '') . '"></label>';
            echo '<label class="check"><input type="checkbox" name="blocked" ' . (!empty($edit['blocked']) ? 'checked' : '') . '> ' . self::t('servers.blocked', 'Заблокирован') . '</label>';
            echo '<button class="primary">' . self::t('action.save', 'Сохранить') . '</button></form></section>';
            self::table(self::t('servers.title', 'Серверы'), ['name', 'base_url', 'blocked', 'last_check_result'], $servers, '/admin/servers', true, $list);
            echo '</div>';
        });
    }

    private static function serverMetricCard(array $server): void
    {
        $metrics = json_decode((string)($server['last_metrics_json'] ?? ''), true);
        $metrics = is_array($metrics) ? $metrics : [];
        $version = is_array($metrics['version'] ?? null) ? $metrics['version'] : [];
        $status = is_array($metrics['status'] ?? null) ? $metrics['status'] : [];
        $versionText = self::serverVersionText($version);
        $cpu = self::serverCpuText($status);
        $memory = self::serverMemoryText($status);
        $streams = self::serverStreamsText($metrics, $status);
        $tokenIssue = self::serverManagementTokenIssue($server);
        $metricExplanation = self::serverMetricExplanation($server, $tokenIssue);

        echo '<article class="server-card">';
        echo '<div><strong>' . Util::h($server['name']) . '</strong><span>' . Util::h($server['base_url']) . '</span></div>';
        echo '<dl>';
        echo '<dt>' . self::t('server.version', 'Версия') . '</dt><dd>' . Util::h($versionText) . '</dd>';
        echo '<dt>CPU</dt><dd>' . Util::h($cpu ?? '-') . '</dd>';
        echo '<dt>RAM</dt><dd>' . Util::h($memory ?? '-') . '</dd>';
        echo '<dt>' . self::t('server.streams', 'Потоки') . '</dt><dd>' . Util::h($streams ?? '-') . '</dd>';
        echo '<dt>' . self::t('server.check', 'Проверка') . '</dt><dd>' . self::localTime($server['last_metrics_at'] ?: $server['last_check_at'] ?: '') . '</dd>';
        echo '</dl>';
        if ($metricExplanation !== null) {
            echo '<div class="server-metric-explain">' . Util::h($metricExplanation) . '</div>';
        }
        if (!empty($server['last_check_result']) && $tokenIssue === null) {
            echo '<div class="server-check-result">';
            self::technicalResult((string)$server['last_check_result']);
            echo '</div>';
        }
        self::smallPost('/admin/dashboard', ['action' => 'refresh_server', 'id' => $server['id']], self::t('action.update', 'Обновить'));
        echo '</article>';
    }

    private static function serverManagementTokenIssue(array $server): ?string
    {
        $encoded = trim((string)($server['management_token_enc'] ?? ''));
        if ($encoded === '') {
            return 'management_token_missing';
        }

        return Crypto::decrypt($encoded) === '' ? 'management_token_unreadable' : null;
    }

    private static function serverMetricExplanation(array $server, ?string $tokenIssue): ?string
    {
        if ($tokenIssue !== null) {
            return self::metricFailureNotice($tokenIssue, '');
        }

        $lastResult = (string)($server['last_check_result'] ?? '');
        if (preg_match('/^HTTP\s+401\b/', $lastResult) || str_contains($lastResult, 'HTTP 401')) {
            return self::metricFailureNotice('', $lastResult);
        }

        return null;
    }

    private static function serverVersionText(array $version): string
    {
        $info = $version;
        if (isset($info['version']) && is_array($info['version'])) {
            $info = $info['version'];
        }

        foreach (['appVersion', 'version', 'buildId', 'commit', 'sourceCommit'] as $key) {
            $value = self::scalarText($info[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return 'unknown';
    }

    private static function serverCpuText(array $status): ?string
    {
        $value = self::numericMetric($status, [
            'cpu.aggregate.usagePercent',
            'cpu.totalPercent',
            'cpu.percent',
            'system.cpuPercent',
        ]);
        return $value === null ? null : self::formatPercent($value);
    }

    private static function serverMemoryText(array $status): ?string
    {
        $value = self::numericMetric($status, [
            'memory.usedPercent',
            'system.memoryUsedPercent',
            'ram.usedPercent',
        ]);
        if ($value !== null) {
            return self::formatPercent($value);
        }

        $used = self::numericMetric($status, ['memory.usedBytes', 'ram.usedBytes']);
        $total = self::numericMetric($status, ['memory.totalBytes', 'ram.totalBytes']);
        if ($used !== null && $total !== null && $total > 0) {
            return self::formatPercent(($used / $total) * 100);
        }

        return null;
    }

    private static function serverStreamsText(array $metrics, array $status): ?string
    {
        $streams = $metrics['streams'] ?? null;
        if (is_array($streams)) {
            if (isset($streams['streams']) && is_array($streams['streams'])) {
                return (string)count($streams['streams']);
            }
            if (array_is_list($streams)) {
                return (string)count($streams);
            }
        }

        $value = self::numericMetric($status, [
            'streams.total',
            'streamCount',
            'cameras.total',
            'archiveOrphans.activeCameraCount',
        ]);
        return $value === null ? null : (string)(int)$value;
    }

    private static function numericMetric(array $data, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = self::arrayPath($data, $path);
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return (float)$value;
            }
        }
        return null;
    }

    private static function scalarText(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }
        return null;
    }

    private static function formatPercent(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.') . '%';
    }

    private static function arrayPath(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
