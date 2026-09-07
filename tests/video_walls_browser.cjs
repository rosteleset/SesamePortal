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
  assert.equal(dimensions.length, 3);
  for (const size of dimensions) assert.deepEqual(size, {width: 42, height: 42, iconWidth: 20, iconHeight: 20});
  const back = await page.locator('.vw-screen > .vw-toolbar > .btn').boundingBox();
  assert.equal(back.height, 42);
  assert.equal(await page.locator('.vw-video-grid').evaluate(grid => getComputedStyle(grid).gap), '10px');
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
  for (const radius of layout.radii) assert.equal(radius, '0px');
  assert.equal(layout.tiles[0].right, layout.tiles[1].left);
  assert.equal(layout.tiles[0].bottom, layout.tiles[2].top);
  assert.equal(await page.locator('[data-wall-archive-controls]').isVisible(), true);
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
    const caption = await page.locator('.vw-tile-caption').first().boundingBox();
    assert(watermark.y + watermark.height <= caption.y + 1);
    await page.screenshot({path: '/tmp/portal-wall-view-desktop.png', fullPage: true});
    await page.locator('[data-wall-fullscreen]').click();
    await page.waitForFunction(() => document.fullscreenElement !== null);
    await assertFullscreenLayout(page);
    await page.screenshot({path: '/tmp/portal-wall-view-fullscreen.png'});
    await page.evaluate(() => document.exitFullscreen());
    await page.waitForFunction(() => document.fullscreenElement === null);
    await assertToolbarSizes(page);
    await page.waitForFunction(() => document.querySelector('[data-wall-archive-status]').textContent.includes('4 / 4'));
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
    await seek(dvr.epoch + 130);
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-state]')[2].textContent.includes('Нет записи'));
    await page.waitForFunction(() => document.querySelector('[data-wall-state]').hidden);
    assert(await frame.locator('video').evaluate(video => !video.paused && video.readyState >= 2));
    await page.screenshot({path: '/tmp/portal-wall-archive-desktop.png', fullPage: true});
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
    // The Portal restriction itself removes all archive UI and range requests.
    execFileSync('php', ['-r', 'require "app/Portal.php"; SesamePortal\\DB::pdo()->exec("UPDATE users SET hide_archive=1 WHERE login=\'wall-demo\'");'], {cwd: root, env});
    const since = dvr.requests.length;
    await page.goto(wallUrl);
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 4);
    await page.waitForTimeout(1200);
    assert.equal(await page.locator('[data-wall-archive-controls]').count(), 0);
    assert.equal(dvr.requests.slice(since).filter(url => url.pathname.endsWith('/timeline_ranges.json')).length, 0);
    execFileSync('php', ['-r', 'require "app/Portal.php"; SesamePortal\\DB::pdo()->exec("UPDATE users SET hide_archive=0 WHERE login=\'wall-demo\'");'], {cwd: root, env});
    await page.setViewportSize({width:390, height:844});
    await page.goto(wallUrl);
    await assertToolbarSizes(page);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.locator('[data-wall-frame]').first().contentFrame().locator('video').evaluate(video => new Promise(resolve => { const check = () => video.readyState >= 2 && video.currentTime > 0.2 ? resolve() : setTimeout(check, 50); check(); }));
    await page.screenshot({path:'/tmp/portal-wall-view-mobile.png',fullPage:true});
    await page.locator('[data-wall-fullscreen]').click();
    await page.waitForFunction(() => document.fullscreenElement !== null);
    await assertFullscreenLayout(page);
    await page.screenshot({path:'/tmp/portal-wall-view-fullscreen-mobile.png'});
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
    console.log('Video wall browser checks passed: editor, search, capacity, order, persistence, real DVR cross-origin HLS frames/pixels, shared seek, pause/resume, rate, gaps, LIVE, watermark, fullscreen, desktop/mobile, scroll lifecycle.');
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
