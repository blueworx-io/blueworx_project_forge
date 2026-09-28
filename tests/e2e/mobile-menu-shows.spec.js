import { test, expect } from '@playwright/test';
import { onPhone, PHONE } from './helpers/phone.js';

// #456. On a phone the bottom bar and its Menu are on the screen, where a
// thumb can reach them — not below the fold of a page that cannot scroll.
// Taps go where the button is drawn, so nothing is scrolled into view first.

/** The middle of an element, and whether that point on the screen is it. */
async function tapPoint(page, testId) {
  return page.evaluate((id) => {
    const el = document.querySelector(`[data-testid="${id}"]`);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const x = r.left + r.width / 2;
    const y = r.top + r.height / 2;
    const hit = document.elementFromPoint(x, y);
    return { x, y, top: r.top, bottom: r.bottom, hits: !!hit && el.contains(hit) };
  }, testId);
}

test('on a phone the bottom bar is on screen, and its menu opens, closes and reaches every screen', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  const menu = page.getByRole('navigation', { name: 'Screens', exact: true });
  const toggle = page.getByTestId('bwx-menu-toggle');

  // The bar sits inside the window, and a tap on Menu lands on Menu.
  const bar = await page.getByTestId('bwx-tabbar').boundingBox();
  expect(bar).not.toBeNull();
  expect(bar.y).toBeGreaterThanOrEqual(0);
  expect(bar.y + bar.height).toBeLessThanOrEqual(PHONE.height);

  const menuAt = await tapPoint(page, 'bwx-menu-toggle');
  expect(menuAt.hits, 'Menu is the thing at its own spot on the screen').toBe(true);

  // Opens and closes by taps where they are drawn.
  await page.mouse.click(menuAt.x, menuAt.y);
  await expect(menu).toBeVisible();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');

  // The menu slides in; its close button is tappable once it has arrived.
  await expect.poll(async () => (await tapPoint(page, 'bwx-menu-close'))?.hits).toBe(true);
  const closeAt = await tapPoint(page, 'bwx-menu-close');
  await page.mouse.click(closeAt.x, closeAt.y);
  await expect(menu).toBeHidden();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');

  // Every screen the desktop rail offers is on the bar or in the menu.
  await page.setViewportSize({ width: 1280, height: 800 });
  const desktop = await menu.locator('[data-testid^="bwx-screen-"], [data-testid^="bwx-link-"]').evaluateAll(
    (els) => els.map((el) => el.getAttribute('data-testid'))
  );
  expect(desktop.length).toBeGreaterThan(4);

  await page.setViewportSize(PHONE);
  await expect(page.getByTestId('bwx-tabbar')).toBeVisible();
  const onBar = { 'bwx-screen-mytasks': 'bwx-tab-mytasks', 'bwx-screen-standup': 'bwx-tab-standup', 'bwx-screen-requests': 'bwx-tab-requests', 'bwx-screen-calendar': 'bwx-tab-calendar' };

  const reopen = await tapPoint(page, 'bwx-menu-toggle');
  expect(reopen.hits).toBe(true);
  await page.mouse.click(reopen.x, reopen.y);
  await expect(menu).toBeVisible();

  for (const testId of desktop) {
    if (onBar[testId]) {
      await expect(page.getByTestId(onBar[testId]), `${testId} is on the bottom bar`).toBeVisible();
    } else {
      await expect(menu.getByTestId(testId), `${testId} is in the menu`).toBeVisible();
    }
  }

  await admin.context.close();
});
