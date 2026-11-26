import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { CLIENT_URL, requireEnvironment, signedIn } from './helpers/pair.js';
import {
  AKISMET,
  CLIENT_PLUGIN,
  HELLO,
  installUpdateStub,
  nextVersion,
  packageOf,
  removeUpdateStub,
  runUpdater,
} from '../e2e/helpers/auto-update.js';

// #713, on the client plugin. A client site is somebody else's WordPress that
// nobody at the studio logs into, so a release that waits for somebody to press
// Update there never arrives. The update checker offers the release; this
// proves WordPress takes it on its own: the background updater is run, offered
// a release of the client plugin and of two plugins that are not ours, and the
// version on disk changes for ours and for no other.
//
// The client site's own copy of the plugin is a staged one, so it can be
// installed over for real — the studio's, on the other site, cannot.

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const CLIENT_WP = process.env.BWX_CLIENT_WP_DIR || join(ROOT, '.wp-test-client', 'wp');

test.beforeAll(() => {
  requireEnvironment();
  installUpdateStub(CLIENT_WP);
});

test.afterAll(() => removeUpdateStub(CLIENT_WP));

test.describe('a client site installing its own releases', () => {
  test('shows the client plugin updating itself, with no switch to leave off', async ({ browser }) => {
    const { context } = await signedIn(browser, CLIENT_URL);
    const page = await context.newPage();

    await page.goto('/wp-admin/plugins.php');

    const own = page.locator(`tr[data-plugin="${CLIENT_PLUGIN}"] td.column-auto-updates`);

    await expect(own).toContainText('Auto-updates enabled');
    await expect(own.locator('.toggle-auto-update')).toHaveCount(0);

    // Akismet and Hello Dolly ship with every WordPress the harness installs,
    // and still offer the choice they always did.
    for (const plugin of [AKISMET, HELLO]) {
      const cell = page.locator(`tr[data-plugin="${plugin}"] td.column-auto-updates`);

      await expect(cell.locator('.toggle-auto-update[data-wp-action="enable"]')).toHaveCount(1);
      await expect(cell).not.toContainText('Auto-updates enabled');
    }

    await context.close();
  });

  test('installs a new release of the client plugin by itself, and touches nothing else', async ({ playwright }) => {
    const request = await playwright.request.newContext({ baseURL: CLIENT_URL });

    const first = await runUpdater(request, {}, []);
    const installed = first.before[CLIENT_PLUGIN];
    const release = nextVersion(installed);

    const run = await runUpdater(
      request,
      { [CLIENT_PLUGIN]: release, [AKISMET]: '99.0.0', [HELLO]: '99.0.0' },
      [CLIENT_PLUGIN]
    );

    expect(run.before[CLIENT_PLUGIN]).toBe(installed);
    expect(run.after[CLIENT_PLUGIN]).toBe(release);

    // The other two had a release on offer as well. Neither was fetched, and
    // neither changed.
    expect(run.downloads).toEqual([packageOf(CLIENT_PLUGIN)]);
    expect(run.after[AKISMET]).toBe(run.before[AKISMET]);
    expect(run.after[HELLO]).toBe(run.before[HELLO]);

    await request.dispose();
  });
});
