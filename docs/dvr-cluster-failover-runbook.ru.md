# Runbook: DVR-side failover SesameDVR

Этот документ описывает эксплуатационные операции для stream-level failover в
SesameDVR. Portal в этих процедурах не участвует: Backup сам принимает решение
по health Master, Master сам выполняет repair после восстановления.

## Переменные примеров

```bash
export MASTER_URL="https://dvr-master.example:8443"
export BACKUP_URL="https://dvr-backup.example:8443"
export MASTER_STREAM="cam1"
export BACKUP_STREAM="cam1-backup"
export MASTER_MANAGEMENT_TOKEN="..."
export BACKUP_MANAGEMENT_TOKEN="..."
export MASTER_PLAYBACK_TOKEN="..."
export BACKUP_PLAYBACK_TOKEN="..."
```

`MASTER_PLAYBACK_TOKEN` и `BACKUP_PLAYBACK_TOKEN` - обычные playback tokens
соответствующих потоков. Они используются в `failover.peerUrl` и подходят для
read-side failover endpoints (`health`, `segments`) так же, как для обычных
playback/archive URL. Control endpoints требуют management/failover auth.

## Проверка связки перед тестом

Проверьте, что Backup видит Master как здоровый поток:

```bash
curl -fsS \
  "$MASTER_URL/api/failover/streams/$MASTER_STREAM/health?token=$MASTER_PLAYBACK_TOKEN" \
  | jq
```

Ожидаемые признаки:

- `recordingState` равно `recording`;
- `liveState` равно `online`;
- `archiveWritable` и `storageWritable` равны `true`;
- `lastSegmentAgeSec` меньше `masterFreshSegmentMaxAgeSec` из настроек Backup.

Проверьте настройки Backup-потока:

```bash
curl -fsS \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  "$BACKUP_URL/api/streams/$BACKUP_STREAM" \
  | jq '.failover'
```

Для standby без hot-buffer ожидается `mode=backup`, `enabled=false`,
`hotBuffer.enabled=false`, корректный `peerUrl` на Master с playback token.

## Как временно отключить Master

Для проверки failover лучше сначала использовать runtime-only остановку. Она не
меняет сохранённую настройку `enabled`, и Master можно быстро вернуть обратно.

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $MASTER_MANAGEMENT_TOKEN" \
  "$MASTER_URL/api/streams/$MASTER_STREAM/stop" \
  | jq
```

Backup должен запустить запись после `failureThreshold` подряд неуспешных
health-check. С текущими default `pollIntervalSec=10` и `failureThreshold=3`
практический target обнаружения отказа - около 30-45 секунд с учётом jitter и
network timeout.

Проверить состояние Backup:

```bash
curl -fsS \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  "$BACKUP_URL/api/streams/$BACKUP_STREAM" \
  | jq '.running, .runtimeDesired, .runtimeOverride, .failover.triggerState'
```

Ожидаемо:

- `.running` становится `true`, когда runtime действительно поднялся;
- `.runtimeDesired` становится `true`;
- `.runtimeOverride` становится `"started"`;
- `.failover.triggerState.state` становится `recording_due_to_failover`.

Если нужно запустить Backup вручную, используйте control endpoint на Backup:

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  "$BACKUP_URL/api/failover/streams/$BACKUP_STREAM/backup/start" \
  | jq
```

## Как вернуть Master и остановить Backup

Запустите Master runtime обратно:

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $MASTER_MANAGEMENT_TOKEN" \
  "$MASTER_URL/api/streams/$MASTER_STREAM/start" \
  | jq
```

Backup остановится сам после `recoveryThreshold` подряд успешных health-check и
выдержки `max(recoveryMinStableSec, failoverStopDelaySec)`.

Проверить, что Backup вернулся в standby:

```bash
curl -fsS \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  "$BACKUP_URL/api/streams/$BACKUP_STREAM" \
  | jq '.running, .runtimeDesired, .runtimeOverride, .failover.triggerState'
```

Ожидаемо:

- `.runtimeOverride` очищается или перестаёт быть `"started"`;
- `.runtimeDesired` возвращается к persisted `enabled=false`;
- `.failover.triggerState.state` становится `standby`.

Если нужно остановить Backup вручную:

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  "$BACKUP_URL/api/failover/streams/$BACKUP_STREAM/backup/stop" \
  | jq
```

## Как проверить, что Backup покрывает downtime

Сначала посмотрите status Backup-потока. Cleanup summary содержит
`backupArchiveUsage`, рассчитанный по failover event intervals и локальному
архиву Backup:

```bash
curl -fsS \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  "$BACKUP_URL/api/streams/$BACKUP_STREAM" \
  | jq '.failover.cleanupState.summary.backupArchiveUsage'
```

Важные поля:

- `coveredFrom`, `coveredTo` - общий временной диапазон backup-архива;
- `coveredDurationSec` - длительность покрытого диапазона;
- `estimatedCoveredDowntimeSec` - оценка покрытия именно failover downtime;
- `totalSegments`, `totalBytes` - общий размер backup-архива;
- `acknowledgedSegments`, `acknowledgedBytes` - уже подтверждённые Master
  интервалы;
- `unacknowledgedSegments`, `unacknowledgedBytes` - данные, которые ещё нельзя
  спокойно чистить без риска потери восстановления;
- `intervals` - разбивка usage по failover-интервалам.

Если `cleanupState` ещё пустой, запустите cleanup-проход вручную. Он не
обязан удалять данные: при отсутствии expired/acked/quota кандидатов он просто
пересчитает summary.

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  "$BACKUP_URL/api/failover/streams/$BACKUP_STREAM/cleanup/run" \
  | jq '.result.summary.backupArchiveUsage'
```

Для быстрой проверки ranges можно использовать обычный playback endpoint
Backup-потока:

```bash
curl -fsS \
  "$BACKUP_URL/$BACKUP_STREAM/recording_status.json?token=$BACKUP_PLAYBACK_TOKEN" \
  | jq
```

## Как вручную запустить repair на Master

Убедитесь, что Master снова пишет свежие сегменты:

```bash
curl -fsS \
  "$MASTER_URL/api/failover/streams/$MASTER_STREAM/health?token=$MASTER_PLAYBACK_TOKEN" \
  | jq
```

Запустите repair-проход на Master:

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $MASTER_MANAGEMENT_TOKEN" \
  "$MASTER_URL/api/failover/streams/$MASTER_STREAM/repair/run" \
  | jq
```

В успешном ответе смотрите:

- `result.summary.remoteSegments` - сколько сегментов увидел Master на Backup;
- `result.summary.missingSegments` - сколько сегментов признано gap и
  запланировано к импорту;
- `result.summary.importedSegments` - сколько реально импортировано;
- `result.summary.failedSegments` - ошибки скачивания/импорта;
- `result.summary.overlapSegments` - сколько Backup-сегментов пересекалось с
  локальным Master-архивом;
- `result.summary.splitBrainSuspected=true` - есть overlap, то есть возможен
  split-brain или pre-roll на границе. Такие Backup-сегменты не импортируются:
  локальный Master segment имеет приоритет.

После успешного repair Master сам отправляет `repair-ack`, если включён
`failover.repair.deleteOnBackup=true`. Для автоматического ack `peerUrl` в
Master-конфиге должен содержать token, который проходит failover write-auth на
Backup, например management token Backup-ноды:

```json
"peerUrl": "https://backup.example/api/failover/streams/cam1-backup?token=<BACKUP_MANAGEMENT_TOKEN>"
```

Если token не задан или не подходит, repair всё равно может скачать и
зарегистрировать сегменты, но в summary будет `repairAckStatus="failed"`, а
Backup будет продолжать считать эти диапазоны unacknowledged.

Если нужно подтвердить диапазон вручную, вызовите `repair-ack` на Backup:

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"ranges":[{"from":1779962400,"to":1779966000}],"deleteGraceSec":3600}' \
  "$BACKUP_URL/api/failover/streams/$BACKUP_STREAM/repair-ack" \
  | jq
```

После grace period cleanup на Backup сможет удалить acknowledged segments.
Acknowledged segments удаляются даже если они попадают в текущий hot-buffer
tail, потому что Master уже подтвердил наличие этих данных. Обычная очистка
hot-buffer выполняется фоновым cleanup worker примерно раз в 30 секунд, поэтому
`hotBuffer.durationSec` - мягкий предел, а не точный wall-clock cutoff.
Для немедленной проверки можно вручную запустить cleanup:

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $BACKUP_MANAGEMENT_TOKEN" \
  "$BACKUP_URL/api/failover/streams/$BACKUP_STREAM/cleanup/run" \
  | jq
```

## Что смотреть при проблемах

- `401 failover_api_unauthorized` на `health` или `segments`: проверьте, что
  `peerUrl` содержит обычный playback token peer-потока, либо используйте
  management/failover auth для служебной проверки.
- Backup не стартует: проверьте `.failover.triggerState.lastError`,
  `.failureCount`, `pollIntervalSec`, `failureThreshold` и свежесть
  `lastSegmentAgeSec` Master.
- Backup быстро стартует/останавливается: увеличьте `failureThreshold`,
  `recoveryThreshold`, `recoveryMinStableSec` или `failoverStopDelaySec`.
- Repair ничего не импортирует: проверьте, что Backup действительно содержит
  ranges за gap, а локальный Master не имеет перекрывающихся segment intervals.
- `splitBrainSuspected=true`: это не ошибка импорта. Это означает, что Backup
  прислал segment, который пересекается с локальным Master segment. Он
  пропускается, чтобы не сломать playback дублями и немонотонными DTS/PTS.
