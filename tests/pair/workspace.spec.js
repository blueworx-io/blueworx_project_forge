import { test, expect } from '@playwright/test';

// #84, proven across two real WordPress sites: a client site renders a record
// it does not hold, and says honestly how old what it is showing is.
//
// The unit tests prove the read-through rules in isolation. This proves the
// thing that actually has to hold — that the record on the client's screen came
// from the studio's database, and that cutting the site off changes what the
// screen says rather than silently showing the same thing forever.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-client';

test.beforeAll(() => {
  if (!CLIENT_URL || !ADMIN_USER || !ADMIN_PASS) {
    throw new Error('BWX_CLIENT_BASE_URL, WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

async function signedIn(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  const nonce = await page.evaluate(() => window.wpApiSettings?.nonce);
  await page.close();

  expect(nonce, `no REST nonce available at ${baseURL}`).toBeTruthy();
  return { context, nonce };
}

// A site registered at the studio and connected at the client, which is the
// state every test here starts from. Each test registers its own so that none
// of them depends on what ran before it.
async function connectedPair(browser, name) {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);

  const registered = await studio.context.request.post('/wp-json/blueworx-forge/v1/sites', {
    headers: { 'X-WP-Nonce': studio.nonce },
    data: { name, url: CLIENT_URL },
  });
  expect(registered.status()).toBe(200);
  const site = await registered.json();

  const connected = await client.context.request.post(
    '/wp-json/blueworx-forge-client/v1/connection',
    {
      headers: { 'X-WP-Nonce': client.nonce },
      data: { studio_url: STUDIO_URL, site_id: site.site_id, key: site.key },
    }
  );
  expect(connected.status()).toBe(200);

  return { studio, client, site };
}

async function workspace(client, { refresh = false } = {}) {
  const response = await client.context.request.get(
    `/wp-json/blueworx-forge-client/v1/workspace${refresh ? '?refresh=1' : ''}`,
    { headers: { 'X-WP-Nonce': client.nonce } }
  );

  expect(response.status()).toBe(200);
  return response.json();
}

test('the client site renders a studio record it does not hold', async ({ browser }) => {
  const { studio, client } = await connectedPair(browser, 'Read Through Ltd');

  const view = await workspace(client);

  expect(view.ok).toBe(true);
  expect(view.sync.state).toBe('live');

  // The client site was handed two things at connection time: an id and a key.
  // It was never told its own name, its address as the studio has it, or when
  // it was connected — so a record carrying all three came from the studio's
  // database and nowhere else. That is ARCH-2 holding: the client renders a
  // canonical record it does not have.
  expect(view.record.name).toBe('Read Through Ltd');
  expect(view.record.url).toBe(CLIENT_URL);
  expect(view.record.status).toBe('active');
  expect(view.record.connected_since).toBeGreaterThan(0);

  await studio.context.close();
  await client.context.close();
});

test('the workspace screen shows the record and how old it is', async ({ browser }) => {
  const { studio, client } = await connectedPair(browser, 'Screen Ltd');

  const page = await client.context.newPage();
  await page.goto(SCREEN);

  // Scoped to the record itself: since #126 the frame above also names the
  // client, and this test is about the record having come from the studio.
  await expect(page.locator('[data-bwx-workspace]')).toContainText('Screen Ltd');
  await expect(page.locator('[data-bwx-sync-state]')).toContainText('Last synced');

  const state = await page.locator('[data-bwx-sync-state]').getAttribute('data-bwx-sync-state');
  expect(['live', 'cached']).toContain(state);

  await page.close();
  await studio.context.close();
  await client.context.close();
});

// A revoked site used to be shown its last copy, labelled stale, on the grounds
// that ARCH-4 keeps a client site working while the studio cannot be reached.
// The studio *was* reached here and said no, which is a different thing
// entirely: going on showing the record is a site reading with a key the studio
// has already refused (D-7, D-8), and calling it an outage blames the network
// for a boundary (#133, #134).
test('a cut-off site stops showing what it last saw, and says it was refused', async ({
  browser,
}) => {
  const { studio, client, site } = await connectedPair(browser, 'Cut Off Ltd');

  await workspace(client);

  const revoked = await studio.context.request.post(
    `/wp-json/blueworx-forge/v1/sites/${site.site_id}/revoke`,
    { headers: { 'X-WP-Nonce': studio.nonce } }
  );
  expect(revoked.status()).toBe(200);

  // Ask again on purpose rather than waiting out the staleness window. This is
  // what the screen's "Check again" link does.
  const view = await workspace(client, { refresh: true });

  expect(view.record, 'a revoked site went on showing the record it last saw').toBeNull();
  expect(view.sync.state).toBe('refused');
  expect(view.sync.stale, 'a refusal has nothing to be out of date about').toBe(false);
  expect(view.sync.status).toBe(401);

  const page = await client.context.newPage();
  await page.goto(SCREEN);

  // The screen says what happened, in a sentence that does not send anybody
  // looking for a network problem.
  await expect(page.locator('[data-bwx-sync-state="refused"]')).toContainText(
    'Your connection is working'
  );

  // And the record itself is gone rather than left on screen behind the notice.
  await expect(page.locator('[data-bwx-workspace]')).toHaveCount(0);

  await page.close();
  await studio.context.close();
  await client.context.close();
});

test('the workspace is not readable by a stranger', async ({ request }) => {
  // The record names the client and when their site was connected, so this
  // route is no more public than the connection one.
  const response = await request.get(`${CLIENT_URL}/wp-json/blueworx-forge-client/v1/workspace`);

  expect([401, 403]).toContain(response.status());
});
