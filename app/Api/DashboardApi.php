<?php

declare(strict_types=1);

namespace SesamePortal;

trait DashboardApi
{
    private static function apiDashboard(array $parts): void
    {
        self::apiRequireAdmin();
        if (count($parts) !== 1) {
            self::apiError(404, 'not_found', 'Unknown dashboard endpoint');
            return;
        }
        $method = self::apiMethod();
        if ($method === 'GET') {
            $servers = array_map(fn($server) => self::apiServerRow($server, true), Repo::all('dvr_servers', 'name ASC'));
            self::apiJson([
                'counts' => [
                    'users' => (int)DB::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                    'groups' => (int)DB::pdo()->query('SELECT COUNT(*) FROM portal_groups')->fetchColumn(),
                    'cameras' => (int)DB::pdo()->query('SELECT COUNT(*) FROM cameras')->fetchColumn(),
                    'servers' => (int)DB::pdo()->query('SELECT COUNT(*) FROM dvr_servers')->fetchColumn(),
                ],
                'servers' => $servers,
            ]);
            return;
        }
        if ($method === 'POST') {
            $input = self::apiInput();
            $serverId = (int)($input['serverId'] ?? $input['server_id'] ?? 0);
            if ($serverId > 0) {
                self::apiJson(DvrClient::fetchServerMetrics($serverId));
                return;
            }
            $results = [];
            foreach (Repo::all('dvr_servers', 'name ASC') as $server) {
                if ((int)$server['blocked'] === 0) {
                    $results[] = ['serverId' => (int)$server['id']] + DvrClient::fetchServerMetrics((int)$server['id']);
                }
            }
            self::apiJson(['results' => $results]);
            return;
        }
        self::apiError(405, 'method_not_allowed', 'Method is not allowed');
    }
}
