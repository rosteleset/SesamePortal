const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const {execFileSync, spawn} = require('node:child_process');
const {mkdtempSync, mkdirSync, rmSync} = require('node:fs');
const {tmpdir} = require('node:os');
const {join, resolve} = require('node:path');
const {createServer} = require('node:net');
const {once} = require('node:events');

(async () => {
  const root = resolve(__dirname, '..');
  const state = mkdtempSync(join(tmpdir(), 'portal-wall-browser-'));
  const artifacts = process.env.SESAME_BROWSER_ARTIFACTS || '/tmp/portal-ptz-users';
  let browser, server;
  try {
    const socket = createServer();
    await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    const base = 'http://127.0.0.1:' + port;
    const env = {...process.env, SESAME_PORTAL_STATE_DIR:state,
      SESAME_PORTAL_DB_DSN:'sqlite:' + state + '/portal.sqlite',
      SESAME_PORTAL_SECRET:'local-ptz-test', SESAME_PORTAL_UPDATE_AUTO_CHECK:'0'};
    execFileSync('php', ['tests/video_walls_browser_fixture.php', base], {cwd:root, env});
    server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'public', 'tests/video_walls_router.php'],
      {cwd:root, env, stdio:'ignore'});
    for (let attempt = 0; ; attempt++) {
      try { if ((await fetch(base + '/login')).ok) break; } catch (_) {}
      if (attempt >= 50) throw new Error('PHP fixture did not start');
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    browser = await chromium.launch({headless:true, channel:'chrome'});
    const context = await browser.newContext({viewport:{width:1440,height:1000}});
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage(), errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/login?lang=ru');
    await page.locator('[name="login"]').fill('wall-demo');
    await page.locator('[name="password"]').fill('wall-demo123');
    await page.locator('button[type="submit"],button.primary').first().click();
    await page.waitForURL(base + '/');
    await page.goto(base + '/admin/users');
    assert.equal(await page.getByLabel('Разрешить PTZ', {exact:true}).isChecked(), false);
    assert.equal(await page.getByRole('columnheader', {name:'PTZ', exact:true}).count(), 0);
    assert.equal(await page.locator('.table-users th').count(), 8, 'User table omits the PTZ column');
    await page.locator('[name="login"]').fill('ptz-viewer');
    await page.locator('[name="password"]').fill('ptz-viewer123');
    await page.locator('[data-submit-button]').click();
    await page.getByText('Пользователь сохранён', {exact:true}).waitFor();
    const users = await (await context.request.get(base + '/api/portal/v1/users')).json();
    const user = users.users.find(u => u.login === 'ptz-viewer');
    assert.equal(user.ptzAllowed, false);
    const endpoint = base + '/api/portal/v1/users/' + user.id;
    await context.request.patch(endpoint, {data:{groupIds:[1]}});
    const {token} = await (await context.request.post(endpoint + '/static-token')).json();
    const permission = async () => (await (await context.request.get(base + '/api/sesamedvr/auth',
      {params:{name:'demo-1',proto:'ptz',token}})).json()).ptz_allowed;
    assert.equal(await permission(), false);
    await page.goto(base + '/admin/users?edit=' + user.id);
    await page.getByLabel('Разрешить PTZ', {exact:true}).check();
    await page.locator('[data-submit-button]').click();
    await page.getByText('Пользователь сохранён', {exact:true}).waitFor();
    assert.equal(await permission(), true);
    await page.reload();
    assert.equal(await page.getByLabel('Разрешить PTZ', {exact:true}).isChecked(), true);
    mkdirSync(artifacts, {recursive:true});
    for (const width of [1440, 390]) {
      await page.setViewportSize({width,height:1000});
      const checkbox = page.getByLabel('Разрешить PTZ', {exact:true});
      await checkbox.scrollIntoViewIfNeeded();
      const box = await checkbox.locator('..').boundingBox();
      assert(box.x >= 0 && box.x + box.width <= width, 'PTZ checkbox fits the viewport');
      assert(await page.locator('.table-users .pill').evaluateAll(elements => elements.every(el => {
        const range = document.createRange();
        range.selectNodeContents(el);
        return range.getClientRects().length === 1;
      })), 'User status labels must not wrap');
      assert.equal(await page.getByRole('columnheader', {name:'PTZ', exact:true}).count(), 0);
      await page.screenshot({path:join(artifacts, 'users-' + width + '.png')});
    }
    await page.getByLabel('Разрешить PTZ', {exact:true}).uncheck();
    await page.locator('[name="password"]').fill('changed-viewer123');
    await page.locator('[data-submit-button]').click();
    await page.getByText('Пользователь сохранён', {exact:true}).waitFor();
    assert.equal(await permission(), false, 'UI revocation applies on the next auth request');
    await context.request.patch(endpoint, {data:{ptzAllowed:true}});
    const preserved = await (await context.request.patch(endpoint, {data:{adminComment:'keep PTZ'}})).json();
    assert.equal(preserved.user.ptzAllowed, true);
    await context.request.patch(endpoint, {data:{ptz_allowed:false,role:'admin'}});
    assert.equal(await permission(), true, 'Admin role overrides the disabled flag');
    await context.request.patch(endpoint, {data:{role:'user'}});
    assert.equal(await permission(), false, 'Demotion restores the stored disabled permission');
    const viewer = await browser.newContext();
    const viewerPage = await viewer.newPage();
    await viewerPage.goto(base + '/login');
    await viewerPage.locator('[name="login"]').fill('ptz-viewer');
    await viewerPage.locator('[name="password"]').fill('changed-viewer123');
    await viewerPage.locator('button[type="submit"],button.primary').first().click();
    await viewerPage.waitForURL(base + '/');
    assert.equal((await viewer.request.patch(endpoint, {data:{ptzAllowed:true}})).status(), 403);
    assert.equal(await permission(), false, 'Viewer cannot grant themselves PTZ');
    assert.deepEqual(errors, []);
    console.log('User PTZ browser: defaults, UI save/revoke, API preservation, admin override/demotion, self-escalation denial and desktop/mobile passed.');
  } finally {
    if (browser) await browser.close();
    if (server && server.exitCode === null) { const exited = once(server, 'exit'); server.kill(); await exited; }
    rmSync(state, {recursive:true, force:true});
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
