import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { signedIn, makePerson, PASSWORD } from './helpers/forge.js';

// Recurring tasks, through the API: a source makes one task per due day on
// the studio's site, once, and not while paused.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

/** Today as the server sees it — its timezone, not this machine's. */
async function serverToday(api) {
  return (await api.get('/standup')).today;
}

test('a daily source makes today’s task once, in Up Next, for its people', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const sites = await admin.api.get('/client-sites');
  const studio = sites.sites.find((one) => one.studio);
  expect(studio, 'the studio has a site').toBeTruthy();

  const person = await makePerson(admin.api, studio.client_id, 'staff', 'recurrer');
  const today = await serverToday(admin.api);

  const made = await admin.api.post('/recurring', {
    title: `Daily check ${RUN_ID}`,
    description: '<p>Look at the thing.</p>',
    work_type: 'task',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [person.id],
    hours_each: '0.5',
    // A checklist is no longer accepted on a recurring task (#382): sent
    // anyway, it is dropped and ignored.
    checklist: [{ text: 'Open it' }, { text: 'Read it' }],
    client_site_id: studio.id,
  });
  expect(made.status(), await made.text()).toBe(200);
  const source = (await made.json()).source;
  expect(source.next_due).toBe(today);
  expect(source.cadence).toBe('Every day');

  // Made now, and only once.
  const first = await (await admin.api.post('/recurring/run', {})).json();
  expect(first.created).toBeGreaterThanOrEqual(1);
  const again = await (await admin.api.post('/recurring/run', {})).json();
  expect(again.created).toBe(0);

  const work = await admin.api.get(`/work-items?client_site_id=${studio.id}`);
  const tasks = work.items.filter((one) => one.title.startsWith(`Daily check ${RUN_ID}`));
  expect(tasks).toHaveLength(1);
  expect(tasks[0].stage).toBe('up-next');
  expect(tasks[0].assignees).toEqual([person.id]);
  expect(tasks[0].planned_due).toBe(today);
  expect(tasks[0].problem).toBe('<p>Look at the thing.</p>');
  expect(tasks[0].hours_each).toBe(0.5);
  // Dropped, not stored: a recurring copy carries no checklist (#382).
  expect(tasks[0].checklist).toEqual([]);
  expect(source.checklist).toEqual([]);

  // The days ahead count against the person before their tasks exist.
  const ahead = new Date(`${today}T12:00:00Z`);
  ahead.setUTCDate(ahead.getUTCDate() + 3);
  const soon = ahead.toISOString().slice(0, 10);
  const capacity = await admin.api.get(`/capacity?from=${soon}&to=${soon}`);
  const row = capacity.people.find((entry) => entry.user_id === person.id);
  expect(row, 'the person is on the capacity read').toBeTruthy();
  expect(row.total.committed).toBe(0.5);

  // The listing knows what it last made, and the history says how it got there.
  const listed = await admin.api.get('/recurring');
  const mine = listed.sources.find((one) => one.id === source.id);
  expect(mine.last.work_item_id).toBe(tasks[0].id);
  const detail = await admin.api.get(`/work-items/${tasks[0].id}`);
  expect(detail.history.some((event) => 'placed' === event.action && 'up-next' === event.to_stage)).toBe(true);

  // It shows up for the person who holds the seat.
  const asPerson = await signedIn(browser, baseURL, person.login, PASSWORD);
  const theirs = await asPerson.api.get(`/work-items?client_site_id=${studio.id}`);
  expect(theirs.items.some((one) => one.id === tasks[0].id)).toBe(true);
  await asPerson.context.close();

  // Paused: nothing more is made. Ended: it leaves the list.
  const paused = await admin.api.patch(`/recurring/${source.id}`, { status: 'paused', record_version: mine.record_version });
  expect(paused.status(), await paused.text()).toBe(200);
  expect((await paused.json()).source.status).toBe('paused');

  const ended = await admin.api.request.delete(`/wp-json/blueworx-forge/v1/recurring/${source.id}`, { headers: admin.api.headers });
  expect(ended.status(), await ended.text()).toBe(200);
  const after = await admin.api.get('/recurring');
  expect(after.sources.some((one) => one.id === source.id)).toBe(false);

  // Staff may read, may not write.
  const staff = await signedIn(browser, baseURL, person.login, PASSWORD);
  const refused = await staff.api.post('/recurring', { title: 'No', rule: { every: 'day' } });
  expect(refused.status()).toBe(403);
  await staff.context.close();

  // A schedule is for somebody, says what to do, when it starts and what it
  // costs: each missing one is refused by field (2026-09-19).
  const nobody = await admin.api.post('/recurring', { title: `For nobody ${RUN_ID}`, rule: { every: 'weekday' }, client_site_id: studio.id });
  expect(nobody.status()).toBe(400);
  const fields = (await nobody.json()).data.fields;
  expect(fields.assignees).toContain('at least one');
  expect(fields.description).toContain('what to do');
  expect(fields.starts_on).toContain('first day');
  expect(fields.hours_each).toContain('hours');

  await admin.context.close();
});

test('a recurring task is set up for a chosen client, and needs one', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'RecurClient', RUN_ID);
  const person = await Forge.makePerson(admin.api, client.id, 'staff', 'recurclient');
  const today = (await admin.api.get('/standup')).today;
  const body = {
    title: `Client chore ${RUN_ID}`,
    description: '<p>Check the forms.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [person.id],
    hours_each: '0.5',
  };

  const none = await admin.api.post('/recurring', body);
  expect(none.status()).toBe(400);
  expect((await none.json()).data.fields.client_site_id).toBe('Choose a client.');

  const made = await admin.api.post('/recurring', { ...body, client_site_id: site.id });
  expect(made.status(), await made.text()).toBe(200);
  expect((await made.json()).source.client_site_id).toBe(site.id);

  await admin.api.post('/recurring/run', {});
  const work = await admin.api.get(`/work-items?client_site_id=${site.id}`);
  expect(work.items.some((item) => item.title.startsWith(`Client chore ${RUN_ID}`))).toBe(true);

  const listed = await admin.api.get('/recurring');
  expect(listed.sources.some((source) => source.client_site_id === site.id)).toBe(true);

  await admin.context.close();
});
