const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const { execFileSync, spawn } = require('node:child_process');
const { mkdtempSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join, resolve } = require('node:path');
const { createServer } = require('node:net');

async function assertToolbarSizes(page) {
  assert.equal(await page.locator('.vw-screen > .vw-toolbar').isVisible(), true);
  const dimensions = await page.locator('.vw-screen > .vw-toolbar .icon-action').evaluateAll(elements => elements.map(element => {
    const box = element.getBoundingClientRect();
    const icon = [...element.querySelectorAll('svg')].find(svg => svg.getBoundingClientRect().width > 0).getBoundingClientRect();
    return {width: box.width, height: box.height, iconWidth: icon.width, iconHeight: icon.height};
  }));
  assert.equal(dimensions.length, 2);
  for (const size of dimensions) assert.deepEqual(size, {width: 42, height: 42, iconWidth: 20, iconHeight: 20});
  const back = await page.locator('.vw-screen > .vw-toolbar > .btn').boundingBox();
  assert.equal(back.height, 42);
  assert.equal(await page.locator('.vw-video-grid').evaluate(grid => getComputedStyle(grid).gap), '0px');
}

async function assertFullscreenLayout(page) {
  assert.equal(await page.locator('.vw-screen > .vw-toolbar').isVisible(), false);
  const layout = await page.locator('[data-wall-view]').evaluate(screen => {
    const grid = screen.querySelector('.vw-video-grid');
    const tiles = [...grid.children];
    return {
      padding: getComputedStyle(screen).padding,
      gap: getComputedStyle(grid).gap,
      grid: grid.getBoundingClientRect().toJSON(),
      tiles: tiles.map(tile => tile.getBoundingClientRect().toJSON()),
      radii: tiles.map(tile => getComputedStyle(tile).borderRadius),
      captionsInside: tiles.every(tile => {
        const stage = tile.querySelector('.vw-video-stage').getBoundingClientRect();
        const caption = tile.querySelector('.vw-tile-caption').getBoundingClientRect();
        return caption.top >= stage.top && caption.bottom <= stage.bottom && stage.height === tile.getBoundingClientRect().height;
      }),
      gridBottom: grid.getBoundingClientRect().bottom,
      height: innerHeight,
      width: innerWidth,
      overflow: screen.scrollHeight > screen.clientHeight + 1,
    };
  });
  assert.equal(layout.padding, '0px');
  assert.equal(layout.gap, '0px');
  assert.equal(layout.grid.top, 0);
  assert.equal(layout.grid.left, 0);
  assert.equal(layout.grid.right, layout.width);
  assert.equal(layout.overflow, false);
  assert.equal(layout.captionsInside, true);
  assert.equal(layout.gridBottom, layout.height);
  for (const radius of layout.radii) assert.equal(radius, '0px');
  assert.equal(layout.tiles[0].right, layout.tiles[1].left);
  assert.equal(layout.tiles[0].bottom, layout.tiles[2].top);
  assert.equal(await page.locator('[data-wall-archive-controls]').isVisible(), true);
}

async function assertControlsAutohide(page) {
  await page.mouse.move(200, 100);
  await page.waitForFunction(() => document.querySelector('[data-wall-view]').classList.contains('vw-controls-hidden'));
  assert.equal(await page.locator('[data-wall-controls]').isVisible(), false);
  await page.mouse.move(180, 90);
  await page.waitForFunction(() => !document.querySelector('[data-wall-view]').classList.contains('vw-controls-hidden'));
  await page.waitForFunction(() => document.querySelector('[data-wall-view]').classList.contains('vw-controls-hidden'));
  const frame = page.locator('[data-wall-frame]').first().contentFrame();
  assert(await frame.locator('.player-app').evaluate(app => app.getBoundingClientRect().width <= innerWidth + 1));
  await frame.locator('video').dispatchEvent('pointerdown', {pointerType: 'touch', bubbles: true});
  await page.waitForFunction(() => !document.querySelector('[data-wall-view]').classList.contains('vw-controls-hidden'));
  assert.equal(await page.locator('[data-wall-controls]').isVisible(), true);
}

async function assertTileControlsSeparated(page) {
  const tile = page.locator('.vw-video-tile').first();
  const button = tile.locator('[data-wall-camera-zoom]');
  const wasEnabled = await button.getAttribute('aria-pressed') === 'true';
  if (!wasEnabled) await button.click();
  const embed = tile.locator('iframe').contentFrame();
  await embed.locator('.video-overlay-actions').hover();
  const stage = await tile.locator('.vw-video-stage').boundingBox();
  const actions = await tile.locator('.vw-tile-actions').boundingBox();
  assert.deepEqual(await tile.locator('.vw-tile-actions .icon-action').evaluateAll(elements => elements.map(element => {
    const box = element.getBoundingClientRect(); return [box.width, box.height];
  })), [[28, 28], [28, 28]], 'camera action buttons have equal square dimensions');
  const caption = await tile.locator('.vw-tile-caption').boundingBox();
  const embedActions = await embed.locator('.video-overlay-actions').boundingBox();
  assert(actions.y >= stage.y && actions.x + actions.width <= stage.x + stage.width);
  assert(actions.y + actions.height < stage.y + stage.height / 2, 'Portal controls are in the upper half of the image');
  assert(actions.y + actions.height <= embedActions.y, 'Portal and embed controls do not overlap');
  assert(caption.x + caption.width <= embedActions.x, 'camera caption leaves space for embed controls');
  await embed.locator('#mute-overlay-toggle').evaluate(button => new Promise((resolve, reject) => {
    const deadline = performance.now() + 3000;
    const check = () => {
      if (Number(getComputedStyle(button).opacity) === 1) resolve();
      else if (performance.now() > deadline) reject(new Error('Embed controls did not appear on hover'));
      else setTimeout(check, 30);
    }; check();
  }));
  const muted = await embed.locator('video').evaluate(video => video.muted);
  await embed.locator('#mute-overlay-toggle').click();
  assert.equal(await embed.locator('video').evaluate(video => video.muted), !muted, 'embed sound control stays accessible');
  await embed.locator('#mute-overlay-toggle').click();
  await button.hover();
  assert.equal(await button.evaluate(button => {
    const box = button.getBoundingClientRect();
    return document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2).closest('button') === button;
  }), true, 'Portal zoom button receives pointer input');
  if (!wasEnabled) await button.click();
}

async function assertTimelineWheelZoom(page) {
  const timeline = page.locator('[data-wall-timeline]');
  const range = () => timeline.evaluate(c => ({from: Number(c.getAttribute('aria-valuemin')), to: Number(c.getAttribute('aria-valuemax'))}));
  await timeline.scrollIntoViewIfNeeded();
  const box = await timeline.boundingBox();
  const x = Math.round(box.x + 1 + (box.width - 2) * 0.3), at = (x - box.x - 1) / (box.width - 2);
  await page.mouse.move(x, box.y + box.height / 2);
  for (const deltaY of [-1, 1, -120, 120]) {
    const before = await range(), span = before.to - before.from;
    const expected = Math.round(span * Math.exp(deltaY * 0.0015));
    await page.mouse.wheel(0, deltaY);
    await page.waitForFunction(expected => {
      const c = document.querySelector('[data-wall-timeline]');
      return Number(c.getAttribute('aria-valuemax')) - Number(c.getAttribute('aria-valuemin')) === expected;
    }, expected);
    const after = await range();
    const anchorDrift = Math.abs((before.from + at * span) - (after.from + at * expected));
    assert(anchorDrift < 2, `wheel zoom keeps the time under the cursor anchored (drift: ${anchorDrift}s)`);
    if (Math.abs(deltaY) === 1) assert(Math.abs(expected / span - 1) < 0.002, 'tiny wheel movement changes scale by less than 0.2%');
  }
  const before = await range();
  await page.mouse.wheel(120, 0);
  await page.waitForTimeout(600);
  assert.deepEqual(await range(), before, 'horizontal-only wheel movement does not zoom or shift the window');
}

async function assertCameraZoomToggle(page) {
  const tile = page.locator('.vw-video-tile').first(), button = tile.locator('[data-wall-camera-zoom]');
  const frame = tile.locator('iframe').contentFrame();
  const scale = () => frame.locator('.stage').evaluate(stage => Number(getComputedStyle(stage).getPropertyValue('--video-zoom-scale')));
  assert.equal(await page.locator('[data-wall-camera-zoom][aria-pressed="false"]:enabled').count(), 4);
  assert.equal(await tile.locator('iframe').getAttribute('tabindex'), '-1');
  await tile.locator('.vw-video-stage').hover();
  let scroll = await page.evaluate(() => scrollY);
  await page.mouse.wheel(0, 180);
  await page.waitForFunction(before => scrollY > before, scroll);
  assert.equal(await scale(), 1, 'wheel over a camera scrolls the page without zooming by default');
  await button.click();
  assert.equal(await button.getAttribute('aria-pressed'), 'true');
  assert.equal(await button.getAttribute('title'), 'Выключить управление масштабом камеры');
  assert.equal(await tile.locator('iframe').getAttribute('tabindex'), '0');
  await tile.locator('.vw-video-stage').hover();
  scroll = await page.evaluate(() => scrollY);
  await page.mouse.wheel(0, -120);
  await frame.locator('.stage').evaluate(stage => new Promise(resolve => {
    const check = () => Number(getComputedStyle(stage).getPropertyValue('--video-zoom-scale')) > 1 ? resolve() : setTimeout(check, 30); check();
  }));
  assert.equal(await page.evaluate(() => scrollY), scroll, 'enabled camera handles wheel itself');
  assert.equal(await page.locator('[data-wall-camera-zoom][aria-pressed="true"]').count(), 1);
  assert.equal(await page.locator('[data-wall-frame]').nth(1).contentFrame().locator('.stage').evaluate(stage => Number(getComputedStyle(stage).getPropertyValue('--video-zoom-scale'))), 1);
  await assertTileControlsSeparated(page);
  await tile.screenshot({path: '/tmp/portal-wall-camera-zoom-desktop.png'});
  const zoomed = await scale();
  await button.click();
  assert.equal(await button.getAttribute('aria-pressed'), 'false');
  await page.evaluate(() => scrollTo(0, 0));
  await tile.locator('.vw-video-stage').hover();
  scroll = await page.evaluate(() => scrollY);
  await page.mouse.wheel(0, 180);
  await page.waitForFunction(before => scrollY > before, scroll);
  assert.equal(await scale(), zoomed, 'disabling zoom restores scrolling without changing framing or reloading');
  await button.press('Enter');
  assert.equal(await button.getAttribute('aria-pressed'), 'true', 'toggle is keyboard accessible');
  await tile.locator('.vw-video-stage').hover();
  await page.mouse.wheel(0, 120);
  await frame.locator('.stage').evaluate(stage => new Promise(resolve => {
    const check = () => Number(getComputedStyle(stage).getPropertyValue('--video-zoom-scale')) === 1 ? resolve() : setTimeout(check, 30); check();
  }));
  await button.click();
}

async function assertCameraTouchScroll(page, context) {
  const stage = page.locator('.vw-video-stage').first();
  await page.evaluate(() => scrollTo(0, 0));
  const box = await stage.boundingBox(), x = box.x + box.width / 2, y = box.y + box.height / 2;
  const touch = await context.newCDPSession(page);
  try {
    await touch.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{id: 1, x, y: y + 50}]});
    for (const dy of [30, 0, -30, -60]) await touch.send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{id: 1, x, y: y + dy}]});
    await touch.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
    await page.waitForFunction(() => scrollY > 30);
    assert.equal(await page.locator('[data-wall-camera-zoom][aria-pressed="true"]').count(), 0, 'touch swipe scrolls the page without enabling zoom');
  } finally { await touch.detach(); }
}

async function assertArchiveDriftTolerance(page) {
  const frame = page.locator('[data-wall-frame]').first().contentFrame();
  await page.locator('[data-wall-speed]').selectOption('1');
  await frame.locator('video').evaluate(video => new Promise(resolve => {
    const check = () => !video.paused && video.playbackRate === 1 ? resolve() : setTimeout(check, 50); check();
  }));
  await page.waitForFunction(() => document.querySelector('[data-wall-state]').hidden);
  const initialSeek = await frame.locator('body').evaluate(() => window.__wallTestSeeks.at(-1));
  assert(Number.isSafeInteger(initialSeek), 'fixture observes actual Portal seek commands');
  for (const offset of [4, -4]) {
    const time = await frame.locator('video').evaluate(video => video.currentTime);
    await page.evaluate(offset => { window.__wallTestOffset = offset; }, offset);
    await page.waitForTimeout(7500);
    assert.equal(await page.locator('[data-wall-state]').first().isHidden(), true, 'small UTC drift does not cover playing video');
    assert.equal(await frame.locator('body').evaluate(() => window.__wallTestSeeks.at(-1)), initialSeek, 'small UTC drift does not seek after the recovery cooldown');
    assert(await frame.locator('video').evaluate(video => video.currentTime) > time + 6, 'video keeps playing without interruption');
  }
  await page.evaluate(() => { window.__wallTestOffset = 12; });
  await page.waitForTimeout(1500);
  assert.equal(await page.locator('[data-wall-state]').first().isHidden(), true, 'brief larger drift does not flash a synchronization overlay');
  assert.equal(await frame.locator('body').evaluate(() => window.__wallTestSeeks.at(-1)), initialSeek);
  await page.evaluate(() => { window.__wallTestOffset = 0; });
  await page.waitForTimeout(1000);
  await page.evaluate(() => { window.__wallTestOffset = 12; });
  await frame.locator('body').evaluate((_body, initialSeek) => new Promise((resolve, reject) => {
    const deadline = performance.now() + 10000;
    const check = () => {
      if (window.__wallTestSeeks.at(-1) > initialSeek) resolve();
      else if (performance.now() > deadline) reject(new Error(`Persistent drift was not corrected: initial=${initialSeek}, commands=${window.__wallTestSeeks.slice(-8)}`));
      else setTimeout(check, 50);
    }; check();
  }), initialSeek);
  await page.evaluate(() => { window.__wallTestOffset = 0; });
  await page.waitForFunction(() => document.querySelector('[data-wall-state]').hidden);
}

async function assertArchiveGapRecovery(page, dvr, seek) {
  const tile = page.locator('.vw-video-tile').nth(2), frame = tile.locator('iframe').contentFrame();
  await page.locator('[data-wall-speed]').selectOption('1');
  await seek(dvr.epoch + 197);
  await page.waitForFunction(() => document.querySelectorAll('[data-wall-state]')[2].textContent.includes('Нет записи'));
  const initial = await frame.locator('body').evaluate(() => window.__wallTestCommands.at(-1));
  assert(initial.unix < dvr.epoch + 200);
  await frame.locator('body').evaluate((_body, initial) => new Promise((resolve, reject) => {
    const deadline = performance.now() + 5000;
    const check = () => {
      if (window.__wallTestCommands.at(-1)?.seek > initial.seek) resolve();
      else if (performance.now() > deadline) reject(new Error('Camera stayed in noRecording after its next archive range started'));
      else setTimeout(check, 30);
    }; check();
  }), initial);
  const resumed = await frame.locator('body').evaluate(() => window.__wallTestCommands.at(-1));
  assert(resumed.unix >= dvr.epoch + 200 && resumed.unix < dvr.epoch + 201,
    `Gap recovery must follow the camera range boundary, not the 10s retry timer: ${JSON.stringify(resumed)}`);
  await page.waitForFunction(() => document.querySelectorAll('[data-wall-state]')[2].hidden);
  const before = await frame.locator('video').evaluate(video => video.getVideoPlaybackQuality().totalVideoFrames);
  await frame.locator('video').evaluate((video, before) => new Promise((resolve, reject) => {
    const deadline = performance.now() + 4000;
    const check = () => {
      if (!video.paused && video.readyState >= 2 && video.getVideoPlaybackQuality().totalVideoFrames > before + 2) resolve();
      else if (performance.now() > deadline) reject(new Error('Archive recovery did not decode new frames'));
      else setTimeout(check, 30);
    }; check();
  }), before);
  await tile.screenshot({path: '/tmp/portal-wall-archive-gap-recovered.png'});
}

(async () => {
  const root = resolve(__dirname, '..');
  const state = mkdtempSync(join(tmpdir(), 'portal-wall-browser-'));
  let browser, server, dvr, page;
  try {
    const socket = createServer();
    await new Promise((resolve) => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise((resolve) => socket.close(resolve));
    const base = `http://127.0.0.1:${port}`;
    const env = {...process.env, SESAME_PORTAL_STATE_DIR: state, SESAME_PORTAL_DB_DSN: `sqlite:${state}/portal.sqlite`, SESAME_PORTAL_SECRET: 'local-wall-test', SESAME_PORTAL_UPDATE_AUTO_CHECK: '0'};
    const playerDir = process.env.SESAME_DVR_PLAYER_DIR;
    if (!playerDir) throw new Error('Set SESAME_DVR_PLAYER_DIR to the updated DVR priv/player directory');
    dvr = await require('./video_walls_dvr_fixture.cjs')(state, playerDir);
    execFileSync('php', ['tests/video_walls_browser_fixture.php', base, dvr.base], {cwd: root, env});
    execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=15', '-t', '4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', join(state, 'sample.mp4')]);
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/video_walls_router.php'], {cwd: root, env, stdio: 'ignore'});
    for (let attempt = 0; ; attempt++) {
      try { if ((await fetch(base + '/login')).ok) break; } catch (_) { /* Server startup. */ }
      if (attempt >= 50) throw new Error('Local PHP fixture did not start');
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    browser = await chromium.launch({headless: true, channel: 'chromium'});
    const context = await browser.newContext({ viewport: {width: 1600, height: 1000} });
    // Perturb only reported UTC in the isolated fixture; the real DVR still decodes HLS.
    await context.addInitScript(() => {
      window.__wallTestOffset = 0;
      window.__wallTestSeeks = [];
      window.__wallTestCommands = [];
      window.addEventListener('message', event => {
        const m = event.data;
        if (m?.protocol !== 'sesame-wall' || m.version !== 1) return;
        if (window === window.top && m.type === 'state' && m.mode === 'archive' && Number.isFinite(m.unix) &&
            event.source === document.querySelector('[data-wall-frame]')?.contentWindow) m.unix += window.__wallTestOffset;
        if (window !== window.top && event.source === window.parent && m.type === 'set') { window.__wallTestSeeks.push(m.seek); window.__wallTestCommands.push(m); }
      }, true);
    });
    await context.route(dvr.hlsUrl, route => route.fulfill({body: dvr.hls, contentType: 'text/javascript'}));
    await context.route('https://unpkg.com/**', (route) => route.fulfill({body: '', contentType: route.request().url().includes('.css') ? 'text/css' : 'application/javascript'}));
    page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(base + '/login?lang=ru');
    await page.locator('[name="login"]').fill('wall-demo');
    await page.locator('[name="password"]').fill('wall-demo123');
    await Promise.all([page.waitForURL(base + '/'), page.locator('button[type="submit"], button.primary').first().click()]);
    await page.getByRole('link', {name: 'Видеостены', exact: true}).click();
    await page.getByRole('link', {name: 'Новая видеостена', exact: true}).click();
    await page.locator('[name="name"]').fill('Входы и парковка');
    await page.locator('[name="rows"]').selectOption('2');
    await page.locator('[name="columns"]').selectOption('2');
    assert.equal(await page.locator('[data-wall-group][open]').count(), 0);
    await page.locator('[data-wall-search]').fill('Parking');
    assert.equal(await page.locator('[data-wall-camera-row]:visible').count(), 4);
    for (const id of [1,2,3,4]) await page.locator(`[name="cameraIds[]"][value="${id}"]`).check();
    await page.locator('[data-wall-search]').fill('demo-5');
    await page.locator('[name="cameraIds[]"][value="5"]').click();
    assert.equal(await page.locator('[name="cameraIds[]"][value="5"]').isChecked(), false);
    assert.equal(await page.locator('[data-wall-ids]').inputValue(), '[1,2,3,4]');
    await page.locator('[data-wall-search]').fill('');
    await page.locator('[data-camera-id="4"] [data-wall-action="up"]').click();
    assert.equal(await page.locator('[data-wall-ids]').inputValue(), '[1,2,4,3]');
    await page.screenshot({path: '/tmp/portal-wall-editor-desktop.png', fullPage: true});
    await page.locator('[data-wall-save]').click();
    await page.waitForURL(/\/video-walls\/view\?id=/);
    assert.equal(await page.locator('.alert.success').count(), 1);
    const wallUrl = page.url();
    await assertToolbarSizes(page);
    await page.locator('.vw-screen > .vw-toolbar').screenshot({path: '/tmp/portal-wall-toolbar-desktop.png'});
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 4);
    const frame = await page.locator('[data-wall-frame]').first().contentFrame();
    await frame.locator('video').waitFor();
    await frame.locator('video').evaluate((video) => new Promise((resolve, reject) => {
      const end = Date.now() + 10000;
      const check = () => {
        if (video.readyState >= 2 && video.currentTime > 0.2) resolve();
        else if (Date.now() > end) reject(new Error('Video not playing'));
        else setTimeout(check, 100);
      }; check();
    }));
    const pixels = await frame.locator('video').evaluate((video) => {
      const canvas = document.createElement('canvas'); canvas.width = 64; canvas.height = 36;
      const ctx = canvas.getContext('2d'); ctx.drawImage(video, 0, 0, 64, 36);
      return [...ctx.getImageData(0,0,64,36).data].filter((n,i) => i % 4 !== 3 && n > 20).length;
    });
    assert(pixels > 500);
    const watermark = await page.locator('.vw-watermark').first().boundingBox();
    const stage = await page.locator('.vw-video-stage').first().boundingBox();
    assert(watermark.y + watermark.height <= stage.y + stage.height + 1);
    await page.screenshot({path: '/tmp/portal-wall-view-desktop.png', fullPage: true});
    await require('./video_walls_eco_browser.cjs')(page, dvr);
    await assertCameraZoomToggle(page);
    await page.locator('.vw-toolbar [data-wall-fullscreen]').click();
    await page.waitForFunction(() => document.fullscreenElement !== null);
    await assertFullscreenLayout(page);
    await assertTileControlsSeparated(page);
    await assertControlsAutohide(page);
    await page.screenshot({path: '/tmp/portal-wall-view-fullscreen.png'});
    await page.waitForFunction(() => document.querySelector('[data-wall-view]').classList.contains('vw-controls-hidden'));
    await page.screenshot({path: '/tmp/portal-wall-view-fullscreen-clean.png'});
    await page.evaluate(() => document.exitFullscreen());
    await page.waitForFunction(() => document.fullscreenElement === null);
    await assertToolbarSizes(page);
    await page.waitForFunction(() => document.querySelector('[data-wall-archive-status]').textContent.includes('4 / 4'));
    // Both event bands contribute to a single track, not only the first camera.
    await page.waitForFunction(epoch => {
      const canvas = document.querySelector('[data-wall-timeline]');
      const from = Number(canvas.getAttribute('aria-valuemin')), to = Number(canvas.getAttribute('aria-valuemax'));
      const dpr = canvas.width / canvas.clientWidth, ctx = canvas.getContext('2d');
      return [-900, -600].every(offset => {
        const x = (1 + (epoch + offset - from) / (to - from) * (canvas.clientWidth - 2)) * dpr;
        const pixel = ctx.getImageData(Math.floor(x), Math.floor(15 * dpr), 1, 1).data;
        return pixel[0] === 197 && pixel[1] === 138 && pixel[2] === 37;
      });
    }, dvr.epoch);
    assert.equal(await page.locator('[data-wall-timeline]').evaluate(canvas => canvas.clientHeight), 86);
    assert.equal(await page.locator('[data-wall-seek]').count(), 0);
    await assertTimelineWheelZoom(page);
    await page.locator('[data-wall-timeline-action="zoomOut"]').click();
    await page.locator('[data-wall-timeline]').press('ArrowLeft');
    await page.waitForFunction(() => document.querySelector('[data-wall-live]').getAttribute('aria-pressed') === 'false');
    await page.locator('[data-wall-live]').click();
    await page.locator('[data-wall-play]').click();
    await frame.locator('video').evaluate(video => new Promise(resolve => { const check = () => video.paused ? resolve() : setTimeout(check, 50); check(); }));
    assert.equal(await page.locator('[data-wall-frame][src]').count(), 4);
    // Seek paused, then resume all cameras. The video is decoded by the actual DVR app.
    const seek = async unix => {
      const value = await page.evaluate(unix => { const date = new Date(unix * 1000); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 19); }, unix);
      await page.locator('[data-wall-date]').fill(value);
      await page.locator('[data-wall-jump] button[type="submit"]').click();
    };
    await seek(dvr.epoch + 10);
    await page.waitForFunction(() => [...document.querySelectorAll('[data-wall-state]')].every(node => node.hidden));
    assert.equal(await frame.locator('video').evaluate(video => video.paused), true);
    const pausedTime = await frame.locator('video').evaluate(video => video.currentTime);
    await page.waitForTimeout(800);
    assert(Math.abs(await frame.locator('video').evaluate(video => video.currentTime) - pausedTime) < 0.1);
    await page.locator('[data-wall-play]').click();
    await page.locator('[data-wall-speed]').selectOption('2');
    await frame.locator('video').evaluate(video => new Promise(resolve => { const check = () => !video.paused && video.playbackRate === 2 ? resolve() : setTimeout(check, 50); check(); }));
    await assertArchiveDriftTolerance(page);
    await seek(dvr.epoch + 130);
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-state]')[2].textContent.includes('Нет записи'));
    await page.waitForFunction(() => document.querySelector('[data-wall-state]').hidden);
    assert(await frame.locator('video').evaluate(video => !video.paused && video.readyState >= 2));
    await page.screenshot({path: '/tmp/portal-wall-archive-desktop.png', fullPage: true});
    await assertArchiveGapRecovery(page, dvr, seek);
    await page.locator('[data-wall-live]').click();
    await page.waitForFunction(() => document.querySelector('[data-wall-live]').getAttribute('aria-pressed') === 'true');
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 4);
    const other = await context.newPage(); await other.goto('about:blank'); await other.bringToFront();
    // Headless visibility varies by platform; explicit stop/start is asserted above.
    await other.close(); await page.bringToFront();
    // Auth metadata denial and a legacy player must never display live video as archive.
    dvr.deny(true);
    await page.goto(wallUrl);
    await page.waitForFunction(() => document.querySelector('[data-wall-archive-status]').textContent.includes('0 / 4'));
    await seek(dvr.epoch + 10);
    await page.waitForFunction(() => document.querySelector('[data-wall-state]').textContent.includes('Архив недоступен'));
    assert.equal(await page.locator('.vw-state-blocking').count(), 4);
    dvr.deny(false); dvr.legacy(true);
    await page.goto(wallUrl);
    await seek(dvr.epoch + 10);
    await page.waitForFunction(() => document.querySelector('[data-wall-state]').textContent.includes('Обновите DVR'));
    assert.equal(await page.locator('.vw-state-blocking').count(), 4);
    dvr.legacy(false);
    await require('./video_walls_bootstrap_browser.cjs')(context, wallUrl);
    await page.bringToFront();
    // The Portal restriction itself removes all archive UI and range requests.
    await page.goto('about:blank');
    execFileSync('php', ['-r', 'require "app/Portal.php"; SesamePortal\\DB::pdo()->exec("UPDATE users SET hide_archive=1 WHERE login=\'wall-demo\'");'], {cwd: root, env});
    const since = dvr.requests.length;
    await page.goto(wallUrl);
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 4);
    await page.waitForTimeout(1200);
    assert.equal(await page.locator('[data-wall-archive-controls]').count(), 0);
    await page.locator('[data-wall-eco]').click();
    await page.waitForFunction(() => [...document.querySelectorAll('[data-wall-frame]')].every(frame => frame.hasAttribute('src') && new URL(frame.src).searchParams.get('economy') === 'idr'));
    await page.waitForTimeout(1200);
    assert.equal(dvr.requests.slice(since).filter(url => /\/(timeline_ranges|motion_events)\.json$/.test(url.pathname)).length, 0);
    execFileSync('php', ['-r', 'require "app/Portal.php"; SesamePortal\\DB::pdo()->exec("UPDATE users SET hide_archive=0 WHERE login=\'wall-demo\'");'], {cwd: root, env});
    await page.setViewportSize({width:390, height:844});
    await page.goto(wallUrl);
    assert.equal(await page.locator('[data-wall-camera-zoom][aria-pressed="true"]').count(), 0, 'page reload resets zoom toggles');
    await assertToolbarSizes(page);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.locator('[data-wall-frame]').first().contentFrame().locator('video').evaluate(video => new Promise(resolve => { const check = () => video.readyState >= 2 && video.currentTime > 0.2 ? resolve() : setTimeout(check, 50); check(); }));
    await assertTileControlsSeparated(page);
    await page.screenshot({path:'/tmp/portal-wall-view-mobile.png',fullPage:true});
    await assertCameraTouchScroll(page, context);
    await page.locator('.vw-toolbar [data-wall-fullscreen]').click();
    await page.waitForFunction(() => document.fullscreenElement !== null);
    await assertFullscreenLayout(page);
    await assertTileControlsSeparated(page);
    await assertControlsAutohide(page);
    await page.screenshot({path:'/tmp/portal-wall-view-fullscreen-mobile.png'});
    const timelineBox = await page.locator('[data-wall-timeline]').boundingBox();
    const beforePinch = await page.locator('[data-wall-timeline]').evaluate(c => Number(c.getAttribute('aria-valuemax')) - Number(c.getAttribute('aria-valuemin')));
    const touch = await context.newCDPSession(page);
    const cameraButton = page.locator('[data-wall-camera-zoom]').first();
    await cameraButton.click();
    assert.equal(await cameraButton.getAttribute('aria-pressed'), 'true');
    const cameraBox = await page.locator('.vw-video-stage').first().boundingBox();
    const cx = cameraBox.x + cameraBox.width / 2, cy = cameraBox.y + cameraBox.height / 2;
    await touch.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{id: 1, x: cx - 25, y: cy}, {id: 2, x: cx + 25, y: cy}]});
    await touch.send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{id: 1, x: cx - 50, y: cy}, {id: 2, x: cx + 50, y: cy}]});
    await touch.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
    await page.locator('[data-wall-frame]').first().contentFrame().locator('.stage').evaluate(stage => new Promise(resolve => {
      const check = () => Number(getComputedStyle(stage).getPropertyValue('--video-zoom-scale')) > 1 ? resolve() : setTimeout(check, 30); check();
    }));
    await page.screenshot({path:'/tmp/portal-wall-camera-zoom-mobile.png'});
    await cameraButton.click();
    const y = timelineBox.y + 20, x = timelineBox.x;
    await touch.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{id: 1, x: x + 80, y}, {id: 2, x: x + 220, y}]});
    await touch.send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{id: 1, x: x + 43, y}, {id: 2, x: x + 269, y}]});
    await touch.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
    await touch.detach();
    await page.waitForFunction(before => {
      const c = document.querySelector('[data-wall-timeline]');
      const start = Number(c.getAttribute('aria-valuemin')), end = Number(c.getAttribute('aria-valuemax'));
      return end - start < before && Number.isSafeInteger(start) && Number.isSafeInteger(end);
    }, beforePinch);
    await page.evaluate(() => document.exitFullscreen());
    await page.waitForFunction(() => document.fullscreenElement === null);
    await assertToolbarSizes(page);
    await page.setViewportSize({width:390, height:600});
    await page.locator('[data-wall-frame]').last().scrollIntoViewIfNeeded();
    await page.waitForFunction(() => {
      const frames = document.querySelectorAll('[data-wall-frame]');
      return !frames[0].hasAttribute('src') && frames[3].hasAttribute('src');
    });
    await page.locator('[data-wall-frame]').last().contentFrame().locator('video').evaluate((video) => new Promise((resolve, reject) => {
      const end = Date.now() + 10000;
      const check = () => {
        if (video.readyState >= 2 && video.currentTime > 0.2) resolve();
        else if (Date.now() > end) reject(new Error('Mobile tile not playing after scroll'));
        else setTimeout(check, 100);
      }; check();
    }));
    await page.setViewportSize({width:390, height:844});
    await seek(dvr.epoch + 20);
    await page.locator('[data-wall-frame]').first().scrollIntoViewIfNeeded();
    await page.waitForFunction(() => document.querySelector('[data-wall-state]').hidden);
    const reloaded = await page.locator('[data-wall-frame]').first().contentFrame();
    assert.equal(await reloaded.locator('#status-pill').innerText(), 'Archive');
    await page.locator('.vw-toolbar a[href*="/edit"]').click();
    assert.equal(await page.locator('[data-wall-ids]').inputValue(), '[1,2,4,3]');
    await page.locator('[data-camera-id="1"] [data-wall-action="down"]').click();
    assert.equal(await page.locator('[data-wall-ids]').inputValue(), '[2,1,4,3]');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.screenshot({path:'/tmp/portal-wall-editor-mobile.png',fullPage:true});
    await page.setViewportSize({width:1600, height:1000});
    await page.goto(base + '/video-walls');
    assert.equal(await page.locator('.vw-library-row').count(), 1);
    await page.screenshot({path:'/tmp/portal-wall-library.png',fullPage:true});
    assert.deepEqual(errors, []);
    console.log('Video wall browser checks passed: editor, real DVR HLS frames/pixels, shared seek/pause/rate, OR events/recordings, wheel/pinch, per-camera zoom toggle, mouse/touch page scrolling, overlay captions, zero tile gaps, fullscreen autohide/mouse/touch, archive denial, desktop/mobile, scroll lifecycle.');
    if (process.argv.includes('--demo')) {
      await browser.close(); browser = null; page = null;
      console.log(JSON.stringify({url: wallUrl, login: 'wall-demo', password: 'wall-demo123', archiveTime: new Date((dvr.epoch + 130) * 1000).toISOString()}));
      await new Promise(resolve => { process.once('SIGTERM', resolve); process.once('SIGINT', resolve); });
    }
  } catch (error) {
    if (page) {
      console.error(await page.locator('[data-wall-state]').allTextContents());
      await page.screenshot({path: '/tmp/portal-wall-failure.png', fullPage: true});
      for (const frame of page.frames().filter(frame => frame.url().includes('/embed.html'))) {
        console.error(await frame.evaluate(() => ({url: location.pathname, status: document.querySelector('#status-pill')?.textContent, currentTime: document.querySelector('video')?.currentTime, paused: document.querySelector('video')?.paused, ready: document.querySelector('video')?.readyState})));
      }
    }
    throw error;
  } finally {
    if (browser) await browser.close();
    if (dvr) await dvr.close();
    if (server && server.exitCode === null) {
      const exited = new Promise((resolve) => server.once('exit', resolve));
      server.kill();
      await exited;
    }
    rmSync(state, {recursive: true, force: true});
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
