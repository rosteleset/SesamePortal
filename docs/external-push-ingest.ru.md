# Внешний RTMP push ingest

Документ описывает штатный приём внешнего RTMP publisher в SesameDVR. Это не
старый контур SRS для live playback: RTMP используется только как входной
транспорт, а дальше поток обрабатывает обычный native ingest SesameDVR.

## Поток данных

```text
publisher
  -> public rtmp://dvr.example.com:1935/push/<stream>?token=<token>
  -> dedicated nginx-rtmp frontend
  -> HTTP publish/update/done callbacks в SesameDVR
  -> local rtmp://127.0.0.1:1935/push/<stream>
  -> native ingest
  -> live-buffer / archive / preview / playback
```

RTMP frontend только принимает соединение и держит live stream. Авторизация,
выбор потока, lifecycle push session и ingest принадлежат SesameDVR.

## Установка

Protected installer по умолчанию:

- устанавливает Debian/Ubuntu packages `nginx` и `libnginx-mod-rtmp`;
- создаёт отдельный однопроцессный RTMP nginx;
- создаёт и включает `sesame-dvr-rtmp.service`;
- записывает public и local RTMP base URL в runtime environment;
- открывает локальный TCP `1935` через активный `ufw` или `iptables`;
- проверяет systemd unit, nginx config и local listener в post-install smoke;
- сохраняет и восстанавливает RTMP config/unit при update rollback;
- удаляет managed unit, config и firewall rule при uninstall.

Основные managed paths:

```text
/etc/sesame-dvr/rtmp-nginx.conf
/etc/systemd/system/sesame-dvr-rtmp.service
/etc/sesame-dvr/sesame-dvr.env
```

Имя environment-файла может отличаться при нестандартном install layout.
Installer записывает в него:

```text
SESAME_DVR_RTMP_PUBLISH_BASE_URL=rtmp://<public-host>:1935/push
SESAME_DVR_RTMP_PULL_BASE_URL=rtmp://127.0.0.1:1935/push
```

Dedicated nginx намеренно имеет `worker_processes 1`: live stream state
`nginx-rtmp` является worker-local. Смешивание RTMP application с
многопроцессным HTTP nginx приводило бы к ситуации, когда publisher и local
pull попали в разные workers. Один worker имеет `16384` connections и
`LimitNOFILE=65535`, чтобы publisher и local pull для тысяч потоков не упёрлись
в стандартный nginx limit.

Если `nginx` был установлен только ради RTMP и HTTP `--publish-service` не
запрошен, bootstrap отключает автоматически запущенный основной `nginx.service`.
Существующий до установки nginx installer не отключает.

Если RTMP обслуживает отдельный внешний frontend, отключите managed frontend
явно:

```bash
sudo sesame-dvr-update --no-rtmp-service
```

Для установки/repair доступны параметры:

```text
--rtmp-service
--no-rtmp-service
--rtmp-service-name <name>
--rtmp-port <port>
--rtmp-nginx-config <path>
```

Cloud firewall, security group и NAT installer изменить не может. В них нужно
разрешить входящий TCP `1935` или выбранный `--rtmp-port`.
Public host берётся из `--publish-server-name`, затем из host FQDN; если FQDN
нет, используется первый адрес хоста.

## Настройка потока через UI

1. Создайте или откройте поток в разделе `Потоки`.
2. Выберите тип источника `push`.
3. Выберите publisher `external`.
4. Сохраните поток.
5. Нажмите `Сгенерировать publish URL`.
6. Передайте полученный URL внешней камере или encoder-у.

URL имеет вид:

```text
rtmp://dvr.example.com/push/camera-1?token=<publishToken>
```

Token принадлежит конкретному push stream. Его ротация инвалидирует старый
publish URL.

## Callback API

Managed frontend вызывает локальный SesameDVR API:

```text
POST /api/push-ingest/rtmp/publish
POST /api/push-ingest/rtmp/update
POST /api/push-ingest/rtmp/done
```

`publish` авторизует stream/token. Non-2xx запрещает публикацию. `update`
обновляет session heartbeat/statistics, `done` закрывает session.

## Readiness в UI и API

SesameDVR проверяет local pull listener низкочастотно и хранит результат в
маленьком runtime snapshot. Статус каждого push-потока содержит `frontend`:

```json
{
  "transport": "rtmp",
  "ready": true,
  "host": "127.0.0.1",
  "port": 1935,
  "reason": null,
  "checkedAtUnix": 1784023200
}
```

Если listener недоступен, UI показывает `RTMP frontend недоступен` и причину.
Проверка не выполняет TCP connect для каждой камеры и каждого `/api/streams`:
один cached snapshot обновляется раз в несколько секунд.

## Проверка на сервере

```bash
sudo systemctl status sesame-dvr-rtmp --no-pager
sudo nginx -t -c /etc/sesame-dvr/rtmp-nginx.conf
sudo ss -ltnp 'sport = :1935'
sudo journalctl -u sesame-dvr-rtmp -n 100 --no-pager
grep '^SESAME_DVR_RTMP_' /etc/sesame-dvr/sesame-dvr.env
```

Проверка публикации с `ffmpeg`:

```bash
ffmpeg -re -i input.mp4 -c copy -f flv \
  'rtmp://dvr.example.com/push/camera-1?token=<publishToken>'
```

После подключения в UI должны появиться активная push session, входящие bytes
и обычный native ingest status. Неверный token должен завершаться отказом
publish.

## SRT

SRT push использует native `SrtRouter` и не зависит от nginx-rtmp service.
Параметр `--no-rtmp-service` отключает только RTMP frontend, не SRT router.
