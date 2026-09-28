import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #455. A recurring task on the standup says who it belongs to, like every
// other task does — so the same chore for two people is two cards that can
// be told apart.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('each copy of a shared recurring task on the standup names its own person', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const studio = (await admin.api.get('/client-sites')).sites.find((one) => one.studio);
  const today = (await admin.api.get('/standup')).today;

  const oneName = `choreone${Date.now()}`;
  const twoName = `choretwo${Date.now()}`;
  const one = await Forge.makePerson(admin.api, studio.client_id, 'staff', oneName);
  const two = await Forge.makePerson(admin.api, studio.client_id, 'staff', twoName);

  // Every day, so it is due today whatever today is.
  const made = await admin.api.post('/recurring', {
    title: `Clear all emails ${RUN_ID}`,
    description: '<p>Inbox zero.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [one.id, two.id],
    hours_each: '0.25',
    client_site_id: studio.id,
  });
  expect(made.status(), await made.text()).toBe(200);

  await admin.api.post('/recurring/run', {});

  const work = await admin.api.get(`/work-items?client_site_id=${studio.id}`);
  const tasks = work.items.filter((item) => item.title.startsWith(`Clear all emails ${RUN_ID}`));
  expect(tasks).toHaveLength(2);
  const mine = tasks.find((item) => item.assignees[0] === one.id);
  const theirs = tasks.find((item) => item.assignees[0] === two.id);

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/#screen=standup');
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 30_000 });

  // Sections start folded; open the one these are in.
  const toggle = page.locator('[data-testid="bwx-standup-section"][data-section="work"] [data-testid="bwx-standup-section-toggle"]');
  if ('false' === (await toggle.getAttribute('aria-expanded'))) {
    await toggle.click();
  }

  const card = (id) => page.locator(`[data-testid="bwx-standup-card"][data-subject="${id}"]`).first();
  await expect(card(mine.id)).toBeVisible({ timeout: 30_000 });
  await expect(card(mine.id).getByTestId('bwx-standup-waiting')).toHaveText(`Waiting on ${oneName}`);
  await expect(card(theirs.id).getByTestId('bwx-standup-waiting')).toHaveText(`Waiting on ${twoName}`);

  await page.close();
  await admin.context.close();
});
