import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// A task's checklist: up to ten one-line items, ticked in the panel, saved
// at once (#451), and counted on the board card.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;
let item;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await makeSite(admin.api, 'Checklist Co', RUN_ID));
  item = (await (await makeItem(admin.api, site.id, { title: `Ticked ${RUN_ID}` })).json()).item;
});

test.afterAll(async () => {
  await admin?.context.close();
});

function cardFor(page) {
  return page.getByTestId('bwx-card').filter({ hasText: `Ticked ${RUN_ID}` });
}

test('three lines added, one ticked, saved, and counted on the card', async () => {
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);

  // No checklist, no count.
  await expect(cardFor(page).getByTestId('bwx-card-checklist')).toHaveCount(0);

  await cardFor(page).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  await page.getByTestId('bwx-checklist-add').click();
  const lines = page.getByTestId('bwx-checklist-text');
  await lines.nth(0).fill('Write it');
  await lines.nth(0).press('Enter');
  await lines.nth(1).fill('Check it');
  await lines.nth(1).press('Enter');
  await lines.nth(2).fill('Ship it');
  await page.getByTestId('bwx-checklist-done').nth(0).check();
  await page.getByTestId('bwx-save').click();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.');

  const saved = await admin.api.get(`/work-items/${item.id}`);
  expect(saved.item.checklist).toEqual([
    { text: 'Write it', done: true },
    { text: 'Check it', done: false },
    { text: 'Ship it', done: false },
  ]);

  await page.reload();
  await page.waitForSelector('[data-testid="bwx-board"]');
  await expect(cardFor(page).getByTestId('bwx-card-checklist')).toHaveText('1/3');

  await cardFor(page).click();
  await expect(page.getByTestId('bwx-checklist-row')).toHaveCount(3);
  await expect(page.getByTestId('bwx-checklist-done').nth(0)).toBeChecked();
  await expect(page.getByTestId('bwx-checklist-done').nth(1)).not.toBeChecked();
  await page.close();
});

test('an eleventh line, or a line with a line break, is refused', async () => {
  const before = (await admin.api.get(`/work-items/${item.id}`)).item;
  const eleven = Array.from({ length: 11 }, (_, i) => ({ text: `Line ${i + 1}`, done: false }));

  const tooMany = await admin.api.patch(`/work-items/${item.id}`, { checklist: eleven, record_version: before.record_version });
  expect(tooMany.status()).toBe(400);
  expect((await tooMany.json()).data.fields.checklist).toContain('10');

  const broken = await admin.api.patch(`/work-items/${item.id}`, {
    checklist: [{ text: 'Two\nlines', done: false }],
    record_version: before.record_version,
  });
  expect(broken.status()).toBe(400);
  expect((await broken.json()).data.fields.checklist).toContain('one line');

  // Nothing above changed the record.
  expect((await admin.api.get(`/work-items/${item.id}`)).item.checklist).toHaveLength(3);
});

test('a checklist change is saved at once, and a later Save changes does not conflict or lose other edits (#451)', async () => {
  const made = (await (await makeItem(admin.api, site.id, { title: `Instant ${RUN_ID}` })).json()).item;
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: `Instant ${RUN_ID}` }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  // Another field edited but not saved, then the checklist changed.
  await page.locator('#bwx-title').fill(`Renamed ${RUN_ID}`);
  await page.getByTestId('bwx-checklist-add').click();
  await page.getByTestId('bwx-checklist-text').nth(0).fill('First');
  await page.getByTestId('bwx-checklist-text').nth(0).blur();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Checklist saved.');
  await page.getByTestId('bwx-checklist-done').nth(0).check();
  await expect(async () => {
    const now = (await admin.api.get(`/work-items/${made.id}`)).item;
    expect(now.checklist).toEqual([{ text: 'First', done: true }]);
    // The rename was not sent early.
    expect(now.title).toBe(`Instant ${RUN_ID}`);
  }).toPass();

  // Still there on reopening, without Save changes.
  await page.reload();
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.getByTestId('bwx-card').filter({ hasText: `Instant ${RUN_ID}` }).click();
  await expect(page.getByTestId('bwx-checklist-done').nth(0)).toBeChecked();

  // Removing a line is saved at once too, and Save changes afterwards is not a conflict.
  await page.locator('#bwx-title').fill(`Renamed ${RUN_ID}`);
  await page.getByTestId('bwx-checklist-remove').nth(0).click();
  await expect(async () => {
    expect((await admin.api.get(`/work-items/${made.id}`)).item.checklist).toEqual([]);
  }).toPass();
  await page.getByTestId('bwx-save').click();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.');
  const after = (await admin.api.get(`/work-items/${made.id}`)).item;
  expect(after.title).toBe(`Renamed ${RUN_ID}`);
  expect(after.checklist).toEqual([]);
  await page.close();
});

test('a checklist change that cannot be saved says so (#451)', async () => {
  const made = (await (await makeItem(admin.api, site.id, { title: `Refused ${RUN_ID}` })).json()).item;
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: `Refused ${RUN_ID}` }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  await page.route(new RegExp(`/work-items/${made.id}$`), (route) =>
    route.request().method() === 'PATCH' ? route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }) : route.continue()
  );
  await page.getByTestId('bwx-checklist-add').click();
  await page.getByTestId('bwx-checklist-text').nth(0).fill('Lost');
  await page.getByTestId('bwx-checklist-text').nth(0).blur();
  await expect(page.getByTestId('bwx-panel-notice')).toContainText('The checklist was not saved');
  await expect(page.getByTestId('bwx-checklist-text').nth(0)).toHaveValue('Lost');
  await page.close();
});
