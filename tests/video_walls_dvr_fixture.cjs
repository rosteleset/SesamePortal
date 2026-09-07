// Serves the actual DVR player from a sibling checkout against synthetic HLS.
const { createServer } = require('node:http');
const { readFileSync } = require('node:fs');
const { join, basename } = require('node:path');
const { execFileSync } = require('node:child_process');
module.exports = async function createDvr(state, playerDir) {
  const epoch = Math.floor(Date.now() / 1000) - 3600;
  const requests = [];
  let deny = false, legacy = false;
  execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=320x180:rate=15', '-t', '60', '-c:v', 'libx264', '-preset', 'ultrafast', '-g', '30', '-sc_threshold', '0', '-f', 'hls', '-hls_time', '2', '-hls_list_size', '0', '-hls_segment_filename', join(state, 'part%02d.ts'), join(state, 'sample.m3u8')]);
  const hlsUrl = 'https://cdn.jsdelivr.net/npm/hls.js@1.6.7/dist/hls.min.js';
  const hlsResponse = await fetch(hlsUrl); if (!hlsResponse.ok) throw new Error('Could not load pinned HLS.js test dependency');
  const hls = await hlsResponse.text();
  const server = createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const path = url.pathname;
    requests.push(url);
    const send = (type, body, status = 200) => { res.writeHead(status, { 'Content-Type': type, 'Cache-Control': 'no-store' }); res.end(body); };
    if (path.endsWith('/embed.html')) {
      let html = readFileSync(join(playerDir, 'index.html'), 'utf8');
      if (legacy) html = html.replace('<script src="/player/wall-control.js"></script>', '');
      send('text/html', html); return;
    }
    if (path.startsWith('/player/') && /^[a-z0-9.-]+$/.test(basename(path))) {
      try { send(path.endsWith('.css') ? 'text/css' : 'text/javascript', readFileSync(join(playerDir, basename(path)))); }
      catch (_) { send('text/plain', '', 404); } return;
    }
    if (path.startsWith('/i18n/')) { send('text/javascript', ''); return; }
    if (path.endsWith('/playback_info.json')) { send('application/json', JSON.stringify({ live: {running: true}, archive: {enabled: !deny}, webrtc: {available: false}, preview: {enabled: false} })); return; }
    if (path.endsWith('/timeline_ranges.json')) {
      const ranges = path.startsWith('/demo-4/') ? [{from: epoch - 3600, duration: 3700}, {from: epoch + 200, duration: 3400}] : [{from: epoch - 3600, duration: 7200}];
      send('application/json', JSON.stringify({ ranges: deny ? [] : ranges }), deny ? 403 : 200); return;
    }
    if (path.endsWith('/motion_events.json')) {
      const start = path.startsWith('/demo-1/') ? epoch - 1000 : epoch - 800;
      send('application/json', JSON.stringify({intervals: deny ? [] : [
        {from: start, duration: 300, state: 'motion'},
        {from: epoch - 100, duration: 30, state: 'idle'},
        {from: epoch + 50, duration: 30, state: 'unavailable'},
      ]}), deny ? 403 : 200); return;
    }
    if (path.endsWith('.m3u8')) {
      if (deny && path.endsWith('/dvr.m3u8')) { send('text/plain', '', 403); return; }
      const start = url.searchParams.has('start') ? new Date(url.searchParams.get('start')).getTime() / 1000 : Date.now() / 1000 - 60;
      let playlist = '#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:2\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n';
      for (let i = 0; i < 30; i++) playlist += `#EXT-X-PROGRAM-DATE-TIME:${new Date((start + i * 2) * 1000).toISOString()}\n#EXTINF:2,\n/media/part${String(i).padStart(2, '0')}.ts\n`;
      send('application/vnd.apple.mpegurl', playlist + '#EXT-X-ENDLIST\n'); return;
    }
    if (/^\/media\/part\d\d.ts$/.test(path)) { send('video/mp2t', readFileSync(join(state, basename(path)))); return; }
    send('application/json', '{}', 404);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  return { base: `http://127.0.0.1:${server.address().port}`, epoch, requests, hlsUrl, hls,
    deny: value => deny = value, legacy: value => legacy = value,
    close: () => new Promise(resolve => { server.closeAllConnections(); server.close(resolve); }) };
};
