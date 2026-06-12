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

Все `POST` endpoints HTML UI, кроме `POST /login`, требуют CSRF поле:

```text
csrf=<token из текущей HTML-формы>
```

Если CSRF token отсутствует или не совпадает на защищённом endpoint, Portal
возвращает HTTP `419` и текст `CSRF token mismatch`.

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
| `login` | да | Логин пользователя |
| `password` | да | Пароль пользователя |

Поведение:

- при успехе: `302` redirect на `/`;
- при ошибке: HTML страницы входа с сообщением об ошибке.
- CSRF для `POST /login` не проверяется, чтобы устаревшая форма входа или
  сменившаяся session cookie не блокировали авторизацию.

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
| `q` | Регистронезависимый поиск по названию камеры или `dvr_stream_name`. Максимально используется 120 символов. |
| `page` | Номер страницы. Размер страницы зависит от `cols`: `2` -> 4 камеры, `3` -> 6, `4` -> 12, `5` -> 15, `6` -> 18. |
| `cols` | Количество камер в ряду, от `2` до `6`. По умолчанию `3`. |
| `refresh` | Интервал обновления preview: `off`, `10`, `30`, `60`, `300`. По умолчанию `30`. |

Ответ: HTML.

Доступные камеры фильтруются по группам пользователя. Для admin фильтр групп
может ссылаться на любую группу. Для обычного пользователя `group:<id>` работает
только если пользователь входит в эту активную группу или её активного предка.
Фильтр по группе включает камеры выбранной группы и её активных подгрупп.

### GET /viewer/map

Карта камер.

Требует login.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `filter` | `all`, `favorites` или `group:<id>`. |
| `q` | Регистронезависимый поиск по названию камеры или `dvr_stream_name`. |

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

Если у камеры включён `watermark_enabled`, Portal поверх iframe добавляет
HTML/CSS watermark с login текущего пользователя. Overlay ограничен верхней
video-зоной player и не должен заходить на нижнюю панель controls. Интенсивность
задаётся через `watermark_intensity`. Поток при этом не транскодируется.

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

Скачивание MP4 архива не идёт через отдельный HTML endpoint Portal: DVR
вызывает `/api/sesamedvr/auth` для URL вида
`/<stream>/archive-<from>-<duration>.mp4`, а Portal пишет событие
`archive.download` в audit после успешной проверки token и доступа.

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

## Admin settings

### GET /admin/settings

Страница настроек Portal. Сейчас содержит блок `Обновления Portal`:

- текущая версия из `RELEASE.json` или fallback из локального Git checkout;
- доступная версия на GitHub по `portal_update_github_repo` /
  `portal_update_github_ref`;
- время последней проверки;
- состояние support tool `/usr/local/sbin/sesame-portal-update`;
- кнопки `Проверить обновления` и `Обновить Portal`.

Требует admin.

Ответ: HTML.

### POST /admin/settings

Управляет проверкой и установкой обновления Portal.

Требует admin и CSRF.

Actions:

| `action` | Поля | Описание |
| --- | --- | --- |
| `check_update` | - | Принудительно проверить последний commit на GitHub и обновить cache `/var/lib/sesame-portal/portal-update-status.json`. |
| `run_update` | - | Запустить configured update command, по умолчанию `sudo -n /usr/local/sbin/sesame-portal-update`. |

`run_update` скачивает выбранную ветку GitHub, устанавливает новый release,
переключает `/opt/sesame-portal/current`, запускает миграции и планирует reload php-fpm.
Web-процесс не пишет напрямую в `/opt`: installer выдаёт пользователю
`www-data` sudoers-право только на точный запуск
`/usr/local/sbin/sesame-portal-update` без произвольных аргументов. Параметры
updater-а для production установки хранятся в root-owned
`/etc/sesame-portal-update.conf`.

Audit actions:

- `portal.update.start`;
- `portal.update.complete`;
- `portal.update.failed`.

В audit пишутся repo, ref, return code и IP. Полный stdout/stderr updater-а
показывается только в HTML notice/details текущему admin.

## Admin users

### GET /admin/users

Список пользователей и форма создания/редактирования.
В таблице показывается статус `Static token`: `есть` или `нет`. Сам token
после выпуска повторно не отображается, потому что в базе хранится только hash.
В форме пользователя отображается дерево групп с множественным выбором,
кнопками `Выбрать все` / `Снять все` и раскрытием подгрупп через `+` / `-`.

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
| `save` | `id`, `login`, `password`, `role`, `blocked`, `group_ids[]` | Создать или обновить пользователя. Для нового пользователя пароль должен быть не короче 6 символов. При редактировании пустой `password` означает "не менять". `role`: `admin` или `user`. `group_ids[]` полностью заменяет membership пользователя в группах. |
| `delete` | `id` | Удалить пользователя. |
| `issue_static` | `id` | Выпустить static token. Если token уже был, действие заменяет его, старый token сразу перестаёт работать. Новый token показывается один раз в HTML notice. |
| `revoke_static` | `id` | Отозвать static playback token. |

`blocked` передаётся как checkbox: присутствует = `1`, отсутствует = `0`.

UI показывает кнопку `Выпустить статический токен`, если token отсутствует, и
`Заменить статический токен`, если token уже есть. Замена требует browser confirm.
Кнопка `Отозвать` показывается только при наличии token.

Audit actions: `user.static_token.issue` для первого выпуска,
`user.static_token.replace` для замены существующего token и
`user.static_token.revoke` для отзыва. Значение token в audit не пишется.

Ответ: HTML.

## Admin groups

### GET /admin/groups

Список групп и форма создания/редактирования. Группа может иметь
`parent_group_id`, то есть быть подгруппой другой группы.

Требует admin.

Таблица групп показывает DB `id` группы отдельной колонкой `ID`, а также
родительскую группу. Этот ID используется публичным JSON API для
`/api/portal/v1/groups/{id}`.

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

Поле `parent_group_id` задаёт родительскую группу. Пустое значение делает
группу корневой. UI не должен разрешать выбрать родителем саму группу или её
подгруппу. При удалении группы её прямые подгруппы переводятся в корневые, а не
удаляются вместе с родителем.

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
| `q` | Регистронезависимый поиск по имени камеры, source URL, имени сервера или `dvr_stream_name`. |
| `page` | Номер страницы. |
| `edit` | ID камеры для заполнения формы редактирования. |
| `delete` | ID камеры для показа панели подтверждения удаления. |

Форма создания также может быть предзаполнена query-параметрами:

| Параметр | Описание |
| --- | --- |
| `display_name` / `displayName` / `name` | Название потока. Используется как имя камеры в Portal. |
| `stream` / `dvr_stream_name` / `dvrStreamName` | Техническое имя потока SesameDVR: только `A-Z`, `a-z`, `0-9`, `-` и `_`, максимум 128 символов. |
| `source_url` | URL источника. |
| `server_id` | ID DVR-сервера. |
| `retention_days` | Глубина архива, например `7d`. |
| `mode` / `dvr_control_mode` | `managed`, `edge_agent` или `read_only`. |
| `agent_id` | Edge Agent ID. |
| `agent_camera_id` | ID камеры внутри агента. |
| `onvif_events_requested` | Непустое значение включает ONVIF events через агента. |
| `watermark_enabled` | Непустое значение включает watermark с login пользователя в player. |
| `watermark_intensity` | Интенсивность watermark в процентах, по умолчанию `16`. |

Ответ: HTML.

UI details:

- если открыт `edit=<id>`, заголовок формы показывает режим редактирования и
  ссылку `Новая камера`; ссылка убирает `edit`, но сохраняет текущие `q` и
  `page`, чтобы не терять контекст списка;
- действия строк таблицы (`edit`, `delete`, `sync`) сохраняют текущие `q` и
  `page`;
- действия в строках таблицы отображаются icon-only кнопками с `title` и
  `aria-label`;
- колонка `Результат` (`last_sync_message`) отображает только цветной статус:
  зелёный - успешная синхронизация, красный - ошибка, оранжевый - read-only
  режим. Полный текст хранится в tooltip/`aria-label`.
- после сохранения камеры верхний notice показывает только короткий
  пользовательский статус; полный HTTP/JSON ответ DVR остаётся в
  `last_sync_message`, tooltip результата и audit.

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
| `display_name` | Название потока. Используется как имя камеры в Portal. Старое поле `name` продолжает приниматься как alias. |
| `source_url` | URL источника. Обязателен для `managed`. |
| `server_id` | ID DVR-сервера. Может быть пустым, если `server_selection=auto`. |
| `server_selection` | `manual` или `auto`. Для `edge_agent` принудительно используется `manual`. |
| `dvr_control_mode` | `managed`, `edge_agent`, `read_only`. |
| `dvr_stream_name` | URL-safe техническое имя stream. Если пусто, строится из `display_name`/`name`. Если `display_name` пустое, имя камеры берётся из `dvr_stream_name`. |
| `agent_id` | Edge Agent ID. Обязателен для `edge_agent`. |
| `agent_camera_id` | ID камеры на стороне агента. Обязателен для `edge_agent`. |
| `onvif_events_requested` | Checkbox для ONVIF events через агента. |
| `watermark_enabled` | Checkbox для watermark с login пользователя поверх player. |
| `watermark_intensity` | Интенсивность watermark в процентах, `1`-`100`. |
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

В журнал попадают, среди прочего:

- `auth.login` / `auth.login_failed` с login и IP входа в UI;
- `archive.download` с user, IP, camera/stream и диапазоном MP4 архива после
  успешной проверки `/api/sesamedvr/auth`.

Query parameters:

| Параметр | Описание |
| --- | --- |
| `q` | Поиск по action, actor login или details. |
| `action` | Фильтр по конкретному action. |
| `actor` | ID пользователя-актора. |
| `page` | Номер страницы. Размер страницы: 50 записей. |

Ответ: HTML.
