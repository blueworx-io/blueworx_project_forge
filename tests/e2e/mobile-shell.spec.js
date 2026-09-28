import { test, expect } from '@playwright/test';
import { makeSite, makePerson, PASSWORD } from './helpers/forge.js';
import { onPhone, spillsSideways } from './helpers/phone.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// Forge on a phone (2026-09-27, #428): the four daily screens along the
// bottom, everything else behind Menu, and the calendar opening on today.
const RUN = `phone${Date.now()}`;

test('on a phone the four daily screens are along the bottom, and the calendar opens on today', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  const bar = page.getByRole('navigation', { name: 'Quick screens' });
  const title = page.locator('.fs-title');

  await expect(page.getByRole('navigation', { name: 'Screens', exact: true })).toBeHidden();
  await expect(page.getByTestId('bwx-new-task')).toBeHidden();
  await expect(page.getByTestId('bwx-profile')).toBeHidden();

  for (const [testId, name] of [['bwx-tab-mytasks', 'My tasks'], ['bwx-tab-standup', 'Daily standup'], ['bwx-tab-requests', 'Requests review'], ['bwx-tab-calendar', 'Calendar']]) {
    await bar.getByTestId(testId).click();
    await expect(bar.getByTestId(testId)).toHaveAttribute('aria-current', 'page');
    await expect(title).toHaveText(name);
  }

  await expect(page.locator('[data-state="loading"]')).toHaveCount(0, { timeout: 60_000 });
  await expect(page.getByTestId('bwx-calendar')).toHaveAttribute('data-mode', 'day');
  await expect(page.getByTestId('bwx-calendar-mode-day')).toHaveAttribute('aria-pressed', 'true');
  await checkAccessibility(page, 'Phone shell', 'app');
  await admin.context.close();
});

test('the menu holds the rest, and closes on a choice, Escape, a tap outside or a wider window', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  const toggle = page.getByTestId('bwx-menu-toggle');
  const menu = page.getByRole('navigation', { name: 'Screens', exact: true });

  await toggle.click();
  await expect(menu).toBeVisible();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByTestId('bwx-menu-close')).toBeFocused();
  // The four on the bottom bar are not repeated in the menu.
  await expect(menu.getByTestId('bwx-screen-mytasks')).toBeHidden();
  await expect(menu.getByTestId('bwx-screen-calendar')).toBeHidden();
  await expect(menu.getByTestId('bwx-screen-work')).toBeVisible();
  await checkAccessibility(page, 'Phone menu', 'app');

  // Tab stays inside the open menu, both ways round.
  for (const key of ['Tab', 'Shift+Tab']) {
    for (let press = 0; press < 30; press += 1) {
      await page.keyboard.press(key);
      expect(await page.evaluate(() => !! document.activeElement?.closest('#bwx-menu')), `${key} keeps focus in the menu`).toBe(true);
    }
  }

  await menu.getByTestId('bwx-screen-meetings').click();
  await expect(page.locator('.fs-title')).toHaveText('Meetings');
  await expect(menu).toBeHidden();
  await expect(page.getByRole('navigation', { name: 'Quick screens' }).locator('[aria-current="page"]')).toHaveCount(0);

  await toggle.click();
  await expect(menu).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();
  await expect(toggle).toBeFocused();

  await toggle.click();
  await expect(menu).toBeVisible();
  await page.getByTestId('bwx-menu-scrim').click({ position: { x: 370, y: 400 } });
  await expect(menu).toBeHidden();

  // Opened, then the window widens past a phone: the ordinary rail, no scrim.
  await toggle.click();
  await expect(menu).toBeVisible();
  await page.setViewportSize({ width: 1280, height: 800 });
  await expect(menu).toBeVisible();
  await expect(page.getByTestId('bwx-menu-scrim')).toHaveCount(0);
  await expect(page.getByTestId('bwx-tabbar')).toHaveCount(0);
  await admin.context.close();
});

test('New task and your profile are in the menu on a phone', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);

  await page.getByTestId('bwx-menu-toggle').click();
  await page.getByTestId('bwx-menu-profile').click();
  await expect(page.locator('.fs-title')).toHaveText('Your profile');

  await page.getByTestId('bwx-menu-toggle').click();
  await page.getByTestId('bwx-menu-new-task').click();
  await expect(page.locator('.fs-title')).toHaveText('Kanban');
  await expect(page.getByRole('navigation', { name: 'Screens', exact: true })).toBeHidden();
  await admin.context.close();
});

test('a link to a screen lands there on a phone, menu closed', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL, undefined, undefined, '#screen=meetings');
  await expect(page.locator('.fs-title')).toHaveText('Meetings');
  await expect(page.getByRole('navigation', { name: 'Screens', exact: true })).toBeHidden();
  expect(await spillsSideways(page)).toBe(false);
  await admin.context.close();
});

test('a Manager\'s menu on a phone has no administrator screens and no empty headings', async ({ browser, baseURL }) => {
  const { admin: owner } = await onPhone(browser, baseURL);
  const where = await makeSite(owner.api, `Phone Co ${RUN}`, RUN);
  const manager = await makePerson(owner.api, where.client.id, 'staff', `mgr${RUN}`);
  await owner.context.close();

  const { page, admin } = await onPhone(browser, baseURL, manager.login, PASSWORD);
  await page.getByTestId('bwx-menu-toggle').click();
  const menu = page.getByRole('navigation', { name: 'Screens', exact: true });
  await expect(menu).toBeVisible();
  await expect(menu.getByTestId('bwx-screen-people')).toHaveCount(0);
  await expect(menu.getByTestId('bwx-screen-reports')).toHaveCount(0);
  // Team and Insight are dropped for a Manager; My day and Intake are all on the bottom bar.
  for (const heading of ['Team', 'Insight']) {
    await expect(menu.locator('.fs-rail-group', { hasText: heading })).toHaveCount(0);
  }
  for (const heading of ['My day', 'Intake']) {
    await expect(menu.locator('.fs-rail-group', { hasText: heading })).toBeHidden();
  }
  await admin.context.close();
});

test('the client picker sits to the left of Updates, on a desktop and a phone', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  for (const size of [{ width: 390, height: 844 }, { width: 1280, height: 800 }]) {
    await page.setViewportSize(size);
    const picker = await page.getByTestId('bwx-client-choice').boundingBox();
    const updates = await page.getByTestId('bwx-signals-open').boundingBox();
    expect(picker.x + picker.width, `at ${size.width} wide`).toBeLessThanOrEqual(updates.x);
  }
  await admin.context.close();
});

test('on a desktop the top bar keeps its spacing around New task and your profile', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  await page.setViewportSize({ width: 1280, height: 800 });
  const newTask = await page.getByTestId('bwx-new-task').boundingBox();
  const divider = await page.locator('.fs-topbar-divider').boundingBox();
  const profile = await page.getByTestId('bwx-profile').boundingBox();
  // The bar's 16px gap plus the divider's 4px margin, either side, as before #428.
  expect(Math.round(divider.x - (newTask.x + newTask.width))).toBe(20);
  expect(Math.round(profile.x - (divider.x + divider.width))).toBe(20);
  await admin.context.close();
});

test('a desktop still opens the calendar on the month, with no bottom bar', async ({ browser, baseURL }) => {
  const { page, admin } = await onPhone(browser, baseURL);
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.reload();
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible();
  await expect(page.getByTestId('bwx-tabbar')).toHaveCount(0);
  await page.getByTestId('bwx-screen-calendar').click();
  await expect(page.locator('[data-state="loading"]')).toHaveCount(0, { timeout: 60_000 });
  await expect(page.getByTestId('bwx-calendar')).toHaveAttribute('data-mode', 'month');
  await admin.context.close();
});
