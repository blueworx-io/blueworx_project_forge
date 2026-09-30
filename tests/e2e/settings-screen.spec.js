import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #467. Every rule Forge follows, in plain English, for administrators.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const SECTIONS = [ 'journey', 'triage', 'review', 'blocked', 'back', 'recurring', 'meetings', 'capacity', 'reminders', 'requests', 'people' ];

test('an administrator reads the rules under Insight, and jumps to a section', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 15_000 });

  await page.getByTestId('bwx-screen-settings').click();
  const screen = page.getByTestId('bwx-settings');
  await expect(screen).toBeVisible();

  for (const id of SECTIONS) {
    const section = page.getByTestId(`bwx-rules-${id}`);
    await expect(section).toHaveCount(1);
    expect(await section.locator('li').count(), `${id} has no rules`).toBeGreaterThan(0);
  }

  await page.getByTestId('bwx-rules-jump').getByRole('link', { name: 'Reminders' }).click();
  await expect(page.getByTestId('bwx-rules-reminders')).toBeInViewport();
  // Keyboard users land where they jumped to, not back on the links.
  await expect(page.getByTestId('bwx-rules-reminders')).toBeFocused();

  await admin.context.close();
});
