import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #561. A client has a page of its own, reached from Clients or by link.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let client;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client } = await Forge.makeSite(admin.api, 'Profile Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

test("opens a client's profile from Clients and by link", async () => {
  const page = await admin.context.newPage();

  await page.goto('/blueworx-forge/#screen=clients');
  await expect(page.getByTestId('bwx-clients-count')).toBeVisible({ timeout: 30_000 });
  await page.getByTestId('bwx-clients-list').locator('tbody tr', { hasText: client.display_name }).click();
  await page.getByTestId('bwx-clients-open-profile').click();

  await expect(page.getByTestId('bwx-client-profile')).toContainText(client.display_name, { timeout: 30_000 });
  await expect(page.getByTestId('bwx-client-projects')).toBeVisible();

  await page.close();

  const linked = await admin.context.newPage();

  await linked.goto(`/blueworx-forge/#screen=client&client=${client.id}`);
  await expect(linked.getByTestId('bwx-client-profile')).toContainText(client.display_name, { timeout: 30_000 });
  await expect(linked.getByTestId('bwx-client-projects')).toBeVisible();
});
