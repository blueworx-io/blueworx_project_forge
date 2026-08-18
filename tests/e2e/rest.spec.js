import { test, expect } from '@playwright/test';

// Unit tests prove each permission callback in isolation. This proves the
// assembled system: the routes are actually registered, and the gated one
// actually refuses a stranger. A controller that was never registered, or a
// permission callback that was never attached, would leave the unit tests green
// and the live API open.

test('the status route answers without a login', async ({ request }) => {
  const response = await request.get('/wp-json/blueworx-forge/v1/status');
  expect(response.status()).toBe(200);

  const body = await response.json();
  expect(body.plugin).toBe('blueworx-forge');
  expect(typeof body.version).toBe('string');
  expect(body.ready).toBe(true);
});

test('a write is refused without permission', async ({ request }) => {
  const response = await request.post('/wp-json/blueworx-forge/v1/status/echo', {
    data: { message: 'hello' },
  });

  // 401 logged out, 403 logged in without the capability. Either is a refusal;
  // anything 2xx is the failure this spec exists to catch.
  expect([401, 403]).toContain(response.status());
});

test('an unknown route under the namespace is a 404, not a 500', async ({ request }) => {
  const response = await request.get('/wp-json/blueworx-forge/v1/nope');
  expect(response.status()).toBe(404);
});
