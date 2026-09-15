import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, PASSWORD } from './helpers/forge.js';

// The corner of every screen says who is signed in, and opens their profile.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('the top bar names the signed-in person and links to their profile', async ({ browser, baseURL, page }) => {
  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client } = await makeSite(admin.api, 'Chip Co', RUN_ID);
  const person = await makePerson(admin.api, client.id, 'staff', 'chip');
  await admin.context.close();

  await signIn(page, person.login, PASSWORD);
  await page.goto('/blueworx-forge/');

  const chip = page.getByTestId('bwx-profile');
  await expect(chip).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-profile-name')).toHaveText(person.user.display_name);
  await expect(page.getByTestId('bwx-profile-email')).toContainText('@');
  await expect(chip).toHaveAttribute('href', /profile\.php$/);
});
