# Настройка DVR-хранилища

Этот документ описывает текущую схему хранения архива SesameDVR после перехода
на `storage` volumes, self-initializing fMP4 segments, `*.hidx` hour indexes и
`*.cidx` camera indexes.

## Что хранится на volume

Каждый volume содержит только DVR archive data и preview cache:

- `segments/<camera>/<YYYY>/<MM>/<DD>/<HH>/*.fmp4` - самодостаточные fMP4
  segment files;
- `segments/<camera>/<YYYY>/<MM>/<DD>/<HH>/.hour_index*.hidx` - primary индекс
  одного часа. Старые `.hour_index*.term` читаются как legacy fallback во время
  runtime-миграции;
- `previews/<camera>/...` - cache preview рядом с исходным segment shard;
- `timelapse/<camera>/...` - готовые timelapse HLS/fMP4 chunks и manifest;
- `.sesame-dvr/camera_indexes/.../*.cidx` - производный индекс камеры;
- `.sesame-dvr/volume_index*` - производный индекс volume;
- `.sesame-dvr/volume_write_state.term` - курсоры политики записи.

Новые segments сначала атомарно попадают в hour folder и обновляют primary
`*.hidx` индекс. Затем изменения асинхронно проходят через materializer
pipeline: `HourIndex` -> `CameraIndex` -> `VolumeIndex`. Производные индексы
ускоряют status, timeline и retention planner, но могут быть восстановлены из
`HourIndex`.

Legacy `global_segment_catalog.term` и `.hour_index*.term` остаются читаемыми
для совместимости со старыми installations и repair/migration paths, но не
являются основной моделью записи новых индексов.

ONVIF events и config остаются централизованными в `dvrRoot`, потому что их
проще бэкапить отдельно.

## Публичные URL архива

Default SingleVolume archive отдаётся по legacy URL:

```text
/dvr/<camera>/...
```

MultiVolume archive отдаётся с явным `volume_id`:

```text
/dvr/v/<volume_id>/<camera>/...
```

Если публичная публикация настроена installer'ом через `--publish-service`,
managed nginx site отдаёт оба варианта напрямую с диска. HLS playlist может
публиковать archive segments как `.mp4` URL для совместимости с плеерами, но на
диске остаются реальные `.fmp4`; managed nginx должен мапить такие `.mp4`/`.m4s`
aliases обратно на соответствующий `.fmp4` файл. Штатный `sesame-dvr-update`
обновляет этот nginx-конфиг вместе с runtime, поэтому новые storage location'ы
не нужно добавлять вручную.

## Режимы

`SingleVolume`:

- используется один основной volume;
- write policy обычно `single`;
- подходит для обычной установки и для обратного слияния MultiVolume в один
  каталог.

`MultiVolume`:

- сервер читает архив со всех online volumes;
- новые segments пишутся по выбранной write policy;
- потеря одного volume превращается в gaps только на его диапазонах, а не
  ломает весь архив;
- offline/read-only/full volumes исключаются из writer path.

Legacy config без блока `storage` автоматически загружается как `SingleVolume`
с volume `default`, root равным `dvrRoot`.

## Политики записи

`single` пишет в выбранный `volumeId`. Если выбранный volume стал недоступен,
writer берёт первый доступный fallback volume и пишет warning в журнал.

`round_robin` распределяет новые segments по writable volumes по очереди.

`weighted_round_robin` делает то же самое, но учитывает `weight` volume.

`least_used` выбирает volume с меньшим `usedPercent`, затем с большим
`freeBytes`.

`scope=camera` закрепляет камеры за writable volumes по хэшу имени камеры. Это
режим по умолчанию: он распределяет камеры по томам и уменьшает разброс архива
одной камеры по всем дискам.

`scope=camera_segment` ведёт отдельный cursor на каждую камеру и распределяет
segments каждой камеры по writable volumes по очереди.

`scope=global` означает один общий cursor policy для всех камер. Он может чуть
ровнее размазывать мгновенную write-нагрузку по дискам, но хуже соответствует
сценарию равномерной потери архива каждой камеры при отказе тома.

`fallback=least_used` просит fallback выбирать наименее заполненный volume.
Пустой fallback означает первый writable volume в порядке config.

## Настройка через UI

В админ-панели SesameDVR откройте `Настройки -> Тома хранения`.

В верхней форме задаются:

- `Режим`: `SingleVolume` или `MultiVolume`;
- `Политика записи`;
- `Scope`;
- `Основной том`;
- `Fallback`;
- `Требовать mountpoint`.

Кнопка `Сохранить хранилище` сохраняет блок `storage` в config через
`PATCH /api/config`.

В правой форме редактируется выбранный volume:

- `ID` - стабильный идентификатор volume, используется в archive URLs
  `/dvr/v/<volume_id>/...`;
- `Root` - абсолютный путь к корню volume;
- `Weight`;
- `Max usage %`;
- `Min free bytes`;
- `включён`;
- `запись включена`.

Кнопка `Изменить` в карточке volume загружает volume в форму. Кнопка `Новый`
очищает форму для добавления нового volume. Кнопка `Удалить` удаляет volume
только из config; данные на диске не удаляются.

## Операции с volume

`Проверить` запускает audit volume catalog без записи.

`Пересобрать каталог` пересканирует volume, восстанавливает hour indexes/catalog
из segment files и записывает результат на volume.

Для ускорения пересборка выполняется параллельно по независимым камерам.
Auto default использует примерно четверть доступных BEAM scheduler'ов и не
больше `32` воркеров; job запускается с низким приоритетом BEAM. На больших
инстансах лимит можно переопределить через
`SESAME_DVR_CATALOG_REBUILD_CONCURRENCY`.

`Выключить` оставляет данные доступными в config history, но volume перестаёт
использоваться как online archive source. Эта операция также выключает запись
на volume.

`Выключить запись` оставляет volume читаемым, но writer больше не пишет на него.
Если на volume ещё есть активные записи, UI покажет ожидание завершения; уже
открытый segment не удаляется.

## Ручная настройка config

Пример MultiVolume:

```json
{
  "storage": {
    "mode": "multi_volume",
    "requireMountpoints": true,
    "volumes": [
      {
        "id": "default",
        "root": "/var/dvr",
        "enabled": true,
        "writable": true,
        "weight": 1
      },
      {
        "id": "archive-b",
        "root": "/mnt/archive-b/sesame-dvr",
        "enabled": true,
        "writable": true,
        "weight": 1,
        "maxUsagePercent": 92
      }
    ],
    "writePolicy": {
      "type": "round_robin",
      "scope": "camera",
      "volumeId": "default",
      "fallback": "least_used"
    }
  }
}
```

После ручного изменения config перезапустите сервис или выполните reload через
штатные механизмы установки/обновления.

## Проверка

На protected release используйте:

```bash
sudo sesame-dvr-storage smoke
```

Audit/rebuild для protected installation запускайте из UI: `Проверить` и
`Пересобрать каталог` на нужной карточке volume.

В dev checkout доступны mix tasks:

```bash
mix sesame_dvr.storage smoke
mix sesame_dvr.storage audit
mix sesame_dvr.storage rebuild-hour-indexes
mix sesame_dvr.storage rebuild-catalog
mix sesame_dvr.storage reverse-merge-report
mix sesame_dvr.storage reverse-merge
```

## Переносы и rollback

SingleVolume -> MultiVolume:

1. Подключите новый диск и создайте root directory.
2. Добавьте volume в UI.
3. Убедитесь, что volume online/writable.
4. Выберите write policy.
5. Запустите audit/rebuild catalog для новых или перенесённых данных.

MultiVolume -> SingleVolume:

1. Переведите лишние volumes в `Выключить запись`.
2. Остановите writer или выполните операцию в maintenance window.
3. Скопируйте `segments` и `previews` из всех volumes в один target volume.
4. Запустите `reverse-merge-report`.
5. Если конфликтов нет, выполните `reverse-merge` или rebuild catalog target
   volume.
6. Переключите режим на `SingleVolume`, policy `single`.

При переносе каталогов между volumes сервер ориентируется на segment files и
hour indexes. URLs генерируются на лету, поэтому после rebuild catalog сервер
будет отдавать архив с нового пути.

## Offline behavior

Если volume offline:

- status ranges показывают только online ranges;
- playback показывает gap;
- writer пропускает этот volume;
- retention помечает его cleanup как `retention_pending`;
- после возврата volume catalog можно пересобрать через UI.

Если volume read-only или full, он остаётся читаемым, но исключается из записи.
