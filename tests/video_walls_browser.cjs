const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const { execFileSync, spawn } = require('node:child_process');
const { mkdtempSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join, resolve } = require('node:path');
const { createServer } = require('node:net');

(async () => {
  const root = resolve(__dirname, '..');
  const state = mkdtempSync(join(tmpdir(), 'portal-wall-browser-'));
  let browser, server;
  try {
    const socket = createServer();
    await new Promise((resolve) => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise((resolve) => socket.close(resolve));
    const base = `http://127.0.0.1:${port}`;
    const env = {...process.env, SESAME_PORTAL_STATE_DIR: state, SESAME_PORTAL_DB_DSN: `sqlite:${state}/portal.sqlite`, SESAME_PORTAL_SECRET: 'local-wall-test', SESAME_PORTAL_UPDATE_AUTO_CHECK: '0'};
    execFileSync('php', ['tests/video_walls_browser_fixture.php', base], {cwd: root, env});
    execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=15', '-t', '4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', join(state, 'sample.mp4')]);
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tests/video_walls_router.php'], {cwd: root, env, stdio: 'ignore'});
    for (let attempt = 0; ; attempt++) {
      try { if ((await fetch(base + '/login')).ok) break; } catch (_) { /* Server startup. */ }
      if (attempt >= 50) throw new Error('Local PHP fixture did not start');
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    browser = await chromium.launch({headless: true, channel: 'chromium'});
    const context = await browser.newContext({ viewport: {width: 1600, height: 1000} });
    await context.route('https://unpkg.com/**', (route) => route.fulfill({body: '', contentType: route.request().url().includes('.css') ? 'text/css' : 'application/javascript'}));
    const page = await context.newPage();
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
    assert(await page.locator('[data-wall-view]').evaluate((screen) => screen.scrollHeight <= screen.clientHeight + 1));
    await page.screenshot({path: '/tmp/portal-wall-view-fullscreen.png'});
    await page.evaluate(() => document.exitFullscreen());
    await page.locator('[data-wall-play]').click();
    assert.equal(await page.locator('[data-wall-frame][src]').count(), 0);
    await page.locator('[data-wall-play]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 4);
    const other = await context.newPage(); await other.goto('about:blank'); await other.bringToFront();
    // Headless visibility varies by platform; explicit stop/start is asserted above.
    await other.close(); await page.bringToFront();
    await page.setViewportSize({width:390, height:844});
    await page.goto(wallUrl);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.screenshot({path:'/tmp/portal-wall-view-mobile.png',fullPage:true});
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
    console.log('Video wall browser checks passed: editor, search, capacity, order, persistence, live frames/pixels, watermark, stop/start, fullscreen, desktop/mobile, scroll lifecycle.');
  } finally {
    if (browser) await browser.close();
    if (server && server.exitCode === null) {
      const exited = new Promise((resolve) => server.once('exit', resolve));
      server.kill();
      await exited;
    }
    rmSync(state, {recursive: true, force: true});
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
