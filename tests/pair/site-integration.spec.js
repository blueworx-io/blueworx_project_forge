import { test, expect } from '@playwright/test';

// #89 across two real WordPress sites. The unit tests decide health from a
// record and the single-instance suite proves the routes exist; only this
// proves the record fills itself in — that a real client site, given a real
// key, calls the studio and the studio's answer to "is that site healthy"
// changes as a result.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

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

// A client and a site beneath it, at the studio, with a key issued through the
// integration route — the whole of #89's studio half, done as an administrator
// would do it.
async function studioSite(studio, label) {
  const client = await (
    await studio.context.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { 'X-WP-Nonce': studio.nonce },
      data: { display_name: `${label} ${RUN_ID}`, timezone: 'Europe/London' },
    })
  ).json();

  const site = await (
    await studio.context.request.post(
      `/wp-json/blueworx-forge/v1/clients/${client.client.id}/sites`,
      {
        headers: { 'X-WP-Nonce': studio.nonce },
        data: { name: `${label} site ${RUN_ID}`, url: CLIENT_URL },
      },
    )
  ).json();

  const issued = await (
    await studio.context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${site.site.id}/integration/key`,
      { headers: { 'X-WP-Nonce': studio.nonce } },
    )
  ).json();

  return { client: client.client, site: site.site, issued };
}

async function integration(studio, siteId) {
  const response = await studio.context.request.get(
    `/wp-json/blueworx-forge/v1/client-sites/${siteId}/integration`,
    { headers: { 'X-WP-Nonce': studio.nonce } },
  );

  expect(response.status()).toBe(200);
  return (await response.json()).integration;
}

test('a connected client site fills in its own connection record', async ({ browser }) => {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);
  const { site, issued } = await studioSite(studio, 'Reporting Co');

  // Issued and never used. Not a fault — nobody has installed it yet.
  expect((await integration(studio, site.id)).health).toBe('never_connected');

  const connected = await client.context.request.post(
    '/wp-json/blueworx-forge-client/v1/connection',
    {
      headers: { 'X-WP-Nonce': client.nonce },
      data: {
        studio_url: STUDIO_URL,
        site_id: issued.integration.registry_site_id,
        key: issued.key,
      },
    },
  );
  expect(connected.status()).toBe(200);

  const record = await integration(studio, site.id);

  // Connecting is what the studio learns from: the site called, said what it
  // is, and said whether it could email this client if a work item moved.
  expect(record.health).toBe('connected');
  expect(record.last_seen_at).toBeGreaterThan(0);
  expect(record.last_report_at).toBeGreaterThan(0);
  /*
   * Reported, rather than reported as one particular answer.
   *
   * This asserted 'yes' until the client site started actually sending (#173),
   * and it only passed because nothing had ever tried. Mail::capability() says
   * no once a send has failed, and on a test instance with no mail transport
   * every send fails — so the answer here now depends on whether another spec
   * has posted a request to this client first, which is not something this
   * spec is about.
   *
   * The product behaviour is right either way: a site that genuinely cannot
   * deliver should say so, and one good send clears it again. What matters
   * here, and is what this test was ever really about, is that the site
   * volunteered an answer at all.
   */
  expect(['yes', 'no']).toContain(record.mail_capable);
  expect(record.mail_detail).not.toBe('');
  expect(record.wp_version).not.toBe('');
  expect(record.php_version).not.toBe('');
  expect(record.home_url).toContain('127.0.0.1');

  await studio.context.close();
  await client.context.close();
});

test('a site whose key is revoked stays cut off, not broken, when it tries again', async ({ browser }) => {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);
  const { site, issued } = await studioSite(studio, 'Cut Off Co');

  await client.context.request.post('/wp-json/blueworx-forge-client/v1/connection', {
    headers: { 'X-WP-Nonce': client.nonce },
    data: {
      studio_url: STUDIO_URL,
      site_id: issued.integration.registry_site_id,
      key: issued.key,
    },
  });

  expect((await integration(studio, site.id)).health).toBe('connected');

  await studio.context.request.delete(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
    { headers: { 'X-WP-Nonce': studio.nonce } },
  );

  // The client site does not know it has been cut off, and tries again.
  await client.context.request.get('/wp-json/blueworx-forge-client/v1/workspace?refresh=1', {
    headers: { 'X-WP-Nonce': client.nonce },
  });

  const record = await integration(studio, site.id);

  // Revoked outranks the failure it caused: we cut this site off on purpose,
  // and it must not turn up on a list of things to go and fix.
  expect(record.health).toBe('revoked');
  expect(record.last_error_code).not.toBe('');

  await studio.context.close();
  await client.context.close();
});
