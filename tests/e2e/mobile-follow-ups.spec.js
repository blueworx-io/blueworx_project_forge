import { test, expect } from '@playwright/test';
import { onPhone, PHONE } from './helpers/phone.js';

// Follow-ups from the phone review (2026-09-28, #440–#444).

/** Waits for a screen to have drawn something, rather than calling its loading state a pass. */
async function settled(page) {
  await expect(page.locator('[data-state="loading"]')).toHaveCount(0, { timeout: 60_000 });
}

test('an unlabelled value in a phone card sits on the right with the rest (#440)', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  await page.getByTestId('bwx-menu-toggle').click();
  await page.getByRole('navigation', { name: 'Screens', exact: true }).getByTestId('bwx-screen-recurring').click();
  await settled(page);

  const row = page.locator('.fk-table[data-stack="true"] tr.fk-row:has(td:not([data-label]) button)').first();
  await expect(row).toBeVisible();
  const card = await row.boundingBox();
  const content = await row.locator('td:not([data-label]) button').last().boundingBox();
  // Within the card's right padding, as a labelled value's is.
  expect(card.x + card.width - (content.x + content.width)).toBeLessThanOrEqual(16);
  await admin.context.close();
});

test('widening the window with the menu open keeps keyboard focus in the rail (#441)', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  await page.getByTestId('bwx-menu-toggle').click();
  await expect(page.getByTestId('bwx-menu-close')).toBeFocused();

  await page.setViewportSize({ width: 1280, height: 800 });
  await expect(page.getByTestId('bwx-tabbar')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => !! document.activeElement?.closest('#bwx-menu'))).toBe(true);
  await admin.context.close();
});

test('narrowing a desktop window to a phone does not slide the menu across; opening it still does (#442)', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  await page.setViewportSize({ width: 1280, height: 800 });
  await expect(page.getByTestId('bwx-tabbar')).toHaveCount(0);

  await page.setViewportSize(PHONE);
  await expect(page.getByTestId('bwx-tabbar')).toBeVisible();
  const rail = page.locator('#bwx-menu');
  expect(await rail.evaluate((el) => getComputedStyle(el).transitionDuration.split(',').every((d) => '0s' === d.trim())), 'no slide on narrowing').toBe(true);

  await page.getByTestId('bwx-menu-toggle').click();
  expect(await rail.evaluate((el) => getComputedStyle(el).transitionDuration.split(',').some((d) => '0s' !== d.trim())), 'opening slides').toBe(true);
  await admin.context.close();
});

test('with the menu open, the screen behind it is out of reach (#443)', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  const main = page.locator('main.fs-main');
  await expect(main).not.toHaveAttribute('inert', /.*/);

  await page.getByTestId('bwx-menu-toggle').click();
  await expect(main).toHaveAttribute('inert', '');
  await expect(page.getByTestId('bwx-tabbar')).toHaveAttribute('inert', '');
  // Nothing behind the menu can take focus.
  const updates = page.getByTestId('bwx-signals-open');
  await updates.evaluate((el) => el.focus());
  await expect(updates).not.toBeFocused();

  await page.keyboard.press('Escape');
  await expect(main).not.toHaveAttribute('inert', /.*/);
  await admin.context.close();
});

test('the phone-size check is set up once, not on every screen change (#444)', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  await page.evaluate(() => {
    window.__compactListeners = 0;
    const add = MediaQueryList.prototype.addEventListener;
    MediaQueryList.prototype.addEventListener = function (type, ...rest) {
      if ('change' === type && this.media.includes('900px')) window.__compactListeners += 1;
      return add.call(this, type, ...rest);
    };
  });

  for (const testId of ['bwx-tab-standup', 'bwx-tab-requests', 'bwx-tab-mytasks', 'bwx-tab-standup']) {
    await page.getByTestId(testId).click();
    await settled(page);
  }
  expect(await page.evaluate(() => window.__compactListeners)).toBe(0);
  await admin.context.close();
});

test('the bottom bar comes back every time the window goes from desktop to phone width', async ({ browser, baseURL }) => {
  // Found 2026-09-28: after one widen and narrow it never came back until a reload.
  const { page, admin } = await onPhone(browser, baseURL);
  for (let round = 0; round < 2; round += 1) {
    await page.setViewportSize({ width: 1280, height: 800 });
    await expect(page.getByTestId('bwx-tabbar')).toHaveCount(0);
    await page.setViewportSize(PHONE);
    await expect(page.getByTestId('bwx-tabbar'), `round ${round + 1}`).toBeVisible();
  }
  await admin.context.close();
});
