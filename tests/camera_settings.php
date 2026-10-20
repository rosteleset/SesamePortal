<?php

declare(strict_types=1);

use SesamePortal\Crypto;
use SesamePortal\DB;
use SesamePortal\DvrClient;
use SesamePortal\TokenService;
use SesamePortal\Util;

$root = dirname(__DIR__);
$stateDir = sys_get_temp_dir() . '/portal-camera-settings-' . bin2hex(random_bytes(8));
mkdir($stateDir, 0700);
putenv('SESAME_PORTAL_STATE_DIR=' . $stateDir);
putenv('SESAME_PORTAL_DB_DSN=sqlite:' . $stateDir . '/portal.sqlite');
putenv('SESAME_PORTAL_SECRET=settings-test-secret');
putenv('SESAME_PORTAL_UPDATE_AUTO_CHECK=0');
putenv('SESAME_PORTAL_DVR_SETTINGS_FIXTURE=' . $stateDir . '/dvr.json');
require $root . '/app/Portal.php';
$processes = [];
$checks = 0;

function expectSettings(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

function fixture(?callable $change = null): array
{
    $file = getenv('SESAME_PORTAL_DVR_SETTINGS_FIXTURE');
    $value = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if ($change) {
        $change($value);
        file_put_contents($file, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
    }
    return $value;
}

function writes(): array
{
    return array_values(array_filter(fixture()['requests'], static fn($r) => $r['method'] !== 'GET'));
}

function lastWrite(): array
{
    $writes = writes();
    return $writes[array_key_last($writes)];
}

function startSettingsServer(array $args): string
{
    global $root, $stateDir, $processes;
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', $address, ...$args], [
        0 => ['pipe', 'r'], 1 => ['file', $stateDir . '/server.log', 'a'], 2 => ['file', $stateDir . '/server.log', 'a'],
    ], $pipes, $root);
    $processes[] = $process;
    fclose($pipes[0]);
    for ($i = 0; $i < 100; $i++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($socket) { fclose($socket); return 'http://' . $address; }
        usleep(20_000);
    }
    throw new RuntimeException('Server did not start');
}

function settingsRequest(string $method, string $path, ?array $data = null, bool $api = false): array
{
    global $base, $stateDir, $token;
    $curl = curl_init($base . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEFILE => $stateDir . '/cookies', CURLOPT_COOKIEJAR => $stateDir . '/cookies',
    ];
    $headers = [];
    if ($api) $headers[] = 'Authorization: Bearer ' . $token;
    if ($data !== null) {
        $headers[] = $api ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_POSTFIELDS] = $api ? json_encode($data) : http_build_query($data);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException(curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, $body];
}

function settingsForm(int $id, ?string $html = null): array
{
    $html ??= settingsRequest('GET', '/admin/cameras?edit=' . $id)[1];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[input[@name="action" and @value="save"]]')->item(0);
    if (!$form) throw new RuntimeException('Missing camera form');
    $values = [];
    foreach ($xpath->query('.//input[@name] | .//select[@name]', $form) as $field) {
        $name = $field->getAttribute('name');
        if ($field->hasAttribute('disabled') || str_ends_with($name, '[]')) continue;
        if ($field->tagName === 'select') {
            $selected = $xpath->query('.//option[@selected]', $field)->item(0) ?? $xpath->query('.//option', $field)->item(0);
            $values[$name] = $selected->getAttribute('value');
        } elseif (in_array($field->getAttribute('type'), ['checkbox', 'radio'], true)) {
            if ($field->hasAttribute('checked')) $values[$name] = $field->getAttribute('value') ?: '1';
        } else {
            $values[$name] = $field->getAttribute('value');
        }
    }
    return $values;
}

function saveSettings(array $form): array
{
    return settingsRequest('POST', '/admin/cameras?edit=' . $form['id'], $form);
}

function settingsTable(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);
    $rows = [];
    foreach ($xpath->query('//table[contains(@class,"table-cameras")]/tbody/tr') as $row) {
        $id = $xpath->query('.//input[@name="id"]', $row)->item(0);
        $cells = $xpath->query('./td', $row);
        if ($id) $rows[(int)$id->getAttribute('value')] = array_map(static fn($cell) => trim($cell->textContent), iterator_to_array($cells));
    }
    return $rows;
}

try {
    DB::migrate();
    $pdo = DB::pdo();
    $now = Util::now();
    $pdo->prepare('INSERT INTO users(login,password_hash,role,created_at) VALUES(?,?,?,?)')
        ->execute(['settings-admin', password_hash('test-password', PASSWORD_DEFAULT), 'admin', $now]);
    $token = TokenService::issueStaticToken(DB::lastInsertId('users'));
    $stream = [
        'name' => 'cam', 'displayName' => 'Current DVR title', 'source' => 'rtsp://example.invalid/current',
        'sourceType' => 'direct', 'enabled' => true, 'archiveEnabled' => true, 'retentionDays' => '30m',
        'webrtcFastStart' => true, 'eventArchiveRetentionEnabled' => true,
        'eventArchiveMaxBytes' => 67_108_865, 'eventArchiveMaxDuration' => null, 'eventArchiveMaxAge' => '60d',
        'timelapseEnabled' => false, 'timelapseFramesPerHour' => 120, 'timelapseRetentionDays' => null,
        'timelapsePlaybackFps' => 20, 'directArchiveVideoTimelineRepairMode' => null, 'audioCodec' => 'copy',
        'ptzEnabled' => true, 'audioGainDb' => 6, 'authMode' => 'public',
    ];
    $agent = array_replace($stream, [
        'sourceType' => 'push', 'source' => 'push://cam', 'displayName' => 'Agent on second DVR',
        'retentionDays' => '2d', 'archiveEnabled' => false,
        'push' => ['agentId' => 'current-agent', 'agentCameraId' => 'current-camera', 'onvifEventsRequested' => true,
            'transport' => 'cmaf', 'publisherKind' => 'agent', 'requested' => true, 'tokenHash' => 'keep'],
    ]);
    file_put_contents($stateDir . '/dvr.json', json_encode(['servers' => ['one' => ['cam' => $stream], 'two' => ['cam' => $agent]], 'errors' => [], 'requests' => []]));
    $dvr = startSettingsServer(['tests/camera_settings_dvr_router.php']);
    foreach (['one', 'two'] as $server) {
        $pdo->prepare('INSERT INTO dvr_servers(name,base_url,management_token_enc,created_at) VALUES(?,?,?,?)')
            ->execute([$server, $dvr . '/' . $server, Crypto::encrypt('settings-test-secret'), $now]);
        $serverId = DB::lastInsertId('dvr_servers');
        $pdo->prepare('INSERT INTO cameras(name,source_url,server_id,dvr_stream_name,dvr_control_mode,agent_id,agent_camera_id,retention_days,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute(['Stale ' . $server, 'rtsp://example.invalid/stale', $serverId, 'cam', $server === 'two' ? 'edge_agent' : 'managed', 'old-agent', 'old-camera', '7d', $now, $now]);
    }
    $base = startSettingsServer(['-t', 'public', 'public/index.php']);
    expectSettings(settingsRequest('POST', '/login', ['login' => 'settings-admin', 'password' => 'test-password'])[0] === 303, 'Login');

    $table = settingsTable(settingsRequest('GET', '/admin/cameras')[1]);
    expectSettings($table[1][5] === '30m' && $table[2][5] === '2d', 'Table loads current retention from the correct DVR without opening a camera form');
    expectSettings($table[1][6] === 'да' && $table[2][6] === 'нет', 'Table loads current archive recording flags');
    expectSettings(array_column(fixture()['requests'], 'path') === ['/one/api/streams', '/two/api/streams'], 'Only one batch request per DVR, not one request per camera');
    $local = $pdo->query('SELECT * FROM cameras WHERE id=1')->fetch();
    expectSettings($local['name'] === 'Stale one' && $local['source_url'] === 'rtsp://example.invalid/stale' && $local['retention_days'] === '7d'
        && $local['updated_at'] === $now && $local['last_sync_at'] === null, 'Refresh only updates display metadata, not local names, sources or sync status');
    expectSettings(writes() === [], 'Reading table never writes to DVR');
    expectSettings(array_keys(settingsTable(settingsRequest('GET', '/admin/cameras?sort=retention&dir=asc')[1])) === [2, 1], 'Retention sorting uses observed DVR values');
    expectSettings(array_keys(settingsTable(settingsRequest('GET', '/admin/cameras?sort=archive&dir=asc')[1])) === [2, 1], 'Archive sorting uses observed DVR values');

    $startRequests = count(fixture()['requests']);
    $filtered = settingsTable(settingsRequest('GET', '/admin/cameras?archive=off&server_id=2')[1]);
    expectSettings(array_keys($filtered) === [2], 'Archive filter uses live values before pagination');
    expectSettings(array_column(array_slice(fixture()['requests'], $startRequests), 'path') === ['/two/api/streams'], 'Server filter only contacts the selected DVR');
    fixture(function (&$s) {
        $s['servers']['two']['cam']['retentionDays'] = '15m';
        $s['servers']['two']['cam']['archiveEnabled'] = true;
        $s['wrappedLists']['two'] = true;
    });
    $filtered = settingsTable(settingsRequest('GET', '/admin/cameras?archive=off&server_id=2')[1]);
    expectSettings($filtered === [] && $pdo->query('SELECT dvr_retention_days FROM cameras WHERE id=2')->fetchColumn() === '15m', 'Next table open refreshes direct DVR edits, including wrapped list responses');
    [$status, $body] = settingsRequest('GET', '/api/portal/v1/cameras?server_id=2', null, true);
    expectSettings($status === 200 && json_decode($body, true)['cameras'][0]['retentionDays'] === '15m', 'Admin API list uses refreshed table metadata too');

    fixture(function (&$s) { $s['errors']['GET /two/api/streams'] = 503; $s['servers']['one']['cam']['retentionDays'] = '45m'; });
    $unavailableList = settingsRequest('GET', '/admin/cameras')[1];
    $table = settingsTable($unavailableList);
    expectSettings($table[1][5] === '45m' && $table[2][5] === '15m', 'Unavailable DVR keeps cached values while other DVR refreshes');
    expectSettings(str_contains($unavailableList, 'последние известные значения'), 'List warns when cached values are shown');
    fixture(function (&$s) {
        unset($s['errors']['GET /two/api/streams']);
        $s['servers']['one']['cam']['retentionDays'] = '30m';
        $s['servers']['two']['cam']['retentionDays'] = '2d';
        $s['servers']['two']['cam']['archiveEnabled'] = false;
    });
    foreach ([['unexpected' => true], []] as $invalidList) {
        fixture(function (&$s) use ($invalidList) { $s['responses']['GET /two/api/streams'] = $invalidList; });
        $page = settingsRequest('GET', '/admin/cameras?server_id=2')[1];
        expectSettings(settingsTable($page)[2][5] === '15m' && str_contains($page, 'последние известные значения'), 'Invalid response or missing stream keeps last known metadata and warns');
    }
    fixture(function (&$s) { unset($s['responses']['GET /two/api/streams']); });
    $startRequests = count(fixture()['requests']);
    settingsRequest('GET', '/admin/cameras?server_id=none');
    expectSettings(count(fixture()['requests']) === $startRequests, 'Unassigned camera filter performs no DVR requests');
    settingsRequest('GET', '/admin/cameras');
    $old = SesamePortal\Repo::camera(1);
    $pdo->exec("UPDATE cameras SET retention_days='4h' WHERE id=1");
    (new ReflectionMethod(DvrClient::class, 'cacheCameraListSettings'))->invoke(null, $old, ['retentionDays' => '1h']);
    expectSettings($pdo->query('SELECT retention_days FROM cameras WHERE id=1')->fetchColumn() === '4h', 'In-flight refresh does not overwrite a concurrent save');
    (new ReflectionMethod(DvrClient::class, 'cacheCameraListSettings'))->invoke(null, $old, ['retentionDays' => '10m']);
    expectSettings($pdo->query('SELECT dvr_retention_days FROM cameras WHERE id=1')->fetchColumn() === '1h', 'An older snapshot cannot overwrite a newer observed value');

    $form = settingsForm(1);
    expectSettings($form['display_name'] === $stream['displayName'] && $form['source_url'] === $stream['source'], 'Fresh title and source');
    expectSettings($form['retention_days'] === '30m' && $form['event_archive_max_mb'] === '64' && $form['event_archive_max_age'] === '60d', 'Fresh event archive limits');
    expectSettings(isset($form['webrtc_fast_start'], $form['event_archive_retention_enabled']), 'Fresh booleans');
    expectSettings($pdo->query('SELECT dvr_retention_days FROM cameras WHERE id=1')->fetchColumn() === '30m'
        && $pdo->query('SELECT retention_days FROM cameras WHERE id=1')->fetchColumn() === '4h' && writes() === [], 'Opening refreshes observations without modifying editable settings or DVR');
    $secondTab = settingsForm(1);
    expectSettings($form['camera_edit_token'] !== $secondTab['camera_edit_token'], 'Independent tab snapshots');
    fixture(function (&$s) {
        $s['servers']['one']['cam']['eventArchiveMaxBytes'] = 268_435_456;
        $s['servers']['one']['cam']['eventArchiveMaxAge'] = '90d';
        $s['servers']['one']['cam']['audioCodec'] = 'aac';
    });
    $form['display_name'] = 'User changed title';
    expectSettings(saveSettings($form)[0] === 200, 'Save form');
    expectSettings(writes() === [['method' => 'PUT', 'path' => '/one/api/streams/cam', 'body' => ['displayName' => 'User changed title']]], 'Only user-changed title sent, not rounded quota or defaults');
    $actual = fixture()['servers']['one']['cam'];
    expectSettings($actual['eventArchiveMaxAge'] === '90d' && $actual['eventArchiveMaxBytes'] === 268_435_456 && $actual['audioCodec'] === 'aac', 'Concurrent DVR changes preserved');
    expectSettings($actual['authMode'] === 'public' && $actual['audioGainDb'] === 6 && $actual['ptzEnabled'], 'Unexposed DVR fields preserved');
    $secondTab['watermark_enabled'] = '1';
    saveSettings($secondTab);
    expectSettings(count(writes()) === 1, 'Other tab local-only change sends nothing');
    expectSettings(fixture()['servers']['one']['cam']['displayName'] === 'User changed title', 'Old tab does not restore old title');
    $fresh = settingsForm(1);
    expectSettings($fresh['event_archive_max_mb'] === '256' && $fresh['audio_codec'] === 'aac', 'Next open reads latest DVR values');
    unset($fresh['event_archive_retention_enabled']);
    $fresh['event_archive_max_age'] = '';
    saveSettings($fresh);
    expectSettings(lastWrite()['body'] === ['eventArchiveRetentionEnabled' => false, 'eventArchiveMaxAge' => null], 'False and null are intentional edits');

    $edge = settingsForm(2);
    expectSettings($edge['display_name'] === 'Agent on second DVR' && $edge['agent_id'] === 'current-agent', 'Correct DVR for same stream name');
    unset($edge['onvif_events_requested']);
    saveSettings($edge);
    expectSettings(lastWrite()['body'] === ['push' => ['onvifEventsRequested' => false]], 'Nested agent patch only contains changed setting');
    expectSettings(fixture()['servers']['two']['cam']['push']['transport'] === 'cmaf' && fixture()['servers']['two']['cam']['push']['tokenHash'] === 'keep', 'Agent transport and secret preserved');

    $beforeWrites = count(writes());
    $wrong = settingsForm(1);
    $wrong['id'] = 2;
    $wrong['display_name'] = 'Wrong camera token';
    $wrong['dvr_control_mode'] = 'edge_agent';
    $wrong['agent_id'] = 'current-agent';
    $wrong['agent_camera_id'] = 'current-camera';
    saveSettings($wrong);
    expectSettings(count(writes()) === $beforeWrites, 'Snapshot cannot be used for another camera');
    $missing = settingsForm(1);
    unset($missing['camera_edit_token']);
    $missing['retention_days'] = '999d';
    saveSettings($missing);
    expectSettings(count(writes()) === $beforeWrites, 'Missing snapshot cannot fall back to full sync');

    $rebound = settingsForm(1);
    $pdo->exec('UPDATE cameras SET server_id=2 WHERE id=1');
    $rebound['display_name'] = 'Stale binding';
    saveSettings($rebound);
    expectSettings(count(writes()) === $beforeWrites && (int)$pdo->query('SELECT server_id FROM cameras WHERE id=1')->fetchColumn() === 2, 'Rebinding invalidates previously opened form without restoring old target');
    $pdo->exec('UPDATE cameras SET server_id=1 WHERE id=1');

    foreach ([401, 404] as $status) {
        fixture(function (&$s) use ($status) { $s['errors']['GET /one/api/streams/cam'] = $status; });
        expectSettings(!DvrClient::loadCameraSettings(SesamePortal\Repo::camera(1))['ok'], 'Unauthorized or absent stream cannot supply an edit baseline');
    }
    fixture(function (&$s) { unset($s['errors']['GET /one/api/streams/cam']); $s['servers']['one']['cam']['name'] = 'wrong-stream'; });
    expectSettings(!DvrClient::loadCameraSettings(SesamePortal\Repo::camera(1))['ok'], 'Response identity must match requested stream');
    fixture(function (&$s) { $s['servers']['one']['cam']['name'] = 'cam'; });

    $failed = settingsForm(1);
    $failed['retention_days'] = '2h';
    fixture(function (&$s) { $s['errors']['PUT /one/api/streams/cam'] = 503; });
    $failedPage = saveSettings($failed)[1];
    $retry = settingsForm(1, $failedPage);
    expectSettings($retry['retention_days'] === '2h' && $retry['camera_edit_token'] === $failed['camera_edit_token'], 'Failed write retains user values and original baseline');
    fixture(function (&$s) { unset($s['errors']['PUT /one/api/streams/cam']); $s['servers']['one']['cam']['eventArchiveMaxAge'] = '120d'; });
    saveSettings($retry);
    expectSettings(lastWrite()['body'] === ['retentionDays' => '2h'], 'Retry remains a delta');
    expectSettings(fixture()['servers']['one']['cam']['eventArchiveMaxAge'] === '120d', 'Retry preserves later DVR changes');

    fixture(function (&$s) { $s['errors']['GET /one/api/streams/cam'] = 503; });
    $unavailable = settingsRequest('GET', '/admin/cameras?edit=1')[1];
    expectSettings(str_contains($unavailable, 'Не удалось прочитать настройки с DVR') && str_contains($unavailable, '<button class="primary" disabled>'), 'Unavailable DVR is explicit and save disabled');
    $beforeWrites = count(writes());
    $forged = settingsForm(1, $unavailable);
    $forged['retention_days'] = '100d';
    saveSettings($forged);
    expectSettings(count(writes()) === $beforeWrites, 'Unavailable snapshot rejected on server too');
    fixture(function (&$s) { unset($s['errors']['GET /one/api/streams/cam']); });

    $deleted = settingsForm(1);
    $deleted['retention_days'] = '3h';
    fixture(function (&$s) { $s['errors']['PUT /one/api/streams/cam'] = 404; });
    saveSettings($deleted);
    expectSettings(count(writes()) === $beforeWrites + 1 && lastWrite()['method'] === 'PUT', 'Disappearing stream is not recreated');
    fixture(function (&$s) { unset($s['errors']['PUT /one/api/streams/cam']); });

    settingsRequest('PATCH', '/api/portal/v1/cameras/1', ['watermarkIntensity' => 23], true);
    expectSettings(count(writes()) === $beforeWrites + 1, 'API local-only patch does not sync stale settings');
    settingsRequest('PATCH', '/api/portal/v1/cameras/1', ['retentionDays' => '4h'], true);
    expectSettings(lastWrite()['body'] === ['retentionDays' => '4h'], 'API forwards only explicit DVR settings');
    settingsRequest('PATCH', '/api/portal/v1/cameras/2', ['onvifEventsRequested' => true], true);
    expectSettings(lastWrite()['body'] === ['push' => ['onvifEventsRequested' => true]], 'API agent update preserves nested fields');
    $beforeWrites = count(writes());
    settingsRequest('PATCH', '/api/portal/v1/cameras/1', ['audioCodec' => 'copy', 'retentionDays' => '8h', 'skipSync' => true], true);
    expectSettings(count(writes()) === $beforeWrites, 'skipSync preserved');
    $table = settingsTable(settingsRequest('GET', '/admin/cameras')[1]);
    expectSettings($table[1][5] === '4h' && $pdo->query('SELECT retention_days FROM cameras WHERE id=1')->fetchColumn() === '8h', 'Table displays actual DVR depth without discarding unsent skipSync settings');
    expectSettings(count(writes()) === $beforeWrites, 'Refreshing pending settings does not trigger a sync');
    [$status, $body] = settingsRequest('POST', '/api/portal/v1/cameras', [
        'name' => 'Created camera', 'dvrStreamName' => 'new-cam', 'serverId' => 1,
        'sourceUrl' => 'rtsp://example.invalid/new', 'retentionDays' => '5d',
    ], true);
    expectSettings($status === 201 && json_decode($body, true)['sync']['ok'], 'New stream still created');
    expectSettings(lastWrite()['method'] === 'POST' && fixture()['servers']['one']['new-cam']['authMode'] === 'authBackend', 'New stream gets full initial configuration');
    $createdId = (int)json_decode($body, true)['camera']['id'];
    settingsRequest('GET', '/admin/cameras');
    expectSettings($pdo->query('SELECT dvr_retention_days FROM cameras WHERE id=' . $createdId)->fetchColumn() === '5d', 'Created stream receives observed metadata on table read');
    settingsRequest('PATCH', '/api/portal/v1/cameras/' . $createdId, ['serverId' => 2, 'skipSync' => true], true);
    expectSettings($pdo->query('SELECT dvr_retention_days FROM cameras WHERE id=' . $createdId)->fetchColumn() === null, 'Rebinding clears observations from the previous DVR');
    settingsRequest('PATCH', '/api/portal/v1/cameras/' . $createdId, ['serverId' => 1, 'skipSync' => true], true);

    $modeChange = settingsForm(1);
    $modeChange['dvr_control_mode'] = 'edge_agent';
    $modeChange['agent_id'] = 'new-agent';
    $modeChange['agent_camera_id'] = 'new-camera';
    fixture(function (&$s) { $s['errors']['PUT /one/api/streams/cam'] = 503; });
    $retryMode = settingsForm(1, saveSettings($modeChange)[1]);
    $failedPatch = lastWrite()['body'];
    expectSettings($failedPatch['sourceType'] === 'push' && $failedPatch['push']['agentId'] === 'new-agent', 'Explicit mode change initializes publisher');
    expectSettings(array_intersect(['retentionDays', 'authMode', 'eventArchiveMaxBytes'], array_keys($failedPatch)) === [], 'Mode change does not send unrelated cached settings');
    fixture(function (&$s) { unset($s['errors']['PUT /one/api/streams/cam']); });
    $beforeWrites = count(writes());
    saveSettings($retryMode);
    expectSettings(count(writes()) === $beforeWrites + 1 && lastWrite()['body'] === $failedPatch, 'Failed mode change can retry with original baseline and new local identity');

    $pdo->exec("UPDATE cameras SET dvr_control_mode='read_only' WHERE id=2");
    fixture(function (&$s) { $s['errors']['GET /two/api/streams/cam'] = 403; });
    $readonly = settingsForm(2);
    $readonly['display_name'] = 'Read-only local title';
    $beforeWrites = count(writes());
    saveSettings($readonly);
    expectSettings(count(writes()) === $beforeWrites && $pdo->query('SELECT name FROM cameras WHERE id=2')->fetchColumn() === 'Read-only local title', 'Read-only local edit remains possible offline');

    expectSettings(DvrClient::cameraSettingsInputPatch(['name'=>'n'], ['enabled'=>true, 'source'=>'ignored']) === [], 'Unsupported aliases do not send stale fields');
    echo "camera settings: $checks checks passed\n";
} finally {
    foreach (array_reverse($processes) as $process) { proc_terminate($process); proc_close($process); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stateDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($stateDir);
}
