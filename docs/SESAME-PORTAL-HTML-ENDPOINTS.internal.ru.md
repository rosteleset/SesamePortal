# SesamePortal Internal HTML Endpoints

Внутренний документ для сопровождения browser UI. Эти routes существуют в
Portal, но не считаются публичным API для внешних интеграций. Для внешних
клиентов использовать `SESAME-PORTAL-API.ru.md`.

## Общие правила UI

Базовый URL в примерах:

```text
https://portal.example.com
```

HTML UI использует session cookie `sesame_portal`.

Все `POST` endpoints HTML UI требуют CSRF поле:

```text
csrf=<token из текущей HTML-формы>
```

Если CSRF token отсутствует или не совпадает, Portal возвращает HTTP `419` и
текст `CSRF token mismatch`.

Глобальный query-параметр `lang=<code>` может применяться к HTML endpoints для
смены языка интерфейса.

## Роли и доступ

`admin`:

- видит все камеры и все группы;
- управляет пользователями, группами, DVR-серверами, камерами и edge agents;
- имеет доступ ко всем `/admin/*` endpoints.

`user`:

- видит только незаблокированные камеры из своих незаблокированных групп;
- может открывать mosaic/map/player/preview;
- может переключать избранное для доступных камер;
- не имеет доступа к `/admin/*`.

Если endpoint требует login, но сессии нет, Portal перенаправляет на `/login`.
Если endpoint требует администратора, но пользователь не admin, Portal
возвращает HTTP `403`.

## Auth/session endpoints

### GET /login

Показывает форму входа.

Ответ: HTML.

### POST /login

Проверяет логин/пароль и создаёт session cookie.

Form fields:

| Поле | Обязательно | Описание |
| --- | --- | --- |
| `csrf` | да | CSRF token формы входа |
| `login` | да | Логин пользователя |
| `password` | да | Пароль пользователя |

Поведение:

- при успехе: `302` redirect на `/`;
- при ошибке: HTML страницы входа с сообщением об ошибке.

### GET /logout

Сбрасывает сессию и перенаправляет на `/login`.

Ответ: `302`.

## Viewer endpoints

### GET /

Главная страница mosaic viewer.

Требует login.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `filter` | `all`, `favorites` или `group:<id>`. По умолчанию `all`. |
| `q` | Поиск по имени камеры или `dvr_stream_name`. Максимально используется 120 символов. |
| `page` | Номер страницы. Размер страницы фиксированный: 24 камеры. |
| `cols` | Количество камер в ряду, от `2` до `6`. По умолчанию `3`. |
| `refresh` | Интервал обновления preview: `off`, `10`, `30`, `60`, `300`. По умолчанию `30`. |

Ответ: HTML.

Доступные камеры фильтруются по группам пользователя. Для admin фильтр групп
может ссылаться на любую группу. Для обычного пользователя `group:<id>` работает
только если пользователь входит в эту активную группу.

### GET /viewer/map

Карта камер.

Требует login.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `filter` | `all`, `favorites` или `group:<id>`. |
| `q` | Поиск по имени камеры или `dvr_stream_name`. |

Ответ: HTML. Список камер для карты встраивается в страницу как
`window.SESAME_CAMERAS`.

### GET /viewer/player

Страница embedded player для одной камеры.

Требует login и доступ к камере.

Query parameters:

| Параметр | Обязательно | Описание |
| --- | --- | --- |
| `id` | да | ID камеры в Portal. |
| `back` | нет | Внутренний path возврата. Внешние URL нормализуются до path. |

Ответ:

- `200` HTML с iframe на SesameDVR `/<stream>/embed.html?...`;
- `403` `Forbidden`, если камера недоступна пользователю;
- `404` `Camera not found`, если камера не найдена или заблокирована.

В iframe передаётся daily playback token текущего пользователя. При наличии
`back` Portal передаёт в DVR absolute `back_url` и `back_label`.

### GET /viewer/preview

Прокси/redirect для preview выбранной камеры.

Требует login и доступ к камере.

Query parameters:

| Параметр | Обязательно | Описание |
| --- | --- | --- |
| `id` | да | ID камеры в Portal. |
| `_` | нет | Cache-busting значение, прокидывается в DVR preview URL. |

Ответ:

- `302` redirect на SesameDVR `/<stream>/preview.jpg?token=<daily-token>`;
- `403` `Forbidden`, если камера недоступна;
- `403` `Token missing`, если у пользователя нет playback token;
- `404` `Preview not found`, если камера не имеет DVR server/stream.

Endpoint специально не вшивает token в HTML надолго: при каждом запросе берётся
актуальный daily token текущей сессии.

### POST /favorite/toggle

Добавляет или удаляет камеру из избранного текущего пользователя.

Требует login, CSRF и доступ к камере.

Form fields:

| Поле | Обязательно | Описание |
| --- | --- | --- |
| `csrf` | да | CSRF token |
| `camera_id` | да | ID камеры |

Ответ:

- `302` redirect на `Referer` или `/`;
- `403` `Forbidden`, если камера недоступна.

## Admin dashboard

### GET /admin/dashboard

Dashboard администратора: счётчики пользователей/групп/камер/DVR-серверов,
карточки серверов, последняя синхронизация камер.

Требует admin.

Ответ: HTML.

### POST /admin/dashboard

Выполняет refresh статистики DVR-серверов.

Требует admin и CSRF.

Actions:

| `action` | Поля | Описание |
| --- | --- | --- |
| `refresh_all` | - | Обновить метрики всех незаблокированных DVR-серверов. |
| `refresh_server` | `id` | Обновить метрики одного DVR-сервера. |

При refresh Portal обращается к выбранному SesameDVR server management API:
`GET /api/system/version`, `GET /api/system/status`, `GET /api/streams`.

Ответ: HTML dashboard с notice о результате.

## Admin users

### GET /admin/users

Список пользователей и форма создания/редактирования.
В таблице показывается статус `Static token`: `есть` или `нет`. Сам token
после выпуска повторно не отображается, потому что в базе хранится только hash.

Требует admin.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `q` | Поиск по `login` или `role`. |
| `page` | Номер страницы. |
| `edit` | ID пользователя для заполнения формы редактирования. |

Ответ: HTML.

### POST /admin/users

Создаёт, обновляет, удаляет пользователя или управляет static token.

Требует admin и CSRF.

Actions:

| `action` | Поля | Описание |
| --- | --- | --- |
| `save` | `id`, `login`, `password`, `role`, `blocked` | Создать или обновить пользователя. Для нового пользователя пароль должен быть не короче 6 символов. При редактировании пустой `password` означает "не менять". `role`: `admin` или `user`. |
| `delete` | `id` | Удалить пользователя. |
| `issue_static` | `id` | Выпустить static token. Если token уже был, действие заменяет его, старый token сразу перестаёт работать. Новый token показывается один раз в HTML notice. |
| `revoke_static` | `id` | Отозвать static playback token. |

`blocked` передаётся как checkbox: присутствует = `1`, отсутствует = `0`.

UI показывает кнопку `Выпустить статический токен`, если token отсутствует, и
`Заменить статический токен`, если token уже есть. Замена требует browser confirm.
Кнопка `Отозвать` показывается только при наличии token.

Ответ: HTML.

## Admin groups

### GET /admin/groups

Список групп и форма создания/редактирования.

Требует admin.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `q` | Поиск по `name` или `description`. |
| `page` | Номер страницы. |
| `edit` | ID группы для заполнения формы редактирования. |

Ответ: HTML.

### POST /admin/groups

Создаёт, обновляет или удаляет группу. Также полностью заменяет membership
пользователь-группа и камера-группа для этой группы.

Требует admin и CSRF.

Actions:

| `action` | Поля | Описание |
| --- | --- | --- |
| `save` | `id`, `name`, `description`, `blocked`, `user_ids[]`, `camera_ids[]` | Создать или обновить группу. Если `id=0` или пустой, создаётся новая группа. |
| `delete` | `id` | Удалить группу. Связи `user_groups` и `camera_groups` удаляются каскадно. |

Ответ: HTML.

## Admin DVR servers

### GET /admin/servers

Список SesameDVR-серверов и форма создания/редактирования.

Требует admin.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `q` | Поиск по `name`, `base_url`, `last_check_result`. |
| `page` | Номер страницы. |
| `edit` | ID сервера для заполнения формы редактирования. |

Ответ: HTML.

### POST /admin/servers

Создаёт, обновляет, удаляет или проверяет DVR server.

Требует admin и CSRF.

Actions:

| `action` | Поля | Описание |
| --- | --- | --- |
| `save` | `id`, `name`, `base_url`, `management_token`, `blocked` | Создать или обновить сервер. `base_url` сохраняется без завершающего `/`. При редактировании пустой `management_token` оставляет старый token. |
| `delete` | `id` | Удалить сервер. |
| `check` | `id` | Проверить сервер через SesameDVR `GET /api/system/version`. |

Ответ: HTML.

## Admin cameras

### GET /admin/cameras

Список камер и форма создания/редактирования.

Требует admin.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `q` | Поиск по имени камеры, source URL, имени сервера или `dvr_stream_name`. |
| `page` | Номер страницы. |
| `edit` | ID камеры для заполнения формы редактирования. |
| `delete` | ID камеры для показа панели подтверждения удаления. |

Форма создания также может быть предзаполнена query-параметрами:

| Параметр | Описание |
| --- | --- |
| `name` | Имя камеры. |
| `stream` / `dvr_stream_name` | Техническое имя потока SesameDVR. |
| `source_url` | URL источника. |
| `server_id` | ID DVR-сервера. |
| `retention_days` | Глубина архива, например `7d`. |
| `mode` / `dvr_control_mode` | `managed`, `edge_agent` или `read_only`. |
| `agent_id` | Edge Agent ID. |
| `agent_camera_id` | ID камеры внутри агента. |
| `onvif_events_requested` | Непустое значение включает ONVIF events через агента. |

Ответ: HTML.

### POST /admin/cameras

Создаёт, обновляет, синхронизирует или удаляет камеру.

Требует admin и CSRF.

Actions:

| `action` | Поля | Описание |
| --- | --- | --- |
| `save` | см. ниже | Создать или обновить камеру, заменить связи `camera_groups`, затем синхронизировать stream на DVR, если режим это требует. |
| `sync` | `id` | Повторно синхронизировать камеру с DVR. |
| `delete` | `id`, `confirm_delete`, `delete_dvr_stream` | Удалить камеру из Portal. Если `delete_dvr_stream` включён и это разрешено режимом/сервером, Portal также вызывает SesameDVR `DELETE /api/streams/<name>?purge=true`. |

Поля `save`:

| Поле | Описание |
| --- | --- |
| `id` | ID камеры. `0` или пустое значение создаёт новую камеру. |
| `name` | Отображаемое имя камеры. |
| `source_url` | URL источника. Обязателен для `managed`. |
| `server_id` | ID DVR-сервера. Может быть пустым, если `server_selection=auto`. |
| `server_selection` | `manual` или `auto`. Для `edge_agent` принудительно используется `manual`. |
| `dvr_control_mode` | `managed`, `edge_agent`, `read_only`. |
| `dvr_stream_name` | URL-safe техническое имя stream. Если пусто, строится из `name`. |
| `agent_id` | Edge Agent ID. Обязателен для `edge_agent`. |
| `agent_camera_id` | ID камеры на стороне агента. Обязателен для `edge_agent`. |
| `onvif_events_requested` | Checkbox для ONVIF events через агента. |
| `latitude`, `longitude` | Координаты камеры. Пустые значения сохраняются как `NULL`. |
| `direction_deg` | Направление камеры, градусы. |
| `view_angle_deg` | Угол обзора, градусы. |
| `retention_days` | Глубина архива. |
| `blocked` | Checkbox блокировки камеры. |
| `group_ids[]` | Полный набор групп камеры. |

Режимы камеры:

- `managed`: Portal пишет stream в SesameDVR через `PUT /api/streams/<name>`
  или `POST /api/streams`, если stream ещё не существует.
- `edge_agent`: Portal пишет push stream с `publisherKind=agent`.
- `read_only`: Portal не меняет DVR-конфигурацию, использует сохранённый
  `dvr_stream_name` только для preview/playback/auth.

Ответ: HTML.

## Admin edge agents

Portal не хранит edge agents локально. `/admin/agents` является UI-прокси к
management API выбранного SesameDVR-сервера.

### GET /admin/agents

Список агентов выбранного DVR-сервера, камеры выбранного агента, последние
команды и журнал.

Требует admin.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `server_id` | ID DVR-сервера. Если не указан, выбирается первый незаблокированный сервер. |
| `agent_id` | ID выбранного агента. Если не указан, выбирается первый агент из списка. |

Portal вызывает на SesameDVR:

- `GET /api/agents`;
- `GET /api/agents/<id>/cameras`;
- `GET /api/agents/<id>/commands`;
- `GET /api/agents/<id>/logs`.

Ответ: HTML.

### POST /admin/agents

Выполняет действие над агентом выбранного DVR-сервера.

Требует admin и CSRF.

Общие поля:

| Поле | Описание |
| --- | --- |
| `server_id` | ID DVR-сервера. |
| `agent_id` | ID агента. Требуется для всех действий, кроме `create`, где это ID нового агента. |

Actions:

| `action` | Поля | SesameDVR call |
| --- | --- | --- |
| `create` | `agent_id`, `name`, `enabled`, `capabilities`, `password` | `POST /api/agents` |
| `update` | `agent_id`, `name`, `enabled`, `capabilities` | `PATCH /api/agents/<id>` |
| `delete` | `agent_id` | `DELETE /api/agents/<id>` |
| `password` | `agent_id`, `password` | `POST /api/agents/<id>/enrollment-password` |
| `revoke` | `agent_id` | `POST /api/agents/<id>/revoke` |
| `rotate` | `agent_id` | `POST /api/agents/<id>/rotate-secret` |
| `scan` | `agent_id` | `POST /api/agents/<id>/cameras/scan` |
| `diagnostics` | `agent_id` | `POST /api/agents/<id>/diagnostics` |
| `command` | `agent_id`, `command`, `payload`, `agent_camera_id`, `timeout_ms` | `POST /api/agents/<id>/commands` |

Особенности:

- `capabilities` передаётся как строка, разделённая запятыми или пробелами,
  и преобразуется в массив.
- Для `command` поле `payload` должно быть JSON object. Если указан
  `agent_camera_id`, Portal добавляет в payload `agentCameraId` и `cameraId`.
- `timeout_ms` передаётся в SesameDVR как `timeoutMs`, если значение больше 0.

Ответ: HTML.

### GET /admin/agents/snapshot

Проксирует JPEG/PNG snapshot камеры агента через выбранный SesameDVR.

Требует admin.

Query parameters:

| Параметр | Обязательно | Описание |
| --- | --- | --- |
| `server_id` | да | ID DVR-сервера. |
| `agent_id` | да | ID агента. |
| `camera_id` | да | ID камеры агента. |
| `fresh` | нет | Если непустой, Portal добавляет `fresh=true` к DVR-запросу. |

Portal вызывает:

```text
GET /api/agents/<agent_id>/cameras/<camera_id>/snapshot.jpg?timeoutMs=2500[&fresh=true]
```

Ответ:

- `200` image content с `Cache-Control: no-store`;
- `400` `missing snapshot parameters`;
- HTTP status от SesameDVR или `502` при ошибке proxy.

## Admin audit

### GET /admin/audit

Журнал действий Portal.

Требует admin.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `q` | Поиск по action, actor login или details. |
| `action` | Фильтр по конкретному action. |
| `actor` | ID пользователя-актора. |
| `page` | Номер страницы. Размер страницы: 50 записей. |

Ответ: HTML.
