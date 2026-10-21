import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #571 and #572. Work waiting on the client has its own standup section, and
// the standup can look at today, tomorrow or the next seven days.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

const section = (page, id) => page.locator(`[data-testid="bwx-standup-section"][data-section="${id}"]`);

async function inReview(api, siteId, title, reviewer, due) {
  const made = await Forge.makeItem(api, siteId, { title });
  expect(made.status(), await made.text()).toBe(200);
  const item = (await made.json()).item;
  const edited = await api.patch(`/work-items/${item.id}`, {
    reviewer_id: reviewer,
    planned_start: '2020-01-01',
    planned_due: due,
    hours_primary: 1,
    record_version: item.record_version,
  });
  expect(edited.status(), await edited.text()).toBe(200);
  const moved = await api.post(`/work-items/${item.id}/override`, {
    to: 'in-review',
    reason: 'Set up for the test.',
    record_version: (await edited.json()).item.record_version,
  });
  expect(moved.status(), await moved.text()).toBe(200);

  return item;
}

test('client review is its own section; a team reviewer stays in work needing attention', async ({ browser, baseURL }) => {
  test.setTimeout(240_000);

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, `Client Review Co ${RUN}`, RUN);
  const person = await Forge.makePerson(admin.api, client.id, 'staff', `rev${RUN}`);

  const withClient = await inReview(admin.api, site.id, `Client reviews ${RUN}`, 'client', '2020-01-02');
  const withTeam = await inReview(admin.api, site.id, `Team reviews ${RUN}`, person.id, '2020-01-02');

  // The list itself: the client's item carries one card, the team's carries both.
  const list = await admin.api.get('/standup');
  const rulesOf = (id) => list.cards.filter((c) => c.subject_id === id).map((c) => c.rule);
  expect(rulesOf(withClient.id)).toEqual(['awaiting-review']);
  expect(rulesOf(withTeam.id).sort()).toEqual(['awaiting-review', 'overdue']);

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/#screen=standup');
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 60_000 });
  await page.getByRole('button', { name: 'Requires Client Review' }).click();
  await page.getByRole('button', { name: 'Work needing attention' }).click();

  const mine = (id) => `[data-testid="bwx-standup-card"][data-subject="${id}"]`;

  await expect(section(page, 'turn').locator(mine(withClient.id))).toHaveCount(1);
  await expect(section(page, 'turn').locator(mine(withTeam.id))).toHaveCount(0);
  await expect(section(page, 'work').locator(mine(withClient.id))).toHaveCount(0);
  await expect(section(page, 'work').locator(mine(withTeam.id)).first()).toBeVisible();

  await admin.context.close();
});

test('the standup switches between today, tomorrow and the next seven days', async ({ browser, baseURL }) => {
  test.setTimeout(240_000);

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Look Ahead Co ${RUN}`, `la${RUN}`);
  const today = (await admin.api.get('/standup')).today;
  const plus = (days) => new Date(Date.parse(`${today}T00:00:00Z`) + days * 86400000).toISOString().slice(0, 10);

  const make = async (title, due) => {
    const made = await Forge.makeItem(admin.api, site.id, { title, planned_start: '2020-01-01', planned_due: due });
    expect(made.status(), await made.text()).toBe(200);

    return (await made.json()).item;
  };

  const now = await make(`Due today ${RUN}`, today);
  const next = await make(`Due tomorrow ${RUN}`, plus(1));
  const later = await make(`Due in four days ${RUN}`, plus(4));
  const far = await make(`Due in nine days ${RUN}`, plus(9));

  const has = async (view, item) => (await admin.api.get(`/standup?view=${view}`)).cards.some((c) => c.subject_id === item.id && ['overdue', 'due-today'].includes(c.rule));

  expect([await has('today', now), await has('today', next), await has('today', later)]).toEqual([true, false, false]);
  expect([await has('tomorrow', now), await has('tomorrow', next), await has('tomorrow', later)]).toEqual([true, true, false]);
  expect([await has('week', now), await has('week', next), await has('week', later), await has('week', far)]).toEqual([true, true, true, false]);

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/#screen=standup');
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 60_000 });

  const card = (item) => page.locator(`[data-testid="bwx-standup-card"][data-subject="${item.id}"][data-rule="due-today"]`);
  const open = page.locator('[data-testid="bwx-standup-section"][data-section="work"] [data-testid="bwx-standup-section-toggle"][aria-expanded="false"]');
  const unfold = async () => {
    while ((await open.count()) > 0) {
      await open.first().click();
    }
  };

  // Today is the default.
  await expect(page.getByTestId('bwx-standup-view-today')).toHaveAttribute('aria-selected', 'true');

  // The switch works from the keyboard.
  await page.getByTestId('bwx-standup-view-today').focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByTestId('bwx-standup-view-tomorrow')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('bwx-standup-view-tomorrow')).toBeFocused();
  await expect(page.getByTestId('bwx-standup-day')).toHaveText(plus(0));
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 60_000 });
  await unfold();
  await expect(card(next).first()).toBeVisible({ timeout: 30_000 });
  await expect(card(later)).toHaveCount(0);

  await page.getByTestId('bwx-standup-view-week').click();
  await expect(page.getByTestId('bwx-standup-view-week')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 60_000 });
  await unfold();
  await expect(card(later).first()).toBeVisible({ timeout: 30_000 });
  await expect(card(far)).toHaveCount(0);

  await page.getByTestId('bwx-standup-view-today').click();
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 60_000 });
  await unfold();
  await expect(card(now).first()).toBeVisible({ timeout: 30_000 });
  await expect(card(next)).toHaveCount(0);

  await admin.context.close();
});
