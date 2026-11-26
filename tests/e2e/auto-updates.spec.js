import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import {
  AKISMET,
  HELLO,
  STUDIO_PLUGIN,
  installUpdateStub,
  nextVersion,
  packageOf,
  removeUpdateStub,
  updaterApi,
  useDisposableCopy,
} from './helpers/auto-update.js';

// #713. A release of Forge installs itself: the update checker offers it, and
// this is the other half — WordPress being told to take the offer without
// anybody pressing Update. Two things are proven. WordPress's own Plugins screen
// asks the question the background updater asks (is auto-update forced for this
// plugin?), so what it says is what a release will do. And the background
// updater itself is run, offered a release of Forge and of two plugins that are
// not ours, and the version on disk changes for Forge and for nobody else.
//
// On a test site Forge is a link to this repository, and installing over a link
// would empty the repository. So the install runs on a disposable copy of what
// ships, swapped in for the length of that one test and swapped back after.
//
// The test site has no network, so the update data and the release come from a
// stand-in (tests/support/auto-update-stub.php) rather than whatever a previous
// check left behind. The client plugin is installed for real in
// tests/pair/client-auto-updates.

const PLUGINS = '/wp-admin/plugins.php';

test.beforeAll(() => installUpdateStub());
test.afterAll(() => removeUpdateStub());

test('Forge updates itself, with no switch for anybody to leave off', async ({ page }) => {
  await signIn(page);
  await page.goto(PLUGINS);

  const row = page.locator(`tr[data-plugin="${STUDIO_PLUGIN}"]`);
  await expect(row).toHaveCount(1);

  const cell = row.locator('td.column-auto-updates');
  await expect(cell).toContainText('Auto-updates enabled');

  // Forced, not a setting: nothing to switch.
  await expect(cell.locator('.toggle-auto-update')).toHaveCount(0);
});

test('other plugins keep the update setting they had', async ({ page }) => {
  await signIn(page);
  await page.goto(PLUGINS);

  // Still a choice somebody makes, and still not made: a control to turn it on.
  for (const plugin of [AKISMET, HELLO]) {
    const cell = page.locator(`tr[data-plugin="${plugin}"] td.column-auto-updates`);

    await expect(cell.locator('.toggle-auto-update[data-wp-action="enable"]')).toHaveCount(1);
    await expect(cell).not.toContainText('Auto-updates enabled');
  }
});

test("the background updater installs a Forge release, and nobody else's",async ({ page }) => {
  await signIn(page);
  const nonce = await page.evaluate(() => window.wpApiSettings?.nonce);
  expect(nonce, 'no REST nonce on the admin page').toBeTruthy();

  const updater = updaterApi(page.request, nonce);
  const restore = useDisposableCopy('studio', 'blueworx-forge');

  try {
    const installed = (await updater.versions())[STUDIO_PLUGIN];
    const release = nextVersion(installed);

    const run = await updater.run(
      { [STUDIO_PLUGIN]: release, [AKISMET]: '99.0.0', [HELLO]: '99.0.0' },
      [STUDIO_PLUGIN]
    );

    // Forge took its release with nobody pressing Update.
    expect(run.before[STUDIO_PLUGIN]).toBe(installed);
    expect(run.after[STUDIO_PLUGIN]).toBe(release);

    // The other two had releases on offer too. Neither was fetched, and
    // neither changed.
    expect(run.downloads).toEqual([packageOf(STUDIO_PLUGIN)]);
    expect(run.after[AKISMET]).toBe(run.before[AKISMET]);
    expect(run.after[HELLO]).toBe(run.before[HELLO]);
  } finally {
    restore();
  }
});

test('the test updater takes nobody but an administrator', async ({ request }) => {
  const asked = await request.post('/wp-json/bwx-forge-test/v1/updates/run', {
    data: { offers: { [STUDIO_PLUGIN]: '9.9.9' }, build: [] },
  });

  expect([401, 403]).toContain(asked.status());
});
