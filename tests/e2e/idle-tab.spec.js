import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

// #701. A Forge tab left open must not keep asking the server for things.
// Once the bell's and the standup's answers took more than five seconds, every
// re-check looked like a change (each carries the time it was worked out), the
// change reloaded every screen, and each reload re-checked again — for ever.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

const SLOW = /\/blueworx-forge\/v1\/(signals|standup)(\?|$)/;

test('an open tab with slow answers goes quiet', async ({ page }) => {
  test.slow();

  await signIn(page, ADMIN_USER, ADMIN_PASS);

  // The busy server: the two answers that kept the loop going take six seconds.
  await page.route(SLOW, async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 6_000));
    await route.continue();
  });

  const bell = page.waitForResponse((response) => /\/signals(\?|$)/.test(response.url()), { timeout: 60_000 });

  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 60_000 });
  await bell;

  // Somebody looks at the standup and goes back to the board: the standup is
  // re-checked, which is what used to set the loop off.
  await page.getByTestId('bwx-screen-standup').click();
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 60_000 });
  await page.getByTestId('bwx-screen-work').click();
  await page.waitForTimeout(15_000);

  const asked = [];
  page.on('request', (request) => {
    if (SLOW.test(request.url()) && 'GET' === request.method()) asked.push(request.url());
  });

  // Half a minute of nobody touching anything: the old loop asked every six seconds.
  await page.waitForTimeout(30_000);

  expect(asked, 'requests made by an idle tab').toEqual([]);
});
