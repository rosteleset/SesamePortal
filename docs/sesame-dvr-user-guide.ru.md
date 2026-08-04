# Sesame DVR: руководство пользователя

Документ описывает эксплуатацию Sesame DVR после установки: первый вход,
добавление камер, просмотр live/архива, ONVIF-события, управление хранилищем,
обновления, диагностику и типовые неисправности.

Руководство рассчитано на администратора сервера и оператора системы. Оно не
заменяет внутренние runbook-документы для сборки protected artifacts и
администрирования license server.

## Содержание

1. [Назначение Sesame DVR](#назначение-sesame-dvr)
2. [Требования к серверу](#требования-к-серверу)
3. [Установка](#установка)
4. [Первый вход и доступы](#первый-вход-и-доступы)
5. [Обзор web-интерфейса](#обзор-web-интерфейса)
6. [Потоки и камеры](#потоки-и-камеры)
7. [Просмотр live и архива](#просмотр-live-и-архива)
8. [ONVIF и события движения](#onvif-и-события-движения)
9. [Агенты](#агенты)
10. [Клиенты](#клиенты)
11. [Мониторинг и журналы](#мониторинг-и-журналы)
12. [Лицензия и обновления](#лицензия-и-обновления)
13. [Хранилище архива](#хранилище-архива)
14. [Глобальные настройки](#глобальные-настройки)
15. [Служебные команды](#служебные-команды)
16. [Файлы и каталоги](#файлы-и-каталоги)
17. [Безопасность](#безопасность)
18. [Типовые сценарии](#типовые-сценарии)
19. [Диагностика проблем](#диагностика-проблем)
20. [HTTP endpoints для интеграций](#http-endpoints-для-интеграций)

## Назначение Sesame DVR

Sesame DVR - серверная система видеозаписи и просмотра IP-камер. Сервер
принимает RTSP/HTTP-источники, UDP multicast, RTMP/SRT push, HLS passthrough,
статические JPEG и зацикленные видеофайлы, пишет архив на диск, отдаёт
live-видео, архивные HLS плейлисты, preview, MP4 export и предоставляет
web-интерфейс администратора.

Основные задачи:

- подключение RTSP/HTTP-камер, UDP multicast, RTMP/SRT push, HLS passthrough,
  статических JPEG-источников и зацикленных видеофайлов;
- запись архива с настраиваемым сроком хранения;
- live-просмотр через embed-плеер, HLS и WebRTC/WHEP;
- публикация multi-bitrate HLS из нескольких существующих потоков;
- просмотр архива по временной шкале;
- выгрузка фрагментов архива в MP4;
- сбор ONVIF motion events;
- мониторинг потоков, сервера, дисков, клиентов и журналов;
- управление хранилищем в SingleVolume и MultiVolume режимах;
- штатное обновление protected-версии без исходников на сервере клиента.

## Требования к серверу

Поддерживаемая production-установка:

- Ubuntu 22.04 / 24.04 / 26.04 или Debian 12 / 13;
- архитектура `amd64 / x86_64`;
- `systemd`;
- `sudo` или root-доступ для установки;
- исходящий HTTPS к `https://license.sesameware.com` и
  `https://license-2.sesameware.com`;
- достаточная пропускная способность сети до камер;
- отдельный диск или раздел под архив желательно монтировать в `/var/dvr`.

Для публичного доступа через домен:

- DNS A/AAAA запись домена должна указывать на сервер;
- входящий TCP `80`;
- для HTTPS и Let's Encrypt также входящий TCP `443`;
- если используется cloud firewall/security group, эти порты нужно открыть там
  отдельно.

Производительность зависит от числа камер, codec, bitrate, длительности
хранения, скорости дисков, CPU и включённых функций. WebRTC работает без
транскодирования, поэтому зависит от поддержки codec в браузере.

## Установка

Обычно установка выполняется одной командой, которую администратор получает из
license-панели. Команда содержит activation key и параметры публикации.

### Установка с публичным HTTPS

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-protected-install.sh \
  | sudo bash -s -- \
      --license-key '<activation-key>' \
      --locale ru \
      --server-name "$(hostname -f)" \
      --publish-service \
      --publish-server-name dvr.example.com \
      --publish-acme \
      --acme-email admin@example.com
```

Замените:

- `<activation-key>` на выданный ключ;
- `dvr.example.com` на домен сервера;
- `admin@example.com` на email для Let's Encrypt.

### Установка с публичным HTTP

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-protected-install.sh \
  | sudo bash -s -- \
      --license-key '<activation-key>' \
      --locale ru \
      --server-name "$(hostname -f)" \
      --publish-service \
      --publish-server-name dvr.example.com
```

`--locale` задаёт начальный язык web-интерфейса (`interfaceLanguage` в
`config.json`). Если параметр не передан, используется язык по умолчанию.

### Что делает инсталлятор

Инсталлятор:

- определяет ОС и архитектуру;
- скачивает подходящий protected artifact;
- активирует лицензию через license server;
- при необходимости ждёт сборки per-instance anchor;
- устанавливает приложение в `/opt/sesame-dvr`;
- создаёт state directory `/var/lib/sesame-dvr`;
- создаёт архивный root `/var/dvr`;
- устанавливает systemd service `sesame-dvr`;
- устанавливает support tools в `/usr/local/sbin`;
- при `--publish-service` настраивает nginx;
- при `--publish-acme` выпускает Let's Encrypt сертификат;
- печатает ссылку для первого входа, если был создан management token.

## Первый вход и доступы

Admin UI доступен по:

```text
https://<domain>/admin
```

или, если HTTPS не настроен:

```text
http://<domain>/admin
```

На чистой установке UI может запросить:

```text
login: admin
password: admin
```

После первого входа нужно сменить стандартный пароль администратора.

Если installer вывел ссылку вида:

```text
https://<domain>/admin?token=...
```

откройте её в браузере. Management token будет сохранён в браузере и будет
использоваться для management API. В разделе `Настройки` можно:

- применить существующий management token;
- сгенерировать новый token;
- сбросить token в текущем браузере;
- сменить пароль UI панели;
- выйти из admin UI.

## Обзор web-интерфейса

Главные разделы:

- `Потоки` - список камер, управление потоками, live/archive playback, ffprobe
  и логи выбранного потока.
- `Мониторинг` - текущие метрики и графики CPU, памяти, дисков, сети, ingest,
  BEAM/runtime и процессов.
- `Журналы` - общий журнал событий Sesame DVR с фильтрами по уровню и source.
- `Клиенты` - активные playback client users, WebRTC-сессии и недавние
  HLS-клиенты.
- `ONVIF` - список ONVIF-устройств, проверка capabilities, подписка на events.
- `Агенты` - удалённые инстансы SesameAgent: одноразовые pairing-приглашения,
  статус подключения, анонсированные агентом камеры, явные bindings потоков,
  diagnostics/logs и, после отдельной managed-авторизации, разрешённые команды
  агенту.
- `Настройки` - лицензия, обновления, хранилище, tokens, orphan cleanup и
  global config.

## Потоки и камеры

Поток - это камера или другой источник видео. Основные поля потока:

- `Имя` - стабильный идентификатор камеры в URL и архиве. Используйте латиницу,
  цифры, дефис или подчёркивание.
- `Тип источника` - `direct` для RTSP/HTTP, `udp_multicast` для UDP multicast,
  `udp_multicast_passthrough` для TS/UDP multicast без перекодирования,
  `push` для RTMP/SRT push, `image` для статического JPEG-источника,
  `video_file` для зацикленного MP4/WebM или `hls_passthrough` для HLS без
  обычного archive transcode pipeline.
- `Source` - RTSP/URL источника, например
  `rtsp://user:password@10.0.0.10:554/stream1`. Для `udp_multicast` используйте
  URL вида
  `udp://@239.10.10.10:5000?localaddr=192.168.0.1&overrun_nonfatal=1&fifo_size=5000000`.
  Для `udp_multicast_passthrough` используйте такой же UDP multicast URL; сервер
  принимает MPEG-TS, режет его в TS/HLS сегменты без изменения A/V кодеков и
  хранит/отдаёт их тем же HLS passthrough path. Для `hls_passthrough` укажите
  HLS URL со схемой `http://`, `https://` или `hls://`. Для `image` и
  `video_file` это путь к загруженному файлу в `dvrRoot/static-sources`; после
  загрузки к нему может добавляться version query, чтобы running-поток
  перезапустился при замене файла.
- `JPEG файл` - файл, который web UI загружает на сервер для `sourceType=image`;
  из него `ffmpeg_nif` формирует статический H.264/fMP4 видеопоток для
  live HLS и WHEP.
- `Видео-файл` - MP4 или WebM размером до 250 MB, который web UI загружает для
  `sourceType=video_file`. Сервер воспроизводит файл по кругу как live-источник;
  долговременный архив для такого типа не создаётся.
- `Писать архив` - включает долговременное хранение сегментов потока. Если
  выключить, сервер оставляет только минимальный live-buffer для live playback и
  сразу удаляет старые сегменты этого потока из storage/catalog. Для JPEG и
  зацикленных видеофайлов архив принудительно выключен, но live-buffer всё равно
  держит последние `liveWindow` fMP4-сегментов, чтобы HLS-плееры стартовали без
  ожидания накопления нескольких обновлений playlist.
- `Запускать по запросу` - доступно для `direct` и `udp_multicast`, только когда
  `Писать архив` выключено. Ingest не запускается при старте сервиса, а
  включается после авторизованного запроса HLS, LL-HLS или WebRTC-зрителя. После
  ухода последнего зрителя runtime останавливается через 60 секунд. Preview
  запросы поток не запускают.
- `Retention` - срок хранения архива. Можно задавать числа в днях или строки
  `7d`, `6h`, `180m`, `2M`. Заглавная `M` означает целое число календарных
  месяцев: `1M` равно числу дней в текущем месяце, `2M` - сумме дней в текущем
  и предыдущем месяцах, `3M` - в текущем и двух предыдущих. Строчная `m`
  означает минуты. Строковое значение сохраняется в конфиге и затем
  отображается в интерфейсе как было введено; числовое значение интерпретируется
  как количество дней.
- `Authorization`:
  - `none` - playback endpoints доступны без token;
  - `static` - playback требует query `?token=<token>`;
  - `auth backend` - доступ проверяется внешним backend URL из global config.
- `Disable audio` - отключает аудио в ingest/playback для потока.
- `Enabled` - поток включён в config и должен запускаться после старта сервиса.

### Multi-bitrate HLS

Multi-bitrate поток объединяет несколько уже существующих потоков с разным
bitrate или разрешением в один HLS master playlist. HLS-плеер получает список
вариантов и может автоматически переключать качество в зависимости от сети и
возможностей устройства.

В разделе `Потоки` multi-bitrate поток создаётся отдельно от обычной камеры.
Для каждого варианта укажите существующий canonical stream. Sesame DVR берёт
`bandwidth`, resolution, frame rate и codecs из cached ffprobe metadata; при
необходимости эти значения можно задать вручную. Если metadata ещё нет, для
включения варианта в master playlist обязательно задайте `bandwidth`.

Multi-bitrate поток не является `sourceType` камеры и не создаёт собственный
архив: его варианты читают live media playlists существующих потоков. Имя
занимает общее playback namespace, а live HLS доступен по
`/<name>/index.m3u8` и обычным aliases `live.m3u8` и `video.m3u8`. Настройки
доступа (`none`, `static` или `authBackend`) задаются для самого multi-bitrate
потока.

Для внешнего RTMP publisher выберите `Тип источника = push`,
`Publisher = external`, сохраните поток и нажмите
`Сгенерировать publish URL`. Если local RTMP listener не готов, панель потока
показывает `RTMP frontend недоступен` вместо бесконечного ожидания publisher-а.
Установка, URL, firewall и серверная диагностика описаны в
[external-push-ingest.ru.md](external-push-ingest.ru.md).

В protected-версии producer не выбирается на уровне отдельного потока:
используется global/default `ffmpeg_nif`. Он пишет self-initializing fMP4
segments напрямую в выбранный storage volume. Внешний `ffmpeg` producer
доступен только в unprotected/dev-сборках и будет отклонён protected runtime.

### Резервирование потоков DVR-side

В секции `Failover` у потока можно включить прикладное резервирование между
двумя SesameDVR-нодами без участия Portal:

- `none` - обычный поток без резервирования;
- `master` - основной поток, который пишет архив и после восстановления может
  забрать недостающие интервалы с Backup;
- `backup` - резервный поток, который следит за health Master и стартует запись
  только после устойчивого отказа.

`Peer URL` должен указывать на связанный поток другой DVR-ноды в формате:

```text
https://peer.example/api/failover/streams/<peer-stream>?token=<playback-token>
```

`token` здесь - обычный playback token peer-потока. Он используется для
health/read-side failover запросов и чтения архива через обычные playback
endpoints. Control-действия `backup/start`, `backup/stop`, `repair/run`,
`cleanup/run` и `repair-ack` требуют management/failover auth.

В защищённой сборке failover включается отдельной license feature
`dvr_failover`. Если feature отключена в лицензии или lease, сервер запрещает
сохранение `failover.mode=master|backup`, возвращает `403
license_feature_disabled` на `/api/failover/...` и останавливает активные
Backup/hot-buffer runtime.

Для Backup можно включить `Hot-buffer`: тогда резервный runtime постоянно пишет
короткий standby-буфер, даже пока Master здоров. Буфер ограничивается
`Hot-buffer duration` и опционально `Hot-buffer quota`. При реальном failover
последние секунды hot-buffer перед стартом Backup сохраняются как repair window,
чтобы закрыть gap между фактическим отказом Master и моментом обнаружения.
Hot-buffer создаёт второе постоянное подключение к камере; на single-client
RTSP камерах этот режим нужно оставлять выключенным.

Для ручной или внешней автоматизации распределения Backup-потоков есть
DVR-side placement planner API. `GET /api/failover/nodes/local/resources`
возвращает resource snapshot текущей ноды, а
`POST /api/failover/placement/plan` принимает список нод и потоков, считает
estimated bitrate, требуемый backup storage на заданный downtime, выбирает
Backup-ноду по capacity/weights и возвращает dry-run warnings. Это не Portal
cluster planner: SesameDVR только считает и, через
`POST /api/failover/placement/apply`, может применить patches для своей
локальной ноды.

### SRT-доставка push-потоков

SRT поддерживается как транспорт доставки для потоков с `sourceType=push`.
Типовой сценарий - камера находится за NAT или в нестабильной сети, локальный
SesameAgent читает её по RTSP/ONVIF и публикует media в SesameDVR по SRT/MPEG-TS.
Также SRT может использовать внешний publisher, если он отправляет MPEG-TS и
указывает правильный stream id.

SesameDVR поднимает общий SRT acceptor/router на одном публичном UDP-порту и
маршрутизирует входящие потоки по `streamid=sesame:stream=<streamName>`. Отдельный
публичный порт на каждую камеру не нужен. Обычно agent получает SRT caller URL
из control-команды, например:

```text
srt://dvr.example.com:10080?mode=caller&transtype=live&pkt_size=1316&streamid=sesame%3Astream%3Dgate
```

Основные настройки SRT на стороне SesameDVR:

- `SESAME_DVR_SRT_PUBLISH_HOST` - публичный host, который попадёт в caller URL;
- `SESAME_DVR_SRT_BIND_HOST` - bind host router-а, по умолчанию `0.0.0.0`;
- `SESAME_DVR_SRT_BASE_PORT` - единый публичный UDP-порт, по умолчанию `10080`;
- `SESAME_DVR_SRT_LATENCY_MS` - latency SRT, по умолчанию `120`;
- `SESAME_DVR_SRT_PASSPHRASE` - опциональный общий passphrase для защиты SRT
  порта.

Для работы SRT нужно открыть входящий UDP-порт `SESAME_DVR_SRT_BASE_PORT` в
firewall/security group. SRT защищает участок доставки publisher/agent ->
SesameDVR, но не является локальным store-and-forward буфером и не исправляет
проблемы RTSP-участка камера -> agent.

### Действия с потоком

- `Сохранить` - записать изменения в config.
- `Старт` - запустить runtime без изменения `enabled` в config.
- `Стоп` - остановить runtime без изменения `enabled` в config.
- `Рестарт` - перезапустить runtime выбранного потока.
- `Вкл` - включить поток в config и запустить.
- `Выкл` - выключить поток в config и остановить.
- `Удалить` - удалить поток из config. Архив на диске автоматически не
  удаляется как часть удаления потока.

В левой панели можно выбирать несколько потоков и выполнять bulk-действия:
`Старт`, `Стоп`, `Вкл`, `Выкл`, `Удалить`.

Для on-demand потока `Старт` принудительно оставляет runtime включённым, а
`Стоп` запрещает автоматический запуск от нового зрителя. Действие `Вкл`
сбрасывает ручное состояние и снова переводит поток в обычное on-demand
ожидание.

### Статусы потока

В карточке и заголовке выбранного потока отображаются:

- runtime state: запущен/остановлен/ошибка;
- ingest producer;
- codec и audio codec;
- готовность WebRTC;
- статус ONVIF;
- состояние архива и задержка последнего сегмента;
- состояние on-demand: ожидание зрителя или активный ingest;
- срок хранения.

Если поток включён, но не пишет архив, в первую очередь проверьте вкладки
`Логи`, `Ffprobe инфо`, `Мониторинг` и доступность RTSP URL с сервера.

## Просмотр live и архива

### Embed-плеер

В разделе `Потоки` вкладка `Монитор` показывает embed-плеер выбранной камеры.
Он умеет:

- live playback;
- переключение к архивному времени;
- отображение временной шкалы архива;
- отображение ONVIF events на шкале;
- воспроизведение архива только по участкам движения;
- воспроизведение архива в режиме timelapse, если он включён для потока;
- управление масштабом timeline;
- переход к выбранному времени;
- выгрузку MP4-фрагмента.

По умолчанию архивный HLS в embed-плеере остаётся конечным VOD playlist, чтобы
не менять поведение существующих внешних встраиваний. Для новых интеграций
доступен отдельный режим `archive_playlist=sliding`:

```text
https://dvr.example.com/cam1/embed.html?dvr=true&archive_playlist=sliding
```

В этом режиме player запрашивает растущий archive HLS `EVENT` playlist без
`#EXT-X-ENDLIST`. Сервер сначала отдаёт короткое окно архива, а затем расширяет
его при повторных запросах hls.js с учётом текущей скорости воспроизведения.
Это уменьшает видимые перезапуски при длинном архивном просмотре и особенно
при быстрых скоростях `8x`/`16x`. В браузерах с native HLS режим автоматически
не используется: они остаются на обычном VOD playlist.

Видимость нижней панели управления задаётся query-параметром `hidecontrols`.
Без параметра или при `hidecontrols=false` player работает как раньше.
`hidecontrols=true` и `hidecontrols=0` всегда скрывают панель. Положительное
число задаёт задержку скрытия в секундах; движение указателя над player или
касание снова показывает панель:

```text
https://dvr.example.com/cam1/embed.html?hidecontrols=3
```

Кнопка режима движения включает воспроизведение архива только по ONVIF
motion=true участкам. Плеер строит специальный HLS playlist из событий движения,
добавляет запас по краям события и пропускает промежутки без движения без
перезагрузки video source. Индикатор текущего воспроизведения остаётся на
реальной шкале времени архива: когда HLS перескакивает дыру без события,
индикатор тоже перескакивает на следующий архивный участок.

Если в настройках потока включена `Писать timelapse`, рядом с обычным архивом
появляется режим `Timelapse`. Он использует отдельный endpoint
`/<camera>/timelapse.m3u8`: без параметров сервер отдаёт весь сохранённый
timelapse, а с `start=...&end=...` ограничивает playlist окном архива. В
playlist попадают только заранее собранные timelapse chunks. Плеер показывает
две системы времени: длительность ускоренного timelapse-файла и реальное время
архивного кадра. Timeline и курсор в режиме `Timelapse` привязаны к реальному
времени архива, как в обычном DVR-режиме, а строка `Timelapse 0:48 / 0:48`
показывает позицию внутри ускоренного media-файла. Частота сохранения задаётся
настройкой `Кадров в час`, глубина хранения задаётся в формате `d`/`h`/`m`, а
скорость итогового HLS - через `FPS воспроизведения`.

Если у потока выключено `Писать архив`, embed-плеер работает как live-only:
не показывает режим `Archive`, не рисует archive timeline и не предлагает MP4
export, даже если URL открыт с `dvr=true`.

Ссылка на embed-плеер:

```text
/<camera>/embed.html
```

Например:

```text
https://dvr.example.com/cam1/embed.html
```

### WebRTC/WHEP

Вкладка `WebRTC` запускает live WebRTC playback. WebRTC зависит от codec:

- H.264 обычно поддерживается браузерами лучше всего;
- HEVC зависит от браузера, ОС и аппаратной поддержки;
- если WebRTC недоступен, используйте HLS/embed fallback.

Для agent push-потоков со сквозным `passthrough_cbcs` плеер использует E2EE
WebRTC. Agent создаёт один дополнительный зашифрованный SRT uplink на камеру, а
SesameDVR обслуживает все browser peer connections и пересылает media, не
расшифровывая её. При первом открытии плеер попросит private key recipient; файл
остаётся в памяти browser tab. Этот режим сейчас video-only и использует generic
WebRTC Encoded Transform (`RTCRtpScriptTransform`) в актуальных Chrome/Edge,
Firefox и Safari. При отсутствии этого API player автоматически использует
encrypted HLS.

Новый WebRTC viewer ждёт ближайший keyframe. Сейчас WHEP PLI не передаётся
обратно Agent, поэтому начальная задержка может достигать длины GOP камеры.

HLS и WebRTC также учитывают лицензионный лимит активных playback client users.
Если лимит исчерпан, новый клиент получит ошибку `max_client_users_exceeded`,
а уже активные клиенты продолжат работать.

### Архив HLS

Архивные интервалы отображаются на timeline. Для ручного просмотра можно задать
начало и конец периода и нажать `Архив HLS`.

Если архив показывает gaps:

- камера могла не писать в этот период;
- volume мог быть offline/read-only/full;
- retention мог удалить старые сегменты;
- поток мог перезапускаться или терять RTSP;
- после ручных переносов данных может потребоваться audit/rebuild catalog.

### MP4 export

Выберите диапазон времени в embed-плеере и используйте download/export. Export
собирает MP4-файл из архивных сегментов. Временные export-файлы автоматически
очищаются после короткого времени.

### Preview

Sesame DVR может генерировать короткие preview MP4, а также JPG frames, если это
включено в настройках. Preview является cache: его можно потерять и
пересоздать из сегментов. Если `previewMp4CacheEnabled=false`, отдельные MP4
preview-файлы не создаются: MP4 preview endpoints используют ближайший архивный
сегмент, а `previewDuration` относится только к режиму отдельного MP4 cache.

## ONVIF и события движения

Раздел `ONVIF` используется для устройств, у которых есть ONVIF endpoint.

Основные операции:

- `Сканировать RTSP` - попытаться найти ONVIF-устройства на основе RTSP-камер;
- `Новое устройство` - добавить ONVIF endpoint вручную;
- `ONVIF камера` в карточке выбранного потока - открыть привязанное ONVIF-устройство
  или попробовать добавить его по RTSP URL этого потока;
- `Проверить возможности` - получить capabilities устройства;
- `Запустить события` - подписаться на PullPoint events;
- `Остановить события` - остановить сбор событий;
- `Очистить журнал` - удалить сохранённые events выбранного устройства.

Если ONVIF добавляется из выбранного RTSP-потока, SesameDVR сначала пробует
найти endpoint автоматически только для этой камеры. Если автопоиск не нашёл
устройство, UI открывает форму добавления и подставляет `Host`,
`Username`/`Password`, имя потока и срок хранения из RTSP-настроек. ONVIF `Port`
и `Path` всё равно надо проверить вручную, потому что RTSP port обычно не равен
ONVIF port. Если автопоиск нашёл endpoint, SesameDVR попросит подтвердить
добавление или привязку устройства перед записью в конфиг.

Поля ONVIF-устройства:

- `Name` - имя устройства;
- `Host` - IP или hostname камеры;
- `Port` - ONVIF port, часто `80`, `8080` или нестандартный порт;
- `Path` - обычно `/onvif/device_service`;
- `Username` и `Password` - учётные данные камеры;
- `Events retention days` - срок хранения ONVIF events;
- `Pull interval seconds` - интервал опроса PullPoint events.

ONVIF events хранятся отдельно от видеоархива, потому что их проще
периодически бэкапить и восстанавливать. В UI они отображаются рядом с
архивными интервалами, чтобы быстрее находить движение.

## Агенты

Раздел `Агенты` используется для удалённых объектов, где SesameDVR не имеет
прямого доступа к локальным RTSP/ONVIF-камерам или где удобнее поставить
небольшой SesameAgent внутри локальной сети объекта.

В разделе можно:

- создать одноразовое pairing invitation и передать его владельцу агента;
- включить, отключить, удалить агента или полностью отозвать pairing;
- проверить online/offline статус, capabilities и последнюю активность агента;
- посмотреть потоки, которые агент сам настроил и анонсировал;
- явно связать announced stream с заранее созданным push stream SesameDVR;
- проверить transport и точное совпадение encryption policy/recipients;
- отдельно сохранить management credential и повысить текущую session до
  `managed`;
- только в `managed` получить snapshot, запустить ONVIF scan, запросить
  diagnostics/log tail или изменить локальный stream.

Владелец агента импортирует invitation через локальный UI и сверяет
TLS/SPKI fingerprint. После pairing агент сам подключается к SesameDVR по
`/agent/v2/connect`. Каждое соединение начинается в базовом режиме:
SesameDVR принимает inventory, ONVIF events и publish requests, но не управляет
локальной конфигурацией. Remote management включается владельцем агента
отдельно и действует только после дополнительной авторизации текущей session.

Локально enabled stream сам запрашивает short-lived scoped grant и публикует
media через RTMP/FLV или SRT/MPEG-TS. Grant выдаётся только при наличии явного
binding `agentId + localStreamId -> serverStreamName` и совпадении revision,
transport и encryption policy. Для encrypted stream downgrade в clear mode
запрещён. RTSP/ONVIF credentials, private recipient keys и clear CEK не
передаются SesameDVR.

Подробная процедура настройки и модель безопасности описаны в
[`sesame-agent-architecture.ru.md`](sesame-agent-architecture.ru.md), wire/API
contract - в [`edge-agent-api.ru.md`](edge-agent-api.ru.md).

Если для SRT agent stream включён `archiveEncryption.mode=passthrough_cbcs` и
WebRTC, Agent не устанавливает отдельное соединение с каждым viewer. Он шифрует
encoded frames один раз и отправляет один дополнительный stream
`<streamName>~frame-e2ee`; fan-out, ICE и SRTP для всех browser clients выполняет
SesameDVR. Поэтому рост числа зрителей не увеличивает количество media uplinks
с объекта. Native SFrame RTP path можно выбрать отдельно через
`SESAME_DVR_LIVE_WEBRTC_E2EE_MODE=sframe`.

Функциональность SesameAgent включается лицензией. Если feature не включена,
раздел `Агенты` и соответствующие API будут недоступны.

## Клиенты

Раздел `Клиенты` показывает:

- сводку активных playback client users и лицензионный лимит;
- активные WebRTC-сессии из native WHEP registry;
- недавние HLS-клиенты по запросам к playlist/segment.

Это полезно, чтобы понять, кто сейчас смотрит камеры и создаёт нагрузку на
сервер.

Playback client user считается по сочетанию IP-адреса клиента и `User-Agent`.
Для HLS активность держится коротким TTL после последних playlist/segment
запросов, для WebRTC используется live registry активных WHEP-сессий. Лицензия
может задавать лимит `maxClientUsers`.

## Мониторинг и журналы

### Мониторинг

Раздел `Мониторинг` показывает:

- CPU всего и CPU Sesame DVR;
- отдельную нагрузку ingest, ONVIF, nginx и BEAM runtime;
- память процесса и системы;
- диски и storage volumes;
- сеть;
- ingest-процессы;
- BEAM profile и top runtime consumers;
- Native IO Gateway, RawFileIO, Disk IO pressure и DeleteQueue;
- backlog/lag внутренних HIDX/CIDX/PIDX materializer-ов;
- статистику hot caches для индексов, recording status и timelapse ranges.

Обычный polling Dashboard должен оставаться дешёвым. Расширенные storage/BEAM
диагностические секции обновляются через debug diagnostics/manual snapshot:
это позволяет смотреть Native IO, RawFileIO, BEAM dirty schedulers и подробные
кеши без постоянной нагрузки на production-сервер.

Если CPU вырос, сначала проверьте:

- сколько камер реально пишут архив;
- не запущены ли массовые playback/API запросы;
- нет ли частых ошибок в логах;
- не идёт ли rebuild/audit catalog;
- не перегружен ли диск.

На крупных RTSP/TCP инсталляциях также проверьте системный `irqbalance`.
Для узлов с сотнями native ingest-потоков он должен быть установлен, включён
и активен. На `sesamedvr-node1` включённый `irqbalance` не убрал все пики
ingest CPU, но улучшил ровность нагрузки после стабилизации, перераспределив
сетевые IRQ между CPU:

```bash
systemctl is-active irqbalance
systemctl is-enabled irqbalance
```

### Prometheus и Grafana

Sesame DVR отдаёт OpenMetrics snapshot для Prometheus по endpoint:

```text
GET /metrics
```

Endpoint предназначен для регулярного scrape со стороны Prometheus и содержит
пер-поточные метрики с labels `server_id` и `name`: `ts_delay`,
`stream_bitrate`, `stream_bytes_in`, `stream_input_retries`,
`stream_online_clients`, а также дополнительные `sesame_dvr_stream_*`
состояния и ingest counters. Метрика `play_bytes` оставлена для совместимости с
Flussonic-like dashboards; сейчас Sesame DVR не считает playback bytes и отдаёт
для неё `0`.

Авторизация `/metrics` задаётся отдельно от admin UI и playback tokens в
`Настройки -> Global config`:

- `Metrics auth mode`: `disabled`, `none`, `bearer` или `basic`;
- `Metrics bearer token`: write-only token для `Authorization: Bearer ...`;
- `Metrics basic username`;
- `Metrics basic password`: write-only пароль Basic auth.

Режим `disabled` полностью выключает выдачу метрик: `GET /metrics` отвечает
`404`. Режим `none` оставляет endpoint открытым без авторизации.

Raw secrets не сохраняются в `config.json`: после сохранения остаются только
hash-поля. Если поле token/password в UI оставить пустым, текущий сохранённый
секрет не меняется.

Пример Prometheus scrape config с Bearer token:

```yaml
scrape_configs:
  - job_name: sesame-dvr
    scheme: https
    metrics_path: /metrics
    bearer_token: <metrics-token>
    static_configs:
      - targets: ["dvr.example.com"]
```

Пример с Basic auth:

```yaml
scrape_configs:
  - job_name: sesame-dvr
    scheme: https
    metrics_path: /metrics
    basic_auth:
      username: prometheus
      password: <metrics-password>
    static_configs:
      - targets: ["dvr.example.com"]
```

Подробное руководство с API-примерами, PromQL и alert rules:
[prometheus-metrics-endpoint.ru.md](./prometheus-metrics-endpoint.ru.md).

### Журналы

В разделе `Журналы` доступен общий event log. В карточке потока есть отдельная
вкладка `Логи` с ingest/ffmpeg логами выбранной камеры.

Типичные источники проблем:

- `rtsp` или `connection refused` - камера недоступна или неправильный URL;
- `401/403` - неверный логин/пароль камеры или playback token;
- `archive stale` - архив не обновляется;
- `license` - проблема лицензии или lease;
- `storage` - volume offline, read-only, full или ошибка catalog.

## Лицензия и обновления

Раздел `Настройки -> Лицензия` показывает:

- статус лицензии;
- срок действия;
- лимиты потоков, retention и playback client users;
- fingerprint;
- lease status и срок действия lease;
- детали license/anchor.

Кнопка `Renew` принудительно обновляет lease с license server. Runtime обычно
обновляет lease сам по расписанию.

Раздел `Настройки -> Обновления` показывает:

- текущую версию сервера;
- доступную версию;
- статус последней проверки;
- состояние update launcher;
- лог сервиса обновления.

Кнопки:

- `Проверить` - запросить актуальную доступную версию;
- `Обновить` - запустить штатное обновление через `sesame-dvr-update.service`.

Во время обновления UI показывает журнал. На этапе рестарта сервиса страница
должна дождаться возврата API и затем показать новую версию или ошибку.

## Хранилище архива

Sesame DVR поддерживает два режима:

- `SingleVolume` - один архивный root;
- `MultiVolume` - несколько томов хранения.

При отсутствии блока `storage` система работает как `SingleVolume` с volume
`default` и root, равным `dvrRoot`.

### Основные каталоги на volume

Для каждого volume внутри root могут находиться:

- `segments` - архивные self-initializing fMP4 сегменты;
- `segments/<camera>/<YYYY>/<MM>/<DD>/<HH>/.hour_index*.hidx` - primary
  per-hour индекс сегментов для конкретного часа;
- `previews` - cache preview, а также per-hour preview рядом с segment shard;
- `timelapse` - готовые timelapse HLS/fMP4 chunks и manifest;
- `.sesame-dvr/camera_indexes/.../*.cidx` - производные индексы камеры;
- `.sesame-dvr/volume_write_state.term` - состояние cursor записи.

Runtime source of truth — Actual HIDX/CIDX/PIDX; `*.hidx`, `*.cidx` и `*.pidx`
являются их disk mirrors. Отдельного volume-level index file нет. Для admin
storage map сводка строится transient из списка настроенных камер и CIDX Actual.

### Индексы и материализация

Обновление индексов идёт внутри Actual:

1. Commit нового сегмента атомарно обновляет HIDX Actual и CIDX Actual.
2. HIDX materializer применяет HIDX Actual vs DiskMirror diff.
3. CIDX materializer независимо применяет CIDX Actual vs DiskMirror diff.

Такой pipeline отделяет запись сегмента от durable index IO.
На Dashboard в debug diagnostics можно смотреть pending/lag materializer-ов и
максимальное отставание materialization pipeline от физической записи сегмента.

Retention planner ставит устаревшие файлы и часовые директории в `DeleteQueue`.
Физическое удаление выполняется через общий IO pipeline; после удаления
индексы получают feedback/tombstones, чтобы уже удалённые часы не попадали в
планировщик повторно.

### Timelapse на диске

Raw fMP4 timelapse frames используются как staging, а не как playback-источник.
Когда накопилось достаточно кадров для одного ускоренного HLS chunk, фоновая
задача собирает chunk, атомарно кладёт его в timelapse storage, обновляет
metadata/HourIndex и manifest, а raw staging удаляет. Поэтому запрос
`/<camera>/timelapse.m3u8` читает готовый manifest и не должен каждый раз
обходить архив или строить playlist заново.

Новые segments пишутся на volume, выбранный текущей write policy. Если запись
на volume выключается, уже открытый segment завершается штатно, а следующие
segments переходят на другой writable volume. Сводка archive status в списке
потоков берётся из catalog summaries и не должна блокироваться медленными
проверками каждого каталога.

### Политики записи

- `single` - новые сегменты пишутся в выбранный основной том.
- `round_robin` - новые сегменты распределяются по writable volumes по очереди.
- `weighted_round_robin` - как round-robin, но с учётом `Weight`.
- `least_used` - выбирается volume с меньшим `usedPercent`, затем с большим
  `freeBytes`.

`Scope`:

- `camera` - отдельный cursor на каждую камеру. Это режим по умолчанию и
  лучший выбор, если нужно при отказе одного из `n` дисков потерять примерно
  `1/n` архива каждой камеры;
- `global` - один общий cursor на все камеры. Может ровнее распределять
  мгновенную write-нагрузку, но при отказе тома хуже соответствует цели
  равномерной потери архива каждой камеры.

`Fallback`:

- пустой - первый writable volume по порядку config;
- `least_used` - fallback выбирает наименее заполненный writable volume.

`Требовать mountpoint` включает более строгую проверку, что volume действительно
смонтирован. Это полезно на production-серверах с отдельными дисками, но может
мешать тестовым конфигурациям с обычными директориями.

### Поля volume

- `ID` - стабильный идентификатор volume. Используется в archive URLs
  `/dvr/v/<volume_id>/...`.
- `Root` - абсолютный путь к корню volume.
- `Weight` - вес для `weighted_round_robin`.
- `Max usage %` - порог заполнения, после которого volume считается full для
  записи.
- `Min free bytes` - минимальный запас свободного места.
- `Включён` - volume доступен как archive source.
- `Запись включена` - writer может писать новые сегменты на volume.

### Действия с volume

- `Изменить` - загрузить volume в форму редактирования.
- `Проверить` - audit catalog без записи.
- `Выключить` - выключить volume как online archive source и одновременно
  выключить запись.
- `Включить` - вернуть volume в работу. Запись после этого включается отдельной
  кнопкой `Включить запись`.
- `Выключить запись` - оставить volume читаемым, но запретить новые записи.
  Если есть активный segment writer, UI покажет ожидание завершения.
- `Включить запись` - разрешить новые записи на volume.
- `Пересобрать каталог` - пересканировать volume и восстановить indexes/catalog
  из segment files.
- `Удалить` - удалить volume только из config. Данные на диске не удаляются.

### Осиротевшие архивы

В `Настройки` есть блок `Осиротевшие архивы`. Он ищет архивы, preview и
служебные файлы, которые больше не относятся к текущим configured streams.

Используйте:

- `Посчитать размер` - безопасный scan без удаления;
- `Удалить архивы и превью` - удалить найденные orphan data.

Перед удалением убедитесь, что нужные камеры не были временно удалены из config
и что архив не нужен для восстановления.

## Глобальные настройки

В разделе `Настройки -> Global config` доступны:

- `Interface language` - язык UI;
- `DVR root` - корневой каталог архива для режима `SingleVolume`;
- `Ingest producer` - глобальный producer по умолчанию. В protected-установке
  должен оставаться `ffmpeg_nif`; внешний `ffmpeg` предназначен только для
  unprotected/dev-сборок и будет отклонён protected runtime.
- `Segment duration` - длительность сегмента архива, по умолчанию 4 секунды;
- `Live window` - число live-сегментов в окне HLS;
- `External WHEP base URL` - внешний base URL для WHEP `Location`, если DVR
  стоит за NAT/reverse proxy и внешний hostname/port отличается от внутреннего;
- `FFmpeg restart delay ms` - задержка перед перезапуском ingest;
- `Camera stop timeout ms` - таймаут остановки камеры;
- `Preview duration`, `Preview check interval ms`, `Preview max concurrency`,
  `Preview min interval ms`;
- `FFprobe max concurrency`;
- `ONVIF events poll interval default, sec`;
- `Cleanup interval minutes`;
- `Archive stale restart after seconds`;
- `Generate separate MP4 preview files`;
- `Also generate JPG frames`;
- `Metrics auth mode`, `Metrics bearer token`, `Metrics basic username`,
  `Metrics basic password` - отдельная авторизация Prometheus endpoint
  `/metrics`; режим `disabled` выключает endpoint;
- `Archive stale restart`.

Меняйте эти параметры аккуратно: они влияют на все камеры и runtime-нагрузку.

## Служебные команды

### Systemd

```bash
sudo systemctl status sesame-dvr --no-pager
sudo systemctl restart sesame-dvr
sudo systemctl stop sesame-dvr
sudo systemctl start sesame-dvr
```

Журнал сервиса:

```bash
sudo journalctl -u sesame-dvr -n 200 --no-pager
sudo journalctl -u sesame-dvr -f
```

Если включён отдельный ONVIF service:

```bash
sudo systemctl status sesame-dvr-onvif --no-pager
sudo journalctl -u sesame-dvr-onvif -n 200 --no-pager
```

### Лицензия

```bash
sudo sesame-dvr-license status
sudo sesame-dvr-license status --json
sudo sesame-dvr-license renew
sudo sesame-dvr-license fingerprint
sudo sesame-dvr-license fingerprint --raw
```

### Обновление и repair

```bash
sudo sesame-dvr-update
sudo sesame-dvr-update --preflight-only
sudo sesame-dvr-update --force
sudo sesame-dvr-repair
```

`sesame-dvr-repair` переустанавливает текущий build из artifact index и
обновляет matching anchor, если он требуется.
Если публичный доступ был настроен через `--publish-service`, обычный
`sesame-dvr-update` автоматически обновляет installer-managed nginx site и
проверяет публичный HLS/DVR segment в smoke.

### Проверка storage

```bash
sudo sesame-dvr-storage smoke
sudo sesame-dvr-storage smoke --json
```

### Support diagnostics

```bash
sudo sesame-dvr-support-diagnostics
sudo sesame-dvr-support-diagnostics --with-onvif-service
```

Команда создаёт sanitized bundle. В bundle не должны попадать raw
`license.json`, `license-lease.json`, `config.json`, private keys и archive
data.

## Файлы и каталоги

Типовая protected-установка:

- `/opt/sesame-dvr/current` - текущий release;
- `/opt/sesame-dvr/releases/<build>` - установленные releases;
- `/var/lib/sesame-dvr/config.json` - конфигурация сервера;
- `/var/lib/sesame-dvr/sesame-dvr.env` - environment и tokens;
- `/var/lib/sesame-dvr/license.json` - лицензия;
- `/var/lib/sesame-dvr/license-lease.json` - online lease;
- `/var/lib/sesame-dvr/current_anchor.so` - текущий per-instance anchor;
- `/var/dvr/segments` - видеоархив default SingleVolume layout;
- `/var/dvr/previews` - preview cache;
- `/var/dvr/onvif-events` - ONVIF events;
- `/var/dvr/tmp` - временные файлы installer/update/debug;
- `/etc/systemd/system/sesame-dvr.service` - unit сервиса;
- `/etc/nginx/sites-available/sesame-dvr.conf` - nginx site при публикации.

В MultiVolume режиме архивные данные могут находиться на нескольких volume
root. URL содержит `volume_id`, поэтому после переноса volume сервер может
использовать новый путь, если catalog/audit видит данные.

## Безопасность

Рекомендации:

- сразу сменить стандартный пароль `admin / admin`;
- хранить activation keys и management token как секреты;
- использовать HTTPS для публичного доступа;
- не публиковать `/admin` без пароля и token;
- для внешнего playback использовать `authMode=static` или `auth backend`;
- не хранить RTSP-пароли в публичных документах и скриншотах;
- ограничить SSH-доступ к серверу;
- регулярно обновлять Sesame DVR и ОС;
- перед отправкой diagnostics использовать только sanitized support bundle.

Playback token для `authMode=static` передаётся так:

```text
https://dvr.example.com/cam1/live.m3u8?token=<token>
```

## Типовые сценарии

### Добавить RTSP-камеру

1. Откройте `Потоки`.
2. Нажмите `Новый поток`.
3. Укажите `Имя`, `Source`, `Retention`.
4. Включите `Enabled`.
5. Нажмите `Сохранить`.
6. Нажмите `Вкл` или `Старт`.
7. Проверьте live в embed-плеере.
8. Через несколько секунд проверьте timeline архива.

### Добавить ONVIF-события к камере

1. Откройте `ONVIF`.
2. Нажмите `Новое устройство` или `Сканировать RTSP`.
3. Укажите host, port, path, username/password.
4. Нажмите `Проверить`.
5. Нажмите `Проверить возможности`.
6. Нажмите `Подписаться` или `Запустить события`.
7. Проверьте появление events на вкладке устройства и на timeline.

Если камера уже заведена как RTSP-поток, можно быстрее: откройте поток в
`Потоки`, нажмите `ONVIF камера`, дождитесь автопоиска или проверьте
предзаполненную форму, затем сохраните устройство.

### Перейти на MultiVolume

1. Подключите новый диск и создайте root directory.
2. В `Настройки -> Тома хранения` выберите `MultiVolume`.
3. Добавьте volume с уникальным `ID` и абсолютным `Root`.
4. Убедитесь, что volume online и доступен для записи.
5. Выберите policy, например `round_robin` или `weighted_round_robin`.
6. Выберите `Scope = сегменты камеры`, если цель - распределять segments каждой
   камеры по всем writable volumes. `Scope = камеры по дискам` закрепляет камеры
   за томами и меньше размазывает архив одной камеры по всем дискам.
7. Нажмите `Сохранить хранилище`.
8. Запустите `Проверить` или `Пересобрать каталог` для перенесённых данных.
9. Проверьте, что новые сегменты появляются на нужных volumes.

### Вывести volume из записи

1. Откройте `Настройки -> Тома хранения`.
2. На нужном volume нажмите `Выключить запись`.
3. Дождитесь, пока активные записи завершатся.
4. Убедитесь, что новые сегменты пишутся на другие writable volumes.
5. После этого volume можно отключать, обслуживать или копировать.

### Обновить сервер

1. Откройте `Настройки -> Обновления`.
2. Нажмите `Проверить`.
3. Если доступна новая версия, нажмите `Обновить`.
4. Следите за логом обновления.
5. После рестарта проверьте версию сервера, license status и состояние потоков.

CLI-вариант:

```bash
sudo sesame-dvr-update
```

### Собрать diagnostics для поддержки

```bash
sudo sesame-dvr-support-diagnostics --with-onvif-service
```

Отправляйте полученный `.tar.gz` bundle. Не отправляйте raw `config.json`,
`license.json`, private keys или архивные видеофайлы без отдельной договорённости.

## Диагностика проблем

### Web UI не открывается

Проверьте:

```bash
sudo systemctl status sesame-dvr --no-pager
sudo systemctl status nginx --no-pager
sudo nginx -t
curl -sS http://127.0.0.1:3000/api/system/status
```

Также проверьте DNS, firewall и открытые порты `80/443`.

### Камера не пишет архив

Проверьте:

- поток включён и запущен;
- RTSP URL доступен с сервера;
- лог потока на вкладке `Логи`;
- `Ffprobe инфо`;
- лимиты лицензии по количеству потоков;
- свободное место и состояние storage volume;
- нет ли `archive stale` в журнале;
- не выключена ли запись на volume.

### Live есть, архив не появляется

Возможные причины:

- камера не отдаёт keyframes достаточно часто;
- ingest получает поток, но writer не может писать на диск;
- volume offline/read-only/full;
- проблема с правами на каталог archive root;
- catalog/index требует rebuild после ручного переноса файлов.

Действия:

```bash
sudo sesame-dvr-storage smoke
sudo journalctl -u sesame-dvr -n 200 --no-pager
```

В UI запустите `Проверить` или `Пересобрать каталог` на нужном volume.

### WebRTC не работает

Проверьте:

- codec камеры поддерживается браузером;
- WebRTC статус в карточке потока;
- нет ли ошибок license capabilities;
- работает ли HLS fallback;
- не блокирует ли proxy нужный WHEP endpoint.

Для HEVC в браузере возможны ограничения ОС/браузера. В таких случаях HLS может
работать, а WebRTC нет.

### HLS показывает `The element has no supported sources`

Проверьте:

- playlist открывается по URL;
- segment URLs из playlist возвращают `200 OK`, а не `404`;
- nginx alias `/dvr/` настроен на актуальный archive root;
- для MultiVolume доступен endpoint `/dvr/v/<volume_id>/...`;
- файлы сегментов реально существуют на volume;
- catalog после переноса данных пересобран.

### ONVIF не работает

Проверьте:

- правильный host и port ONVIF;
- path `/onvif/device_service` или vendor-specific path;
- логин/пароль камеры;
- доступность камеры с сервера;
- capabilities check;
- PullPoint events поддерживаются конкретной камерой.

Некоторые камеры отдают в ONVIF XAddr с недоступным адресом. В таком случае
сервер должен использовать фактический host/port устройства, если fallback
поддержан текущей версией.

### Лицензия invalid или lease не обновляется

Проверьте:

```bash
sudo sesame-dvr-license status
sudo sesame-dvr-license renew
sudo journalctl -u sesame-dvr -n 200 --no-pager | grep -i license
```

Также проверьте исходящий HTTPS к:

```text
https://license.sesameware.com
https://license-2.sesameware.com
```

### Обновление не прошло

Проверьте лог в UI или:

```bash
sudo systemctl status sesame-dvr-update --no-pager
sudo journalctl -u sesame-dvr-update -n 300 --no-pager
```

Для проверки без переключения версии:

```bash
sudo sesame-dvr-update --preflight-only
```

Для переустановки текущего build:

```bash
sudo sesame-dvr-repair
```

### Диск заполнился

Проверьте:

- `Настройки -> Тома хранения`;
- `Мониторинг -> Диски`;
- retention по камерам;
- orphan archives;
- не выключен ли cleaner;
- нет ли offline volume с pending retention cleanup.

Если volume full, writer исключает его из новых записей. После освобождения
места может потребоваться `Проверить` или `Пересобрать каталог`.

## HTTP endpoints для интеграций

Полный справочник всех HTTP API методов, параметров и payloads:
[sesame-dvr-api.ru.md](./sesame-dvr-api.ru.md).

Основные playback endpoints:

```text
GET /<camera>/embed.html
GET /<camera>/live.m3u8
GET /<camera>/dvr.m3u8?start=<unix>&end=<unix>
GET /<camera>/dvr.m3u8?start=<unix>&end=<unix>&sliding=1
GET /<camera>/motion_dvr.m3u8?start=<unix>&end=<unix>
GET /<camera>/motion_dvr_map.json?start=<unix>&end=<unix>
GET /<camera>/timelapse.m3u8
GET /<camera>/timelapse.m3u8?start=<unix>&end=<unix>
GET /<camera>/recording_status.json
GET /<camera>/timeline_ranges.json
GET /<camera>/motion_events.json
GET /<camera>/preview.mp4
GET /<camera>/preview.jpg
GET /<camera>/archive-<from_unix>-<duration_seconds>.mp4
POST /<camera>/whep/
DELETE /<camera>/whep/<session_id>
```

`/<camera>/motion_events.json` принимает `from`/`to` в Unix seconds и отдаёт только запрошенный кусок событий движения. Embed-плеер при первом открытии показывает окно 12 часов вокруг live-точки и загружает события только для видимой части шкалы, чтобы не читать всю ONVIF-историю.

`/<camera>/recording_status.json` отдаёт совместимые с Flussonic диапазоны
архива; в каждом range поля `from` и `duration` отдаются целыми Unix seconds.
Для потоков с выключенным `Писать архив` этот endpoint возвращает пустой список
ranges и `archiveEnabled=false`.

`/<camera>/timeline_ranges.json` используется embed-плеером для шкалы архива.
Он принимает `from`/`to` в Unix seconds и `bucket` в секундах, возвращает только
нужное окно ranges и при большом масштабе может агрегировать соседние диапазоны.
Для live-only потоков возвращает пустой список ranges и `archiveEnabled=false`.
Внешним интеграциям нужно продолжать использовать `recording_status.json`.

Обычный `/<camera>/dvr.m3u8?start=...&end=...` отдаёт конечный VOD playlist.
Растущий archive playlist включается только явно через `sliding=1` или через
embed-параметр `archive_playlist=sliding`; старые интеграции без этих
параметров не меняют поведение.

`/<camera>/motion_dvr.m3u8?start=...&end=...` отдаёт archive HLS только по
motion=true интервалам, а `/<camera>/motion_dvr_map.json` отдаёт карту
соответствия media time и реального archive time. Эти endpoints нужны
embed-плееру для режима просмотра по событиям: видео идёт непрерывно по
сегментам с движением, а UI timeline остаётся привязан к настоящему времени
архива.

`/<camera>/timelapse.m3u8` отдаёт HLS VOD из всех сохранённых отдельных
timelapse chunks, если для потока включена запись timelapse. Если передать
`start=...&end=...`, playlist ограничивается этим реальным окном архива.
Playback path читает материализованный manifest; сборка chunks и обновление
manifest выполняются фоновыми задачами при записи timelapse. Файлы хранятся под
`timelapse/<camera>/YYYY/MM/DD/HH/` на storage volume и чистятся по отдельной
настройке глубины хранения timelapse.

Для административного API `GET /api/onvif/devices/:id/events` параметры `page`/`pageSize` и `before`/`after` загружают список кусками. `timelineFrom`/`timelineTo` задают отдельный кусок `timelineEvents`, `timelineCarry=true` добавляет последнее событие перед началом окна для восстановления состояния, а `events=false` отключает выдачу страницы списка и используется для лёгких timeline-only запросов.

Прямые archive/data endpoints:

```text
GET /dvr/<camera>/...
GET /dvr/v/<volume_id>/<camera>/...
```

Ниже приведена не исчерпывающая выдержка часто используемых административных
endpoints. Большинство методов `/api/...` требует management token или активную
admin session; точные требования авторизации и полный список методов приведены
в [API-справочнике](./sesame-dvr-api.ru.md).

```text
GET /api/i18n
GET /api/system/status
GET /api/system/features
GET /api/system/version
GET /api/system/license
POST /api/system/license/renew
GET /api/system/update/status
POST /api/system/update/check
POST /api/system/update/start
GET /api/system/update/hot-patch/status
POST /api/system/update/hot-patch/start
POST /api/system/update/rollback
GET /api/config
GET /api/config/multi-bitrate-streams
PATCH /api/config
GET /api/streams
POST /api/streams
PUT /api/streams/<name>
PUT /api/streams/<name>/static-image
PUT /api/streams/<name>/static-video
DELETE /api/streams/<name>
GET /api/push-ingest/sessions
GET /api/agents
GET /api/agents/pairing-invitations
POST /api/agents/pairing-invitations
GET /api/agents/pairing-invitations/<invitation_id>
DELETE /api/agents/pairing-invitations/<invitation_id>
GET /api/agents/<id>
PATCH /api/agents/<id>
DELETE /api/agents/<id>
POST /api/agents/<id>/revoke
GET /api/agents/<id>/streams
GET /api/agents/<id>/streams/<stream_id>/snapshot.jpg
GET /api/agents/<id>/streams/<stream_id>/onvif/events
GET /api/agents/<id>/bindings
GET /api/agents/<id>/bindings/<stream_id>
PUT /api/agents/<id>/bindings/<stream_id>
DELETE /api/agents/<id>/bindings/<stream_id>
PUT /api/agents/<id>/management-credential
DELETE /api/agents/<id>/management-credential
POST /api/agents/<id>/management-auth
POST /api/agents/<id>/diagnostics
GET /api/agents/<id>/logs
GET /api/agents/<id>/commands
POST /api/agents/<id>/commands
POST /api/agent/v2/pair
GET /agent/v2/connect
GET /streamer/api/v3/config/stats
GET /streamer/api/v3/streams
GET /streamer/api/v3/streams/<name>
PUT /streamer/api/v3/streams/<name>
POST /streamer/api/v3/streams/<name>/dvr/export
GET /metrics
GET /api/storage/volumes
GET /api/storage/catalog-jobs/<job_id>
POST /api/storage/volumes/<volume_id>/audit
POST /api/storage/volumes/<volume_id>/rebuild-catalog
POST /api/storage/volumes/<volume_id>/enable
POST /api/storage/volumes/<volume_id>/disable
POST /api/storage/volumes/<volume_id>/writable-on
POST /api/storage/volumes/<volume_id>/writable-off
POST /api/storage/volumes/<volume_id>/drain
GET /api/onvif/devices
POST /api/onvif/devices
POST /api/onvif/devices/scan
POST /api/onvif/devices/scan-stream
```

Совместимый с Flussonic слой доступен по префиксам `/streamer/api/v3/...` и
`/flussonic/api/v3/...`; оба набора aliases используют тот же management token.
Реализованы `config/stats`, список и карточка потоков, обновление потока через
`PUT` и запуск DVR export. Для заголовка авторизации принимаются варианты
`Authorization: Bearer <token>`, `Authorization: <token>` и
`X-Management-Token: <token>`. `PUT /streamer/api/v3/streams/<name>` принимает
Flussonic body, где `inputs[0].url` становится `source`, `disabled` становится
обратным значением `enabled`, `dvr.expiration` в секундах превращается в
`retentionDays`, а `on_play.url` задаёт глобальный `authBackendUrl` и
`authMode=authBackend` для потока. Удаление в этом слое выполняется только через
`PUT` с `disabled=true`; Flussonic `DELETE` намеренно не используется.

Endpoints `POST /api/push-ingest/rtmp/publish`,
`POST /api/push-ingest/rtmp/update` и `POST /api/push-ingest/rtmp/done`
используются управляемым RTMP/SRT frontend для регистрации lifecycle внешнего
publisher. Обычным playback-клиентам вызывать их не требуется.

`stats.bytes_in` в совместимом API берётся из native ingest счётчика входных
байтов. Значение становится `0`, если поток остановлен или за последние 30
секунд не было входных пакетов, поэтому фильтр
`stats.alive=false&stats.running=true&stats.bytes_in=0` ищет запущенные потоки
без текущего входного трафика от камеры. Накопленный счётчик текущего процесса
доступен как `stats.bytes_in_total`.

Для playback с `authMode=static` добавляйте query token:

```text
?token=<playback-token>
```

Для production-интеграций лучше использовать documented UI/API flows и
сохранять совместимость URL камеры, `volume_id` и management token.
