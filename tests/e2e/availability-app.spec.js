import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson } from './helpers/forge.js';

// The availability screen in the app: pick a person, see their week, set
// their hours, record time off. Names carry a run id because the instance is
// reused between runs.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const NAME = `avail${STAMP}`;

test.describe.configure({ mode: 'serial' });

let admin;
let person;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await makeSite(admin.api, 'Availability app', RUN_ID);
  person = await makePerson(admin.api, where.client.id, 'staff', NAME);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
});

test('the rail offers Availability under Team, and it opens', async ({ page }) => {
  const entry = page.getByTestId('bwx-screen-availability');
  await expect(entry).toBeVisible();

  await entry.click();
  await expect(page.getByTestId('bwx-availability')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('h1')).toHaveText('Availability');
});
