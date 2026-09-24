import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// The before-items resolve themselves (2026-09-18). Nothing is typed into a
// "record" box any more: a check is worked out from the task, chosen from a
// dropdown on its row, or filled in a box on the task that appears when the
// next stage wants it.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let client;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client, site } = await Forge.makeSite(admin.api, 'Resolve Co', RUN_ID));
  await Forge.onSupport(admin, site.id, 200);
});

test.afterAll(async () => {
  await admin?.context.close();
});

async function openOnBoard(page, title) {
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: title }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
}

/** Bug Tracking is off the board's linear path, so a bug is opened from the list. */
async function openFromList(page, itemId) {
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', site.id);
  await page.getByTestId('bwx-view-list').click();
  await page.locator(`[data-testid="bwx-row"][data-item="${itemId}"] .bwx-row-open`).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
}

test('a new idea with a site needs its source picked and two people named, and then moves', async () => {
  const title = `Idea ${RUN_ID}`;
  const item = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const seats = await Forge.seatsFor(admin.api, item);
  const page = await admin.context.newPage();

  await openOnBoard(page, title);

  const gate = page.getByTestId('bwx-gate').first();

  // The site and the description are read off the task; the source is the one pick.
  await expect(gate.locator('li[data-requirement="G-FUTURE-IDEA-2"]')).toHaveAttribute('data-met', 'true');
  await expect(gate.locator('li[data-requirement="G-FUTURE-IDEA-3"] [data-testid="bwx-pick"]')).toBeVisible();
  await expect(page.locator('[data-testid="bwx-move"][data-to="triage"]')).toHaveAttribute('data-ready', 'false');

  // Since 2026-09-19 the two people are named here too, and the panel says so.
  await expect(page.locator('[data-testid="bwx-needed"][data-field="primary_user_id"]')).toContainText('needed to leave');
  await gate.locator('li[data-requirement="G-FUTURE-IDEA-3"] [data-testid="bwx-pick"]').selectOption('client-request');
  await page.selectOption('#bwx-primary_user_id', seats.primary_user_id);
  await page.selectOption('#bwx-reviewer_id', seats.reviewer_id);
  await page.getByTestId('bwx-save').click();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.', { timeout: 30_000 });

  // Nothing left: the gate list is gone and the move is ready.
  await expect(page.locator('[data-testid="bwx-move"][data-to="triage"]')).toHaveAttribute('data-ready', 'true');
  await page.locator('[data-testid="bwx-move"][data-to="triage"]').click();
  await expect(page.getByTestId('bwx-panel-stage')).toHaveText('Triage');

  const records = (await admin.api.get(`/work-items/${item.id}`)).records;

  expect(records['G-FUTURE-IDEA-3'].value).toBe('client-request');

  await page.close();
});

test('a bug reaches Documentation from four boxes, two picks, a link and who pays', async () => {
  test.slow();

  const title = `Bug ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title, work_type: 'bug' })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage', 'bug-tracking']);
  const page = await admin.context.newPage();

  await openFromList(page, item.id);

  // The four stage boxes are on the task now, because this stage wants them.
  for (const id of ['G-BUG-TRACKING-2', 'G-BUG-TRACKING-3', 'G-BUG-TRACKING-4', 'G-BUG-TRACKING-7']) {
    await page.getByTestId(`bwx-box-${id}`).fill(`Filled in for ${id}.`);
  }

  const gate = page.getByTestId('bwx-gate').first();

  await gate.locator('li[data-requirement="G-BUG-TRACKING-1"] [data-testid="bwx-pick"]').selectOption('regression');
  await gate.locator('li[data-requirement="G-BUG-TRACKING-6"] [data-testid="bwx-pick"]').selectOption('high');
  await page.selectOption('#bwx-commercial_class', 'free-bug');
  await page.getByTestId('bwx-save').click();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.', { timeout: 30_000 });

  // Evidence is a comment with a link, worked out rather than ticked.
  await expect(gate.locator('li[data-requirement="G-BUG-TRACKING-5"]')).toHaveAttribute('data-met', 'false');
  await page.getByTestId('bwx-section-evidence').click();
  await page.getByTestId('bwx-comment').fill('The broken page.');
  await page.getByTestId('bwx-comment-url').fill('https://example.test/broken');
  await page.getByTestId('bwx-add-comment').click();

  // That was the last thing: Who pays answered Delivered by Forge as well, so
  // the list has nothing left to show and the move is ready.
  await expect(page.getByTestId('bwx-gate')).toHaveCount(0, { timeout: 30_000 });
  await expect(page.locator('[data-testid="bwx-move"][data-to="documentation-period"]')).toHaveAttribute('data-ready', 'true');

  await page.locator('[data-testid="bwx-move"][data-to="documentation-period"]').click();
  await expect(page.getByTestId('bwx-panel-stage')).toHaveText('Documentation period');

  const saved = await admin.api.get(`/work-items/${item.id}`);

  expect(saved.records['G-BUG-TRACKING-3'].value).toBe('Filled in for G-BUG-TRACKING-3.');
  expect(saved.records['G-BUG-TRACKING-6'].value).toBe('high');

  await page.close();
});

test('the planned hours item is met by the three seat hours alone', async ({ browser, baseURL }) => {
  test.slow();

  const crew = await Forge.team(admin.api, browser, baseURL, client.id);
  const title = `Hours ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next']);

  // G-UP-NEXT is what Up Next has to satisfy to leave, so it is the gate before In development.
  const before = (await admin.api.get(`/work-items/${item.id}`)).readiness['in-development'].all.find((row) => 'G-UP-NEXT-4' === row.id);

  expect(before.met).toBe(false);

  const edited = await admin.api.patch(`/work-items/${item.id}`, {
    ...crew.seats,
    hours_primary: 3,
    hours_review: 1,
    hours_delivery: 0.5,
    record_version: item.record_version,
  });

  expect(edited.status(), await edited.text()).toBe(200);

  const after = await admin.api.get(`/work-items/${item.id}`);

  expect(after.readiness['in-development'].all.find((row) => 'G-UP-NEXT-4' === row.id).met).toBe(true);
  expect(after.records['G-UP-NEXT-4']).toBeUndefined();

  // And the panel shows the tick, with no control on the row.
  const page = await admin.context.newPage();

  await openOnBoard(page, title);

  const row = page.getByTestId('bwx-gate').first().locator('li[data-requirement="G-UP-NEXT-4"]');

  await expect(row).toHaveAttribute('data-met', 'true');
  await expect(row.getByTestId('bwx-pick')).toHaveCount(0);

  await page.close();
  await crew.close();
});

test('blocking on another item stores that item as the reason', async () => {
  const title = `Blocked ${RUN_ID}`;
  const other = (await (await Forge.makeItem(admin.api, site.id, { title: `Upstream ${RUN_ID}` })).json()).item;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage']);
  const page = await admin.context.newPage();

  await openOnBoard(page, title);
  await page.getByTestId('bwx-show-block').click();

  // Who owns the blocker comes first and is the one thing it needs; there is
  // no next action (2026-09-24).
  const form = page.locator('[data-testid="bwx-block"].bwx-actions-form');
  await expect(form.locator('select, input').first()).toHaveAttribute('data-testid', 'bwx-blocker-owner');
  await expect(page.getByTestId('bwx-blocker-next_action')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Block it' })).toBeDisabled();

  await page.getByTestId('bwx-blocker-owner').selectOption('client');
  await page.getByTestId('bwx-blocker-reason').selectOption(other.id);
  await page.getByTestId('bwx-blocker-dependency').selectOption('other');
  await page.getByTestId('bwx-blocker-dependency-text').fill('A decision from them.');
  await page.getByTestId('bwx-blocker-target_date').fill('2026-10-01');
  await page.getByRole('button', { name: 'Block it' }).click();

  await expect(page.getByTestId('bwx-panel-stage')).toHaveText('Blocked');

  const history = (await admin.api.get(`/work-items/${item.id}`)).history;
  const blocked = history.find((event) => 'blocked' === event.action);

  expect(blocked.reason).toBe(`Upstream ${RUN_ID}`);
  expect(blocked.detail).toBe('A decision from them.');

  await page.close();
});
