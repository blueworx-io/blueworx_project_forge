import { test, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

// A spec that skips itself is not a spec that passed. Fail loudly instead, so a
// missing secret is a broken build rather than a silently smaller suite.
test.beforeAll(() => {
  if (!ADMIN_USER || !ADMIN_PASS) {
    throw new Error(
      'WP_ADMIN_USER and WP_ADMIN_PASS must be set. In CI they come from repo ' +
        'secrets via `secrets: inherit`; locally `npm run wp:up` prints them.'
    );
  }
});

// The harness serves WordPress from PHP's built-in server, which handles one
// request at a time. Signing in lands on the app page, and leaving the app's
// bundle and its REST calls in flight there starves the very next admin request
// until it times out. These specs are about the WordPress side, so the bundle is
// blocked for them; app-page.spec.js is where the app itself is exercised.
test.beforeEach(async ({ page }) => {
  await page.route('**/assets/js/forge-app.js', (route) => route.abort());
});

// Signing in does NOT land on wp-admin: the plugin redirects users to the Forge
// app page, which deliberately renders no admin bar. So the proof of a successful
// login is wp-admin answering afterwards, not anything on the landing page.
async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#adminmenu')).toBeVisible();
}

test('the plugin is installed and active', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  const row = page.locator(
    'tr[data-slug="forge-project-management"], tr#forge-project-management'
  );
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('Forge Project Management');
  // Word-boundary match is load-bearing: WordPress renders an inactive row with
  // class="inactive", and the substring "active" inside "inactive" would match
  // /active/ regardless of activation state. Do not simplify this back.
  await expect(row).toHaveClass(/(^|\s)active(\s|$)/);
});

test('activating the plugin raises no PHP error', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  // A fatal on activation surfaces here as an error notice rather than a crash.
  // Filtered to :visible because WP core renders a hidden, empty .notice-error
  // template in every plugin row's auto-updates column whether or not anything
  // actually failed.
  await expect(page.locator('#message.error, .notice-error:visible')).toHaveCount(0);
  await expect(page.locator('text=Fatal error')).toHaveCount(0);
});

test('signing in lands on the Forge app page, not wp-admin', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');

  // The plugin's login_redirect filter sends users to the app instead of the
  // WordPress backend. Losing that filter would strand every non-admin user on
  // a dashboard they have no reason to see.
  await expect(page).toHaveURL(/\/forge-project-management\/?$/);
  await expect(page.locator('#forge-pm-app')).toHaveCount(1);
});

test('the admin menu and every item screen it links to are reachable', async ({ page }) => {
  // Seven wp-admin page loads on a single-threaded PHP server, each a few
  // seconds. The default 30s budget is about one WordPress screen, not seven.
  test.setTimeout(180_000);
  await signIn(page);
  await page.goto('/wp-admin/admin.php?page=forge-project-management', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('h1')).toContainText('Forge Project Management');

  // The six post types the plugin registers. A type that failed to register
  // renders WordPress's "Invalid post type" screen instead of a list table, so
  // this is the cheapest proof the whole set is live.
  for (const type of [
    'forge_feature',
    'forge_subitem',
    'forge_bug',
    'forge_feedback',
    'forge_release',
    'forge_company_date',
  ]) {
    await page.goto(`/wp-admin/edit.php?post_type=${type}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#wpbody-content')).not.toContainText('Invalid post type');
    await expect(page.locator('table.wp-list-table')).toBeVisible();
  }
});
