import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #170. The working surface for the day, and the one promise it has to keep.
//
// "A dismissed card reappears while its condition holds." Everything below is
// that sentence, checked from both directions: hiding a card does not make the
// count lie, reloading brings it back, and the only thing that removes it for
// good is the condition ceasing to be true.
//
// The instance is kept between runs and other specs leave work behind, so every
// assertion here is scoped to this run's own item.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** A signed-in browser, and one overdue piece of work on a site of its own. */
async function withSomethingLate(browser, baseURL) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Standup Board Co ${RUN_ID}`, RUN_ID);

  const made = await Forge.makeItem(admin.api, site.id, {
    title: `Late thing ${RUN_ID}`,
    planned_start: '2020-01-01',
    planned_due: '2020-01-02',
  });

  expect(made.status(), await made.text()).toBe(200);

  return { admin, item: (await made.json()).item };
}

/** Opens the day's list and waits for it to arrive. */
async function openStandup(page) {
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-standup').click();

  const board = page.getByTestId('bwx-standup');

  // The first request after a page load pays this instance's cold start, which
  // is longer than the default expect timeout and nothing to do with the test.
  await expect(board).toBeVisible({ timeout: 30_000 });

  return board;
}

test('the day’s list shows what is late, in a section that names itself', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, item } = await withSomethingLate(browser, baseURL);
  const page = await admin.context.newPage();

  await openStandup(page);

  const card = page.locator(
    `[data-testid="bwx-standup-card"][data-subject="${item.id}"][data-rule="overdue"]`
  );

  await expect(card).toBeVisible();
  await expect(card).toContainText(`Late thing ${RUN_ID}`);

  // Under the section it belongs to, not in a single undifferentiated list.
  await expect(
    page.locator('[data-testid="bwx-standup-section"][data-section="work"]')
  ).toContainText('Work needing attention');

  await page.close();
  await admin.context.close();
});

test('hiding a card never makes the count lie, and a reload brings it back', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, item } = await withSomethingLate(browser, baseURL);
  const page = await admin.context.newPage();

  await openStandup(page);

  const section = page.locator('[data-testid="bwx-standup-section"][data-section="work"]');
  const card = page.locator(
    `[data-testid="bwx-standup-card"][data-subject="${item.id}"][data-rule="overdue"]`
  );

  await expect(card).toBeVisible();

  const before = await section.getByTestId('bwx-standup-section-count').textContent();

  await card.getByTestId('bwx-standup-dismiss').click();
  await expect(card).toHaveCount(0);

  /*
   * The card is out of the way and the number is not. This is the whole
   * promise: whatever anybody tidies away, the section still says how much
   * there actually is, and says how much of it is hidden.
   */
  const after = await section.getByTestId('bwx-standup-section-count').textContent();

  expect(after).toContain(before.split(' · ')[0]);
  expect(after).toContain('hidden');

  // And it comes straight back, because nothing was recorded anywhere.
  await section.getByTestId('bwx-standup-section-show').click();
  await expect(card).toBeVisible();

  await card.getByTestId('bwx-standup-dismiss').click();
  await expect(card).toHaveCount(0);

  await openStandup(page);
  await expect(card).toBeVisible();

  await page.close();
  await admin.context.close();
});

test('a card goes for good only when its condition stops being true', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, item } = await withSomethingLate(browser, baseURL);
  const page = await admin.context.newPage();

  await openStandup(page);

  const card = page.locator(
    `[data-testid="bwx-standup-card"][data-subject="${item.id}"][data-rule="overdue"]`
  );

  await expect(card).toBeVisible();

  // Moved, not dismissed. The date is the condition, so changing it is the only
  // honest way to clear the card.
  const current = await admin.api.get(`/work-items/${item.id}`);
  const moved = await admin.api.patch(`/work-items/${item.id}`, {
    planned_start: '2099-01-01',
    planned_due: '2099-12-31',
    record_version: current.item.record_version,
  });

  expect(moved.status(), await moved.text()).toBe(200);

  await page.getByTestId('bwx-standup-refresh').click();
  await expect(card).toHaveCount(0);

  // Still gone after a full reload, which a hidden card would not be.
  await openStandup(page);
  await expect(card).toHaveCount(0);

  await page.close();
  await admin.context.close();
});
