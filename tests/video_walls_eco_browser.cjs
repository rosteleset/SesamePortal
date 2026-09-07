const assert = require('node:assert/strict');

module.exports = async function assertEconomyPlayback(page, dvr) {
  const button = page.locator('[data-wall-eco]');
  const commands = () => page.locator('[data-wall-frame]').first().contentFrame().locator('body')
    .evaluate(() => window.__wallTestCommands.at(-1));
  const playing = async (eco, mode = 'live', paused = false, rate = 1) => {
    await page.waitForFunction(eco => [...document.querySelectorAll('[data-wall-frame]')].every(frame =>
      frame.hasAttribute('src') && new URL(frame.src).searchParams.has('economy') === eco), eco);
    for (let i = 0; i < 4; i++) {
      await page.locator('[data-wall-frame]').nth(i).contentFrame().locator('video').evaluate((video, expected) => new Promise((resolve, reject) => {
        const deadline = performance.now() + 15000;
        const check = () => {
          const command = window.__wallTestCommands?.at(-1);
          const idr = new URL(location.href).searchParams.get('economy') === 'idr';
          if (idr === expected.eco && command?.mode === expected.mode && command?.paused === expected.paused && command?.rate === expected.rate &&
              video.readyState >= 2 && !video.ended && video.paused === expected.paused && video.playbackRate === expected.rate) resolve();
          else if (performance.now() > deadline) reject(new Error(`ECO playback not ready: ${JSON.stringify({command, idr, ready: video.readyState, paused: video.paused, rate: video.playbackRate})}`));
          else setTimeout(check, 50);
        }; check();
      }), {eco, mode, paused, rate});
    }
    await page.waitForFunction(() => [...document.querySelectorAll('[data-wall-state]')].every(node => node.hidden));
    assert.equal(await button.getAttribute('aria-pressed'), String(eco));
  };
  const assertNoFullTraffic = async () => {
    const since = dvr.requests.length;
    await page.waitForTimeout(1200);
    assert.equal(dvr.requests.slice(since).filter(url => /\/(live|llhls|dvr)\.m3u8$|\/media\/part/.test(url.pathname)).length, 0, 'ECO does not continue fetching full streams');
  };
  await playing(false);
  await button.click();
  await playing(true);
  assert.equal(await button.getAttribute('title'), 'Выключить ECO: полные видеопотоки');
  assert.equal(new Set(dvr.requests.filter(url => url.pathname.endsWith('/idr.m3u8')).map(url => url.pathname)).size, 4);
  const pixels = await page.locator('[data-wall-frame]').first().contentFrame().locator('video').evaluate(video => {
    const canvas = document.createElement('canvas'); canvas.width = 64; canvas.height = 36;
    const ctx = canvas.getContext('2d'); ctx.drawImage(video, 0, 0, 64, 36);
    return [...ctx.getImageData(0, 0, 64, 36).data].filter((n, i) => i % 4 !== 3 && n > 20).length;
  });
  assert(pixels > 500, 'actual DVR player decodes sparse IDR fMP4 frames');
  const sample = () => page.locator('[data-wall-frame]').first().contentFrame().locator('video').evaluate(video => ({time: video.currentTime, frames: video.getVideoPlaybackQuality().totalVideoFrames}));
  const before = await sample();
  await page.waitForTimeout(4500);
  const after = await sample();
  assert(after.time > before.time + 3 && after.frames > before.frames && after.frames - before.frames < 12, 'live ECO advances at a sparse frame cadence');
  await assertNoFullTraffic();
  await page.screenshot({path: '/tmp/portal-wall-eco-desktop.png', fullPage: true});

  await page.locator('[data-wall-play]').click();
  await playing(true, 'live', true);
  await button.click();
  await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 0);
  await button.click();
  assert.equal(await page.locator('[data-wall-frame][src]').count(), 0, 'paused live stays unloaded during mode switches');
  await page.locator('[data-wall-play]').click();
  await playing(true);

  await page.evaluate(() => {
    document.querySelector('[data-wall-eco]').click();
    document.querySelector('[data-wall-play]').click();
  });
  await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 0);
  await button.click();
  const unix = dvr.epoch + 20;
  const date = await page.evaluate(unix => {
    const d = new Date(unix * 1000); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 19);
  }, unix);
  await page.locator('[data-wall-date]').fill(date);
  await page.locator('[data-wall-jump] button[type="submit"]').click();
  await page.locator('[data-wall-speed]').selectOption('2');
  await playing(true, 'archive', true, 2);
  assert(dvr.requests.some(url => url.pathname.endsWith('/idr.m3u8') && url.searchParams.has('start')));
  await button.click();
  await playing(false, 'archive', true, 2);
  assert.equal((await commands()).unix, unix, 'switch off keeps the exact paused archive target');
  assert.equal(await page.locator('[data-wall-date]').inputValue(), date);
  assert(dvr.requests.some(url => url.pathname.endsWith('/dvr.m3u8')), 'normal archive uses full streams again');
  await page.locator('[data-wall-play]').click();
  await button.click();
  await playing(true, 'archive', false, 2);
  assert((await commands()).unix >= unix && (await commands()).unix < unix + 30, 'running archive joins the advancing shared clock');
  await assertNoFullTraffic();
  await page.locator('[data-wall-play]').click();
  await playing(true, 'archive', true, 2);
  const pausedUnix = (await commands()).unix;
  const oldChannel = new URL(await page.locator('[data-wall-frame]').first().getAttribute('src')).searchParams.get('controller_id');
  await button.evaluate(button => { button.click(); button.click(); button.click(); });
  await playing(false, 'archive', true, 2);
  assert.equal((await commands()).unix, pausedUnix, 'rapid toggles keep pause and position');
  await page.evaluate(channel => {
    const frame = document.querySelector('[data-wall-frame]');
    window.dispatchEvent(new MessageEvent('message', {source: frame.contentWindow, origin: frame.dataset.wallOrigin,
      data: {protocol: 'sesame-wall', version: 1, channel, type: 'ready', archive: false}}));
  }, oldChannel);
  await page.waitForFunction(() => [...document.querySelectorAll('[data-wall-state]')].every(node => node.hidden));

  await page.locator('[data-wall-live]').click();
  await button.press('Space');
  await playing(true);
  await page.setViewportSize({width: 390, height: 844});
  await page.evaluate(() => scrollTo(0, 0));
  await page.waitForFunction(() => ![...document.querySelectorAll('[data-wall-frame]')].at(-1).hasAttribute('src'));
  await page.locator('[data-wall-frame]').last().scrollIntoViewIfNeeded();
  await page.waitForFunction(() => {
    const frame = [...document.querySelectorAll('[data-wall-frame]')].at(-1);
    return frame.hasAttribute('src') && new URL(frame.src).searchParams.get('economy') === 'idr';
  });
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
  await page.screenshot({path: '/tmp/portal-wall-eco-mobile.png', fullPage: true});
  await page.locator('.vw-toolbar [data-wall-fullscreen]').click();
  await page.waitForFunction(() => document.fullscreenElement !== null);
  await playing(true);
  await button.click();
  await playing(false);
  await button.click();
  await playing(true);
  await page.screenshot({path: '/tmp/portal-wall-eco-fullscreen-mobile.png'});
  await page.evaluate(() => document.exitFullscreen());
  await page.setViewportSize({width: 1600, height: 1000});

  // Current DVRs with IDR unavailable must not silently play the full stream.
  await button.click(); await playing(false);
  dvr.idr(false);
  await button.click();
  await page.waitForFunction(() => [...document.querySelectorAll('[data-wall-state]')].every(node => !node.hidden && node.textContent === 'Буферизация'));
  await assertNoFullTraffic();
  for (let i = 0; i < 4; i++) assert.equal(await page.locator('[data-wall-frame]').nth(i).contentFrame().locator('video').evaluate(video => video.readyState), 0);
  dvr.idr(true);
  await button.click(); await playing(false);
  await page.evaluate(() => scrollTo(0, 0));
  console.log('ECO: live/archive IDR decoding, no full traffic, pause/rate/time, rapid toggles, stale channels, mobile remount, fullscreen and unavailable IDR passed.');
};
