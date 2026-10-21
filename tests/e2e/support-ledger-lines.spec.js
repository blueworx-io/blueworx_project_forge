import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #568. A task sent back after its work started does not take its hours
// again, and the Support screen shows each task once, with the hours it used
// and its reserved / released / done history folded beneath it.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('a task sent back and started again is charged once, and shows as one line', async ({ browser, baseURL }) => {
  test.setTimeout(300_000);

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'Ledger Co', RUN_ID);
  const { hours } = await Forge.onSupport(admin, site.id, 20);
  const crew = await Forge.team(admin.api, browser, baseURL, client.id);

  const title = `Design Updates ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const planned = await admin.api.patch(`/work-items/${made.id}`, {
    commercial_class: 'chargeable',
    hours_primary: 2,
    hours_review: 0.25,
    record_version: made.record_version,
  });
  expect(planned.status(), await planned.text()).toBe(200);

  const path = ['triage', 'documentation-period', 'up-next', 'in-development'];
  let item = await Forge.walkTo(admin.api, (await planned.json()).item, path, { seats: crew.seats });
  // What the three seats add up to, as the walk left them.
  const PLANNED = Math.round((Number(item.hours_primary) + Number(item.hours_review) + Number(item.hours_delivery)) * 100) / 100;
  const LEFT = Math.round((hours - PLANNED) * 100) / 100;
  expect((await Forge.hourLedger(admin, site.id)).balance).toBe(LEFT);

  // Sent back to Up Next after the work started: nothing is taken again.
  const back = await admin.api.post(`/work-items/${item.id}/return`, { to: 'up-next', reason: 'More changes.', record_version: item.record_version });
  expect(back.status(), await back.text()).toBe(200);
  item = (await back.json()).item;
  expect((await Forge.hourLedger(admin, site.id)).balance).toBe(LEFT);

  // And forward again: still charged once.
  item = await Forge.walkTo(admin.api, item, ['in-development'], { seats: crew.seats });
  const ledger = await Forge.hourLedger(admin, site.id);
  expect(ledger.balance).toBe(LEFT);
  expect(ledger.entries.filter(([type]) => 'work-reservation' === type)).toHaveLength(1);

  // The screen: one line for the task, its hours used, and its history folded.
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-support').click();
  await expect(page.getByTestId('bwx-support')).toBeVisible({ timeout: 30_000 });
  await page.getByTestId('bwx-client-choice').selectOption(site.id);
  await expect(page.getByTestId('bwx-support-state')).toBeVisible({ timeout: 30_000 });

  const rows = page.getByTestId('bwx-support-ledger').locator('tbody tr', { hasText: title });
  await expect(rows).toHaveCount(1);
  await expect(rows).toContainText('Work done');
  await expect(rows.getByTestId('bwx-ledger-hours')).toHaveAttribute('data-hours', String(-PLANNED));

  const history = rows.getByTestId('bwx-ledger-history');
  await expect(history.locator('li').first()).toBeHidden();
  await history.locator('summary').click();
  await expect(history.locator('li')).toHaveCount(3);
  await expect(history).toContainText('Work reserved');
  await expect(history).toContainText('Work released');

  // "Left" is the balance the ledger adds up to.
  await expect(page.getByTestId('bwx-support-balance')).toHaveAttribute('data-balance', String(LEFT));
  await expect(page.getByTestId('bwx-support-ledger')).toContainText(`Left: ${LEFT}h`);

  await page.close();
  await crew.close();
  await admin.context.close();
});
