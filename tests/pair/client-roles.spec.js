import { test, expect } from '@playwright/test';
import { signedIn, requireEnvironment, connectedPair, CLIENT_URL } from './helpers/pair.js';

// A Forge: Manager runs the work on the client site without being able to
// break the site. Everything Forge draws opens for them; the Connection —
// the one screen about the arrangement rather than the work — does not.

requireEnvironment();

const RUN = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
let userCount = 0;
const PASSWORD = 'forge-test-pw-4471';

/** A user on the client site holding one role. */
async function user(admin, role) {
  const login = `${role.replace(/_/g, '')}${RUN}${userCount++}`;
  const made = await admin.context.request.post('/wp-json/wp/v2/users', {
    headers: admin.headers,
    data: { username: login, email: `${login}@example.test`, password: PASSWORD, roles: [role] },
  });
  expect(made.status(), await made.text()).toBe(201);
  return login;
}

/** A user on the client site holding the Forge: Manager role. */
const manager = (admin) => user(admin, 'forge_manager');

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
    // A connected site, so a request the manager sends has somewhere to go —
    // an unconnected site answers every send with a polite no, and a test
    // that only checked the status would call that a pass.
    const pair = await connectedPair(browser, 'Manager', RUN);
    const admin = pair.clientSite;
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
      data: { type: 'idea', title: `A manager can write ${RUN}`, description: 'Sent by a Forge: Manager in the pair suite.' },
    });
    expect(submitted.status(), await submitted.text()).toBe(200);
    const body = await submitted.json();
    expect(body.ok, JSON.stringify(body)).toBe(true);
    expect(body.result).toBe('sent');

    const connection = await context.request.get('/wp-json/blueworx-forge-client/v1/connection', {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(connection.status()).toBe(403);

    await context.close();
    await pair.close();
  });
});

test.describe('somebody signed in without Forge access', () => {
  test('is told no on the workspace page, not sent round in circles', async ({ browser }) => {
    const admin = await signedIn(browser, CLIENT_URL);
    const login = await user(admin, 'subscriber');
    const { context, page } = await signedInAs(browser, login);

    // Before this, the page sent them to sign in — which they already had —
    // so they were shown a sign-in form that could only bring them back here.
    const answer = await page.goto('/forge/');
    expect(answer.status()).toBe(403);
    await expect(page).toHaveURL(/\/forge\/?$/);
    await expect(page.getByTestId('bwx-client-app')).toHaveCount(0);
    await expect(page.locator('body')).toContainText('Forge: Manager');

    await context.close();
    await admin.context.close();
  });
});
