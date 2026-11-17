import { test, expect } from '@playwright/test';
import { signedIn, makeSite } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGitHubStub, removeGitHubStub, githubSays } from './helpers/github.js';

// #680. An administrator links GitHub repositories to a client's site from the
// client's page; a repository belongs to one site only. GitHub is the
// stand-in from tests/support/github-stub.php.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `${Date.now()}${Math.floor(Math.random() * 1e4)}`;
const ALPHA = 'blueworx-io/alpha';
const BETA = 'blueworx-io/beta';

test.describe.configure({ mode: 'serial' });

let admin;
let one;
let two;

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGitHubStub();

  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  one = await makeSite(admin.api, 'Repos A', RUN);
  two = await makeSite(admin.api, 'Repos B', RUN);

  await githubSays(admin.api.request, { down: false, denied: false, reset: true, repos: [ALPHA, BETA] });
  const connected = await admin.api.post('/connections/github', { token: 'good-token-4321', org: 'blueworx-io', secret: 'whsec-test' });
  expect(connected.ok()).toBe(true);
});

test.afterAll(async () => {
  await admin?.api.put(`/client-sites/${one.site.id}/github-repos`, { repos: [] });
  await admin?.api.put(`/client-sites/${two.site.id}/github-repos`, { repos: [] });
  await admin?.api.del('/connections/github');
  await admin?.context.close();
  removeGitHubStub();
});

test('a site keeps the repos chosen for it', async ({ page }) => {
  test.slow();

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#screen=client&client=${one.client.id}`);

  const box = page.getByTestId(`bwx-site-repo-${one.site.id}-${ALPHA}`);
  await expect(box).toBeVisible({ timeout: 60000 });
  await expect(box).not.toBeChecked();
  await box.check();
  await expect(box).toBeChecked();
  await expect.poll(async () => (await admin.api.get(`/client-sites/${one.site.id}/github-repos`)).repos, { timeout: 30000 }).toEqual([ALPHA]);

  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#screen=client&client=${one.client.id}`);
  await expect(page.getByTestId(`bwx-site-repo-${one.site.id}-${ALPHA}`)).toBeChecked({ timeout: 60000 });
  await expect(page.getByTestId(`bwx-site-repo-${one.site.id}-${BETA}`)).not.toBeChecked();
});

test('a repo linked elsewhere is refused by name', async ({ page }) => {
  test.slow();

  const refused = await admin.api.put(`/client-sites/${two.site.id}/github-repos`, { repos: [ALPHA] });
  expect(refused.status()).toBe(409);
  const body = await refused.json();
  expect(body.message).toContain(one.site.name);
  expect(body.message).toContain(ALPHA);

  // The other site's page shows it taken, and cannot tick it.
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#screen=client&client=${two.client.id}`);
  const box = page.getByTestId(`bwx-site-repo-${two.site.id}-${ALPHA}`);
  await expect(box).toBeDisabled({ timeout: 60000 });
  await expect(page.getByTestId(`bwx-site-repo-${two.site.id}-${BETA}`)).toBeEnabled();

  // Nothing was linked to the second site.
  expect((await admin.api.get(`/client-sites/${two.site.id}/github-repos`)).repos).toEqual([]);
});
