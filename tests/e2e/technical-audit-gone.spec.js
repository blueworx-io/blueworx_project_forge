import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #546. Technical Audit is gone. A task goes straight from Documentation
// Period to Up Next with its description and the documentation approval, and
// no screen, filter or picker offers the old stage.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Audit Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('the stage is gone from the list, the board and the journey', async () => {
  test.slow();

  const stages = await admin.api.get('/stages');
  expect(stages.stages.map((stage) => stage.id)).not.toContain('technical-audit');
  expect(stages.columns).not.toContain('technical-audit');
  expect(stages.columns.indexOf('up-next')).toBe(stages.columns.indexOf('documentation-period') + 1);

  const title = `Audited ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const documented = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);

  // The next step is Up Next, through the documentation gate alone.
  const detail = await admin.api.get(`/work-items/${documented.id}`);
  expect(detail.available).toEqual(['up-next']);
  expect(detail.readiness['up-next'].all.map((row) => row.id)).toEqual(['G-DOCUMENTATION-1', 'G-DOCUMENTATION-9']);

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await expect(page.locator('[data-testid="bwx-column"][data-stage="technical-audit"]')).toHaveCount(0);
  await expect(page.locator('[data-testid="bwx-column"][data-stage="up-next"]')).toBeVisible();

  await page.getByTestId('bwx-card').filter({ hasText: title }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
  await expect(page.locator('[data-testid="bwx-move"][data-to="up-next"]')).toBeVisible();
  await expect(page.locator('[data-testid="bwx-move"][data-to="technical-audit"]')).toHaveCount(0);
  await page.close();

  const moved = await Forge.walkTo(admin.api, documented, ['up-next']);
  expect(moved.stage).toBe('up-next');

  // And a move to the old stage is refused as unknown.
  const refused = await admin.api.post(`/work-items/${moved.id}/transition`, { to: 'technical-audit', record_version: moved.record_version });
  expect(refused.status()).toBe(400);
});
