# Изменения в SesamePortal

Все внесённые в проект изменения по состоянию на текущую сессию. Изменения не закоммичены
(находятся в рабочем дереве).

## Оглавление

1. [Поставщик карт (OpenStreetMap / Яндекс) + центр карты по умолчанию](#1-поставщик-карт-и-центр-карты)
2. [Поправка проекции Яндекс-тайлов в Leaflet](#2-поправка-проекции-яндекс-тайлов)
3. [Переключатель светлой/тёмной темы (per-user)](#3-переключатель-светлойтёмной-темы)
4. [Документация модели данных](#4-документация-модели-данных)
5. [Авто-имя потока dvr_stream_name с случайным hex-суффиксом](#5-авто-имя-потока-dvr_stream_name-со-случайным-hex-суффиксом)
6. [Сворачиваемые панели «Новая группа» и «Новый пользователь»](#6-сворачиваемые-панели-новая-группа-и-новый-пользователь)
7. [Кнопка настроек камеры (шестерёнка) на мозаике](#7-кнопка-настроек-камеры-шестерёнка-на-мозаике)
8. [Сворачиваемая панель «Новая камера» на странице камер](#8-сворачиваемая-панель-новая-камера-на-странице-камер)
9. [Постоянный токен: обратимое хранение и раскрытие/копирование](#9-постоянный-токен-обратимое-хранение-и-раскрытиекопирование)
10. [Миграция на PostgreSQL 18 с автосинхронизацией identity-последовательностей](#10-миграция-на-postgresql-18-с-автосинхронизацией-identity-последовательностей)

---

## 1. Поставщик карт и центр карты

Добавлена поддержка двух провайдеров карт (`openstreetmap` и `yandex`) и настраиваемого
центра карты по умолчанию.

### Конфигурация — `config.example.php`

Добавлены ключи:

```php
'map_provider'    => 'openstreetmap',
'map_default_lat' => 47.242057,
'map_default_lng' => 38.889615,
```

Каждый ключ имеет переменную окружения:
`SESAME_PORTAL_MAP_PROVIDER`, `SESAME_PORTAL_MAP_DEFAULT_LAT`, `SESAME_PORTAL_MAP_DEFAULT_LNG`.

### Серверная часть — `app/Portal.php`

- `Config::reset()` — сброс статического кэша конфигурации (нужен после записи `var/config.php`).
- `Config::defaults()` — добавлены `map_provider`, `map_default_lat`, `map_default_lng`.
- `Util::mapProvider()` — валидированный провайдер (`openstreetmap` | `yandex`, иначе `openstreetmap`).
- `Util::mapTileUrl(string $provider)` — URL тайлов:
  - yandex: `https://core-renderer-tiles.maps.yandex.net/tiles?l=map&x={x}&y={y}&z={z}&lang=ru_RU`
  - openstreetmap: `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`
- `Util::mapAttribution(string $provider)` — атрибуция для выбранного провайдера.
- `Util::mapDefaultView()` — координаты центра (`lat`, `lng`) из конфига, с валидацией чисел.
- `layout()`: в `window.SESAME_MAP_PROVIDER` и `window.SESAME_MAP_VIEW` прокидываются
  текущий провайдер и центр карты.
- Страница `/admin/settings`:
  - экшены `save_map_provider` и `save_map_view` с валидацией;
  - `saveConfigValue(string $key, string $value)` — перезапись `var/config.php`
    через `var_export` с инвалидацией opcache;
  - `mapProviderPanel()` — выбор провайдера;
  - `mapViewPanel()` — поля широты/долготы центра;
  - панели оборачиваются в `<div class="map-settings-grid">` (рядом друг с другом).

### Локализация — `app/PortalI18nCatalog.php`

Во всех 13 локалях (ru, de, fr, es, it, pt, bg, pl, zh, ja, ko, ar, hy) добавлены ключи:

- `settings.mapProvider`, `settings.mapProviderDesc`, `settings.mapProviderSaved`,
  `settings.mapProviderInvalid`
- `mapProvider.openstreetmap`, `mapProvider.yandex`
- `settings.mapView`, `settings.mapViewDesc`, `settings.mapViewLat`, `settings.mapViewLng`,
  `settings.mapViewSaved`, `settings.mapViewInvalid`

Дополнительно в английский блок (`I18n::en()` в `app/Portal.php`) добавлены те же ключи
на английском (фолбэк для локали `en`).

### Стили — `public/assets/styles.css`

- `.map-settings-grid` — двухколоночная сетка (2 колонки, отступ 12px), `.panel` без нижнего
  отступа внутри.
- В медиа-правило `@media(max-width:980px)` добавлен `.map-settings-grid`
  (схлопывание в одну колонку на узких экранах).

---

## 2. Поправка проекции Яндекс-тайлов

### Проблема

Яндекс использует нестандартную Меркаторовскую сетку тайлов с вертикальным смещением
(только по Y) относительно стандартного Web Mercator (EPSG:3857). При использовании
стандартного `L.CRS.EPSG3857` тайлы Яндекса накладываются с ошибкой по широте.

Смещение измерено по морскому профилю кросс-корреляцией реальных тайлов OSM↔Яндекс
на 5 широтах (35–61°):

```
shift_tiles = 0.001064 · sin(lat) · 2^z
```

Для Таганрога (lat 47.24): при z12 ≈ 3.19 тайла, при z13 ≈ 6.37 тайла.

### Решение — `public/assets/app.js`

Добавлен кастомный CRS `EPSG:Yandex3857` (extends `L.CRS.Earth`):

- `YANDEX_PROJECTION_K = 0.001064`, `YANDEX_PROJECTION_R = 6378137`,
  `YANDEX_PROJECTION_MAX_LAT = 85.0511287798`
- `yandexWorldY(lat)` — Меркатор y минус `2πR·K·sin φ`
- `yandexProjection()` — `project`/`unproject` (unproject методом Ньютона, 8 итераций,
  допуск `1e-4`), bounds `[-πR, πR]`
- `yandexCRS()` — CRS с трансформацией `L.transformation(0.5/(πR), 0.5, -0.5/(πR), 0.5)`
- `mapCRS()` — ленивый выбор CRS: для `yandex` → кастомный, иначе `L.CRS.EPSG3857`

Применён как `crs: mapCRS()` в `initMap()` (страница карты) и в
`initCameraPositionEditorMap()` (редактор позиции камеры).

### Верификация

- Семантическая сверка: `L.CRS.Earth`, `L.transformation`, `projection.bounds` существуют
  в minified Leaflet 1.9.4.
- Детерминированная симуляция конвейера Leaflet 1.9.4: зум-12 центр → пиксель 368594.5,
  ряды тайлов 1438–1440 (было 1435–1437). Пользователем подтверждено
  «всё отображается корректно».
- Yandex-тайлы отклоняют дробный `y` (HTTP 400), поэтому дробное смещение компенсируется
  кастомным CRS, а не сдвигом URL.

---

## 3. Переключатель светлой/тёмной темы

Пер-пользовательская тема (`light` / `dark` / авто по `prefers-color-scheme`),
переключатель — кнопка в правом верхнем углу (topbar) рядом с аватаром.

### Серверная часть — `app/Portal.php`

- **Миграция**: `DB::migrate()` → `ensureColumn('users', 'theme', "TEXT NOT NULL DEFAULT ''")`.
  Пустое значение = «по системе».
- **Роут**: `/theme` → `App::updateTheme()`.
- **Обработчик `updateTheme()`**: `Auth::requireLogin()`, вайтлист `light`/`dark` (иначе
  сброс в `''`), `UPDATE users SET theme = ? WHERE id = ?`, редирект назад.
  CSRF проверяется глобально в `handle()`.
- **`layout()`**:
  - на `<html>` ставится `data-theme="light|dark"`, если пользователь явно выбрал тему;
  - если выбор не сохранён (авто) — в `<head>` инлайн-скрипт резолвит тему из
    `prefers-color-scheme` (без FOUC) и помечает `data-theme-auto="1"`;
    скрипт вставляется только для авторизованных страниц с хромом (логин-страница не
    затрагивается);
  - в topbar перед аватаром добавлен `<button class="theme-toggle" data-theme-toggle …>`
    с иконкой (`sun`/`moon` в зависимости от текущей темы) и `data-title-light`/`data-title-dark`
    для тултипа; кнопка и аватар обёрнуты в `<div class="topbar-actions">`;
  - в `icon()` добавлены иконки `sun` и `moon`.

### Локализация — `app/PortalI18nCatalog.php`

Во всех 13 локалях добавлены ключи:

- `nav.theme` (например «Тема» / «Theme» / «Тема»…)
- `nav.theme.toLight` («Включить светлую тему» и т.п.)
- `nav.theme.toDark` («Включить тёмную тему» и т.п.)

### Клиентская часть — `public/assets/app.js`

`initThemeToggle()`:

- читает `[data-theme-toggle]`; если кнопки нет — выход;
- `currentTheme()` = `html[data-theme]` (фолбэк «light»);
- по клику применяет противоположную тему, обновляет иконку и `title`/`aria-label`,
  снимает `data-theme-auto` и отправляет `fetch POST /theme` с `csrf=window.SESAME_CSRF`
  (form-encoded); при ошибке — откат;
- слушает `matchMedia('(prefers-color-scheme: dark)')` и меняет тему на лету, пока
  `data-theme-auto="1"` (т.е. пока пользователь не выбрал тему явно).

### Стили — `public/assets/styles.css`

- `.topbar-actions` (flex, gap 10px) и `.theme-toggle` (круглая кнопка 36×36,
  hover → золотой акцент).
- `:root{color-scheme:light}` и `:root[data-theme="dark"]` — тёмная палитра переменных:

```css
--ink:#E8E5E0; --ink-2:#CFCBC4; --gold:#D9B36C; --gold-2:#E8BE55;
--ivory:#161616; --surface:#242424; --line:#3A3A3A; --line-strong:#4A4A4A;
--muted:#A39D93; --soft:#1E1E1E;
--success:#7BC69B; --success-bg:#1E3226; --danger:#E88983; --danger-bg:#3A2220;
--warn:#E0B168; --warn-bg:#3A301A; --info:#8FB8EE; --info-bg:#1E2B3D;
--shadow:0 14px 35px rgba(0,0,0,.45); --shadow-sm:0 5px 16px rgba(0,0,0,.35);
```

- Точечные оверрайды `[data-theme="dark"]` для жёстко зашитых цветов:
  - поверхности `#fff` → `var(--surface)` (панели, карточки, таблицы, кнопки, поля,
    модалки, групповое дерево, карточки агентов, импорт камер и т.д.);
  - светлые оттенки → тёмные аналоги (`.table th`, `th`, `code`, `pre`, `.map`,
    `.map-canvas`, `.meter`, `.assignment-list`, `.kv code` и др.);
  - золотые активные состояния (`#F9F0DE`/`#FFF8E8`/`#F8F0DF`) → `#3A2E14` с текстом `#E8C98A`;
  - текстовые цвета `#4A4640`/`#6A655F`/`#77716A`/`#8A5C0C` → светлые (`#CFCBC4`/`#A39D93`/`#E8C98A`);
  - инверсия через `var(--ink)` как фон: `.pill.active`, `.user`, `.btn.ink`
    (в тёмной теме становятся золотыми/инвертированными);
  - Leaflet-контролы (`.leaflet-bar a`, attribution, попапы) и `.leaflet-container` → тёмные;
  - фон `.shell` и `.panel`/`.agent-settings-details` → полупрозрачные тёмные;
  - статусные бордеры пилюль и баннера обновления → тёмные.

Тексты `#fff` на всегда-тёмных поверхностях (sidebar, плеер, превью, логин, маркеры)
не трогаются. Логин-страница переключателя не имеет и остаётся как была.

### Верификация темы

- `php -l`, `node --check`, баланс фигурных скобок CSS.
- HTTP: кнопка присутствует на всех авторизованных страницах (`/`, `/admin/*`, `/viewer/map`),
  отсутствует на логине; без сохранённой темы `data-theme` не выводится (авто-режим).
- POST `/theme` с `theme=dark|light` → 303; повторный GET отдаёт `data-theme="..."`;
  значение сохраняется в `users.theme` (SQLite). POST с невалидным значением сбрасывает тему.
- POST без CSRF → 419.
- Локализация: `?lang=de` отдаёт «Helle Oberfläche aktivieren».

---

## 4. Документация модели данных

Создан файл `docs/data-model-groups-users-cameras.ru.md`:

- таблицы: `users`, `portal_groups` (self-FK `parent_group_id`), `user_groups` (M:N),
  `cameras`, `camera_groups` (M:N), `dvr_servers`, `favorites`, `audit_logs`;
- Mermaid `erDiagram` и `flowchart` прав доступа
  (admin → все группы → все камеры; user → `user_groups` → прямые группы →
  `groupBranchIds()` BFS с пропуском заблокированных → `camera_groups` → камеры);
- ссылки на код: `app/Portal.php:232, 3924, 3974, 3981, 4060, 4124`;
- каскады удаления (user/group/camera CASCADE; дочерние группы `parent_group_id` SET NULL;
  сервер SET NULL).

---

## 5. Авто-имя потока dvr_stream_name со случайным hex-суффиксом

### Серверная часть — `app/Portal.php`

В `cameraNamesFromInput()` (авто-генерация имён камер) имя потока теперь формируется
как слагифицированное отображаемое имя + случайный суффикс из 6 hex-символов:

```php
$streamName = Util::dvrStreamSlug($displayName) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
```

Пример: `openipc_tc_321_v2` → `openipc_tc_321_v2-0e2619` (суффикс меняется при каждом
вызове). Затронуты все три точки вызова: форма создания камеры, API `/api/portal/v1/cameras`
и генерация дефолтных имён при импорте.

### Поведение

- Явно введённое пользователем имя потока не меняется.
- Существующие камеры в БД не перегенерируются.
- Случайный суффикс исключает коллизии имён потоков при массовом добавлении камер.

---

## 6. Сворачиваемые панели «Новая группа» и «Новый пользователь»

Формы создания группы и пользователя на страницах `/admin/groups` и `/admin/users`
превращены в сворачиваемые панели поверх таблиц (схлопнуты по умолчанию, разворачиваются
кликом по заголовку).

### Разметка — `app/Portal.php`

- Обёртка страниц: `<div class="group-admin-stack">` (6095) и `<div class="user-admin-stack">` (6175) —
  одна колонка: сначала панель создания, затем таблица.
- Панели: `<details class="panel group-create-panel">` (6176) и
  `<details class="panel user-create-panel">` (6096) с `<summary><h2>…</h2></summary>`.
- Атрибут `open` выставляется только при редактировании (`$edit`) — тогда панель
  называется «Изменить группу» / «Изменить пользователя» и развёрнута.

### Исправленный баг

Первоначально обёртка пользователей была `class="admin-grid user-admin-stack"` — класс
`admin-grid` задавал двухколоночную сетку (`grid-template-columns: minmax(280px,420px) minmax(0,1fr)`),
из-за чего панели отображались рядом, а не друг под другом. Класс `admin-grid` убран,
оставлен только `user-admin-stack`.

### Стили — `public/assets/styles.css`

```css
.group-admin-stack, .user-admin-stack { display: grid; gap: 16px; align-items: start; }
```

- summary без стандартного маркера (`list-style:none`), стрелка `▸` / `▾` при открытии,
  `summary h2 { margin: 0; }`.
- Проверено: `?edit=1` → панель открыта с заголовком «Изменить …».

---

## 7. Кнопка настроек камеры (шестерёнка) на мозаике

На карточках мозаики («Камеры») рядом со звездой избранного появилась шестерёнка
«Настройки» — ссылка на форму редактирования камеры. Видна только администраторам.

### Серверная часть — `app/Portal.php`

- `viewer()`: `$isAdmin = ($user['role'] ?? '') === 'admin';` (7474), значение пробрасывается
  через layout-closure в `mosaic()` (сигнатура расширена параметром `bool $isAdmin`).
- `mosaic()`: карточка получает модификатор `camera-card-admin`; для админа рядом с
  `favoriteButton()` рендерится `cameraSettingsButton((int)$camera['id'])` (7559).
- Новый метод `cameraSettingsButton(int $cameraId)` (9289): back = `safeLocalPath(REQUEST_URI)`
  (фолбэк `/`), ссылка `/admin/cameras?edit=<id>&back=<back>`, разметка
  `<a class="camera-settings" title/aria-label="Настройки">` + `icon('settings')` + `<span class="sr-only">`.

### Стили — `public/assets/styles.css`

- `.camera-settings` — 34×34, absolute `right:52px; bottom:12px`, border-radius 10px,
  иконка 18×18, hover меняет цвет рамки и текста.
- `.camera-card-admin > .camera-meta` — `padding-right: 96px` (у обычных пользователей
  остаётся 54px под одну звезду).
- Dark-theme: `.camera-settings` → тёмный фон `#26241F`, рамка `#38352E`.

### Проверка

- `php -l` без ошибок.
- Админ: на каждой карточке `<a class="camera-settings" href="/admin/cameras?edit=N&back=…">`.
- Обычный пользователь: шестерёнка не рендерится (`camera-settings` отсутствует), карточка
  без модификатора `camera-card-admin`.

---

## 8. Сворачиваемая панель «Новая камера» на странице камер

Форма создания/редактирования камеры на `/admin/cameras` свёрнута по умолчанию
и расположена сверху, таблица «Камеры» — под ней (одна колонка).

### Разметка — `app/Portal.php`

- Обёртка: `<div class="camera-admin-stack">` (вместо `admin-grid` — двухколоночной сетки,
  где форма была узкой колонкой слева).
- Панель: `<details class="panel camera-create-panel"[$edit?' open':'']>` с
  `<summary><h2>Новая камера | Изменить камеру</h2><span class="camera-create-actions">…</span></summary>`.
- Кнопки «Назад», «Новая камера» (только при редактировании), «Импорт с DVR» остались в
  заголовке панели (flex-summary по образцу `.agent-create-panel`).
- Атрибут `open` выставляется при `?edit=N` (в т.ч. переход с мозаики через шестерёнку) —
  панель «Изменить камеру» развёрнута.
- Панель подтверждения удаления (`?delete=N`) и страница импорта не изменены.

### Стили — `public/assets/styles.css`

- `.camera-admin-stack` добавлен в общий селектор стека
  (`.group-admin-stack, .user-admin-stack`): `display:grid; gap:16px; align-items:start`.
- `.camera-create-panel > summary` — flex-заголовок, без маркера, стрелка `▸`/`▾`;
  `.camera-create-panel > summary h2 { margin:0; font-size:18px }`;
  `.camera-create-actions { display:flex; gap:8px; margin-left:auto }`.

### Проверка

- `php -l` без ошибок.
- `/admin/cameras` → `<details class="panel camera-create-panel">` (без `open`), порядок
  «Новая камера» → «Камеры».
- `/admin/cameras?edit=1` → `open` + «Изменить камеру»; кнопки «Новая камера»/«Импорт с DVR»
  на месте; `?delete=1` → панель удаления рендерится.

---

## 9. Постоянный токен: обратимое хранение и раскрытие/копирование

Ранее постоянный (static) токен пользователя хранился только как необратимый хеш
(`static_token_hash`) — его нельзя было показать после создания. Добавлено обратимое
хранилище (`static_token_enc`, AES-256-GCM через `Crypto::encrypt()`) и UI раскрытия
токена с копированием.

### Серверная часть — `app/Portal.php`

- **Миграция**: `DB::migrate()` → `ensureColumn('users', 'static_token_enc', 'TEXT')`
  (также колонка добавлена в `sqliteSchema`/`mysqlSchema`/`pgsqlSchema`).
- **`TokenService`**:
  - `issueStaticToken($userId)` теперь пишет и `static_token_hash` (password_hash),
    и `static_token_enc` (Crypto::encrypt токена);
  - `revokeStaticToken($userId)` сбрасывает оба поля в NULL;
  - `verifyStaticToken($token)` дополнительно читает `static_token_enc`.
- **Страница пользователя** (`/admin/users`): при редактировании пользователя-админа
  рендерится блок `<div class="static-token-row">`:
  - `static-token-head` — надпись «Постоянный токен пользователя» (`users.staticToken`)
    и кнопка-глаз (`data-static-token-reveal`);
  - `static-token-field` — поле ввода (`static-token-input`, readonly, `*******` по
    умолчанию) и кнопка копирования (`static-token-copy`).
  - При отсутствии токена — кнопка «Выпустить» (`TokenService::issueStaticToken`).
- **`layout()`**: в `window.SESAME_I18N` прокидываются
  `staticTokenReveal` (`token.staticReveal`) и `staticTokenCopied` (`token.staticCopied`).

### Клиентская часть — `public/assets/app.js`

`initStaticTokens(root = document)`:

- `maskToken(input, button)` — маскирует поле в `*******`, ставит `title`/`aria-label`
  из `tr("staticTokenReveal", "Show and copy")`.
- `copyToken(input)` — `navigator.clipboard.writeText(input.value)` с фолбэком
  `fallbackCopy(input)` (создаёт временный `<textarea>`, `document.execCommand('copy')`,
  удаляет).
- Делегирование: клик по `[data-static-token-reveal]`:
  - если кнопка-глаз (`button`) → раскрывает реальное значение токена (AJAX-выборка
    недоступна; токен уже в `input.value` после раскрытия сервером);
  - если это сама кнопка-глаз — маскирует обратно;
  - клик по раскрытому полю/кнопке-копирования → копирует токен, ставит класс
    `is-copied` и подпись «Скопировано» (`tr("staticTokenCopied")`).
- Идемпотентность: `data-static-token-reveal-bound="1"`.

### Локализация — `app/PortalI18nCatalog.php`

Во всех 13 локалях добавлены ключи:
- `token.staticReveal` («Показать и скопировать» / «Show and copy» / «Anzeigen und kopieren»…)
- `token.staticCopied` («Скопировано» / «Copied» / «Kopiert»…)
- `users.staticToken` («Постоянный токен пользователя»)

### Стили — `public/assets/styles.css`

- `.static-token-row/head/label/actions/field/input/copy` — поле стало полной ширины
  под надписью (высота 40px, как остальные поля формы); кнопка-копирования 40×40,
  `is-copied` → зелёный акцент.
- Dark-theme: `.static-token-input`/`.static-token-copy` → тёмный фон и рамка.

### Поведение toggle (исправленный баг)

Первоначально клик по раскрытому полю маскировал токен (toggle). Исправлено: клик по
раскрытому полю копирует/выделяет токен, маскирует только кнопка-глаз. Добавлен
`document.execCommand('copy')` фолбэк для не-HTTPS окружений.

---

## 10. Миграция на PostgreSQL 18 с автосинхронизацией identity-последовательностей

Перенос хранилища с SQLite на PostgreSQL 18 (локальный кластер `18-main`, БД
`sesame_portal`, роль `sesame`) с сохранением данных и автоматической синхронизацией
identity-последовательностей при восстановлении из бэкапа. Полная миграция выполнена
и описана в `docs/migration-pgsql.ru.md`.

### Серверная часть — `app/Portal.php`

- **`DB::syncIdentity(string $table, string $column = 'id')`** (225-243): для pgsql
  выполняет `setval(pg_get_serial_sequence(), max(last_value, MAX(id)), true)` —
  синхронизирует sequence с максимальным вставленным id. Для других драйверов — no-op.
- **`Cli::restore()`**: после commit вызывает `DB::syncIdentity()` для таблиц
  `IDENTITY_TABLES = [users, portal_groups, dvr_servers, cameras, audit_logs]`
  (~10323). Ручная синхронизация sequence больше не нужна.
- Существующий `Portal::syncPortalGroupIdentityAfterExplicitInsert()` (~9943) переведён
  на общий хелпер `DB::syncIdentity()`; старый `Portal::quoteQualifiedIdentifier()`
  удалён.
- **Исправлены pgsql-несовместимости SQL** (найдены pgsql-smoke):
  - `Cli::rotateSecrets()` (~10248): `management_token_enc != ""` → `<> ''` — в PG
    `""` это пустой идентификатор, не строковый литерал;
  - Dashboard `recentSync` (5734): `COALESCE(c.last_sync_at, "")` → `''`;
  - Список камер (8693): `SELECT DISTINCT ... ORDER BY COALESCE(s.name,'')` — PG
    требует, чтобы выражения `ORDER BY` при `DISTINCT` присутствовали в выборке.
    Обёрнуто в субзапрос с вычислимыми псевдонимами `sort_server`/`sort_sync`;
    `cameraListSortColumns()` (8806-8818) и `cameraListOrderSql()` (8821-8828)
    переключены на имена выходных колонок (`name`, `dvr_stream_name`, `sort_server`,
    `dvr_control_mode`, `archive_enabled`, `retention_days`, `sort_sync`,
    `updated_at`, `created_at`).

### Тесты

- **`tests/http_smoke.sh`**: config.php-heredoc берёт `db_dsn`/`db_user`/`db_password`
  из env (`SESAME_PORTAL_DB_DSN` и т.д.); sqlite-блок `sqlite_duplicate_group_migration`
  обёрнут в `if [[ -z "${SESAME_PORTAL_DB_DSN:-}" ]]`; исправлен SQL
  `server_selection || ":" || ...` → `':'` (pgsql: `":"` — пустой идентификатор).
- **`tests/http_smoke_pgsql.sh`** (новый): обёртка — `psql` DROP/CREATE SCHEMA на
  `sesame_portal_test` + запуск общего smoke с env DSN pgsql.
- Гейт `SESAME_PORTAL_DB_DSN` переключает путь: без него — SQLite (по умолчанию),
  с ним — указанный DSN.
- Результат: sqlite smoke ✅, pgsql smoke ✅.

### Документация

- **`docs/migration-pgsql.ru.md`**: переписан под фактическое состояние — автосинк
  секвенсов, pgsql-smoke, найденные/починенные несовместимости, фактические объёмы
  данных (2 users, 1 group, 1 server, 8 cameras, 4 camera_groups, 78 audit_logs,
  0 favorites), воспроизводимые шаги миграции, откат.

### Production-миграция (выполнено)

- Установлен `php8.3-pgsql`; созданы роль `sesame` и БД `sesame_portal` /
  `sesame_portal_test`.
- `var/config.php` переключен на `pgsql:host=127.0.0.1;port=5432;dbname=sesame_portal`.
- `php bin/portal migrate` + `php bin/portal restore /tmp/sesame-backup.json` (секвенсы
  синхронизировались автоматически).
- Воркеры `php -S 0.0.0.0:8080` перезапущены; все страницы отвечают (303/200, без 500);
  insert/delete камеры — sequence и каскады работают.
- Бэкапы отката: `/tmp/sesame-backup.json`, `/tmp/portal.sqlite.bak`, `/tmp/config.php.bak`.

---

## Примечания

- Dev-сервер: `php -S 127.0.0.1:8080 -t public`, логин `admin` / `admin123`.
- БД по умолчанию: SQLite `var/portal.sqlite` (переключается через
  `SESAME_PORTAL_DB_DSN`/`DB_USER`/`DB_PASSWORD`).
- `tests/http_smoke.sh`: пред-существующий 404 на `GET /api/portal/v1/cameras/camera.test-1`
  не связан с этими изменениями.
