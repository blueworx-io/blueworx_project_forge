import { test, expect } from '@playwright/test';
import { signedIn, requireEnvironment, CLIENT_URL } from './helpers/pair.js';

// A Forge: Manager runs the work on the client site without being able to
// break the site. Everything Forge draws opens for them; the Connection —
// the one screen about the arrangement rather than the work — does not.

requireEnvironment();

const RUN = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
let managerCount = 0;
const PASSWORD = 'forge-test-pw-4471';

/** A user on the client site holding the Forge: Manager role. */
async function manager(admin) {
  const login = `manager${RUN}${managerCount++}`;
  const made = await admin.context.request.post('/wp-json/wp/v2/users', {
    headers: admin.headers,
    data: { username: login, email: `${login}@example.test`, password: PASSWORD, roles: ['forge_manager'] },
  });
  expect(made.status(), await made.text()).toBe(201);
  return login;
}

/** A fresh browser context signed in to the client site as `login`. */
async function signedInAs(browser, login) {
  const context = await browser.newContext({ baseURL: CLIENT_URL });
  const page = await context.newPage();
  await page.goto('/wp-login.php');
  await page
    .waitForFunction(() => document.activeElement?.id === 'user_login', null, { timeout: 5000 })
    .catch(() => {});
  await page.fill('#user_login', login);
  await page.fill('#user_pass', PASSWORD);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
  return { context, page };
}

test.describe('a Forge: Manager on the client site', () => {
  test('sees every Forge screen except the Connection', async ({ browser }) => {
    const admin = await signedIn(browser, CLIENT_URL);
    const login = await manager(admin);
    const { context, page } = await signedInAs(browser, login);

    await page.goto('/wp-admin/admin.php?page=blueworx-forge-client');

    const menu = page.locator('#adminmenu');
    await expect(menu.getByRole('link', { name: 'Overview' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Work Board' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'New Request' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Connection' })).toHaveCount(0);

    // Reaching it by URL is refused, not quietly shown.
    const refused = await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-connection');
    expect(refused.status()).toBe(403);

    await context.close();
    await admin.context.close();
  });

  test('can open the workspace page and read the work, but not the connection', async ({ browser }) => {
    const admin = await signedIn(browser, CLIENT_URL);
    const login = await manager(admin);
    const { context, page } = await signedInAs(browser, login);

    await page.goto('/forge/');
    await expect(page.getByTestId('bwx-client-app')).toBeVisible({ timeout: 30_000 });

    const nonce = await page.evaluate(() => window.bwxForgeClientData?.nonce);
    expect(nonce, 'the page handed the app its REST nonce').toBeTruthy();

    const workspace = await context.request.get('/wp-json/blueworx-forge-client/v1/workspace', {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(workspace.status()).toBe(200);

    const submitted = await context.request.post('/wp-json/blueworx-forge-client/v1/submissions', {
      headers: { 'X-WP-Nonce': nonce },
      data: { type: 'idea', title: `A manager can write ${RUN}` },
    });
    expect(submitted.status(), await submitted.text()).toBeLessThan(300);
    const body = await submitted.json();
    expect(body.result).not.toBe('screenshot');

    const connection = await context.request.get('/wp-json/blueworx-forge-client/v1/connection', {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(connection.status()).toBe(403);

    await context.close();
    await admin.context.close();
  });
});
