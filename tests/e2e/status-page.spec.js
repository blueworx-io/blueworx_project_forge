import { test, expect } from '@playwright/test';

// The Status screen is the one page that echoes diagnostic values straight into
// HTML, and its "Copy Status Report" button embeds the whole report as JSON
// inside an onclick attribute. Escaping either wrongly breaks the page quietly:
// the report copies as truncated garbage, or the attribute ends early and the
// button silently does nothing. These specs pin both.

const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

test.beforeAll(() => {
  if (!ADMIN_USER || !ADMIN_PASS) {
    throw new Error('WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

test.beforeEach(async ({ page }) => {
  // See activation.spec.js: the app bundle would starve the next admin request.
  await page.route('**/assets/js/forge-app.js', (route) => route.abort());
});

test('the status screen renders its diagnostics', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  const response = await page.goto('/wp-admin/admin.php?page=forge-pm-status', {
    waitUntil: 'domcontentloaded',
  });
  expect(response?.status()).toBe(200);

  await expect(page.locator('h1')).toContainText('Status');
  await expect(page.locator('#wpbody-content')).toContainText('Version');
  // Not a "Fatal error" text check: the page's own copy says "No PHP fatal
  // errors captured", and Playwright's text= matching is case-insensitive.
  await expect(page.locator('.error-message, .php-error')).toHaveCount(0);

  // The copy button's handler holds the report as a JSON literal. Reading it back
  // through the DOM proves the attribute survived escaping intact — if it had not,
  // the handler would be cut off mid-string and never parse.
  const handler = await page.locator('#forge-copy-btn').getAttribute('onclick');
  expect(handler).toContain('navigator.clipboard.writeText');

  const literal = handler.match(/var text = ([\s\S]*?);\s*navigator/);
  expect(literal, 'the report literal is missing from the handler').not.toBeNull();
  expect(() => JSON.parse(literal[1])).not.toThrow();
  expect(JSON.parse(literal[1])).toContain('Forge');
});
