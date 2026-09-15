import { test, expect } from '@playwright/test';
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

test('a daily source makes today’s task once, in Up Next, with its seats', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const sites = await admin.api.get('/client-sites');
  const studio = sites.sites.find((one) => one.studio);
  expect(studio, 'the studio has a site').toBeTruthy();

  const person = await makePerson(admin.api, studio.client_id, 'staff', 'recurrer');
  const today = await serverToday(admin.api);

  const made = await admin.api.post('/recurring', {
    title: `Daily check ${RUN_ID}`,
    description: 'Look at the thing.',
    work_type: 'task',
    rule: { every: 'day' },
    starts_on: today,
    primary_user_id: person.id,
    hours_primary: '0.5',
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
  expect(tasks[0].primary_user_id).toBe(person.id);
  expect(tasks[0].planned_due).toBe(today);
  expect(tasks[0].problem).toBe('Look at the thing.');
  expect(tasks[0].hours_primary).toBe(0.5);

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

  await admin.context.close();
});
