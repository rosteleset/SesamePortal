<?php

declare(strict_types=1);

namespace SesamePortal;

trait AgentsPages
{
    private static function agents(): void
    {
        Auth::requireAdmin();
        $servers = Repo::all('dvr_servers', 'name ASC');
        $selectedServerId = self::selectedServerId($servers);
        $selectedAgentId = trim((string)($_GET['agent_id'] ?? ''));
        $message = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $action = (string)Util::post('action');
            $selectedServerId = (int)Util::post('server_id', $selectedServerId);
            $selectedAgentId = trim((string)Util::post('agent_id', $selectedAgentId));
            $result = null;

            if ($selectedServerId <= 0) {
                $message = self::t('agents.serverRequired', 'Выберите SesameDVR сервер');
            } elseif ($action === 'create') {
                $agentId = trim((string)Util::post('agent_id'));
                $name = trim((string)Util::post('name')) ?: $agentId;
                if ($agentId === '') {
                    $message = self::t('agents.agentId', 'Agent ID') . ' required';
                } else {
                    $payload = [
                        'id' => $agentId,
                        'name' => $name,
                        'enabled' => Util::checkbox('enabled') === 1,
                        'capabilities' => self::agentCapabilitiesFromText((string)Util::post('capabilities')),
                    ];
                    $password = trim((string)Util::post('password'));
                    if ($password !== '') {
                        $payload['password'] = $password;
                    }
                    $result = DvrClient::createAgent($selectedServerId, $payload);
                    $selectedAgentId = $agentId;
                }
            } elseif ($selectedAgentId === '') {
                $message = self::t('agents.agentId', 'Agent ID') . ' required';
            } elseif ($action === 'update') {
                $result = DvrClient::updateAgent($selectedServerId, $selectedAgentId, [
                    'name' => trim((string)Util::post('name')) ?: $selectedAgentId,
                    'enabled' => Util::checkbox('enabled') === 1,
                    'capabilities' => self::agentCapabilitiesFromText((string)Util::post('capabilities')),
                ]);
            } elseif ($action === 'delete') {
                $result = DvrClient::deleteAgent($selectedServerId, $selectedAgentId);
                if (!empty($result['ok'])) {
                    $selectedAgentId = '';
                }
            } elseif ($action === 'password') {
                $password = trim((string)Util::post('password'));
                $result = $password === ''
                    ? ['ok' => false, 'message' => self::t('agents.password', 'Enrollment password') . ' required']
                    : DvrClient::setAgentEnrollmentPassword($selectedServerId, $selectedAgentId, $password);
            } elseif ($action === 'revoke') {
                $result = DvrClient::revokeAgent($selectedServerId, $selectedAgentId);
            } elseif ($action === 'rotate') {
                $result = DvrClient::rotateAgentSecret($selectedServerId, $selectedAgentId);
            } elseif ($action === 'scan') {
                $result = DvrClient::scanAgentCameras($selectedServerId, $selectedAgentId);
            } elseif ($action === 'diagnostics') {
                $result = DvrClient::agentDiagnostics($selectedServerId, $selectedAgentId);
            } elseif ($action === 'command') {
                [$payload, $payloadError] = self::agentCommandPayload((string)Util::post('payload'), (string)Util::post('agent_camera_id'));
                if ($payloadError !== null) {
                    $result = ['ok' => false, 'message' => $payloadError];
                } else {
                    $timeout = (int)Util::post('timeout_ms', 0);
                    $result = DvrClient::agentCommand($selectedServerId, $selectedAgentId, trim((string)Util::post('command')) ?: 'test_camera', $payload, $timeout > 0 ? $timeout : null);
                }
            }

            if (is_array($result)) {
                $message = self::agentActionMessage($action, $result);
            }
        }

        $agentsResult = $selectedServerId > 0 ? DvrClient::listAgents($selectedServerId) : ['ok' => false, 'message' => self::t('agents.noServer', 'Сначала добавьте SesameDVR сервер с management token.'), 'data' => ['agents' => []]];
        $agents = is_array($agentsResult['data'] ?? null) && is_array(($agentsResult['data']['agents'] ?? null)) ? $agentsResult['data']['agents'] : [];
        if ($selectedAgentId === '' && $agents) {
            $selectedAgentId = (string)($agents[0]['id'] ?? '');
        }
        $selectedAgent = self::findAgentRow($agents, $selectedAgentId);
        $agentCamerasResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentCameras($selectedServerId, $selectedAgentId) : null;
        $agentCommandsResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentCommands($selectedServerId, $selectedAgentId) : null;
        $agentLogsResult = $selectedAgentId !== '' && $selectedServerId > 0 ? DvrClient::agentLogs($selectedServerId, $selectedAgentId) : null;

        self::layout(self::t('agents.title', 'Edge-агенты'), function () use ($servers, $selectedServerId, $selectedAgentId, $selectedAgent, $agentsResult, $agents, $agentCamerasResult, $agentCommandsResult, $agentLogsResult, $message) {
            self::notice($message);
            if (!$servers) {
                self::notice(self::t('agents.noServer', 'Сначала добавьте SesameDVR сервер с management token.'));
                return;
            }

            echo '<section class="panel agents-toolbar"><form method="get" action="/admin/agents" class="filters">';
            echo '<label>' . self::t('cameras.server', 'Сервер') . '<select name="server_id" onchange="this.form.submit()">';
            foreach ($servers as $server) {
                echo '<option value="' . (int)$server['id'] . '" ' . ($selectedServerId === (int)$server['id'] ? 'selected' : '') . '>' . Util::h($server['name']) . '</option>';
            }
            echo '</select></label><button>' . self::t('action.update', 'Обновить') . '</button></form></section>';

            if ($selectedServerId <= 0) {
                return;
            }

            echo '<div class="admin-grid agents-admin-grid"><details class="panel agent-create-panel"><summary><span><strong>' . self::t('agents.new', 'Новый агент') . '</strong><small>' . self::t('agents.createHint', 'Создание агента нужно только перед первичной установкой edge-устройства.') . '</small></span></summary>';
            echo '<form method="post" class="form">' . Csrf::field();
            echo '<input type="hidden" name="action" value="create"><input type="hidden" name="server_id" value="' . (int)$selectedServerId . '">';
            echo '<label>' . self::t('agents.agentId', 'Agent ID') . '<input name="agent_id" placeholder="agt-office-1" required></label>';
            echo '<label>' . self::t('agents.agentName', 'Название агента') . '<input name="name" placeholder="Office NanoPi"></label>';
            echo '<label>' . self::t('agents.password', 'Пароль enrollment') . '<input name="password" autocomplete="new-password"></label>';
            echo '<label>' . self::t('agents.capabilities', 'Возможности') . '<input name="capabilities" value="rtmp_push,onvif_events"></label>';
            echo '<label class="check"><input type="checkbox" name="enabled" checked> ' . self::t('agents.enabled', 'Включён') . '</label>';
            echo '<button class="primary">' . self::t('agents.create', 'Создать агента') . '</button></form></details>';

            $agentsSummary = !empty($agentsResult['ok'])
                ? self::t('agents.loaded', 'Загружено агентов') . ': ' . count($agents)
                : self::agentResultSummary($agentsResult, self::t('agents.noAgents', 'Агенты не найдены'));
            echo '<section class="panel"><div class="section-head"><h2>' . self::t('agents.title', 'Edge-агенты') . '</h2><span class="muted">' . Util::h($agentsSummary) . '</span></div>';
            if (!empty($agentsResult['message'])) {
                self::technicalResult((string)$agentsResult['message'], self::t('agents.details', 'Технические детали'));
            }
            if (!$agents) {
                echo '<p class="muted">' . self::t('agents.noAgents', 'Агенты не найдены') . '</p>';
            }
            echo '<div class="agent-list">';
            foreach ($agents as $agent) {
                self::agentCard($selectedServerId, $agent, $selectedAgentId);
            }
            echo '</div></section></div>';

            if ($selectedAgentId !== '') {
                self::agentDetails($selectedServerId, $selectedAgentId, $selectedAgent, $agentCamerasResult, $agentCommandsResult, $agentLogsResult);
            }
        });
    }

    private static function agentSnapshotProxy(): void
    {
        Auth::requireAdmin();
        $serverId = (int)($_GET['server_id'] ?? 0);
        $agentId = trim((string)($_GET['agent_id'] ?? ''));
        $cameraId = trim((string)($_GET['camera_id'] ?? ''));
        if ($serverId <= 0 || $agentId === '' || $cameraId === '') {
            http_response_code(400);
            echo 'missing snapshot parameters';
            return;
        }

        $result = DvrClient::agentSnapshot($serverId, $agentId, $cameraId, !empty($_GET['fresh']));
        if (empty($result['ok'])) {
            http_response_code((int)($result['status'] ?? 502) ?: 502);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string)($result['message'] ?? 'snapshot failed');
            return;
        }

        $contentType = (string)($result['contentType'] ?? 'image/jpeg');
        if (!str_starts_with(strtolower($contentType), 'image/')) {
            $contentType = 'image/jpeg';
        }
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store');
        echo (string)($result['data'] ?? '');
    }

    private static function selectedServerId(array $servers): int
    {
        $requested = (int)($_GET['server_id'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }
        foreach ($servers as $server) {
            if ((int)($server['blocked'] ?? 0) === 0) {
                return (int)$server['id'];
            }
        }
        return $servers ? (int)$servers[0]['id'] : 0;
    }

    private static function agentCapabilitiesFromText(string $text): array
    {
        $parts = preg_split('/[\s,]+/', trim($text)) ?: [];
        $capabilities = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $capabilities[$part] = true;
            }
        }
        return array_keys($capabilities);
    }

    private static function agentCommandPayload(string $text, string $agentCameraId): array
    {
        $text = trim($text);
        $payload = [];
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                return [[], 'Payload JSON must be an object'];
            }
            $payload = $decoded;
        }

        $agentCameraId = trim($agentCameraId);
        if ($agentCameraId !== '') {
            $payload += [
                'agentCameraId' => $agentCameraId,
                'cameraId' => $agentCameraId,
            ];
        }
        return [$payload, null];
    }

    private static function agentActionMessage(string $action, array $result): string
    {
        $data = $result['data'] ?? null;
        if (!empty($result['ok']) && is_array($data) && !empty($data['agentSecret'])) {
            return self::t('agents.newSecret', 'Новый секрет агента') . ': ' . $data['agentSecret'];
        }

        if (empty($result['ok'])) {
            return self::agentResultSummary($result, self::t('agents.actionFailed', 'Операция не выполнена'));
        }

        return match ($action) {
            'scan', 'diagnostics', 'command' => self::t('agents.actionQueued', 'Команда поставлена в очередь'),
            default => self::t('agents.actionCompleted', 'Операция выполнена'),
        };
    }

    private static function agentResultSummary(?array $result, string $okText): string
    {
        if (!$result) {
            return '';
        }
        if (!empty($result['ok'])) {
            return $okText;
        }

        $status = (int)($result['status'] ?? 0);
        $prefix = self::t('agents.actionFailed', 'Операция не выполнена');
        if ($status > 0) {
            return $prefix . ' · HTTP ' . $status;
        }

        $message = trim((string)($result['message'] ?? ''));
        return $message !== '' && !str_starts_with($message, 'HTTP ')
            ? $prefix . ' · ' . $message
            : $prefix;
    }

    private static function findAgentRow(array $agents, string $agentId): ?array
    {
        foreach ($agents as $agent) {
            if (is_array($agent) && (string)($agent['id'] ?? '') === $agentId) {
                return $agent;
            }
        }
        return null;
    }

    private static function agentCard(int $serverId, array $agent, string $selectedAgentId): void
    {
        $id = (string)($agent['id'] ?? '');
        if ($id === '') {
            return;
        }
        $name = (string)($agent['name'] ?? $id);
        $status = (string)($agent['status'] ?? 'offline');
        $capabilities = is_array($agent['capabilities'] ?? null) ? implode(',', $agent['capabilities']) : '';
        $active = $id === $selectedAgentId ? ' active' : '';
        $href = '/admin/agents?' . http_build_query(['server_id' => $serverId, 'agent_id' => $id]);

        echo '<article class="agent-card' . $active . '">';
        echo '<div class="agent-card-head"><div><a class="agent-card-title" href="' . Util::h($href) . '">' . Util::h($name) . '</a><code>' . Util::h($id) . '</code></div>';
        echo self::statusPill($status) . '</div>';
        echo '<dl class="agent-meta">';
        echo '<dt>' . self::t('agents.version', 'Версия') . '</dt><dd>' . Util::h($agent['version'] ?? '-') . '</dd>';
        echo '<dt>' . self::t('agents.lastSeen', 'Последняя связь') . '</dt><dd>' . self::localTime($agent['lastSeenAt'] ?? '') . '</dd>';
        echo '<dt>' . self::t('agents.cameraCount', 'Камеры') . '</dt><dd>' . Util::h($agent['cameraCount'] ?? 0) . '</dd>';
        echo '<dt>' . self::t('agents.mediaSessions', 'Медиа-сессии') . '</dt><dd>' . Util::h($agent['activeMediaSessions'] ?? 0) . '</dd>';
        echo '</dl>';
        echo '<details class="agent-settings-details" open><summary>' . self::t('agents.settings', 'Настройки') . '</summary>';
        echo '<form method="post" class="agent-edit-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="update"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($id) . '">';
        echo '<label>' . self::t('agents.agentName', 'Название агента') . '<input name="name" value="' . Util::h($name) . '"></label>';
        echo '<label>' . self::t('agents.capabilities', 'Возможности') . '<input name="capabilities" value="' . Util::h($capabilities) . '"></label>';
        echo '<label class="check"><input type="checkbox" name="enabled" ' . (!empty($agent['enabled']) ? 'checked' : '') . '> ' . self::t('agents.enabled', 'Включён') . '</label>';
        echo '<button>' . self::t('action.save', 'Сохранить') . '</button></form>';
        echo '</details>';
        echo '<div class="agent-action-block"><strong>' . self::t('agents.actions', 'Действия') . '</strong>';
        echo '<div class="row-actions row-actions-icons agent-actions">';
        self::smallPost('/admin/agents', ['action' => 'scan', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.scan', 'Сканировать ONVIF'), '', '', 'scan');
        self::smallPost('/admin/agents', ['action' => 'diagnostics', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.diagnostics', 'Диагностика'), '', '', 'diagnostics');
        self::smallPost('/admin/agents', ['action' => 'revoke', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.revoke', 'Отозвать секрет'), '', '', 'ban');
        self::smallPost('/admin/agents', ['action' => 'rotate', 'server_id' => $serverId, 'agent_id' => $id], self::t('agents.rotateSecret', 'Сменить секрет'), '', '', 'key');
        self::smallPost('/admin/agents', ['action' => 'delete', 'server_id' => $serverId, 'agent_id' => $id], self::t('action.delete', 'Удалить'), 'danger', '', 'trash');
        echo '</div></div>';
        echo '<details class="agent-settings-details"><summary>' . self::t('agents.enrollment', 'Enrollment') . '</summary>';
        echo '<form method="post" class="agent-password-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="password"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($id) . '">';
        echo '<label>' . self::t('agents.password', 'Пароль enrollment') . '<input name="password" autocomplete="new-password"></label><button>' . self::t('agents.setPassword', 'Задать пароль') . '</button></form>';
        echo '</details>';
        echo '</article>';
    }

    private static function agentDetails(int $serverId, string $agentId, ?array $agent, ?array $camerasResult, ?array $commandsResult, ?array $logsResult): void
    {
        echo '<details class="panel agent-command-panel"><summary><span><strong>' . self::t('agents.commandConsole', 'Консоль команд') . '</strong><small>' . Util::h($agent['name'] ?? $agentId) . ' · ' . Util::h($agentId) . '</small></span></summary>';
        echo '<form method="post" class="form agent-command-form">' . Csrf::field();
        echo '<input type="hidden" name="action" value="command"><input type="hidden" name="server_id" value="' . $serverId . '"><input type="hidden" name="agent_id" value="' . Util::h($agentId) . '">';
        echo '<div class="form-row"><label>' . self::t('agents.command', 'Команда') . '<input name="command" value="test_camera"></label>';
        echo '<label>' . self::t('cameras.agentCameraId', 'Agent camera ID') . '<input name="agent_camera_id"></label></div>';
        echo '<label>' . self::t('agents.payload', 'Payload JSON') . '<textarea name="payload" placeholder="{&quot;agentCameraId&quot;:&quot;cam1&quot;}"></textarea></label>';
        echo '<label>' . self::t('agents.timeout', 'Таймаут, мс') . '<input name="timeout_ms" type="number" min="1000" step="1000" placeholder="30000"></label>';
        echo '<button class="primary">' . self::t('agents.sendCommand', 'Отправить команду') . '</button></form></details>';

        $cameras = is_array($camerasResult['data'] ?? null) && is_array(($camerasResult['data']['cameras'] ?? null)) ? $camerasResult['data']['cameras'] : [];
        $cameraSummary = !empty($camerasResult['ok'])
            ? count($cameras)
            : self::agentResultSummary($camerasResult, '0');
        echo '<section class="panel"><div class="section-head"><h2>' . self::t('agents.cameras', 'Камеры агента') . '</h2><span class="muted">' . Util::h((string)$cameraSummary) . '</span></div>';
        if (!empty($camerasResult['message'])) {
            self::technicalResult((string)$camerasResult['message'], self::t('agents.details', 'Технические детали'));
        }
        if (!$cameras) {
            echo '<p class="muted">-</p>';
        }
        echo '<div class="agent-camera-grid">';
        foreach ($cameras as $camera) {
            if (is_array($camera)) {
                self::agentCameraCard($serverId, $agentId, $camera);
            }
        }
        echo '</div></section>';

        echo '<div class="grid cols-2">';
        self::jsonDetailsPanel(self::t('agents.lastCommands', 'Последние команды'), $commandsResult['data'] ?? $commandsResult);
        self::jsonDetailsPanel(self::t('agents.lastLogs', 'Последние записи журнала'), $logsResult['data'] ?? $logsResult);
        echo '</div>';
    }

    private static function agentCameraCard(int $serverId, string $agentId, array $camera): void
    {
        $cameraId = (string)($camera['agentCameraId'] ?? $camera['id'] ?? '');
        if ($cameraId === '') {
            return;
        }
        $name = (string)($camera['name'] ?? $cameraId);
        $stream = Util::dvrStreamSlug($name);
        $snapshotUrl = '/admin/agents/snapshot?' . http_build_query(['server_id' => $serverId, 'agent_id' => $agentId, 'camera_id' => $cameraId]);
        $createUrl = '/admin/cameras?' . http_build_query([
            'mode' => 'edge_agent',
            'server_id' => $serverId,
            'agent_id' => $agentId,
            'agent_camera_id' => $cameraId,
            'name' => $name,
            'stream' => $stream,
            'onvif_events_requested' => !empty($camera['onvifStatus']) ? 1 : 0,
        ]);

        echo '<article class="agent-camera-card">';
        echo '<div class="agent-snapshot"><img src="' . Util::h($snapshotUrl) . '" alt=""></div>';
        echo '<div><strong>' . Util::h($name) . '</strong><code>' . Util::h($cameraId) . '</code></div>';
        echo '<dl class="agent-meta">';
        echo '<dt>' . self::t('agents.source', 'Источник') . '</dt><dd>' . Util::h($camera['sourceKind'] ?? '-') . '</dd>';
        echo '<dt>RTSP</dt><dd>' . Util::h($camera['rtspUrlRedacted'] ?? '-') . '</dd>';
        echo '<dt>' . self::t('agents.media', 'Медиа') . '</dt><dd>' . Util::h(self::agentValueSummary($camera['mediaStatus'] ?? null)) . '</dd>';
        echo '<dt>' . self::t('agents.onvif', 'ONVIF') . '</dt><dd>' . Util::h(self::agentValueSummary($camera['onvifStatus'] ?? null)) . '</dd>';
        echo '<dt>' . self::t('agents.lastSeen', 'Последняя связь') . '</dt><dd>' . self::localTime($camera['lastSeenAt'] ?? '') . '</dd>';
        echo '</dl>';
        echo '<a class="btn" href="' . Util::h($createUrl) . '">' . self::t('agents.useCamera', 'Создать камеру в Portal') . '</a>';
        echo '</article>';
    }

    private static function agentValueSummary(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? self::t('agents.yes', 'да') : self::t('agents.no', 'нет');
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (!is_array($value)) {
            return self::t('agents.unknown', 'неизвестно');
        }

        $parts = [];
        foreach (['status', 'state', 'backend'] as $key) {
            if (!empty($value[$key]) && is_scalar($value[$key])) {
                $parts[] = (string)$value[$key];
            }
        }
        if (array_key_exists('running', $value)) {
            $parts[] = self::truthyMetricValue($value['running']) ? self::t('agents.running', 'работает') : self::t('agents.stopped', 'остановлен');
        }
        if (array_key_exists('online', $value)) {
            $parts[] = self::truthyMetricValue($value['online']) ? self::t('agents.online', 'online') : self::t('agents.offline', 'offline');
        }
        $error = $value['lastError'] ?? $value['error'] ?? null;
        if (is_scalar($error) && trim((string)$error) !== '') {
            $parts[] = 'error: ' . mb_substr(trim((string)$error), 0, 120);
        }

        $parts = array_values(array_unique(array_filter($parts, static fn($part) => $part !== '')));
        return $parts ? implode(' · ', $parts) : self::t('agents.technicalData', 'Технические данные');
    }
}
