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
