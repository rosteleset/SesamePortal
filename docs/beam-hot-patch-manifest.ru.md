# BEAM hot patch manifest

`beam_hot_patch` - это дополнительный protected artifact для обновления
строго ограниченного набора BEAM-модулей без restart `sesame-dvr.service`.
Обычная сборка full release на `license` остаётся основной и не меняется:
hot patch строится только как побочный артефакт между двумя уже собранными
release build-ами.

## Когда можно делать hot patch

Hot patch допустим только если diff между `fromBuildId` и `toBuildId`
содержит `.beam` файлы из allowlist и не содержит:

- NIF/native `.so`;
- `erts/`;
- `priv/` и frontend/static assets;
- `sys.config`, `vm.args`, `COOKIE`;
- dependency app beams вне явного allowlist;
- migrations/persistent-state schema changes;
- изменения supervision tree или boot-only config.

Первый safe scope:

- `SesameDvr.Retention.Scheduler`;
- `SesameDvr.Retention.Cleaner`;
- `SesameDvr.Storage.CameraIndexMaterializer`;
- `SesameDvr.Storage.VolumeIndexMaterializer`.

## Manifest

Минимальный manifest:

```json
{
  "schema": "sesame-dvr-beam-hot-patch-v1",
  "artifactType": "beam_hot_patch",
  "patchId": "20260615-camera-materializer-fix",
  "fromBuildId": "old-build-id",
  "toBuildId": "new-build-id",
  "commitSha": "0123456789abcdef",
  "moduleAllowlist": [
    "SesameDvr.Storage.CameraIndexMaterializer"
  ],
  "requiresQuiesce": [
    "camera_index_materializer"
  ],
  "beamFiles": [
    {
      "module": "SesameDvr.Storage.CameraIndexMaterializer",
      "path": "beams/Elixir.SesameDvr.Storage.CameraIndexMaterializer.beam",
      "sha256": "hex-sha256",
      "bytes": 123456
    }
  ],
  "deniesRestart": true,
  "createdAt": "2026-06-15T00:00:00Z"
}
```

`path` всегда относительный к директории staged artifact или абсолютный путь
при ручном локальном применении. Runtime проверяет `bytes`, `sha256`,
`fromBuildId`, allowlist/denylist и обязательные exports для quiesce adapters.

## License index

`protected/index.json` может содержать дополнительный массив:

```json
{
  "hotPatches": [
    {
      "artifactType": "beam_hot_patch",
      "patchId": "20260615-camera-materializer-fix",
      "fromBuildId": "old-build-id",
      "toBuildId": "new-build-id",
      "os": "ubuntu",
      "version": "24.04",
      "arch": "amd64",
      "updateChannel": "release",
      "manifestJsonUrl": "https://license.sesameware.com/.../manifest.json",
      "manifestSha256": "hex-sha256",
      "manifestBytes": 1234,
      "manifestSignatureUrl": "https://license.sesameware.com/.../manifest.json.sig",
      "manifestSignatureSha256": "hex-sha256",
      "manifestSignatureBytes": 96,
      "signatureRequired": true,
      "signatureAlgorithm": "ed25519-raw",
      "releaseSigningPublicKeyUrl": "https://license.sesameware.com/.../release-signing-public.pem",
      "releaseSigningPublicKeySha256": "hex-sha256",
      "modules": [
        "SesameDvr.Storage.CameraIndexMaterializer"
      ],
      "requiresQuiesce": [
        "camera_index_materializer"
      ]
    }
  ]
}
```

Клиент выбирает hot patch только если `installed.buildId == fromBuildId`.
Full release selection продолжает работать через `artifacts[]`.

Перед передачей manifest в runtime updater проверяет `manifestBytes` /
`manifestSha256`. Если в index есть `manifestSignatureUrl` или
`signatureRequired=true`, дополнительно скачивается detached signature и
`releaseSigningPublicKeyUrl`, после чего manifest проверяется тем же
release-signing ключом, что и full protected package.

## Runtime flow

1. `plan` читает manifest или берёт compatible patch из `UpdateChecker`;
   remote-flow скачивает manifest и `.beam` файлы в локальный staged dir.
2. Runtime проверяет target build, sha256/bytes и allowlist/denylist.
3. `prepare` quiesce-ит только заявленные подсистемы:
   retention scheduler, camera materializer, volume materializer.
4. Runtime делает `:code.soft_purge/1` и `:code.load_binary/3`.
5. Выполняется smoke: module-specific `hot_patch_smoke/0` или stats call.
6. `resume` восстанавливает прежнее состояние runtime enabled flags.
7. При ошибке уже загруженные modules откатываются из staged backup старых
   `.beam`, затем подсистемы resume-ятся.

UI показывает два разных действия: full release `Обновить` через systemd и
hot patch `Обновить без перезапуска`, который вызывает
`POST /api/system/update/hot-patch/start` с `source=available`.

Статус последней попытки хранится в
`/var/lib/sesame-dvr/update/hot_patch_status.json`.
