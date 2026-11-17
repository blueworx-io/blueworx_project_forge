import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGitHubStub, removeGitHubStub, githubSays, githubCalls, runGitHubJobs } from './helpers/github.js';

// #681. Choosing a repo for a task makes its GitHub issue, once, after the
// save has answered. A GitHub that is down never stops the save: the issue is
// made when GitHub is back.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const ALPHA = 'blueworx-io/alpha';
const BETA = 'blueworx-io/beta';

let admin;
let site;
let first;

test.describe.configure({ mode: 'serial' });

/** A fresh feature on this spec's site, in Documentation period, where the repo is asked. */
async function feature(title) {
  const made = (await (await Forge.makeItem(admin.api, site.id, { title: `${title} ${RUN_ID}`, level: 'feature' })).json()).item;
  return Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);
}

/** Sets an item's repo, as the picker does. */
async function chooseRepo(id, repo) {
  const current = (await admin.api.get(`/work-items/${id}`)).item;
  return admin.api.patch(`/work-items/${id}`, { github_repo: repo, record_version: current.record_version });
}

/** The calls that made an issue for this item: its marker is in the body. */
async function createsFor(id) {
  return (await githubCalls(admin.api.request)).filter(
    (call) => 'POST' === call.method && /^\/repos\/[^/]+\/[^/]+\/issues$/.test(call.path) && call.body.includes(`forge:${id}`),
  );
}

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGitHubStub();
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Issue Co', RUN_ID));

  await githubSays(admin.api.request, { down: false, denied: false, hooks_denied: false, issues_refused: false, reset: true, hooks: {}, repos: [ALPHA, BETA] });
  expect((await admin.api.post('/connections/github', { token: 'good-token-4321' })).ok()).toBe(true);
  expect((await admin.api.put(`/client-sites/${site.id}/github-repos`, { repos: [ALPHA] })).status()).toBe(200);
});

test.afterAll(async () => {
  await githubSays(admin.api.request, { down: false });
  await admin.api.put(`/client-sites/${site.id}/github-repos`, { repos: [] });
  await admin.api.del('/connections/github');
  await admin?.context.close();
  removeGitHubStub();
});

test('choosing a repo creates one issue that links back', async ({ page }) => {
  test.slow();
  first = await feature('Make an issue');

  const saved = await chooseRepo(first.id, ALPHA);
  expect(saved.status(), await saved.text()).toBe(200);

  // Made after the save answered, in the same request's last breath.
  await expect.poll(async () => (await createsFor(first.id)).length, { timeout: 30000 }).toBe(1);

  const [made] = await createsFor(first.id);
  expect(made.path).toBe(`/repos/${ALPHA}/issues`);

  const sent = JSON.parse(made.body);
  expect(sent.title).toBe(first.title);
  expect(sent.body).toContain('## Description');
  expect(sent.body).toContain('Something needs doing.');
  expect(sent.body).toContain(`Tracked in Forge: `);
  expect(sent.body).toContain(`#item=${first.id}`);
  expect(sent.body.trim().endsWith(`<!-- forge:${first.id} -->`)).toBe(true);

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#item=${first.id}`);

  const issue = page.getByTestId('bwx-item-issue');
  await expect(issue).toHaveText(`${ALPHA}#1`, { timeout: 60000 });
  await expect(issue).toHaveAttribute('href', `https://github.com/${ALPHA}/issues/1`);
});

test('saving the repo again makes no second issue', async () => {
  test.slow();

  const again = await chooseRepo(first.id, ALPHA);
  expect(again.status(), await again.text()).toBe(200);

  const current = (await admin.api.get(`/work-items/${first.id}`)).item;
  const renamed = await admin.api.patch(`/work-items/${first.id}`, { title: `Renamed ${RUN_ID}`, record_version: current.record_version });
  expect(renamed.status(), await renamed.text()).toBe(200);

  await runGitHubJobs(admin.api.request);
  expect(await createsFor(first.id)).toHaveLength(1);
});

test('No repo needed makes no issue', async () => {
  test.slow();
  const item = await feature('No repo');

  const saved = await chooseRepo(item.id, 'none');
  expect(saved.status(), await saved.text()).toBe(200);

  await runGitHubJobs(admin.api.request);
  expect(await createsFor(item.id)).toHaveLength(0);
  expect((await admin.api.get(`/work-items/${item.id}`)).github.link).toBeNull();
});

test('a GitHub that is down still saves, and catches up', async ({ page }) => {
  test.slow();
  const item = await feature('GitHub down');

  await githubSays(admin.api.request, { down: true });

  const saved = await chooseRepo(item.id, ALPHA);
  expect(saved.status(), await saved.text()).toBe(200);
  expect((await saved.json()).item.github_repo).toBe(ALPHA);
  expect((await admin.api.get(`/work-items/${item.id}`)).github.link).toBeNull();

  // The try made after the save failed, and the GitHub row says so.
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('about:blank');
  await page.goto('/blueworx-forge/#screen=settings');
  await expect(page.getByTestId('bwx-connection-github-outbox')).toContainText('waiting to be sent to GitHub', { timeout: 60000 });
  await expect(page.getByTestId('bwx-connection-github-outbox')).toContainText('GitHub could not be reached.');

  await githubSays(admin.api.request, { down: false });
  await runGitHubJobs(admin.api.request);

  expect(await createsFor(item.id)).toHaveLength(1);
  const link = (await admin.api.get(`/work-items/${item.id}`)).github.link;
  expect(link.repo).toBe(ALPHA);
  expect(link.number).toBeGreaterThan(1);
});
