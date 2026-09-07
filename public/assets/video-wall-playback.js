(function (root) {
  'use strict';
  const protocol = 'sesame-wall';
  const clamp = (n, a, b) => Math.max(a, Math.min(b, n));
  class Clock {
    constructor(now = () => performance.now()) { this.now = now; this.set(Date.now() / 1000, 'live', false, 1); }
    time() { return this.mode === 'live' ? Date.now() / 1000 : this.unix + (this.paused ? 0 : (this.now() - this.anchor) / 1000 * this.rate); }
    set(unix, mode = this.mode, paused = this.paused, rate = this.rate) { Object.assign(this, { unix, mode, paused, rate, anchor: this.now() }); }
  }
  function normalizeRanges(input) {
    return Array.isArray(input) ? input.filter(r => r && Number.isFinite(r.from) && Number.isFinite(r.duration) && r.from >= 0 && r.duration > 0).slice(0, 20000) : [];
  }
  function init(screen) {
    const $ = name => screen.querySelector(`[data-wall-${name}]`);
    const labels = JSON.parse($('playback-labels').textContent);
    const clock = new Clock();
    const archive = screen.dataset.wallArchive === '1';
    const items = [...screen.querySelectorAll('[data-wall-frame]')].map(frame => ({
      frame, overlay: frame.parentElement.querySelector('[data-wall-state]'), origin: frame.dataset.wallOrigin,
      visible: false, ready: false, archive: false, state: null, seek: 0, revision: 0,
      ranges: [], rangeError: null, rangeRequest: 0, lastCorrection: 0,
    }));
    let revision = 0, request = 0, span = 12 * 3600, from = Math.floor(clock.time() - span), to = from + span;
    let destroyed = false, timelineWidth = 0, dateDirty = false;
    const dateText = unix => new Date(unix * 1000).toLocaleString();
    const dateInput = unix => { const d = new Date(unix * 1000); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 19); };
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
      span = clamp(newSpan, 60, 86400);
      from = Math.max(0, Math.floor(center - span / 2)); to = from + span;
      items.forEach(item => { item.ranges = []; ranges(item); });
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
      if (m.type === 'ready') {
        const initial = !item.ready;
        item.ready = true; item.archive = m.archive === true;
        if (initial) { command(item, true); ranges(item); }
      } else if (m.type === 'state' && m.revision === item.revision) {
        item.state = m; item.archive = m.archive === true; item.received = performance.now();
        if (!item.archive) item.ranges = [];
      } else if (m.type === 'ranges' && m.request === item.rangeRequest) {
        item.rangePending = false;
        item.ranges = m.error ? [] : normalizeRanges(m.ranges);
        item.rangeError = m.error ? (m.error === 'archiveDenied' ? 'archiveDenied' : 'rangesError') : null;
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
      $('clock').textContent = clock.mode === 'live' ? 'LIVE' : dateText(clock.time());
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
        $('seek').min = from; $('seek').max = to; $('seek').value = clamp(clock.time(), from, to);
        $('seek').setAttribute('aria-valuetext', dateText(Number($('seek').value)));
        $('window').textContent = `${dateText(from)} - ${dateText(to)}`;
        const loaded = items.filter(i => i.ready && i.archive).length;
        const error = items.find(i => i.rangeError);
        $('archive-status').textContent = error ? labels[error.rangeError] : `${labels.timeline}: ${loaded} / ${items.length}`;
      }
    }
    function draw() {
      if (!archive) return;
      const canvas = $('timeline'), width = canvas.parentElement.clientWidth;
      const height = 28 + items.length * 25, dpr = window.devicePixelRatio || 1;
      if (!width) return;
      timelineWidth = width;
      if (canvas.width !== Math.round(width * dpr) || canvas.height !== Math.round(height * dpr)) {
        canvas.width = Math.round(width * dpr); canvas.height = Math.round(height * dpr);
        canvas.style.height = `${height}px`;
      }
      const ctx = canvas.getContext('2d'); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.fillStyle = '#171c1f'; ctx.fillRect(0, 0, width, height);
      const left = Math.min(128, width * 0.32), track = width - left - 8;
      const x = unix => left + (unix - from) / span * track;
      ctx.font = '11px system-ui'; ctx.textBaseline = 'middle';
      const ticks = width < 500 ? 3 : 6;
      for (let n = 0; n <= ticks; n++) {
        const unix = from + n * span / ticks, pos = x(unix);
        ctx.fillStyle = '#333c40'; ctx.fillRect(pos, 24, 1, height - 24);
        ctx.fillStyle = '#d3dcdf'; ctx.textAlign = n === ticks ? 'right' : 'left';
        ctx.fillText(new Date(unix * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), pos, 12);
      }
      ctx.textAlign = 'left';
      items.forEach((item, i) => {
        const y = 28 + i * 25;
        ctx.save(); ctx.beginPath(); ctx.rect(4, y, left - 10, 24); ctx.clip();
        ctx.fillStyle = '#d3dcdf'; ctx.fillText(`${i + 1}. ${item.frame.title}`, 5, y + 10); ctx.restore();
        ctx.fillStyle = '#253137'; ctx.fillRect(left, y + 2, track, 17);
        ctx.fillStyle = '#27b982';
        item.ranges.forEach(r => {
          const start = Math.max(from, r.from), end = Math.min(to, r.from + r.duration);
          if (end > start) ctx.fillRect(x(start), y + 4, Math.max(1, (end - start) / span * track), 13);
        });
      });
      const cursor = clock.time();
      if (cursor >= from && cursor <= to) { ctx.fillStyle = '#ffcc63'; ctx.fillRect(x(cursor) - 1, 24, 2, height - 24); }
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
      if (archive) { $('speed').value = '1'; windowAt(clock.time() - span / 2); }
      items.forEach(item => command(item, true)); visibility(); render();
    });
    if (archive) {
      $('date').addEventListener('input', () => { dateDirty = true; });
      $('jump').addEventListener('submit', event => { event.preventDefault(); const unix = new Date($('date').value).getTime() / 1000; dateDirty = false; seek(unix); });
      $('speed').addEventListener('change', () => { clock.set(clock.time(), clock.mode, clock.paused, Number($('speed').value)); items.forEach(i => command(i)); });
      $('seek').addEventListener('change', () => seek(Number($('seek').value)));
      $('timeline').addEventListener('click', event => {
        const left = Math.min(128, timelineWidth * 0.32), x = event.clientX - $('timeline').getBoundingClientRect().left;
        if (x >= left) seek(from + clamp((x - left) / (timelineWidth - left - 8), 0, 1) * span);
      });
      screen.querySelectorAll('[data-wall-timeline-action]').forEach(button => button.addEventListener('click', () => {
        const action = button.dataset.wallTimelineAction, center = (from + to) / 2;
        if (action === 'zoomIn' || action === 'zoomOut') windowAt(center, span * (action === 'zoomIn' ? 0.5 : 2));
        else windowAt(center + (action === 'previousWindow' ? -1 : 1) * span * 0.8);
      }));
    }
    const full = $('fullscreen'), fullscreen = screen.requestFullscreen || screen.webkitRequestFullscreen;
    full.hidden = !fullscreen;
    full.addEventListener('click', async () => {
      try {
        if (document.fullscreenElement || document.webkitFullscreenElement) await (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        else await fullscreen.call(screen);
      } catch (_) { full.blur(); }
    });
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => { const item = items.find(i => i.frame === entry.target); item.visible = entry.isIntersecting; }); visibility();
    }, { rootMargin: '100px' });
    items.forEach(item => observer.observe(item.frame));
    const resize = new ResizeObserver(draw); if (archive) resize.observe($('timeline').parentElement);
    window.addEventListener('message', receive);
    document.addEventListener('visibilitychange', visibility);
    const timer = setInterval(tick, 500), rangeTimer = setInterval(() => {
      if (clock.mode === 'live') windowAt(clock.time() - span / 2);
      else items.forEach(ranges);
    }, 30000);
    window.addEventListener('pagehide', () => { destroyed = true; visibility(); });
    window.addEventListener('pageshow', () => { destroyed = false; visibility(); });
    render();
    return { clock, seek, close() { clearInterval(timer); clearInterval(rangeTimer); observer.disconnect(); resize.disconnect(); window.removeEventListener('message', receive); document.removeEventListener('visibilitychange', visibility); destroyed = true; visibility(); } };
  }
  root.SesameVideoWallPlayback = { init, Clock, normalizeRanges };
})(typeof window === 'undefined' ? globalThis : window);
