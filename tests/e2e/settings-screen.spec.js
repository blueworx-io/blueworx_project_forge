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

test('an administrator checks for updates from Settings, and nobody else can (#603)', async ({ browser, baseURL }) => {
  test.slow();

  const { version } = JSON.parse(await import('node:fs').then((fs) => fs.readFileSync('package.json', 'utf8')));
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/#screen=settings');

  const updates = page.getByTestId('bwx-updates');
  await expect(updates).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-updates-summary')).toContainText(`Forge ${version} is installed.`, { timeout: 60_000 });

  await page.getByTestId('bwx-updates-check').click();
  await expect(page.getByTestId('bwx-updates-check')).toHaveText('Check for updates', { timeout: 60_000 });
  await expect(page.getByTestId('bwx-updates-summary')).toContainText(`Forge ${version} is installed.`);

  // A branch's version is never behind the latest release, so there is nothing to install.
  const answer = await admin.api.get('/updates');
  expect(answer.installed).toBe(version);
  expect(answer.update_available).toBe(false);
  const install = await admin.api.post('/updates/install', {});
  expect(install.status()).toBe(409);

  // Somebody signed out is refused every route.
  const anon = await browser.newContext({ baseURL });
  for (const [method, path] of [['get', '/updates'], ['post', '/updates/check'], ['post', '/updates/install']]) {
    const refused = await anon.request[method](`/wp-json/blueworx-forge/v1${path}`);
    expect([401, 403], `${method} ${path}`).toContain(refused.status());
  }

  await anon.close();
  await admin.context.close();
});
