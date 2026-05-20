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
9. [Клиенты](#клиенты)
10. [Мониторинг и журналы](#мониторинг-и-журналы)
11. [Лицензия и обновления](#лицензия-и-обновления)
12. [Хранилище архива](#хранилище-архива)
13. [Глобальные настройки](#глобальные-настройки)
14. [Служебные команды](#служебные-команды)
15. [Файлы и каталоги](#файлы-и-каталоги)
16. [Безопасность](#безопасность)
17. [Типовые сценарии](#типовые-сценарии)
18. [Диагностика проблем](#диагностика-проблем)
19. [HTTP endpoints для интеграций](#http-endpoints-для-интеграций)

## Назначение Sesame DVR

Sesame DVR - серверная система видеозаписи и просмотра IP-камер. Сервер
принимает RTSP/URL-потоки, пишет архив на диск, отдаёт live-видео, архивные HLS
плейлисты, preview, MP4 export и предоставляет web-интерфейс администратора.

Основные задачи:

- подключение RTSP-камер и других видеоисточников;
- запись архива с настраиваемым сроком хранения;
- live-просмотр через embed-плеер, HLS и WebRTC/WHEP;
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
      --server-name "$(hostname -f)" \
      --publish-service \
      --publish-server-name dvr.example.com
```

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
- `Настройки` - лицензия, обновления, хранилище, tokens, orphan cleanup и
  global config.

## Потоки и камеры

Поток - это камера или другой источник видео. Основные поля потока:

- `Имя` - стабильный идентификатор камеры в URL и архиве. Используйте латиницу,
  цифры, дефис или подчёркивание.
- `Source` - RTSP/URL источника, например
  `rtsp://user:password@10.0.0.10:554/stream1`.
- `Retention` - срок хранения архива. Можно задавать числа в днях или строки
  `7d`, `6h`, `180m`.
- `Authorization`:
  - `none` - playback endpoints доступны без token;
  - `static` - playback требует query `?token=<token>`;
  - `auth backend` - доступ проверяется внешним backend URL из global config.
- `Disable audio` - отключает аудио в ingest/playback для потока.
- `Enabled` - поток включён в config и должен запускаться после старта сервиса.

В protected-версии producer не выбирается на уровне отдельного потока:
используется global/default `ffmpeg_nif`. Он пишет self-initializing fMP4
segments напрямую в выбранный storage volume. Внешний `ffmpeg` producer
доступен только в unprotected/dev-сборках и будет отклонён protected runtime.

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

### Статусы потока

В карточке и заголовке выбранного потока отображаются:

- runtime state: запущен/остановлен/ошибка;
- ingest producer;
- codec и audio codec;
- готовность WebRTC;
- статус ONVIF;
- состояние архива и задержка последнего сегмента;
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
- управление масштабом timeline;
- переход к выбранному времени;
- выгрузку MP4-фрагмента.

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
пересоздать из сегментов.

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
может задавать лимит `maxClientUsers`; для старых лицензий также учитывается
legacy поле `maxClientConnections`.

## Мониторинг и журналы

### Мониторинг

Раздел `Мониторинг` показывает:

- CPU всего и CPU Sesame DVR;
- отдельную нагрузку ingest, ONVIF, nginx и BEAM runtime;
- память процесса и системы;
- диски и storage volumes;
- сеть;
- ingest-процессы;
- BEAM profile и top runtime consumers.

Если CPU вырос, сначала проверьте:

- сколько камер реально пишут архив;
- не запущены ли массовые playback/API запросы;
- нет ли частых ошибок в логах;
- не идёт ли rebuild/audit catalog;
- не перегружен ли диск.

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

Legacy config без блока `storage` автоматически работает как `SingleVolume` с
volume `default`, root равным `dvrRoot`.

### Основные каталоги на volume

Для каждого volume внутри root могут находиться:

- `segments` - архивные self-initializing fMP4 сегменты;
- `segments/<camera>/<YYYY>/<MM>/<DD>/<HH>/.hour_index*.term` - per-hour
  индексы. Несколько таких файлов в одной часовой папке читаются вместе и
  мержатся, что позволяет копировать `segments` между дисками без перезаписи
  index-файла;
- `previews` - cache preview, а также per-hour preview рядом с segment shard;
- `.sesame-dvr/global_segment_catalog.term` - catalog volume;
- `.sesame-dvr/volume_write_state.term` - состояние cursor записи.

Источником истины остаются segment files. Catalog и indexes ускоряют работу и
могут быть восстановлены пересканированием.

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
- `DVR root` - корневой каталог архива для legacy/single-volume режима;
- `Ingest producer` - глобальный producer по умолчанию. В protected-установке
  должен оставаться `ffmpeg_nif`; внешний `ffmpeg` предназначен только для
  unprotected/dev-сборок и будет отклонён protected runtime.
- `Segment duration` - длительность сегмента архива, по умолчанию 4 секунды;
- `Live window` - число live-сегментов в окне HLS;
- `FFmpeg restart delay ms` - задержка перед перезапуском ingest;
- `Camera stop timeout ms` - таймаут остановки камеры;
- `Preview duration`, `Preview check interval ms`, `Preview max concurrency`,
  `Preview min interval ms`;
- `FFprobe max concurrency`;
- `ONVIF events poll interval default, sec`;
- `Cleanup interval minutes`;
- `Archive stale restart after seconds`;
- `Generate MP4 previews`;
- `Also generate JPG frames`;
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
- `/var/dvr/segments` - видеоархив legacy/default layout;
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
6. Оставьте `Scope = по камерам`, если цель - равномерная потеря `1/n`
   архива каждой камеры при отказе одного диска.
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

Основные playback endpoints:

```text
GET /<camera>/embed.html
GET /<camera>/live.m3u8
GET /<camera>/dvr.m3u8?start=<unix>&end=<unix>
GET /<camera>/recording_status.json
GET /<camera>/timeline_ranges.json
GET /<camera>/motion_events.json
GET /<camera>/preview.mp4
GET /<camera>/preview.jpg
POST /<camera>/whep/
DELETE /<camera>/whep/<session_id>
```

`/<camera>/motion_events.json` принимает `from`/`to` в Unix seconds и отдаёт только запрошенный кусок событий движения. Embed-плеер при первом открытии показывает окно 12 часов вокруг live-точки и загружает события только для видимой части шкалы, чтобы не читать всю ONVIF-историю.

`/<camera>/recording_status.json` отдаёт совместимые с Flussonic диапазоны
архива; в каждом range поля `from` и `duration` отдаются целыми Unix seconds.

`/<camera>/timeline_ranges.json` используется embed-плеером для шкалы архива.
Он принимает `from`/`to` в Unix seconds и `bucket` в секундах, возвращает только
нужное окно ranges и при большом масштабе может агрегировать соседние диапазоны.
Внешним интеграциям нужно продолжать использовать `recording_status.json`.

Для административного API `GET /api/onvif/devices/:id/events` параметры `page`/`pageSize` и `before`/`after` загружают список кусками. `timelineFrom`/`timelineTo` задают отдельный кусок `timelineEvents`, `timelineCarry=true` добавляет последнее событие перед началом окна для восстановления состояния, а `events=false` отключает выдачу страницы списка и используется для лёгких timeline-only запросов.

Archive/data endpoints:

```text
GET /dvr/<camera>/...
GET /dvr/v/<volume_id>/<camera>/...
GET /<camera>/archive-<from_unix>-<duration_seconds>.mp4
```

Management API используется admin UI и требует management token/session:

```text
GET /api/system/status
GET /api/system/version
GET /api/system/license
POST /api/system/license/renew
GET /api/system/update/status
POST /api/system/update/check
POST /api/system/update/start
GET /api/streams
POST /api/streams
PUT /api/streams/<name>
DELETE /api/streams/<name>
GET /streamer/api/v3/streams
GET /streamer/api/v3/streams/<name>
PUT /streamer/api/v3/streams/<name>
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

Совместимый с Flussonic слой `/streamer/api/v3/streams` использует тот же
management token. Для заголовка авторизации принимаются варианты
`Authorization: Bearer <token>`, `Authorization: <token>` и
`X-Management-Token: <token>`. `PUT /streamer/api/v3/streams/<name>` принимает
Flussonic body, где `inputs[0].url` становится `source`, `disabled` становится
обратным значением `enabled`, `dvr.expiration` в секундах превращается в
`retentionDays`, а `on_play.url` задаёт глобальный `authBackendUrl` и
`authMode=authBackend` для потока. Удаление в этом слое выполняется только через
`PUT` с `disabled=true`; Flussonic `DELETE` намеренно не используется.

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
