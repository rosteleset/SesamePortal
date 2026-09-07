(function (root) {
  'use strict';
  const protocol = 'sesame-wall';
  const clamp = (n, a, b) => Math.max(a, Math.min(b, n));
  function wheelZoomFactor(event) {
    // Match the DVR embed timeline's wheel units and sensitivity.
    const delta = event.deltaY * (event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? 240 : 1);
    return Number.isFinite(delta) ? Math.exp(clamp(delta, -600, 600) * 0.0015) : 1;
  }
  class Clock {
    constructor(now = () => performance.now()) { this.now = now; this.set(Date.now() / 1000, 'live', false, 1); }
    time() { return this.mode === 'live' ? Date.now() / 1000 : this.unix + (this.paused ? 0 : (this.now() - this.anchor) / 1000 * this.rate); }
    set(unix, mode = this.mode, paused = this.paused, rate = this.rate) { Object.assign(this, { unix, mode, paused, rate, anchor: this.now() }); }
  }
  function normalizeRanges(input) {
    return Array.isArray(input) ? input.filter(r => r && Number.isFinite(r.from) && Number.isFinite(r.duration) && Number.isFinite(r.from + r.duration) && r.from >= 0 && r.duration > 0).slice(0, 20000) : [];
  }
  function unionRanges(lists, from = 0, to = Infinity) {
    const sorted = lists.flatMap(list => normalizeRanges(list).map(r => ({ from: Math.max(from, r.from), to: Math.min(to, r.from + r.duration) })))
      .filter(r => r.to > r.from).sort((a, b) => a.from - b.from || a.to - b.to);
    const merged = [];
    for (const range of sorted) {
      const last = merged[merged.length - 1];
      if (last && range.from <= last.from + last.duration) last.duration = Math.max(last.from + last.duration, range.to) - last.from;
      else merged.push({ from: range.from, duration: range.to - range.from });
    }
    return merged;
  }
  function init(screen) {
    const $ = name => screen.querySelector(`[data-wall-${name}]`);
    const labels = JSON.parse($('playback-labels').textContent);
    const clock = new Clock();
    const archive = screen.dataset.wallArchive === '1';
    const items = [...screen.querySelectorAll('[data-wall-frame]')].map(frame => ({
      frame, overlay: frame.parentElement.querySelector('[data-wall-state]'), origin: frame.dataset.wallOrigin,
      tile: frame.closest('.vw-video-tile'), zoomButton: frame.closest('.vw-video-tile').querySelector('[data-wall-camera-zoom]'), zoomEnabled: false,
      visible: false, ready: false, archive: false, state: null, seek: 0, revision: 0,
      ranges: [], events: [], rangesLoaded: false, eventsLoaded: false, eventSupport: false,
      rangeError: null, eventError: null, rangeRequest: 0, lastCorrection: 0,
    }));
    let revision = 0, request = 0, span = 12 * 3600, from = Math.floor(clock.time() - span * 0.8), to = from + span;
    let destroyed = false, timelineWidth = 0, dateDirty = false, rangeTimerPending, controlsTimer;
    let unionDirty = true, recordingUnion = [], eventUnion = [], controlsHovered = false, drag = null, pinch = null;
    const pointers = new Map();
    function setCameraZoom(item, enabled) {
      item.zoomEnabled = enabled;
      item.tile.classList.toggle('vw-zoom-enabled', enabled);
      item.frame.tabIndex = enabled ? 0 : -1;
      item.zoomButton.setAttribute('aria-pressed', String(enabled));
      item.zoomButton.title = labels[enabled ? 'disableCameraZoom' : 'enableCameraZoom'];
    }
    const isFullscreen = () => (document.fullscreenElement || document.webkitFullscreenElement) === screen;
    function showControls() {
      clearTimeout(controlsTimer);
      screen.classList.remove('vw-controls-hidden');
      if (isFullscreen()) controlsTimer = setTimeout(hideControls, 2500);
    }
    function hideControls() {
      const focused = $('controls').contains(document.activeElement) &&
        (document.activeElement.matches('input, select, :focus-visible'));
      if (controlsHovered || focused || drag || pinch) { showControls(); return; }
      screen.classList.toggle('vw-controls-hidden', isFullscreen());
    }
    const dateText = unix => new Date(unix * 1000).toLocaleString();
    const dateInput = unix => { const d = new Date(unix * 1000); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 19); };
    function positionCaption(item) {
      const stage = item.frame.parentElement, w = stage.clientWidth, h = stage.clientHeight;
      const aspect = item.aspect || 16 / 9;
      stage.parentElement.style.setProperty('--wall-caption-x', `${Math.max(0, (w - Math.min(w, h * aspect)) / 2) + 8}px`);
      stage.parentElement.style.setProperty('--wall-caption-y', `${Math.max(0, (h - Math.min(h, w / aspect)) / 2) + 8}px`);
    }
    function send(item, type, payload = {}) {
      if (item.channel && item.frame.hasAttribute('src')) item.frame.contentWindow.postMessage({ protocol, version: 1, channel: item.channel, type, ...payload }, item.origin);
    }
    function command(item, seek = false) {
      if (!item.ready) return;
      if (seek) { item.seek++; item.state = null; item.lastCorrection = performance.now(); }
      item.revision = ++revision;
      send(item, 'set', { revision: item.revision, seek: item.seek, mode: clock.mode, unix: clock.time(), paused: clock.paused, rate: clock.mode === 'live' ? 1 : clock.rate });
    }
    function ranges(item) {
      if (!archive || !item.ready || !item.archive) return;
      item.rangeRequest = ++request;
      item.rangePending = true;
      item.rangeStarted = performance.now();
      send(item, 'ranges', { request: item.rangeRequest, from, to });
    }
    function windowAt(center, newSpan = span) {
      span = clamp(Math.round(newSpan), 60, 86400);
      from = Math.max(0, Math.floor(center - span / 2)); to = from + span;
      items.forEach(item => { item.rangesLoaded = false; item.eventsLoaded = false; item.rangeRequest = ++request; item.rangePending = false; });
      clearTimeout(rangeTimerPending);
      rangeTimerPending = setTimeout(() => items.forEach(ranges), 150);
      unionDirty = true;
      draw();
    }
    function seek(unix) {
      if (!archive || !Number.isFinite(unix)) return;
      unix = clamp(unix, 1, Date.now() / 1000);
      clock.set(unix, 'archive');
      if (unix < from || unix > to) windowAt(unix);
      items.forEach(item => command(item, true));
      render();
    }
    function mount(item) {
      if (item.frame.hasAttribute('src')) return;
      item.channel = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');
      item.ready = false; item.state = null; item.started = performance.now();
      const url = new URL(item.frame.dataset.src, location.href);
      url.searchParams.set('controller_id', item.channel);
      item.frame.src = url.href;
    }
    function unmount(item) {
      clearTimeout(item.timer); item.timer = null;
      setCameraZoom(item, false);
      item.frame.removeAttribute('src'); item.channel = null; item.ready = false; item.state = null;
    }
    function visibility() {
      items.forEach((item, i) => {
        if (document.hidden || !item.visible || destroyed || (clock.paused && clock.mode === 'live' && !item.ready)) unmount(item);
        else if (!item.frame.hasAttribute('src') && !item.timer) item.timer = setTimeout(() => { item.timer = null; mount(item); }, i * 100);
      });
    }
    function receive(event) {
      const m = event.data;
      if (!m || m.protocol !== protocol || m.version !== 1) return;
      const item = items.find(i => i.channel && i.channel === m.channel && i.origin === event.origin && i.frame.contentWindow === event.source);
      if (!item) return;
      if (m.type === 'activity') { showControls(); return; }
      if (m.type === 'ready') {
        const initial = !item.ready;
        item.ready = true; item.archive = m.archive === true; item.eventSupport = m.events === true;
        if (initial) { command(item, true); ranges(item); }
      } else if (m.type === 'state' && m.revision === item.revision) {
        item.state = m; item.archive = m.archive === true; item.received = performance.now();
        if (Number.isFinite(m.videoWidth) && Number.isFinite(m.videoHeight) && m.videoWidth > 0 && m.videoHeight > 0 && m.videoWidth <= 32768 && m.videoHeight <= 32768) item.aspect = m.videoWidth / m.videoHeight;
        positionCaption(item);
        if (!item.archive) { item.ranges = []; item.events = []; item.rangesLoaded = false; item.eventsLoaded = false; unionDirty = true; }
      } else if (m.type === 'ranges' && m.request === item.rangeRequest) {
        item.rangePending = false;
        item.ranges = m.error ? [] : normalizeRanges(m.ranges);
        item.rangesLoaded = !m.error;
        item.rangeError = m.error ? (m.error === 'archiveDenied' ? 'archiveDenied' : 'rangesError') : null;
        unionDirty = true;
      } else if (m.type === 'events' && m.request === item.rangeRequest) {
        item.events = m.error ? [] : normalizeRanges(m.events);
        item.eventsLoaded = !m.error;
        item.eventError = m.error ? 'rangesError' : null;
        unionDirty = true;
      }
      render();
    }
    function status(item) {
      if (!item.channel && clock.paused) return 'paused';
      if (!item.ready) return performance.now() - item.started > 8000 ? 'updateDvr' : 'connecting';
      if (clock.mode === 'live' && item.state && (item.state.error || !item.state.ready || item.state.mode !== 'live')) return 'buffering';
      if (clock.mode === 'archive') {
        if (!item.archive) return 'archiveDenied';
        const s = item.state;
        if (!s) return 'syncing';
        if (s.error && Object.hasOwn(labels, s.error)) return s.error;
        if (!s.ready || s.busy || s.ended) return 'buffering';
        if (s.mode !== 'archive' || !Number.isFinite(s.unix) || Math.abs(s.unix - clock.time()) > 2 + clock.rate) return 'syncing';
        if (!clock.paused && s.paused) return 'buffering';
      }
      return null;
    }
    function render() {
      $('clock').textContent = dateText(clock.time());
      const label = labels[clock.paused ? 'play' : 'pause'];
      $('play').title = label; $('play').setAttribute('aria-label', label);
      $('play').setAttribute('aria-pressed', String(!clock.paused));
      $('play-icon').hidden = clock.paused;
      $('resume-icon').hidden = !clock.paused;
      $('live').setAttribute('aria-pressed', String(clock.mode === 'live'));
      items.forEach(item => {
        const key = status(item);
        item.overlay.hidden = !key;
        item.overlay.textContent = labels[key] || '';
        // Live from a legacy DVR must not masquerade as archive playback.
        item.overlay.classList.toggle('vw-state-blocking', clock.mode === 'archive' && !!key);
      });
      if (archive) {
        $('speed').disabled = clock.mode === 'live';
        if (!dateDirty && document.activeElement !== $('date')) $('date').value = dateInput(clock.time());
        $('timeline').setAttribute('aria-valuemin', from); $('timeline').setAttribute('aria-valuemax', to);
        $('timeline').setAttribute('aria-valuenow', clamp(clock.time(), from, to));
        $('timeline').setAttribute('aria-valuetext', dateText(clock.time()));
        const loaded = items.filter(i => i.rangesLoaded).length;
        const error = items.find(i => i.rangeError || i.eventError);
        const message = error ? labels[error.rangeError || error.eventError] : `${labels.timeline}: ${loaded} / ${items.length}`;
        $('archive-status').textContent = message;
        $('controls').title = error ? message : items.some(i => i.ready && i.archive && !i.eventSupport) ? labels.updateDvr : '';
        $('timeline').setAttribute('aria-description', [message, $('controls').title].filter(Boolean).join('. '));
      }
    }
    function draw() {
      if (!archive) return;
      const canvas = $('timeline'), width = canvas.parentElement.clientWidth;
      const height = 86, dpr = window.devicePixelRatio || 1;
      if (!width) return;
      timelineWidth = width;
      if (canvas.width !== Math.round(width * dpr) || canvas.height !== Math.round(height * dpr)) {
        canvas.width = Math.round(width * dpr); canvas.height = Math.round(height * dpr);
        canvas.style.height = `${height}px`;
      }
      const ctx = canvas.getContext('2d'); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, width, height);
      const left = 1, track = width - 2;
      const x = unix => left + (unix - from) / span * track;
      if (unionDirty) {
        recordingUnion = unionRanges(items.map(item => item.ranges), from, to);
        eventUnion = unionRanges(items.map(item => item.events), from, to);
        unionDirty = false;
      }
      const gradient = ctx.createLinearGradient(0, 0, 0, 58);
      gradient.addColorStop(0, '#202328'); gradient.addColorStop(1, '#111318');
      ctx.fillStyle = gradient; ctx.strokeStyle = '#ffffff26';
      ctx.beginPath(); ctx.roundRect(0.5, 0.5, width - 1, 57, 5); ctx.fill(); ctx.stroke();
      ctx.fillStyle = '#ffffff1c'; ctx.fillRect(left, 29, track, 1);
      // Unknown cameras never turn an unreported interval into a confirmed gap.
      ctx.fillStyle = items.length && items.every(i => i.rangesLoaded) ? '#843e44' : '#505966'; ctx.fillRect(left, 38, track, 10);
      ctx.fillStyle = '#474e58'; ctx.fillRect(left, 9, track, 14);
      const now = Date.now() / 1000;
      if (to > now) { ctx.fillStyle = '#505660'; ctx.fillRect(x(Math.max(from, now)), 38, (to - Math.max(from, now)) / span * track, 10); }
      for (const [list, y, h, color] of [[recordingUnion, 38, 10, '#22b573'], [eventUnion, 9, 14, '#c58a25']]) {
        ctx.fillStyle = color;
        list.forEach(r => ctx.fillRect(x(r.from), y, Math.max(1, r.duration / span * track), h));
      }
      const interval = [1, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 14400, 21600, 43200].find(n => n >= span / Math.max(width / 100, 1)) || 86400;
      ctx.font = '11px system-ui'; ctx.textBaseline = 'middle';
      for (let unix = Math.ceil(from / (interval / 5)) * (interval / 5); unix <= to; unix += interval / 5) {
        const pos = x(unix), major = Math.abs(unix / interval - Math.round(unix / interval)) < 0.001;
        ctx.fillStyle = '#9c7d42'; ctx.fillRect(pos, 63, 1, major ? 7 : 4);
        if (major) {
          ctx.fillStyle = '#b0b7c2'; ctx.textAlign = pos < 40 ? 'left' : pos > width - 40 ? 'right' : 'center';
          ctx.fillText(new Date(unix * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', ...(interval < 60 ? { second: '2-digit' } : {}) }), pos, 79);
        }
      }
      const cursor = clock.time();
      if (cursor >= from && cursor <= to) {
        ctx.fillStyle = '#20d67a'; ctx.fillRect(x(cursor) - 1, 44, 2, 10);
        ctx.beginPath(); ctx.arc(x(cursor), 43, 6, 0, Math.PI * 2); ctx.fill();
        ctx.strokeStyle = '#f7fff9'; ctx.lineWidth = 2; ctx.stroke();
      }
    }
    function tick() {
      items.forEach(item => {
        if (!item.channel) return;
        if (item.rangePending && performance.now() - item.rangeStarted > 12000) { item.rangeError = 'rangesError'; item.rangePending = false; }
        if (!item.ready) { send(item, 'hello'); return; }
        if (clock.mode !== 'archive' || !item.archive) return;
        const s = item.state, now = performance.now();
        const stale = !s || now - item.received > 3000;
        const drift = Number.isFinite(s?.unix) ? Math.abs(s.unix - clock.time()) : Infinity;
        // Bound recovery traffic even for offline cameras and permanent gaps.
        const cooldown = s?.error === 'noRecording' ? 10000 : 6000;
        if (now - item.lastCorrection > cooldown && (stale || s?.error || s?.ended || (!s?.busy && drift > 2 + clock.rate))) command(item, true);
      });
      render();
      draw();
    }
    $('play').addEventListener('click', () => {
      clock.set(clock.time(), clock.mode, !clock.paused);
      items.forEach(item => { command(item); if (!item.ready && clock.paused) item.frame.removeAttribute('src'); });
      if (!clock.paused) visibility();
      render();
    });
    $('live').addEventListener('click', () => {
      clock.set(Date.now() / 1000, 'live', false, 1);
      if (archive) { $('speed').value = '1'; windowAt(clock.time() - span * 0.3); }
      items.forEach(item => command(item, true)); visibility(); render();
    });
    if (archive) {
      $('date').addEventListener('input', () => { dateDirty = true; });
      $('jump').addEventListener('submit', event => { event.preventDefault(); const unix = new Date($('date').value).getTime() / 1000; dateDirty = false; seek(unix); });
      $('speed').addEventListener('change', () => { clock.set(clock.time(), clock.mode, clock.paused, Number($('speed').value)); items.forEach(i => command(i)); });
      const timeline = $('timeline');
      const ratio = clientX => clamp((clientX - timeline.getBoundingClientRect().left - 1) / Math.max(1, timelineWidth - 2), 0, 1);
      timeline.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        timeline.setPointerCapture(event.pointerId); pointers.set(event.pointerId, event.clientX);
        if (pointers.size === 2) {
          const [a, b] = [...pointers.values()];
          pinch = { distance: Math.max(1, Math.abs(a - b)), span, unix: from + ratio((a + b) / 2) * span };
          drag = null;
        } else drag = { id: event.pointerId, x: event.clientX, from, span, moved: false };
        showControls(); event.preventDefault();
      });
      timeline.addEventListener('pointermove', event => {
        if (pointers.has(event.pointerId)) pointers.set(event.pointerId, event.clientX);
        if (pinch && pointers.size === 2) {
          const [a, b] = [...pointers.values()], newSpan = clamp(pinch.span * pinch.distance / Math.max(1, Math.abs(a - b)), 60, 86400);
          windowAt(pinch.unix + (0.5 - ratio((a + b) / 2)) * newSpan, newSpan);
        } else if (drag?.id === event.pointerId) {
          if (Math.abs(event.clientX - drag.x) > 4) drag.moved = true;
          if (drag.moved) windowAt(drag.from + drag.span / 2 - (event.clientX - drag.x) / Math.max(1, timelineWidth - 2) * drag.span, drag.span);
        }
        const pos = ratio(event.clientX);
        $('tooltip').hidden = false;
        $('tooltip').textContent = dateText(from + pos * span);
        const half = Math.min($('tooltip').offsetWidth / 2, timelineWidth / 2);
        $('tooltip').style.left = `${clamp(pos * timelineWidth, half, timelineWidth - half)}px`;
      });
      const release = event => {
        if (event.type === 'pointerup' && drag?.id === event.pointerId && !drag.moved) seek(from + ratio(event.clientX) * span);
        pointers.delete(event.pointerId); drag = null;
        if (!pointers.size) pinch = null;
        $('tooltip').hidden = true;
        showControls();
      };
      timeline.addEventListener('pointerup', release);
      timeline.addEventListener('pointercancel', release);
      timeline.addEventListener('pointerleave', () => { $('tooltip').hidden = true; });
      timeline.addEventListener('wheel', event => {
        event.preventDefault();
        const newSpan = clamp(Math.round(span * wheelZoomFactor(event)), 60, 86400);
        if (newSpan === span) return;
        const at = ratio(event.clientX), anchor = from + at * span;
        windowAt(anchor + (0.5 - at) * newSpan, newSpan);
      }, { passive: false });
      timeline.addEventListener('keydown', event => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') seek(clock.time() + (event.key === 'ArrowLeft' ? -1 : 1) * (event.shiftKey ? 60 : 5));
        else if (event.key === 'Home') seek(from);
        else if (event.key === 'End') seek(to);
        else if (event.key === '+' || event.key === '-') windowAt((from + to) / 2, span * (event.key === '+' ? 0.5 : 2));
        else return;
        event.preventDefault();
      });
      screen.querySelectorAll('[data-wall-timeline-action]').forEach(button => button.addEventListener('click', () => {
        const action = button.dataset.wallTimelineAction, center = (from + to) / 2;
        if (action === 'zoomIn' || action === 'zoomOut') windowAt(center, span * (action === 'zoomIn' ? 0.5 : 2));
        else windowAt(center + (action === 'previousWindow' ? -1 : 1) * span * 0.8);
      }));
    }
    const fullscreen = screen.requestFullscreen || screen.webkitRequestFullscreen;
    screen.querySelectorAll('[data-wall-fullscreen]').forEach(full => {
      full.hidden = !fullscreen;
      full.addEventListener('click', async () => {
        try {
          if (isFullscreen()) await (document.exitFullscreen || document.webkitExitFullscreen).call(document);
          else await fullscreen.call(screen);
        } catch (_) { full.blur(); }
      });
    });
    items.forEach(item => {
      setCameraZoom(item, false);
      item.zoomButton.disabled = false;
      item.zoomButton.addEventListener('click', () => { setCameraZoom(item, !item.zoomEnabled); showControls(); });
    });
    document.addEventListener('fullscreenchange', showControls);
    document.addEventListener('webkitfullscreenchange', showControls);
    screen.addEventListener('pointermove', showControls);
    screen.addEventListener('pointerdown', showControls);
    screen.addEventListener('focusin', showControls);
    $('controls').addEventListener('pointerenter', event => { controlsHovered = event.pointerType === 'mouse'; showControls(); });
    $('controls').addEventListener('pointerleave', () => { controlsHovered = false; showControls(); });
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => { const item = items.find(i => i.frame === entry.target); item.visible = entry.isIntersecting; }); visibility();
    }, { rootMargin: '100px' });
    items.forEach(item => observer.observe(item.frame));
    const resize = new ResizeObserver(() => { items.forEach(positionCaption); draw(); });
    resize.observe(screen.querySelector('.vw-video-grid'));
    if (archive) resize.observe($('timeline').parentElement);
    window.addEventListener('message', receive);
    document.addEventListener('visibilitychange', visibility);
    const timer = setInterval(tick, 500), rangeTimer = setInterval(() => {
      if (clock.mode === 'live') windowAt(clock.time() - span * 0.3);
      else items.forEach(ranges);
    }, 30000);
    window.addEventListener('pagehide', () => { destroyed = true; visibility(); });
    window.addEventListener('pageshow', () => { destroyed = false; visibility(); });
    render();
    return { clock, seek, close() { clearTimeout(controlsTimer); clearTimeout(rangeTimerPending); clearInterval(timer); clearInterval(rangeTimer); observer.disconnect(); resize.disconnect(); window.removeEventListener('message', receive); document.removeEventListener('visibilitychange', visibility); document.removeEventListener('fullscreenchange', showControls); document.removeEventListener('webkitfullscreenchange', showControls); destroyed = true; visibility(); } };
  }
  root.SesameVideoWallPlayback = { init, Clock, normalizeRanges, unionRanges, wheelZoomFactor };
})(typeof window === 'undefined' ? globalThis : window);
