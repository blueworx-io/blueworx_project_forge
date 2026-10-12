import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #491. Somebody on leave gets no recurring task for those days, and nothing
// is made up when they are back, so there is nothing to tick off.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('a person on leave gets no recurring task, and their time stays free', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const studio = (await admin.api.get('/client-sites')).sites.find((one) => one.studio);
  const today = (await admin.api.get('/standup')).today;

  const away = await Forge.makePerson(admin.api, studio.client_id, 'staff', 'leavea');
  const here = await Forge.makePerson(admin.api, studio.client_id, 'staff', 'leaveb');

  await Forge.setHours(admin.api, away.id, 8);
  await Forge.setHours(admin.api, here.id, 8);

  const leave = await admin.api.post(`/users/${away.id}/leave`, { starts_on: today, ends_on: today, kind: 'leave' });
  expect(leave.status(), await leave.text()).toBe(200);

  const made = await admin.api.post('/recurring', {
    title: `Check the backups ${RUN_ID}`,
    description: '<p>Backups ran.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [away.id, here.id],
    hours_each: '0.5',
    client_site_id: studio.id,
  });
  expect(made.status(), await made.text()).toBe(200);

  await admin.api.post('/recurring/run', {});

  const work = await admin.api.get(`/work-items?client_site_id=${studio.id}`);
  const tasks = work.items.filter((item) => item.title.startsWith(`Check the backups ${RUN_ID}`));

  expect(tasks, 'only the person at work gets one').toHaveLength(1);
  expect(tasks[0].assignees).toEqual([here.id]);

  const capacity = await admin.api.get(`/capacity?from=${today}&to=${today}`);
  const row = capacity.people.find((entry) => entry.user_id === away.id);

  expect(row?.total.committed ?? 0, 'nothing on the day away').toBe(0);

  await admin.context.close();
});
