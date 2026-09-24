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
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

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
  await unfold(page);

  return board;
}

/**
 * Sections start folded, with only their count showing. The specs below are
 * about what is in them, so they open every section first.
 */
async function unfold(page) {
  const folded = page.locator('[data-testid="bwx-standup-section-toggle"][aria-expanded="false"]');

  while ((await folded.count()) > 0) {
    await folded.first().click();
  }
}

test('sections start folded and stay open once opened', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, item } = await withSomethingLate(browser, baseURL);
  const page = await admin.context.newPage();

  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-standup').click();
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 30_000 });

  const card = page.locator(`[data-testid="bwx-standup-card"][data-subject="${item.id}"][data-rule="overdue"]`);

  // Folded: the section and its count are there, the card is not. Overdue
  // work is in the "work" section.
  const late = page.locator('[data-testid="bwx-standup-section"][data-section="work"]');
  await expect(late).toHaveAttribute('data-open', 'false');
  await expect(late.getByTestId('bwx-standup-section-count')).toContainText('thing');
  await expect(card).toHaveCount(0);

  await late.getByTestId('bwx-standup-section-toggle').click();
  await expect(late).toHaveAttribute('data-open', 'true');
  await expect(card).toBeVisible();

  // Remembered for the visit: away and back, and it is still open.
  await page.getByTestId('bwx-screen-mytasks').click();
  await page.getByTestId('bwx-screen-standup').click();
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 30_000 });
  await expect(late).toHaveAttribute('data-open', 'true');
  await expect(card).toBeVisible();

  await page.close();
  await admin.context.close();
});

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
  // The list is worked out again on the server, which on a busy instance is
  // slower than the default wait; the point here is what comes back, not when.
  await expect(card).toHaveCount(0, { timeout: 30_000 });

  // Still gone after a full reload, which a hidden card would not be.
  await openStandup(page);
  await expect(card).toHaveCount(0);

  await page.close();
  await admin.context.close();
});

test('meetings that have happened and are not settled are listed, and leave once settled', async ({ browser, baseURL }) => {
  test.slow();

  // A weekly meeting that started a fortnight ago: two or three of them have
  // happened and nobody has said what became of them (2026-09-24).
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site, client } = await Forge.makeSite(admin.api, `Standup Meetings Co ${RUN_ID}`, `${RUN_ID}-m`);
  await Forge.onSupport(admin, site.id, 200);
  const host = await Forge.makePerson(admin.api, client.id, 'staff', `sm${RUN_ID.replace('-', '')}`);
  const title = `Catch-up ${RUN_ID}`;
  const started = new Date(Date.now() - 14 * 86400000).toISOString().slice(0, 10);
  const wrote = await admin.api.post(`/client-sites/${site.id}/meetings/series`, {
    title, frequency: 'weekly', starts_on: started, ends_on: '', time_of_day: '10:00',
    duration_mins: 60, timezone: 'Europe/London', host_user_id: host.id, attendees: '', planned_hours: 0,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const list = await admin.api.get('/standup');
  const ours = (list.to_settle ?? []).filter((one) => one.title === title);
  expect(ours.length).toBeGreaterThanOrEqual(2);
  expect(ours.every((one) => one.date <= list.today)).toBe(true);
  expect(ours[0].site_id).toBe(site.id);

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-standup').click();
  const settle = page.getByTestId('bwx-standup-settle');
  await expect(settle).toBeVisible({ timeout: 30_000 });
  await expect(settle.locator('li', { hasText: title })).toHaveCount(ours.length);

  // Settled, it is not on the list any more.
  const first = ours[0];
  const done = await admin.api.post(`/client-sites/${site.id}/meetings/${first.series_id}/${first.slot}/settle`, { status: 'held' });
  expect(done.status(), await done.text()).toBe(200);
  const after = (await admin.api.get('/standup')).to_settle.filter((one) => one.title === title);
  expect(after).toHaveLength(ours.length - 1);

  // The line takes you to the site's meetings to settle it.
  await settle.locator('li', { hasText: title }).first().getByRole('link', { name: 'Open' }).click();
  await expect(page.getByTestId('bwx-meetings')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-meetings-site')).toHaveValue(site.id);

  await page.close();
  await admin.context.close();
});

test('the host settles their own meeting from the standup; somebody else cannot', async ({ browser, baseURL }) => {
  test.slow();

  // Admins or hosts settle a meeting (Luke, 2026-09-24).
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site, client } = await Forge.makeSite(admin.api, `Host Settles Co ${RUN_ID}`, `${RUN_ID}-h`);
  await Forge.onSupport(admin, site.id, 200);
  const stamp = RUN_ID.replace('-', '');
  const host = await Forge.makePerson(admin.api, client.id, 'staff', `hs${stamp}`);
  const other = await Forge.makePerson(admin.api, client.id, 'staff', `ho${stamp}`);
  const title = `Host catch-up ${RUN_ID}`;
  const started = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
  const wrote = await admin.api.post(`/client-sites/${site.id}/meetings/series`, {
    title, frequency: 'weekly', starts_on: started, ends_on: '', time_of_day: '10:00',
    duration_mins: 60, timezone: 'Europe/London', host_user_id: host.id, attendees: '', planned_hours: 0,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  // Somebody who is not the host is refused, and told nothing about the site.
  const asOther = await Forge.signedIn(browser, baseURL, other.login, Forge.PASSWORD);
  const theirs = (await asOther.api.get('/standup')).to_settle.filter((one) => one.title === title);
  expect(theirs.every((one) => false === one.can_settle)).toBe(true);
  const refused = await asOther.api.post(`/client-sites/${site.id}/meetings/${theirs[0].series_id}/${theirs[0].slot}/settle`, { status: 'held' });
  expect(refused.status()).toBe(403);
  await asOther.context.close();

  // The host sees theirs, and settles it with one press.
  const asHost = await Forge.signedIn(browser, baseURL, host.login, Forge.PASSWORD);
  const mine = (await asHost.api.get('/standup')).to_settle.filter((one) => one.title === title);
  expect(mine.length).toBeGreaterThanOrEqual(1);
  expect(mine.every((one) => true === one.can_settle)).toBe(true);

  const page = await asHost.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-standup').click();
  const line = page.getByTestId('bwx-standup-settle').locator('li', { hasText: title }).first();
  await expect(line).toBeVisible({ timeout: 30_000 });
  await line.getByRole('button', { name: 'Held' }).click();
  await expect(page.getByTestId('bwx-standup-settle').locator('li', { hasText: title })).toHaveCount(mine.length - 1, { timeout: 30_000 });

  const after = (await admin.api.get(`/client-sites/${site.id}/meetings`)).past.meetings.find((one) => one.slot === mine[0].slot);
  expect(after.status).toBe('held');

  await page.close();
  await asHost.context.close();
  await admin.context.close();
});
