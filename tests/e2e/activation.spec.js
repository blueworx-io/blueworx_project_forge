import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { forge, makeSite } from './helpers/forge.js';

const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

// A spec that skips itself is not a spec that passed. Fail loudly instead, so a
// missing secret is a broken build rather than a silently smaller suite.
test.beforeAll(() => {
  if (!ADMIN_USER || !ADMIN_PASS) {
    throw new Error('WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

test('the plugin is installed and active', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  // By plugin file, not by row id: WordPress builds the id from the plugin's
  // display name, so renaming the plugin silently stops the row being found.
  const row = page.locator('tr[data-plugin="blueworx-forge/blueworx-forge.php"]');
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('BlueWorx Labs | Forge Parent Site');
  // Word-boundary match is load-bearing: WordPress renders an inactive row with
  // class="inactive", and the substring "active" inside "inactive" would match
  // /active/ regardless of activation state.
  await expect(row).toHaveClass(/(^|\s)active(\s|$)/);
});

test('activating the plugin raises no PHP error', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  // A fatal on activation surfaces here as an error notice rather than a crash.
  // Filtered to :visible because WP core renders a hidden, empty .notice-error
  // template in every plugin row's auto-updates column.
  await expect(page.locator('#message.error, .notice-error:visible')).toHaveCount(0);
});

test('activation builds the plugin tables', async ({ page }) => {
  await signIn(page);

  // The sites screen keeps its sites in an option, not a table, so it proves
  // only that the plugin booted, not that bwx_forge_clients and
  // bwx_forge_client_sites exist. Writing a client with a site and reading
  // them back does: if either table (or a column on it) is missing, the INSERT
  // or the SELECT that draws the list fails, and the client never appears.
  const runId = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const name = `Activation Ltd ${runId}`;

  await page.goto('/wp-admin/admin.php?page=blueworx-forge-sites');
  // On the shared page shell the heading is the screen's own name and "Forge"
  // is the eyebrow above it, so this reads the heading for what it now says.
  await expect(page.locator('h1.bw-pagehead__h1')).toContainText('Client sites');

  // The app page is where the nonce is localised and where the client is read
  // back: the Clients screen lives there, not in WordPress admin.
  await page.goto('/blueworx-forge/');
  const nonce = await page.evaluate(() => window.bwxForgeData?.nonce);
  expect(nonce, 'no REST nonce was localised for the signed-in user').toBeTruthy();

  const api = forge(page.request, nonce);
  await makeSite(api, 'Activation Ltd', runId);

  await page.goto('/blueworx-forge/#screen=clients');
  // Already on the app page, so that was a fragment change rather than a
  // navigation; the app reads the hash on a fresh load, so force one.
  await page.reload();
  await expect(page.getByTestId('bwx-clients')).toBeVisible({ timeout: 30_000 });
  await expect(
    page.getByTestId('bwx-clients-list').locator('tbody tr', { hasText: name })
  ).toBeVisible();
});
