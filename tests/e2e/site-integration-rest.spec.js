import { test, expect } from '@playwright/test';

// Issue #89's routes against a real WordPress. The unit tests prove how health
// is decided; only a real site proves the routes exist, the table is there, and
// a key issued through them actually works.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

// Nothing here is ever deleted and idempotency keys are remembered for a day,
// so every name this run writes carries the run with it. A suite that only
// passes against a freshly wiped database is a suite that will fail on its own
// leftovers.
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function signedInContext(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto('/blueworx-forge/');
  const nonce = await page.evaluate(() => window.bwxForgeData?.nonce);
  expect(nonce, 'no REST nonce was localised for the signed-in user').toBeTruthy();

  await page.close();
  return { context, nonce };
}

async function createSite(request, nonce, label) {
  const clientResponse = await request.post('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
    data: { display_name: `${label} ${RUN_ID}`, timezone: 'Europe/London' },
  });
  expect(clientResponse.status()).toBe(200);
  const client = (await clientResponse.json()).client;

  const siteResponse = await request.post(
    `/wp-json/blueworx-forge/v1/clients/${client.id}/sites`,
    {
      headers: { 'X-WP-Nonce': nonce },
      data: { name: `${label} site ${RUN_ID}`, url: 'https://example.test' },
    },
  );
  expect(siteResponse.status()).toBe(200);

  return { client, site: (await siteResponse.json()).site };
}

test('a stranger cannot read or change a site connection', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Locked');
  await context.close();

  const anonymous = await browser.newContext({ baseURL });

  const read = await anonymous.request.get(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration`,
  );
  expect([401, 403]).toContain(read.status());

  const issued = await anonymous.request.post(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
  );
  expect([401, 403]).toContain(issued.status());

  await anonymous.close();
});

test('a site nobody has connected reads as unconfigured, not as broken', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Fresh');

  const response = await context.request.get(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  expect(response.status()).toBe(200);
  const { integration } = await response.json();

  expect(integration.health).toBe('unconfigured');
  expect(integration.key_state).toBe('unissued');
  expect(integration.client_site_id).toBe(site.id);

  await context.close();
});

test('issuing a key shows it once and never again', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Issued');

  const issued = await context.request.post(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  expect(issued.status()).toBe(200);
  const body = await issued.json();

  expect(body.key).toMatch(/^[0-9a-f]{64}$/);
  expect(body.rotated).toBe(false);
  expect(body.integration.key_state).toBe('active');
  expect(body.integration.registry_site_id).toMatch(/^site_[0-9a-f]{16}$/);

  // A key issued and never used is not the same as a broken one.
  expect(body.integration.health).toBe('never_connected');

  const read = await context.request.get(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  const readBody = await read.json();
  expect(JSON.stringify(readBody)).not.toContain(body.key);

  await context.close();
});

test('rotating replaces the key without changing which site it is', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Rotated');

  const first = await (
    await context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
      { headers: { 'X-WP-Nonce': nonce } },
    )
  ).json();

  const second = await (
    await context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
      { headers: { 'X-WP-Nonce': nonce } },
    )
  ).json();

  expect(second.rotated).toBe(true);
  expect(second.key).not.toBe(first.key);
  expect(second.integration.registry_site_id).toBe(first.integration.registry_site_id);
  expect(second.integration.key_rotated_at).toBeGreaterThan(0);

  await context.close();
});

test('revoking cuts the site off and keeps the record', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Revoked');

  await context.request.post(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  const revoked = await context.request.delete(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  expect(revoked.status()).toBe(200);
  const { integration } = await revoked.json();

  // Cut off deliberately, which is not a fault — the record and its history stay.
  expect(integration.health).toBe('revoked');
  expect(integration.key_state).toBe('revoked');
  expect(integration.key_revoked_at).toBeGreaterThan(0);
  expect(integration.id).toBeTruthy();

  await context.close();
});

test('a revoked site issued a new key comes back as a new registration', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Reinstated');

  const first = await (
    await context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
      { headers: { 'X-WP-Nonce': nonce } },
    )
  ).json();

  await context.request.delete(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  const again = await (
    await context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
      { headers: { 'X-WP-Nonce': nonce } },
    )
  ).json();

  // Bringing a site back is a registration decision, not a key operation: the
  // old registry entry stays revoked as the record of what happened.
  expect(again.integration.registry_site_id).not.toBe(first.integration.registry_site_id);
  expect(again.integration.key_state).toBe('active');
  expect(again.integration.health).toBe('never_connected');

  await context.close();
});

test('a retry of the same issue request does not hand out a second key', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Retried');

  const key = `issue-${RUN_ID}`;
  const headers = { 'X-WP-Nonce': nonce, 'Idempotency-Key': key };

  const first = await (
    await context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
      { headers },
    )
  ).json();

  const replay = await (
    await context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
      { headers },
    )
  ).json();

  expect(replay.key).toBe(first.key);
  expect(replay.integration.registry_site_id).toBe(first.integration.registry_site_id);

  await context.close();
});

test('a retry key used on one site cannot answer for another', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const one = await createSite(context.request, nonce, 'First');
  const two = await createSite(context.request, nonce, 'Second');

  const key = `shared-${RUN_ID}`;
  const headers = { 'X-WP-Nonce': nonce, 'Idempotency-Key': key };

  const first = await (
    await context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${one.site.id}/integration/key`,
      { headers },
    )
  ).json();

  const second = await (
    await context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${two.site.id}/integration/key`,
      { headers },
    )
  ).json();

  // Handing site two its neighbour's key would connect one client's site to
  // another client's record.
  expect(second.key).not.toBe(first.key);
  expect(second.integration.client_site_id).toBe(two.site.id);

  await context.close();
});

test("a client's sites carry their connection in the one listing", async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { client, site } = await createSite(context.request, nonce, 'Listed');

  await context.request.post(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  const listed = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/clients/${client.id}/sites`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  const listedSite = listed.sites.find((entry) => entry.id === site.id);

  expect(listedSite.integration.health).toBe('never_connected');
  expect(listedSite.integration.key_state).toBe('active');

  await context.close();
});

test('an inactive site is not handed a working key', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Closed');

  await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: site.record_version },
  });

  const refused = await context.request.post(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  expect(refused.status()).toBe(409);

  await context.close();
});

test('a site with no key has nothing to revoke', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const { site } = await createSite(context.request, nonce, 'Keyless');

  const refused = await context.request.delete(
    `/wp-json/blueworx-forge/v1/client-sites/${site.id}/integration/key`,
    { headers: { 'X-WP-Nonce': nonce } },
  );

  expect(refused.status()).toBe(409);

  await context.close();
});
