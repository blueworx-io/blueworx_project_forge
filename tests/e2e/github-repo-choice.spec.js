import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGitHubStub, removeGitHubStub, githubSays } from './helpers/github.js';

// #681. Work names the repo it belongs in (or "No repo needed") before it
// leaves Documentation Period. A project or a release is never asked.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Repo Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('a feature cannot reach Up Next without a repo answer', async () => {
  test.slow();

  const made = (await (await Forge.makeItem(admin.api, site.id, { title: `Repo ${RUN_ID}`, level: 'feature' })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);

  const approved = await admin.api.post(`/work-items/${item.id}/gate`, { requirement: 'G-DOCUMENTATION-9', value: 'approved', evidence: '' });
  expect(approved.status(), await approved.text()).toBe(200);

  const before = (await admin.api.get(`/work-items/${item.id}`)).item;
  const refused = await admin.api.post(`/work-items/${item.id}/transition`, { to: 'up-next', record_version: before.record_version });

  expect(refused.status(), await refused.text()).toBe(409);
  const unmet = (await refused.json()).unmet;
  expect(unmet.map((row) => row.id)).toEqual(['G-DOCUMENTATION-10']);
  expect(unmet[0].label).toBe('Repo');

  const filed = await admin.api.patch(`/work-items/${item.id}`, { github_repo: 'none', record_version: before.record_version });
  expect(filed.status(), await filed.text()).toBe(200);

  const after = (await admin.api.get(`/work-items/${item.id}`)).item;
  const moved = await admin.api.post(`/work-items/${item.id}/transition`, { to: 'up-next', record_version: after.record_version });

  expect(moved.status(), await moved.text()).toBe(200);
  expect((await moved.json()).item.stage).toBe('up-next');
});

test('a release is never asked', async () => {
  const project = (await (await Forge.makeItem(admin.api, site.id, { title: `Project ${RUN_ID}`, level: 'project' })).json()).item;
  const release = (await (await Forge.makeItem(admin.api, site.id, { title: `Release ${RUN_ID}`, level: 'milestone', parent_id: project.id })).json()).item;

  const detail = await admin.api.get(`/work-items/${release.id}`);
  const rows = Object.values(detail.readiness).flatMap((gate) => gate.all.map((row) => row.id));

  expect(rows).not.toContain('G-DOCUMENTATION-10');
});

test('a client cannot add work with a repo chosen', async ({ browser, baseURL }) => {
  const { client, site: theirSite } = await Forge.makeSite(admin.api, 'Client Repo Co', RUN_ID);
  await Forge.onSupport(admin, theirSite.id, 200);

  const person = await Forge.makePerson(admin.api, client.id, 'client_admin', 'repoclient');
  const theirs = await browser.newContext({ baseURL });
  const page = await theirs.newPage();

  await signIn(page, person.login, Forge.PASSWORD);
  const nonce = await (await theirs.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text();
  await page.close();

  const made = await theirs.request.post('/wp-json/blueworx-forge/v1/work-items', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      client_site_id: theirSite.id,
      title: `Client repo ${RUN_ID}`,
      problem: 'Something needs doing.',
      level: 'feature',
      work_type: 'feature',
      github_repo: 'none',
    },
  });

  // Nothing sets the active-package condition yet, so a client is refused at the door. If that
  // changes, the repo they sent must still not be stored.
  if (200 !== made.status()) {
    expect(made.status(), await made.text()).toBe(403);
    await theirs.close();
    return;
  }


  const stored = (await admin.api.get(`/work-items/${(await made.json()).item.id}`)).item;
  expect(stored.github_repo).toBe('');

  await theirs.close();
});

// The picker on the task's panel: the site's repos first, then the account's
// other repos, then No repo needed. Once the task has its issue the repo is
// fixed and the panel shows the issue instead.
test.describe('the repo picker', () => {
  test.describe.configure({ mode: 'serial' });

  const ALPHA = 'blueworx-io/alpha';
  const BETA = 'blueworx-io/beta';
  const LOCKED = "This task already has a GitHub issue, so its repo can't change. Move the issue in GitHub if it needs to go elsewhere.";
  let pickSite;
  let feature;

  async function openPanel(page) {
    await signIn(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('about:blank');
    await page.goto(`/blueworx-forge/#item=${feature.id}`);
  }

  test.beforeAll(async () => {
    test.setTimeout(600000);
    installGitHubStub();
    ({ site: pickSite } = await Forge.makeSite(admin.api, 'Picker Co', RUN_ID));

    await githubSays(admin.api.request, { down: false, denied: false, hooks_denied: false, reset: true, hooks: {}, repos: [ALPHA, BETA] });
    expect((await admin.api.post('/connections/github', { token: 'good-token-4321' })).ok()).toBe(true);
    expect((await admin.api.put(`/client-sites/${pickSite.id}/github-repos`, { repos: [ALPHA] })).status()).toBe(200);

    const made = (await (await Forge.makeItem(admin.api, pickSite.id, { title: `Picker ${RUN_ID}`, level: 'feature' })).json()).item;
    feature = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);
  });

  test.afterAll(async () => {
    await admin.api.put(`/client-sites/${pickSite.id}/github-repos`, { repos: [] });
    await admin.api.del('/connections/github');
    removeGitHubStub();
  });

  test('the picker lists the site\'s repos first', async ({ page }) => {
    test.slow();
    await openPanel(page);

    const picker = page.getByTestId('bwx-item-repo');
    await expect(picker).toBeVisible({ timeout: 60000 });
    await expect(picker.locator('option')).toHaveText([ALPHA, BETA, 'No repo needed']);
  });

  test('choosing a repo saves it', async ({ page }) => {
    test.slow();
    await openPanel(page);

    await page.getByTestId('bwx-item-repo').selectOption(BETA);
    await expect.poll(async () => (await admin.api.get(`/work-items/${feature.id}`)).item.github_repo, { timeout: 30000 }).toBe(BETA);
  });

  test('without GitHub only No repo needed is offered', async ({ page }) => {
    test.slow();
    expect((await admin.api.del('/connections/github')).ok()).toBe(true);
    await openPanel(page);

    const picker = page.getByTestId('bwx-item-repo');
    await expect(picker).toBeVisible({ timeout: 60000 });
    await expect(picker.locator('option')).toHaveText(['No repo needed']);
  });

  test('a linked task shows its issue and its repo cannot change', async ({ page }) => {
    test.slow();
    expect((await admin.api.post('/connections/github', { token: 'good-token-4321' })).ok()).toBe(true);

    const linked = await admin.api.request.post('/wp-json/bwx-forge-test/v1/github/link', { data: { item_id: feature.id, repo: ALPHA, number: 12, issue_id: 7000 + Math.floor(Math.random() * 1e6) } });
    expect((await linked.json()).added).toBe(true);

    await openPanel(page);

    const issue = page.getByTestId('bwx-item-issue');
    await expect(issue).toHaveText(`${ALPHA}#12`, { timeout: 60000 });
    await expect(issue).toHaveAttribute('href', `https://github.com/${ALPHA}/issues/12`);
    await expect(issue).toHaveAttribute('target', '_blank');
    await expect(page.getByTestId('bwx-item-repo')).toHaveCount(0);

    const current = (await admin.api.get(`/work-items/${feature.id}`)).item;
    const refused = await admin.api.patch(`/work-items/${feature.id}`, { github_repo: BETA, record_version: current.record_version });
    expect(refused.status(), await refused.text()).toBe(409);
    const body = await refused.json();
    expect(body.code).toBe('bwx_forge_github_repo_locked');
    expect(body.message).toBe(LOCKED);
  });
});
