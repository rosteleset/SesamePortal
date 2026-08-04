# SesameAgent protocol v2

Этот документ описывает действующий clean-break API между SesameAgent и
SesameDVR. Protocol v1, enrollment password, query-auth, `X-Agent-Id`,
`X-Agent-Secret`, trusted RTMP/SRT path и автоматическая миграция не
поддерживаются.

Архитектурные причины и security model описаны в
[`sesame-agent-architecture.ru.md`](./sesame-agent-architecture.ru.md).

## 1. Роли и источники истины

- SesameAgent хранит локальную конфигурацию камер и desired state.
- SesameAgent всегда первым устанавливает исходящее TLS/WebSocket соединение.
- SesameDVR хранит pairing identity и явные bindings
  `agentId/localStreamId -> serverStreamName`.
- SesameDVR не создаёт камеру из inventory и не запускает поток сам.
- В `base` сервер принимает inventory, ONVIF events и publish requests.
- В `managed` сервер дополнительно может отправлять команды из allowlist.
- `managed` действует только до разрыва текущего WebSocket.

## 2. Версия и идентификаторы

Текущая версия:

```text
protocolVersion = 2
```

`agentId` генерируется один раз при создании fresh config:

```text
agt_<16..128 символов A-Z a-z 0-9 _ ->
```

`localStreamId` уникален только внутри агента. Глобальная идентичность потока:

```text
agentId + localStreamId + revision
```

## 3. Pairing

### 3.1. Создание invitation на SesameDVR

Admin API, защищённый обычной management-авторизацией SesameDVR:

```http
POST /api/agents/pairing-invitations
Content-Type: application/json

{
  "serverUrl": "https://dvr.example.com:8443",
  "ttlSeconds": 900,
  "displayName": "Home NanoPi"
}
```

Invitation не содержит fingerprint TLS-сертификата. Pairing привязан к
`serverUrl`, а сертификат проверяется стандартным TLS-клиентом по системной
цепочке CA, сроку действия и DNS name.

Ответ `201`:

```json
{
  "pairingInvitation": {
    "id": "pinv_...",
    "serverUrl": "https://dvr.example.com:8443",
    "pairingCode": "...",
    "expiresAt": "2026-07-25T12:15:00Z",
    "status": "active"
  }
}
```

`pairingCode` показывается один раз. В state store хранится только его hash.

### 3.2. Pairing с локального UI агента

Пользователь вставляет invitation в локальный UI SesameAgent. До отправки
`pairingCode` агент:

1. соединяется только с HTTPS `serverUrl`;
2. проверяет certificate chain, срок действия и DNS name через системное
   хранилище CA;
3. отклоняет redirect на другой origin;
4. только после этого отправляет запрос.

```http
POST /api/agent/v2/pair
Content-Type: application/json

{
  "protocolVersion": 2,
  "agentId": "agt_0123456789abcdef0123456789abcdef",
  "agentName": "home-nanopi-1",
  "pairingCode": "...",
  "version": "0.2.0",
  "capabilities": [
    "protocol_v2",
    "rtmp_push",
    "srt_push",
    "encrypted_cmaf_cbcs",
    "encrypted_cmaf_llhls_parts",
    "onvif_events"
  ],
  "hardware": {
    "arch": "arm64"
  }
}
```

`encrypted_cmaf_llhls_parts` означает поддержку encrypted media lifecycle
`Init -> Part* -> SegmentComplete`. Capability дополняет
`encrypted_cmaf_cbcs`: без неё агент может публиковать только завершённые
encrypted CMAF segments.

Ответ `201`:

```json
{
  "agentId": "agt_0123456789abcdef0123456789abcdef",
  "agentSecret": "...",
  "controlUrl": "wss://dvr.example.com:8443/agent/v2/connect",
  "protocolVersion": 2,
  "pairedAt": "2026-07-25T12:01:00Z",
  "serverTime": "2026-07-25T12:01:00Z"
}
```

Invitation атомарно становится `used`. Повторное использование запрещено.
Агент сохраняет `serverUrl`, `controlUrl`, `agentSecret` и `pairedAt` в config
с правами `0600`. TLS certificate renew и смена TLS private key не меняют
pairing state.

## 4. Control WebSocket

Endpoint:

```text
GET /agent/v2/connect
```

Обязательные headers:

```text
Upgrade: websocket
Connection: upgrade
X-Sesame-Agent-Protocol: 2
X-Sesame-Agent-Id: agt_...
X-Sesame-Agent-Secret: ...
X-Sesame-Agent-Version: 0.2.0
```

Перед upgrade SesameDVR проверяет лицензию, rate limit, protocol version,
agent status и constant-time hash `agentSecret`. Секреты в query string
запрещены. Одновременное второе соединение с тем же `agentId` отклоняется.
Агент снова выполняет стандартную TLS certificate/hostname validation для
сохранённого WSS URL.

После disconnect сервер:

- переводит runtime агента в `offline`;
- сбрасывает `managed`;
- отзывает все ephemeral publish grants и активные SRT routes агента.

## 5. Envelope

Все WebSocket сообщения являются JSON object:

```json
{
  "protocolVersion": 2,
  "type": "heartbeat",
  "messageId": "msg_...",
  "requestId": "req_...",
  "sentAt": "2026-07-25T12:02:00Z",
  "payload": {}
}
```

Разрешены только поля:

- `protocolVersion`;
- `type`;
- `messageId`;
- `requestId`;
- `sentAt`;
- `payload`.

Неизвестные поля, неизвестный `type`, неверная версия, слишком большое
сообщение и payload неправильного типа возвращают `protocol_error`.

## 6. Запуск сессии и inventory

Сервер первым отправляет:

```json
{
  "protocolVersion": 2,
  "type": "welcome",
  "payload": {
    "agentId": "agt_...",
    "connectionMode": "base",
    "heartbeatIntervalMs": 30000,
    "serverTime": "..."
  }
}
```

Агент отвечает `hello`. Сервер отправляет `state_resync_request`, после чего
агент передаёт полный `state_snapshot`.

Пример анонсированного потока:

```json
{
  "streamId": "garage",
  "revision": 4,
  "name": "Garage",
  "enabled": true,
  "desiredEnabled": true,
  "runtimeState": "publishing",
  "publishTransport": "srt",
  "videoCodec": "h264",
  "lastMediaAt": "2026-07-25T12:02:03Z",
  "encryptionRequired": true,
  "encryptionMode": "passthrough_cbcs",
  "encryptionPolicyDigest": "sha256:...",
  "recipientFingerprints": ["..."],
  "onvif": {
    "enabled": true,
    "running": true,
    "eventsQueued": 0
  }
}
```

Агент использует:

- `state_snapshot` для полного снимка;
- `stream_announce` для upsert одного потока;
- `stream_withdraw` для удаления потока из текущего inventory;
- `heartbeat` для liveness/status.

Inventory является runtime-снимком агента и не изменяет camera config
SesameDVR.

## 7. Server bindings

Admin API:

```http
PUT /api/agents/:agentId/bindings/:localStreamId
Content-Type: application/json

{
  "serverStreamName": "home-garage",
  "enabled": true,
  "approvedTransport": "srt",
  "retentionDays": 7,
  "encryptionRequired": true,
  "encryptionMode": "passthrough_cbcs",
  "encryptionPolicyDigest": "sha256:...",
  "approvedRecipients": [
    {
      "recipientKeyId": "...",
      "publicKeySpki": "...",
      "keyWrapAlgorithm": "rsa_oaep_sha256"
    }
  ],
  "replaceExistingBinding": false
}
```

Binding принимается только если:

- агент и анонсированный поток существуют;
- `approvedTransport` совпадает с inventory и push camera SesameDVR;
- clear/encrypted state совпадает с обеих сторон;
- digest и полный recipient allowlist совпадают;
- `retentionDays`, если указан, является положительным integer;
- server stream имеет `sourceType=push` и `publisherKind=agent`.

Один server stream может принадлежать только одному binding. Для осознанной
атомарной замены нужен `replaceExistingBinding: true`; прежний grant/session
сразу отзывается.

Удаление binding:

```http
DELETE /api/agents/:agentId/bindings/:localStreamId
```

также отзывает grant и активную публикацию.

## 8. Publish grant

Агент сам инициирует запрос только для локально `desiredEnabled` потока:

```json
{
  "protocolVersion": 2,
  "type": "publish_request",
  "messageId": "msg_...",
  "requestId": "pub_...",
  "sentAt": "...",
  "payload": {
    "agentId": "agt_...",
    "streamId": "garage",
    "revision": 4,
    "transport": "srt",
    "encryptionRequired": true,
    "encryptionMode": "passthrough_cbcs",
    "encryptionPolicyDigest": "sha256:...",
    "recipientFingerprints": ["..."],
    "currentPublishToken": "present-only-on-renewal"
  }
}
```

SesameDVR проверяет inventory, binding, revision, transport, encryption policy,
отсутствие конфликтующего publisher и возвращает `publish_grant` либо
`publish_denied`.

Grant содержит:

- точный `serverStreamName`;
- полный `publishUrl`;
- случайный scoped `publishToken`;
- `expiresAt`;
- неизменяемую encryption policy;
- при encrypted live дополнительный `livePublishUrl`;
- `lowLatencyHlsEnabled` и `lowLatencyHlsPartDurationMs` из server-side camera
  config.

Токен связан с `agentId`, `localStreamId`, `revision`, server stream,
transport, digest и recipients. Он не является management token и не даёт
доступа к другому потоку.

Для первоначального grant `currentPublishToken` не передаётся. За 30 секунд до
`expiresAt` агент отправляет renewal с текущим token. SesameDVR принимает его
только от того же authenticated agent для того же `localStreamId`, revision,
binding и уже активного publisher. При успехе срок продлевается у того же token,
активной push session и media/live SRT authorizations без перезапуска ingest.

RTMP проверяет токен при publish. SRT router проверяет подписанный stream id и
тот же scope до маршрутизации. Trusted bypass отсутствует.

Grant расходуется атомарно при успешной авторизации publisher. Один token
можно использовать ровно один раз для канала `media` и, если grant явно
содержит encrypted-live канал, ровно один раз для `live_sframe`. Renewal не
сбрасывает эти consumed-channel признаки. Повторная
авторизация того же channel тем же token отклоняется как
`publish_token_replayed`; SRT reconnect использует существующую authorization,
пока продлённый grant действителен. Новый token требуется после revoke, смены
revision/binding или потери server-side grant state.

При изменении revision агент прекращает старую публикацию и запрашивает новый
grant. Один и тот же clear segment нельзя отправлять повторно с начала после
ACK; retry относится к неподтверждённому chunk/grant.

Для `passthrough_cbcs + lowLatencyHlsEnabled=true` native publisher использует
wire lifecycle `Init -> Part* -> SegmentComplete`. Каждый frame имеет отдельный
ACK по `{sequence, kind, partIndex}` и остаётся в durable spool до подтверждения
completion всего segment.

## 9. Шифрование

Для `encryptionRequired=true` поддерживается:

```text
encryptionMode = passthrough_cbcs
```

Агент:

1. получает RTSP packets;
2. собирает CMAF в RAM;
3. шифрует video samples CBCS;
4. отправляет только encrypted payload;
5. оборачивает CEK отдельно для каждого разрешённого recipient через
   RSA-OAEP-SHA256;
6. передаёт `wrapped_content_key`, но не clear CEK.

SesameDVR принимает wrapped key только если binding, revision, stream name,
digest, recipient fingerprint, public SPKI и algorithm совпадают. Managed
команда не может отключить locally-required encryption или изменить recipient
allowlist.

## 10. Base и managed

Каждое соединение начинает работу в `base`.

Для managed mode:

1. пользователь локально включает remote management;
2. агент создаёт secret либо Argon2id hash пользовательского пароля;
3. администратор сохраняет тот же secret в credential store SesameDVR;
4. SesameDVR отправляет `management_auth`;
5. агент проверяет secret и отвечает `management_auth_result`;
6. только текущее соединение становится `managed`.

Разрешённые managed-команды:

- `list_cameras`;
- `scan_onvif`;
- `test_camera`;
- `get_snapshot`;
- `start_media`, `stop_media`, `restart_media`;
- `start_onvif_events`, `stop_onvif_events`;
- `update_camera_config`, `delete_camera_config`;
- `update_agent_config`;
- `collect_diagnostics`, `agent_log_tail`;
- `cancel_command`.

Каждая команда содержит `commandId`, `idempotencyKey`, `deadline`, `name` и
строго проверяемый `payload`. Mutation-команды требуют `reason`.
`delete_camera_config` дополнительно требует:

```json
{
  "streamId": "garage",
  "reason": "retired camera",
  "confirm": "delete garage"
}
```

Агент возвращает `command_ack` и `command_result`. Повтор
`idempotencyKey` не повторяет побочный эффект. Просроченная, отменённая или
неразрешённая команда не выполняется.

Даже в managed запрещено менять:

- paired server URL;
- local admin password;
- remote-management secret;
- locally required encryption;
- recipient allowlist;
- произвольные пути/команды процесса.

## 11. ONVIF events

Агент отправляет `onvif_events_batch` только для локально настроенных ONVIF
collectors. Каждое событие имеет стабильный `eventId`, `localStreamId`,
timestamp и нормализованный payload.

SesameDVR:

- связывает событие с `agent.<agentId>.<localStreamId>`;
- дедуплицирует `eventId`;
- возвращает `onvif_events_ack`;
- не использует ONVIF-событие для изменения конфигурации агента.

Очередь retry на стороне агента bounded и хранится согласно локальной
политике. Runtime retry/observability на SesameDVR не является источником
конфигурации.

## 12. Admin API SesameDVR

Все endpoint ниже требуют management auth и feature `edge_agent`, кроме
pairing endpoint и agent WebSocket, которые используют свой credential:

| Метод | Endpoint | Назначение |
|---|---|---|
| GET | `/api/agents` | Список paired agents |
| GET/PATCH/DELETE | `/api/agents/:id` | Карточка, имя/enabled, удаление |
| POST | `/api/agents/:id/revoke` | Отозвать pairing |
| GET/POST | `/api/agents/pairing-invitations` | Список/создание invitation |
| GET/DELETE | `/api/agents/pairing-invitations/:id` | Просмотр/удаление invitation |
| GET | `/api/agents/:id/streams` | Текущий inventory |
| GET | `/api/agents/:id/streams/:streamId/snapshot.jpg` | Managed snapshot |
| GET | `/api/agents/:id/streams/:streamId/onvif/events` | Принятые ONVIF events |
| GET/PUT/DELETE | `/api/agents/:id/bindings/:streamId` | Binding |
| PUT/DELETE | `/api/agents/:id/management-credential` | Credential store |
| POST | `/api/agents/:id/management-auth` | Авторизовать текущую сессию |
| GET/POST | `/api/agents/:id/commands` | История/отправка команды |
| GET | `/api/agents/:id/logs` | Bounded redacted log tail |
| POST | `/api/agents/:id/diagnostics` | Диагностика |

## 13. Локальный API SesameAgent

Локальный UI работает на `http://<LAN-IP>:8080`. После first-run setup
используется HttpOnly `SameSite=Strict` session cookie; изменяющие запросы
требуют CSRF token.

| Метод | Endpoint | Назначение |
|---|---|---|
| POST | `/api/setup` | Задать первый локальный admin password |
| POST | `/api/login`, `/api/logout` | Локальная сессия |
| GET | `/api/status` | Runtime, base/managed, inventory |
| GET/PUT | `/api/config` | Разрешённые общие локальные настройки |
| POST/DELETE | `/api/pairing` | Pair/reset pairing |
| GET/POST | `/api/remote-management` | Status/enable/disable/rotate/revoke |
| GET | `/api/logs`, `/api/diagnostics` | Redacted диагностика |
| POST | `/api/onvif/scan` | Локальный scan |
| GET/POST | `/api/cameras` | Список/создание локального потока |
| DELETE | `/api/cameras/:id` | Локальное удаление |
| POST | `/api/cameras/:id/test` | RTSP test |
| POST | `/api/cameras/:id/test_onvif` | ONVIF test |
| GET | `/api/cameras/:id/snapshot.jpg` | RAM-cached snapshot |
| POST | `/api/cameras/:id/start_media` и другие action | Локальное управление |

Секреты в public config/status/logs редактируются. Snapshot cache хранится в
RAM, а не на flash.

## 14. Ошибки и observability

Security-значимые события имеют отдельные counters/recent entries:

- pairing success/failure;
- connect/auth reject;
- managed auth/command accept/reject;
- publish grant/deny/token reject;
- SRT route reject/error;
- wrapped key accept/reject;
- protocol message reject.

Секреты, publish URL query, RTSP/ONVIF passwords, CEK и private keys в
observability не попадают.
