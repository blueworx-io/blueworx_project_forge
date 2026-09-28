import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #462. Opening a recurring task says who it belongs to, as any other task
// does.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('a recurring task, opened, names the person it is waiting on', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const studio = (await admin.api.get('/client-sites')).sites.find((one) => one.studio);
  const today = (await admin.api.get('/standup')).today;

  const name = `panelchore${Date.now()}`;
  const person = await Forge.makePerson(admin.api, studio.client_id, 'staff', name);

  // Every day, so it is due today whatever today is.
  const made = await admin.api.post('/recurring', {
    title: `Water the plants ${RUN_ID}`,
    description: '<p>All of them.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [person.id],
    hours_each: '0.25',
    client_site_id: studio.id,
  });
  expect(made.status(), await made.text()).toBe(200);

  await admin.api.post('/recurring/run', {});

  const work = await admin.api.get(`/work-items?client_site_id=${studio.id}`);
  const task = work.items.find((item) => item.title.startsWith(`Water the plants ${RUN_ID}`));
  expect(task, 'the day’s task was made').toBeTruthy();

  const page = await admin.context.newPage();
  await page.goto(`/blueworx-forge/#item=${task.id}`);
  await expect(page.getByTestId('bwx-panel-waiting')).toHaveText(`Waiting on ${name}`, { timeout: 60_000 });

  await page.close();
  await admin.context.close();
});
