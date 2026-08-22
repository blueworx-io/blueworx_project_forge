import { test, expect } from '@playwright/test';

// #126, proven across two real WordPress sites: the client's frame is
// permanently theirs.
//
// The guarantee this is really about is not that the navigation hides other
// clients. It is that the client artifact holds one site id and one signing
// key, so it has no credential for anybody else and nothing it can type will
// invent one. These tests try to widen the scope the ways somebody actually
// would — a parameter on the read, a hand-edited URL, a second client's id —
// and prove each of them changes nothing.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const HOME = '/wp-admin/admin.php?page=blueworx-forge-client';

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

/** A registered, connected pair, plus a second client the first must never see. */
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

  // Somebody else entirely, registered at the studio and never connected here.
  const other = await studio.context.request.post('/wp-json/blueworx-forge/v1/sites', {
    headers: { 'X-WP-Nonce': studio.nonce },
    data: { name: `${name} somebody else`, url: 'https://not-this-site.test' },
  });
  expect(other.status()).toBe(200);

  return { studio, client, site, other: await other.json() };
}

const RUN = `shell${Date.now()}`;

test.describe('the client workspace frame', () => {
  test('the workspace screen carries navigation for the client pages', async ({ browser }) => {
    const { client } = await connectedPair(browser, `${RUN} frame`);
    const page = await client.context.newPage();

    await page.goto(HOME);

    const nav = page.locator('[data-testid="bwx-client-nav"]');
    await expect(nav).toBeVisible();

    // The pages that exist now. Later issues add to this; what matters here is
    // that there is one frame they all hang off rather than a set of unrelated
    // admin pages.
    await expect(nav.locator('[data-testid="bwx-client-nav-item"]')).not.toHaveCount(0);
    await expect(nav).toContainText('Overview');

    await page.close();
  });

  test('the frame names the client whose workspace it is', async ({ browser }) => {
    const { client } = await connectedPair(browser, `${RUN} named`);
    const page = await client.context.newPage();

    await page.goto(HOME);

    // Not decoration. A person administering several client sites needs the
    // screen to say which one they are looking at before they act on it.
    await expect(page.locator('[data-testid="bwx-client-scope"]')).toContainText(`${RUN} named`);

    await page.close();
  });

  test('no link in the frame carries a client or a site to address', async ({ browser }) => {
    const { client } = await connectedPair(browser, `${RUN} links`);
    const page = await client.context.newPage();

    await page.goto(HOME);

    const targets = await page
      .locator('[data-testid="bwx-client-nav"] a')
      .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('href') ?? ''));

    expect(targets.length).toBeGreaterThan(0);

    for (const href of targets) {
      // A navigation that can name a client is a navigation that can be edited
      // to name a different one. The frame carries no such parameter at all.
      expect(href, `${href} carries an addressable scope`).not.toMatch(
        /[?&](client|client_id|site|site_id|client_site_id)=/
      );
    }

    await page.close();
  });

  test('a hand-edited URL cannot point the workspace at another client', async ({ browser }) => {
    const { client, other } = await connectedPair(browser, `${RUN} edited`);
    const page = await client.context.newPage();

    // The most obvious attempt: name somebody else's site in the query string.
    await page.goto(`${HOME}&site_id=${other.site_id}&client_id=${other.site_id}`);

    await expect(page.locator('[data-testid="bwx-client-scope"]')).toContainText(`${RUN} edited`);
    await expect(page.locator('body')).not.toContainText('somebody else');

    await page.close();
  });

  test('the read ignores a site named in the request and answers for the signing site', async ({
    browser,
  }) => {
    const { client, site, other } = await connectedPair(browser, `${RUN} forged`);

    // The same attempt one level down, at the route the screen reads through.
    const response = await client.context.request.get(
      `/wp-json/blueworx-forge-client/v1/workspace?site_id=${other.site_id}&client_site_id=${other.site_id}`,
      { headers: { 'X-WP-Nonce': client.nonce } }
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body.record?.site_id).toBe(site.site_id);
  });
});
