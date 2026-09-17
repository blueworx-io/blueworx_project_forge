import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makePackage } from './helpers/forge.js';

// The package catalogue in the app: see what is on offer, add one, revise
// it as a new version, retire and restore, reorder. Names carry a run id
// because the instance is reused between runs and already holds packages.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

test.describe.configure({ mode: 'serial' });

let admin;
let seeded;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  seeded = await makePackage(admin.api, `Seeded ${RUN_ID}`, { hours: 10, price: 900 });
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-packages').click();
  await expect(page.getByTestId('bwx-packages')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-packages-count')).toBeVisible({ timeout: 30_000 });
});

/** The catalogue row for one package, found by its name. */
function rowFor(page, name) {
  return page.getByTestId('bwx-packages-list').locator('tbody tr', { hasText: name });
}

test('the rail offers Packages under Insight, and the catalogue lists what is on offer', async ({ page }) => {
  await expect(page.locator('h1')).toHaveText('Support packages');

  const row = rowFor(page, seeded.name);
  await expect(row).toBeVisible();
  await expect(row).toContainText('On the shelf');
  await expect(row).toContainText('10h');
  await expect(row).toContainText('v1');
});
