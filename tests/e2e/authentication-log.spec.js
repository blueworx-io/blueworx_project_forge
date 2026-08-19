import { test, expect } from '@playwright/test';

// #202. One request must leave one entry in the security log.
//
// WordPress calls a route's permission callback twice — the second time from
// rest_send_allow_header(), to work out the Allow header — and our callback
// consumes a single-use nonce, so the second call was logged as a replayed
// request. This is the regression test for that, written against a real
// WordPress because the double call comes from WordPress, not from us: a unit
// test can only check the shape of the fix, never that core still behaves the
// way the fix assumes.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

test('one refused request leaves one entry in the security log', async ({ page, context }) => {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  const nonce = await page.evaluate(() => window.wpApiSettings?.nonce);
  expect(nonce, 'no REST nonce available').toBeTruthy();

  const count = async () => {
    const response = await context.request.get('/wp-json/blueworx-forge/v1/security-log', {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(response.status()).toBe(200);
    return (await response.json()).refused.length;
  };

  const before = await count();

  // One unsigned request to a route that requires a signature.
  const refused = await context.request.get('/wp-json/blueworx-forge/v1/client/handshake');
  expect([401, 403]).toContain(refused.status());

  expect(await count(), 'one request was logged more than once').toBe(before + 1);
});
