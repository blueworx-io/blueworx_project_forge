import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// A recurring chore for several people (2026-09-18): since 2026-09-24 one
// task a day for each of them, each done on its own with one press, free,
// and each person's hours counted against their capacity.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('two people on a weekday chore get a copy each, and each finishes their own', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const studio = (await admin.api.get('/client-sites')).sites.find((one) => one.studio);
  const today = (await admin.api.get('/standup')).today;
  const weekday = new Date(`${today}T12:00:00Z`).getUTCDay();

  const one = await Forge.makePerson(admin.api, studio.client_id, 'staff', 'chorea');
  const two = await Forge.makePerson(admin.api, studio.client_id, 'staff', 'choreb');

  await Forge.setHours(admin.api, one.id, 8);
  await Forge.setHours(admin.api, two.id, 8);

  // Every weekday, and every day on a weekend, so the task is due today whatever today is.
  const made = await admin.api.post('/recurring', {
    title: `Clear the inbox ${RUN_ID}`,
    description: '<p>Inbox zero.</p>',
    rule: { every: 0 === weekday || 6 === weekday ? 'day' : 'weekday' },
    starts_on: today,
    assignees: [one.id, two.id],
    hours_each: '0.5',
    client_site_id: studio.id,
  });
  expect(made.status(), await made.text()).toBe(200);
  const source = (await made.json()).source;
  expect(source.assignees).toEqual([one.id, two.id]);
  if (0 !== weekday && 6 !== weekday) {
    expect(source.cadence).toBe('Every weekday');
  }

  await admin.api.post('/recurring/run', {});

  const work = await admin.api.get(`/work-items?client_site_id=${studio.id}`);
  const tasks = work.items.filter((item) => item.title.startsWith(`Clear the inbox ${RUN_ID}`));

  // One task each (2026-09-24): each person has their own copy to do and
  // tick off, it is free, and it waits in Up Next.
  expect(tasks).toHaveLength(2);
  const mine = tasks.find((item) => item.assignees[0] === one.id);
  const theirs = tasks.find((item) => item.assignees[0] === two.id);
  for (const task of [mine, theirs]) {
    expect(task.assignees).toHaveLength(1);
    expect(task.stage).toBe('up-next');
    expect(task.commercial_class).toBe('free-general');
  }

  // Each person's hours count against their own capacity, once.
  const capacity = await admin.api.get(`/capacity?from=${today}&to=${today}`);
  for (const who of [one, two]) {
    const row = capacity.people.find((entry) => entry.user_id === who.id);
    expect(row, `${who.id} is on the capacity read`).toBeTruthy();
    expect(row.total.committed).toBe(0.5);
  }

  // The first person ticks theirs from My tasks, and it is done; the second
  // person's copy is untouched.
  const asOne = await Forge.signedIn(browser, baseURL, one.login, Forge.PASSWORD);
  const page = await asOne.context.newPage();

  await page.goto('/blueworx-forge/#screen=mytasks');
  await expect(page.getByTestId('bwx-mytasks')).toBeVisible({ timeout: 30_000 });

  const row = page.locator('[data-testid="bwx-mytasks"] tr', { hasText: `Clear the inbox ${RUN_ID}` });
  await expect(row).toHaveCount(1);
  await row.getByTestId('bwx-mytasks-tick').click();
  await expect.poll(async () => (await admin.api.get(`/work-items/${mine.id}`)).item.stage, { timeout: 30_000 }).toBe('released');
  expect((await admin.api.get(`/work-items/${theirs.id}`)).item.stage).toBe('up-next');
  await page.close();

  // Nobody else ticks your copy.
  const forged = await asOne.api.post(`/work-items/${theirs.id}/tick`, { user_id: two.id, done: true });
  expect(forged.status()).toBe(403);
  await asOne.context.close();

  // A checklist with a line open holds it (2026-09-19).
  const asTwo = await Forge.signedIn(browser, baseURL, two.login, Forge.PASSWORD);
  const listed = await admin.api.patch(`/work-items/${theirs.id}`, {
    checklist: [{ text: 'Archive the old threads', done: false }],
    record_version: (await admin.api.get(`/work-items/${theirs.id}`)).item.record_version,
  });
  expect(listed.status(), await listed.text()).toBe(200);
  const held = await asTwo.api.post(`/work-items/${theirs.id}/tick`, { done: true });
  expect(held.status()).toBe(409);
  await admin.api.patch(`/work-items/${theirs.id}`, {
    checklist: [{ text: 'Archive the old threads', done: true }],
    record_version: (await admin.api.get(`/work-items/${theirs.id}`)).item.record_version,
  });

  // The second person opens theirs: no list of people, no who-and-when, and
  // a Done on the right that finishes it.
  const panel = await asTwo.context.newPage();
  await panel.goto(`/blueworx-forge/#item=${theirs.id}`);
  await expect(panel.getByTestId('bwx-panel')).toBeVisible({ timeout: 30_000 });
  await expect(panel.getByTestId('bwx-chore')).toHaveCount(0);
  await expect(panel.getByTestId('bwx-assign')).toHaveCount(0);
  await expect(panel.getByTestId('bwx-panel')).not.toContainText('Who pays for it');
  await panel.getByTestId('bwx-chore-done').click();
  await expect.poll(async () => (await admin.api.get(`/work-items/${theirs.id}`)).item.stage, { timeout: 30_000 }).toBe('released');

  const done = await admin.api.get(`/work-items/${theirs.id}`);
  expect(done.history.some((event) => 'placed' === event.action && 'released' === event.to_stage)).toBe(true);

  await panel.close();
  await asTwo.context.close();
  await admin.context.close();
});
