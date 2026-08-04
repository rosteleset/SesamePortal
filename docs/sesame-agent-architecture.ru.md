# Целевая архитектура SesameAgent

Статус документа: упрощённая целевая спецификация для последующего приведения
SesameAgent и SesameDVR к описанной модели.

Целевой protocol v2 является clean break. Совместимость с текущим тестовым
прототипом и автоматическая миграция его config не требуются.

Текущий реализованный протокол описан в
[`edge-agent-api.ru.md`](edge-agent-api.ru.md). Этот документ описывает не
текущее состояние, а требуемое поведение.

Реализация Agent поддерживается отдельно в
[`SesameWare/SesameAgent`](https://gitap.ru/SesameWare/SesameAgent); в
SesameDVR находятся server-side protocol, API и интеграция.

## Исходные требования

Ниже требования сохранены в исходном виде, без редакторских исправлений:

> 1. агент имеет два режима работы: базовый режим и режим удаленного управления.
>    в базовом режиме агент инициирует подключение к серверу (адрес сервера указан в конфиге). каждый агент должен иметь свой уникальный id. агент и сервер должны пройти процедуру pairing чтобы сервер доверял агенту, а агент серверу. система должна быть защищена от подмены агента для сервера и сервера для агента. агент должен иметь дополнительный секрет, знание которого необходимо серверу, чтобы агент принимал от сервера команды, какие позволяют серверу менять конфигурацию агента.
> 2. по умолчанию агент не разрешает серверу менять свою конфигурацию. сервер может принимать от агента лишь те потоки информации, что агент сам ему анонсирует. т.е. сервер может получить список потоков и их состояние, но самостоятельно менять статус включен-выключен запущен-остановлен может только сам агент.
> 3. агент должен предоставлять пользователю через UI возможность настраивать доступные ему видео-потоки и ONVIF устройства, сканировать локальную сеть в поиске камер.
> 4. агент не может сам управлять и менять конфигурацию сервера ни в каком режиме.
> 5. агент локально может делать repair для потоков перед их отправкой на сервер, а также отрезать лишние дорожки.
> 6. также в будущем агент должен обеспечивать возможность обеспечивать обработку syslog от устройств

## 1. Основная модель

Используется один код, один executable и один artifact SesameAgent.

У агента есть два состояния:

1. `base` — базовый режим, включённый по умолчанию;
2. `managed` — текущее соединение дополнительно авторизовано для удалённого
   управления.

`managed` не является отдельной сборкой. После разрыва соединения агент снова
находится в `base`, пока SesameDVR повторно не предъявит management secret.

```text
SesameAgent
  -> HTTPS/WSS
  -> SesameDVR

base:
  agent announces state and publishes locally enabled data

managed:
  base capabilities
  + SesameDVR may send predefined management commands
```

## 2. Три разных credential

Необходимо разделить три задачи:

| Credential | Для чего нужен | Кто хранит |
|---|---|---|
| `pairingCode` | Одноразовая первичная привязка | Временно агент и сервер |
| `agentSecret` | Агент доказывает свою identity SesameDVR | Агент; hash на сервере |
| `managementSecret` | Сервер получает право управлять агентом | Сервер; hash на агенте |

Эти credentials нельзя заменять одним общим паролем.

Playback token, publish token и management token SesameDVR также не должны
использоваться вместо них.

## 3. Идентичность агента

При первом запуске агент локально создаёт случайный стабильный `agentId`:

```text
agt_<random-128-bit-id>
```

`agentId`:

- не меняется после reboot или update;
- не зависит от IP и MAC address;
- хранится локально;
- уникален для одной установки;
- не является секретом.

Клонирование config вместе с `agentId` и `agentSecret` запрещено. SesameDVR
должен обнаруживать одновременное подключение двух экземпляров с одной
identity и отражать конфликт в журнале.

## 4. Pairing

### 4.1. Создание приглашения

Администратор SesameDVR создаёт одноразовое приглашение:

```json
{
  "serverUrl": "https://dvr.example.com:8443",
  "pairingCode": "<random-one-time-code>",
  "expiresAt": "2026-07-25T12:00:00Z"
}
```

Приглашение можно передать как JSON, QR-код или строку.

`pairingCode`:

- случайный;
- одноразовый;
- действует ограниченное время;
- удаляется сервером сразу после успешного pairing.

### 4.2. Действия агента

Пользователь импортирует приглашение в локальный UI агента.

Агент:

1. подключается к `serverUrl` по HTTPS;
2. проверяет TLS certificate chain, срок действия сертификата и DNS name через
   системное хранилище доверенных CA;
3. отправляет `agentId`, `pairingCode`, имя, version и capabilities;
4. получает случайный `agentSecret`;
5. сохраняет `serverUrl`, `controlUrl`, `agentSecret` и время pairing.

SesameDVR сохраняет:

- `agentId`;
- hash `agentSecret`;
- имя и capabilities;
- время pairing.

После pairing:

- сервер узнаёт агента по `agentId + agentSecret`;
- агент узнаёт сервер по сохранённому HTTPS/WSS URL и стандартной проверке
  certificate chain и DNS name;
- повторно использовать pairing code нельзя.

Pairing не привязан к конкретному TLS-сертификату, его serial number или SPKI.
Штатное обновление сертификата и смена TLS private key не требуют повторного
pairing, если URL сервера и доверенная TLS-цепочка остаются корректными.

## 5. Базовое подключение

Агент всегда сам открывает:

```text
wss://dvr.example.com:8443/agent/v2/connect
```

Перед подключением агент проверяет:

- TLS certificate chain;
- DNS name;
- срок действия и назначение сертификата.

В заголовках соединения передаются:

```text
X-Sesame-Agent-ID: <agentId>
X-Sesame-Agent-Secret: <agentSecret>
```

SesameDVR проверяет hash секрета и создаёт базовую control session.

`agentSecret`:

- не передаётся в URL;
- не выводится в лог;
- может быть отозван или ротирован;
- хранится на агенте в файле с правами `0600`;
- на сервере хранится только как password hash.

## 6. Базовый режим

В базовом режиме source of truth для локальной конфигурации — агент.

Агент может отправлять серверу:

- heartbeat и технический status;
- список локально настроенных потоков;
- состояние `enabled`, `running`, `stopped`, `error`;
- codecs, resolution, bitrate и диагностическую причину ошибки;
- список локально настроенных ONVIF sources без credentials;
- ONVIF-события, отправку которых пользователь включил локально;
- запрос credentials для публикации потока;
- media publish status;
- в будущем — разрешённые локальной настройкой syslog events.

SesameDVR может отправлять агенту:

- `welcome`;
- acknowledgement;
- запрос повторно прислать текущий state snapshot;
- publish URL и краткоживущий publish token;
- отказ в публикации;
- backpressure;
- protocol error;
- уведомление о завершении server session.

В базовом режиме SesameDVR не может:

- включать или выключать поток;
- запускать, останавливать или перезапускать media;
- добавлять, изменять или удалять камеры;
- запускать сканирование локальной сети;
- менять ONVIF config;
- запрашивать произвольный snapshot;
- читать локальные логи;
- менять repair или track selection;
- запускать update агента.

## 7. Анонс потоков

После подключения и после любого локального изменения агент отправляет
`stream_announce`:

```json
{
  "type": "stream_announce",
  "stream": {
    "id": "garage-main",
    "name": "Гараж",
    "revision": 17,
    "enabled": true,
    "state": "running",
    "videoCodec": "h264",
    "audioCodec": "aac",
    "width": 1920,
    "height": 1080,
    "fps": 25,
    "publishTransport": "srt",
    "encryptionRequired": true
  }
}
```

Анонс не содержит:

- RTSP URL;
- username/password камеры;
- полный ONVIF URL с credentials;
- локальные filesystem paths;
- management secret.

SesameDVR может принять анонс и связать его со своим потоком. Эта связь меняет
только server-side config и не меняет config агента.

## 8. Публикация media

### 8.1. Запрос от агента

В базовом режиме агент самостоятельно решает, какой локально enabled поток
должен работать. Команда `start_media` со стороны SesameDVR для этого не нужна.

Агент отправляет:

```json
{
  "type": "publish_request",
  "streamId": "garage-main",
  "transport": "srt",
  "encryptionMode": "passthrough_cbcs"
}
```

SesameDVR проверяет:

- агент paired и authenticated;
- такой `streamId` был анонсирован этим агентом;
- поток связан с server-side stream;
- transport и encryption разрешены;
- хватает license limit;
- нет конфликтующего publisher.

При успехе сервер возвращает:

```json
{
  "type": "publish_grant",
  "streamId": "garage-main",
  "url": "srt://dvr.example.com:10080?...",
  "publishToken": "<short-lived-token>",
  "expiresAt": "2026-07-25T12:10:00Z"
}
```

Publish token привязан к:

- `agentId`;
- `streamId`;
- server stream name;
- transport;
- сроку действия.

Агент заранее запрашивает новый token до истечения старого.

Token является single-use capability. SesameDVR атомарно расходует его при
успешной авторизации конкретного publisher channel. Основной `media` и
опциональный `live_sframe` являются отдельными явно перечисленными channels;
каждый из них можно открыть этим grant только один раз. Replay того же
token/channel отклоняется, а reconnect требует нового `publish_request`.

### 8.2. RTMP

```text
rtmp://dvr.example.com/push/<streamName>?token=<publishToken>
```

SesameDVR проверяет token до принятия publisher.

### 8.3. SRT

SRT должен использовать тот же индивидуальный publish token:

```text
srt://dvr.example.com:10080?mode=caller&streamid=<encoded-stream-and-token>
```

SRT acceptor сначала проверяет stream name и token и только потом принимает
publisher. Общий `SESAME_DVR_SRT_PASSPHRASE` можно оставить как дополнительное
шифрование транспорта, но не как авторизацию конкретного агента.

Trusted connect path отсутствует: любой SRT publisher проходит ту же scoped
token authorization, что и RTMP.

### 8.4. Шифрование содержимого

Publish authorization и media encryption — разные механизмы.

Если агент локально настроен только на защищённую публикацию, SesameDVR не
может заставить его перейти на clear mode. Несовместимый `publish_grant`
отклоняется агентом.

Для end-to-end encrypted stream source of truth для encryption policy всегда
находится на агенте. Локально защищённая часть stream config включает:

```json
{
  "encryptionRequired": true,
  "archiveEncryption": {
    "mode": "passthrough_cbcs",
    "scheme": "sample_aes_cbcs",
    "cryptBlocks": 1,
    "skipBlocks": 9,
    "recipients": [
      {
        "recipientKeyId": "64-lowercase-hex-sha256-spki",
        "publicKeySpki": "base64-der-spki",
        "keyWrapAlgorithm": "rsa_oaep_sha256"
      }
    ]
  }
}
```

`recipients` является локально защищённым allowlist, а не рекомендацией
SesameDVR. Добавление, удаление или замена recipient public key требует
локального подтверждения пользователя в UI агента. Это правило действует и в
`managed`.

Private key:

- создаётся в браузере локального пользователя либо на другом доверенном
  устройстве;
- не передаётся агенту или SesameDVR;
- не хранится в config агента;
- нужен только владельцу для расшифрования архива и live video.

Агент использует recipient public keys для wrapping случайных CEK. SesameDVR
может сохранить encrypted fragments, KID и wrapped CEK, но не получает clear
CEK.

В `stream_announce` и `publish_request` агент передаёт `encryptionRequired`,
режим шифрования и recipient fingerprints. Public SPKI можно передавать
отдельным versioned policy message после pairing; private key не передаётся
никогда.

Для первой версии server-side stream policy и локальная agent policy должны
иметь одинаковый режим и одинаковый набор recipient fingerprints. SesameDVR
либо принимает policy целиком, либо отклоняет `publish_request`. Он не
возвращает агенту изменённый recipient list.

Перед началом media агент проверяет `publish_grant`:

- clear grant запрещён при `encryptionRequired=true`;
- более слабый или неизвестный encryption mode запрещён;
- добавленный SesameDVR recipient запрещён;
- отсутствие ожидаемого recipient запрещено;
- несовпадение stream, agent, transport или срока token запрещено.

Команда `start_media` в `managed` меняет только локальный desired state. Она не
может передать новую encryption policy. Media pipeline всегда читает локально
подтверждённую policy.

Шифрование SRT transport не заменяет content encryption. Требование
`encryptionRequired=true` означает, что агент должен передавать уже
зашифрованный CMAF, даже если SRT также использует passphrase.

## 9. Удалённое управление

### 9.1. Включение

По умолчанию:

```json
{
  "remoteManagementEnabled": false
}
```

Пользователь локально включает удалённое управление. Агент создаёт случайный
`managementSecret` не менее 256 бит и показывает его один раз.

Пользователь передаёт secret администратору SesameDVR. На агенте сохраняется
только hash. На SesameDVR secret хранится как защищённый credential и не
показывается после сохранения.

Обычный короткий человеческий пароль не рекомендуется. Если UI позволяет его
задать, агент должен хранить Argon2id hash и применять rate limit.

### 9.2. Авторизация текущего соединения

После создания базовой session SesameDVR отправляет:

```json
{
  "type": "management_auth",
  "secret": "<managementSecret>"
}
```

Сообщение передаётся только внутри уже authenticated WSS. Агент:

1. проверяет, что remote management локально включён;
2. проверяет secret по сохранённому hash;
3. применяет rate limit;
4. помечает только текущее соединение как `managed`;
5. пишет событие в локальный audit log.

После disconnect право управления теряется. При следующем подключении SesameDVR
должен снова выполнить `management_auth`.

В первой версии не нужны отдельные scopes, management key pairs, подписанные
command envelopes и отдельные management session tokens. Один правильный
management secret разрешает полный фиксированный набор management-команд.

### 9.3. Централизованная проверка

До вызова любого handler сообщение проходит один общий gate:

```text
base message       -> allowed in base
management command -> requires managed connection
unknown command    -> denied
```

Нельзя ограничиваться проверками внутри отдельных handlers. Новая команда
должна быть явно зарегистрирована как `base` или `management`; значение по
умолчанию — `deny`.

Каждая команда имеет:

- `commandId`;
- `idempotencyKey`;
- deadline;
- строго проверяемую JSON schema.

Повтор команды с тем же idempotency key не должен повторять побочный эффект.

## 10. Команды удалённого управления

После успешного `management_auth` SesameDVR может отправлять:

| Команда | Назначение |
|---|---|
| `list_cameras` | Получить расширенный локальный список |
| `scan_onvif` | Запустить локальный ONVIF scan |
| `test_camera` | Проверить RTSP/ONVIF |
| `get_snapshot` | Получить snapshot |
| `start_media` | Запустить локальный media pipeline |
| `stop_media` | Остановить pipeline |
| `restart_media` | Перезапустить pipeline |
| `start_onvif_events` | Запустить ONVIF collector |
| `stop_onvif_events` | Остановить collector |
| `update_camera_config` | Изменить локальную камеру |
| `delete_camera_config` | Удалить локальную камеру |
| `update_agent_config` | Изменить разрешённые общие настройки |
| `collect_diagnostics` | Собрать диагностику |
| `agent_log_tail` | Получить ограниченный tail логов |
| `update_agent` | Установить подписанное обновление |

Даже в `managed` серверу всегда запрещено:

- менять или читать `managementSecret`;
- включать remote management;
- менять paired server URL;
- менять локальный admin password;
- отключать локально обязательное шифрование;
- менять `archiveEncryption.mode`, если он локально защищён;
- добавлять, удалять или заменять recipient public keys;
- получать recipient private keys или clear CEK;
- выполнять shell-команды;
- устанавливать неподписанный artifact.

## 11. Локальный UI

UI агента должен позволять локальному пользователю:

### Подключение

- указать SesameDVR URL;
- импортировать pairing invitation;
- видеть `agentId`;
- видеть pairing и connection status;
- видеть paired server URL и состояние TLS;
- отозвать pairing.

### Удалённое управление

- включить или выключить remote management;
- сгенерировать новый management secret;
- отозвать старый secret;
- видеть, авторизовано ли текущее соединение как `managed`;
- видеть время последней management-авторизации;
- просматривать audit log удалённых команд.

### Потоки

- добавить, изменить и удалить RTSP source;
- включить или выключить поток;
- запустить, остановить и перезапустить его;
- выбрать RTSP TCP/UDP;
- проверить подключение;
- выбрать publish transport;
- включить обязательное шифрование;
- локально создать recipient key pair или импортировать public key;
- сохранить и повторно проверить private key до включения шифрования;
- видеть полный recipient fingerprint;
- добавлять и отзывать recipients только после локального подтверждения;
- видеть, что encryption policy защищена от удалённого изменения;
- настроить repair;
- выбрать передаваемые дорожки;
- видеть codecs, bitrate, state и ошибки.

### ONVIF

- сканировать выбранную локальную сеть;
- добавить устройство вручную;
- настроить credentials;
- выбрать media profile;
- проверить ONVIF services;
- включить сбор событий;
- связать ONVIF device с локальным stream.

Credentials камер остаются на агенте и не отображаются SesameDVR.

## 12. Media repair и выбор дорожек

Локальный pipeline:

```text
RTSP
  -> demux
  -> repair
  -> track filter
  -> remux/encryption
  -> RTMP or SRT publish
```

Repair может включать:

- исправление отрицательных или немонотонных DTS/PTS;
- нормализацию time base;
- ожидание keyframe перед началом publish;
- восстановление H.264 SPS/PPS и H.265 VPS/SPS/PPS;
- отбрасывание повреждённых packets;
- reconnect с backoff;
- устранение timestamp discontinuity после reconnect.

Track filter позволяет:

- оставить одну video track;
- отключить audio;
- выбрать одну audio track;
- удалить metadata, subtitles и data tracks.

По умолчанию используется remux без decode/encode. Транскодирование включается
только локальной явной настройкой.

## 13. ONVIF events

ONVIF collectors работают локально на агенте.

Агент:

- хранит ONVIF credentials;
- поддерживает PullPoint/poll;
- присваивает событиям стабильные ID и sequence;
- отправляет batches на SesameDVR;
- повторяет неподтверждённые batches;
- использует ограниченную RAM-очередь;
- сообщает число потерянных событий при переполнении.

В базовом режиме отправляются события только по устройствам, которые локальный
пользователь явно включил.

## 14. Будущий syslog

В будущем агент получает отдельный выключенный по умолчанию syslog module.

Планируемая поддержка:

- RFC 3164 и RFC 5424;
- UDP и TCP;
- TLS как отдельная опция;
- ограничение source CIDR;
- filters по facility и severity;
- redaction;
- bounded RAM queue;
- batch delivery с acknowledgement;
- rate limit и counters dropped messages.

По умолчанию syslog queue не записывается на flash.

## 15. Агент не управляет SesameDVR

`agentSecret` принимается только endpoint, предназначенными для агента:

- connect;
- announcements;
- publish request;
- ONVIF/syslog delivery.

Он не принимается:

- admin API;
- server config API;
- stream mutation API;
- user/license/update API;
- management API.

Анонс агента является данными, а не командой. Он не должен автоматически
менять server config, если на SesameDVR явно не включена соответствующая
server-side policy.

## 16. Хранение и журнал

На flash хранятся только:

- `agentId`;
- `agentSecret`;
- paired server URL и control URL;
- hash `managementSecret`;
- локальный config;
- локальный admin password hash.

В RAM хранятся:

- признак `managed` текущего соединения;
- publish tokens;
- media statistics;
- snapshot cache;
- ONVIF/syslog queues;
- command dedup cache.

Требования:

- secret-файлы `0600`;
- atomic config update;
- redaction URL, passwords и tokens;
- bounded logs с rotation;
- частые counters не записываются на flash.

Audit log фиксирует:

- pairing и revoke;
- успешный и неуспешный `management_auth`;
- каждую удалённую management-команду;
- изменение local config без секретов;
- изменение encryption policy и recipient allowlist;
- отклонение команды или `publish_grant`, пытавшихся ослабить шифрование;
- publish authorization failures.

## 17. Пример желаемого поведения: домашняя RTSP-камера

В этом примере пользователь установил SesameAgent в домашней сети и подключил
к нему RTSP-камеру. SesameDVR должен записывать архив, зашифрованный на агенте
до отправки в Интернет.

### 17.1. Общая подготовка

1. На SesameDVR включены license features для agents, encryption и archive.
2. SesameDVR имеет доступный SRT listener.
3. Администратор SesameDVR создаёт одноразовое pairing invitation.
4. Пользователь задаёт пароль локального UI SesameAgent, импортирует invitation
   и сверяет адрес SesameDVR. Агент проверяет сертификат через системную цепочку
   доверия и DNS name.
5. После pairing агент сохраняет `agentId`, `agentSecret`, server/control URL,
   затем сам открывает WSS control connection. Обновление TLS-сертификата не
   меняет pairing state.
6. Владелец архива создаёт RSA-OAEP key pair в локальном UI агента либо на
   другом доверенном устройстве.
7. Владелец сохраняет private key минимум в двух безопасных offline-копиях.
   Агент и SesameDVR получают только public SPKI и его fingerprint.

Pairing, publish authorization и content encryption остаются независимыми:

- pairing устанавливает взаимное доверие агента и SesameDVR;
- publish token разрешает конкретному агенту опубликовать конкретный stream;
- recipient public key защищает CEK и определяет, кто сможет расшифровать video.

### 17.2. Вариант A: только базовый режим

1. Пользователь в локальном UI агента добавляет RTSP source и credentials.
2. Пользователь проверяет RTSP, выбирает TCP/UDP, tracks, repair и SRT transport.
3. Пользователь включает `encryptionRequired=true`,
   `archiveEncryption.mode=passthrough_cbcs` и добавляет recipient public key.
4. Пользователь локально включает поток. RTSP и ONVIF credentials не покидают
   агент.
5. Агент отправляет SesameDVR `stream_announce` без RTSP URL и credentials:

   ```text
   agentId: agt_home
   streamId: garage-main
   codec: h264
   transport: srt
   encryptionRequired: true
   recipientKeyIds: [...]
   state: waiting_for_publish
   ```

6. Администратор видит announced stream и создаёт либо выбирает server-side
   stream.
7. Администратор связывает пару `{agentId, streamId}` с server stream name,
   задаёт retention, storage и archive enabled.
8. Администратор добавляет в server-side policy тот же recipient public key и
   разрешает только `SRT + passthrough_cbcs`.
9. Агент сам отправляет `publish_request`. Команда `start_media` со стороны
   SesameDVR не используется.
10. SesameDVR проверяет binding, license, capacity, transport, encryption mode
    и точное совпадение recipient fingerprints.
11. SesameDVR возвращает short-lived `publish_grant`, связанный с agent, local
    stream, server stream и SRT transport.
12. Агент проверяет grant, подключается к камере, выполняет repair/remux,
    создаёт CMAF в RAM, шифрует samples по CBCS и отправляет media по SRT.
13. Агент передаёт wrapped CEK по authenticated WSS и повторяет доставку до
    acknowledgement.
14. SesameDVR записывает encrypted CMAF и wrapped CEK. Он не получает clear
    video, clear CEK или private key.
15. После reboot или разрыва сети агент сам восстанавливает RTSP и WSS,
    запрашивает новый publish token и продолжает публикацию.

В базовом режиме администратор SesameDVR не может сканировать домашнюю сеть,
менять камеру, запрашивать snapshot или запускать и останавливать pipeline.

### 17.3. Вариант B: managed

1. Выполняются pairing и key onboarding из общей подготовки.
2. Пользователь локально включает remote management.
3. Агент создаёт `managementSecret`, показывает его один раз и хранит только
   hash.
4. Пользователь передаёт secret администратору по отдельному защищённому каналу.
5. SesameDVR сохраняет secret в credential store, не возвращает его в UI/API и
   после базового WSS connect отправляет `management_auth`.
6. Агент переводит только текущее соединение в `managed` и пишет audit event.
7. Пользователь заранее фиксирует локальный security floor:
   `encryptionRequired=true` и разрешённые recipient public keys.
8. Администратор через SesameDVR может выполнить ONVIF scan, добавить или
   проверить камеру, выбрать profile, tracks, repair, SRT и изменить desired
   state.
9. Если администратор вводит RTSP/ONVIF credentials через SesameDVR, он
   неизбежно получает к ним доступ. Чтобы не раскрывать credentials
   администратору, пользователь добавляет камеру локально, а managed-доступ
   используется только для разрешённых runtime operations.
10. Команда `start_media` изменяет локальный desired state, но не содержит
    encryption policy.
11. Агент использует тот же agent-driven `publish_request` и получает тот же
    short-lived publish token, что и в базовом режиме.
12. Любая команда или grant, пытающиеся отключить CBCS, заменить recipient или
    добавить server-owned key, отклоняются агентом и попадают в audit log.
13. Media encryption, SRT delivery, wrapped CEK и запись архива выполняются так
    же, как в базовом режиме.
14. После disconnect соединение снова имеет состояние `base`. Для новых
    management-команд SesameDVR повторяет `management_auth`, но локально enabled
    media продолжает восстанавливаться без server command.
15. Пользователь может локально отключить remote management или отозвать
    `managementSecret`, не разрывая pairing и обычную публикацию.

### 17.4. Что проверяют пользователь и администратор

На агенте должны отображаться:

- paired server URL и состояние TLS;
- connection mode `base` или `managed`;
- локальный desired/runtime state потока;
- `encryptionRequired`, `passthrough_cbcs` и recipient fingerprints;
- SRT publish state, token renewal и время последнего media packet;
- ошибки policy mismatch и audit удалённых команд.

На SesameDVR должны отображаться:

- agent и announced local stream;
- server-side binding;
- publisher authenticated;
- archive writing;
- encryption mode `passthrough_cbcs`;
- recipient fingerprints и состояние wrapped-key delivery;
- отсутствие clear fallback.

Если для server-side stream включён LL-HLS, publish grant дополнительно
фиксирует `lowLatencyHlsEnabled=true` и `lowLatencyHlsPartDurationMs`. Agent
шифрует каждый завершённый CMAF fragment и отправляет
`Init -> Part* -> SegmentComplete`; receiver публикует parts в native
`LiveHlsEdge` до durable archive commit. BEAM не принимает media/part events.

Контрольная проверка:

1. Сохранённый fragment не воспроизводится как обычный clear MP4.
2. Неверный private key отклоняется player.
3. Правильный private key расшифровывает live/archive только на стороне
   авторизованного player.
4. Clear `publish_grant` отклоняется агентом.
5. В `base` команды `start_media`, `scan_onvif` и `update_camera_config`
   отклоняются без побочного эффекта.
6. После revoke `managementSecret` поток продолжает публиковаться, но
   management-команды больше не выполняются.

## 18. Изменения для реализации целевой схемы

### 18.1. Clean break вместо совместимости

Новый протокол получает обязательную версию `2`. Реализация не должна
поддерживать текущий прототип как второй режим и не должна содержать
автоматическую миграцию его config.

Из SesameDVR и SesameAgent удаляются:

- `/agent/v1/connect`;
- enrollment по заранее созданному agent record и общему password;
- `/api/agents/enroll` в текущем значении;
- legacy headers `X-Agent-Id` и `X-Agent-Secret`;
- передача `agentSecret` через URL или query parameters;
- сообщения `camera_snapshot` и камеры внутри общего `status`;
- автоматический `restore_runtime_on_connect`;
- server-driven `start_media` как обычный способ запуска agent stream;
- SRT `trusted_connect`, `trusted_heartbeat` и `trusted_finish`;
- SRT Stream ID без индивидуального publish token;
- generic remote config patch без field-level authorization;
- compatibility aliases старых JSON fields и message types.

SesameAgent config получает обязательный `schemaVersion: 2`. Старый config не
интерпретируется частично: агент завершает startup с понятной ошибкой и
предлагает выполнить локальный reset/reconfiguration. Для единственного
тестового прототипа используется ручной cutover из раздела 18.8.

SesameDVR принимает только protocol v2. Поддерживать одновременно v1 и v2,
определять поведение по capability либо оставлять feature flag для legacy
агента не нужно.

### 18.2. Новый control protocol v2

Control endpoint:

```text
wss://dvr.example.com:8443/agent/v2/connect
```

После pairing используются только:

```text
X-Sesame-Agent-ID: <agentId>
X-Sesame-Agent-Secret: <agentSecret>
X-Sesame-Agent-Protocol: 2
X-Sesame-Agent-Version: <artifact-version>
```

Каждое protocol message содержит:

```json
{
  "protocolVersion": 2,
  "type": "stream_announce",
  "messageId": "msg_<random>",
  "sentAt": "2026-07-25T12:00:00Z"
}
```

Для mutation-команд дополнительно обязательны `commandId`, `idempotencyKey` и
`deadline`. Для request/reply сообщений используется `requestId`.

Agent-to-server base messages:

- `hello`;
- `heartbeat`;
- `state_snapshot`;
- `stream_announce`;
- `stream_withdraw`;
- `publish_request`;
- `publish_status`;
- `onvif_events_batch`;
- `wrapped_content_key`;
- `wrapped_live_key`;
- `command_ack`;
- `command_result`;
- `protocol_error`.

Server-to-agent base messages:

- `welcome`;
- `heartbeat_ack`;
- `state_resync_request`;
- `publish_grant`;
- `publish_denied`;
- `publish_revoke`;
- `onvif_events_ack`;
- `wrapped_content_key_ack`;
- `wrapped_live_key_ack`;
- `backpressure`;
- `protocol_error`;
- `server_shutdown`.

`management_auth` является отдельным повышением полномочий текущего
authenticated connection. После него SesameDVR может отправлять только команды
из фиксированного management registry.

Для каждого message type задаётся отдельная JSON schema. Неизвестный type,
неизвестное поле в security-sensitive payload или неверная версия протокола
отклоняются. Нельзя десериализовать command payload прямо в общий `Config`.

### 18.3. Изменения в SesameAgent

#### Identity, pairing и TLS

В [`config.go`](https://gitap.ru/SesameWare/SesameAgent/src/branch/main/internal/agent/config.go)
необходимо:

- генерировать стабильный случайный `agentId` при первом startup;
- добавить `schemaVersion`;
- заменить свободные `serverUrl/controlUrl` на paired server record;
- хранить `agentSecret`, server/control URL и `pairedAt`;
- добавить `remoteManagementEnabled` и hash `managementSecret`;
- хранить local admin password только как Argon2id hash;
- не возвращать secrets через `PublicConfig`;
- записывать config атомарно с `fsync` файла и родительской directory;
- сохранять secret-bearing config с mode `0600`.

В enrollment client вместо текущего password enrollment реализуется импорт
pairing invitation и `POST /api/agent/v2/pair`. HTTP pairing и WSS используют
общий TLS transport, который проверяет certificate chain, срок действия и DNS
name через системное хранилище CA. Pairing state не содержит TLS fingerprint и
не меняется при renew сертификата или смене TLS private key.

#### Central command gate

В [`control.go`](https://gitap.ru/SesameWare/SesameAgent/src/branch/main/internal/agent/control.go)
перед command queue добавляется единый registry:

```text
message type -> base | management | deny
```

Все существующие remote commands классифицируются как `management`. Новая
команда без явной регистрации получает `deny`.

Connection state содержит `base` или `managed`. Оно создаётся как `base`,
становится `managed` только после успешного `management_auth` и немедленно
сбрасывается после disconnect.

Проверка management secret:

- для случайного 256-bit secret выполняется по SHA-256 hash с constant-time
  compare;
- для опционального human password используется Argon2id;
- использует rate limit и задержку после ошибок;
- не раскрывает причину mismatch;
- пишет audit event;
- выполняется до постановки command в queue.

Отклонённая команда не должна менять config, desired state, media manager,
ONVIF manager или filesystem.

#### Local desired state и reconciler

В [`agent.go`](https://gitap.ru/SesameWare/SesameAgent/src/branch/main/internal/agent/agent.go)
добавляется постоянный local reconciler. Он запускается:

- после загрузки config;
- после pairing/reconnect;
- после локального изменения stream;
- после разрешённой management-команды;
- после завершения media runner;
- перед истечением publish grant.

Для каждого локального stream хранятся отдельно:

- `desiredEnabled`;
- `runtimeState`;
- monotonic `revision`;
- выбранный source/profile/tracks/repair;
- publish transport;
- локально защищённая encryption policy;
- последнее состояние announce/request/grant.

Reconciler сам отправляет `stream_announce` и `publish_request`. Он не ждёт
`start_media` от SesameDVR. В `managed` команда `start_media` меняет только
`desiredEnabled`, после чего используется тот же reconciler.

После reboot locally enabled streams автоматически возвращаются в
`waiting_for_binding`, `waiting_for_grant` или `publishing`.

#### Publish state machine

Для каждого stream реализуется:

```text
disabled
  -> announced
  -> waiting_for_binding
  -> requesting_grant
  -> publishing
  -> renewing
  -> reconnect_backoff
```

`publish_request` содержит:

- `agentId`;
- local `streamId` и `revision`;
- requested transport;
- codec/track summary;
- `encryptionRequired`;
- canonical `encryptionPolicyDigest`;
- recipient fingerprints;
- текущий `currentPublishToken` только при renewal;
- nonce/request ID.

Агент проверяет, что `publish_grant` относится к текущим agent, stream,
revision, transport и policy digest. Grant из старой revision либо grant,
ослабляющий encryption, отклоняется.

Publish token хранится только в RAM, redacted в логах и никогда не записывается
в config. До expiry агент доказывает владение текущим token и продлевает его без
перезапуска media-процесса. Renewal одновременно продлевает active push session
и обе SRT authorizations (`media`, `live_sframe`). Новый token запрашивается
после revoke, смены revision/binding или потери server-side grant state.

#### Encryption policy

`CameraConfig` заменяется versioned local stream model. Encryption fields
выделяются в отдельную структуру, которую generic management patch не может
изменить.

Локальный key onboarding:

1. Browser UI генерирует RSA-OAEP 3072 key pair либо импортирует public SPKI.
2. Private key скачивается пользователем и повторно проверяется в browser.
3. Backend агента получает только public SPKI, fingerprint и algorithm.
4. Изменение recipient allowlist требует локальную authenticated session и
   явное подтверждение.
5. После изменения policy увеличивается stream revision и начинается новый CEK
   epoch.

Native encrypted publisher остаётся единственным рабочим path для
`passthrough_cbcs`. Отсутствие publisher, unsupported codec или policy mismatch
переводят stream в error; clear fallback запрещён.

Wrapped key messages связываются с publish request, local stream revision,
server binding, KID и recipient fingerprint. До acknowledgement они повторяются
идемпотентно.

#### Local UI и local API

В [`server.go`](https://gitap.ru/SesameWare/SesameAgent/src/branch/main/internal/agent/server.go)
необходимо добавить:

- onboarding первого запуска;
- import pairing invitation;
- отображение paired server URL, TLS trust mode и pairing status;
- revoke/reset pairing;
- remote management enable/disable;
- generate/rotate/revoke management secret;
- локальный audit log management attempts;
- stream desired/runtime state;
- key onboarding и recipient allowlist;
- protected-policy indicator;
- publish binding/grant/status diagnostics.

Local API разделяется на read и mutation routes. Все mutation routes требуют
локальную admin session и CSRF protection. Secrets, private keys и clear CEK не
возвращаются ни одним endpoint. JSON-ответы local API отправляются с
`Cache-Control: no-store`; session cookie имеет `HttpOnly`, `SameSite=Strict` и
`Secure` при HTTPS. Redaction URL и диагностических логов регистронезависимо
закрывает pairing/publish/session tokens, agent/management secrets, camera
credentials, CEK, content/wrapped/private keys и `Authorization`.

Snapshot cache, command dedup, publish tokens и ONVIF/syslog delivery queues
остаются bounded и находятся в RAM. Текущий persistent spool не используется
как неявный default для flash-based устройства.

### 18.4. Изменения в SesameDVR

#### Pairing API и data model

В [`config.ex`](../lib/sesame_dvr/config.ex) текущий `Config.Agent` необходимо
заменить v2 model:

- `id`;
- `name`;
- `enabled`;
- `secret_hash`;
- `paired_at`;
- `revoked_at`;
- `protocol_version`;
- `version`;
- `capabilities`;
- `management_credential_ref`;
- runtime status, не сохраняемый как agent config.

`password_hash` старого enrollment удаляется.

Добавляется pairing invitation store:

- случайный one-time code;
- hash code;
- server URL;
- expiry;
- optional display name/policy;
- `usedAt` и `usedByAgentId`.

Admin API:

```text
POST   /api/agents/pairing-invitations
GET    /api/agents/pairing-invitations/:id
DELETE /api/agents/pairing-invitations/:id
POST   /api/agents/:id/revoke
```

Agent API:

```text
POST /api/agent/v2/pair
GET  /agent/v2/connect
```

Pairing endpoint rate-limits IP, invitation ID и `agentId`, атомарно consumes
code и возвращает `agentSecret` ровно один раз. SesameDVR хранит только
password hash `agentSecret`.

#### Agent session и announcements

В [`agent_controller.ex`](../lib/sesame_dvr/web/agent_controller.ex) остаётся
только header authentication для v2 WSS. Query auth и старый enroll удаляются.

В [`agent_socket.ex`](../lib/sesame_dvr/web/agent_socket.ex):

- удаляется `:restore_agent_runtime`;
- session получает protocol version и connection mode;
- `welcome` не содержит commands;
- `state_snapshot` и `stream_announce` обновляют только inventory/runtime;
- server commands отправляются только после managed authorization;
- disconnect очищает managed session и ephemeral grants.

Текущий `Config.AgentCamera` заменяется inventory entity `AgentStream`:

- `agent_id`;
- `local_stream_id`;
- `revision`;
- public metadata;
- desired/runtime state;
- transport/capabilities;
- encryption summary/digest;
- last seen.

Inventory не содержит RTSP/ONVIF credentials и не является server stream
config.

#### Server-side binding

Добавляется явная binding entity:

```text
{agentId, localStreamId} -> serverStreamName
```

Binding хранит:

- approved transport;
- archive/retention/storage policy;
- approved encryption mode;
- canonical encryption policy digest;
- approved recipient public keys;
- enabled/disabled acceptance state.

Создание или изменение binding выполняет только администратор SesameDVR.
Announcement никогда автоматически не создаёт и не меняет server stream.

Для agent source обычные `Streams.start/stop` управляют только server-side
accept/archive state. Они не отправляют агенту `start_media`. Remote start/stop
показываются как отдельные managed actions.

#### Management credential и commands

SesameDVR хранит raw `managementSecret`, потому что должен предъявлять его
агенту. Secret хранится не в обычном JSON config, а в encrypted/root-owned
credential store. В `Config.Agent` сохраняется только reference.

Admin API никогда не возвращает raw secret после сохранения. UI позволяет:

- сохранить или заменить credential;
- удалить credential;
- выполнить/retry `management_auth`;
- видеть `base`, `managed` и причину отказа без раскрытия secret.

Command API проверяет managed state server-side до отправки сообщения. Это
дополнительная защита; окончательное решение всегда принимает agent gate.

Generic `update_agent_config` и `update_camera_config` заменяются typed payload
с allowlist fields. Encryption policy, pairing, local password и management
secret отсутствуют в этих schemas.

#### Agent-driven publish authorization

В [`edge_agents.ex`](../lib/sesame_dvr/edge_agents.ex) issuance publish token
переносится из подготовки `start_media` в обработчик `publish_request`.

SesameDVR проверяет:

- authenticated agent session;
- актуальную stream revision;
- наличие server-side binding;
- binding enabled;
- license feature и stream limit;
- codec/transport support;
- отсутствие conflicting publisher;
- точное совпадение encryption policy digest и recipients.

[`PublishTokens`](../lib/sesame_dvr/edge_agents/publish_tokens.ex) можно
сохранить, но token должен быть связан с:

- `agentId`;
- local `streamId`;
- revision;
- server stream name;
- transport;
- encryption policy digest;
- expiry;
- one active publish session.

Grant содержит opaque token, URL, expiry и подтверждённый policy digest.

#### RTMP и SRT

RTMP callback продолжает вызывать
[`PushSessionRegistry.authorize/3`](../lib/sesame_dvr/ingest/push_session_registry.ex),
но принимает только v2 grant token.

[`PushEndpoint`](../lib/sesame_dvr/ingest/push_endpoint.ex) для SRT должен
включать token в encoded Stream ID. Stream ID содержит version, stream name и
opaque token; весь token redacted в логах.

[`SrtRouter`](../lib/sesame_dvr/ingest/srt_router.ex) до создания route/session:

1. разбирает v2 Stream ID;
2. вызывает `PushSessionRegistry.authorize/3`;
3. получает session ID и approved server stream;
4. только после успеха подключает publisher к archive ingest;
5. heartbeat/finish выполняет по session ID.

`trusted_connect`, `trusted_heartbeat`, `trusted_finish` и `trusted_publish`
удаляются из `PushSessionRegistry`.

#### Encrypted archive и wrapped keys

SesameDVR принимает `passthrough_cbcs` только для binding с совпадающей policy.
Wrapped-key handler проверяет:

- текущую authenticated agent session;
- binding agent/local stream/server stream;
- stream revision;
- KID;
- recipient fingerprint и public SPKI;
- policy digest;
- отсутствие conflicting envelope.

SesameDVR не должен иметь API для передачи recipient private key на agent.
Clear fallback, server-side re-encryption и автоматическое добавление
server-owned recipient запрещены для agent E2EE stream.

### 18.5. Изменения UI SesameDVR

В разделе Agents необходимо:

- заменить форму создания agent/password на pairing invitation;
- показывать protocol version, pairing и revoke status;
- показывать connection mode `base`/`managed`;
- добавить protected management credential workflow;
- показывать announced streams отдельно от server streams;
- добавить явные bind/unbind actions;
- отображать local desired state только как read-only inventory;
- скрывать/disable management actions в `base`;
- показывать audit событий pairing, management auth и command rejection.

В stream form для agent source:

- выбираются agent и announced local stream;
- задаются server-side archive/retention/storage fields;
- импортируется тот же recipient public key;
- отображается сравнение local/server policy digest;
- отсутствуют RTSP URL и camera credentials;
- server start/stop не маскируется под remote agent start/stop.

### 18.6. Изменения observability

Agent metrics/status:

- pairing state и paired server URL;
- base/managed state;
- announce revision;
- publish state machine;
- grant expiry и renewals;
- encryption mode/policy digest;
- wrapped-key delivery/ack;
- rejected management commands;
- rejected weaker grants.

SesameDVR metrics/status:

- connected v2 agents;
- base/managed sessions;
- announced/unbound/bound streams;
- publish requests/grants/denials;
- token auth failures по transport;
- SRT rejects до route creation;
- policy mismatch;
- wrapped-key validation failures.

Логи обеих сторон обязаны redacted для `agentSecret`, `managementSecret`,
pairing code, publish token, RTSP credentials и private key material.

### 18.7. Изменения тестов

Go tests SesameAgent:

- pairing через стандартную TLS certificate/hostname validation;
- reconnect после обновления TLS-сертификата без изменения pairing state;
- config v2 и отказ от legacy config;
- command registry default deny;
- отсутствие side effects в base;
- managed reset после disconnect;
- agent-driven startup/reconnect;
- grant revision/policy validation;
- recipient allowlist local-only;
- отсутствие clear fallback;
- token и secret redaction.

Elixir tests SesameDVR:

- one-time pairing и replay rejection;
- v1 endpoint, query auth и legacy headers rejected;
- announcement не меняет server config;
- отсутствие runtime restore command;
- binding и policy approval;
- publish token scope/replay/expiry;
- SRT authorize before route;
- отсутствие trusted path;
- managed command blocking;
- wrapped-key binding/policy validation.

End-to-end:

- fresh agent pairing;
- base encrypted stream;
- managed encrypted stream;
- agent power cycle;
- SesameDVR restart;
- network interruption;
- revoke management secret без остановки base publication;
- revoke pairing;
- wrong recipient/private key;
- attempted clear downgrade;
- H.264 и H.265 encrypted archive/live.

### 18.8. Ручной cutover тестового прототипа

Runtime migration code не создаётся. Переход выполняется один раз:

1. Остановить media и старый SesameAgent.
2. Сохранить отдельно список RTSP/ONVIF sources и recipient public/private keys.
3. Развернуть SesameDVR с protocol v2.
4. Удалить старый agent record, enrollment password, secret и agent bindings.
5. Развернуть SesameAgent v2 с новым пустым config либо локально заново создать
   его config через UI.
6. Выполнить pairing invitation.
7. Вручную добавить локальные streams, ONVIF settings и protected encryption
   policy.
8. В SesameDVR создать новые explicit bindings и server archive policies.
9. При необходимости включить remote management и сохранить новый
   `managementSecret`.
10. Проверить encrypted archive/live и после проверки удалить backup старого
    prototype config.

Ни один из этих шагов не является обязанностью production binary. Одноразовый
внешний helper допустим только для оператора тестового стенда и не включается в
SesameAgent/SesameDVR artifact.

## 19. Порядок реализации

### Этап 1. Зафиксировать protocol v2

- Зафиксировать message schemas, config schema и policy digest.
- Удалить v1 endpoint, legacy auth и compatibility fields.
- Добавить strict protocol/config version checks.
- Обновить Agent и SesameDVR tests под clean-break contract.

### Этап 2. Реализовать pairing и базовую session

- Добавить одноразовые pairing invitations.
- Сохранять paired server URL без привязки к TLS-сертификату.
- Оставить текущий `agentSecret`, но выдавать его только через pairing.
- Удалить передачу `agentSecret` через query string.
- Пройти fresh pairing и WSS reconnect без media.

### Этап 3. Перенести ownership потоков на агент

- Добавить local desired state и reconciler.
- Агент анонсирует локальный desired/runtime state.
- Агент анонсирует encryption mode и recipient fingerprints без private key.
- Удалить автоматический `restore_runtime_on_connect`.
- Агент сам отправляет `publish_request`.
- После reconnect агент сам восстанавливает локально enabled streams.
- Добавить explicit server-side binding.

### Этап 4. Закрыть publish authorization

- Сохранить индивидуальный short-lived token для RTMP.
- Добавить индивидуальный token в SRT Stream ID.
- Удалить весь SRT trusted path.
- Авторизовать SRT до route/archive session.
- Привязать token к agent, local stream, revision, transport и binding.

### Этап 5. Закрепить end-to-end encryption policy

- Добавить local key onboarding и защищённый recipient allowlist.
- Добавить точное согласование local/server encryption policy.
- Отклонять grant, ослабляющий encryption или изменяющий recipients.
- Привязать wrapped keys к binding, revision, KID и recipient.
- Запретить clear fallback.

### Этап 6. Добавить managed mode

- Добавить central command gate с default deny.
- Добавить локальное enable/rotate/revoke management secret.
- Добавить server credential store и `management_auth`.
- Сбрасывать `managed` после disconnect.
- Заменить generic config patch на typed management commands.

### Этап 7. Завершить UI и локальные функции

- Завершить Agent UI pairing, streams, encryption, management и audit.
- Завершить SesameDVR UI invitations, inventory, binding и connection mode.
- Формализовать repair и track filtering.
- Добавить bounded RAM queues.

### Этап 8. Hardening и ручной cutover

- Пройти unit, integration и end-to-end security tests.
- Проверить power/network/server restart.
- Выполнить ручной cutover тестового NanoPi и RBT.
- После проверки удалить backup prototype config.

### Этап 9. Syslog после основного media path

- Добавить listeners, filters и parser.
- Добавить batch delivery на SesameDVR.

## 20. Минимальные тесты

1. Агент отклоняет недоверенный, просроченный или выпущенный для другого DNS
   name TLS-сертификат, но продолжает работать после штатной замены валидного
   сертификата.
2. Сервер отклоняет неверный `agentSecret`.
3. Pairing code нельзя использовать дважды.
4. В `base` все management-команды отклоняются без побочных эффектов.
5. Неверный `managementSecret` не включает `managed`.
6. Правильный `managementSecret` включает `managed` только до disconnect.
7. Сервер не может удалённо заменить management secret или pairing.
8. После reboot агент сам запускает локально enabled streams.
9. RTMP и SRT token другого агента или потока отклоняется.
10. Agent credential не проходит admin API SesameDVR.
11. RTSP/ONVIF credentials не покидают агент.
12. Snapshot, event и syslog queues не создают частых записей на flash.
13. `managed` command не может отключить locally required encryption.
14. `managed` command не может добавить, удалить или заменить recipient.
15. SesameDVR не получает recipient private key или clear CEK.
16. Agent отклоняет grant с другим encryption mode или recipient set.
17. После revoke `managementSecret` locally enabled encrypted stream продолжает
    публиковаться в base.
