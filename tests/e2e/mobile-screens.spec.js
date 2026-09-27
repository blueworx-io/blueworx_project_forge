import { test, expect } from '@playwright/test';
import { onPhone, spillsSideways } from './helpers/phone.js';
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
