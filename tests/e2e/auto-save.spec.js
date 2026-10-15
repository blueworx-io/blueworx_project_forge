import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #544. In the task panel a drop-down pick, a date and a checklist line save
// on their own, without Save changes, and survive a reload. Typed text still
// waits for Save changes. A refused auto-save says why under the field and
// keeps the saved value.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Auto Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

const read = (id) => admin.api.get(`/work-items/${id}`).then((answer) => answer.item);

test('a drop-down, a date and a checklist line save without Save changes; typed text waits', async () => {
  test.slow();

  const title = `Saves itself ${RUN_ID}`;
  const item = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: title }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  // Something typed, never saved: it must survive every auto-save below.
  await page.locator('#bwx-title').fill(`Typed ${RUN_ID}`);

  // A drop-down.
  await page.locator('#bwx-priority').selectOption('high');
  await expect.poll(() => read(item.id).then((now) => now.priority), { timeout: 30_000 }).toBe('high');
  await expect(page.getByTestId('bwx-panel-notice')).toContainText('Saved.');

  // A date.
  await page.locator('#bwx-planned_due').fill('2026-11-10');
  await expect.poll(() => read(item.id).then((now) => now.planned_due), { timeout: 30_000 }).toBe('2026-11-10');

  // A checklist line.
  await page.getByTestId('bwx-checklist-add').click();
  await page.getByTestId('bwx-checklist-text').nth(0).fill('Wire it up');
  await page.getByTestId('bwx-checklist-text').nth(0).blur();
  await expect.poll(() => read(item.id).then((now) => (now.checklist ?? []).map((row) => row.text)), { timeout: 30_000 }).toEqual(['Wire it up']);

  // The title was not sent, and is still what was typed.
  expect((await read(item.id)).title).toBe(title);
  await expect(page.locator('#bwx-title')).toHaveValue(`Typed ${RUN_ID}`);

  // All of it survives a reload.
  await page.reload();
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: title }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
  await expect(page.locator('#bwx-priority')).toHaveValue('high');
  await expect(page.locator('#bwx-planned_due')).toHaveValue('2026-11-10');
  await expect(page.getByTestId('bwx-checklist-text').nth(0)).toHaveValue('Wire it up');

  await page.close();
});

test('a refused auto-save says why under the field and keeps the saved value', async () => {
  test.slow();

  const title = `Refused ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const dated = await admin.api.patch(`/work-items/${made.id}`, { planned_due: '2026-11-10', record_version: made.record_version });
  expect(dated.status(), await dated.text()).toBe(200);

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: title }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  // Review by before Deliver by is out of order: the server says no.
  await page.locator('#bwx-review_target').fill('2026-11-01');
  const said = page.locator('[data-testid="bwx-field-error"][data-field="review_target"]');
  await expect(said).toBeVisible({ timeout: 30_000 });
  await expect(said).not.toHaveText('');

  // The field went back to what is saved, and nothing was written.
  await expect(page.locator('#bwx-review_target')).toHaveValue('');
  expect((await read(made.id)).review_target).toBe('');

  // A good date clears the message.
  await page.locator('#bwx-review_target').fill('2026-11-12');
  await expect.poll(() => read(made.id).then((now) => now.review_target), { timeout: 30_000 }).toBe('2026-11-12');
  await expect(said).toHaveCount(0);

  await page.close();
});
