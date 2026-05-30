# Sesame DVR: Prometheus / OpenMetrics endpoint

Документ описывает настройку и использование endpoint `/metrics`, который
отдаёт статистику Sesame DVR в формате OpenMetrics для Prometheus, Grafana и
alerting.

## Кратко

Endpoint:

```text
GET /metrics
```

Ответ:

- content type:
  `application/openmetrics-text; version=1.0.0; charset=utf-8`;
- формат совместим с Prometheus/OpenMetrics;
- содержит метрики по каждому потоку с labels `server_id` и `name`;
- завершается строкой `# EOF`;
- не использует management token и playback token потоков;
- имеет отдельную настройку доступности/авторизации: `disabled`, `none`,
  `bearer` или `basic`.

## Настройки

Глобальные поля config:

| Поле | Значение | Назначение |
| --- | --- | --- |
| `metricsAuthMode` | `disabled`, `none`, `bearer`, `basic` | Режим доступности/авторизации `/metrics`. Default: `none`. |
| `metricsBearerToken` | string | Write-only token для `Authorization: Bearer ...`. |
| `metricsBasicUsername` | string | Username для Basic auth. Обязателен при `metricsAuthMode=basic`. |
| `metricsBasicPassword` | string | Write-only password для Basic auth. |

Raw secrets в `config.json` не сохраняются. При сохранении сервер пишет только:

```json
{
  "metricsBearerTokenHash": "...",
  "metricsBasicPasswordHash": "..."
}
```

`GET /api/config` возвращает безопасные признаки:

```json
{
  "metricsAuthMode": "basic",
  "metricsBasicUsername": "prometheus",
  "metricsBearerTokenConfigured": true,
  "metricsBasicPasswordConfigured": true
}
```

Если в UI или API не передавать `metricsBearerToken` /
`metricsBasicPassword`, текущий сохранённый секрет не меняется. Для ротации
нужно передать новое непустое значение.

## Настройка через UI

Откройте:

```text
https://<host>/admin
```

Далее:

1. Перейдите в `Настройки -> Global config`.
2. Найдите блок полей:
   - `Авторизация /metrics`;
   - `Metrics bearer token`;
   - `Metrics basic логин`;
   - `Metrics basic пароль`.
3. Выберите режим:
   - `отключено` для полного выключения endpoint;
   - `нет` для открытого endpoint;
   - `Bearer token` для Prometheus с bearer token;
   - `Basic auth` для Prometheus с basic auth.
4. Для `Bearer token` задайте `Metrics bearer token`.
5. Для `Basic auth` задайте `Metrics basic логин` и
   `Metrics basic пароль`.
6. Сохраните настройки.

## Настройка через API

Все примеры ниже используют management API, поэтому нужен management token или
admin session. В примерах:

```bash
export DVR_URL="https://dvr.example.com"
export MGMT_TOKEN="<management-token>"
```

### Посмотреть текущую настройку

```bash
curl -sS \
  -H "Authorization: Bearer $MGMT_TOKEN" \
  "$DVR_URL/api/config" \
  | jq '{
      metricsAuthMode,
      metricsBasicUsername,
      metricsBearerTokenConfigured,
      metricsBasicPasswordConfigured
    }'
```

### Отключить endpoint

```bash
curl -sS -X PATCH \
  -H "Authorization: Bearer $MGMT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"metricsAuthMode":"disabled"}' \
  "$DVR_URL/api/config"
```

После этого:

```bash
curl -i "$DVR_URL/metrics"
```

Запрос должен вернуть `404`. В этом режиме Sesame DVR не отдаёт OpenMetrics
body, даже если передать старый bearer token или Basic credentials.

### Открытый endpoint без авторизации

```bash
curl -sS -X PATCH \
  -H "Authorization: Bearer $MGMT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"metricsAuthMode":"none"}' \
  "$DVR_URL/api/config"
```

После этого:

```bash
curl -i "$DVR_URL/metrics"
```

### Bearer token

```bash
export METRICS_TOKEN="$(openssl rand -hex 32)"

curl -sS -X PATCH \
  -H "Authorization: Bearer $MGMT_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"metricsAuthMode\":\"bearer\",
    \"metricsBearerToken\":\"$METRICS_TOKEN\"
  }" \
  "$DVR_URL/api/config"
```

Проверка:

```bash
curl -i "$DVR_URL/metrics"

curl -i \
  -H "Authorization: Bearer $METRICS_TOKEN" \
  "$DVR_URL/metrics"
```

Первый запрос должен вернуть `401`, второй - `200`.

### Basic auth

```bash
export METRICS_USER="prometheus"
export METRICS_PASSWORD="$(openssl rand -base64 32)"

curl -sS -X PATCH \
  -H "Authorization: Bearer $MGMT_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"metricsAuthMode\":\"basic\",
    \"metricsBasicUsername\":\"$METRICS_USER\",
    \"metricsBasicPassword\":\"$METRICS_PASSWORD\"
  }" \
  "$DVR_URL/api/config"
```

Проверка:

```bash
curl -i "$DVR_URL/metrics"

curl -i \
  -u "$METRICS_USER:$METRICS_PASSWORD" \
  "$DVR_URL/metrics"
```

Первый запрос должен вернуть `401` с header
`WWW-Authenticate: Basic realm="SesameDVR Metrics"`, второй - `200`.

## Проверка Endpoint

Проверка HTTP status:

```bash
curl -fsS \
  -H "Authorization: Bearer $METRICS_TOKEN" \
  "$DVR_URL/metrics" \
  >/tmp/sesame-dvr-metrics.txt
```

Проверка content type:

```bash
curl -sSI \
  -H "Authorization: Bearer $METRICS_TOKEN" \
  "$DVR_URL/metrics" \
  | grep -i '^content-type:'
```

Проверка наличия основных метрик:

```bash
grep -E '^(ts_delay|stream_bitrate|stream_bytes_in|stream_online_clients)\{' \
  /tmp/sesame-dvr-metrics.txt \
  | head
```

Пример фрагмента ответа:

```text
# HELP ts_delay Seconds between now and the latest indexed archive segment.
# TYPE ts_delay gauge
ts_delay{name="cam1",server_id="dvr01"} 2

# HELP stream_bitrate Configured or probed stream bitrate in bits per second.
# TYPE stream_bitrate gauge
stream_bitrate{name="cam1",server_id="dvr01"} 3128000

# EOF
```

## Настройка Prometheus

### Bearer token

Быстрый вариант:

```yaml
scrape_configs:
  - job_name: sesame-dvr
    scheme: https
    metrics_path: /metrics
    bearer_token: <metrics-token>
    static_configs:
      - targets:
          - dvr.example.com
```

Для production лучше хранить token в отдельном файле:

```yaml
scrape_configs:
  - job_name: sesame-dvr
    scheme: https
    metrics_path: /metrics
    bearer_token_file: /etc/prometheus/secrets/sesame-dvr-metrics-token
    static_configs:
      - targets:
          - dvr.example.com
```

Если Sesame DVR опубликован на нестандартном порту:

```yaml
static_configs:
  - targets:
      - dvr.example.com:8443
```

### Basic auth

```yaml
scrape_configs:
  - job_name: sesame-dvr
    scheme: https
    metrics_path: /metrics
    basic_auth:
      username: prometheus
      password: <metrics-password>
    static_configs:
      - targets:
          - dvr.example.com
```

Для production лучше использовать `password_file`:

```yaml
scrape_configs:
  - job_name: sesame-dvr
    scheme: https
    metrics_path: /metrics
    basic_auth:
      username: prometheus
      password_file: /etc/prometheus/secrets/sesame-dvr-metrics-password
    static_configs:
      - targets:
          - dvr.example.com
```

### Self-signed TLS

Если на стенде используется self-signed сертификат, временно можно включить:

```yaml
tls_config:
  insecure_skip_verify: true
```

Для production лучше настроить нормальный trusted certificate, а не отключать
проверку TLS.

## Метрики

### Flussonic-compatible

| Метрика | Тип | Labels | Описание |
| --- | --- | --- | --- |
| `ts_delay` | gauge | `server_id`, `name` | Задержка между текущим временем сервера и последним индексированным archive segment, секунды. |
| `stream_bitrate` | gauge | `server_id`, `name` | Bitrate из runtime/ffprobe metadata, bit/s. Если metadata ещё нет, значение `0`. |
| `stream_bytes_in` | counter | `server_id`, `name` | Сколько bytes прочитал текущий ingest-процесс из input source. Counter сбрасывается при restart сервиса/процесса. |
| `stream_input_retries` | counter | `server_id`, `name` | Количество запланированных ingest restart attempts с момента старта сервиса. |
| `stream_online_clients` | gauge | `server_id`, `name` | Текущее количество HLS + WebRTC playback clients, которые видит Sesame DVR. |
| `play_bytes` | counter | `server_id`, `name` | Compatibility metric. Сейчас Sesame DVR не считает playback bytes и отдаёт `0`. |

### Sesame DVR-specific

| Метрика | Тип | Labels | Описание |
| --- | --- | --- | --- |
| `sesame_dvr_stream_enabled` | gauge | `server_id`, `name` | `1`, если поток включён в config. |
| `sesame_dvr_stream_running` | gauge | `server_id`, `name` | `1`, если ingest runtime сейчас запущен. |
| `sesame_dvr_stream_archive_writing` | gauge | `server_id`, `name` | `1`, если archive status считает, что поток сейчас пишет свежие сегменты. |
| `sesame_dvr_stream_input_packets_total` | counter | `server_id`, `name` | Media packets, прочитанные из input source. |
| `sesame_dvr_stream_video_packets_total` | counter | `server_id`, `name` | Video packets, прочитанные из input source. |
| `sesame_dvr_stream_audio_packets_total` | counter | `server_id`, `name` | Audio packets, прочитанные из input source. |
| `sesame_dvr_stream_skipped_packets_total` | counter | `server_id`, `name` | Packets, которые ingest пропустил. |

`server_id` сейчас берётся из hostname сервера. `name` - стабильное имя потока
в Sesame DVR.

## PromQL-примеры для Grafana

Активные playback clients по потокам:

```promql
stream_online_clients
```

Суммарное число viewers на сервере:

```promql
sum by (server_id) (stream_online_clients)
```

Bitrate в Mbps:

```promql
stream_bitrate / 1000000
```

Скорость входящего трафика ingest за 5 минут:

```promql
rate(stream_bytes_in[5m])
```

Включённые потоки, у которых ingest не запущен:

```promql
sesame_dvr_stream_enabled == 1
and on (server_id, name)
sesame_dvr_stream_running == 0
```

Потоки с задержкой архива больше 5 минут:

```promql
ts_delay > 300
```

Потоки с рестартами ingest за последние 15 минут:

```promql
increase(stream_input_retries[15m]) > 0
```

Скорость skipped packets:

```promql
rate(sesame_dvr_stream_skipped_packets_total[5m])
```

Включённые потоки, у которых нет свежей записи архива:

```promql
sesame_dvr_stream_enabled == 1
and on (server_id, name)
sesame_dvr_stream_archive_writing == 0
```

## Alert Rules

Пример группы alert rules:

```yaml
groups:
  - name: sesame-dvr-streams
    rules:
      - alert: SesameDvrStreamDown
        expr: |
          sesame_dvr_stream_enabled == 1
          and on (server_id, name)
          sesame_dvr_stream_running == 0
        for: 2m
        labels:
          severity: warning
        annotations:
          summary: "Sesame DVR stream is enabled but not running"
          description: "Stream {{ $labels.name }} on {{ $labels.server_id }} is enabled but ingest is not running."

      - alert: SesameDvrArchiveStale
        expr: ts_delay > 300
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Sesame DVR archive is stale"
          description: "Stream {{ $labels.name }} on {{ $labels.server_id }} has archive delay {{ $value }} seconds."

      - alert: SesameDvrInputRestarting
        expr: increase(stream_input_retries[15m]) > 2
        for: 1m
        labels:
          severity: warning
        annotations:
          summary: "Sesame DVR stream input is restarting"
          description: "Stream {{ $labels.name }} restarted more than twice during the last 15 minutes."

      - alert: SesameDvrNoInputTraffic
        expr: |
          sesame_dvr_stream_running == 1
          and on (server_id, name)
          rate(stream_bytes_in[5m]) == 0
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Sesame DVR stream has no input traffic"
          description: "Stream {{ $labels.name }} is running but input byte rate is zero."
```

## Security Notes

- Если Prometheus не используется, выставьте `metricsAuthMode=disabled`.
- Для production не оставляйте `metricsAuthMode=none`, если `/metrics`
  доступен не только из private network.
- Предпочтительно использовать HTTPS.
- Для Prometheus лучше хранить секреты через `bearer_token_file` или
  `password_file`, а не прямо в `prometheus.yml`.
- `/metrics` не даёт управление сервером, но раскрывает имена потоков,
  состояние записи, bitrate и активность клиентов.
- Если используется reverse proxy/firewall, разрешите доступ к `/metrics`
  только от Prometheus.

## Troubleshooting

### `401 Unauthorized`

Проверьте:

- `metricsAuthMode`;
- правильность `Authorization: Bearer ...`;
- для Basic auth - username и password;
- что после ротации secret Prometheus был перезапущен или перечитал config.

### `404 Not Found`

Если `GET /metrics` возвращает `404`, проверьте `metricsAuthMode`. При
`metricsAuthMode=disabled` endpoint намеренно выключен и не отдаёт метрики.

### Prometheus показывает `up == 0`

Проверьте из Prometheus host:

```bash
curl -v "$DVR_URL/metrics"
```

Если используется HTTPS:

- проверьте DNS;
- проверьте сертификат;
- проверьте порт в `targets`;
- временно проверьте `tls_config.insecure_skip_verify: true` только для
  диагностики self-signed TLS.

### В метриках нет потоков

`/metrics` строится из текущего списка потоков Sesame DVR. Если потоков нет в
`GET /api/streams`, их не будет и в `/metrics`.

### `stream_bitrate` равен `0`

Bitrate берётся из runtime/ffprobe metadata. Значение может быть `0`, если:

- поток ещё не запускался;
- metadata ещё не собрана;
- source не отдаёт bitrate;
- ffprobe/libavformat не смогли определить bitrate.

### Counter сбрасывается

`stream_bytes_in`, `stream_input_retries` и `sesame_dvr_stream_*_total`
относятся к текущему runtime процесса и могут сбрасываться при restart сервиса
или ingest-процесса. В Grafana используйте `rate()` / `increase()` вместо
абсолютных значений для графиков и alerts.

### `play_bytes` всегда `0`

Это ожидаемое поведение текущей версии. Метрика оставлена для совместимости с
Flussonic-like dashboards, но Sesame DVR пока не считает фактически отданные
playback bytes.
