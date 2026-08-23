# План добавления PWA в SesamePortal

> Статус: задокументировано, реализация — позже.
> Дата составления: 2026-08-13.

## Контекст проекта

SesamePortal — PHP-монолит (`app/Portal.php`, ~11.5k строк) для управления камерами
видеонаблюдения SesameDVR.

- **Продакшн**: nginx + php-fpm, HTTPS через certbot (см. `scripts/install.sh`).
- **Dev**: `php -S` built-in сервер, отдающий `public/` (см. `start-portal.sh`).
- **Аутентификация**: cookie-сессия (`sesame_portal`), серверный рендеринг HTML.
- **Статика**: `public/assets/` с кеш-бастингом `?v=mtime` (через `assetUrl()`).
- **Видео/превью**: iframe и 302-redirect на **внешние** DVR-серверы (cross-origin).
- **Карта**: Leaflet, тайлы с CDN (OpenStreetMap/Yandex).
- **Тема**: светлая/тёмная, авто через `prefers-color-scheme`.

PWA в проекте отсутствует полностью.

## Реалистичный scope

Видео и превью приходят с внешних DVR-серверов, а список камер рендерится сервером
из БД за сессией. **Полный оффлайн** (доступ к камерам без сети/портала) невозможен
по природе приложения.

Поэтому PWA здесь даёт:

1. **Installability** — иконка на домашнем экране, standalone-режим, splash,
   theme-color (главная ценность для мобильного оператора).
2. **App-shell caching** — статику (`app.js`, `styles.css`, Leaflet, иконки) в
   SW-кеш → мгновенный холодный старт даже на плохой сети.
3. **Offline fallback** — изящная страница «Портал недоступен / вы офлайн» вместо
   ошибки браузера.
4. **Resilience** — network-first для HTML (свежие данные), SWR/cache-first для
   статики.

**Не кешируем** превью/видео/тайлы карты: cross-origin, токены в URL, быстро
устаревают, раздувает кеш.

## Принятые решения (выбрано при планировании)

| Аспект | Решение |
|---|---|
| Иконки PNG | Скрипт генерации + `rsvg-convert` (воспроизводимо) |
| Offline-стратегия | App-shell + `offline.html` (статика + fallback) |
| Display mode | `standalone` |
| Manifest | Статический файл |

## Новые файлы

### `public/manifest.json`

Статический файл (статика кешируется, простой MIME, не зависит от языка).

- `name`: "SesamePortal"
- `short_name`: "Portal" (≤12 символов)
- `description`, `lang:"ru"`, `dir:"ltr"`
- `start_url:"/?source=pwa"`, `scope:"/"`, `display:"standalone"`, `orientation:"any"`
- `background_color:"#121212"`, `theme_color:"#C1964E"` (light) + dark через `media`
- `categories:["utilities","video"]`
- `icons`: `icon-192.png` (192×192, purpose:"any"), `icon-512.png` (512×512,
  purpose:"any"), `icon-512-maskable.png` (purpose:"maskable"), `icon.svg`
  (purpose:"any", scalable)
- `shortcuts`: Камеры (`/?source=pwa`), Мозаика (`/mosaic`), Карта
  (`/viewer/map`), Избранное (`/?filter=favorites`) — с иконками

### `public/sw.js`

Service worker. Версия в константе `CACHE_VERSION`.

- **install**: precache app-shell — `/assets/app.js`, `/assets/styles.css`,
  `/assets/favicon.svg`, `/assets/icons/*.png`, `/offline.html`, `/manifest.json`;
  `skipWaiting()`.
- **activate**: удалить кеши не текущей версии, `clients.claim()`.
- **fetch** (стратегии по URL):
  - navigation (mode `navigate`) → **network-first** → fallback cached HTML →
    `/offline.html`.
  - same-origin `/assets/*` → **stale-while-revalidate**.
  - `unpkg.com/leaflet*` (.js/.css) → **SWR**, `mode:"no-cors"` (opaque).
  - `/viewer/preview`, `/viewer/player`, `/api/*`, DVR-embed (cross-origin),
    карта-тайлы → **network-only** (без перехвата, `return fetch(...)`).
  - всё прочее cross-origin → **network-only**.

Без Background Sync / Push (out of scope; DVR-токены и cross-origin делают это
бессмысленным).

### `public/offline.html`

Статическая, стили инлайн (палитра `--ivory`/`--gold`), иконка inline-SVG,
сообщение «Портал недоступен — проверьте подключение», кнопка «Повторить»
(`location.reload()`). Минимальный i18n: RU + EN через `navigator.language`.

### `public/assets/icons/`

`icon-192.png`, `icon-512.png`, `icon-512-maskable.png`, `icon.svg`
(копия/адаптация `mark.svg` с safe-zone для maskable). PNG генерируются скриптом и
коммитятся.

### `scripts/generate-icons.sh`

Генерация PNG из SVG через `rsvg-convert` (fallback на Inkscape). Источник —
`public/assets/logo-sesameportal.svg` (или `mark.svg`). Маскируемая версия =
центрированный знак на сплошном фоне `#121212` с padding ~20% (safe-zone). Запуск:
`bash scripts/generate-icons.sh`.

## Изменения существующих файлов

### `app/Portal.php` → `layout()` (стр. 9175)

Добавить в `<head>` после `<meta name="viewport">`:

```html
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#C1964E" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121212" media="(prefers-color-scheme: dark)">
<link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="SesamePortal">
<meta name="mobile-web-app-capable" content="yes">
```

Важно: и для `showChrome=true`, и для `showChrome=false` (login) — manifest/install
работают с любой страницы.

### `public/assets/app.js`

В конце IIFE:

```js
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  });
  let refreshing = false;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (refreshing) return;
    refreshing = true;
    window.location.reload();
  });
}
```

### `scripts/install.sh` (nginx site, блоки ~стр. 255/279)

Добавить в `server { }` (оба блока — HTTP и HTTPS):

```nginx
types { application/manifest+json webmanifest; }
location = /sw.js { add_header Cache-Control "no-cache, must-revalidate"; }
location = /manifest.json { add_header Cache-Control "no-cache"; }
```

Без этого SW может залипнуть на старой версии в кеше.

### `tests/http_smoke.sh`

Добавить проверки:

- `GET /manifest.json` → 200, `Content-Type` содержит `manifest` или `json`.
- `GET /sw.js` → 200.
- `GET /offline.html` → 200.
- страница `/login` содержит `<link rel="manifest"` и `<meta name="theme-color"`.
- `GET /assets/icons/icon-192.png` → 200 (после генерации иконок).

## Нюанс: MIME manifest в dev (`php -S`)

PHP built-in сервер **не знает** `.webmanifest` → отдаёт
`application/octet-stream` → браузер отклонит manifest.

**Решение**: использовать `manifest.json` — `application/json` известен и
`php -S`, и nginx. `rel="manifest" href="/manifest.json"` полностью валидно. В
nginx `types` добавляем на будущее.

## Что НЕ входит (осознанно)

- Кеш превью/видео/тайлов карты (cross-origin, токены, быстрое устаревание,
  раздувание кеша).
- Push-уведомления / Background Sync (нет полезного сценария).
- Полный оффлайн-доступ к камерам (архитектурно невозможно).
- IndexedDB / server-side PWA features.

## Порядок реализации

1. `scripts/generate-icons.sh` + сгенерировать PNG, закоммитить.
2. `public/manifest.json`.
3. `public/offline.html`.
4. `public/sw.js`.
5. `app/Portal.php` `layout()` — meta-теги.
6. `public/assets/app.js` — регистрация SW.
7. `scripts/install.sh` — nginx headers.
8. `tests/http_smoke.sh` — проверки.
9. Ручная проверка: Lighthouse PWA-аудит, install prompt в Chrome,
   standalone-режим на Android, offline-fallback.

## Зависимости

- `rsvg-convert` (пакет `librsvg2-bin`) или Inkscape — для генерации PNG-иконок.
- HTTPS на проде — уже есть (certbot). SW требует HTTPS или localhost; prod и
  dev (`127.0.0.1`/`localhost`) подходят. `0.0.0.0` в `start-portal.sh` — если
  заходить по IP из LAN, SW не зарегистрируется (нужен localhost или HTTPS).