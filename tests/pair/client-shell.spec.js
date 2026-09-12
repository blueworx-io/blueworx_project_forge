import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

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

  await signIn(page);

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
  test('the client pages are reachable from the WordPress admin menu', async ({ browser }) => {
    const { client } = await connectedPair(browser, `${RUN} frame`);
    const page = await client.context.newPage();

    await page.goto(HOME);

    // The tab strip is gone — the side menu is the navigation. What matters is
    // that the client pages still hang off one place rather than being a set of
    // unrelated admin pages with no way in.
    const menu = page.locator('#adminmenu a[href*="page=blueworx-forge-client"]');
    await expect(menu.first()).toBeVisible();
    expect(await menu.count()).toBeGreaterThan(1);

    // And nothing repeats it along the top.
    await expect(page.locator('[data-testid="bwx-client-nav"]')).toHaveCount(0);

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

  // Task 7 moved the scope line out of Nav::render() and into Page::open()'s
  // eyebrow, which only the top-level screen (tested above, at HOME) was built
  // on at the time. Task 10 moved the board's frame (WorkScreen::render()) onto
  // the same shell, so the board now carries the eyebrow too.
  test(
    'the board screen also names the client whose workspace it is',
    async ({ browser }) => {
      const { client } = await connectedPair(browser, `${RUN} board named`);
      const page = await client.context.newPage();

      await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-board');

      await expect(page.locator('[data-testid="bwx-client-scope"]')).toBeVisible();
      await expect(page.locator('[data-testid="bwx-client-scope"]')).toContainText(
        `${RUN} board named`
      );

      await page.close();
    }
  );

  test('no link in the frame carries a client or a site to address', async ({ browser }) => {
    const { client } = await connectedPair(browser, `${RUN} links`);
    const page = await client.context.newPage();

    await page.goto(HOME);

    const links = page.locator('#adminmenu a[href*="page=blueworx-forge-client"]');
    await expect(links.first()).toBeVisible();

    const targets = await links.evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('href') ?? '')
    );

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

  test('the client screens are built on the shared design system shell', async ({ browser }) => {
    const { client } = await connectedPair(browser, `${RUN} shell`);
    const page = await client.context.newPage();

    await page.goto(HOME);

    await expect(page.locator('.bw-admin.bw-page')).toBeVisible();
    await expect(page.locator('.bw-pagehead')).toBeVisible();

    // The design system's stylesheet is actually on the page, not just referenced.
    const loaded = await page.evaluate(() =>
      [...document.styleSheets].some((s) => (s.href || '').includes('blueworx-admin-design.css'))
    );
    expect(loaded, 'the design system stylesheet is enqueued').toBe(true);

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
