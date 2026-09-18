import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// A task's checklist: up to ten one-line items, ticked in the panel, saved
// with Save changes, and counted on the board card.

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
  await page.selectOption('[data-testid="bwx-site"]', site.id);

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
