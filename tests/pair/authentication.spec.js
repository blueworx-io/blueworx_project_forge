import { test, expect } from '@playwright/test';

// #83, proven across two real WordPress sites: a client site can show which
// client it is, and can be cut off.
//
// The unit tests prove the signature scheme in isolation. This proves the thing
// that actually has to hold — that a real client site, holding only a key,
// reaches a real studio and is refused the moment that key is revoked.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

test.beforeAll(() => {
  if (!CLIENT_URL || !ADMIN_USER || !ADMIN_PASS) {
    throw new Error('BWX_CLIENT_BASE_URL, WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

// Each site needs its own signed-in context and its own REST nonce — they are
// two separate WordPress installs that know nothing about each other.
async function signedIn(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  // wp-admin localises a REST nonce on every screen; the client site has no app
  // page of its own yet, so read it from there rather than from the front end.
  const nonce = await page.evaluate(() => window.wpApiSettings?.nonce);
  await page.close();

  expect(nonce, `no REST nonce available at ${baseURL}`).toBeTruthy();
  return { context, nonce };
}

async function registerSite(studio, name) {
  const response = await studio.context.request.post('/wp-json/blueworx-forge/v1/sites', {
    headers: { 'X-WP-Nonce': studio.nonce },
    data: { name, url: CLIENT_URL },
  });

  expect(response.status()).toBe(200);
  const body = await response.json();

  expect(body.site_id).toBeTruthy();
  expect(body.key).toBeTruthy();
  return body;
}

async function connectClient(client, site) {
  const response = await client.context.request.post('/wp-json/blueworx-forge-client/v1/connection', {
    headers: { 'X-WP-Nonce': client.nonce },
    data: { studio_url: STUDIO_URL, site_id: site.site_id, key: site.key },
  });

  expect(response.status()).toBe(200);
  expect((await response.json()).configured).toBe(true);
}

async function clientConnectionStatus(client) {
  const response = await client.context.request.get('/wp-json/blueworx-forge-client/v1/connection', {
    headers: { 'X-WP-Nonce': client.nonce },
  });

  expect(response.status()).toBe(200);
  return response.json();
}

test('a registered site proves which client it is, and stops when revoked', async ({ browser }) => {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);

  const site = await registerSite(studio, 'Acme Ltd');
  await connectClient(client, site);

  // The client site signs a request with only its key, and the studio confirms
  // which client it is. Nothing else is presented — no user, no password.
  const connected = await clientConnectionStatus(client);
  expect(connected.ok).toBe(true);
  expect(connected.site_id).toBe(site.site_id);
  expect(connected.name).toBe('Acme Ltd');

  // Cut it off from the studio. The client site is not touched and still holds
  // its key — which is the point: revocation has to work without the client's
  // cooperation, because a site you want to cut off is not going to help.
  const revoked = await studio.context.request.post(
    `/wp-json/blueworx-forge/v1/sites/${site.site_id}/revoke`,
    { headers: { 'X-WP-Nonce': studio.nonce } }
  );
  expect(revoked.status()).toBe(200);

  const afterRevoke = await clientConnectionStatus(client);
  expect(afterRevoke.ok).toBe(false);
  expect(afterRevoke.status).toBe(401);

  await studio.context.close();
  await client.context.close();
});

test('rotating a key stops the old one working', async ({ browser }) => {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);

  const site = await registerSite(studio, 'Rotating Ltd');
  await connectClient(client, site);
  expect((await clientConnectionStatus(client)).ok).toBe(true);

  const rotated = await studio.context.request.post(
    `/wp-json/blueworx-forge/v1/sites/${site.site_id}/rotate`,
    { headers: { 'X-WP-Nonce': studio.nonce } }
  );
  expect(rotated.status()).toBe(200);
  const newKey = (await rotated.json()).key;
  expect(newKey).not.toBe(site.key);

  // The client is still holding the old key and is now refused.
  expect((await clientConnectionStatus(client)).ok).toBe(false);

  // Give it the new one and it works again, without re-registering.
  await connectClient(client, { site_id: site.site_id, key: newKey });
  expect((await clientConnectionStatus(client)).ok).toBe(true);

  await studio.context.close();
  await client.context.close();
});

test('a site presenting a key it was never given is refused', async ({ browser }) => {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);

  const site = await registerSite(studio, 'Impostor Ltd');
  await connectClient(client, { site_id: site.site_id, key: 'not-the-real-key' });

  const status = await clientConnectionStatus(client);
  expect(status.ok).toBe(false);
  expect(status.status).toBe(401);

  await studio.context.close();
  await client.context.close();
});

test('an unsigned request to a client route is refused', async ({ request }) => {
  // The bluntest attempt there is, and the one a scanner will make: call the
  // studio's client endpoint with no signature at all.
  const response = await request.get('/wp-json/blueworx-forge/v1/client/handshake');

  expect([401, 403]).toContain(response.status());
});

test('every refusal is logged, and the log is not public', async ({ browser, request }) => {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);

  // This spec makes its own refusal rather than relying on an earlier one. A
  // test that only passes because of what ran before it is a test that will
  // one day pass for the wrong reason, or fail for one.
  const site = await registerSite(studio, 'Logged Ltd');
  await connectClient(client, site);

  const revoked = await studio.context.request.post(
    `/wp-json/blueworx-forge/v1/sites/${site.site_id}/revoke`,
    { headers: { 'X-WP-Nonce': studio.nonce } }
  );
  expect(revoked.status()).toBe(200);

  // The refusal that the log must now contain.
  expect((await clientConnectionStatus(client)).ok).toBe(false);

  // A stranger cannot read the log.
  const unauthenticated = await request.get('/wp-json/blueworx-forge/v1/security-log');
  expect([401, 403]).toContain(unauthenticated.status());

  const response = await studio.context.request.get('/wp-json/blueworx-forge/v1/security-log', {
    headers: { 'X-WP-Nonce': studio.nonce },
  });
  expect(response.status()).toBe(200);

  const refused = (await response.json()).refused;
  expect(Array.isArray(refused)).toBe(true);

  const entry = refused.find((item) => item.site_id === site.site_id);
  expect(entry, 'the refused request left no trace in the log').toBeTruthy();
  expect(entry.reason).toBe('revoked_site');
  expect(typeof entry.time).toBe('number');

  await studio.context.close();
  await client.context.close();
});

test('the studio refuses to register a site for a stranger', async ({ request }) => {
  // Registration is a manual studio action (ARCH-6). There is no enrolment by
  // turning up, and this is the request that would do it.
  const response = await request.post('/wp-json/blueworx-forge/v1/sites', {
    data: { name: 'Self Enrolled', url: 'https://evil.example' },
  });

  expect([401, 403]).toContain(response.status());
});
