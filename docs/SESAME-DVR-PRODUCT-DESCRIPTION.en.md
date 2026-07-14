# Sesame DVR: Product Description

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
- remote live video viewing through a browser;
- archive playback on a timeline;
- accelerated review of long periods using the timelapse archive;
- exporting a selected archive fragment as MP4;
- publishing cameras and archives to customers, operators, or external systems;
- embedding the video player into third-party web applications;
- monitoring cameras, ingest processes, the server, and connected clients;
- exposing OpenMetrics/Prometheus metrics for external monitoring and alerting;
- collecting ONVIF motion events and correlating motion with the video archive;
- storing archives in SingleVolume or MultiVolume mode with per-volume catalogs;
- centralized management of large camera deployments within license and server
  capacity limits.

## Core Features

### Camera Ingest and Recording

Sesame DVR accepts video streams from IP cameras and other sources available via
RTSP or compatible HTTP URLs, local UDP multicast/MPEG-TS streams, and push
streams delivered over RTMP/FLV or SRT/MPEG-TS. Each camera can have its own
name, source, recording parameters, archive retention period, playback
authorization mode, and additional ingest settings.

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
- HLS fallback when WebRTC is unavailable for a particular stream or browser.

This allows Sesame DVR to serve as a live video source for an operator console,
customer portal, or standalone camera page.

### Video Archive

Sesame DVR writes a durable DVR archive to disk as self-contained fMP4 segments
and indexes them for fast access to a requested time range. The archive can be
played through a time-bounded HLS playlist, opened in the web interface, or
used by third-party applications through HTTP endpoints.

For cameras with ONVIF events, the embedded player can play archive video in a
motion-only mode. Intervals without motion events are skipped while the
timeline remains tied to the actual archive time.

When timelapse is enabled for a camera, Sesame DVR stores a separate accelerated
video archive. Raw timelapse frames are used as staging data; a background task
then packages them into HLS/fMP4 chunks and updates the materialized timelapse
manifest. Timelapse playback therefore does not need to rebuild a playlist by
scanning the archive on every request. The player has a separate media duration
for timelapse playback, while the timeline and current-frame label remain tied
to the real archive time.

Each camera can have its own retention period. Old recordings are removed
automatically according to retention settings and license limits.

### Storage and Scaling

A standard installation uses `SingleVolume`; the default archive is located at
`/var/dvr/segments`. Large installations can use `MultiVolume`, where several
storage volumes remain online at the same time and new segments are distributed
using the `single`, `round_robin`, `weighted_round_robin`, or `least_used`
policy.

Each volume has its own catalog and hour indexes. If one volume goes offline,
archive playback shows gaps only for ranges stored on that volume, while the
remaining volumes stay available. Public MultiVolume archive URLs use the
`/dvr/v/<volume_id>/<camera>/...` form. The managed nginx site serves these
segments directly from disk and is maintained by the standard updater.

Archive lookup uses three index levels: `HourIndex`, `CameraIndex`, and
`VolumeIndex`. In the current protected edition, the primary index formats are
`*.hidx` for hourly indexes and `*.cidx` for camera indexes. They are designed
for inexpensive append/patch updates instead of rewriting large `.term`
snapshots in full. Updates propagate asynchronously: committing a segment
updates `HourIndex`, the materializer then updates `CameraIndex`, followed by
`VolumeIndex`.

Segment writes, commits, index updates, and physical deletion all pass through
the shared Native IO / Disk IO scheduler. This provides priorities and per-work
class limits so archive recording remains the highest-priority workload even
during retention, rebuilds, or bulk deletion.

### Archive Export

Users can select an archive interval and export it as an MP4 file. This is useful
for providing footage to a customer, security team, or technical support, or for
preserving evidence of an event.

### Administrator Web Interface

The built-in admin panel allows the system to be managed without editing
configuration files manually. The UI can be used to:

- add, edit, start, stop, enable, and disable streams;
- inspect the state of all cameras and their archives;
- see which streams are recording, running, stopped, or reporting errors;
- open live and archive playback;
- inspect the selected stream log and the global runtime log;
- run ffprobe diagnostics for a camera;
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
motion-only archive playback, and MP4 fragment export.

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
- preview files in `/var/dvr/previews`;
- ONVIF events in `/var/dvr/onvif-events`;
- the application in `/opt/sesame-dvr/current`.

The archive does not require a database. The file system with video segments
and primary `*.hidx` hour indexes remains the source of truth. Derived `*.cidx`
CameraIndex and VolumeIndex data can be rebuilt from HourIndex, while obsolete
legacy `.term` indexes are read only for compatibility during migration.

## Important Limitations

Sesame DVR is not a general-purpose video analytics system. It does not perform
face, license plate, object, or behavioral recognition without external
integrations.

WebRTC playback does not transcode video and therefore depends on the codec
support of the particular stream and browser. HLS playback is available when a
WebRTC scenario is not supported.

Performance and maximum camera count depend on CPU, storage, network, camera
codec profiles, bitrate, archive retention, and the features enabled for the
installation. The protected runtime is designed for `ffmpeg_nif` ingest. The
external CLI `ffmpeg` producer remains available for unprotected/development
scenarios and is not a production fallback in a protected build.
