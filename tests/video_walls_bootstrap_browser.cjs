const assert = require('node:assert/strict');

module.exports = async function assertPlayerBootstrapHidden(context, wallUrl) {
  const page = await context.newPage();
  let releaseScript, releaseMetadata, metadataRequests = 0;
  const scriptGate = new Promise(resolve => { releaseScript = resolve; });
  let metadataGate = new Promise(resolve => { releaseMetadata = resolve; });
  const assertHidden = async () => {
    const frames = await page.locator('[data-wall-frame]').evaluateAll(elements => elements.map(frame => ({
      opacity: getComputedStyle(frame).opacity, pointerEvents: getComputedStyle(frame).pointerEvents,
      hidden: frame.getAttribute('aria-hidden'), tabIndex: frame.tabIndex,
    })));
    assert.equal(frames.length, 4);
    for (const frame of frames) assert.deepEqual(frame, {opacity: '0', pointerEvents: 'none', hidden: 'true', tabIndex: -1});
    assert.equal(await page.locator('[data-wall-state]:visible').count(), 4, 'Portal still shows connection status');
    assert.equal(await page.locator('.vw-tile-caption:visible').count(), 4);
  };
  try {
    await page.route('**/player/app.js', async route => { await scriptGate; await route.continue(); });
    await page.route('**/playback_info.json*', async route => { metadataRequests++; await metadataGate; await route.continue(); });
    await page.goto(wallUrl, {waitUntil: 'domcontentloaded'});
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 4);
    const frame = page.locator('[data-wall-frame]').first().contentFrame();
    await frame.locator('#center-play').waitFor({state: 'attached'});
    await assertHidden();
    // A magnifier enabled during loading must not focus/interact with hidden UI.
    await page.locator('[data-wall-camera-zoom]').first().click();
    await assertHidden();
    releaseScript();
    await frame.locator('#camera-title').evaluate(node => new Promise(resolve => {
      const check = () => node.textContent !== 'Sesame DVR' ? resolve() : setTimeout(check, 30); check();
    }));
    assert(metadataRequests > 0);
    assert.equal(await frame.locator('#player-app').evaluate(app => app.classList.contains('wall-controlled')), false,
      'actual embed still has bootstrap UI while playback metadata is pending');
    await assertHidden();
    await page.locator('.vw-video-grid').screenshot({path: '/tmp/portal-wall-bootstrap-hidden.png'});
    releaseMetadata();
    await page.waitForFunction(() => document.querySelectorAll('.vw-frame-ready').length === 4);
    assert.equal(await page.locator('[data-wall-frame]').first().getAttribute('aria-hidden'), 'false');
    assert.equal(await page.locator('[data-wall-frame]').first().getAttribute('tabindex'), '0');
    for (const iframe of await page.locator('[data-wall-frame]').all()) {
      assert.equal(await iframe.evaluate(f => getComputedStyle(f).opacity), '1');
      const styles = await iframe.contentFrame().locator('.top-overlay, .center-play, .controls, .mobile-controls-toggle')
        .evaluateAll(elements => elements.map(element => getComputedStyle(element).display));
      assert(styles.every(display => display === 'none'), 'embed UI is hidden before the iframe is revealed');
    }
    // Every new channel starts hidden, including a fresh ECO player instance.
    metadataGate = new Promise(resolve => { releaseMetadata = resolve; });
    await page.locator('[data-wall-eco]').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-wall-frame][src]').length === 4);
    await assertHidden();
    releaseMetadata();
    await page.waitForFunction(() => document.querySelectorAll('.vw-frame-ready').length === 4);
    console.log('Bootstrap: slow player script, pending metadata and ECO remount never expose embed controls.');
  } finally {
    releaseScript(); releaseMetadata();
    await page.close();
  }
};
