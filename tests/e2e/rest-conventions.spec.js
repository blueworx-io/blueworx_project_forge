import { test, expect } from '@playwright/test';

// The unit tests prove each convention in isolation. This proves them assembled,
// against a real WordPress, through the reference write — because the failure
// that matters is a helper that is correct and never actually called.

const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

test.beforeAll(() => {
  if (!ADMIN_USER || !ADMIN_PASS) {
    throw new Error('WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

// The write requires manage_options, so these run as the administrator. Signing
// in through wp-login and reusing the cookie jar is what a browser does; the
// REST nonce comes from the app page, which localises one for the current user.
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

async function currentVersion(request, nonce) {
  const response = await request.get('/wp-json/blueworx-forge/v1/status', {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return (await response.json()).record_version;
}

test('a write made against the current version is accepted', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const version = await currentVersion(context.request, nonce);

  const response = await context.request.post('/wp-json/blueworx-forge/v1/status/echo', {
    headers: { 'X-WP-Nonce': nonce },
    data: { message: 'current', record_version: version },
  });

  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.ok).toBe(true);
  // The write moved the record on, so the next write must be made against the
  // new version rather than the one this caller started with.
  expect(body.record.version).toBe(version + 1);

  await context.close();
});

test('a stale write is rejected and the current state comes back with it', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const version = await currentVersion(context.request, nonce);

  const response = await context.request.post('/wp-json/blueworx-forge/v1/status/echo', {
    headers: { 'X-WP-Nonce': nonce },
    data: { message: 'stale', record_version: version - 1 },
  });

  expect(response.status()).toBe(409);

  const body = await response.json();
  expect(body.code).toBe('bwx_forge_stale_write');
  // ARCH-5: rejected and the current state returned, never merged. Without the
  // current state the caller cannot see what moved underneath them.
  expect(body.data.current_version).toBe(version);
  expect(body.data.current).toBeTruthy();

  // And it changed nothing.
  expect(await currentVersion(context.request, nonce)).toBe(version);

  await context.close();
});

test('a write with no version at all is refused', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);

  const response = await context.request.post('/wp-json/blueworx-forge/v1/status/echo', {
    headers: { 'X-WP-Nonce': nonce },
    data: { message: 'versionless' },
  });

  expect(response.status()).toBe(400);
  expect((await response.json()).code).toBe('bwx_forge_missing_version');

  await context.close();
});

test('a replayed write under one idempotency key produces one record', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const version = await currentVersion(context.request, nonce);
  const key = `replay-${version}-${process.pid}`;

  const send = () =>
    context.request.post('/wp-json/blueworx-forge/v1/status/echo', {
      headers: { 'X-WP-Nonce': nonce, 'Idempotency-Key': key },
      data: { message: 'once', record_version: version },
    });

  const first = await send();
  expect(first.status()).toBe(200);
  const firstBody = await first.json();

  // The retry is made against the version the first attempt already moved past.
  // It must still succeed: a replay is answered from the remembered response
  // before the version is looked at, or every retry would be refused as stale.
  const second = await send();
  expect(second.status()).toBe(200);
  const secondBody = await second.json();

  expect(secondBody).toEqual(firstBody);
  expect(secondBody.record.writes).toBe(firstBody.record.writes);
  expect(await currentVersion(context.request, nonce)).toBe(firstBody.record.version);

  await context.close();
});

test('an unusable idempotency key is refused rather than stored', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const version = await currentVersion(context.request, nonce);

  const response = await context.request.post('/wp-json/blueworx-forge/v1/status/echo', {
    headers: { 'X-WP-Nonce': nonce, 'Idempotency-Key': '../../etc/passwd' },
    data: { message: 'bad key', record_version: version },
  });

  expect(response.status()).toBe(400);
  expect((await response.json()).code).toBe('bwx_forge_invalid_idempotency_key');

  await context.close();
});
