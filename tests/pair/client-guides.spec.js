import { test, expect } from '@playwright/test';
import { signedIn, requireEnvironment, CLIENT_URL } from './helpers/pair.js';

// #580. A Forge: Manager cannot open the BlueWorx Guides page, so the same
// guides are in the workspace as a Guides tab.

requireEnvironment();

const RUN = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
const PASSWORD = 'forge-test-pw-4471';

test('a Forge: Manager reads the guides in the workspace', async ({ browser }) => {
  const admin = await signedIn(browser, CLIENT_URL);
  const login = `guides${RUN}`;
  const made = await admin.context.request.post('/wp-json/wp/v2/users', {
    headers: admin.headers,
    data: { username: login, email: `${login}@example.test`, password: PASSWORD, roles: ['forge_manager'] },
  });
  expect(made.status(), await made.text()).toBe(201);

  const context = await browser.newContext({ baseURL: CLIENT_URL });
  const page = await context.newPage();
  await page.goto('/wp-login.php');
  await page.fill('#user_login', login);
  await page.fill('#user_pass', PASSWORD);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto('/forge/');
  const shell = page.getByTestId('bwx-client-app');
  await expect(shell).toBeVisible({ timeout: 30_000 });

  await shell.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Guides' }).click();
  await expect(page).toHaveURL(/#guides$/);
  await expect(shell.getByRole('heading', { level: 1, name: 'Guides' })).toBeVisible();

  const guides = page.getByTestId('bwx-guides');
  for (const title of [
    'Getting around Forge',
    'Asking for something',
    'Following up a request',
    'Reviewing finished work',
    'Answering the studio and commenting',
    'Seeing what is being worked on',
    'Onboarding and launch readiness',
    'Support hours and buying more',
    'Emails you will get',
  ]) {
    await expect(guides.getByRole('heading', { level: 3, name: title })).toBeVisible();
  }

  // A button label is emphasised, not shown with its asterisks.
  const ask = page.getByTestId('bwx-guide-forge-ask-for-something');
  await expect(ask.locator('em', { hasText: 'Send to the studio' }).first()).toBeVisible();
  await expect(ask).not.toContainText('*');

  await context.close();
  await admin.context.close();
});
