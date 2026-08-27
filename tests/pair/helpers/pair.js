import { expect } from '@playwright/test';

// The preamble every two-instance spec pays before it can assert anything:
// sign in to both sites, register a client and a site on the studio, issue a
// key, and connect the client site with it.
//
// It lives here rather than in each spec because it had already been copied
// into every file in this directory, and the copies were beginning to differ.
// A spec should read as the rule it is proving, not as a recipe for getting to
// it — and a helper that is wrong fails every spec loudly, where a helper that
// is copied wrong fails one of them quietly.
//
// Not matched by testMatch (`**/*.spec.js`), so it is a module rather than a
// suite that asserts nothing.

export const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
export const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';

const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const STUDIO_API = '/wp-json/blueworx-forge/v1';
const CLIENT_API = '/wp-json/blueworx-forge-client/v1';

/** Fails the run early and by name, rather than as a confusing timeout. */
export function requireEnvironment() {
  if (!CLIENT_URL || !ADMIN_USER || !ADMIN_PASS) {
    throw new Error('BWX_CLIENT_BASE_URL, WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
}

/**
 * A browser context signed in to one of the two sites, with that site's REST
 * nonce.
 *
 * Each site needs its own: a nonce is issued for a session on one WordPress, so
 * a request to the other carrying it is a request from a stranger.
 */
export async function signedIn(browser, baseURL) {
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

  return {
    context,
    nonce,
    get: (path) =>
      context.request
        .get(`${STUDIO_API}${path}`, { headers: { 'X-WP-Nonce': nonce } })
        .then((r) => r.json()),
    post: (path, data) =>
      context.request.post(`${STUDIO_API}${path}`, { headers: { 'X-WP-Nonce': nonce }, data }),
    patch: (path, data) =>
      context.request.patch(`${STUDIO_API}${path}`, { headers: { 'X-WP-Nonce': nonce }, data }),
  };
}

/** A client, a site and a signing key, all on the studio. */
export async function studioSite(studio, label, runId) {
  const client = await (
    await studio.post('/clients', { display_name: `${label} ${runId}`, timezone: 'Europe/London' })
  ).json();

  const site = await (
    await studio.post(`/clients/${client.client.id}/sites`, {
      name: `${label} site ${runId}`,
      url: CLIENT_URL,
    })
  ).json();

  const issued = await (
    await studio.post(`/client-sites/${site.site.id}/integration/key`, {})
  ).json();

  return { client: client.client, site: site.site, issued };
}

/** Points the client site at the studio with one of those keys. */
export async function connect(client, issued) {
  const response = await client.context.request.post(`${CLIENT_API}/connection`, {
    headers: { 'X-WP-Nonce': client.nonce },
    data: {
      studio_url: STUDIO_URL,
      site_id: issued.integration.registry_site_id,
      key: issued.key,
    },
  });

  expect(response.status(), await response.text()).toBe(200);
}

/**
 * Makes the client site forget its credentials, the way a person would.
 *
 * Through the screen rather than through a route, because there is no route:
 * forgetting credentials is a thing somebody does on their own site, behind a
 * confirm dialog, and a spec that reached past it would be testing a door the
 * product does not have.
 */
export async function disconnect(clientSite) {
  const page = await clientSite.context.newPage();

  page.on('dialog', (dialog) => dialog.accept());

  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-connection');

  const button = page.locator('[data-bwx-action="bwx_forge_client_disconnect"]');

  // A site with no credentials has no button to forget them with, which is the
  // state this was asked for anyway. Both sites here are shared and long-lived,
  // so a spec that insisted on the button would fail whenever it ran second.
  if (0 < (await button.count())) {
    await button.click();
    await expect(page.locator('[data-bwx-result="disconnected"]')).toHaveCount(1);
  }

  await page.close();
}

/** A piece of work on one of those sites, made by the studio. */
export async function makeItem(studio, siteId, data = {}) {
  const created = await studio.post('/work-items', {
    client_site_id: siteId,
    level: 'sub-feature',
    work_type: 'feature',
    title: 'Something being done',
    problem: 'Something needs doing.',
    ...data,
  });

  expect(created.status(), await created.text()).toBe(200);

  return (await created.json()).item;
}

/**
 * Both sites, connected, with one piece of work on the client's.
 *
 * The shape almost every spec here wants, so that a spec's first ten lines are
 * about what it is testing rather than about getting two WordPress installs to
 * know about each other.
 *
 * `clientSite` is the browser signed in to the client's WordPress; `client` is
 * the client record on the studio. Two different things that both want to be
 * called "the client", so neither of them is.
 */
export async function connectedPair(browser, label, runId, item = {}) {
  const studio = await signedIn(browser, STUDIO_URL);
  const clientSite = await signedIn(browser, CLIENT_URL);
  const registered = await studioSite(studio, label, runId);

  await connect(clientSite, registered.issued);

  return {
    studio,
    clientSite,
    client: registered.client,
    site: registered.site,
    issued: registered.issued,
    work: await makeItem(studio, registered.site.id, item),
    async close() {
      await studio.context.close();
      await clientSite.context.close();
    },
  };
}
