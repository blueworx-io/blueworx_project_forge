import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #467. Every rule Forge follows, in plain English, for administrators.
// #528. One topic at a time: the topics are tabs.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const SECTIONS = [ 'journey', 'triage', 'review', 'blocked', 'back', 'recurring', 'meetings', 'capacity', 'reminders', 'requests', 'people' ];

test('an administrator reads the rules one topic at a time, from tabs', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 15_000 });

  await page.getByTestId('bwx-screen-settings').click();
  const screen = page.getByTestId('bwx-settings');
  await expect(screen).toBeVisible();

  // Every topic is a tab, and the first is open.
  const tabs = page.getByTestId('bwx-rules-tabs');
  for (const id of SECTIONS) {
    await expect(tabs.getByTestId(`bwx-rules-tab-${id}`)).toHaveRole('tab');
  }
  await expect(tabs.getByTestId('bwx-rules-tab-journey')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('bwx-rules-journey')).toBeVisible();
  await expect(page.locator('[data-testid^="bwx-rules-"][role="tabpanel"]')).toHaveCount(1);

  // Picking a tab shows only that topic, with rules in it.
  await tabs.getByRole('tab', { name: 'Reminders' }).click();
  await expect(tabs.getByTestId('bwx-rules-tab-reminders')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('bwx-rules-reminders')).toBeVisible();
  await expect(page.getByTestId('bwx-rules-journey')).toHaveCount(0);
  expect(await page.getByTestId('bwx-rules-reminders').locator('li').count(), 'reminders has no rules').toBeGreaterThan(0);

  // The keyboard moves along the tabs too.
  await tabs.getByTestId('bwx-rules-tab-reminders').focus();
  await page.keyboard.press('ArrowRight');
  await expect(tabs.getByTestId('bwx-rules-tab-requests')).toBeFocused();
  await expect(page.getByTestId('bwx-rules-requests')).toBeVisible();

  await admin.context.close();
});

test('a link to one topic opens Settings on that tab', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/#screen=settings&rules=capacity');

  await expect(page.getByTestId('bwx-settings')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByTestId('bwx-rules-tab-capacity')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('bwx-rules-capacity')).toBeVisible();
  await expect(page.getByTestId('bwx-rules-journey')).toHaveCount(0);

  await admin.context.close();
});

// #529. Settings is a cog, in the menu and on its page, not a scroll.
test('Settings carries a cog icon in the menu and on its header', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 15_000 });

  const entry = page.getByTestId('bwx-screen-settings');
  await expect(entry.locator('svg.lucide-settings')).toHaveCount(1);

  await entry.click();
  await expect(page.getByTestId('bwx-settings')).toBeVisible();
  await expect(page.locator('.fk-tile svg.lucide-settings')).toHaveCount(1);

  await admin.context.close();
});
