import { test, expect } from '@playwright/test';
import { signedIn, makeSite } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGitHubStub, removeGitHubStub, githubSays, githubState, githubSecret } from './helpers/github.js';

// #680. An administrator links GitHub repositories to a client's site from the
// client's page; a repository belongs to one site only. Linking a repo adds
// Forge's webhook to it, and unlinking takes it off. GitHub is the stand-in
// from tests/support/github-stub.php.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `${Date.now()}${Math.floor(Math.random() * 1e4)}`;
const ALPHA = 'blueworx-io/alpha';
const BETA = 'blueworx-io/beta';
const GAMMA = 'blueworx-io/gamma';

test.describe.configure({ mode: 'serial' });

let admin;
let one;
let two;

async function hooksOn(repo) {
  return (await githubState(admin.api.request)).hooks[repo] ?? [];
}

async function put(siteId, repos) {
  return admin.api.put(`/client-sites/${siteId}/github-repos`, { repos });
}

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGitHubStub();

  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  one = await makeSite(admin.api, 'Repos A', RUN);
  two = await makeSite(admin.api, 'Repos B', RUN);

  await githubSays(admin.api.request, { down: false, denied: false, hooks_denied: false, reset: true, hooks: {}, repos: [ALPHA, BETA, GAMMA] });
  const connected = await admin.api.post('/connections/github', { token: 'good-token-4321' });
  expect(connected.ok()).toBe(true);
});

test.afterAll(async () => {
  await githubSays(admin.api.request, { denied: false, hooks_denied: false });
  await put(one.site.id, []);
  await put(two.site.id, []);
  await admin?.api.del('/connections/github');
  await admin?.context.close();
  removeGitHubStub();
});

test('a site keeps the repos chosen for it, and each gets one webhook', async ({ page }) => {
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

  const hooks = await hooksOn(ALPHA);
  expect(hooks).toHaveLength(1);
  expect(hooks[0].name).toBe('web');
  expect(hooks[0].active).toBe(true);
  expect(hooks[0].events).toEqual(['issues']);
  expect(hooks[0].config.content_type).toBe('json');
  expect(hooks[0].config.insecure_ssl).toBe('0');
  expect(hooks[0].config.url).toMatch(/blueworx-forge\/v1\/github\/webhook$/);
  expect(hooks[0].config.secret).toBe(await githubSecret(admin.api.request));

  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#screen=client&client=${one.client.id}`);
  await expect(page.getByTestId(`bwx-site-repo-${one.site.id}-${ALPHA}`)).toBeChecked({ timeout: 60000 });
  await expect(page.getByTestId(`bwx-site-repo-${one.site.id}-${BETA}`)).not.toBeChecked();
});

test('a repo linked elsewhere is refused by name', async ({ page }) => {
  test.slow();

  const refused = await put(two.site.id, [ALPHA]);
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
  await expect(page.getByTestId('bwx-site-repos')).toContainText(`linked to ${one.site.name}`);
  const listed = (await admin.api.get('/github/repos')).repos.find((row) => row.repo === ALPHA);
  expect(listed).toEqual({ repo: ALPHA, site_id: one.site.id, site_name: one.site.name });
  await expect(page.getByTestId(`bwx-site-repo-${two.site.id}-${BETA}`)).toBeEnabled();

  // Nothing was linked to the second site, and the hook was not doubled.
  expect((await admin.api.get(`/client-sites/${two.site.id}/github-repos`)).repos).toEqual([]);
  expect(await hooksOn(ALPHA)).toHaveLength(1);
});

test('a repo that already has Forge\'s webhook reuses it', async () => {
  const url = (await hooksOn(ALPHA))[0].config.url;
  await githubSays(admin.api.request, {
    hooks: {
      ...(await githubState(admin.api.request)).hooks,
      [BETA]: [{ id: 555, name: 'web', active: false, events: ['push'], config: { url, content_type: 'form', secret: 'old' } }],
    },
  });

  const linked = await put(two.site.id, [BETA]);
  expect(linked.status(), await linked.text()).toBe(200);

  const hooks = await hooksOn(BETA);
  expect(hooks).toHaveLength(1);
  expect(hooks[0].id).toBe(555);
  expect(hooks[0].active).toBe(true);
  expect(hooks[0].events).toEqual(['issues']);
  expect(hooks[0].config.content_type).toBe('json');
  expect(hooks[0].config.secret).toBe(await githubSecret(admin.api.request));
});

test('unlinking a repo takes its webhook off', async () => {
  const unlinked = await put(two.site.id, []);
  expect(unlinked.status()).toBe(200);

  expect(await hooksOn(BETA)).toEqual([]);
  expect((await githubState(admin.api.request)).calls.some((call) => call.method === 'DELETE' && call.path === `/repos/${BETA}/hooks/555`)).toBe(true);
});

test('a repo GitHub won\'t add the webhook to is not linked, and says why', async () => {
  await githubSays(admin.api.request, { hooks_denied: true });

  const refused = await put(two.site.id, [GAMMA]);
  expect(refused.status()).toBe(502);
  expect((await refused.json()).message).toBe(`GitHub didn't let Forge add its webhook to ${GAMMA}, so it wasn't linked. Check the token can manage webhooks.`);
  expect((await admin.api.get(`/client-sites/${two.site.id}/github-repos`)).repos).toEqual([]);

  // Shown on the GitHub row too.
  const github = (await admin.api.get('/connections')).services.find((service) => service.id === 'github');
  expect(github.error).toContain(`webhook to ${GAMMA}`);

  await githubSays(admin.api.request, { hooks_denied: false });
});

test('connecting again signs every linked repo\'s webhook with the new secret', async () => {
  const before = await githubSecret(admin.api.request);

  expect((await admin.api.post('/connections/github', { token: 'good-token-5555' })).ok()).toBe(true);

  const after = await githubSecret(admin.api.request);
  expect(after).not.toBe(before);

  const hooks = await hooksOn(ALPHA);
  expect(hooks).toHaveLength(1);
  expect(hooks[0].config.secret).toBe(after);
});

test('a token GitHub no longer accepts is shown on the client page', async ({ page }) => {
  test.slow();

  // Connecting again empties the kept repo list, so the page asks GitHub.
  expect((await admin.api.post('/connections/github', { token: 'good-token-4321' })).ok()).toBe(true);
  await githubSays(admin.api.request, { denied: true });

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#screen=client&client=${one.client.id}`);

  await expect(page.getByTestId('bwx-site-repos-error')).toHaveText('GitHub no longer accepts the token. Reconnect it on the Connections page.', { timeout: 60000 });

  await githubSays(admin.api.request, { denied: false });
});
