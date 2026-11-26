import { test, expect } from '@playwright/test';
import { CLIENT_URL, requireEnvironment, signedIn } from './helpers/pair.js';

// #713, on the client plugin. A client site is somebody else's WordPress that
// nobody at the studio logs into, so a release that waits for somebody to press
// Update there never arrives. The update checker offers the release; this proves
// WordPress is told to take it on its own — WordPress's Plugins screen asks the
// same question the background updater does.
//
// And only for this plugin: every other plugin on the client's site keeps the
// update setting it had.

test.beforeAll(() => {
  requireEnvironment();
});

const PLUGINS = '/wp-admin/plugins.php';
const OWN = 'blueworx-forge-client/blueworx-forge-client.php';

test.describe('a client site installing its own releases', () => {
  test('updates the client plugin with no switch to leave off, and nothing else', async ({ browser }) => {
    const { context } = await signedIn(browser, CLIENT_URL);
    const page = await context.newPage();

    await page.goto(PLUGINS);

    const own = page.locator(`tr[data-plugin="${OWN}"] td.column-auto-updates`);

    await expect(own).toContainText('Auto-updates enabled');
    await expect(own.locator('.toggle-auto-update')).toHaveCount(0);

    // Nobody else has been switched on.
    await expect(
      page.locator('#the-list tr[data-plugin] td.column-auto-updates', { hasText: 'Auto-updates enabled' })
    ).toHaveCount(1);

    // Akismet and Hello Dolly ship with every WordPress the harness installs,
    // and still offer the choice they always did.
    for (const plugin of ['akismet/akismet.php', 'hello.php']) {
      const cell = page.locator(`tr[data-plugin="${plugin}"] td.column-auto-updates`);

      await expect(cell.locator('.toggle-auto-update[data-wp-action="enable"]')).toHaveCount(1);
    }

    await context.close();
  });
});
