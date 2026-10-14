import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #548. A task with no designer goes straight from Up Next to In Development;
// Design Process is only a stop for tasks that have a design. The checks that
// run when work starts still run on that move.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const TO_UP_NEXT = ['triage', 'documentation-period', 'up-next'];

let admin;
let client;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client, site } = await Forge.makeSite(admin.api, 'Skip Co', RUN_ID));
  await Forge.onSupport(admin, site.id, 400);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('with no designer the next step from Up Next is In Development, and the capacity check still runs', async () => {
  test.slow();

  const title = `Plain ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  // A doer of this spec's own, so cutting their hours touches nobody else's run.
  const doer = await Forge.makePerson(admin.api, client.id, 'staff', `doer${RUN_ID}`);
  const seats = { ...(await Forge.seatsFor(admin.api, made)), primary_user_id: doer.id };

  // One hour a day for the doer, and five hours of work on one day: over-booked.
  await Forge.setHours(admin.api, doer.id, 1);
  const parked = await Forge.walkTo(admin.api, made, TO_UP_NEXT, { seats: { ...seats, hours_primary: 5, planned_due: '2026-11-10' } });

  const detail = await admin.api.get(`/work-items/${parked.id}`);
  expect(detail.item.designer_id).toBe('');
  expect(detail.available).toEqual(['in-development']);

  // The panel agrees: the move offered is to In Development, and Design is not.
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: title }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
  await expect(page.locator('[data-testid="bwx-move"][data-to="in-development"]')).toBeVisible();
  await expect(page.locator('[data-testid="bwx-move"][data-to="design-process"]')).toHaveCount(0);
  await page.close();

  // Everything else the gate wants, so the only thing left is the capacity.
  const ready = await Forge.satisfy(admin.api, parked, 'in-development');
  const refused = await admin.api.post(`/work-items/${ready.id}/transition`, { to: 'in-development', record_version: ready.record_version });
  expect(refused.status(), await refused.text()).toBe(409);
  expect((await refused.json()).unmet.map((row) => row.id)).toContain('G-UP-NEXT-8');

  // With a reason it goes, and lands in In Development, never in Design.
  const moved = await admin.api.post(`/work-items/${ready.id}/transition`, {
    to: 'in-development',
    record_version: ready.record_version,
    capacity_reason: 'Agreed with the client.',
  });
  expect(moved.status(), await moved.text()).toBe(200);
  expect((await moved.json()).item.stage).toBe('in-development');

  // And going back from In Development offers Up Next, not Design.
  const after = await admin.api.get(`/work-items/${ready.id}`);
  expect(after.returns).not.toContain('design-process');
  expect(after.returns).toContain('up-next');
});

test('with a designer the task still goes through Design Process', async () => {
  test.slow();

  const made = (await (await Forge.makeItem(admin.api, site.id, { title: `Designed ${RUN_ID}` })).json()).item;
  const designer = await Forge.makePerson(admin.api, client.id, 'staff', `designer${RUN_ID}`);
  const parked = await Forge.walkTo(admin.api, made, TO_UP_NEXT, { seats: { designer_id: designer.id } });

  const detail = await admin.api.get(`/work-items/${parked.id}`);
  expect(detail.available).toEqual(['design-process']);

  const refused = await admin.api.post(`/work-items/${parked.id}/transition`, { to: 'in-development', record_version: parked.record_version });
  expect(refused.status()).toBe(409);

  const designed = await Forge.walkTo(admin.api, parked, ['design-process'], { seats: { designer_id: designer.id } });
  expect(designed.stage).toBe('design-process');
});
