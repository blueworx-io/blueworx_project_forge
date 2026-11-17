import { test, expect } from '@playwright/test';
import { signedIn } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGitHubStub, removeGitHubStub, githubSays, githubSecret } from './helpers/github.js';

// #680. An administrator connects GitHub on the Connections page with a token
// only: Forge makes the webhook secret itself. GitHub is the stand-in from
// tests/support/github-stub.php.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const TOKEN = 'good-token-9876';

test.describe.configure({ mode: 'serial' });

/** Opens one screen of the app afresh: the app reads its hash once, as it loads. */
async function openScreen(page, screen) {
  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#screen=${screen}`);
}

let admin;

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGitHubStub();

  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  await admin.api.del('/connections/github');
  await githubSays(admin.api.request, { down: false, denied: false, hooks_denied: false, reset: true, repos: ['blueworx-io/site-a'] });
});

test.afterAll(async () => {
  await githubSays(admin.api.request, { denied: false });
  await admin?.api.del('/connections/github');
  await admin?.context.close();
  removeGitHubStub();
});

async function fillForm(page, token) {
  await page.getByTestId('bwx-github-token').fill(token);
  await page.getByTestId('bwx-github-save').click();
}

test('a refused token saves nothing', async ({ page }) => {
  test.slow();

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'settings');

  const row = page.getByTestId('bwx-connection-github');
  await expect(row).toHaveAttribute('data-connected', 'no', { timeout: 60000 });

  await page.getByTestId('bwx-connection-github-connect').click();
  // The token is all that is asked for.
  await expect(page.getByTestId('bwx-github-form').locator('input')).toHaveCount(1);
  await expect(page.getByTestId('bwx-github-form')).not.toContainText('organisation');
  await fillForm(page, 'bad-token');
  await expect(page.getByTestId('bwx-github-notice')).toContainText('GitHub did not accept that token', { timeout: 60000 });
  await expect(row).toHaveAttribute('data-connected', 'no');
});

test('connects with a good token and shows it only by its last four', async ({ page }) => {
  test.slow();

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'settings');

  const row = page.getByTestId('bwx-connection-github');
  await expect(row).toHaveAttribute('data-connected', 'no', { timeout: 60000 });

  await page.getByTestId('bwx-connection-github-connect').click();
  await fillForm(page, TOKEN);
  await expect(row).toHaveAttribute('data-connected', 'yes', { timeout: 60000 });
  await expect(row).toContainText('Connected');
  await expect(row).toContainText('••••9876');

  // Forge made a long secret of its own, and it never reaches the browser.
  const secret = await githubSecret(admin.api.request);
  expect(secret).toMatch(/^[A-Za-z0-9]{40}$/);

  const source = await page.content();
  expect(source).not.toContain(TOKEN);
  expect(source).not.toContain(secret);

  const answer = await admin.api.request.get('/wp-json/blueworx-forge/v1/connections', { headers: admin.api.headers });
  const text = await answer.text();
  expect(text).not.toContain(TOKEN);
  expect(text).not.toContain(secret);

  const repos = await admin.api.get('/github/repos');
  expect(repos.connected).toBe(true);
  expect(repos.repos).toEqual([{ repo: 'blueworx-io/site-a', site_id: '', site_name: '' }]);
});

test('a token GitHub stops accepting shows on the GitHub row', async ({ page }) => {
  test.slow();

  // Connecting again empties the kept repo list, so the next read asks GitHub.
  expect((await admin.api.post('/connections/github', { token: TOKEN })).ok()).toBe(true);
  await githubSays(admin.api.request, { denied: true });
  const listed =await admin.api.request.get('/wp-json/blueworx-forge/v1/github/repos', { headers: admin.api.headers });
  expect(listed.status()).toBe(422);
  expect((await listed.json()).message).toBe('GitHub no longer accepts the token. Reconnect it on the Connections page.');
  await githubSays(admin.api.request, { denied: false });

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'settings');

  const problem = page.getByTestId('bwx-connection-github-problem');
  await expect(problem).toContainText('GitHub no longer accepts the token', { timeout: 60000 });
});

test('disconnecting forgets the token', async ({ page }) => {
  test.slow();

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'settings');

  const row = page.getByTestId('bwx-connection-github');
  await expect(row).toHaveAttribute('data-connected', 'yes', { timeout: 60000 });

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('bwx-connection-github-disconnect').click();
  await expect(row).toHaveAttribute('data-connected', 'no', { timeout: 60000 });
  await expect(row).toContainText('Not connected');
  await expect(page.getByTestId('bwx-connection-github-problem')).toHaveCount(0);
});

test('a good connect clears the last problem', async () => {
  test.slow();

  await admin.api.post('/connections/github', { token: TOKEN });
  await githubSays(admin.api.request, { denied: true });
  await admin.api.request.get('/wp-json/blueworx-forge/v1/github/repos', { headers: admin.api.headers });
  await githubSays(admin.api.request, { denied: false });

  const github = async () => (await admin.api.get('/connections')).services.find((one) => one.id === 'github');
  expect((await github()).error).toContain('no longer accepts the token');

  const again = await admin.api.post('/connections/github', { token: TOKEN });
  expect(again.ok()).toBe(true);
  expect((await github()).error).toBe('');
});
