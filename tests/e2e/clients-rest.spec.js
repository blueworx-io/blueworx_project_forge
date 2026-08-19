import { test, expect } from '@playwright/test';

// The endpoints assembled against a real WordPress: unit tests can prove the
// rules, but only a real site proves the routes are registered, the tables are
// there, and the conventions are actually applied rather than merely available.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

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

async function createClient(request, nonce, name) {
  const response = await request.post('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
    data: { display_name: name, timezone: 'Europe/London' },
  });

  expect(response.status()).toBe(200);
  return (await response.json()).client;
}

test('a stranger cannot list or create clients', async ({ request }) => {
  expect([401, 403]).toContain((await request.get('/wp-json/blueworx-forge/v1/clients')).status());

  const created = await request.post('/wp-json/blueworx-forge/v1/clients', {
    data: { display_name: 'Trespass' },
  });
  expect([401, 403]).toContain(created.status());
});

test('a client with two sites has two independent workspaces', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Acme Ltd');

  const sites = [];
  for (const name of ['Acme Main', 'Acme Shop']) {
    const response = await context.request.post(
      `/wp-json/blueworx-forge/v1/clients/${client.id}/sites`,
      { headers: { 'X-WP-Nonce': nonce }, data: { name, url: 'https://example.test' } },
    );
    expect(response.status()).toBe(200);
    sites.push((await response.json()).site);
  }

  expect(sites[0].id).not.toEqual(sites[1].id);

  // Each site answers for itself and never for its sibling.
  for (const site of sites) {
    const response = await context.request.get(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    const body = await response.json();
    expect(body.site.id).toBe(site.id);
    expect(body.site.client_id).toBe(client.id);
  }

  await context.close();
});

test('an edit made against an old version is refused, not merged', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Stale Ltd');

  const first = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'Stale Limited', record_version: client.record_version },
  });
  expect(first.status()).toBe(200);

  const second = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'Something else', record_version: client.record_version },
  });
  expect(second.status()).toBe(409);

  const body = await second.json();
  expect(body.code).toBe('bwx_forge_stale_write');
  // The rejection carries the current state, so the person can see what moved.
  expect(body.data.current.legal_name).toBe('Stale Limited');

  await context.close();
});

test('a write with no version at all is refused', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Versionless Ltd');

  const response = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'No version' },
  });

  expect(response.status()).toBe(400);
  expect((await response.json()).code).toBe('bwx_forge_missing_version');

  await context.close();
});

test('a retried create produces one client, not two', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);

  const send = () =>
    context.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { 'X-WP-Nonce': nonce, 'Idempotency-Key': 'clients-retry-1' },
      data: { display_name: 'Retry Ltd', timezone: 'UTC' },
    });

  const first = await send();
  const second = await send();

  expect((await first.json()).client.id).toBe((await second.json()).client.id);

  const listed = await context.request.get('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
  });
  const named = (await listed.json()).clients.filter((c) => c.display_name === 'Retry Ltd');
  expect(named).toHaveLength(1);

  await context.close();
});

test('an idempotency key is scoped per client, not shared across them', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const clientA = await createClient(context.request, nonce, 'Scoped A Ltd');
  const clientB = await createClient(context.request, nonce, 'Scoped B Ltd');

  const createSite = (client) =>
    context.request.post(`/wp-json/blueworx-forge/v1/clients/${client.id}/sites`, {
      headers: { 'X-WP-Nonce': nonce, 'Idempotency-Key': 'shared-retry-key' },
      data: { name: `${client.display_name} Main` },
    });

  const responseA = await createSite(clientA);
  const responseB = await createSite(clientB);

  expect(responseA.status()).toBe(200);
  expect(responseB.status()).toBe(200);

  const siteA = (await responseA.json()).site;
  const siteB = (await responseB.json()).site;

  expect(siteA.id).not.toEqual(siteB.id);
  expect(siteA.client_id).toBe(clientA.id);
  expect(siteB.client_id).toBe(clientB.id);

  await context.close();
});

test('deactivating a client deactivates its sites', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Closing Ltd');

  const created = await context.request.post(
    `/wp-json/blueworx-forge/v1/clients/${client.id}/sites`,
    { headers: { 'X-WP-Nonce': nonce }, data: { name: 'Closing Main' } },
  );
  const site = (await created.json()).site;

  const closed = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: client.record_version },
  });
  expect(closed.status()).toBe(200);

  const after = await context.request.get(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect((await after.json()).site.status).toBe('inactive');

  await context.close();
});
