import { test, expect } from '@playwright/test';
import { makeSite, asClientSite, makeSubmission } from './helpers/forge.js';
import { onPhone, spillsSideways, PHONE } from './helpers/phone.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// The studio's screens on a phone (2026-09-27, #429–#431): each one fits the
// width, with anything wide scrolling inside itself rather than the page.

/** Waits for a screen to have drawn something, rather than calling its loading state a pass. */
async function settled(page) {
  await expect(page.locator('[data-state="loading"]')).toHaveCount(0, { timeout: 60_000 });
}

test('My tasks and Daily standup fit a phone', async ({ browser, baseURL }) => {
  test.setTimeout(180_000);
  const { page, admin } = await onPhone(browser, baseURL);

  await page.getByTestId('bwx-tab-mytasks').click();
  await settled(page);
  expect(await spillsSideways(page), 'My tasks fits').toBe(false);
  await checkAccessibility(page, 'My tasks on a phone', 'app');

  await page.getByTestId('bwx-tab-standup').click();
  await settled(page);
  expect(await spillsSideways(page), 'Daily standup fits').toBe(false);
  for (const lines of await page.locator('.bwx-diary-lines').all()) {
    const box = await lines.boundingBox();
    if (box) {
      expect(box.x + box.width, 'the diary fits').toBeLessThanOrEqual(390);
    }
  }
  await checkAccessibility(page, 'Daily standup on a phone', 'app');
  await admin.context.close();
});

test('Requests review works on a phone: a request is a card, and picking it shows the actions above the bottom bar', async ({ browser, baseURL, request }) => {
  test.setTimeout(180_000);
  const { page, admin } = await onPhone(browser, baseURL);
  const RUN = `req${Date.now()}`;
  const asked = await makeSite(admin.api, `Phone requests ${RUN}`, RUN);
  const site = await asClientSite(admin.api, asked.site.id, request);
  await makeSubmission(site, { title: `Phone request ${RUN}` });

  await page.getByTestId('bwx-tab-requests').click();
  await settled(page);
  expect(await spillsSideways(page), 'Requests fits').toBe(false);

  const row = page.getByTestId('bwx-queue-row').filter({ hasText: `Phone request ${RUN}` });
  await expect(row).toBeVisible();
  await expect(row.locator('td[data-label="Client"]')).toBeVisible();
  const card = await row.boundingBox();
  expect(card.x + card.width, 'the card fits').toBeLessThanOrEqual(PHONE.width);

  await row.getByRole('checkbox').click();
  const bulk = page.getByTestId('bwx-queue-bulk');
  await expect(bulk).toBeVisible();
  const bar = await page.getByTestId('bwx-tabbar').boundingBox();
  const box = await bulk.boundingBox();
  expect(box.x, 'the actions start on screen').toBeGreaterThanOrEqual(0);
  expect(box.x + box.width, 'the actions end on screen').toBeLessThanOrEqual(PHONE.width);
  expect(box.y + box.height, 'the actions sit above the bottom bar').toBeLessThanOrEqual(bar.y);
  await checkAccessibility(page, 'Requests on a phone', 'app');
  await admin.context.close();
});
