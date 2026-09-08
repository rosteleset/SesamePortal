<?php

declare(strict_types=1);

namespace SesamePortal;

trait DashboardPages
{
    private static function dashboard(): void
    {
        Auth::requireAdmin();
        $message = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            if ($action === 'refresh_server') {
                $server = Repo::server((int)Util::post('id'));
                $result = DvrClient::fetchServerMetrics((int)Util::post('id'));
                $message = self::dashboardRefreshNotice($result, $server['name'] ?? '');
            } elseif ($action === 'refresh_all') {
                $okCount = 0;
                $errorCount = 0;
                foreach (Repo::all('dvr_servers', 'name ASC') as $server) {
                    if ((int)$server['blocked'] === 0) {
                        $result = DvrClient::fetchServerMetrics((int)$server['id']);
                        $result['ok'] ? $okCount++ : $errorCount++;
                    }
                }
                $message = self::dashboardRefreshAllNotice($okCount, $errorCount);
            }
        }

        $counts = [
            self::t('dashboard.users', 'Пользователи') => (int)DB::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            self::t('dashboard.groups', 'Группы') => (int)DB::pdo()->query('SELECT COUNT(*) FROM portal_groups')->fetchColumn(),
            self::t('dashboard.cameras', 'Камеры') => (int)DB::pdo()->query('SELECT COUNT(*) FROM cameras')->fetchColumn(),
            self::t('dashboard.dvrServers', 'DVR серверы') => (int)DB::pdo()->query('SELECT COUNT(*) FROM dvr_servers')->fetchColumn(),
        ];
        $servers = Repo::all('dvr_servers', 'name ASC');
        $recentSync = DB::pdo()->query('SELECT c.*, s.name AS server_name FROM cameras c LEFT JOIN dvr_servers s ON s.id = c.server_id ORDER BY COALESCE(c.last_sync_at, "") DESC, c.name ASC LIMIT 12')->fetchAll();

        self::layout(self::t('nav.dashboard', 'Dashboard'), function () use ($counts, $servers, $recentSync, $message) {
            self::notice($message);
            echo '<section class="summary-grid">';
            foreach ($counts as $label => $value) {
                echo '<div class="summary-card"><span>' . Util::h($label) . '</span><strong>' . Util::h($value) . '</strong></div>';
            }
            echo '</section>';
            echo '<section class="panel"><div class="section-head"><h2>' . self::t('dashboard.dvrServersTitle', 'SesameDVR серверы') . '</h2>';
            self::smallPost('/admin/dashboard', ['action' => 'refresh_all'], self::t('action.updateAll', 'Обновить все'), 'primary');
            echo '</div><div class="server-grid">';
            foreach ($servers as $server) {
                self::serverMetricCard($server);
            }
            echo '</div></section>';
            self::table(self::t('dashboard.recentSync', 'Последняя синхронизация камер'), ['name', 'server_name', 'last_sync_ok', 'last_sync_at', 'last_sync_message'], $recentSync, '/admin/cameras', true, null, false);
        });
    }

    private static function dashboardRefreshNotice(array $result, string $serverName = ''): string
    {
        $prefix = $serverName !== '' ? $serverName . ': ' : '';
        if (!empty($result['ok'])) {
            return $prefix . self::t('dashboard.metricsUpdated', 'Статистика обновлена');
        }

        return $prefix . self::metricFailureNotice((string)($result['reason'] ?? ''), (string)($result['message'] ?? ''));
    }

    private static function dashboardRefreshAllNotice(int $okCount, int $errorCount): string
    {
        return self::t('dashboard.refreshFinished', 'Обновление завершено') . ': '
            . $okCount . ' ' . self::t('dashboard.refreshOk', 'успешно') . ', '
            . $errorCount . ' ' . self::t('dashboard.refreshErrors', 'с ошибкой');
    }
}
