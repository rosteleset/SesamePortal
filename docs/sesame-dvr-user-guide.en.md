# Sesame DVR: User Guide

This document describes how to operate Sesame DVR after installation: initial
sign-in, adding cameras, live and archive playback, ONVIF events, storage
management, updates, diagnostics, and common failures.

The guide is intended for server administrators and system operators. It does
not replace internal runbooks for building protected artifacts or operating the
license server.

## Table of Contents

1. [Purpose of Sesame DVR](#purpose-of-sesame-dvr)
2. [Server Requirements](#server-requirements)
3. [Installation](#installation)
4. [Initial Sign-In and Access](#initial-sign-in-and-access)
5. [Web Interface Overview](#web-interface-overview)
6. [Streams and Cameras](#streams-and-cameras)
7. [Live and Archive Playback](#live-and-archive-playback)
8. [ONVIF and Motion Events](#onvif-and-motion-events)
9. [Agents](#agents)
10. [Clients](#clients)
11. [Monitoring and Logs](#monitoring-and-logs)
12. [License and Updates](#license-and-updates)
13. [Archive Storage](#archive-storage)
14. [Global Settings](#global-settings)
15. [Service Commands](#service-commands)
16. [Files and Directories](#files-and-directories)
17. [Security](#security)
18. [Common Workflows](#common-workflows)
19. [Troubleshooting](#troubleshooting)
20. [HTTP Endpoints for Integrations](#http-endpoints-for-integrations)

## Purpose of Sesame DVR

Sesame DVR is a server-side video recording and viewing system for IP cameras.
The server accepts RTSP/URL streams, records an archive to disk, serves live
video, archive HLS playlists, previews, MP4 exports, and provides an
administrator web interface.

Its primary tasks are:

- connecting RTSP cameras, push streams, static JPEG sources, and other video
  sources;
- recording an archive with a configurable retention period;
- live viewing through the embedded player, HLS, and WebRTC/WHEP;
- archive playback on a timeline;
- exporting archive fragments as MP4;
- collecting ONVIF motion events;
- monitoring streams, the server, disks, clients, and logs;
- managing storage in SingleVolume and MultiVolume modes;
- updating the protected edition without leaving source code on a customer
  server.

## Server Requirements

Supported production environments:

- Ubuntu 22.04 / 24.04 / 26.04 or Debian 12 / 13;
- `amd64 / x86_64` architecture;
- `systemd`;
- `sudo` or root access for installation;
- outbound HTTPS access to `https://license.sesameware.com` and
  `https://license-2.sesameware.com`;
- sufficient network bandwidth to the cameras;
- preferably, a dedicated disk or partition for the archive mounted at
  `/var/dvr`.

For public access through a domain:

- the domain's DNS A/AAAA record must point to the server;
- inbound TCP port `80` must be open;
- inbound TCP port `443` must also be open for HTTPS and Let's Encrypt;
- if the environment uses a cloud firewall or security group, these ports must
  be opened there as well.

Performance depends on the camera count, codecs, bitrate, retention period,
disk speed, CPU, and enabled features. WebRTC does not transcode video, so it
also depends on browser codec support.

## Installation

Installation is normally performed with a single command obtained by the
administrator from the license panel. The command contains the activation key
and publishing parameters.

### Installation with Public HTTPS

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

Replace:

- `<activation-key>` with the issued key;
- `dvr.example.com` with the server domain;
- `admin@example.com` with the email address for Let's Encrypt.

### Installation with Public HTTP

```bash
curl -fsSL https://license.sesameware.com/sesame-dvr-artifacts/bootstrap-protected-install.sh \
  | sudo bash -s -- \
      --license-key '<activation-key>' \
      --server-name "$(hostname -f)" \
      --publish-service \
      --publish-server-name dvr.example.com
```

### What the Installer Does

The installer:

- detects the operating system and architecture;
- downloads the appropriate protected artifact;
- activates the license through the license server;
- waits for a per-instance anchor build when required;
- installs the application in `/opt/sesame-dvr`;
- creates the state directory `/var/lib/sesame-dvr`;
- creates the archive root `/var/dvr`;
- installs the `sesame-dvr` systemd service;
- installs support tools in `/usr/local/sbin`;
- configures nginx when `--publish-service` is used;
- issues a Let's Encrypt certificate when `--publish-acme` is used;
- prints the initial sign-in URL if a management token was generated.

## Initial Sign-In and Access

The admin UI is available at:

```text
https://<domain>/admin
```

or, when HTTPS is not configured:

```text
http://<domain>/admin
```

On a clean installation, the UI may request:

```text
login: admin
password: admin
```

Change the default administrator password after the first sign-in.

If the installer printed a URL such as:

```text
https://<domain>/admin?token=...
```

open it in a browser. The management token is stored by the browser and used
for management API requests. In `Settings`, you can:

- apply an existing management token;
- generate a new token;
- clear the token in the current browser;
- change the admin UI password;
- sign out of the admin UI.

## Web Interface Overview

The main sections are:

- `Streams` - camera list, stream management, live/archive playback, ffprobe,
  and logs for the selected stream.
- `Monitoring` - current metrics and charts for CPU, memory, disks, network,
  ingest, BEAM/runtime, and processes.
- `Logs` - the Sesame DVR event log with level and source filters.
- `Clients` - active playback client users, WebRTC sessions, and recent HLS
  clients.
- `ONVIF` - ONVIF device list, capability checks, and event subscriptions.
- `Agents` - remote Sesame DVR Edge Agents: enrollment, connection status,
  agent camera list, on-site ONVIF scan, diagnostics/logs, commands, and stream
  creation from agent cameras.
- `Settings` - license, updates, storage, tokens, orphan cleanup, and global
  configuration.

## Streams and Cameras

A stream represents a camera or another video source. Its primary fields are:

- `Name` - the stable camera identifier used in URLs and archive paths. Use
  Latin letters, digits, a hyphen, or an underscore.
- `Source type` - `direct` for an RTSP/HTTP URL, `udp_multicast` for UDP
  multicast/MPEG-TS, `push` for a stream published to Sesame DVR from an
  external RTMP or SRT source, or `image` for a static JPEG source.
- `Source` - the source address or path. For `direct`, this is an RTSP/HTTP URL,
  for example `rtsp://user:password@10.0.0.10:554/stream1`. For
  `udp_multicast`, use a URL such as
  `udp://@239.10.10.10:5000?localaddr=192.168.0.1&overrun_nonfatal=1&fifo_size=5000000`.
  For `push`, this is the logical ingest endpoint. For `image`, this is the path
  to the uploaded JPEG in `dvrRoot/static-sources`; a version query may be
  appended after upload so a running stream restarts when the file changes.
- `JPEG file` - the file uploaded by the web UI for `sourceType=image`.
  `ffmpeg_nif` turns it into a static H.264/fMP4 video stream for live HLS,
  WHEP/WebRTC, previews, and the embedded player.
- `Record archive` - enables long-term storage of stream segments. When
  disabled, the server keeps only the minimal live buffer required for live
  playback and immediately removes older segments for this stream from storage
  and the catalog. Archive recording is disabled by default for JPEG sources,
  but the live buffer still keeps the latest `liveWindow` fMP4 segments so HLS
  players can start without waiting for several playlist updates.
- `Retention` - archive retention period. It can be a number of days or a string
  such as `7d`, `6h`, or `180m`. A string is preserved in the configuration and
  displayed exactly as entered; a numeric value is interpreted as days for
  compatibility with older configurations.
- `Authorization`:
  - `none` - playback endpoints are accessible without a token;
  - `static` - playback requires the `?token=<token>` query parameter;
  - `auth backend` - access is checked by the external backend URL in the
    global configuration.
- `Disable audio` - disables audio during ingest and playback for this stream.
- `Enabled` - the stream is enabled in the configuration and must start when
  the service starts.

In the protected edition, the producer is not selected per stream. The global
default `ffmpeg_nif` producer is used and writes self-initializing fMP4 segments
directly to the selected storage volume. The external `ffmpeg` producer is only
available in unprotected/development builds and is rejected by the protected
runtime.

### DVR-Side Stream Failover

The stream's `Failover` section can enable application-level redundancy between
two Sesame DVR nodes without involving Portal:

- `none` - a regular stream without failover;
- `master` - the primary stream, which records the archive and can retrieve
  missing intervals from Backup after recovery;
- `backup` - the standby stream, which monitors Master health and starts
  recording only after a sustained failure.

`Peer URL` must point to the related stream on the other DVR node in this form:

```text
https://peer.example/api/failover/streams/<peer-stream>?token=<playback-token>
```

Here, `token` is the regular playback token of the peer stream. It is used for
health/read-side failover requests and archive reads through standard playback
endpoints. Control actions `backup/start`, `backup/stop`, `repair/run`,
`cleanup/run`, and `repair-ack` require management/failover authorization.

In a protected build, failover is controlled by the separate `dvr_failover`
license feature. If the feature is absent from the license or lease, the server
rejects `failover.mode=master|backup`, returns `403 license_feature_disabled`
for `/api/failover/...`, and stops active Backup or hot-buffer runtimes.

`Hot-buffer` can be enabled for Backup. The standby runtime then continuously
records a short buffer even while Master is healthy. The buffer is limited by
`Hot-buffer duration` and an optional `Hot-buffer quota`. During an actual
failover, the final seconds of the hot buffer before Backup starts are retained
as a repair window to close the gap between the real Master failure and its
detection. A hot buffer creates a second permanent camera connection, so it
should remain disabled for single-client RTSP cameras.

A DVR-side placement planner API is available for manual or external automation
of Backup stream placement. `GET /api/failover/nodes/local/resources` returns a
resource snapshot for the local node. `POST /api/failover/placement/plan`
accepts a node and stream list, estimates bitrate and required backup storage
for the specified downtime, selects a Backup node according to capacity and
weights, and returns dry-run warnings. This is not a Portal cluster planner:
Sesame DVR only calculates the plan and can apply patches to its local node
through `POST /api/failover/placement/apply`.

Operational checks, manual Backup start, repair, and cleanup are described in
the [DVR-side failover runbook](dvr-cluster-failover-runbook.ru.md) (Russian).

### JPEG Source

`sourceType=image` represents a static image that Sesame DVR exposes as a normal
stream. Common use cases include a test source, a camera placeholder, a demo
screen, or an integration that periodically replaces one JPEG.

To configure it in the UI:

1. Create or open a stream in `Streams`.
2. Set `Source type` to `image`.
3. Upload a JPEG in the `JPEG file` field.
4. Save and enable the stream.

The server stores the file in `dvrRoot/static-sources` and uses it as input for
`ffmpeg_nif`. Uploading the JPEG again changes the source version and restarts
the stream so viewers receive the updated image.

### UDP Multicast Source

Use `sourceType=udp_multicast` when Sesame DVR must receive a local multicast
MPEG-TS stream directly from the network. Such streams are commonly published
by network equipment, an IPTV headend, or another media server on the LAN.

Example source:

```text
udp://@239.10.10.10:5000?localaddr=192.168.0.1&overrun_nonfatal=1&fifo_size=5000000
```

`localaddr` identifies the local interface address through which the server must
join the multicast group. This is important on servers with several network
interfaces or VLANs. The `overrun_nonfatal` and `fifo_size` parameters reduce
the risk of ingest stopping during brief bursts in the input stream.

### SRT Delivery for Push Streams

SRT is supported as a delivery transport for `sourceType=push` streams. A
typical case is a camera behind NAT or on an unstable network: a local Edge
Agent reads it over RTSP/ONVIF and publishes media to Sesame DVR over
SRT/MPEG-TS. An external publisher can also use SRT if it sends MPEG-TS and
provides the correct stream ID.

Sesame DVR runs a shared SRT acceptor/router on one public UDP port and routes
incoming streams using `streamid=sesame:stream=<streamName>`. A separate public
port is not required for every camera. The agent normally receives an SRT caller
URL in a control command, for example:

```text
srt://dvr.example.com:10080?mode=caller&transtype=live&pkt_size=1316&streamid=sesame%3Astream%3Dgate
```

Primary Sesame DVR SRT settings:

- `SESAME_DVR_SRT_PUBLISH_HOST` - the public host included in caller URLs;
- `SESAME_DVR_SRT_BIND_HOST` - the router bind host, `0.0.0.0` by default;
- `SESAME_DVR_SRT_BASE_PORT` - the shared public UDP port, `10080` by default;
- `SESAME_DVR_SRT_LATENCY_MS` - SRT latency, `120` by default;
- `SESAME_DVR_SRT_PASSPHRASE` - an optional shared passphrase for protecting
  the SRT port.

Open inbound UDP port `SESAME_DVR_SRT_BASE_PORT` in the firewall or security
group. SRT protects delivery from publisher/agent to Sesame DVR, but it is not a
local store-and-forward buffer and does not fix problems on the RTSP path from
camera to agent.

### Stream Actions

- `Save` - write changes to the configuration.
- `Start` - start the runtime without changing `enabled` in the configuration.
- `Stop` - stop the runtime without changing `enabled` in the configuration.
- `Restart` - restart the selected stream runtime.
- `Enable` - enable the stream in the configuration and start it.
- `Disable` - disable the stream in the configuration and stop it.
- `Delete` - remove the stream from the configuration. Archive data on disk is
  not deleted automatically when the stream is removed.

The left panel supports selecting several streams and applying bulk actions:
`Start`, `Stop`, `Enable`, `Disable`, and `Delete`.

### Stream Status

The selected stream card and header display:

- runtime state: running, stopped, or failed;
- ingest producer;
- video and audio codecs;
- WebRTC readiness;
- ONVIF status;
- archive state and latest-segment delay;
- retention period.

If a stream is enabled but does not record an archive, first check `Logs`,
`FFprobe info`, `Monitoring`, and RTSP URL availability from the server.

## Live and Archive Playback

### Embedded Player

The `Monitor` tab in `Streams` displays the embedded player for the selected
camera. It supports:

- live playback;
- switching to an archive time;
- an archive timeline;
- ONVIF event markers on the timeline;
- motion-only archive playback;
- timelapse archive playback when enabled for the stream;
- timeline zoom control;
- navigation to a selected time;
- MP4 fragment export.

By default, archive HLS in the embedded player remains a finite VOD playlist to
preserve the behavior of existing external embeds. New integrations can enable
the separate `archive_playlist=sliding` mode:

```text
https://dvr.example.com/cam1/embed.html?dvr=true&archive_playlist=sliding
```

In this mode, the player requests a growing HLS `EVENT` archive playlist without
`#EXT-X-ENDLIST`. The server initially returns a short archive window and then
extends it on subsequent hls.js requests according to the current playback
speed. This reduces visible restarts during long archive sessions, especially
at `8x` and `16x`. Browsers with native HLS do not use this mode automatically
and continue to use a normal VOD playlist.

The motion mode button enables archive playback only for intervals where ONVIF
reports `motion=true`. The player builds a dedicated HLS playlist from motion
events, adds margins around each event, and skips intervals without motion
without reloading the video source. The playback indicator remains on the real
archive timeline: when HLS skips an event-free gap, the indicator jumps to the
next archive interval as well.

If `Record timelapse` is enabled for the stream, a `Timelapse` mode appears next
to the regular archive. The server stores raw timelapse frames as staging data,
packages them into HLS/fMP4 chunks in the background, and serves a materialized
manifest at `/<camera>/timelapse.m3u8`. With `start=...&end=...`, the playlist
is limited to that real archive window. The player displays the accelerated
media duration separately, while the timeline and seeking remain tied to real
archive time. `Frames per hour` controls capture frequency, retention uses the
`d`/`h`/`m` format, and `Playback FPS` sets the resulting HLS playback speed.

When `Record archive` is disabled, the embedded player is live-only. It does not
show `Archive`, draw an archive timeline, or offer MP4 export even if the URL is
opened with `dvr=true`.

Embedded player URL:

```text
/<camera>/embed.html
```

Example:

```text
https://dvr.example.com/cam1/embed.html
```

### WebRTC/WHEP

The `WebRTC` tab starts live WebRTC playback. WebRTC support depends on the
codec:

- H.264 generally has the broadest browser support;
- HEVC depends on the browser, operating system, and hardware support;
- use the HLS/embedded-player fallback when WebRTC is unavailable.

HLS and WebRTC also enforce the licensed active playback client user limit. If
the limit is reached, a new client receives `max_client_users_exceeded`, while
already active clients continue to work.

### Archive HLS

Archive intervals are displayed on the timeline. For manual playback, specify
the beginning and end of a period and click `Archive HLS`.

Possible reasons for archive gaps include:

- the camera was not recording during that period;
- the volume was offline, read-only, or full;
- retention removed older segments;
- the stream restarted or lost its RTSP connection;
- a catalog audit or rebuild is required after files were moved manually.

### MP4 Export

Select a time range in the embedded player and use download/export. The export
operation assembles an MP4 file from archive segments. Temporary export files
are removed automatically after a short period.

### Preview

Sesame DVR can generate short MP4 previews and JPG frames when enabled in the
settings. Preview data is a cache and can be deleted and rebuilt from segments.

When `previewMp4CacheEnabled=false`, separate MP4 preview files are not created.
MP4 preview endpoints use the nearest archive segment, and `previewDuration`
applies only to the separate MP4 cache mode.

## ONVIF and Motion Events

The `ONVIF` section is used for devices with an ONVIF endpoint.

Primary operations:

- `Scan RTSP` - try to discover ONVIF devices from RTSP cameras;
- `New device` - add an ONVIF endpoint manually;
- `ONVIF camera` on the selected stream card - open the linked ONVIF device or
  try to add one using that stream's RTSP URL;
- `Check capabilities` - retrieve device capabilities;
- `Start events` - subscribe to PullPoint events;
- `Stop events` - stop event collection;
- `Clear log` - delete saved events for the selected device.

When an ONVIF device is added from a selected RTSP stream, Sesame DVR first
tries to discover an endpoint for that camera only. If discovery fails, the UI
opens the add form and pre-fills `Host`, `Username`/`Password`, stream name, and
retention from the RTSP settings. Check the ONVIF `Port` and `Path` manually,
because the RTSP port is normally different from the ONVIF port. If discovery
finds an endpoint, Sesame DVR asks for confirmation before adding or linking the
device in the configuration.

ONVIF device fields:

- `Name` - device name;
- `Host` - camera IP address or hostname;
- `Port` - ONVIF port, often `80`, `8080`, or a vendor-specific port;
- `Path` - normally `/onvif/device_service`;
- `Username` and `Password` - camera credentials;
- `Events retention days` - ONVIF event retention period;
- `Pull interval seconds` - PullPoint event polling interval.

ONVIF events are stored separately from the video archive because they are
easier to back up and restore independently. The UI displays them alongside
archive intervals to make motion easier to locate.

## Agents

The `Agents` section is intended for remote sites where Sesame DVR cannot access
local RTSP/ONVIF cameras directly, or where a small Edge Agent inside the site's
LAN is more convenient.

This section allows you to:

- create an agent and issue an enrollment password;
- enable, disable, or delete an agent, and revoke or rotate its secret;
- inspect online/offline status, capabilities, and last activity;
- view cameras discovered or reported by the agent;
- retrieve a camera snapshot through the agent;
- run an ONVIF scan at the agent site;
- request diagnostics or a log tail and send commands to the agent;
- create a Sesame DVR stream from an agent camera.

After enrollment, the agent connects to Sesame DVR over WebSocket, receives
commands, and publishes media through push ingest using RTMP/FLV or SRT/MPEG-TS.
Such cameras normally use `sourceType=push`: the agent reads the local
RTSP/ONVIF source, while Sesame DVR accepts the published stream and handles
archive recording, live playback, previews, HLS/WebRTC, and APIs in the same
way as for a directly connected camera.

Edge Agent functionality is license-controlled. If the feature is not enabled,
the `Agents` section and related APIs are unavailable.

## Clients

The `Clients` section displays:

- a summary of active playback client users and the licensed limit;
- active WebRTC sessions from the native WHEP registry;
- recent HLS clients based on playlist and segment requests.

This helps identify who is currently watching cameras and contributing to
server load.

A playback client user is identified by the combination of client IP address
and `User-Agent`. HLS activity remains active for a short TTL after the latest
playlist or segment request, while WebRTC uses the live registry of active WHEP
sessions. The license can define `maxClientUsers`; legacy licenses may instead
use `maxClientConnections`.

## Monitoring and Logs

### Monitoring

The `Monitoring` section displays:

- total CPU and Sesame DVR CPU usage;
- separate ingest, ONVIF, nginx, and BEAM runtime load;
- process and system memory;
- disks and storage volumes;
- network activity;
- ingest processes;
- the BEAM profile and top runtime consumers.

When CPU usage increases, first check:

- how many cameras are actively recording;
- whether bulk playback or API requests are running;
- whether logs contain frequent errors;
- whether a catalog rebuild or audit is in progress;
- whether disk throughput is saturated.

### Prometheus and Grafana

Sesame DVR exposes an OpenMetrics snapshot for Prometheus at:

```text
GET /metrics
```

The endpoint is intended for regular Prometheus scraping. It includes
per-stream metrics with `server_id` and `name` labels: `ts_delay`,
`stream_bitrate`, `stream_bytes_in`, `stream_input_retries`, and
`stream_online_clients`, together with additional `sesame_dvr_stream_*` states
and ingest counters. The `play_bytes` metric is retained for compatibility with
Flussonic-style dashboards; Sesame DVR does not currently count playback bytes
and reports `0` for it.

Configure `/metrics` authorization separately from the admin UI and playback
tokens in `Settings -> Global config`:

- `Metrics auth mode`: `disabled`, `none`, `bearer`, or `basic`;
- `Metrics bearer token`: write-only token for `Authorization: Bearer ...`;
- `Metrics basic username`;
- `Metrics basic password`: write-only Basic authentication password.

`disabled` turns metric delivery off completely and makes `GET /metrics`
return `404`. `none` leaves the endpoint open without authentication.

Raw secrets are not stored in `config.json`; only hash fields remain after
saving. Leaving the token or password input empty preserves the stored secret.

Example Prometheus scrape configuration with a Bearer token:

```yaml
scrape_configs:
  - job_name: sesame-dvr
    scheme: https
    metrics_path: /metrics
    bearer_token: <metrics-token>
    static_configs:
      - targets: ["dvr.example.com"]
```

Example with Basic authentication:

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

For API examples, PromQL, and alert rules, see the
[Prometheus metrics endpoint guide](./prometheus-metrics-endpoint.ru.md)
(Russian).

### Logs

The `Logs` section contains the general event log. A separate `Logs` tab on the
stream card contains ingest/ffmpeg logs for the selected camera.

Common problem indicators:

- `rtsp` or `connection refused` - the camera is unavailable or its URL is
  incorrect;
- `401/403` - invalid camera credentials or playback token;
- `archive stale` - the archive is not advancing;
- `license` - a license or lease problem;
- `storage` - a volume is offline, read-only, full, or has a catalog error.

## License and Updates

`Settings -> License` displays:

- license status;
- expiration date;
- stream, retention, and playback client user limits;
- fingerprint;
- lease status and lease expiration;
- license and anchor details.

`Renew` forces a lease refresh from the license server. The runtime normally
renews the lease automatically on schedule.

`Settings -> Updates` displays:

- the current server version;
- the available version;
- the latest check status;
- update launcher state;
- update service log.

Available actions:

- `Check` - request the current available version;
- `Update` - start a standard full release update through
  `sesame-dvr-update.service`;
- `Update without restart` - apply a compatible BEAM hot patch if the license
  server has published a `beam_hot_patch` for the current `buildId`.

The UI displays the update log while the update runs. During the service restart
stage, the page waits for the API to return and then displays the new version or
an error. A hot patch does not restart the service and can update only a limited
set of BEAM modules. The manifest format is described in
[BEAM hot patch manifest](./beam-hot-patch-manifest.ru.md) (Russian).

## Archive Storage

Sesame DVR supports two storage modes:

- `SingleVolume` - one archive root;
- `MultiVolume` - multiple storage volumes.

A legacy configuration without a `storage` block automatically operates as
`SingleVolume` with the `default` volume whose root is `dvrRoot`.

### Main Directories on a Volume

Each volume root can contain:

- `segments` - self-initializing fMP4 archive segments;
- `segments/<camera>/<YYYY>/<MM>/<DD>/<HH>/.hour_index*.hidx` - the primary
  per-hour segment index for a specific hour;
- `segments/<camera>/<YYYY>/<MM>/<DD>/<HH>/.hour_index*.term` - a legacy
  fallback for reading older indexes during runtime migration;
- `previews` - preview cache, including per-hour previews beside the segment
  shard;
- `timelapse` - materialized timelapse HLS/fMP4 chunks and manifests;
- `.sesame-dvr/camera_indexes/.../*.cidx` - derived camera indexes;
- `.sesame-dvr/volume_index*` - the derived volume index;
- `.sesame-dvr/volume_write_state.term` - write cursor state.

Segment files and primary `HourIndex` data in `*.hidx` format remain the source
of truth. `CameraIndex` (`*.cidx`) and `VolumeIndex` are derived indexes that
accelerate archive lookup, status endpoints, and retention planning, but can be
rebuilt from `HourIndex`.

### Indexes and Materialization

Index updates form a pipeline:

1. Committing a new segment updates `HourIndex` for
   `{volume, camera, hour}`.
2. `CameraIndexMaterializer` applies hour changes to `CameraIndex`
   asynchronously.
3. `VolumeIndexMaterializer` applies camera changes to `VolumeIndex`
   asynchronously.

This pipeline decouples segment recording from the more expensive derived
indexes. Dashboard debug diagnostics expose pending materializer work and lag,
including the greatest delay between physical segment recording and the
materialization pipeline.

The retention planner submits expired files and hour directories to
`DeleteQueue`. Physical deletion runs through the shared IO pipeline. After a
deletion, indexes receive feedback and tombstones so removed hours are not
planned repeatedly.

### Timelapse on Disk

Raw fMP4 timelapse frames are staging data, not a playback source. Once enough
frames have accumulated for an accelerated HLS chunk, a background task builds
the chunk, writes it atomically to timelapse storage, updates metadata,
HourIndex, and the manifest, and removes the raw staging files. A request to
`/<camera>/timelapse.m3u8` therefore reads a materialized manifest and does not
need to scan the archive or rebuild the playlist on every request.

New segments are written to the volume selected by the active write policy. If
recording is disabled on a volume, the current open segment finishes normally
and subsequent segments move to another writable volume. Archive status in the
stream list comes from catalog summaries and should not be blocked by slow
checks of every directory.

### Write Policies

- `single` - new segments are written to the selected primary volume.
- `round_robin` - new segments rotate across writable volumes.
- `weighted_round_robin` - round-robin with `Weight` taken into account.
- `least_used` - selects the volume with the lower `usedPercent`, then the one
  with more `freeBytes`.

`Scope`:

- `camera` - a separate cursor for every camera. This is the default and the
  best choice when failure of one of `n` disks should lose approximately `1/n`
  of each camera's archive;
- `global` - one cursor for all cameras. It may distribute instantaneous write
  load more evenly, but provides less uniform per-camera loss if a volume
  fails.

`Fallback`:

- empty - the first writable volume in configuration order;
- `least_used` - selects the least full writable volume.

`Require mountpoint` performs a stricter check that the volume is actually
mounted. This is useful on production servers with dedicated disks but may be
inconvenient in test configurations that use regular directories.

### Volume Fields

- `ID` - the stable volume identifier used in archive URLs such as
  `/dvr/v/<volume_id>/...`.
- `Root` - absolute path to the volume root.
- `Weight` - weight used by `weighted_round_robin`.
- `Max usage %` - usage threshold after which the volume is considered full for
  recording.
- `Min free bytes` - required free-space reserve.
- `Enabled` - the volume is available as an archive source.
- `Writable` - the writer may record new segments on the volume.

### Volume Actions

- `Edit` - load the volume into the edit form.
- `Check` - audit the catalog without writing changes.
- `Disable` - take the volume offline as an archive source and disable writing.
- `Enable` - return the volume online. Recording must then be enabled separately
  with `Enable writing`.
- `Disable writing` - keep the volume readable but prevent new recordings. If a
  segment writer is active, the UI waits for the segment to finish.
- `Enable writing` - allow new recordings on the volume.
- `Rebuild catalog` - rescan the volume and rebuild indexes and catalog data
  from segment files.
- `Delete` - remove the volume from the configuration only. Data on disk is not
  deleted.

### Orphan Archives

`Settings` includes an `Orphan archives` section. It finds archive, preview, and
service files that no longer belong to configured streams.

Available actions:

- `Calculate size` - run a safe scan without deleting anything;
- `Delete archives and previews` - remove the discovered orphan data.

Before deletion, make sure the affected cameras were not removed from the
configuration temporarily and the archive is not needed for recovery.

## Global Settings

`Settings -> Global config` includes:

- `Interface language` - UI language;
- `DVR root` - archive root for legacy or SingleVolume mode;
- `Ingest producer` - global default producer. A protected installation must
  continue to use `ffmpeg_nif`; external `ffmpeg` is intended only for
  unprotected/development builds and is rejected by the protected runtime;
- `Segment duration` - archive segment duration, 4 seconds by default;
- `Live window` - number of live segments in the HLS window;
- `External WHEP base URL` - external base URL used for WHEP `Location` when
  the DVR is behind NAT or a reverse proxy and the public host or port differs
  from the internal address;
- `FFmpeg restart delay ms` - delay before ingest restarts;
- `Camera stop timeout ms` - camera stop timeout;
- `Preview duration`, `Preview check interval ms`, `Preview max concurrency`,
  and `Preview min interval ms`;
- `FFprobe max concurrency`;
- `ONVIF events poll interval default, sec`;
- `Cleanup interval minutes`;
- `Archive stale restart after seconds`;
- `Generate separate MP4 preview files`;
- `Also generate JPG frames`;
- `Metrics auth mode`, `Metrics bearer token`, `Metrics basic username`, and
  `Metrics basic password` - independent authorization for the Prometheus
  `/metrics` endpoint; `disabled` turns the endpoint off;
- `Archive stale restart`.

Change these values carefully because they affect all cameras and runtime load.

## Service Commands

### Systemd

```bash
sudo systemctl status sesame-dvr --no-pager
sudo systemctl restart sesame-dvr
sudo systemctl stop sesame-dvr
sudo systemctl start sesame-dvr
```

Service journal:

```bash
sudo journalctl -u sesame-dvr -n 200 --no-pager
sudo journalctl -u sesame-dvr -f
```

If a separate ONVIF service is enabled:

```bash
sudo systemctl status sesame-dvr-onvif --no-pager
sudo journalctl -u sesame-dvr-onvif -n 200 --no-pager
```

### License

```bash
sudo sesame-dvr-license status
sudo sesame-dvr-license status --json
sudo sesame-dvr-license renew
sudo sesame-dvr-license fingerprint
sudo sesame-dvr-license fingerprint --raw
```

### Update and Repair

```bash
sudo sesame-dvr-update
sudo sesame-dvr-update --preflight-only
sudo sesame-dvr-update --force
sudo sesame-dvr-repair
```

`sesame-dvr-repair` reinstalls the current build from the artifact index and
updates the matching anchor when required. If public access was configured with
`--publish-service`, a regular `sesame-dvr-update` also updates the
installer-managed nginx site and checks a public HLS/DVR segment during its
smoke test.

### Storage Check

```bash
sudo sesame-dvr-storage smoke
sudo sesame-dvr-storage smoke --json
```

### Support Diagnostics

```bash
sudo sesame-dvr-support-diagnostics
sudo sesame-dvr-support-diagnostics --with-onvif-service
```

This command creates a sanitized bundle. The bundle must not include raw
`license.json`, `license-lease.json`, `config.json`, private keys, or archive
data.

## Files and Directories

A typical protected installation uses:

- `/opt/sesame-dvr/current` - current release;
- `/opt/sesame-dvr/releases/<build>` - installed releases;
- `/var/lib/sesame-dvr/config.json` - server configuration;
- `/var/lib/sesame-dvr/sesame-dvr.env` - environment and tokens;
- `/var/lib/sesame-dvr/license.json` - license;
- `/var/lib/sesame-dvr/license-lease.json` - online lease;
- `/var/lib/sesame-dvr/current_anchor.so` - current per-instance anchor;
- `/var/dvr/segments` - legacy/default video archive layout;
- `/var/dvr/previews` - preview cache;
- `/var/dvr/onvif-events` - ONVIF events;
- `/var/dvr/tmp` - installer, update, and debug temporary files;
- `/etc/systemd/system/sesame-dvr.service` - service unit;
- `/etc/nginx/sites-available/sesame-dvr.conf` - nginx site when published.

In MultiVolume mode, archive data can be located under several volume roots.
The URL contains `volume_id`, so after a volume is moved the server can use its
new path once the data is visible to the catalog or audit.

## Security

Recommendations:

- change the default `admin / admin` password immediately;
- treat activation keys and the management token as secrets;
- use HTTPS for public access;
- do not expose `/admin` without a password and token;
- use `authMode=static` or `auth backend` for external playback;
- do not put RTSP passwords in public documents or screenshots;
- restrict SSH access to the server;
- update Sesame DVR and the operating system regularly;
- send only the sanitized support bundle when providing diagnostics.

Pass the playback token for `authMode=static` as follows:

```text
https://dvr.example.com/cam1/live.m3u8?token=<token>
```

## Common Workflows

### Add an RTSP Camera

1. Open `Streams`.
2. Click `New stream`.
3. Set `Name`, `Source`, and `Retention`.
4. Enable `Enabled`.
5. Click `Save`.
6. Click `Enable` or `Start`.
7. Check live playback in the embedded player.
8. After several seconds, check the archive timeline.

### Add ONVIF Events to a Camera

1. Open `ONVIF`.
2. Click `New device` or `Scan RTSP`.
3. Set host, port, path, username, and password.
4. Click `Check`.
5. Click `Check capabilities`.
6. Click `Subscribe` or `Start events`.
7. Confirm that events appear on the device tab and timeline.

If the camera already exists as an RTSP stream, use the shorter workflow: open
the stream in `Streams`, click `ONVIF camera`, wait for automatic discovery or
check the pre-filled form, and then save the device.

### Switch to MultiVolume

1. Connect a new disk and create its root directory.
2. In `Settings -> Storage volumes`, select `MultiVolume`.
3. Add a volume with a unique `ID` and absolute `Root`.
4. Confirm that the volume is online and writable.
5. Select a policy such as `round_robin` or `weighted_round_robin`.
6. Keep `Scope = camera` if the goal is to lose approximately `1/n` of each
   camera's archive when one disk fails.
7. Click `Save storage`.
8. Run `Check` or `Rebuild catalog` for migrated data.
9. Confirm that new segments are written to the expected volumes.

### Remove a Volume from the Write Set

1. Open `Settings -> Storage volumes`.
2. Click `Disable writing` for the volume.
3. Wait for active writes to finish.
4. Confirm that new segments are being written to other writable volumes.
5. The volume can then be disconnected, serviced, or copied.

### Update the Server

1. Open `Settings -> Updates`.
2. Click `Check`.
3. If a new version is available, click `Update`.
4. Follow the update log.
5. After restart, check the server version, license status, and stream state.

CLI alternative:

```bash
sudo sesame-dvr-update
```

### Collect Diagnostics for Support

```bash
sudo sesame-dvr-support-diagnostics --with-onvif-service
```

Send the generated `.tar.gz` bundle. Do not send raw `config.json`,
`license.json`, private keys, or archived video files unless specifically
agreed.

## Troubleshooting

### The Web UI Does Not Open

Check:

```bash
sudo systemctl status sesame-dvr --no-pager
sudo systemctl status nginx --no-pager
sudo nginx -t
curl -sS http://127.0.0.1:3000/api/system/status
```

Also check DNS, the firewall, and open ports `80/443`.

### A Camera Does Not Record an Archive

Check that:

- the stream is enabled and running;
- the RTSP URL is accessible from the server;
- the selected stream's `Logs` tab does not report an error;
- `FFprobe info` succeeds;
- the licensed stream limit is not exceeded;
- the storage volume has free space and is online;
- the journal does not report `archive stale`;
- writing is enabled on the volume.

### Live Works but No Archive Appears

Possible reasons:

- the camera does not send keyframes frequently enough;
- ingest receives the stream, but the writer cannot write to disk;
- the volume is offline, read-only, or full;
- archive root directory permissions are incorrect;
- a catalog or index rebuild is required after files were moved manually.

Actions:

```bash
sudo sesame-dvr-storage smoke
sudo journalctl -u sesame-dvr -n 200 --no-pager
```

Run `Check` or `Rebuild catalog` for the affected volume in the UI.

### WebRTC Does Not Work

Check that:

- the browser supports the camera codec;
- the stream card reports WebRTC readiness;
- no license capability errors are present;
- HLS fallback works;
- the proxy is not blocking the required WHEP endpoint.

HEVC browser support may depend on the operating system and browser. In such a
case, HLS may work even when WebRTC does not.

### HLS Reports `The element has no supported sources`

Check that:

- the playlist opens by URL;
- segment URLs from the playlist return `200 OK` rather than `404`;
- the nginx `/dvr/` alias points to the current archive root;
- `/dvr/v/<volume_id>/...` is available for MultiVolume data;
- segment files actually exist on the volume;
- the catalog was rebuilt after data was moved.

### ONVIF Does Not Work

Check:

- the correct ONVIF host and port;
- `/onvif/device_service` or the vendor-specific path;
- camera username and password;
- camera reachability from the server;
- the capability check;
- whether the camera supports PullPoint events.

Some cameras return an inaccessible address in ONVIF XAddr. In this case, the
server should use the device's actual host and port if that fallback is
supported by the installed version.

### License Is Invalid or the Lease Does Not Renew

Check:

```bash
sudo sesame-dvr-license status
sudo sesame-dvr-license renew
sudo journalctl -u sesame-dvr -n 200 --no-pager | grep -i license
```

Also verify outbound HTTPS access to:

```text
https://license.sesameware.com
https://license-2.sesameware.com
```

### An Update Failed

Check the UI log or run:

```bash
sudo systemctl status sesame-dvr-update --no-pager
sudo journalctl -u sesame-dvr-update -n 300 --no-pager
```

To validate an update without switching versions:

```bash
sudo sesame-dvr-update --preflight-only
```

To reinstall the current build:

```bash
sudo sesame-dvr-repair
```

### A Disk Is Full

Check:

- `Settings -> Storage volumes`;
- `Monitoring -> Disks`;
- camera retention values;
- orphan archives;
- whether the cleaner is disabled;
- whether an offline volume has pending retention cleanup.

When a volume is full, the writer excludes it from new recordings. After space
is freed, it may be necessary to run `Check` or `Rebuild catalog`.

## HTTP Endpoints for Integrations

The complete reference for all HTTP API methods, parameters, and payloads is
available in [sesame-dvr-api.ru.md](./sesame-dvr-api.ru.md) (Russian).

Primary playback endpoints:

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

`/<camera>/motion_events.json` accepts `from` and `to` as Unix seconds and
returns only the requested motion-event interval. On initial load, the embedded
player shows a 12-hour window around the live point and loads events only for
the visible timeline range, avoiding a full scan of ONVIF history.

`/<camera>/recording_status.json` returns Flussonic-compatible archive ranges.
The `from` and `duration` fields in every range are integer Unix seconds. For a
stream where `Record archive` is disabled, the endpoint returns an empty range
list and `archiveEnabled=false`.

`/<camera>/timeline_ranges.json` is used by the embedded player for the archive
timeline. It accepts `from` and `to` in Unix seconds and `bucket` in seconds,
returns only the requested range window, and can aggregate adjacent ranges at a
large scale. For live-only streams, it returns an empty range list and
`archiveEnabled=false`. External integrations should continue to use
`recording_status.json`.

A regular `/<camera>/dvr.m3u8?start=...&end=...` request returns a finite VOD
playlist. A growing archive playlist is enabled only explicitly with
`sliding=1` or the `archive_playlist=sliding` embedded-player parameter. Older
integrations without these parameters retain their existing behavior.

`/<camera>/motion_dvr.m3u8?start=...&end=...` returns archive HLS only for
`motion=true` intervals, while `/<camera>/motion_dvr_map.json` maps media time
to real archive time. The embedded player uses these endpoints for motion-event
playback: video proceeds continuously through motion segments while the UI
timeline remains tied to the actual archive time.

`/<camera>/timelapse.m3u8` returns HLS VOD from materialized timelapse chunks
when timelapse recording is enabled. Providing `start=...&end=...` limits the
playlist to that real archive window. The playback path reads the materialized
manifest; background tasks build chunks and update the manifest while
timelapse is recorded. Files are stored under
`timelapse/<camera>/YYYY/MM/DD/HH/` on the storage volume and are cleaned up
according to the separate timelapse retention setting.

For the administrative `GET /api/onvif/devices/:id/events` API, `page` and
`pageSize` or `before` and `after` load the event list in chunks.
`timelineFrom` and `timelineTo` select a separate `timelineEvents` window,
`timelineCarry=true` includes the final event before the window to restore
state, and `events=false` disables the list page for lightweight timeline-only
requests.

Direct archive/data endpoints:

```text
GET /dvr/<camera>/...
GET /dvr/v/<volume_id>/<camera>/...
```

The admin UI uses the management API, which requires a management token or
session:

```text
GET /api/system/status
GET /api/system/version
GET /api/system/license
POST /api/system/license/renew
GET /api/system/update/status
POST /api/system/update/check
POST /api/system/update/start
POST /api/system/update/hot-patch/start
GET /api/streams
POST /api/streams
PUT /api/streams/<name>
PUT /api/streams/<name>/static-image
DELETE /api/streams/<name>
GET /api/agents
POST /api/agents
POST /api/agents/enroll
GET /api/agents/<id>
PATCH /api/agents/<id>
DELETE /api/agents/<id>
POST /api/agents/<id>/enrollment-password
POST /api/agents/<id>/rotate-secret
POST /api/agents/<id>/revoke
GET /api/agents/<id>/cameras
GET /api/agents/<id>/cameras/<camera_id>/snapshot.jpg
POST /api/agents/<id>/cameras/scan
POST /api/agents/<id>/diagnostics
GET /api/agents/<id>/logs
GET /api/agents/<id>/commands
POST /api/agents/<id>/commands
GET /streamer/api/v3/streams
GET /streamer/api/v3/streams/<name>
PUT /streamer/api/v3/streams/<name>
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

The Flussonic-compatible `/streamer/api/v3/streams` layer uses the same
management token. Accepted authorization headers are
`Authorization: Bearer <token>`, `Authorization: <token>`, and
`X-Management-Token: <token>`. `PUT /streamer/api/v3/streams/<name>` accepts a
Flussonic request body in which `inputs[0].url` becomes `source`, `disabled`
becomes the inverse of `enabled`, `dvr.expiration` in seconds becomes
`retentionDays`, and `on_play.url` sets the global `authBackendUrl` and
`authMode=authBackend` for the stream. Deletion through this compatibility
layer uses only `PUT` with `disabled=true`; Flussonic `DELETE` is intentionally
not implemented.

`stats.bytes_in` in the compatibility API comes from the native ingest input
byte counter. The value becomes `0` if the stream is stopped or no input packets
arrived during the last 30 seconds. The filter
`stats.alive=false&stats.running=true&stats.bytes_in=0` therefore finds running
streams without current camera input traffic. The cumulative counter for the
current process is available as `stats.bytes_in_total`.

For playback with `authMode=static`, add the query token:

```text
?token=<playback-token>
```

Production integrations should use documented UI and API flows and preserve
camera URL, `volume_id`, and management token compatibility.
