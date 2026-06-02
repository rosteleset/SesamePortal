# SesamePortal External API

Этот документ описывает публичный HTTP API SesamePortal для внешних
интеграций. Основной namespace:

```text
/api/portal/v1
```

Браузерные HTML routes, формы и служебные UI endpoints не считаются публичным
API и вынесены во внутренний документ `SESAME-PORTAL-HTML-ENDPOINTS.internal.ru.md`.

## Общие правила

Базовый URL в примерах:

```text
https://portal.example.com
```

JSON API принимает один из способов аутентификации:

```text
Authorization: Bearer <static-token>
```

Также поддерживаются headers:

```text
X-Portal-Token: <static-token>
X-Api-Token: <static-token>
```

Для запросов из браузера можно использовать session cookie `sesame_portal`.
Для внешних интеграций рекомендуется static token пользователя.

Daily playback tokens намеренно не принимаются JSON API. Они остаются только
для playback/auth-backend сценариев SesameDVR.

JSON API не использует CSRF. Все ответы JSON API возвращаются с:

```text
Content-Type: application/json; charset=utf-8
Cache-Control: no-store
```

Формат ошибок единый:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "name is required"
  }
}
```

## Роли и доступ

`admin`:

- видит все камеры и все группы;
- управляет пользователями, группами, DVR-серверами, камерами и edge agents;
- имеет доступ ко всем административным JSON endpoints.

`user`:

- видит только незаблокированные камеры из своих незаблокированных групп;
- может читать доступные камеры и управлять своим избранным;
- не может менять административные сущности.

Ошибки доступа:

| Условие | HTTP | `error.code` |
| --- | --- | --- |
| Нет session/static token | `401` | `unauthorized` |
| Недостаточно прав | `403` | `forbidden` |
| Сущность не найдена | `404` | `not_found` |
| Конфликт с существующей сущностью | `409` | зависит от endpoint |
| Невалидный JSON | `400` | `invalid_json` |
| Ошибка валидации | `422` | `validation_failed` |
| Метод не поддержан | `405` | `method_not_allowed` |

## JSON API v1

Для списков поддерживаются `q`, `page`, `pageSize`/`page_size`, если это
применимо. `pageSize` ограничивается сервером, чтобы интеграция не могла
случайно забрать слишком большой объём данных одним запросом.

### GET /api/portal/v1

Возвращает краткое описание API и список ресурсов. Не требует auth.

### GET /api/portal/v1/me

Возвращает текущего пользователя.

Ответ:

```json
{
  "user": {
    "id": 1,
    "login": "admin",
    "role": "admin",
    "blocked": false,
    "hasStaticToken": true,
    "groupIds": [1]
  }
}
```

### GET /api/portal/v1/dashboard

Admin only. Возвращает счётчики и настроенные SesameDVR servers вместе с
последними сохранёнными метриками.

### POST /api/portal/v1/dashboard

Admin only. Обновляет метрики DVR-серверов. Если передать `serverId`, обновляет
один сервер, иначе все незаблокированные.

Payload:

```json
{ "serverId": 1 }
```

### /api/portal/v1/users

Admin only.

| Method | Endpoint | Описание |
| --- | --- | --- |
| `GET` | `/users` | Список пользователей. Query: `q`, `page`, `pageSize`. |
| `POST` | `/users` | Создать пользователя. |
| `GET` | `/users/{id}` | Получить пользователя с `groupIds`. |
| `PATCH`/`PUT` | `/users/{id}` | Обновить пользователя. |
| `DELETE` | `/users/{id}` | Удалить пользователя. |
| `POST` | `/users/{id}/static-token` | Выпустить static token. Token показывается один раз. Если token уже был, он заменяется. |
| `DELETE` | `/users/{id}/static-token` | Отозвать static token. |

Операции со static token пишутся в audit: первый выпуск -
`user.static_token.issue`, замена существующего token -
`user.static_token.replace`, отзыв - `user.static_token.revoke`. Сам token в
журнал не записывается.

Payload создания/обновления:

```json
{
  "login": "operator",
  "password": "secret123",
  "role": "user",
  "blocked": false
}
```

При обновлении пустой или отсутствующий `password` оставляет текущий пароль.

### /api/portal/v1/groups

Admin only.

| Method | Endpoint | Описание |
| --- | --- | --- |
| `GET` | `/groups` | Список групп. Query: `q`, `page`, `pageSize`. |
| `POST` | `/groups` | Создать группу. |
| `GET` | `/groups/{id}` | Получить группу с `userIds` и `cameraIds`. |
| `PATCH`/`PUT` | `/groups/{id}` | Обновить группу. |
| `DELETE` | `/groups/{id}` | Удалить группу. |
| `GET` | `/groups/{id}/children` | Получить прямые подгруппы группы по group id. |
| `POST` | `/groups/{id}/children` | Создать подгруппу внутри группы по group id. |
| `GET` | `/groups/{id}/users` | Получить пользователей группы по group id. |
| `PUT`/`PATCH` | `/groups/{id}/users` | Полностью заменить список пользователей группы. |
| `POST` | `/groups/{id}/users` | Добавить пользователей в группу. |
| `DELETE` | `/groups/{id}/users` | Удалить пользователей из группы. |
| `GET` | `/groups/{id}/cameras` | Получить камеры группы по group id. |
| `PUT`/`PATCH` | `/groups/{id}/cameras` | Полностью заменить список камер группы. |
| `POST` | `/groups/{id}/cameras` | Добавить камеры в группу. |
| `DELETE` | `/groups/{id}/cameras` | Удалить камеры из группы. |

Payload создания/обновления:

```json
{
  "id": 1001,
  "name": "Operators",
  "parentGroupId": null,
  "description": "Main operator group",
  "blocked": false,
  "userIds": [2, 3],
  "cameraIds": [10, 11]
}
```

`id` в объекте группы - это стабильный group id из Portal DB. Его нужно
использовать в endpoints `/groups/{id}` и `/groups/{id}/...`.

`name` - отображаемое название группы, а не уникальный идентификатор.
Одинаковые названия групп разрешены, в том числе на одном уровне дерева.
Внешние интеграции должны различать группы только по `id`; для отображения
человеку можно дополнительно использовать `parentGroupId`, путь группы или
собственный внешний mapping.

При создании группы `id` можно явно передать в payload. Это полезно для внешних
интеграций, которые синхронизируют группы из своей системы. Если `id` не
передан, Portal сгенерирует его автоматически. Явный `id` должен быть
положительным integer и не должен уже существовать; при конфликте Portal
вернёт `409 group_id_exists`. При обновлении группы менять `id` нельзя:
`PATCH`/`PUT /groups/{id}` с другим `id` вернёт `422 validation_failed`.

`parentGroupId` задаёт родительскую группу. `null`, `0` или пустое значение
делают группу корневой. Portal не позволит назначить родителем саму группу или
её подгруппу, чтобы не создать цикл.

`userIds` и `cameraIds` заменяют связи только если ключ передан в payload.
Membership, выданный пользователю на группу, распространяется на активные
подгруппы этой группы. Фильтр камер `filter=group:{id}` также включает камеры
из всех подгрупп выбранной группы.

Пример объекта группы в ответе:

```json
{
  "id": 1,
  "parentGroupId": null,
  "parentGroupName": null,
  "name": "Operators",
  "description": "Main operator group",
  "blocked": false,
  "createdAt": "2026-05-26T12:00:00+00:00",
  "childGroupIds": [4],
  "children": [
    {
      "id": 4,
      "parentGroupId": 1,
      "parentGroupName": "Operators",
      "name": "Night shift",
      "description": "",
      "blocked": false,
      "createdAt": "2026-05-26T12:10:00+00:00"
    }
  ],
  "userIds": [2, 3],
  "cameraIds": [10, 11]
}
```

Membership endpoints принимают `userIds`, `cameraIds` или универсальный ключ
`ids`. `PUT`/`PATCH` заменяет весь список, `POST` добавляет переданные ID,
`DELETE` удаляет только переданные ID. Для полной очистки передайте пустой
список через `PUT`/`PATCH`.

Примеры:

```http
GET /api/portal/v1/groups/1/users
PUT /api/portal/v1/groups/1/users
Content-Type: application/json

{ "userIds": [2, 3] }
```

```http
GET /api/portal/v1/groups/1/cameras
POST /api/portal/v1/groups/1/cameras
Content-Type: application/json

{ "cameraIds": [10, 11] }
```

Ответ `/groups/{id}/children`:

```json
{
  "group": {
    "id": 1,
    "parentGroupId": null,
    "parentGroupName": null,
    "name": "Operators",
    "description": "Main operator group",
    "blocked": false,
    "createdAt": "2026-05-26T12:00:00+00:00"
  },
  "childGroupIds": [4],
  "children": [
    {
      "id": 4,
      "parentGroupId": 1,
      "parentGroupName": "Operators",
      "name": "Night shift",
      "description": "",
      "blocked": false,
      "createdAt": "2026-05-26T12:10:00+00:00"
    }
  ]
}
```

`POST /groups/{id}/children` принимает тот же payload, что и создание группы,
включая опциональный явный `id`, но `parentGroupId` принудительно
устанавливается в `{id}`.

Ответ `/groups/{id}/users`:

```json
{
  "group": {
    "id": 1,
    "name": "Operators",
    "description": "Main operator group",
    "blocked": false,
    "userIds": [2, 3],
    "cameraIds": [10, 11]
  },
  "userIds": [2, 3],
  "users": [
    { "id": 2, "login": "operator", "role": "user", "blocked": false }
  ]
}
```

### /api/portal/v1/servers

Admin only. Управление SesameDVR-серверами, которые Portal использует для
preview/playback/sync/agents.

| Method | Endpoint | Описание |
| --- | --- | --- |
| `GET` | `/servers` | Список серверов. |
| `POST` | `/servers` | Создать сервер. |
| `GET` | `/servers/{id}` | Детали сервера с `lastMetrics`. |
| `PATCH`/`PUT` | `/servers/{id}` | Обновить сервер. |
| `DELETE` | `/servers/{id}` | Удалить сервер. |
| `POST` | `/servers/{id}/check` | Проверить `GET /api/system/version` на DVR. |
| `POST` | `/servers/{id}/refresh` | Обновить version/status/streams метрики DVR. |

Payload:

```json
{
  "name": "Main DVR",
  "baseUrl": "https://dvr.example.com",
  "managementToken": "secret-token",
  "blocked": false
}
```

При обновлении отсутствующий `managementToken` оставляет старый token. Пустой
`managementToken` очищает token.

### /api/portal/v1/cameras

`GET` доступен обычным пользователям и admin. Изменение камер требует admin.

| Method | Endpoint | Описание |
| --- | --- | --- |
| `GET` | `/cameras` | Список камер. Для user только доступные камеры. |
| `POST` | `/cameras` | Создать камеру и, по умолчанию, синхронизировать stream на DVR. |
| `GET` | `/cameras/{id}` | Детали камеры с `groupIds`. |
| `PATCH`/`PUT` | `/cameras/{id}` | Обновить камеру. |
| `DELETE` | `/cameras/{id}` | Удалить камеру из Portal. |
| `POST` | `/cameras/{id}/sync` | Повторно синхронизировать камеру с DVR. |

Query для списка:

| Параметр | Описание |
| --- | --- |
| `scope=accessible` | Даже для admin вернуть только viewer-доступную выборку. |
| `filter` | `all`, `favorites`, `group:<id>`. Фильтр по группе включает её подгруппы. |
| `q` | Регистронезависимый поиск по названию камеры (`displayName`/`name`) или техническому имени потока `dvrStreamName`. |
| `page`, `pageSize` | Пагинация. |

Payload камеры:

```json
{
  "displayName": "Entrance",
  "sourceUrl": "rtsp://camera/stream1",
  "serverId": 1,
  "serverSelection": "manual",
  "dvrControlMode": "managed",
  "dvrStreamName": "entrance",
  "retentionDays": "7d",
  "latitude": 55.751244,
  "longitude": 37.618423,
  "directionDeg": 90,
  "viewAngleDeg": 60,
  "watermarkEnabled": true,
  "watermarkIntensity": 16,
  "blocked": false,
  "groupIds": [1, 2],
  "sync": true
}
```

`displayName` - человекочитаемое название потока в SesameDVR. Для обратной
совместимости вместо него можно передавать старое поле `name`. Portal сохраняет
это значение как имя камеры. Если `displayName`/`name` не передан, имя камеры
берётся из технического `dvrStreamName`.

`dvrStreamName` - техническое имя потока в SesameDVR и playback URL. Допустимы
только `A-Z`, `a-z`, `0-9`, `-` и `_`, максимум 128 символов. Если оно не
передано, Portal сгенерирует валидное имя из `displayName`/`name`.

`watermarkEnabled=true` включает HTML/CSS watermark с login текущего
пользователя поверх video-зоны Portal player. `watermarkIntensity` задаёт
интенсивность в процентах, по умолчанию `16`. Видеопоток при этом не
транскодируется.

Для Edge Agent камеры:

```json
{
  "displayName": "Edge cam 1",
  "serverId": 1,
  "dvrControlMode": "edge_agent",
  "dvrStreamName": "edge-cam-1",
  "agentId": "agent-office",
  "agentCameraId": "cam-1",
  "onvifEventsRequested": true
}
```

`sync=false` или `skipSync=true` сохраняет камеру без немедленного DVR sync.
`DELETE /cameras/{id}?purge=true` дополнительно вызывает SesameDVR
`DELETE /api/streams/<name>?purge=true`.

### /api/portal/v1/favorites

Работает от имени текущего пользователя.

| Method | Endpoint | Описание |
| --- | --- | --- |
| `GET` | `/favorites` | Список избранных доступных камер. |
| `PUT`/`POST` | `/favorites/{cameraId}` | Добавить камеру в избранное. |
| `DELETE` | `/favorites/{cameraId}` | Удалить камеру из избранного. |

### /api/portal/v1/agents

Admin only. Portal не хранит agents локально: эти endpoints проксируют
management API выбранного SesameDVR-сервера. Во всех запросах нужен
`serverId`/`server_id` в query или JSON body.

| Method | Endpoint | SesameDVR call |
| --- | --- | --- |
| `GET` | `/agents?serverId=1` | `GET /api/agents` |
| `POST` | `/agents` | `POST /api/agents` |
| `GET` | `/agents/{id}` | cameras + commands + logs выбранного агента |
| `PATCH`/`PUT` | `/agents/{id}` | `PATCH /api/agents/{id}` |
| `DELETE` | `/agents/{id}` | `DELETE /api/agents/{id}` |
| `POST` | `/agents/{id}/enrollment-password` | `POST /api/agents/{id}/enrollment-password` |
| `POST` | `/agents/{id}/revoke` | `POST /api/agents/{id}/revoke` |
| `POST` | `/agents/{id}/rotate-secret` | `POST /api/agents/{id}/rotate-secret` |
| `GET` | `/agents/{id}/cameras` | `GET /api/agents/{id}/cameras` |
| `POST` | `/agents/{id}/cameras/scan` | `POST /api/agents/{id}/cameras/scan` |
| `POST` | `/agents/{id}/diagnostics` | `POST /api/agents/{id}/diagnostics` |
| `GET` | `/agents/{id}/commands` | `GET /api/agents/{id}/commands` |
| `POST` | `/agents/{id}/commands` | `POST /api/agents/{id}/commands` |
| `GET` | `/agents/{id}/logs` | `GET /api/agents/{id}/logs` |

Payload создания агента:

```json
{
  "serverId": 1,
  "id": "agent-office",
  "name": "Office agent",
  "enabled": true,
  "capabilities": ["rtmp_push", "onvif_events"],
  "password": "enrollment-password"
}
```

Payload команды:

```json
{
  "serverId": 1,
  "command": "test_camera",
  "payload": { "agentCameraId": "cam-1" },
  "timeoutMs": 30000
}
```

### GET /api/portal/v1/audit

Admin only. Возвращает журнал действий.

UI login пишет события `auth.login` и `auth.login_failed` с login и IP. MP4
archive export/download через SesameDVR auth backend пишет `archive.download`
после успешной проверки playback token.

Query:

| Параметр | Описание |
| --- | --- |
| `q` | Поиск по action, actor login или details. |
| `action` | Фильтр по action. |
| `actor` | ID пользователя. |
| `page`, `pageSize` | Пагинация. |

### Примеры

```bash
curl -H "Authorization: Bearer $TOKEN" \
  https://portal.example.com/api/portal/v1/cameras?scope=accessible
```

```bash
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"blocked":true}' \
  https://portal.example.com/api/portal/v1/cameras/10
```

## SesameDVR auth-backend API

### GET /api/sesamedvr/auth

Machine-facing endpoint для SesameDVR auth backend. DVR вызывает его перед
выдачей playback/live/archive/preview доступа, чтобы Portal проверил token
пользователя и права на конкретный stream.

Аутентификация здесь не через session cookie, а через playback token в query.
CSRF не используется.

Token можно передать одним из способов:

| Способ | Описание |
| --- | --- |
| `token=<value>` | Основной вариант. |
| `auth_token=<value>` | Alias. |
| `playback_token=<value>` | Alias. |
| `qs=<query-string>` | Portal распарсит query string и найдёт `token`, `auth_token` или `playback_token`. |
| `uri=<url-or-path>` / `path=<url-or-path>` / `request_uri=<url-or-path>` | Portal распарсит query из этого значения и найдёт token aliases. |
| `original_uri=<url-or-path>` | Alias для оригинального URI запроса. |

Stream/camera можно передать одним из способов:

| Способ | Описание |
| --- | --- |
| `camera=<name>` | Имя камеры или `dvr_stream_name`. |
| `stream=<name>` | Alias. |
| `name=<name>` | Alias. |
| `uri=<url-or-path>` / `path=<url-or-path>` / `request_uri=<url-or-path>` | Portal берёт первый path segment как имя stream. |
| `original_uri=<url-or-path>` | Alias для оригинального URI запроса. |

Проверка доступа:

1. Portal ищет пользователя по daily token, previous daily token в overlap-окне
   00:00-05:59 timezone Portal, или static token.
2. Пользователь должен быть не заблокирован.
3. Камера должна существовать, быть не заблокированной и совпасть по
   `dvr_stream_name` или `name`.
4. Для обычного пользователя камера должна входить хотя бы в одну его
   незаблокированную группу. Admin проходит проверку доступа ко всем
   незаблокированным камерам.

Ответы:

| Условие | HTTP | Body |
| --- | --- | --- |
| Доступ разрешён | `200` | `ok\n` |
| Token пустой/неверный, stream пустой, камера недоступна | `403` | `denied\n` |

Если доступ разрешён и оригинальный URI соответствует
`/<stream>/archive-<from>-<duration>.mp4`, Portal пишет в audit событие
`archive.download` с actor user, `camera_id`, `stream`, `from`, `duration`,
методом и IP (`X-Forwarded-For`/`X-Real-IP`/`REMOTE_ADDR`). Для этого DVR/nginx
должен передавать оригинальный URI в `uri`, `path`, `request_uri`,
`original_uri` или соответствующем `X-Original-URI` header.

Примеры:

```bash
curl 'https://portal.example.com/api/sesamedvr/auth?token=<token>&stream=camera-1'
```

```bash
curl 'https://portal.example.com/api/sesamedvr/auth?uri=/camera-1/embed.html?token=<token>'
```

## Outbound SesameDVR API calls

Следующие endpoints не являются endpoints SesamePortal. Это management API
SesameDVR, которое Portal вызывает на настроенных DVR-серверах:

- `GET /api/system/version`;
- `GET /api/system/status`;
- `GET /api/streams`;
- `GET /api/streams/<name>`;
- `POST /api/streams`;
- `PUT /api/streams/<name>`;
- `DELETE /api/streams/<name>?purge=true`;
- `GET /api/agents`;
- `POST /api/agents`;
- `PATCH /api/agents/<id>`;
- `DELETE /api/agents/<id>`;
- `POST /api/agents/<id>/enrollment-password`;
- `POST /api/agents/<id>/revoke`;
- `POST /api/agents/<id>/rotate-secret`;
- `GET /api/agents/<id>/cameras`;
- `POST /api/agents/<id>/cameras/scan`;
- `GET /api/agents/<id>/cameras/<camera_id>/snapshot.jpg`;
- `POST /api/agents/<id>/diagnostics`;
- `GET /api/agents/<id>/commands`;
- `POST /api/agents/<id>/commands`;
- `GET /api/agents/<id>/logs`.

Portal передаёт management token в header:

```text
X-Management-Token: <token>
```

Token хранится в таблице DVR-серверов в зашифрованном виде.
