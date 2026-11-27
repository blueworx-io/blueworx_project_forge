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
  updaterApi,
  useDisposableCopy,
  withOtherPlugins,
} from '../e2e/helpers/auto-update.js';

// #713, on the client plugin. A client site is somebody else's WordPress that
// nobody at the studio logs into, so a release that waits for somebody to press
// Update there never arrives. The update checker offers the release; this
// proves WordPress takes it on its own: the background updater is run, offered
// a release of the client plugin and of two plugins that are not ours, and the
// version on disk changes for ours and for no other.
//
// The harness links the client site's plugin to a staged copy, and installing
// over a link would empty what it points at, so the install runs on a copy of
// what ships, swapped in for that one test and swapped back after.

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const CLIENT_WP = process.env.BWX_CLIENT_WP_DIR || join(ROOT, '.wp-test-client', 'wp');

test.beforeAll(() => {
  requireEnvironment();
  installUpdateStub(CLIENT_WP);
});

test.afterAll(() => removeUpdateStub(CLIENT_WP));

test.describe('a client site installing its own releases', () => {
  test('shows the client plugin updating itself, with no switch to leave off', async ({ browser }) => {
    const { context, nonce } = await signedIn(browser, CLIENT_URL);
    const page = await context.newPage();

    await page.goto('/wp-admin/plugins.php');

    const own = page.locator(`tr[data-plugin="${CLIENT_PLUGIN}"] td.column-auto-updates`);

    await expect(own).toContainText('Auto-updates enabled');
    await expect(own.locator('.toggle-auto-update')).toHaveCount(0);

    // Akismet and Hello Dolly ship with every WordPress the harness installs.
    // Each keeps the choice somebody made, set here rather than assumed.
    const updater = updaterApi(context.request, nonce);
    await withOtherPlugins(updater, [AKISMET], async () => {
      await page.reload();

      const on = page.locator(`tr[data-plugin="${AKISMET}"] td.column-auto-updates`);
      const off = page.locator(`tr[data-plugin="${HELLO}"] td.column-auto-updates`);

      await expect(on.locator('.toggle-auto-update[data-wp-action="disable"]')).toHaveCount(1);
      await expect(off.locator('.toggle-auto-update[data-wp-action="enable"]')).toHaveCount(1);
      await expect(off).not.toContainText('Auto-updates enabled');
    });

    await context.close();
  });

  test('installs a new release of the client plugin by itself, and touches nothing else', async ({ browser }) => {
    const { context, nonce } = await signedIn(browser, CLIENT_URL);
    const updater = updaterApi(context.request, nonce);

    // The harness links this plugin to the staged copy; installing over a link
    // would empty what it points at, so the update lands on a copy of its own
    // and the link goes back afterwards.
    const restore = useDisposableCopy('client', 'blueworx-forge-client', CLIENT_WP);

    try {
      const installed = (await updater.versions())[CLIENT_PLUGIN];
      const release = nextVersion(installed);

      // Neither of the others has automatic updates on, whatever this site had.
      let run;
      await withOtherPlugins(updater, [], async () => {
        run = await updater.run(
          { [CLIENT_PLUGIN]: release, [AKISMET]: '99.0.0', [HELLO]: '99.0.0' },
          [CLIENT_PLUGIN]
        );
      });

      expect(run.before[CLIENT_PLUGIN]).toBe(installed);
      expect(run.after[CLIENT_PLUGIN]).toBe(release);

      // The other two had a release on offer as well. Neither was fetched, and
      // neither changed.
      expect(run.downloads).toEqual([packageOf(CLIENT_PLUGIN)]);
      expect(run.after[AKISMET]).toBe(run.before[AKISMET]);
      expect(run.after[HELLO]).toBe(run.before[HELLO]);
    } finally {
      restore();
      await context.close();
    }
  });
});
