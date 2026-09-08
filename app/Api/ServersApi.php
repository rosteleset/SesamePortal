<?php

declare(strict_types=1);

namespace SesamePortal;

trait ServersApi
{
    private static function apiServers(array $parts): void
    {
        self::apiRequireAdmin();
        $method = self::apiMethod();
        $id = isset($parts[1]) ? (int)$parts[1] : 0;

        if (count($parts) === 1) {
            if ($method === 'GET') {
                $list = self::filteredRows('dvr_servers', ['name', 'base_url', 'last_check_result'], 'name ASC', self::apiPageSize());
                self::apiJson(['servers' => array_map([self::class, 'apiServerRow'], $list['rows']), 'pagination' => self::apiPagination($list)]);
                return;
            }
            if ($method === 'POST') {
                self::apiSaveServer(0, self::apiInput());
                return;
            }
        }
        if ($id <= 0) {
            self::apiError(404, 'not_found', 'Server not found');
            return;
        }
        if (count($parts) === 2) {
            if ($method === 'GET') {
                $server = Repo::server($id);
                $server ? self::apiJson(['server' => self::apiServerRow($server, true)]) : self::apiError(404, 'not_found', 'Server not found');
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                self::apiSaveServer($id, self::apiInput());
                return;
            }
            if ($method === 'DELETE') {
                DB::pdo()->prepare('DELETE FROM dvr_servers WHERE id=?')->execute([$id]);
                Audit::log('server.delete', 'server_id=' . $id);
                self::apiJson(['ok' => true]);
                return;
            }
        }
        if (count($parts) === 3 && $method === 'POST') {
            if ($parts[2] === 'check') {
                self::apiJson(DvrClient::checkServer($id));
                return;
            }
            if ($parts[2] === 'refresh') {
                self::apiJson(DvrClient::fetchServerMetrics($id));
                return;
            }
        }
        self::apiError(404, 'not_found', 'Unknown servers endpoint');
    }

    private static function apiSaveServer(int $id, array $input): void
    {
        $current = $id > 0 ? Repo::server($id) : null;
        if ($id > 0 && !$current) {
            self::apiError(404, 'not_found', 'Server not found');
            return;
        }
        $name = trim((string)($input['name'] ?? ($current['name'] ?? '')));
        $baseUrl = rtrim(trim((string)($input['baseUrl'] ?? $input['base_url'] ?? ($current['base_url'] ?? ''))), '/');
        if ($name === '' || $baseUrl === '') {
            self::apiError(422, 'validation_failed', 'name and baseUrl are required');
            return;
        }
        $blocked = self::apiBlockedValue($input, $current);
        $tokenKeyExists = array_key_exists('managementToken', $input) || array_key_exists('management_token', $input);
        $token = $input['managementToken'] ?? $input['management_token'] ?? null;
        $enc = $current['management_token_enc'] ?? null;
        if ($tokenKeyExists) {
            $token = trim((string)$token);
            $enc = $token === '' ? null : Crypto::encrypt($token);
        }
        $pdo = DB::pdo();
        if ($id > 0) {
            $pdo->prepare('UPDATE dvr_servers SET name=?, base_url=?, management_token_enc=?, blocked=? WHERE id=?')
                ->execute([$name, $baseUrl, $enc, $blocked, $id]);
        } else {
            $pdo->prepare('INSERT INTO dvr_servers(name, base_url, management_token_enc, blocked, created_at) VALUES(?, ?, ?, ?, ?)')
                ->execute([$name, $baseUrl, $enc, $blocked, Util::now()]);
            $id = DB::lastInsertId('dvr_servers');
        }
        Audit::log('server.save', $name);
        self::apiJson(['server' => self::apiServerRow(Repo::server($id), true)], $current ? 200 : 201);
    }

    private static function apiServerRow(?array $server, bool $detailed = false): array
    {
        if (!$server) {
            return [];
        }
        $row = [
            'id' => (int)$server['id'],
            'name' => (string)$server['name'],
            'baseUrl' => (string)$server['base_url'],
            'blocked' => (int)($server['blocked'] ?? 0) === 1,
            'hasManagementToken' => trim((string)($server['management_token_enc'] ?? '')) !== '',
            'lastCheckAt' => $server['last_check_at'] ?? null,
            'lastCheckResult' => $server['last_check_result'] ?? null,
            'lastMetricsAt' => $server['last_metrics_at'] ?? null,
            'createdAt' => $server['created_at'] ?? null,
        ];
        if ($detailed) {
            $metrics = json_decode((string)($server['last_metrics_json'] ?? ''), true);
            $row['lastMetrics'] = is_array($metrics) ? $metrics : null;
        }
        return $row;
    }
}
