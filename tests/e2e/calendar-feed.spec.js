import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// The studio's diary (2026-09-18): company days, birthdays and campaigns
// added from the calendar's list, and the day's chores, meetings and leave
// drawn on the calendar and read out on the standup — the same list, both
// places.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let today;
let client;
let site;
let person;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  today = (await admin.api.get('/standup')).today;
  ({ client, site } = await Forge.makeSite(admin.api, 'Diary Co', RUN_ID));
  await Forge.onSupport(admin, site.id, 100);
  person = await Forge.makePerson(admin.api, client.id, 'staff', 'diarist');
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('dates added from the calendar list are on the month view and in the list', async () => {
  const page = await admin.context.newPage();

  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', site.id);
  await page.getByTestId('bwx-view-calendar').click();
  await expect(page.getByTestId('bwx-calendar')).toBeVisible();

  // List is last in the switcher, and is where a date is added.
  const modes = page.locator('[data-testid^="bwx-calendar-mode-"]');
  await expect(modes.last()).toHaveAttribute('data-testid', 'bwx-calendar-mode-list');
  await modes.last().click();
  // Adding is a side panel now (2026-09-19).
  await page.getByTestId('bwx-date-add').click();
  await expect(page.getByTestId('bwx-diary-add')).toBeVisible();

  await page.getByTestId('bwx-date-title').fill(`Office closed ${RUN_ID}`);
  await page.getByTestId('bwx-date-kind').selectOption('company-day');
  await page.getByTestId('bwx-date-on').fill(today);
  await page.getByTestId('bwx-date-save').click();
  await expect(page.getByTestId('bwx-diary-add-notice')).toHaveText('Added.');

  await page.getByTestId('bwx-date-title').fill(`Birthday ${RUN_ID}`);
  await page.getByTestId('bwx-date-kind').selectOption('birthday');
  await page.getByTestId('bwx-date-who').selectOption('some');
  await page.getByTestId(`bwx-date-person-${person.id}`).check();
  await page.getByTestId('bwx-date-save').click();
  await expect(page.getByTestId('bwx-diary-add-notice')).toHaveText('Added.');
  await page.getByTestId('bwx-diary-add').getByRole('button', { name: 'Close' }).first().click();

  const day = page.locator(`[data-testid="bwx-diary-day"][data-date="${today}"]`);
  await expect(day).toContainText(`Office closed ${RUN_ID}`);
  await expect(day).toContainText(`Birthday ${RUN_ID}`);

  // And on the month view, as dates.
  await page.getByTestId('bwx-calendar-mode-month').click();
  const cell = page.locator(`[data-testid="bwx-calendar-day"][data-date="${today}"]`);
  // A busy day shows its first few and "+N more" (2026-09-20); open it up.
  await expect(cell).toBeVisible();
  if (0 < (await cell.getByTestId('bwx-calendar-more').count())) {
    await cell.getByTestId('bwx-calendar-more').click();
  }
  await expect(cell.locator('[data-testid="bwx-calendar-diary"][data-kind="date"]', { hasText: RUN_ID })).toHaveCount(2);

  // Stored for the people asked for.
  const listed = await admin.api.get(`/calendar-dates?from=${today}&to=${today}`);
  const birthday = listed.dates.find((one) => one.title === `Birthday ${RUN_ID}`);
  expect(birthday.people).toEqual([person.id]);
  expect(listed.dates.find((one) => one.title === `Office closed ${RUN_ID}`).people).toBe('all');

  await page.close();
});

test('a busy day shows its first few and opens the rest', async () => {
  // Four dates on one day, ten days out, where nothing else is: the month
  // view shows three and "+1 more" until asked.
  const on = new Date(`${today}T12:00:00Z`);
  on.setUTCDate(on.getUTCDate() + 10);
  const busy = on.toISOString().slice(0, 10);

  for (const n of [1, 2, 3, 4]) {
    const made = await admin.api.post('/calendar-dates', { title: `Busy ${n} ${RUN_ID}`, kind: 'campaign', on_date: busy, people: 'all' });
    expect(made.status(), await made.text()).toBe(200);
  }

  const page = await admin.context.newPage();
  await page.goto(`/blueworx-forge/#site=${site.id}`);
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', site.id);
  await page.getByTestId('bwx-view-calendar').click();
  await page.getByTestId('bwx-calendar-mode-month').click();
  await page.getByTestId('bwx-calendar-goto').fill(busy);

  const cell = page.locator(`[data-testid="bwx-calendar-day"][data-date="${busy}"]`);
  await expect(cell).toBeVisible();
  const mine = cell.locator('[data-testid="bwx-calendar-diary"]', { hasText: RUN_ID });
  await expect(cell.getByTestId('bwx-calendar-more')).toContainText('more');
  await expect(cell.locator('[data-testid="bwx-calendar-diary"], [data-testid="bwx-calendar-entry"]')).toHaveCount(3);

  await cell.getByTestId('bwx-calendar-more').click();
  await expect(mine).toHaveCount(4);
  await expect(cell.getByTestId('bwx-calendar-less')).toBeVisible();

  await cell.getByTestId('bwx-calendar-less').click();
  await expect(cell.getByTestId('bwx-calendar-more')).toBeVisible();

  await page.close();
});

test('a chore, a meeting and leave are on today’s feed and the standup’s diary', async () => {
  // A chore due today.
  const made = await admin.api.post('/recurring', {
    title: `Check the inbox ${RUN_ID}`,
    description: '<p>Read it.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [person.id],
    hours_each: '0.5',
  });
  expect(made.status(), await made.text()).toBe(200);
  await admin.api.post('/recurring/run', {});

  // A meeting today on a site in reach.
  const series = await admin.api.post(`/client-sites/${site.id}/meetings/series`, {
    title: `Weekly call ${RUN_ID}`,
    frequency: 'weekly',
    starts_on: today,
    ends_on: '',
    time_of_day: '10:00',
    duration_mins: 60,
    timezone: 'Europe/London',
    host_user_id: person.id,
    attendees: 'The client',
    planned_hours: 0,
  });
  expect(series.status(), await series.text()).toBe(200);

  // Somebody away today.
  const away = await admin.api.post(`/users/${person.id}/leave`, { starts_on: today, ends_on: today, kind: 'leave', note: `Away ${RUN_ID}` });
  expect(away.status(), await away.text()).toBe(200);

  const feed = await admin.api.get(`/calendar?from=${today}&to=${today}`);
  const titles = feed.entries.map((entry) => `${entry.kind}:${entry.title}`);

  expect(titles.some((title) => title.startsWith(`recurring:Check the inbox ${RUN_ID}`))).toBe(true);
  expect(titles).toContain(`meeting:Weekly call ${RUN_ID}`);
  expect(titles).toContain('leave:diarist');
  expect(feed.entries.find((entry) => 'recurring' === entry.kind && entry.title.startsWith(`Check the inbox ${RUN_ID}`)).detail).toBe('0 of 1 done');

  // The standup reads the same list.
  const standup = await admin.api.get('/standup');
  expect(standup.diary.map((entry) => entry.id).sort()).toEqual(feed.entries.map((entry) => entry.id).sort());

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/#screen=standup');
  await expect(page.getByTestId('bwx-standup-diary')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-standup-diary')).toContainText(`Weekly call ${RUN_ID}`);
  await expect(page.getByTestId('bwx-standup-diary')).toContainText('diarist');
  await page.close();

});

test('staff read the diary and cannot add to it', async ({ browser, baseURL }) => {
  const asPerson = await Forge.signedIn(browser, baseURL, person.login, Forge.PASSWORD);

  const feed = await asPerson.api.get(`/calendar?from=${today}&to=${today}`);
  expect(feed.entries.map((entry) => entry.title), JSON.stringify(feed).slice(0, 300)).toContain(`Office closed ${RUN_ID}`);

  const refused = await asPerson.api.post('/calendar-dates', { title: 'Mine', kind: 'other', on_date: today });
  expect(refused.status()).toBe(403);

  await asPerson.context.close();
});
