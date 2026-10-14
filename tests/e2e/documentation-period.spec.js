import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #545. Documentation Period no longer asks which sites the work affects: the
// description and the reviewer's approval are the whole gate.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Docs Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('a task leaves Documentation Period with its description and the approval alone', async () => {
  test.slow();

  const title = `Documented ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);

  const detail = await admin.api.get(`/work-items/${item.id}`);
  const [next] = detail.available;
  const rows = detail.readiness[next].all.map((row) => row.id);

  expect(rows).not.toContain('G-DOCUMENTATION-7');
  expect(rows).toEqual(['G-DOCUMENTATION-1', 'G-DOCUMENTATION-9']);

  // The panel shows no Affected sites row either.
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: title }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  const gate = page.getByTestId('bwx-gate').first();
  await expect(gate.locator('li[data-requirement="G-DOCUMENTATION-9"]')).toBeVisible();
  await expect(gate).not.toContainText('Affected sites');

  // Only the approval stands in the way, and with it the task moves on.
  const approved = await admin.api.post(`/work-items/${item.id}/gate`, { requirement: 'G-DOCUMENTATION-9', value: 'approved', evidence: '' });
  expect(approved.status(), await approved.text()).toBe(200);

  const current = (await admin.api.get(`/work-items/${item.id}`)).item;
  const moved = await admin.api.post(`/work-items/${item.id}/transition`, { to: next, record_version: current.record_version });

  expect(moved.status(), await moved.text()).toBe(200);
  expect((await moved.json()).item.stage).toBe(next);

  await page.close();
});
