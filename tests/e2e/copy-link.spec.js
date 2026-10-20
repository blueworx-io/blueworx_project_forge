import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #559. Every item has a Copy link button that puts a link to it on the clipboard.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;
let item;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Linked Co', RUN_ID));
  item = (await (await Forge.makeItem(admin.api, site.id, { title: `Linked item ${RUN_ID}` })).json()).item;
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('copies a link that opens the item', async () => {
  test.slow();
  await admin.context.grantPermissions(['clipboard-read', 'clipboard-write']);
  const page = await admin.context.newPage();
  await page.goto(`/blueworx-forge/#item=${item.id}`);
  await expect(page.getByTestId('bwx-panel')).toContainText(item.title, { timeout: 60_000 });

  const button = page.getByTestId('bwx-copy-link');
  await button.click();
  await expect(page.getByTestId('bwx-copy-link-done')).toHaveText('Link copied');

  const link = await button.getAttribute('data-link');
  expect(link).toMatch(new RegExp(`#item=${item.id}$`));
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(link);

  const other = await admin.context.newPage();
  await other.goto(link);
  await expect(other.getByTestId('bwx-panel')).toContainText(item.title, { timeout: 60_000 });
});

test('falls back to showing the link', async () => {
  test.slow();
  const page = await admin.context.newPage();
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
  });
  await page.goto(`/blueworx-forge/#item=${item.id}`);
  const button = page.getByTestId('bwx-copy-link');
  await button.click();
  await expect(page.getByTestId('bwx-copy-link-field')).toHaveValue(await button.getAttribute('data-link'));
});
