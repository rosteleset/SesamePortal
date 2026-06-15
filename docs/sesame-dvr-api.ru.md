# Sesame DVR API

Документ описывает HTTP API, доступный в текущем runtime Sesame DVR. Список
сверен с `lib/sesame_dvr/web/router.ex` и
`lib/sesame_dvr/web/onvif_runtime_router.ex`.

## Общие правила

- Базовый URL примеров: `http://127.0.0.1:3000`.
- JSON endpoints принимают и возвращают `application/json`.
- Для всех `GET` маршрутов также работает `HEAD`, потому что runtime использует
  `Plug.Head`.
- Path-параметры `:camera`, `:name`, `:id`, `:volume_id`, `:camera_id` нужно
  URL-encode'ить.
- Management API защищается `SesameDvr.Web.ManagementAuth`. Сейчас это
  маршруты `/api/streams*`, `/api/system*`, `/api/sessions`, `/api/config*`,
  `/api/logs`, `/api/onvif*`, `/api/admin*`, `/api/agents*` и
  Flussonic-compatible API `/streamer/api/v3/...`. Исключения внутри
  `/api/agents*`: `/api/agents/enroll` и
  `/api/agents/:id/onvif/events/batch` используют agent/enrollment auth.
- Playback endpoints, включая player, playlists, archive metadata, previews,
  MP4 export и WebRTC, авторизуются настройками конкретного потока:
  `authMode=none`, `static` или `authBackend`.
- `/dvr/...` segment URLs внутри HLS playlists остаются прямыми immutable media
  URL. В текущей модели stream token защищает получение playlist, а не каждый
  segment URL отдельно.

## Management Auth

По умолчанию management endpoints доступны только с loopback-адресов:
`127.0.0.1`/`::1`, без внешних `X-Forwarded-For` или `X-Real-IP`.
Удалённый доступ разрешается только если включён remote management:

```bash
SESAME_DVR_MANAGEMENT_ALLOW_REMOTE=true
```

Если задан `SESAME_DVR_MANAGEMENT_TOKEN`, либо если в `config.json` есть
`managementTokenHash`, каждый management-запрос должен передавать токен одним
из способов:

```http
Authorization: Bearer <token>
Authorization: <token>
X-Management-Token: <token>
```

`managementTokenHash`, созданный ротацией токена в Settings или через
`POST /api/admin/management-token/rotate`, имеет приоритет над
`SESAME_DVR_MANAGEMENT_TOKEN`; plaintext token в `config.json` не хранится.
Новый token возвращается один раз. Если rotated token потерян, recovery
делается вне приложения: удалить `managementTokenHash` из config и
перезапустить сервис, чтобы снова использовать environment token.

Query-параметр `?token=...`, `management_token` или `managementToken`
поддерживается Web UI для `/admin` и `/dashboard`: браузер сохраняет token в
`sessionStorage`, убирает его из адресной строки и дальше отправляет API-запросы
через `Authorization: Bearer ...`. Для прямых management API-запросов
используйте headers выше, а не query string.

Web UI также может использовать admin session cookie, полученный через
`POST /api/admin/login`.

## Admin UI и статические ресурсы

| Метод | Путь | Назначение |
| --- | --- | --- |
| `GET` | `/admin` | Web UI администрирования. |
| `GET` | `/admin/:asset` | JS/CSS/assets админки. |
| `GET` | `/admin/status-icons/:variant/:asset` | Иконки статусов. |
| `GET` | `/dashboard` | Совместимый вход на dashboard. |
| `GET` | `/dashboard/:asset` | Assets dashboard. |
| `GET` | `/player/:asset` | Assets embed/player. |
| `GET` | `/i18n/:asset` | Файлы локализации UI/player. |
| `GET` | `/api/i18n` | Текущая локализация через JSON API. |

## Admin Session

| Метод | Путь | Body/Query | Ответ |
| --- | --- | --- | --- |
| `GET` | `/api/admin/session` | - | `{authenticated, passwordConfigured, passwordChangeRequired}`. |
| `POST` | `/api/admin/login` | `{"login":"admin","password":"..."}` или `{"password":"..."}` | Ставит session cookie и возвращает состояние сессии. |
| `POST` | `/api/admin/logout` | - | Удаляет session cookie. |
| `POST` | `/api/admin/management-token/rotate` | `{"currentManagementToken":"..."}` если token уже задан | Генерирует новый management token и возвращает его один раз. |

## Playback И Media API

Playback endpoints отдают CORS headers, чтобы embed/player мог работать с
другого origin. Параметр `:camera` в таблице означает имя в общем playback
namespace: обычный canonical stream или, для live HLS routes, multi-bitrate
stream.

| Метод | Путь | Query/Body | Назначение |
| --- | --- | --- | --- |
| `GET` | `/:camera/embed.html` | `dvr=true|false`, `token`, `archive_playlist=vod|sliding` | Встроенный player. |
| `GET` | `/:camera/playback_info.json` | `token` | Доступность HLS/WebRTC, archive flag и codec summary. |
| `GET` | `/:camera/live.m3u8` | `token` | Live HLS playlist. |
| `GET` | `/:camera/index.m3u8` | `token` | Alias live HLS. |
| `GET` | `/:camera/video.m3u8` | `token` | Alias live HLS. |
| `GET` | `/:camera/live.fmp4.m3u8` | `token` | Alias live fMP4 HLS. |
| `GET` | `/:camera/index.fmp4.m3u8` | `token` | Alias live fMP4 HLS. |
| `GET` | `/:camera/video.fmp4.m3u8` | `token` | Alias live fMP4 HLS. |
| `GET` | `/:camera/dvr.m3u8` | `start`, `end`, `token`, `sliding`, `window`, `rate`, `clientStartedMs`, `maxEnd` | Archive HLS по ISO/Unix времени. |
| `GET` | `/:camera/motion_dvr.m3u8` | `start`, `end`, `token`, `sliding`, `window`, `rate`, `clientStartedMs`, `maxEnd` | Archive HLS только по ONVIF motion intervals. |
| `GET` | `/:camera/motion_dvr_map.json` | `start`, `end`, `token`, `sliding`, `window`, `rate`, `clientStartedMs`, `maxEnd` | Mapping media time -> archive time для motion-only playlist. |
| `GET` | `/:camera/timelapse.m3u8` | `token`, опционально `start`, `end` | Timelapse archive HLS из сохранённых кадров, если `timelapseEnabled=true`. Без `start/end` отдаёт весь сохранённый timelapse. |
| `GET` | `/:camera/motion-segment/*relative_parts` | internal | Внутренний media proxy для motion-only HLS с переписанным media time. |
| `GET` | `/:camera/archive-segment/*relative_parts` | internal | Внутренний media proxy archive segment для player/workflows. |
| `GET` | `/:camera/index-:from-:duration.m3u8` | `token` | Archive HLS по Unix seconds. |
| `GET` | `/:camera/index-:from-:duration.fmp4.m3u8` | `token` | Archive fMP4 HLS по Unix seconds. |
| `GET` | `/:camera/archive-:from-:duration.mp4` | `token` | MP4 export archive interval. |
| `GET` | `/:camera/timelapse-:from-:duration.mp4` | `token` | MP4 export timelapse interval. |
| `POST` | `/:camera/playback_warning.json` | JSON warning body | Приём warning/diagnostic событий от embed/player. |
| `GET` | `/:camera/recording_status.json` | `token` | Flussonic-compatible archive ranges. `from` и `duration` всегда integer seconds. |
| `GET` | `/:camera/timeline_ranges.json` | `from`, `to`, `bucket`, `token` | Archive ranges для UI timeline с optional bucket aggregation. |
| `GET` | `/:camera/motion_events.json` | `from`, `to`, `bucket`/`bucketSeconds`, `token` | Агрегированные ONVIF motion intervals по потоку. |
| `GET` | `/:camera/timelapse_segments.json` | `from`, `to`, `token` | Список/metadata timelapse segments для диагностики и UI. |
| `GET` | `/:camera/preview.mp4` | `token` | Самый свежий MP4 preview или ближайший архивный сегмент при `previewMp4CacheEnabled=false`. |
| `GET` | `/:camera/preview.jpg` | `token` | Самый свежий JPEG preview. |
| `GET` | `/:camera/:timestamp-preview.jpg` | `token` | JPEG preview, ближайший к Unix timestamp. |
| `GET` | `/:camera/:yyyy/:mm/:dd/:HH/:MM/:SS-preview.mp4` | `token` | Date-based MP4 preview или ближайший архивный сегмент при `previewMp4CacheEnabled=false`. |
| `GET` | `/:camera/:yyyy/:mm/:dd/:HH/:MM/:SS-preview.jpg` | `token` | Date-based JPEG preview. |
| `GET` | `/:camera/static-live/:resource` | `token` | Static-image live segment alias. |
| `GET` | `/dvr/:camera/static-live/:resource` | `token` | Static-image live segment alias under `/dvr`. |
| `GET` | `/:stream/hls-segment/:variant/*relative_parts` | `token` | Multi-bitrate child segment proxy. |
| `GET` | `/:camera/:resource` | `token` | Generic route для live aliases, preview aliases и других single-resource playback paths. |
| `GET` | `/:camera/:year/:month/:day/:hour/:minute/:resource` | `token` | Generic route для date-based preview resources. |
| `POST` | `/:camera/whep/` | SDP offer body | Native WebRTC/WHEP. Возвращает `201`, SDP answer и `Location`. |
| `PATCH` | `/:camera/whep/:session_id` | trickle ICE/SDP patch body | Обновляет WHEP session. |
| `DELETE` | `/:camera/whep/:session_id` | - | Закрывает WHEP session. |
| `GET` | `/dvr/*path_parts` | optional `token` | Прямой доступ к archive segment файлам default volume. |
| `GET` | `/dvr/v/:volume_id/:camera/*relative_parts` | optional `token` | Прямой доступ к archive файлам MultiVolume. |
| `GET` | `/dvr/t/:volume_id/:camera/*relative_parts` | optional `token` | Прямой доступ к timelapse archive файлам MultiVolume. |

### Multi-bitrate live HLS

Multi-bitrate stream занимает то же пространство имён, что и обычные потоки.
Если имя в `/<name>/index.m3u8` совпадает с включённым multi-bitrate stream,
сервер отдаёт HLS master playlist с вариантами из его config. То же поведение
используют live aliases `live.m3u8`, `video.m3u8` и fMP4 aliases.

Child media playlist запрашивается по URL, который сервер сам кладёт в master:

```text
GET /<name>/index.m3u8?media=1&variant=<variant>
```

`variant` можно также передать как `v`. Эти URL предназначены для HLS-клиента;
обычно их не нужно строить вручную. Каждый variant ссылается на уже
записываемый canonical stream, но playback auth проверяется по config самого
multi-bitrate stream. Auth settings canonical streams, входящих в состав
variant list, на master и child playlist такого stream не влияют.

Сегменты в child media playlist остаются обычными `/dvr/...` URL и обслуживаются
по той же модели, что и сегменты canonical streams: playlist защищён token'ом
потока, а сами segment URL не получают отдельный signed token.

Ответы для некорректного multi-bitrate playback:

- `400` - media playlist запрошен без `variant`/`v`;
- `404` - stream выключен или variant не найден;
- `503` - master playlist пока нельзя собрать, например ещё нет metadata для
  variant stream и не заданы fallback attributes.

Атрибуты `#EXT-X-STREAM-INF` берутся из cached ffprobe/libavformat metadata
canonical stream. В config variant можно задать fallback/override fields:
`bandwidth`, `averageBandwidth`, `resolution`, `frameRate`, `codecs`. Если
metadata ещё нет, `bandwidth` обязателен, чтобы variant попал в master playlist.

### Archive HLS playlist modes

Обычный `GET /:camera/dvr.m3u8?start=<ISO>&end=<ISO>` возвращает конечный
VOD playlist с `#EXT-X-ENDLIST`. Это поведение остаётся default для обратной
совместимости с внешними интеграциями.

Для embed-плеера можно включить отдельный растущий режим:

```text
GET /<camera>/embed.html?dvr=true&archive_playlist=sliding
```

В этом режиме player запрашивает media playlist с `sliding=1`. Сервер отдаёт
HLS `EVENT` playlist без `#EXT-X-ENDLIST`, ставит `Cache-Control: no-cache` и
расширяет правую границу плейлиста при повторных запросах. Это позволяет hls.js
продолжать архивное воспроизведение без видимого перезапуска на границе
исходного VOD-окна.

Параметры server-side `sliding=1`:

- `start` - начало archive playback;
- `end` - начальная правая граница, нужна для совместимости master/media URL;
- `window` - базовое окно playlist в секундах, ограничено сервером;
- `rate` - текущая скорость воспроизведения, чтобы сервер расширял окно быстрее
  при `8x`/`16x`;
- `clientStartedMs` - клиентское время старта playlist в milliseconds;
- `maxEnd` - максимальная правая граница доступного archive range.

Native HLS browsers не используют `archive_playlist=sliding`; для них embed
остаётся на конечном VOD playlist, чтобы не получить прыжок к live edge.

### Motion-only archive HLS

Режим просмотра архива только по событиям движения использует отдельные
endpoints:

```text
GET /<camera>/motion_dvr.m3u8?start=<ISO>&end=<ISO>
GET /<camera>/motion_dvr_map.json?start=<ISO>&end=<ISO>
```

`motion_dvr.m3u8` строит HLS playlist только из сегментов, которые попадают в
ONVIF motion=true интервалы с 5-секундным padding по краям. Между непоследовательными
архивными участками вставляется `#EXT-X-DISCONTINUITY`, но media timeline для
hls.js остаётся непрерывной: сегменты отдаются через внутренний
`/<camera>/motion-segment/...` proxy, который переписывает fMP4 `tfdt`/`mfhd`
timestamps под последовательное воспроизведение.

`motion_dvr_map.json` отдаёт sidecar-карту соответствия непрерывного media time
и реального времени архива. Embed-плеер использует её, чтобы зелёный индикатор
и seek по timeline оставались в настоящем archive time, хотя HLS-плейлист
физически пропускает промежутки без движения.

Параметры:

- `start`, `end` - видимое окно архива;
- `sliding`/`archive_sliding` - включить растущий HLS `EVENT` playlist;
- `window`, `rate`, `clientStartedMs`, `maxEnd` - те же параметры расширения
  правой границы, что и у обычного `dvr.m3u8?sliding=1`.

`motion-segment` является внутренним media endpoint для embed-плеера и не
предназначен как стабильный public integration API. Внешним интеграциям,
которым нужна только разметка движения, обычно достаточно
`motion_events.json`.

### Timelapse Archive HLS

Если для потока включено `timelapseEnabled`, сервер сохраняет raw timelapse
frames как staging, фоново собирает из них HLS/fMP4 chunks и отдаёт готовый
materialized manifest как обычный HLS VOD:

```text
GET /<camera>/timelapse.m3u8
GET /<camera>/timelapse.m3u8?start=<ISO>&end=<ISO>
```

Без `start` и `end` endpoint отдаёт весь сохранённый timelapse для потока.
Если передать `start` и `end`, они задают реальное окно архива. Внутри playlist
попадают только уже собранные timelapse chunks. Embed-плеер в режиме
`Timelapse` показывает длительность ускоренного media-файла отдельно, а
timeline/seek остаются привязаны к реальному archive time.

Timelapse chunks лежат отдельно от основного архива под
`timelapse/<camera>/...` на соответствующем storage volume и чистятся по
`timelapseRetentionDays`. Сохранение кадров идёт из already committed archive
segments; фактическая частота не может быть выше частоты доступных archive
segments/keyframes.

## System API

| Метод | Путь | Query/Body | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/system/status` | `debug`, `samples`, `includeSamples`, `stateSize`, `cacheTtlMs` | Снимок CPU/RAM/disk/network/streams/runtime. |
| `GET` | `/api/system/features` | - | Runtime feature flags/capabilities для UI. |
| `GET` | `/api/system/history` | `range`, `max_points`/`maxPoints` | История метрик dashboard. |
| `GET` | `/api/system/stream-bitrate-history` | `range`, `name`/`stream`, `max_points`/`maxPoints` | История bitrate по потокам для dashboard. |
| `GET` | `/api/system/version` | - | Build id, commit, target, NIF metadata. |
| `GET` | `/api/system/license` | - | License, lease, anchor и native guard status. |
| `POST` | `/api/system/license/renew` | - | Принудительное продление online lease/license. |
| `GET` | `/api/system/update/status` | - | Состояние update launcher. |
| `POST` | `/api/system/update/check` | - | Alias status refresh для update launcher. |
| `POST` | `/api/system/update/start` | optional JSON | Запуск update job. Если body содержит `mode=hot_patch`, `beam_hot_patch` или `hotpatch`, запускается BEAM hot patch flow. |
| `GET` | `/api/system/update/hot-patch/status` | - | Последний status BEAM hot patch runtime. |
| `POST` | `/api/system/update/hot-patch/start` | optional JSON | Запуск BEAM hot patch. Если source/manifest не переданы, используется `source=available`. |
| `POST` | `/api/system/update/rollback` | - | Запуск rollback job, если доступен. |
| `GET` | `/api/system/runtime-tuning` | - | Текущие runtime tuning settings, sources и доступные поля. |
| `PATCH` | `/api/system/runtime-tuning` | JSON object | Меняет поддерживаемые runtime tuning fields без рестарта. |
| `POST` | `/api/system/runtime-tuning/reset` | optional fields/body | Сбрасывает runtime overrides к env/default. |
| `POST` | `/api/system/runtime-tuning/persist-startup` | - | Сохраняет отличающиеся runtime settings в start-up env/config. |
| `GET` | `/api/system/orphan-archives` | - | Статус расчёта осиротевших archive/preview данных. |
| `POST` | `/api/system/orphan-archives/scan` | - | Запускает/обновляет расчёт осиротевших данных. |
| `DELETE` | `/api/system/orphan-archives` | - | Удаляет найденные осиротевшие archive/preview данные. |
| `GET` | `/api/system/beam_profile` | `duration_ms`, `limit`, `light` | BEAM reductions/profile snapshot. |
| `GET` | `/api/system/ingest_profile` | `limit` | Per-stream/per-stage ingest profile. |
| `GET` | `/api/system/reconciliation_profile` | `duration_ms`, `limit` | Profiling reconciliation work. |
| `GET` | `/api/system/segment_index_profile` | `iterations`, `range_seconds`, `camera_limit`, `no_playlists` | SegmentIndex diagnostics. |

## Prometheus / OpenMetrics API

| Метод | Путь | Auth | Назначение |
| --- | --- | --- | --- |
| `GET` | `/metrics` | `metricsAuthMode` | OpenMetrics snapshot для Prometheus. |

`/metrics` не использует management auth и не зависит от playback token
отдельных потоков. Endpoint включается и защищается отдельными глобальными настройками:

- `metricsAuthMode`: `disabled`, `none`, `bearer` или `basic`; default `none`;
- `metricsBearerToken`: write-only token для `Authorization: Bearer ...`;
- `metricsBasicUsername`: обязательный username для Basic auth;
- `metricsBasicPassword`: write-only password для Basic auth.

При `metricsAuthMode=disabled` endpoint остаётся в router, но отвечает `404`
и не отдаёт OpenMetrics body.

В `config.json` raw secrets не сохраняются: сервер пишет
`metricsBearerTokenHash` и `metricsBasicPasswordHash`. `GET /api/config`
возвращает только признаки `metricsBearerTokenConfigured` и
`metricsBasicPasswordConfigured`.

Примеры:

```bash
curl http://127.0.0.1:3000/metrics
curl -H "Authorization: Bearer $METRICS_TOKEN" https://dvr.example.com/metrics
curl -u prometheus:secret https://dvr.example.com/metrics
```

Ответ имеет content type
`application/openmetrics-text; version=1.0.0; charset=utf-8`, содержит строки
`# TYPE`/`# HELP` и завершается `# EOF`. Основные Flussonic-compatible метрики
по каждому потоку:

| Метрика | Тип | Labels | Значение |
| --- | --- | --- | --- |
| `ts_delay` | gauge | `server_id`, `name` | Задержка последнего индексированного archive segment, секунды. |
| `stream_bitrate` | gauge | `server_id`, `name` | Bitrate из runtime/ffprobe metadata, bit/s. |
| `stream_bytes_in` | counter | `server_id`, `name` | Накопленные входящие bytes текущего ingest-процесса. |
| `stream_input_retries` | counter | `server_id`, `name` | Количество restart attempts ingest с момента старта сервиса. |
| `stream_online_clients` | gauge | `server_id`, `name` | Текущие HLS + WebRTC playback clients, видимые Sesame DVR. |
| `play_bytes` | counter | `server_id`, `name` | Reserved compatibility metric; сейчас Sesame DVR не считает playback bytes и отдаёт `0`. |

Дополнительно отдаются Sesame DVR-specific метрики:
`sesame_dvr_stream_enabled`, `sesame_dvr_stream_running`,
`sesame_dvr_stream_archive_writing`,
`sesame_dvr_stream_input_packets_total`,
`sesame_dvr_stream_video_packets_total`,
`sesame_dvr_stream_audio_packets_total` и
`sesame_dvr_stream_skipped_packets_total`.

Практическая настройка Prometheus, curl-проверки, PromQL и alert examples
описаны отдельно: [prometheus-metrics-endpoint.ru.md](./prometheus-metrics-endpoint.ru.md).

## Config API

| Метод | Путь | Body | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/config` | - | Глобальная часть `config.json`. |
| `GET` | `/api/config/multi-bitrate-streams` | - | Список multi-bitrate streams из config. |
| `PATCH` | `/api/config` | JSON object | Обновляет глобальные настройки. |

Глобальные настройки включают `dvrRoot`, `storage`, preview options,
`authBackendUrl`, `metricsAuthMode`, `metricsBearerToken`,
`metricsBasicUsername`, `metricsBasicPassword`, `interfaceLanguage`, ONVIF
defaults, cleanup intervals, license/update settings и другие поля, которые
поддерживает `Config.Store`.

### Multi-bitrate Stream Config

`GET /api/config` намеренно не возвращает `multiBitrateStreams`. Для чтения
текущего списка используйте отдельный endpoint:

```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:3000/api/config/multi-bitrate-streams
```

Ответ:

```json
{
  "multiBitrateStreams": [
    {
      "name": "uae-1-auto",
      "enabled": true,
      "authMode": "none",
      "authToken": "",
      "variants": [
        {
          "name": "sub",
          "streamName": "uae-1-sub",
          "bandwidth": 650000,
          "averageBandwidth": 520000,
          "resolution": "1280x720",
          "frameRate": 15,
          "codecs": "avc1.64001F"
        },
        {
          "name": "main",
          "streamName": "uae-1",
          "bandwidth": 2200000,
          "resolution": "1920x1080",
          "codecs": "avc1.640028"
        }
      ]
    }
  ]
}
```

Создание, обновление и удаление выполняются через существующий
`PATCH /api/config`. Поле `multiBitrateStreams` заменяет весь список, поэтому
перед изменением нужно прочитать текущую версию через
`GET /api/config/multi-bitrate-streams` и отправить полный новый массив.
Удаление stream - это сохранение массива без него.

```bash
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "multiBitrateStreams": [
      {
        "name": "uae-1-auto",
        "enabled": true,
        "authMode": "none",
        "variants": [
          {"name": "sub", "streamName": "uae-1-sub", "bandwidth": 650000},
          {"name": "main", "streamName": "uae-1", "bandwidth": 2200000}
        ]
      }
    ]
  }' \
  http://127.0.0.1:3000/api/config
```

Поля multi-bitrate stream:

- `name` - имя в общем playback namespace. Не может совпадать с canonical
  stream или другим multi-bitrate stream, не может содержать `/`, не может быть
  `.` или `..`.
- `enabled` - включает playback multi-bitrate stream, default `true`.
- `authMode` - `none`, `static` или `authBackend`.
- `authToken` - token для `authMode=static`.
- `variants` - непустой список вариантов.

Поля variant:

- `name` - имя variant в master playlist. Если не задано, используется
  `streamName`. Alias `label` также принимается во входном JSON. Должно быть
  уникальным внутри stream, не может содержать `/`, `?`, `#`, не может быть `.`
  или `..`.
- `streamName` - имя существующего canonical stream, из которого берётся media
  playlist. Alias `stream` также принимается во входном JSON.
- `bandwidth` - fallback/override для `BANDWIDTH`, positive integer.
- `averageBandwidth` - fallback/override для `AVERAGE-BANDWIDTH`.
- `resolution` - fallback/override `RESOLUTION` в формате `WIDTHxHEIGHT`.
- `frameRate` - fallback/override `FRAME-RATE`.
- `codecs` - fallback/override `CODECS`.

## Storage API

Storage endpoints управляют рабочим состоянием хранилищ и catalog jobs. Для
production-публикации держите их за тем же management access layer или внешней
reverse-proxy защитой, что и `/api/system/*` и `/api/streams*`.

| Метод | Путь | Body | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/storage/volumes` | - | Snapshot storage volumes и catalog status. |
| `GET` | `/api/storage/catalog-jobs` | - | Список текущих/последних catalog/audit/rebuild jobs. |
| `GET` | `/api/storage/catalog-jobs/:job_id` | - | Состояние catalog/audit/rebuild job. |
| `POST` | `/api/storage/catalog-jobs/:job_id/cancel` | - | Отменяет running catalog/audit/rebuild job. |
| `POST` | `/api/storage/catalog-jobs/:job_id/concurrency` | `{"concurrency": 4}` | Меняет concurrency running catalog job. |
| `POST` | `/api/storage/volumes/:volume_id/audit` | - | Запускает read-only catalog audit. |
| `POST` | `/api/storage/volumes/:volume_id/rebuild-catalog` | - | Пересобирает global/hour catalog volume. |
| `POST` | `/api/storage/volumes/:volume_id/enable` | - | Включает volume. |
| `POST` | `/api/storage/volumes/:volume_id/disable` | - | Отключает volume и writable. |
| `POST` | `/api/storage/volumes/:volume_id/writable-on` | - | Разрешает новые записи на volume. |
| `POST` | `/api/storage/volumes/:volume_id/writable-off` | - | Запрещает новые записи на volume. |
| `POST` | `/api/storage/volumes/:volume_id/drain` | - | Переводит volume в draining: writable off, active writes завершаются. |

## Streams API

Stream body использует поля камеры из `config.json`:

```json
{
  "name": "cam1",
  "source": "rtsp://user:pass@10.0.0.10/stream",
  "sourceType": "direct",
  "enabled": true,
  "archiveEnabled": true,
  "retentionDays": "6h",
  "timelapseEnabled": true,
  "timelapseFramesPerHour": 60,
  "timelapseRetentionDays": "3d",
  "timelapsePlaybackFps": 25,
  "failover": {
    "mode": "none"
  },
  "disableAudio": false,
  "ingestProducer": "ffmpeg_nif",
  "authMode": "none",
  "authToken": ""
}
```

`retentionDays` можно передавать числом дней или строкой с единицами `d`, `h`,
`m`, например `"3d"`, `"6h"`, `"180m"`. Строковое значение сохраняется и
возвращается API без нормализации; числовое значение считается днями для
обратной совместимости.

`timelapseRetentionDays` использует тот же формат `d`/`h`/`m` и хранится как
строка, если клиент передал строку. `timelapseFramesPerHour` и
`timelapsePlaybackFps` должны быть положительными целыми числами.

`failover.mode` может быть `none`, `master` или `backup`. Для `master` и
`backup` обязательно поле `failover.peerUrl` в canonical формате:

```text
https://peer.example/api/failover/streams/<peer-stream>?token=<playback-token>
```

`token` в `peerUrl` - это обычный playback token связанного потока. Он
используется для health/read-side failover запросов и для чтения архива через
обычные playback endpoints того же потока. Отдельный service/scoped token для
archive-read в текущем MVP не требуется.

Если у уже существующего failover-потока меняется failover identity
(`mode`/peer DVR/peer stream из `peerUrl`), `PUT/PATCH /api/streams/:name`
вернёт `409 failover_identity_change_requires_confirmation`. Для сохранения
нужно повторить запрос с `confirmFailoverIdentityChange=true`. Если вместе с
подтверждением передать `purgeOldFailoverArchive=true`, DVR удалит локальный
archive layout этого потока, связанный со старой failover-связью. Без purge
старые segment files остаются на диске.

`master`-поток продолжает писать архив штатно и может включить repair:

```json
"failover": {
  "mode": "master",
  "peerUrl": "https://backup.example/api/failover/streams/cam1-backup?token=...",
  "repair": {
    "enabled": true,
    "pollIntervalSec": 300,
    "lookbackHours": 24,
    "maxBandwidthBytesPerSec": 10485760,
    "maxConcurrentDownloads": 2,
    "deleteOnBackup": true,
    "deleteGraceSec": 3600
  }
}
```

`backup`-поток проверяет health master-потока и стартует runtime только после
устойчивого отказа. Если `hotBuffer.enabled=false`, backup-поток должен быть
сохранён с `enabled=false`, иначе loader отклонит конфиг:

```json
"enabled": false,
"failover": {
  "mode": "backup",
  "peerUrl": "https://master.example/api/failover/streams/cam1?token=...",
  "pollIntervalSec": 10,
  "failureThreshold": 3,
  "recoveryThreshold": 12,
  "recoveryMinStableSec": 120,
  "masterFreshSegmentMaxAgeSec": 20,
  "failoverStartCooldownSec": 60,
  "failoverStopDelaySec": 180,
  "backupRetentionMaxBytes": 107374182400,
  "hotBuffer": {
    "enabled": false,
    "durationSec": 300,
    "maxBytes": 104857600
  }
}
```

`backupRetentionMaxBytes` задаёт byte-quota для backup-архива. Cleanup-проход
для `failover.mode=backup` сначала удаляет acknowledged ranges после
`repair-ack` и grace, затем expired сегменты по обычному `retentionDays`, затем
самые старые backup-сегменты, чтобы уложиться в quota. Если включён hot-buffer,
Backup держит runtime постоянно включённым и пишет короткий standby-буфер даже
при здоровом Master. Старые standby-сегменты hot-buffer чистятся отдельно по
`hotBuffer.durationSec` и, если задано, по `hotBuffer.maxBytes`; cleanup worker
проверяет backup-потоки примерно раз в 30 секунд, поэтому лимит является
мягким и может отличаться на длительность poll interval плюс размер сегмента.
Acknowledged сегменты удаляются после grace даже если они попадают в текущий
hot-buffer tail: подтверждение Master имеет приоритет над standby-буфером. При переходе в
failover cleanup считает последние `hotBuffer.durationSec` секунд перед
моментом старта failover частью repair window, чтобы Master мог закрыть gap
между реальным отказом и моментом обнаружения. Hot-buffer открывает второе
постоянное подключение к камере, поэтому для single-client RTSP камер его надо
оставлять выключенным.

`sourceType` принимает:

- `direct`: RTSP/HLS/file URL или другой URL, который поддерживает native ingest.
- `udp_multicast`: UDP multicast source, например
  `udp://@239.10.10.10:5000?localaddr=192.168.0.1&overrun_nonfatal=1&fifo_size=5000000`.
- `push`: stream получает media от внешнего publisher через push-ingest frontend.
- `image`: статический JPEG source. Сначала загрузите JPEG через
  `PUT /api/streams/:name/static-image`, затем сохраните возвращённый `source`
  в stream config.

`archiveEnabled=false` отключает долговременный архив для потока. Ingest всё
равно пишет минимальный live-buffer, чтобы работали live HLS/WebRTC, но старые
сегменты этого потока сразу вычищаются из storage/catalog. Для `sourceType=image`
архив по умолчанию отключён; live-buffer хранит последние `liveWindow`
самостоятельные fMP4-сегменты, чтобы HLS-плееры не ждали накопления live
playlist при старте. Для таких потоков `recording_status.json` и
`timeline_ranges.json` возвращают пустые archive ranges, а `playback_info.json`
возвращает `archive.enabled=false`.

| Метод | Путь | Body/Query | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/streams` | - | Список потоков с config/runtime/archive status. |
| `GET` | `/api/streams/:name` | - | Один поток. |
| `POST` | `/api/streams` | Stream body | Создаёт поток. |
| `PUT` | `/api/streams/:name` | Stream body/patch | Обновляет поток. |
| `PATCH` | `/api/streams/:name` | Stream patch | Частично обновляет поток. |
| `DELETE` | `/api/streams/:name` | `purge`/`purgeArchive` | Удаляет поток; optional purge archive. |
| `GET` | `/api/streams/:name/logs` | `limit` | Bounded runtime logs конкретного потока. |
| `GET` | `/api/streams/:name/ffprobe` | - | Cached ffprobe/libavformat metadata. |
| `POST` | `/api/streams/:name/ffprobe` | - | Принудительный ffprobe diagnostics run. |
| `POST` | `/api/streams/:name/rebuild-catalog` | - | Пересобирает catalog/hour indexes только для потока. |
| `POST` | `/api/streams/:name/rebuild-timelapse-manifest` | - | Пересобирает materialized timelapse manifest для потока. |
| `PUT` | `/api/streams/:name/static-image` | Raw JPEG body, max 15 MB | Загружает JPEG source в `dvrRoot/static-sources`. |
| `POST` | `/api/streams/:name/start` | - | Runtime-only start; config `enabled` не меняется. |
| `POST` | `/api/streams/:name/stop` | - | Runtime-only stop; config `enabled` не меняется. |
| `POST` | `/api/streams/:name/restart` | - | Runtime restart ingest/supervisor. |
| `POST` | `/api/streams/:name/push-token/rotate` | - | Ротация publish token для `sourceType=push`. |
| `POST` | `/api/streams/:name/enable` | - | Persisted enable и runtime sync. |
| `POST` | `/api/streams/:name/disable` | - | Persisted disable и runtime stop. |
| `POST` | `/api/streams/bulk` | `{"action":"start","names":["cam1"]}` | Асинхронный bulk job. |
| `GET` | `/api/streams/bulk/:id` | - | Состояние bulk job. |

Bulk `action`: `start`, `stop`, `enable`, `disable`, `delete`.
Для `delete` можно передать `purge`, `purgeArchive` или `purge_archive`.
Runtime bulk parallelism управляется `SESAME_DVR_STREAM_BULK_CONCURRENCY`
и по умолчанию равен `20`.

## Failover API

Failover endpoints используются DVR-нодами между собой и не являются
пользовательским playback API. `health` и `segments` принимают обычный playback
token выбранного потока из `peerUrl`; это тот же token, который работает для
обычных playback/archive URL этого потока. Также для служебных локальных
вызовов поддерживается management token как
`Authorization: Bearer <token>`, `X-Management-Token` или query `token`.
Если management token не настроен, loopback-запросы разрешены как у локального
management API. Control/write endpoints не используют playback token из
`peerUrl` и требуют management/failover API token.

В protected runtime вся failover-функциональность требует license feature
`dvr_failover`. Без неё сервер возвращает `403
{"error":"license_feature_disabled","feature":"dvr_failover"}` для
`/api/failover/...` и не принимает конфигурации потоков с
`failover.mode=master|backup`.

| Метод | Путь | Body/Query | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/failover/streams/:stream/health` | `token` | Состояние конкретного потока для backup-trigger. |
| `GET` | `/api/failover/streams/:stream/segments` | `from`, `to`, `token` | Машиночитаемый manifest archive segments для repair-read; `from`/`to` принимают Unix seconds или ISO timestamp. |
| `POST` | `/api/failover/streams/:stream/repair-ack` | `{"ranges":[{"from": unix, "to": unix}], "deleteGraceSec": 3600}` | Master подтверждает, что диапазоны с Backup импортированы и могут считаться acknowledged. |
| `POST` | `/api/failover/streams/:stream/backup/start` | - | Ручной запуск записи Backup-потока. Требует management/failover auth. |
| `POST` | `/api/failover/streams/:stream/backup/stop` | - | Ручная остановка записи Backup-потока. Требует management/failover auth. |
| `POST` | `/api/failover/streams/:stream/repair/run` | - | Ручной запуск repair-прохода для Master-потока. Требует management/failover auth. |
| `POST` | `/api/failover/streams/:stream/cleanup/run` | - | Ручной запуск cleanup-прохода для Backup-потока. Требует management/failover auth. |
| `GET` | `/api/failover/nodes/local/resources` | - | Resource snapshot текущей DVR-ноды для внешнего/ручного placement planning. Требует management/failover auth. |
| `POST` | `/api/failover/placement/plan` | `nodes`, `streams` | Dry-run resource-aware placement planner. Требует management/failover auth. |
| `POST` | `/api/failover/placement/apply` | `targetNodeId`, `patches`, optional `dryRun` | Применяет сгенерированные stream patches только для текущей DVR-ноды. Требует management/failover auth. |

Health response:

```json
{
  "streamName": "cam1",
  "nodeId": "dvr-node-1",
  "role": "master",
  "recordingState": "recording",
  "liveState": "online",
  "archiveWritable": true,
  "storageWritable": true,
  "lastSegmentUnixMs": 1779962400123,
  "lastSegmentAgeSec": 4,
  "updatedAt": 1779962404,
  "bootId": "..."
}
```

Backup считает Master здоровым только если endpoint вернул `2xx`,
`recordingState=recording`, `liveState=online`, writable storage/archive и
`lastSegmentAgeSec <= failover.masterFreshSegmentMaxAgeSec`.
После `failureThreshold` подряд отрицательных проверок backup runtime стартует,
после `recoveryThreshold` подряд успешных проверок и выдержки
`max(recoveryMinStableSec, failoverStopDelaySec)` останавливается. Если
`hotBuffer.enabled=true`, runtime после восстановления Master не останавливается,
а возвращается в standby hot-buffer. Все state transitions пишутся в общий
event log и локальный failover event store в
`SESAME_DVR_STATE_DIR/failover/event-store.json`.

Placement planner не заменяет Portal cluster planner. Это DVR-side API для
ручной или внешней автоматизации: caller передаёт список DVR-нод и защищаемых
потоков, а сервер считает bitrate, требуемый backup storage, capacity/weight
score, single-node-failure warnings и возвращает dry-run plan. Нода описывается
полями `nodeId`, `baseUrl`, `physicalHost`, `weight`, `cpuLoad`, `cpuCores`,
`memoryAvailableBytes`, `storageWritableBytes`, `archiveWriteBytesPerSec`,
`activeIngestCount`, `standbyBackupCount`, `activeFailoverCount`,
`estimatedBackupBytesReserved`, optional `maxIngestCount` и
`maxArchiveWriteBytesPerSec`. Поток описывается полями `streamName`,
`masterNodeId`, optional `backupStreamName`, `bitrateBytesPerSec` или
`bitrateBitsPerSec`, `maxCoveredDowntimeSec`, `safetyFactor`, `source`,
`masterPlaybackToken`, `backupPlaybackToken`.

Минимальный пример dry-run:

```bash
curl -H "Content-Type: application/json" \
  -H "X-Management-Token: $TOKEN" \
  -d '{
    "nodes": [
      {"nodeId":"dvr-a","baseUrl":"https://dvr-a.example","physicalHost":"host-a","storageWritableBytes":500000000000,"cpuCores":8},
      {"nodeId":"dvr-b","baseUrl":"https://dvr-b.example","physicalHost":"host-b","storageWritableBytes":500000000000,"cpuCores":8}
    ],
    "streams": [
      {"streamName":"cam1","masterNodeId":"dvr-a","bitrateBitsPerSec":4000000,"maxCoveredDowntimeSec":3600}
    ],
    "safetyFactor": 1.3
  }' \
  https://dvr-a.example/api/failover/placement/plan
```

`plan.assignments` содержит выбранный `backupNodeId`,
`requiredBackupBytes`, estimated bitrate и warning codes. `failureScenarios`
показывает, перегрузит ли отказ одной Master-ноды оставшиеся Backup-ноды.
`patches` можно передать в `/api/failover/placement/apply`; endpoint применяет
только patches с `nodeId`, совпадающим с текущей DVR-нодой, остальные помечает
как `skipped`. Для проверки без изменения конфига передайте `dryRun: true`.

`repair-ack` сохраняет acknowledged ranges в failover event store. Backup cleanup
читает эти ranges, ждёт `deleteGraceSec` из payload или default grace, удаляет
соответствующие segment files и обновляет hour/camera/catalog indexes через
штатный retention path. Если cleanup удаляет сегменты из-за quota, а не только
из-за ack/retention, событие пишется warning-уровнем и содержит `deleteReasons`.
Каждый cleanup-проход также сохраняет отдельный `cleanupStatus` в failover
event store и возвращается в `cleanupState.summary` stream status. В summary
есть `backupArchiveUsage`: суммарные bytes/segments backup-архива, covered
range, `estimatedCoveredDowntimeSec`, acknowledged/unacknowledged usage и
разбивка usage по failover-интервалам из event log.

Repair-проход по умолчанию использует обычные playback endpoints Backup:
`recording_status.json` для карты ranges, затем `dvr.m3u8?start=...&end=...`
для segment URLs missing intervals, затем `/dvr/...` или `/dvr/v/...` для
скачивания media bytes. `/api/failover/streams/:stream/segments` остаётся
доступным machine-readable manifest endpoint для случаев, где нужны
дополнительные metadata/checksum/volume fields, но не является отдельным media
path. Repair делает bounded retry для чтения ranges/playlist/media bytes с
Backup. Media download пишет во временный файл; если попытка оборвалась после
частичного тела, следующая попытка продолжает скачивание с размера этого temp
file через HTTP `Range: bytes=<offset>-`. Если сервер игнорирует Range и
возвращает `200`, temp file перезаписывается полным телом. После финальной
ошибки временный файл удаляется.

Если Backup содержит сегменты, пересекающиеся с локальными Master-сегментами
за тот же период, repair не импортирует эти Backup-сегменты. В summary такого
прохода возвращаются `overlapSegments`, `splitBrainSuspected=true`, а в
`results` соответствующие элементы имеют `status=skipped` и
`reason=overlaps_local_archive`. Это защищает playback от дублей и
немонотонных DTS/PTS при split-brain; локальный Master segment имеет
приоритет, Backup заполняет только реальные gaps.

Если в Master-настройке включено `failover.repair.deleteOnBackup=true`, после
успешного прохода Master отправляет `repair-ack` на Backup для сегментов,
которые были импортированы или уже покрыты локальным Master-архивом. В payload
используется `failover.repair.deleteGraceSec`. Для этого `peerUrl` должен
содержать token, который проходит failover write-auth на Backup
(`management_token`/failover token). Иначе repair завершится, но в summary будет
`repairAckStatus="failed"`, а Backup продолжит хранить эти диапазоны как
unacknowledged.

Практические команды для проверки отказа Master, оценки покрытого downtime и
ручного repair описаны в `docs/dvr-cluster-failover-runbook.ru.md`.

### Static JPEG Stream Example

```bash
SOURCE=$(curl -sS -X PUT \
  -H "Authorization: Bearer $TOKEN" \
  --data-binary @offline.jpg \
  http://127.0.0.1:3000/api/streams/offline/static-image | jq -r .source)

curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"offline\",\"sourceType\":\"image\",\"source\":\"$SOURCE\",\"enabled\":true}" \
  http://127.0.0.1:3000/api/streams
```

## Flussonic-Compatible Stream API

| Метод | Путь | Body/Query | Назначение |
| --- | --- | --- | --- |
| `GET` | `/streamer/api/v3/streams` | `select`, `limit`, `stats.alive`, `stats.running`, `stats.bytes_in` | Список потоков в формате, близком к Flussonic. |
| `GET` | `/streamer/api/v3/streams/:name` | - | Один поток в Flussonic-compatible формате. |
| `PUT` | `/streamer/api/v3/streams/:name` | Flussonic stream body | Создать/обновить поток или выключить через `disabled:true`. |

Supported input mapping:

- `inputs[0].url` -> `source`
- `disabled` -> inverse `enabled`
- `dvr.expiration` seconds -> `retentionDays`
- `on_play.url` -> global `authBackendUrl` and stream `authMode=authBackend`

Удаление через Flussonic `DELETE` не реализовано намеренно. Для удаления из
внешней интеграции используйте `PUT` с `{"name":"cam","disabled":true}`.

Пример поиска проблемных running streams без входящего потока:

```text
GET /streamer/api/v3/streams?select=name&stats.alive=false&stats.running=true&limit=1000&stats.bytes_in=0
```

## ONVIF API

| Метод | Путь | Body/Query | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/onvif/devices` | `q`, `page`, `pageSize` | Список ONVIF устройств. |
| `POST` | `/api/onvif/devices/scan` | - | Сканирует ONVIF candidates из RTSP sources в config. |
| `POST` | `/api/onvif/devices/scan-stream` | `{"name":"stream","dryRun":true}` | Сканирует ONVIF device для одного stream. |
| `POST` | `/api/onvif/devices/capabilities/check` | `{"ids":["..."]}` | Batch capabilities check, max 200 ids. |
| `POST` | `/api/onvif/devices/bulk` | `{"action":"...","ids":["..."]}` | Batch операции над ONVIF devices. |
| `POST` | `/api/onvif/devices` | Device body | Добавляет устройство. |
| `PUT` | `/api/onvif/devices/:id` | Device body | Полное обновление устройства. |
| `PATCH` | `/api/onvif/devices/:id` | Device patch | Частичное обновление устройства. |
| `DELETE` | `/api/onvif/devices/:id` | - | Удаляет устройство и останавливает events collector. |
| `POST` | `/api/onvif/devices/:id/check` | - | Проверяет устройство и capabilities. |
| `GET` | `/api/onvif/devices/:id/events/status` | - | Runtime status events collector. |
| `GET` | `/api/onvif/events/subscriptions` | - | Список всех event collectors. |
| `POST` | `/api/onvif/events/subscriptions/bulk` | `{"action":"start","ids":["..."]}` | Batch start/stop collectors. |
| `POST` | `/api/onvif/devices/:id/events/subscribe` | - | Включает и запускает PullPoint collector. |
| `POST` | `/api/onvif/devices/:id/events/unsubscribe` | - | Останавливает collector и выключает `eventsEnabled`. |
| `GET` | `/api/onvif/devices/:id/events` | см. ниже | Список/таймлайн событий. |
| `DELETE` | `/api/onvif/devices/:id/events` | - | Удаляет сохранённые события устройства. |

Device body:

```json
{
  "id": "cam1",
  "name": "cam1",
  "host": "10.0.0.10",
  "port": 80,
  "path": "/onvif/device_service",
  "username": "admin",
  "password": "secret",
  "enabled": true,
  "eventsEnabled": false,
  "eventsPullIntervalMs": 5000,
  "sourceStreams": ["cam1"]
}
```

Events query:

- `from`, `to`: Unix seconds, milliseconds, or ISO-like time accepted by parser.
- `page`, `pageSize`/`limit`: page of list events, max `2000`.
- `before`, `after`: cursor-like filters by event time.
- `events=false`: не возвращать list events, только timeline chunk.
- `timeline=true`: добавить `timelineEvents`.
- `timelineFrom`, `timelineTo`: границы timeline chunk.
- `timelineCarry=true`: добавить последнее событие перед `timelineFrom`.

## SesameAgent API

Подробный контракт SesameAgent описан отдельно:
[docs/edge-agent-api.ru.md](./edge-agent-api.ru.md).

| Метод | Путь | Body/Query | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/agents` | - | Список инстансов SesameAgent. |
| `POST` | `/api/agents` | Agent body | Создать agent и enrollment. |
| `POST` | `/api/agents/enroll` | Enrollment body | Enrollment handshake от agent. |
| `GET` | `/api/agents/:id` | - | Один agent. |
| `PUT` | `/api/agents/:id` | Agent body/patch | Обновить agent. |
| `PATCH` | `/api/agents/:id` | Agent patch | Обновить agent. |
| `DELETE` | `/api/agents/:id` | - | Удалить agent. |
| `POST` | `/api/agents/:id/enrollment-password` | `{"password":"..."}` | Задать enrollment password. |
| `POST` | `/api/agents/:id/revoke` | - | Revoke agent. |
| `POST` | `/api/agents/:id/rotate-secret` | - | Ротация agent secret. |
| `GET` | `/api/agents/:id/cameras` | - | Камеры, известные agent. |
| `PUT` | `/api/agents/:id/cameras` | `{"cameras":[...]}` | Заменить список камер agent. |
| `GET` | `/api/agents/:id/cameras/:camera_id/snapshot.jpg` | `fresh`, `timeoutMs` | Получить snapshot через agent. |
| `GET` | `/api/agents/:id/cameras/:camera_id/onvif/events` | same as agent event store | События agent camera. |
| `PUT` | `/api/agents/:id/cameras/:camera_id/config` | Camera config body | Создать/обновить config камеры agent. |
| `DELETE` | `/api/agents/:id/cameras/:camera_id/config` | - | Удалить локальный config камеры agent. |
| `POST` | `/api/agents/:id/onvif/events/batch` | Batch events | Приём ONVIF events от agent; agent-auth. |
| `POST` | `/api/agents/:id/cameras/scan` | Optional command body | Команда agent: scan ONVIF cameras. |
| `PUT` | `/api/agents/:id/remote-config` | Remote config body | Обновить remote config agent. |
| `POST` | `/api/agents/:id/diagnostics` | Optional command body | Команда agent: diagnostics. |
| `GET` | `/api/agents/:id/logs` | - | Logs agent. |
| `GET` | `/api/agents/:id/commands` | - | Commands history/queue. |
| `POST` | `/api/agents/:id/commands` | `{"command":"...","payload":{},"timeoutMs":...}` | Произвольная команда agent. |
| `GET` | `/agent/v1/connect` | headers/query auth, WebSocket upgrade | Persistent WebSocket channel agent -> Sesame DVR. |

## Push Ingest Callback API

Эти endpoints вызываются RTMP/SRT frontend'ом или интеграционным publish
слоем. Они принимают query string, `application/x-www-form-urlencoded` или JSON.

| Метод | Путь | Поля | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/push-ingest/sessions` | - | Active push sessions. |
| `POST` | `/api/push-ingest/rtmp/publish` | `streamName`/`stream`/`name`, `token`/`publishToken`, optional `args` | Authorize and open publish session. |
| `POST` | `/api/push-ingest/rtmp/update` | `sessionId` или stream name, bytes/codecs stats | Heartbeat/stats update. |
| `POST` | `/api/push-ingest/rtmp/done` | `sessionId` или stream name, `reason` | Закрыть publish session. |

## Sessions And Logs

| Метод | Путь | Query | Назначение |
| --- | --- | --- | --- |
| `GET` | `/api/sessions` | - | Active WebRTC, HLS и playback client counters. |
| `GET` | `/api/logs` | `limit` | Merged Sesame DVR + ONVIF runtime event log, max `1000`. |

## ONVIF Runtime Internal API

Когда ONVIF collectors вынесены в отдельный сервис, DVR runtime проксирует часть
команд во внутренний ONVIF runtime. Эти endpoints не предназначены как внешний
публичный API.

| Метод | Путь | Назначение |
| --- | --- | --- |
| `GET` | `/api/onvif/events/subscriptions` | Runtime collectors snapshot. |
| `GET` | `/api/logs` | Runtime logs. |
| `GET` | `/api/system/beam_profile` | BEAM profile ONVIF service. |
| `GET` | `/api/onvif/devices/:id/events/status` | Collector status. |
| `POST` | `/api/onvif/runtime/config/reload` | Reload config in ONVIF runtime. |
| `POST` | `/api/onvif/runtime/devices/:id/events/subscribe` | Runtime-only subscribe. |
| `POST` | `/api/onvif/runtime/devices/:id/events/unsubscribe` | Runtime-only unsubscribe. |

## Common Error Shape

Management JSON endpoints обычно возвращают:

```json
{"error": "error_code", "reason": "..."}
```

Playback/media endpoints могут возвращать plain text ошибки (`403 Forbidden`,
`404 Not found`, `503 ...`) для совместимости с плеерами и media clients.
