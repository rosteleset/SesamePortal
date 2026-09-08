<?php

declare(strict_types=1);

namespace SesamePortal;

trait AgentsApi
{
    private static function apiAgents(array $parts): void
    {
        self::apiRequireAdmin();
        $method = self::apiMethod();
        $input = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? self::apiInput() : [];
        $serverId = (int)($_GET['server_id'] ?? $_GET['serverId'] ?? $input['server_id'] ?? $input['serverId'] ?? 0);
        if ($serverId <= 0) {
            self::apiError(422, 'validation_failed', 'serverId is required');
            return;
        }
        $agentId = isset($parts[1]) ? trim((string)$parts[1]) : '';

        if (count($parts) === 1) {
            if ($method === 'GET') {
                self::apiJson(DvrClient::listAgents($serverId));
                return;
            }
            if ($method === 'POST') {
                $payload = [
                    'id' => trim((string)($input['id'] ?? $input['agent_id'] ?? $input['agentId'] ?? '')),
                    'name' => trim((string)($input['name'] ?? '')),
                    'enabled' => array_key_exists('enabled', $input) ? self::apiBool($input['enabled']) : true,
                    'capabilities' => is_array($input['capabilities'] ?? null)
                        ? array_values($input['capabilities'])
                        : self::agentCapabilitiesFromText((string)($input['capabilities'] ?? '')),
                ];
                if ($payload['id'] === '') {
                    self::apiError(422, 'validation_failed', 'agent id is required');
                    return;
                }
                if ($payload['name'] === '') {
                    $payload['name'] = $payload['id'];
                }
                if (!empty($input['password'])) {
                    $payload['password'] = (string)$input['password'];
                }
                self::apiJson(DvrClient::createAgent($serverId, $payload), 201);
                return;
            }
        }
        if ($agentId === '') {
            self::apiError(404, 'not_found', 'Agent not found');
            return;
        }
        if (count($parts) === 2) {
            if ($method === 'GET') {
                self::apiJson([
                    'agentId' => $agentId,
                    'cameras' => DvrClient::agentCameras($serverId, $agentId),
                    'commands' => DvrClient::agentCommands($serverId, $agentId),
                    'logs' => DvrClient::agentLogs($serverId, $agentId),
                ]);
                return;
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                $payload = [];
                if (array_key_exists('name', $input)) {
                    $payload['name'] = trim((string)$input['name']) ?: $agentId;
                }
                if (array_key_exists('enabled', $input)) {
                    $payload['enabled'] = self::apiBool($input['enabled']);
                }
                if (array_key_exists('capabilities', $input)) {
                    $payload['capabilities'] = is_array($input['capabilities'])
                        ? array_values($input['capabilities'])
                        : self::agentCapabilitiesFromText((string)$input['capabilities']);
                }
                self::apiJson(DvrClient::updateAgent($serverId, $agentId, $payload));
                return;
            }
            if ($method === 'DELETE') {
                self::apiJson(DvrClient::deleteAgent($serverId, $agentId));
                return;
            }
        }
        $tail = array_slice($parts, 2);
        if ($method === 'GET' && $tail === ['cameras']) {
            self::apiJson(DvrClient::agentCameras($serverId, $agentId));
            return;
        }
        if ($method === 'GET' && $tail === ['commands']) {
            self::apiJson(DvrClient::agentCommands($serverId, $agentId));
            return;
        }
        if ($method === 'GET' && $tail === ['logs']) {
            self::apiJson(DvrClient::agentLogs($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['enrollment-password']) {
            self::apiJson(DvrClient::setAgentEnrollmentPassword($serverId, $agentId, (string)($input['password'] ?? '')));
            return;
        }
        if ($method === 'POST' && $tail === ['revoke']) {
            self::apiJson(DvrClient::revokeAgent($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['rotate-secret']) {
            self::apiJson(DvrClient::rotateAgentSecret($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['cameras', 'scan']) {
            self::apiJson(DvrClient::scanAgentCameras($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['diagnostics']) {
            self::apiJson(DvrClient::agentDiagnostics($serverId, $agentId));
            return;
        }
        if ($method === 'POST' && $tail === ['commands']) {
            $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
            self::apiJson(DvrClient::agentCommand($serverId, $agentId, trim((string)($input['command'] ?? 'test_camera')) ?: 'test_camera', $payload, isset($input['timeoutMs']) ? (int)$input['timeoutMs'] : null));
            return;
        }
        self::apiError(404, 'not_found', 'Unknown agents endpoint');
    }
}
