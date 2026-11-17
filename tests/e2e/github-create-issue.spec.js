import { test, expect, request as pwRequest } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGitHubStub, removeGitHubStub, githubSays, githubCalls, githubState, githubSecret, sendHook, runGitHubJobs, issueJob } from './helpers/github.js';

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

/**
 * The issues GitHub actually made for this item. A call GitHub refused is in
 * the call log too, so this is what "one issue" is measured by.
 */
async function issuesFor(id) {
  const made = (await githubState(admin.api.request)).issues ?? {};
  return Object.values(made).flat().filter((issue) => issue.body.includes(`forge:${id}`));
}

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGitHubStub();
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Issue Co', RUN_ID));

  await githubSays(admin.api.request, { down: false, denied: false, hooks_denied: false, issues_refused: false, lose_create_answer: false, reset: true, hooks: {}, repos: [ALPHA, BETA] });
  expect((await admin.api.post('/connections/github', { token: 'good-token-4321' })).ok()).toBe(true);
  expect((await admin.api.put(`/client-sites/${site.id}/github-repos`, { repos: [ALPHA] })).status()).toBe(200);
});

test.afterAll(async () => {
  await githubSays(admin.api.request, { down: false, denied: false, issues_refused: false, lose_create_answer: false });
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

  // Even the first try looks for an issue already made, by marker alone.
  const looked = (await githubCalls(admin.api.request)).filter((call) => 'GET' === call.method && call.path.startsWith(`/repos/${ALPHA}/issues?`));
  expect(looked.length).toBeGreaterThan(0);
  expect(looked.at(-1).path).toContain('since=');
  expect(looked.at(-1).path).not.toContain('creator=');

  const sent = JSON.parse(made.body);
  expect(sent.title).toBe(first.title);
  expect(sent.body).toContain('## Description');
  expect(sent.body).toContain('Something needs doing.');
  expect(sent.body).toContain(`Tracked in Forge: `);
  expect(sent.body).toContain(`#item=${first.id}`);
  expect(sent.body.trim().endsWith(`<!-- forge:${first.id} -->`)).toBe(true);

  // The number GitHub gave it. Not assumed to be 1: the test site is reused,
  // and anything a reconnect sends again takes numbers first.
  const link = (await admin.api.get(`/work-items/${first.id}`)).github.link;
  expect(link.repo).toBe(ALPHA);

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#item=${first.id}`);

  const issue = page.getByTestId('bwx-item-issue');
  await expect(issue).toHaveText(`${ALPHA}#${link.number}`, { timeout: 60000 });
  await expect(issue).toHaveAttribute('href', `https://github.com/${ALPHA}/issues/${link.number}`);
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

  await githubSays(admin.api.request, { down: false, denied: false, issues_refused: false, lose_create_answer: false });
  await runGitHubJobs(admin.api.request);

  expect(await createsFor(item.id)).toHaveLength(1);
  const link = (await admin.api.get(`/work-items/${item.id}`)).github.link;
  expect(link.repo).toBe(ALPHA);
  expect(link.number).toBeGreaterThan(1);
});

/** The item's link, once its issue is made. */
async function linkOf(id) {
  return (await admin.api.get(`/work-items/${id}`)).github.link;
}

/** Connects again with a good token, as an administrator would. */
async function reconnect() {
  expect((await admin.api.post('/connections/github', { token: 'good-token-4321' })).ok()).toBe(true);
}

test('a token that runs out uses up no tries, and the change goes after reconnecting', async () => {
  test.slow();
  const item = await feature('Token runs out');

  await githubSays(admin.api.request, { denied: true });
  const saved = await chooseRepo(item.id, ALPHA);
  expect(saved.status(), await saved.text()).toBe(200);

  for (let run = 0; run < 9; run += 1) {
    await runGitHubJobs(admin.api.request);
  }

  const job = await issueJob(admin.api.request, item.id);
  expect(job.status).toBe('pending');
  expect(Number(job.attempts)).toBe(0);
  expect(job.last_error).toContain('token');

  await githubSays(admin.api.request, { denied: false });
  await reconnect();

  // Run once the reconnect has answered.
  await expect.poll(async () => (await linkOf(item.id))?.repo ?? null, { timeout: 30000 }).toBe(ALPHA);
  expect(await issuesFor(item.id)).toHaveLength(1);
});

test('a change that gave up goes again after reconnecting', async () => {
  test.slow();
  const item = await feature('Gave up');

  await githubSays(admin.api.request, { issues_refused: true });
  const saved = await chooseRepo(item.id, ALPHA);
  expect(saved.status(), await saved.text()).toBe(200);

  for (let run = 0; run < 10 && 'failed' !== (await issueJob(admin.api.request, item.id)).status; run += 1) {
    await runGitHubJobs(admin.api.request);
  }

  expect((await issueJob(admin.api.request, item.id)).status).toBe('failed');
  const row = (await admin.api.get('/connections')).services.find((service) => 'github' === service.id);
  expect(row.outbox).toContain("couldn't be sent to GitHub");
  expect(row.error).toContain("couldn't be sent to GitHub");

  await githubSays(admin.api.request, { issues_refused: false });
  await reconnect();

  await expect.poll(async () => (await linkOf(item.id))?.repo ?? null, { timeout: 30000 }).toBe(ALPHA);
  expect(await issuesFor(item.id)).toHaveLength(1);
});

test('an answer GitHub lost makes no second issue', async () => {
  test.slow();
  const item = await feature('Lost answer');

  await githubSays(admin.api.request, { lose_create_answer: true });
  const saved = await chooseRepo(item.id, ALPHA);
  expect(saved.status(), await saved.text()).toBe(200);

  // GitHub made it, but Forge never heard.
  await expect.poll(async () => (await createsFor(item.id)).length, { timeout: 30000 }).toBe(1);
  expect(await linkOf(item.id)).toBeNull();
  expect(Number((await issueJob(admin.api.request, item.id)).attempts)).toBe(1);

  await githubSays(admin.api.request, { lose_create_answer: false });
  await runGitHubJobs(admin.api.request);
  await runGitHubJobs(admin.api.request);

  expect(await createsFor(item.id)).toHaveLength(1);
  expect(await issuesFor(item.id)).toHaveLength(1);
  const link = await linkOf(item.id);
  expect(link.repo).toBe(ALPHA);
  expect((await issueJob(admin.api.request, item.id)).status).toBe('done');
});

test('work added with its repo already chosen gets its issue', async () => {
  test.slow();
  const made = await Forge.makeItem(admin.api, site.id, { title: `Added with a repo ${RUN_ID}`, level: 'feature', github_repo: ALPHA });
  expect(made.status(), await made.text()).toBe(200);
  const item = (await made.json()).item;
  expect(item.github_repo).toBe(ALPHA);

  await expect.poll(async () => (await linkOf(item.id))?.repo ?? null, { timeout: 30000 }).toBe(ALPHA);
  expect(await createsFor(item.id)).toHaveLength(1);
});

test("GitHub's message about our own issue links it, never a new task", async () => {
  test.slow();
  const item = await feature('Our own issue');
  const bare = await pwRequest.newContext({ baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892' });

  try {
    const secret = await githubSecret(admin.api.request);
    const id = Date.now() * 100 + Math.floor(Math.random() * 100);
    const before = (await admin.api.get(`/work-items?client_site_id=${site.id}`)).items.length;

    // GitHub's message beats Forge's own create call: the repo is chosen
    // while GitHub cannot be reached, so no issue has been made yet.
    await githubSays(admin.api.request, { down: true });
    expect((await chooseRepo(item.id, ALPHA)).status()).toBe(200);
    await githubSays(admin.api.request, { down: false });

    const sent = await sendHook(
      bare,
      'issues',
      {
        action: 'opened',
        issue: { id, number: 4242, title: item.title, body: `Something.

<!-- forge:${item.id} -->`, labels: [], user: { login: 'blueworx-io' } },
        repository: { full_name: ALPHA },
      },
      { secret },
    );
    expect(sent.status(), await sent.text()).toBe(200);
    expect((await sent.json()).outcome).toBe('linked');

    const link = await linkOf(item.id);
    expect(link.repo).toBe(ALPHA);
    expect(link.number).toBe(4242);

    // The create job now finds the link and makes nothing.
    await runGitHubJobs(admin.api.request);
    expect(await createsFor(item.id)).toHaveLength(0);
    expect(await issuesFor(item.id)).toHaveLength(0);
    expect((await linkOf(item.id)).number).toBe(4242);

    // Sent again, it is a repeat; and no new task came in.
    const again = await sendHook(bare, 'issues', { action: 'opened', issue: { id, number: 4242, title: item.title, body: `<!-- forge:${item.id} -->`, labels: [] }, repository: { full_name: ALPHA } }, { secret });
    expect((await again.json()).outcome).toBe('duplicate');
    expect((await admin.api.get(`/work-items?client_site_id=${site.id}`)).items).toHaveLength(before);
  } finally {
    await bare.dispose();
  }
});

test('a Forge marker from another account, or in another repo, links nothing', async () => {
  test.slow();
  const item = await feature('Copied marker');
  const bare = await pwRequest.newContext({ baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892' });

  try {
    await githubSays(admin.api.request, { down: true });
    expect((await chooseRepo(item.id, ALPHA)).status()).toBe(200);
    await githubSays(admin.api.request, { down: false });

    const secret = await githubSecret(admin.api.request);
    const base = Date.now() * 100 + Math.floor(Math.random() * 100);
    const send = (n, login, repo) =>
      sendHook(bare, 'issues', { action: 'opened', issue: { id: base + n, number: 5000 + n, title: `Stranger ${n} ${RUN_ID}`, body: `Hello

<!-- forge:${item.id} -->`, labels: [], user: { login } }, repository: { full_name: repo } }, { secret });

    // A stranger copying the marker is taken in as a new issue, not linked.
    const stranger = await send(1, 'someone-else', ALPHA);
    expect((await stranger.json()).outcome).toBe('created');
    expect(await linkOf(item.id)).toBeNull();

    // Our own account, but not the item's repo: also taken in as new.
    expect((await admin.api.put(`/client-sites/${site.id}/github-repos`, { repos: [ALPHA, BETA] })).status()).toBe(200);
    try {
      const elsewhere = await send(2, 'blueworx-io', BETA);
      expect((await elsewhere.json()).outcome).toBe('created');
      expect(await linkOf(item.id)).toBeNull();
    } finally {
      await admin.api.put(`/client-sites/${site.id}/github-repos`, { repos: [ALPHA] });
    }
  } finally {
    await bare.dispose();
  }
});
