# Shared Video Wall Playback

Portal uses the actual SesameDVR embed player, not an additional media engine.
Install both the Portal changes and the DVR wall-control assets/controller.
The DVR implementation is based on `origin/elexir-webrtc` (`a0c72904`).

## Behavior

- One UTC clock controls seek, pause/resume, 0.5x, 1x, 2x, 4x, 8x and LIVE.
- The ECO toggle next to LIVE switches all tiles to `economy=idr` for both live
  and archive: keyframes only, without audio or transcoding. Resolution stays
  unchanged; cadence and bandwidth savings depend on the source keyframe interval.
  ECO is off by default and lasts for the current wall view, including fullscreen
  and offscreen tile remounts. It does not change saved camera/DVR settings.
  Switching reconnects embeds with fresh authorization and control channels,
  preserving the shared UTC clock, archive position, pause and speed. A paused
  live wall reconnects only on resume. Switching off restores ordinary playback.
  DVRs must support `economy=idr` and the IDR endpoints; see DVR's
  `docs/idr-playback.ru.md` for deployment requirements. A supporting player does
  not fall back to a full stream when IDR is unavailable. Pre-IDR DVR builds
  must be upgraded before using ECO. No standalone embed menu toggle is added.
- One embed-style timeline shows two independent unions (logical OR): events
  in the upper amber lane, recording ranges in the lower green lane. An interval
  is present when at least one camera reports it. Overlaps and duplicates merge;
  gaps between disjoint intervals remain. There are no per-camera timeline rows
  or separate seek slider. Unknown camera metadata is not treated as a confirmed
  recording gap. Zoom: 1 minute to 24 hours; default: 12 hours. Wheel/pinch zoom,
  drag pans, click/tap seeks. Wheel zoom uses the same delta normalization and
  sensitivity as the DVR embed player, anchored at the cursor; horizontal-only
  scrolling does not zoom. Dates use the browser timezone.
- Camera tiles have no spacing; captions overlay the fitted video image. In
  fullscreen the top navigation is hidden and the lower playback panel overlays
  the wall, without reserving space. It hides after 2.5 seconds of inactivity,
  reappears on pointer/touch activity, and stays visible during control interaction.
  The bottom-edge wake zone works even with older DVR players. Exit via Escape or
  the lower fullscreen button. Ordinary view keeps playback controls visible.
- Each camera has a magnifier toggle, off by default. While off, mouse/touch
  input passes through the iframe so the Portal page scrolls normally. Enabling
  it allows the existing embed wheel/pinch zoom and pan in that camera only.
  Disabling it locks the current framing and restores page scrolling without
  restarting playback. Reloading or remounting a tile starts with zoom controls
  off again. Standalone embed players and the shared timeline are unchanged;
  this toggle requires only a Portal update, not a new DVR control protocol.
- Portal's magnifier and standalone-player link overlay the upper-right corner
  of each image. The camera caption stays at the lower left and reserves space
  for the embed player's sound/fullscreen controls at the lower right, including
  fullscreen and narrow layouts.
- The clock advances independently of buffering cameras. During playback, up to
  10 seconds of lead or lag is tolerated without a synchronization overlay or
  seek. Greater drift must persist in the same direction for 3 seconds; returning
  within tolerance clears that observation period. Drift above 30 seconds skips
  the grace period. State age and the player's reported speed are accounted for
  when comparing clocks. On pause the previous stricter tolerance remains
  (`2 + playback rate` seconds), without a grace period. Manual seeks are immediate.
  Corrections/recovery remain limited to once per 6 seconds per camera; gaps retry
  every 10 seconds unless the running clock enters that camera's next known
  recording range. In that case Portal requests playback on the next 500 ms tick,
  without waiting for the gap retry timer. This is one attempt per entered range;
  paused clocks, missing ranges and repeated failures retain the normal limit.
  A different camera's contribution to the shared timeline does not trigger it.
  This recovery change needs only a Portal update, not new DVR assets/protocol.
  Buffering, errors, archive denial and wrong playback mode are not treated as
  acceptable drift.
  Buffered seeks avoid reloading HLS when the exact UTC target is already mapped
  and buffered. This is time alignment, **not frame-accurate synchronization**.
- The DVR verifies exact recording ranges before opening an archive target.
  It does not snap a missing recording to a later interval. The affected tile
  is covered with a gap/status message; other cameras keep playing.
- Hidden tabs and offscreen iframes are unloaded. A newly visible iframe obtains
  a fresh Portal authorization redirect and joins the current shared position.
  Offscreen rows retain their last ranges until remounted; they may be stale.
- Users with `hide_archive=1` get no archive UI or range requests and `dvr=false`.
  DVR playback metadata and Auth Backend enforce the underlying permission.
  The existing Flussonic `allowed_dvr_ranges` authorization contract is unchanged.
- A legacy DVR still plays live. Archive tiles show an update requirement after
  handshake timeout, never a live picture presented as archive playback.
- All wall players are muted. Open an individual player for camera-specific
  controls. Independent timeline/keyboard controls are disabled in controlled mode.

## Embed Bootstrap

Portal mounts `/video-walls/stream?id=W&camera_id=C&controller_id=NONCE`.
With ECO enabled it adds `&economy=idr`; the redirect forwards this value to
the embed. Omit `economy` for normal playback; other values (including arrays)
return HTTP 400. Authorization and `hide_archive` checks are unchanged.
The endpoint checks session, wall ownership, membership, camera access and DVR
block status. It redirects with the current daily token and these extra fields:

```text
controller_version=1
controller_id=<32 lowercase hexadecimal characters, fresh for each mount>
controller_origin=https://portal.example:5443
hidecontrols=true
dvr=true|false
```

The configured Portal base URL must match its external origin, especially behind
a reverse proxy. The parent expects the origin of the configured DVR server URL.
Do not redirect the embed to a different origin without updating that URL.
Both ends require matching `event.source`, exact `event.origin`, channel and
version. `postMessage` always uses an exact target origin, never `*`.
Tokens and internal error details are not sent through this protocol.

## Messages (Version 1)

Every message is an object with `protocol: "sesame-wall"`, `version: 1`,
`channel: NONCE`, and `type`. Times are Unix seconds, not milliseconds.

| Direction | Type | Additional fields |
| --- | --- | --- |
| Portal to DVR | `hello` | None; asks for capabilities/current state |
| DVR to Portal | `ready` | `archive`: boolean; `events`: boolean capability |
| Portal to DVR | `set` | `revision`, `seek`: nonnegative integers; `mode`: `live` or `archive`; `unix`: positive finite number; `paused`: boolean; `rate`: 0.5, 1, 2, 4, 8 |
| DVR to Portal | `state` | `revision`, `archive`, `mode`, `unix` (actual mapped archive media time, or null), `ready`, `paused`, `rate`, `ended`, `busy`, `error`; optional `videoWidth`, `videoHeight` for caption placement |
| Portal to DVR | `ranges` | `request`: integer; `from`, `to`: nonnegative integer Unix seconds; `0 < to-from <= 86400` |
| DVR to Portal | `ranges` | `request`, `ranges`: array of `{from, duration}`; optional `error` |
| DVR to Portal | `events` | Same `request`, `events`: array of `{from, duration}`; optional `error` |
| DVR to Portal | `activity` | No personal/input data; wakes fullscreen controls (pointer motion throttled to 250 ms) |

Events use the DVR's existing authenticated `motion_events.json` endpoint. Only
`state=motion` intervals contribute to the event union; idle/unavailable intervals
do not become events. Event metadata is sent separately so a failed event query
does not erase recording ranges or prevent playback. Queries use the same abort
scope and request ID; late/superseded results are discarded. `hide_archive` also
prevents event queries. Install updated `app.js`, `wall-control.js` and `styles.css`
on DVRs to enable event reporting, pointer activity forwarding and narrow tiles
without the standalone player's 320px minimum width; older v1 players still
provide recording ranges, but cannot populate the event lane.

`revision` must increase. Increment `seek` for a new target, recovery or mode
change; retain it for pause/rate changes. Pending seeks are serialized and
superseded targets are skipped. Pause applies immediately while media loads.
Reports arrive every 500 ms; stale revisions, channels and range request IDs
are ignored. Range requests are coalesced; normal refresh is every 30 seconds.
Wide timeline ranges may be aggregated by DVR, but seek validation is exact.

Stable error codes: `noRecording`, `archiveDenied`, `rangesError`, `eventsError`, `buffering`.
The Portal localizes them. A `ready` handshake is capability detection, not proof
that video is playing; use actual media state and mapped timestamps.

## Verification

Portal: `php tests/video_walls.php`, `bash tests/http_smoke.sh`,
`node --test tests/video_wall_playback.cjs`.

Cross-origin browser integration with actual DVR player assets and synthetic HLS:
the fixture includes separate full-rate TS and sparse all-keyframe fMP4 media,
and checks live/archive ECO requests, decoded frames, pause/rate preservation,
rapid switching, remounts, fullscreen/mobile controls and no full-stream fallback.

```sh
SESAME_DVR_PLAYER_DIR=/path/to/SesameDVR/priv/player node tests/video_walls_browser.cjs
```

Add `--demo` to keep the isolated fixture running after verification. The test
prints its local URL, fixture-only credentials and an archive timestamp. Stop it
with Ctrl+C to remove its temporary state.

DVR: `node --test test/player/wall_control_test.mjs` and the existing player tests.
Browser fixtures are not evidence of production stream availability or latency.
