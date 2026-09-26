import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, onSupport } from './helpers/forge.js';

// The meetings screen in the app (PR 6 of the move out of WordPress admin):
// pick a site, see its standing meetings and the next twelve weeks of them,
// start a series, move one meeting, say what became of it, end the series.
// The site, its hours and the host are this run's own because the instance
// is reused between runs.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');

/** A date some days on from a YYYY-MM-DD, as YYYY-MM-DD. */
function daysOn(from, days) {
  const when = new Date(`${from}T00:00:00Z`);
  when.setUTCDate(when.getUTCDate() + days);
  return when.toISOString().slice(0, 10);
}

/** Next Monday, always ahead of today, so the first meeting is on the list. */
function nextMonday() {
  const day = new Date();
  day.setUTCDate(day.getUTCDate() + (((8 - day.getUTCDay()) % 7) || 7));
  return day.toISOString().slice(0, 10);
}

test.describe.configure({ mode: 'serial' });

let admin;
let site;
let host;
let guest;
let first;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await makeSite(admin.api, 'Meetings app', RUN_ID);
  site = where.site;
  await onSupport(admin, site.id, 200);
  host = await makePerson(admin.api, where.client.id, 'staff', `host${STAMP}`);
  guest = await makePerson(admin.api, where.client.id, 'staff', `guest${STAMP}`);
  first = nextMonday();
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-meetings').click();
  await expect(page.getByTestId('bwx-meetings')).toBeVisible({ timeout: 30_000 });
});

/** Picks this run's site and waits for its meetings to be drawn. */
async function onSite(page) {
  const picker = page.getByTestId('bwx-meetings-site');
  await expect(picker).toBeEnabled({ timeout: 30_000 });
  await picker.selectOption(site.id);
  await expect(page.getByTestId('bwx-meetings-standing')).toBeVisible({ timeout: 30_000 });
}

/** The row on the list for one slot. */
function rowFor(page, slot) {
  return page.getByTestId('bwx-meetings-list').locator('tbody tr', { has: page.locator(`[data-slot="${slot}"]`) });
}

test('the rail offers Meetings under Clients, and a site with none says so', async ({ page }) => {
  await expect(page.locator('h1')).toHaveText('Meetings');
  await expect(page.getByTestId('bwx-meetings')).toContainText('Choose a site to see its meetings');

  await onSite(page);

  await expect(page.getByTestId('bwx-meetings-standing')).toContainText('No standing meetings');
  await expect(page.getByTestId('bwx-meetings-series')).toHaveCount(0);
  await expect(page.getByTestId('bwx-meetings-list')).toContainText('Nothing is coming up');
  await expect(page.getByTestId('bwx-meetings-add')).toBeVisible();
});

test('adding a weekly series shows it as a card, with its meetings on the list by week', async ({ page }) => {
  await onSite(page);
  await page.getByTestId('bwx-meetings-add').click();

  const form = page.getByTestId('bwx-meetings-series-form');
  await expect(form).toBeVisible();

  // A series with no name is refused with the field named, and the form stays.
  await form.getByTestId('bwx-meetings-series-save').click();
  await expect(form.getByTestId('bwx-meetings-series-notice')).toContainText('Give the series a name');
  await expect(form).toBeVisible();

  await form.getByTestId('bwx-meetings-series-title').fill(`Weekly catch-up ${RUN_ID}`);
  await form.getByTestId('bwx-meetings-series-frequency').selectOption('weekly');
  await form.getByTestId('bwx-meetings-series-starts').fill(first);
  await form.getByTestId('bwx-meetings-series-time').fill('10:00');
  await form.getByTestId('bwx-meetings-series-duration').fill('120');
  await expect(form.getByTestId('bwx-meetings-series-timezone')).toHaveValue('Europe/London');
  await form.getByTestId('bwx-meetings-series-host').selectOption(host.id);
  await form.getByTestId(`bwx-meetings-series-attendee-${guest.id}`).check();
  await form.getByTestId('bwx-meetings-series-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-meetings-notice')).toContainText('Series added');

  const card = page.getByTestId('bwx-meetings-series');
  await expect(card).toHaveCount(1);
  await expect(card).toHaveAttribute('data-state', 'active');
  await expect(card).toContainText(`Weekly catch-up ${RUN_ID}`);
  await expect(card).toContainText('Every week');
  await expect(card).toContainText('10:00 Europe/London');
  await expect(card).toContainText(host.user.display_name);
  await expect(card).toContainText(guest.user.display_name);
  await expect(card).toContainText('2h');
  await expect(card).toContainText('Running');
  await expect(card.getByTestId('bwx-meetings-series-end')).toBeVisible();

  // Twelve weeks of a weekly series from next Monday: at least eleven, each
  // under the week it falls in, each holding its hours.
  const rows = page.getByTestId('bwx-meetings-list').locator('tbody tr');
  await expect(rows.first()).toBeVisible();
  expect(await rows.count()).toBeGreaterThan(10);
  await expect(page.getByTestId('bwx-meetings-week').first()).toContainText('Week of');

  const row = rowFor(page, first);
  await expect(row).toContainText(`${first} 10:00`);
  await expect(row).toContainText(`Weekly catch-up ${RUN_ID}`);
  await expect(row).toContainText('Scheduled');
  await expect(row).toContainText('Set aside');
  await expect(row).not.toContainText('moved from');
});

test('moving the first meeting a day later moves only that one, and says where it came from', async ({ page }) => {
  await onSite(page);

  const row = rowFor(page, first);
  await row.getByTestId('bwx-meetings-move').click();

  const form = page.getByTestId('bwx-meetings-move-form');
  await expect(form).toBeVisible();
  await expect(form.getByTestId('bwx-meetings-move-on')).toHaveValue(first);
  await form.getByTestId('bwx-meetings-move-on').fill(daysOn(first, 1));
  await form.getByTestId('bwx-meetings-move-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-meetings-notice')).toContainText('Moved');

  // Keyed by the slot the rule put it on, shown on the day it moved to.
  const moved = rowFor(page, first);
  await expect(moved).toContainText(`${daysOn(first, 1)} 10:00`);
  await expect(moved).toContainText(`(moved from ${first})`);
  await expect(rowFor(page, daysOn(first, 7))).toContainText(`${daysOn(first, 7)} 10:00`);
  await expect(rowFor(page, daysOn(first, 7))).not.toContainText('moved from');

  // Written, not just drawn: a fresh load of the site shows the same.
  await page.goto(`/blueworx-forge/#screen=meetings&site=${site.id}`);
  await page.reload();
  await expect(rowFor(page, first)).toContainText(`${daysOn(first, 1)} 10:00`, { timeout: 30_000 });
  await expect(rowFor(page, first)).toContainText(`(moved from ${first})`);
});

test('marking the moved meeting held spends its hours', async ({ page }) => {
  await onSite(page);

  const row = rowFor(page, first);
  await row.getByTestId('bwx-meetings-settle').click();

  const form = page.getByTestId('bwx-meetings-settle-form');
  await expect(form).toBeVisible();
  await form.getByTestId('bwx-meetings-settle-status').selectOption('held');
  await form.getByTestId('bwx-meetings-settle-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-meetings-notice')).toContainText('Marked held');
  await expect(rowFor(page, first)).toContainText('Held');
  await expect(rowFor(page, first)).toContainText('Spent');
  // A settled meeting stays where it happened, as on the admin page.
  await expect(rowFor(page, first).getByTestId('bwx-meetings-move')).toHaveCount(0);
  await expect(rowFor(page, daysOn(first, 7)).getByTestId('bwx-meetings-move')).toBeVisible();
});

test('a standing meeting can be edited, not just ended', async ({ page }) => {
  await onSite(page);

  const card = page.getByTestId('bwx-meetings-series');
  await card.getByTestId('bwx-meetings-series-edit').click();

  const form = page.getByTestId('bwx-meetings-series-form');
  await expect(form).toBeVisible();
  // The form opens with what is there now.
  await expect(form.getByTestId('bwx-meetings-series-title')).not.toHaveValue('');
  await form.getByTestId('bwx-meetings-series-title').fill(`Renamed ${RUN_ID}`);
  await form.getByTestId('bwx-meetings-series-time').fill('14:30');
  await form.getByTestId('bwx-meetings-series-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-meetings-notice')).toContainText('Saved');
  await expect(card).toContainText(`Renamed ${RUN_ID}`);
  await expect(card).toContainText('14:30');
  await expect(card).toHaveAttribute('data-state', 'active');
});

test('ending the series marks it ended, and the end button goes', async ({ page }) => {
  await onSite(page);

  const card = page.getByTestId('bwx-meetings-series');
  page.once('dialog', (dialog) => dialog.accept());
  await card.getByTestId('bwx-meetings-series-end').click();

  await expect(page.getByTestId('bwx-meetings-notice')).toContainText('Series ended');
  await expect(card).toHaveAttribute('data-state', 'ended');
  await expect(card).toContainText('Ended');
  await expect(card.getByTestId('bwx-meetings-series-end')).toHaveCount(0);

  // The one that was held stays held; nothing on the list still holds hours.
  await expect(rowFor(page, first)).toContainText('Held');
  await expect(page.getByTestId('bwx-meetings-list')).not.toContainText('Set aside');
});

test('a link with the screen and site in the hash lands on them', async ({ page }) => {
  await page.goto(`/blueworx-forge/#screen=meetings&site=${site.id}`);
  // The beforeEach already opened the app without a hash, so this is a
  // same-document fragment change, not a real navigation; a link from
  // outside always arrives as a fresh load, so force one to match that.
  await page.reload();

  await expect(page.getByTestId('bwx-meetings')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-meetings-series')).toHaveCount(1, { timeout: 30_000 });
  await expect(page.getByTestId('bwx-meetings-site')).toHaveValue(site.id);
  // Read once and cleared, so a reload is a plain reload.
  expect(new URL(page.url()).hash).toBe('');
});

test('meetings that have passed are listed below the next twelve weeks, and can be settled', async ({ page }) => {
  // A site of its own, with a weekly series that started three weeks ago.
  const where = await makeSite(admin.api, 'Meetings past', `${RUN_ID}-p`);
  await onSupport(admin, where.site.id, 200);
  const started = daysOn(new Date().toISOString().slice(0, 10), -21);
  const wrote = await admin.api.post(`/client-sites/${where.site.id}/meetings/series`, {
    title: `Gone by ${RUN_ID}`, frequency: 'weekly', starts_on: started, ends_on: '', time_of_day: '10:00',
    duration_mins: 60, timezone: 'Europe/London', host_user_id: host.id, attendees: '', planned_hours: 0,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  // The page was opened before the site existed, so arrive by link.
  await page.goto(`/blueworx-forge/#screen=meetings&site=${where.site.id}`);
  await page.reload();

  const past = page.getByTestId('bwx-meetings-past');
  await expect(past).toBeVisible({ timeout: 30_000 });
  const rows = past.locator('tbody tr');
  await expect(rows).toHaveCount(3);
  await expect(page.getByTestId('bwx-meetings-past-older')).toBeDisabled();
  await expect(page.getByTestId('bwx-meetings-past-newer')).toBeDisabled();

  const newest = rows.first();
  await newest.getByTestId('bwx-meetings-settle').click();
  const form = page.getByTestId('bwx-meetings-settle-form');
  await form.getByTestId('bwx-meetings-settle-status').selectOption('held');
  await form.getByTestId('bwx-meetings-settle-save').click();

  await expect(form).toBeHidden();
  await expect(rows.first()).toContainText('Held');
});

test('picking All Clients lists standing meetings across every site, with no week view', async ({ page }) => {
  const another = await makeSite(admin.api, 'Meetings app all', `${RUN_ID}-all`);
  await onSupport(admin, another.site.id, 200);
  const wrote = await admin.api.post(`/client-sites/${another.site.id}/meetings/series`, {
    title: `All clients standing ${RUN_ID}`, frequency: 'weekly', starts_on: first, ends_on: '', time_of_day: '09:00',
    duration_mins: 30, timezone: 'Europe/London', host_user_id: host.id, attendees: '', planned_hours: 0,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  // The picker read its site list in beforeEach, before this site existed —
  // a fresh load, like a link landing here, is what picks it up.
  await page.goto(`/blueworx-forge/#screen=meetings&site=${site.id}`);
  await page.reload();
  await expect(page.getByTestId('bwx-meetings-standing')).toBeVisible({ timeout: 30_000 });

  await page.getByTestId('bwx-meetings-site').selectOption('all');

  const table = page.getByTestId('bwx-meetings-all');
  await expect(table).toBeVisible({ timeout: 30_000 });
  await expect(table).toContainText(`All clients standing ${RUN_ID}`);
  await expect(table).toContainText(another.site.name);
  await expect(page.getByTestId('bwx-meetings-week')).toHaveCount(0);
  await expect(page.getByTestId('bwx-meetings-list')).toHaveCount(0);

  // A row is a shortcut: clicking it switches the picker to that client's site.
  await table.getByText(`All clients standing ${RUN_ID}`).click();
  await expect(page.getByTestId('bwx-meetings-site')).toHaveValue(another.site.id, { timeout: 30_000 });
  await expect(page.getByTestId('bwx-meetings-standing')).toBeVisible({ timeout: 30_000 });
});
