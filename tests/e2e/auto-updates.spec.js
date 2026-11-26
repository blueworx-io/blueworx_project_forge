import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

// #713. A release of Forge installs itself: the update checker offers it, and
// this is the other half — WordPress being told to take the offer without
// anybody pressing Update. WordPress's own Plugins screen asks the same
// question the background updater does (is auto-update forced for this
// plugin?), so what that screen says is what a release will do.
//
// And only for Forge. Every other plugin keeps the setting it has, which on a
// plugin nobody has opted in is the "Enable auto-updates" control.

const PLUGINS = '/wp-admin/plugins.php';
const OWN = 'blueworx-forge/blueworx-forge.php';

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test('Forge updates itself, with no switch for anybody to leave off', async ({ page }) => {
  await page.goto(PLUGINS);

  const row = page.locator(`tr[data-plugin="${OWN}"]`);
  await expect(row).toHaveCount(1);

  const cell = row.locator('td.column-auto-updates');
  await expect(cell).toContainText('Auto-updates enabled');

  // Forced, not a setting: nothing to switch.
  await expect(cell.locator('.toggle-auto-update')).toHaveCount(0);
});

test('other plugins keep the update setting they had', async ({ page }) => {
  await page.goto(PLUGINS);

  // Nobody else has been switched on: nothing but Forge says so.
  const enabled = page.locator('#the-list tr[data-plugin] td.column-auto-updates', { hasText: 'Auto-updates enabled' });
  await expect(enabled).toHaveCount(1);
  await expect(page.locator(`tr[data-plugin="${OWN}"] td.column-auto-updates`)).toContainText('Auto-updates enabled');

  // And the ones that were a choice still are: a control to turn it on, left
  // alone. Akismet and Hello Dolly ship with every WordPress the harness
  // installs.
  for (const plugin of ['akismet/akismet.php', 'hello.php']) {
    const cell = page.locator(`tr[data-plugin="${plugin}"] td.column-auto-updates`);

    await expect(cell.locator('.toggle-auto-update[data-wp-action="enable"]')).toHaveCount(1);
  }
});
