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

  // Picking every request is still one tap on a phone.
  await row.getByRole('checkbox').click();
  await expect(bulk).toBeHidden();
  const all = page.getByRole('checkbox', { name: 'Select every request shown' });
  await expect(all).toBeVisible();
  await all.click();
  await expect(bulk).toBeVisible();
  await expect(row.getByRole('checkbox')).toBeChecked();
  await admin.context.close();
});

/** Anything inside `selector` that ends past the right edge of the screen, and is not inside a sideways scroller of its own. */
async function stickingOut(page, selector) {
  return page.evaluate(({ selector, width }) => {
    const root = document.querySelector(selector);
    if (!root) return [`${selector} not found`];
    const out = [];
    for (const el of root.querySelectorAll('*')) {
      const r = el.getBoundingClientRect();
      if (0 === r.width || r.right <= width + 1) continue;
      let inScroller = false;
      for (let p = el.parentElement; p && p !== root.parentElement; p = p.parentElement) {
        const o = getComputedStyle(p).overflowX;
        if (('auto' === o || 'scroll' === o) && p.getBoundingClientRect().right <= width + 1) { inScroller = true; break; }
      }
      if (!inScroller) out.push(`${el.tagName.toLowerCase()}.${[...el.classList].join('.')}`);
    }
    return out.slice(0, 5);
  }, { selector, width: PHONE.width });
}

const MENU_SCREENS = [
  'bwx-screen-work', 'bwx-screen-gantt', 'bwx-screen-capacity', 'bwx-screen-recurring', 'bwx-screen-reminders',
  'bwx-screen-clients', 'bwx-screen-support', 'bwx-screen-meetings', 'bwx-screen-onboarding',
  'bwx-screen-people', 'bwx-screen-availability', 'bwx-screen-reports', 'bwx-screen-subscriptions', 'bwx-screen-packages',
];

test('every screen in the menu opens on a phone without spilling off the side', async ({ browser, baseURL }) => {
  test.setTimeout(600_000);
  const { page, admin } = await onPhone(browser, baseURL);
  const menu = page.getByRole('navigation', { name: 'Screens', exact: true });

  for (const testId of MENU_SCREENS) {
    await page.getByTestId('bwx-menu-toggle').click();
    await menu.getByTestId(testId).click();
    await settled(page);
    expect(await spillsSideways(page), `${testId} fits the screen`).toBe(false);
    await checkAccessibility(page, `${testId} on a phone`, 'app');
  }

  await page.getByTestId('bwx-menu-toggle').click();
  await page.getByTestId('bwx-menu-profile').click();
  await settled(page);
  expect(await spillsSideways(page), 'your profile fits').toBe(false);
  await admin.context.close();
});

test('on a phone a Kanban column and the Gantt timeline both get room, and a task opens full width', async ({ browser, baseURL }) => {
  test.setTimeout(180_000);
  const { page, admin } = await onPhone(browser, baseURL);
  const menu = page.getByRole('navigation', { name: 'Screens', exact: true });

  await page.getByTestId('bwx-menu-toggle').click();
  await menu.getByTestId('bwx-screen-work').click();
  await settled(page);
  const column = await page.getByTestId('bwx-column').first().boundingBox();
  expect(column.width, 'a column fits the screen, with the next one peeking').toBeLessThanOrEqual(PHONE.width - 24);

  const card = page.getByTestId('bwx-card').first();
  if (await card.count()) {
    await card.click();
    await expect(page.getByTestId('bwx-panel')).toBeVisible();
    await settled(page);
    expect(await stickingOut(page, '[data-testid="bwx-panel"]'), 'nothing in the task panel runs off the screen').toEqual([]);
    await page.keyboard.press('Escape');
  }

  await page.getByTestId('bwx-menu-toggle').click();
  await menu.getByTestId('bwx-screen-gantt').click();
  await settled(page);
  const gantt = await page.getByTestId('bwx-gantt').boundingBox();
  const label = await page.getByTestId('bwx-gantt-row').first().locator(':scope > *').first().boundingBox();
  if (label) {
    expect(label.width, 'the names leave the timeline most of the screen').toBeLessThanOrEqual(gantt.width * 0.4);
  }
  await admin.context.close();
});

test('on a phone the standing meeting form fits the screen', async ({ browser, baseURL }) => {
  test.setTimeout(180_000);
  const { page, admin } = await onPhone(browser, baseURL);
  const RUN = `mtg${Date.now()}`;
  const { site } = await makeSite(admin.api, `Phone meetings ${RUN}`, RUN);
  await page.reload();
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible();

  await page.getByTestId('bwx-client-choice').selectOption(site.id);
  await page.getByTestId('bwx-menu-toggle').click();
  await page.getByRole('navigation', { name: 'Screens', exact: true }).getByTestId('bwx-screen-meetings').click();
  await settled(page);
  await page.getByTestId('bwx-meetings-add').click();
  await expect(page.getByTestId('bwx-meetings-series-form')).toBeVisible();
  expect(await stickingOut(page, '[data-testid="bwx-meetings-series-form"]'), 'nothing in the form runs off the screen').toEqual([]);
  const fields = page.getByTestId('bwx-meetings-series-form').locator('input, select');
  const first = await fields.nth(0).boundingBox();
  expect(first.width, 'fields take the width of the form, one to a line').toBeGreaterThan(PHONE.width / 2);
  await admin.context.close();
});
