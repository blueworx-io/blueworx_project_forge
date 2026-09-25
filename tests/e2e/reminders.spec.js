import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// Reminders (2026-09-25): a task on a day or over a few, for one or more
// people on a client, one copy each, on the calendar and in My tasks.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

function plus(date, days) {
  return new Date(Date.parse(`${date}T00:00:00Z`) + days * 86400000).toISOString().slice(0, 10);
}

test('a three-day reminder gives each person a copy, and edits reach only the unticked one', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'Remind', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const one = await Forge.makePerson(admin.api, client.id, 'staff', 'remindera');
  const two = await Forge.makePerson(admin.api, client.id, 'staff', 'reminderb');
  const title = `Renew the domain ${RUN_ID}`;

  const made = await admin.api.post('/reminders', {
    client_site_id: site.id,
    title,
    assignees: [one.id, two.id],
    starts_on: today,
    ends_on: plus(today, 2),
  });
  expect(made.status(), await made.text()).toBe(200);
  const reminder = (await made.json()).reminder;
  expect(reminder.id.startsWith('rem_')).toBe(true);
  expect(reminder.copies).toHaveLength(2);
  expect(reminder.category).toBe('general');

  const badType = await admin.api.post('/reminders', {
    client_site_id: site.id,
    title: `Bad type ${RUN_ID}`,
    assignees: [one.id],
    starts_on: today,
    category: 'party',
  });
  expect(badType.status()).toBe(400);
  expect((await badType.json()).data.fields.category).toBe('Pick a type.');

  const work = await admin.api.get(`/work-items?client_site_id=${site.id}`);
  const tasks = work.items.filter((item) => item.title === title);
  expect(tasks).toHaveLength(2);
  for (const task of tasks) {
    expect(task.assignees).toHaveLength(1);
    expect(task.planned_start).toBe(today);
    expect(task.planned_due).toBe(plus(today, 2));
    expect(task.commercial_class).toBe('free-general');
    expect(task.stage).toBe('up-next');
  }
  const mine = tasks.find((task) => task.assignees[0] === one.id);
  const theirs = tasks.find((task) => task.assignees[0] === two.id);

  // Person one ticks theirs; it is done, the other is untouched.
  const asOne = await Forge.signedIn(browser, baseURL, one.login, Forge.PASSWORD);
  const ticked = await asOne.api.post(`/work-items/${mine.id}/tick`, { done: true });
  expect(ticked.status(), await ticked.text()).toBe(200);
  expect((await admin.api.get(`/work-items/${theirs.id}`)).item.stage).toBe('up-next');

  // An edit renames and re-dates only the copy nobody ticked, and never makes more.
  const edited = await admin.api.patch(`/reminders/${reminder.id}`, {
    title: `${title} (moved)`,
    starts_on: plus(today, 1),
    ends_on: plus(today, 3),
    record_version: reminder.record_version,
  });
  expect(edited.status(), await edited.text()).toBe(200);
  expect((await admin.api.get(`/work-items/${mine.id}`)).item.title).toBe(title);
  const moved = (await admin.api.get(`/work-items/${theirs.id}`)).item;
  expect(moved.title).toBe(`${title} (moved)`);
  expect(moved.planned_start).toBe(plus(today, 1));
  await admin.api.post('/recurring/run', {});
  const after = await admin.api.get(`/work-items?client_site_id=${site.id}`);
  expect(after.items.filter((item) => item.title.startsWith(title))).toHaveLength(2);

  // Deleting removes the unticked copy and keeps the ticked one.
  const removed = await admin.api.del(`/reminders/${reminder.id}`);
  expect(removed.status(), await removed.text()).toBe(200);
  const left = (await admin.api.get(`/work-items?client_site_id=${site.id}`)).items.filter((item) => item.title.startsWith(title));
  expect(left.map((item) => item.id)).toEqual([mine.id]);
});

test('only the author or an administrator changes a reminder, and only on a client they reach', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RemindAuth', RUN_ID);
  const elsewhere = await Forge.makeSite(admin.api, 'RemindElse', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const author = await Forge.makePerson(admin.api, client.id, 'staff', 'remauthor');
  const other = await Forge.makePerson(admin.api, client.id, 'staff', 'remother');

  const asAuthor = await Forge.signedIn(browser, baseURL, author.login, Forge.PASSWORD);
  const made = await asAuthor.api.post('/reminders', { client_site_id: site.id, title: `Theirs ${RUN_ID}`, assignees: [other.id], starts_on: today });
  expect(made.status(), await made.text()).toBe(200);
  const reminder = (await made.json()).reminder;
  expect(reminder.can_edit).toBe(true);

  const outside = await asAuthor.api.post('/reminders', { client_site_id: elsewhere.site.id, title: 'No', assignees: [other.id], starts_on: today });
  expect(outside.status()).toBe(400);
  expect((await outside.json()).data.fields.client_site_id).toBe('Choose a client.');

  const asOther = await Forge.signedIn(browser, baseURL, other.login, Forge.PASSWORD);
  const listed = (await asOther.api.get('/reminders')).reminders.find((one) => one.id === reminder.id);
  expect(listed.can_edit).toBe(false);
  const refused = await asOther.api.patch(`/reminders/${reminder.id}`, { title: 'Mine now', record_version: reminder.record_version });
  expect(refused.status()).toBe(403);

  const early = await asAuthor.api.patch(`/reminders/${reminder.id}`, { ends_on: plus(today, -1), record_version: reminder.record_version });
  expect(early.status()).toBe(400);
  expect((await early.json()).data.fields.ends_on).toBe('The end date is before the start date.');
});

test('the calendar shows a reminder as a Reminder, over its days', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RemindCal', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const person = await Forge.makePerson(admin.api, client.id, 'staff', 'remcal');

  for (const [title, ends, category] of [[`Span ${RUN_ID}`, plus(today, 2), 'campaign'], [`One day ${RUN_ID}`, '', undefined]]) {
    const body = { client_site_id: site.id, title, assignees: [person.id], starts_on: plus(today, 1), ends_on: ends };
    if (category) {
      body.category = category;
    }
    const made = await admin.api.post('/reminders', body);
    expect(made.status(), await made.text()).toBe(200);
  }

  // A window that starts inside the span still finds it.
  const feed = await admin.api.get(`/calendar?from=${plus(today, 2)}&to=${plus(today, 5)}`);
  const span = feed.entries.filter((entry) => entry.title === `Span ${RUN_ID}`);
  expect(span).toHaveLength(1);
  expect(span[0].kind).toBe('reminder');
  expect(span[0].label).toBe('Reminder');
  expect(span[0].date).toBe(plus(today, 1));
  expect(span[0].ends_on).toBe(plus(today, 2));
  expect(span[0].detail).toBe('Campaign · To do');

  const whole = await admin.api.get(`/calendar?from=${today}&to=${plus(today, 5)}`);
  const single = whole.entries.find((entry) => entry.title === `One day ${RUN_ID}`);
  expect(single.kind).toBe('reminder');
  expect(single.ends_on).toBe('');
  expect(single.detail).toBe('General · To do');
  expect(whole.entries.some((entry) => 'recurring' === entry.kind && entry.title.includes(RUN_ID))).toBe(false);

  // A reminder for two people is one entry, not one per copy.
  const one = await Forge.makePerson(admin.api, client.id, 'staff', 'remcalone');
  const two = await Forge.makePerson(admin.api, client.id, 'staff', 'remcaltwo');
  const pairTitle = `Pair ${RUN_ID}`;
  const pairMade = await admin.api.post('/reminders', { client_site_id: site.id, title: pairTitle, assignees: [one.id, two.id], starts_on: plus(today, 1) });
  expect(pairMade.status(), await pairMade.text()).toBe(200);

  const beforeTick = await admin.api.get(`/calendar?from=${today}&to=${plus(today, 5)}`);
  const pairEntry = beforeTick.entries.find((entry) => entry.title === pairTitle);
  expect(pairEntry.kind).toBe('reminder');
  expect(pairEntry.people.sort()).toEqual([one.id, two.id].sort());
  expect(pairEntry.detail).toBe('General · To do');

  const work = await admin.api.get(`/work-items?client_site_id=${site.id}`);
  const mine = work.items.find((item) => item.title === pairTitle && item.assignees[0] === one.id);
  const asOne = await Forge.signedIn(browser, baseURL, one.login, Forge.PASSWORD);
  const ticked = await asOne.api.post(`/work-items/${mine.id}/tick`, { done: true });
  expect(ticked.status(), await ticked.text()).toBe(200);

  const afterTick = await admin.api.get(`/calendar?from=${today}&to=${plus(today, 5)}`);
  const pairAfter = afterTick.entries.find((entry) => entry.title === pairTitle);
  expect(pairAfter.detail).toBe('General · 1 of 2 done');
});

test('in My tasks a reminder waits by its start, then sits in Today until ticked', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RemindMine', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const person = await Forge.makePerson(admin.api, client.id, 'staff', 'remmine');

  const reminders = [
    [`Started ${RUN_ID}`, plus(today, -2), plus(today, 20)],
    [`In five ${RUN_ID}`, plus(today, 5), ''],
    [`In ten ${RUN_ID}`, plus(today, 10), plus(today, 12)],
  ];
  for (const [title, starts, ends] of reminders) {
    const made = await admin.api.post('/reminders', { client_site_id: site.id, title, assignees: [person.id], starts_on: starts, ends_on: ends });
    expect(made.status(), await made.text()).toBe(200);
  }

  const me = await Forge.signedIn(browser, baseURL, person.login, Forge.PASSWORD);
  const page = await me.context.newPage();
  await page.goto('/blueworx-forge/#screen=mytasks');
  const table = page.getByTestId('bwx-mytasks-table');
  await expect(table).toBeVisible({ timeout: 60_000 });

  // Today (the default): the one that has started, though it is not due for weeks.
  await expect(table.locator('tbody tr', { hasText: `Started ${RUN_ID}` })).toHaveCount(1);
  await expect(table).not.toContainText(`In five ${RUN_ID}`);
  await expect(table).not.toContainText(`In ten ${RUN_ID}`);

  await table.getByRole('button', { name: /^Next seven days/ }).click();
  await expect(table.locator('tbody tr', { hasText: `In five ${RUN_ID}` })).toHaveCount(1);

  await table.getByRole('button', { name: /^Further out/ }).click();
  await expect(table.locator('tbody tr', { hasText: `In ten ${RUN_ID}` })).toHaveCount(1);

  await table.getByRole('button', { name: /^Everything/ }).click();
  for (const [title] of reminders) {
    await expect(table.locator('tbody tr', { hasText: title })).toHaveCount(1);
  }
  await page.close();
});

test('anyone on the team adds a reminder from its page', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RemindPage', RUN_ID);
  const today = (await admin.api.get('/standup')).today;
  const person = await Forge.makePerson(admin.api, client.id, 'staff', 'rempage');
  const title = `From the page ${RUN_ID}`;

  const me = await Forge.signedIn(browser, baseURL, person.login, Forge.PASSWORD);
  const page = await me.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-reminders').click();
  await expect(page.getByTestId('bwx-reminders')).toBeVisible({ timeout: 60_000 });

  await page.getByTestId('bwx-reminders-add').click();
  await page.getByTestId('bwx-reminder-title').fill(title);
  await page.getByTestId('bwx-reminder-client').selectOption(site.id);
  await page.getByTestId(`bwx-reminder-person-${person.id}`).check();
  await page.getByTestId('bwx-reminder-starts').fill(plus(today, 1));
  await page.getByTestId('bwx-reminder-ends').fill(plus(today, 3));
  await page.getByTestId('bwx-reminder-category').selectOption('deadline');
  await page.getByTestId('bwx-reminder-save').click();

  const row = page.getByTestId('bwx-reminders-table').locator('tbody tr', { hasText: title });
  await expect(row).toHaveCount(1, { timeout: 30_000 });
  await expect(row).toContainText(client.display_name);
  await expect(row).toContainText('0 of 1 done');
  await expect(row).toContainText('Deadline');

  // Its author may edit it.
  await expect(row.getByTestId('bwx-reminder-edit')).toBeVisible();
  await page.close();
});
