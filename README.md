# SesamePortal

Languages: [English](README.md) | [Русский](README.ru.md)

SesamePortal is a local PHP surveillance portal for SesameDVR installations. It
manages DVR servers, users, groups, cameras, per-user playback tokens, favorites,
and provides mosaic/map viewing pages backed by a SesameDVR auth backend.

## Local Development

```bash
php bin/portal migrate
php bin/portal create-admin admin admin123
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`.

Default local state is stored in `var/portal.sqlite`. Production installs use
`/var/lib/sesame-portal`.

SQLite is the default storage backend. PostgreSQL/MySQL can be selected through
config or environment variables:

```php
'db_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=sesame_portal',
'db_user' => 'sesame_portal',
'db_password' => 'secret',
```

The same values can be passed as `SESAME_PORTAL_DB_DSN`,
`SESAME_PORTAL_DB_USER`, and `SESAME_PORTAL_DB_PASSWORD`.

The UI supports the same language set as SesameDVR: `ru`, `en`, `de`, `fr`,
`es`, `it`, `pt`, `bg`, `pl`, `zh`, `ja`, `ko`, `ar`, and `hy`. The default
language is configured with `locale` or `SESAME_PORTAL_LOCALE`; users can
switch from the language dropdown or with `?lang=<code>`.

## Camera Modes

Each camera can run in one of three modes:

- `managed`: SesamePortal writes the stream configuration to the selected
  SesameDVR server through the management API. The stream title
  (`display_name`/`displayName`) is sent as the stream `displayName`;
  `dvr_stream_name` stays the URL-safe technical stream name used by DVR
  endpoints.
- `edge_agent`: SesamePortal creates a push stream on the DVR with
  `publisherKind=agent`, linked to `agent_id` and `agent_camera_id`.
- `read_only`: SesamePortal does not change DVR configuration and only uses the
  selected DVR server plus `dvr_stream_name` for preview, auth, and playback.

Per camera, Portal can add an HTML/CSS watermark with the current user's login
over the Portal player and control its intensity. The video stream is not
transcoded for this.

## Viewer UI

The mosaic supports all/favorites/group filtering, case-insensitive search by
camera title or technical stream name, pagination, and a 2-6 cameras-per-row
density switch. Page size follows the selected density: 4/6/12/15/18 cameras for
2/3/4/5/6 columns. It uses fixed 16:9 camera cards. Preview images are
preloaded behind a loader and then swapped in, so refreshes do not show
half-loaded images. The preview refresh interval is configurable from the viewer
UI, including an `Off` mode. If a DVR stream is offline, the card shows `Stream
unavailable`; an old preview may still be shown with that status overlay.

The map view supports the same filters and search. It auto-fits the current
camera set, clusters nearby cameras at lower zoom levels, shows camera
direction/FOV markers, and exposes the same favorite toggle as the mosaic.

All UI timestamps are rendered in the browser timezone. The server stores and
exchanges timestamps as absolute values.

## Screenshots

### SesameDVR

| Streams and playback | Monitoring | ONVIF |
| --- | --- | --- |
| <img src="docs/screenshots/sesamedvr-streams.jpg" alt="SesameDVR streams and player" width="280"> | <img src="docs/screenshots/sesamedvr-monitoring.jpg" alt="SesameDVR monitoring" width="280"> | <img src="docs/screenshots/sesamedvr-onvif.jpg" alt="SesameDVR ONVIF devices" width="280"> |

### SesamePortal: Desktop

| Dashboard | Mosaic | Map | Player |
| --- | --- | --- | --- |
| <img src="docs/screenshots/sesameportal-desktop-dashboard.jpg" alt="SesamePortal desktop dashboard" width="220"> | <img src="docs/screenshots/sesameportal-desktop-mosaic.jpg" alt="SesamePortal desktop mosaic" width="220"> | <img src="docs/screenshots/sesameportal-desktop-map.jpg" alt="SesamePortal desktop map" width="220"> | <img src="docs/screenshots/sesameportal-desktop-player.jpg" alt="SesamePortal desktop player" width="220"> |

### SesamePortal: Mobile

| Mosaic | Map | Player |
| --- | --- | --- |
| <img src="docs/screenshots/sesameportal-mobile-mosaic.jpg" alt="SesamePortal mobile mosaic" width="180"> | <img src="docs/screenshots/sesameportal-mobile-map.jpg" alt="SesamePortal mobile map" width="180"> | <img src="docs/screenshots/sesameportal-mobile-player.jpg" alt="SesamePortal mobile player" width="180"> |

## Production Install

```bash
sudo bash scripts/install.sh \
  --domain portal.example.com \
  --email admin@example.com \
  --admin-login admin \
  --admin-password 'change-me-now'
```

The installer creates an nginx site, connects the detected php-fpm socket,
initializes SQLite, creates the first admin user, and can issue a Let's Encrypt
certificate through `certbot certonly --webroot`. It writes only the
SesamePortal site file and does not let certbot rewrite existing nginx site
configs. It also installs `/usr/local/sbin/sesame-portal-update` and a sudoers
rule so an admin can update Portal from the web UI under `Settings`.

For repair/update runs against an existing database:

```bash
sudo bash scripts/install.sh \
  --domain portal.example.com \
  --email admin@example.com \
  --repair
```

The installer backs up the current release, nginx site, cron entry, config, and
SQLite files before applying changes. If a step fails, it restores those files
and reloads nginx/php-fpm when possible.

## First Login

Open the portal URL after installation:

```text
https://portal.example.com
```

Use the credentials passed to the installer:

- login: the value of `--admin-login`, default `admin`;
- password: the value of `--admin-password`.

Example from the command above:

```text
login: admin
password: change-me-now
```

During `--repair` runs, the installer does not change existing admin users when
`--admin-password` is omitted.

## SesameDVR Trial Install

SesamePortal is normally connected to one or more SesameDVR servers. For GitHub
evaluation installs, use this public SesameDVR trial key:

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh | sudo bash -s -- --license-key SDVR-TRIAL-85GT2-A7YYD-HSSEN-YW98U
```

For public HTTPS access, pass the target DVR domain and ACME email:

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-trial-install.sh \
  | sudo bash -s -- \
      --license-key SDVR-TRIAL-85GT2-A7YYD-HSSEN-YW98U \
      --publish-service \
      --publish-server-name dvr.example.com \
      --publish-acme \
      --acme-email admin@example.com
```

More details are in
[`docs/SESAME-DVR-TRIAL-INSTALL.ru.md`](docs/SESAME-DVR-TRIAL-INSTALL.ru.md).

Additional SesameDVR documentation:

- [SesameDVR feature and product description](docs/SESAME-DVR-PRODUCT-DESCRIPTION.en.md)
- [SesameDVR user guide](docs/sesame-dvr-user-guide.en.md)
- [SesameDVR HTTP API reference (RU)](docs/sesame-dvr-api.ru.md)
- [SesameDVR storage configuration (RU)](docs/dvr-storage-configuration.ru.md)
- [SesameDVR Prometheus / OpenMetrics endpoint (RU)](docs/prometheus-metrics-endpoint.ru.md)
- [SesameDVR BEAM hot patch manifest (RU)](docs/beam-hot-patch-manifest.ru.md)
- [SesameDVR DVR-side failover runbook (RU)](docs/dvr-cluster-failover-runbook.ru.md)

## CLI

```bash
php bin/portal migrate
php bin/portal create-admin <login> <password>
php bin/portal rotate-tokens
php bin/portal rotate-secrets
php bin/portal backup /path/to/backup.json
php bin/portal restore /path/to/backup.json
```

`rotate-secrets` re-encrypts stored SesameDVR management tokens with the
configured `crypto_primary_key` while preserving access to older key ids for
rotation windows.

## Checks

```bash
php -l app/Portal.php
bash -n scripts/install.sh
tests/http_smoke.sh
```
