import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// A recurring chore for several people (2026-09-18): one task a day on the
// board, each person ticking their own, done when everyone has, and each
// person's hours counted against their capacity.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('two people share a weekday chore, tick their own, and it completes when both have', async ({ browser, baseURL }) => {
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

  // One task for the day, however many people do it.
  expect(tasks).toHaveLength(1);
  const task = tasks[0];
  expect(task.assignees).toEqual([one.id, two.id]);
  expect(task.stage).toBe('up-next');

  // Each person's hours count against their own capacity.
  const capacity = await admin.api.get(`/capacity?from=${today}&to=${today}`);
  for (const who of [one, two]) {
    const row = capacity.people.find((entry) => entry.user_id === who.id);
    expect(row, `${who.id} is on the capacity read`).toBeTruthy();
    expect(row.total.committed).toBe(0.5);
  }

  // The first person ticks from My tasks; the board then says 1 of 2.
  const asOne = await Forge.signedIn(browser, baseURL, one.login, Forge.PASSWORD);
  const page = await asOne.context.newPage();

  await page.goto('/blueworx-forge/#screen=mytasks');
  await expect(page.getByTestId('bwx-mytasks')).toBeVisible({ timeout: 30_000 });

  const row = page.locator('[data-testid="bwx-mytasks"] tr', { hasText: `Clear the inbox ${RUN_ID}` });
  await expect(row).toBeVisible();
  // The tick lands when the server answers, so the box follows the reload rather than the click.
  await row.getByTestId('bwx-mytasks-tick').click();
  await expect(row.getByTestId('bwx-mytasks-tick')).toBeChecked({ timeout: 30_000 });

  const half = await admin.api.get(`/work-items/${task.id}`);
  expect(Object.keys(half.item.ticks)).toEqual([one.id]);
  expect(half.item.stage).toBe('up-next');

  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', studio.id);
  const card = page.locator('[data-testid="bwx-card"]', { hasText: `Clear the inbox ${RUN_ID}` });
  await expect(card).toHaveCount(1);
  await expect(card.getByTestId('bwx-card-ticks')).toHaveText('1 of 2 done');
  await page.close();

  // The first person cannot tick for the second.
  const forged = await asOne.api.post(`/work-items/${task.id}/tick`, { user_id: two.id, done: true });
  expect((await admin.api.get(`/work-items/${task.id}`)).item.ticks[two.id]).toBeUndefined();
  expect(forged.status()).toBe(200); // Their own tick again, not the other person's.

  // Somebody not assigned is refused.
  const stranger = await Forge.makePerson(admin.api, studio.client_id, 'staff', 'chorec');
  const asStranger = await Forge.signedIn(browser, baseURL, stranger.login, Forge.PASSWORD);
  const refused = await asStranger.api.post(`/work-items/${task.id}/tick`, { done: true });
  expect(refused.status()).toBe(403);
  await asStranger.context.close();

  // A checklist with a line open holds the tick (2026-09-19).
  const asTwo = await Forge.signedIn(browser, baseURL, two.login, Forge.PASSWORD);
  const listed = await admin.api.patch(`/work-items/${task.id}`, {
    checklist: [{ text: 'Archive the old threads', done: false }],
    record_version: (await admin.api.get(`/work-items/${task.id}`)).item.record_version,
  });
  expect(listed.status(), await listed.text()).toBe(200);
  const held = await asTwo.api.post(`/work-items/${task.id}/tick`, { done: true });
  expect(held.status()).toBe(409);
  await admin.api.patch(`/work-items/${task.id}`, {
    checklist: [{ text: 'Archive the old threads', done: true }],
    record_version: (await admin.api.get(`/work-items/${task.id}`)).item.record_version,
  });

  // The second person ticks over the API, and that is everyone: a check-in
  // has nothing to review or release, so it is Released.
  const ticked = await asTwo.api.post(`/work-items/${task.id}/tick`, { done: true });
  expect(ticked.status(), await ticked.text()).toBe(200);
  expect((await ticked.json()).item.stage).toBe('released');

  const done = await admin.api.get(`/work-items/${task.id}`);
  expect(done.history.some((event) => 'placed' === event.action && 'released' === event.to_stage)).toBe(true);

  await asTwo.context.close();
  await asOne.context.close();
  await admin.context.close();
});
