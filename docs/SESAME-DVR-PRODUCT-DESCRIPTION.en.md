# Sesame DVR: Product Description

[Русская версия](SESAME-DVR-PRODUCT-DESCRIPTION.ru.md)

Sesame DVR is a server-side video recording and viewing system for IP cameras.
The product is installed on a Linux server, connects to cameras and other video
sources over RTSP/HTTP URLs and UDP multicast, accepts push delivery over
RTMP/SRT, maintains a video archive, serves live video, and provides a web
interface for stream management, archive playback, and system diagnostics.

Sesame DVR can be used as a lightweight DVR/NVR server at sites that require
centralized video ingest, recording, fast time-based search, and access to live
or archived video through a browser, API, or embedded player.

## Use Cases

- recording video from IP cameras, RTSP/HTTP sources, UDP multicast, and push
  streams;
- SRT/MPEG-TS delivery from an Edge Agent or external publisher to Sesame DVR;
- ingesting HLS and UDP multicast without unnecessary transcoding, as well as
  looped local video files and static images for live publishing;
- remote live video viewing through a browser;
- archive playback on a timeline;
- accelerated review of long periods using the timelapse archive;
- exporting a selected archive fragment as MP4;
- storing and playing encrypted archives, including end-to-end encryption at
  the Edge Agent;
- publishing cameras and archives to customers, operators, or external systems;
- embedding the video player into third-party web applications;
- monitoring cameras, ingest processes, the server, and connected clients;
- exposing OpenMetrics/Prometheus metrics for external monitoring and alerting;
- collecting ONVIF motion events and correlating motion with the video archive;
- retaining only archive intervals protected by events;
- storing archives in SingleVolume or MultiVolume mode with per-volume catalogs;
- DVR server redundancy with automatic backup recording and recovery of missing
  archive intervals;
- centralized management of large camera deployments within license and server
  capacity limits.

## Core Features

### Camera Ingest and Recording

Sesame DVR accepts video from IP cameras and other sources over RTSP or a
compatible HTTP URL, local UDP multicast/MPEG-TS, and push delivery over
RTMP/FLV or SRT/MPEG-TS. It supports direct ingest, HLS and UDP multicast
passthrough, uploaded looped video files, and static JPEG sources.

Each stream can configure its source, TCP/UDP transport for RTSP, archive and
retention settings, audio mode (`copy`, disabled, or AAC transcoding), WebRTC,
playback authorization, and additional ingest options. An ordered list of
fallback sources can also be configured. Sesame DVR automatically switches to
the next URL when input or video disappears from the active source.

For UDP multicast sources, set `sourceType=udp_multicast` and use a source such
as `udp://@239.10.10.10:5000?localaddr=192.168.0.1...` to explicitly select the
network interface used to join the multicast group.

For sites behind NAT or on unstable networks, Sesame DVR Edge Agent can read a
local camera over RTSP/ONVIF and deliver the stream to the central DVR over
SRT/MPEG-TS. Sesame DVR handles these cameras as regular `sourceType=push`
streams, records them, and provides live and archive playback through HLS,
WebRTC, and the embedded player. SRT uses one shared UDP port and routes streams
by `streamid=sesame:stream=<streamName>`, so a separate public port is not
required for every camera.

Compatible direct-ingest streams can simultaneously publish an additional
MPEG-TS UDP multicast output. It uses its own bounded queue, so a failure of the
extra relay does not stop archive recording, Live HLS, or WebRTC.

The service starts enabled cameras automatically, monitors their runtime state,
and can restart a stream when archive recording stops advancing. In the
protected edition, the primary ingest path is the native `ffmpeg_nif`, which
writes self-initializing fMP4 segment files directly to the selected storage
volume without a separate operating-system `ffmpeg` process for every
compatible stream.

### Live Viewing

Browser playback options include:

- live HLS streams;
- native WebRTC/WHEP for H.264 and browser-supported HEVC;
- optional G.711 audio passthrough for compatible streams;
- a multi-bitrate HLS master playlist for preconfigured variants of one stream;
- HLS fallback when WebRTC is unavailable for a particular stream or browser.

This allows Sesame DVR to serve as a live video source for an operator console,
customer portal, or standalone camera page.

WebRTC can be enabled or disabled independently for each stream. When long-term
recording is not required, Sesame DVR keeps only a short bounded live buffer for
Live HLS and other live use cases. Depending on configuration, the buffer can
reside on disk or in memory; this does not change the playback contract.

### Video Archive

Sesame DVR writes a durable DVR archive to disk as self-contained fMP4 segments
and indexes them for fast access to a requested time range. The archive can be
played through a time-bounded HLS playlist, opened in the web interface, or
used by third-party applications through HTTP endpoints.

For cameras with ONVIF events, the embedded player can play archive video in a
motion-only mode. Intervals without motion events are skipped while the
timeline remains tied to the actual archive time.

A separate event archive retention mode can retain only intervals protected by
events, with configurable padding before and after an event, maximum age, total
duration, and byte quota. Regular and event-based retention use the same current
archive map and do not require scanning the file system.

When timelapse is enabled for a camera, Sesame DVR stores a separate accelerated
video archive. Raw timelapse frames are used as staging data; a background task
then packages them into HLS/fMP4 chunks and updates the materialized timelapse
manifest. Timelapse playback therefore does not need to rebuild a playlist by
scanning the archive on every request. The player has a separate media duration
for timelapse playback, while the timeline and current-frame label remain tied
to the real archive time.

Each camera can have its own retention period. Old recordings are removed
automatically according to retention settings and license limits.

Native ingest can also create periodic MP4 and JPEG previews. For archived
cameras, previews are stored next to the hourly data on the corresponding volume
and follow the same retention policy. For live-buffer streams, the server keeps
only the latest MP4 and JPEG previews and replaces them with newer ones.

### Storage and Scaling

A standard installation uses `SingleVolume`; the default archive is located at
`/var/dvr/segments`. Large installations can use `MultiVolume`, where several
storage volumes remain online at the same time and new segments are distributed
using the `single`, `round_robin`, `weighted_round_robin`, or `least_used`
policy.

Each volume has its own compact archive and preview indexes. If one volume goes
offline, archive playback shows gaps only for ranges stored on that volume,
while the remaining volumes stay available. Public MultiVolume archive URLs use
the `/dvr/v/<volume_id>/<camera>/...` form. The managed nginx site serves these
segments directly from disk and is maintained by the standard updater.

During normal operation, Sesame DVR trusts its indexes and does not locate media
by enumerating directories. Current state is updated as media is created or
deleted, and background native processes persist it to disk. A full file-system
scan is reserved for explicitly requested repair or recovery operations.

Segment writes, commits, index updates, and physical deletion all pass through
the shared Native IO / Disk IO scheduler. This provides priorities and per-work
class limits so archive recording remains the highest-priority workload even
during retention, rebuilds, or bulk deletion.

### Archive Export

Users can select an archive interval and export it as an MP4 file. This is useful
for providing footage to a customer, security team, or technical support, or for
preserving evidence of an event.

### Encrypted Streams

With the `encryption` license feature, Sesame DVR can store Live HLS and archive
video as SAMPLE-AES/CBCS `1:9`. The owner's key pair is generated in the browser:
the server stores only the public key, while the private key is offered to the
user as a download and is never stored in Sesame DVR. Losing the private key
means losing access to the encrypted archive.

Two recording modes are supported:

- Sesame DVR receives a clear stream, generates content encryption keys on a
  configurable rotation schedule, and encrypts H.264/H.265 CMAF before storage;
- a trusted Edge Agent next to the camera receives RTSP, packages and encrypts
  CMAF without transcoding, and sends it over SRT together with key identifiers
  and wrapped keys. While receiving, recording, and storing such a passthrough
  stream, Sesame DVR never receives clear video, clear content keys, or the
  owner's private key.

In compatible Chromium clients, the embedded player unwraps content keys locally
and uses ClearKey/EME without sending clear keys to the server. Safari uses an
explicitly confirmed compatibility mode: the browser temporarily supplies only
the keys required by a short-lived playback session, and Sesame DVR decrypts the
requested fragments in memory. The same confirmed mechanism is used to export a
selected encrypted interval as a regular MP4 file. Temporary keys and clear
fragments are not written to disk or shared cache.

WebRTC remains a separate live channel and can be disabled per stream. It is not
available for end-to-end encrypted Edge Agent passthrough because Sesame DVR has
no clear video stream. Content-key records that are no longer referenced are
removed as the associated archive expires under retention.

### Redundancy and Archive Recovery

With the `dvr_failover` feature, two Sesame DVR servers can be linked as Master
and Backup. The Backup monitors Master freshness and starts recording after a
sustained failure. An optional short hot buffer can preserve several minutes
before the failure is detected.

After recovery, the Master can download missing segments from the Backup, verify
the ranges already present, and acknowledge a successful transfer. The Backup
removes acknowledged data after a grace period and applies its own age and byte
limits. The API also exposes health, manual start and stop, repair, and dry-run
stream placement planning across nodes.

### Administrator Web Interface

The built-in admin panel allows the system to be managed without editing
configuration files manually. The UI can be used to:

- add, edit, start, stop, enable, and disable streams;
- inspect the state of all cameras and their archives;
- see which streams are recording, running, stopped, or reporting errors;
- open live and archive playback;
- inspect the selected stream log and the global runtime log;
- run ffprobe diagnostics for a camera;
- configure RTSP transport, fallback sources, audio, WebRTC, timelapse, event
  retention, archive volume, and stream encryption;
- manage playback authorization;
- inspect active playback client users, active WebRTC clients, and recent HLS
  clients;
- work with ONVIF devices and motion events;
- manage storage volumes and run catalog audits and rebuilds;
- view the server health dashboard.

The initial credentials are `admin / admin`; the interface requires the
administrator to change this temporary password after the first sign-in.

### ONVIF and Motion Events

Sesame DVR can discover ONVIF devices from RTSP sources, test device
availability, store the ONVIF camera list, and collect PullPoint events. An
ONVIF device can be added from a selected RTSP stream: the system attempts to
find an endpoint for that camera and asks for confirmation before writing it to
the configuration.

Motion events are stored separately from the video archive and displayed in the
UI on the same timeline as available archive intervals. This makes it easier to
locate moments where motion was detected.

### Embedded Player and API

Each camera has an embedded player that can be integrated into a third-party
interface. It supports live video, a DVR timeline, seeking to archive time,
motion-only archive playback, timelapse, MP4 range selection and export,
current-frame capture, mouse/touch zoom, and private-key input for encrypted
streams.

HTTP endpoints are also available for live HLS, archive HLS playlists, MP4
export, previews, recording status, WebRTC/WHEP, and the management API. This
allows Sesame DVR to integrate with customer portals, internal monitoring
systems, billing platforms, CRM systems, and industry-specific applications.

### Monitoring and Diagnostics

The dashboard displays the current server state and metric history. It includes
CPU, memory, disk, network, ingest load, stream status, process, client session,
and log information.

External monitoring systems can scrape the `/metrics` endpoint in OpenMetrics
format. Endpoint authorization is configured independently from the admin UI
and playback tokens and supports `disabled`, `none`, `bearer`, and `basic`
modes. This makes it possible to connect Prometheus, Grafana, and alert rules
without giving the monitoring system a management token.

For large installations, the dashboard also exposes the state of Native IO
Gateway, RawFileIO, Disk IO pressure, DeleteQueue, materializer backlog,
materialization lag, cache hit rate, and BEAM scheduler pools. Expensive
diagnostic sections are loaded manually through a debug snapshot so regular
dashboard refreshes do not create additional production load.

This information helps an administrator quickly determine whether a problem is
caused by a camera, network access, disk, CPU, ffmpeg/ingest, or overall server
load.

### Public Access

The installer can publish Sesame DVR through nginx. The application continues
to listen internally on `127.0.0.1:3000`, while nginx accepts external HTTP or
HTTPS requests, proxies the web interface and API, and serves DVR segments
directly from disk.

When a domain is provided, the installer can issue a Let's Encrypt certificate
through ACME and enable HTTPS. If the server already has a custom nginx
configuration, the installer does not overwrite unrelated sites unless an
explicit Sesame DVR domain is provided.

### Licensing and Updates

A protected installation is activated with a license key through the license
server. The license is bound to the server and defines limits such as the number
of cameras, expiration date, maximum retention, and maximum number of active
playback client users. A playback client user is identified by the combination
of the client IP address and `User-Agent`; the limit applies to both HLS and
WebRTC access.
Additional capabilities, including encrypted archives and DVR failover, are
enabled by separate license features.

Use the standard command to update an installed system:

```bash
sudo sesame-dvr-update
```

The updater downloads a published protected artifact and applies it without
building the project from source on the customer's server.

For a narrow class of BEAM-only fixes, Sesame DVR can publish a separate
`beam_hot_patch` artifact. It can be applied without restarting
`sesame-dvr.service`, but only to explicitly allowed modules and only when the
current `buildId` matches the manifest `fromBuildId`. A full release update
remains the primary and mandatory path for NIF/native code, frontend assets,
systemd or installer changes, launch configuration, and state migrations.

## Typical Workflow

1. The administrator prepares a Linux server and domain.
2. The administrator runs the installation command with a license key.
3. The installer sets up dependencies, the service, nginx publishing, and
   license activation.
4. The administrator opens the admin UI, changes the password, and adds cameras.
5. Sesame DVR starts recording and serving live video.
6. Operators or external systems use the web interface, embedded player, or API
   for live viewing, archive playback, and MP4 export.

## Where Sesame DVR Is Most Useful

- sites with IP cameras that require a centralized video archive;
- integrators who need to deploy a DVR server quickly at a customer site;
- customer portals where video must be available in a browser;
- monitoring systems for buildings, construction sites, warehouses, offices,
  factories, and remote locations;
- projects that need HTTP/API access to live and archived video without a
  heavyweight VMS platform;
- installations that require controlled archive retention with automatic
  cleanup of old recordings.

## Operating Model

Sesame DVR runs as a `systemd` service. Its main data is stored in the file
system:

- configuration and license state in `/var/lib/sesame-dvr`;
- the default video archive in `/var/dvr/segments`;
- additional archive volumes under the roots configured for storage volumes;
- preview files next to the camera's hourly data on the corresponding archive or
  disk live-buffer volume, or in the native memory live buffer;
- ONVIF events in `/var/dvr/onvif-events`;
- the application in `/opt/sesame-dvr/current`.

The archive does not require a database. Sesame DVR maintains the current map of
segments, previews, and encryption-key references in the native runtime and
persists compact indexes next to each volume's data. Repairing from media files
is a separate administrative operation, not part of the normal read path.

## Important Limitations

Sesame DVR is not a general-purpose video analytics system. It does not perform
face, license plate, object, or behavioral recognition without external
integrations.

WebRTC playback does not transcode video and therefore depends on the codec
support of the particular stream and browser. HLS playback is available when a
WebRTC scenario is not supported.

Encrypted H.265 support depends on the media capabilities of the browser and
operating system. Chromium uses ClearKey/EME, while Safari requires an explicitly
confirmed temporary server-decryption mode. Sesame DVR has no recovery copy of
the owner's private key.

Performance and maximum camera count depend on CPU, storage, network, camera
codec profiles, bitrate, archive retention, and the features enabled for the
installation. The protected runtime is designed for `ffmpeg_nif` ingest. The
external CLI `ffmpeg` producer remains available for unprotected/development
scenarios and is not a production fallback in a protected build.
