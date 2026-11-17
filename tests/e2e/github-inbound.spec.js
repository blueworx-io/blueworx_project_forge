import { test, expect, request as pwRequest } from '@playwright/test';
import { signedIn, makeSite, asClientSite } from './helpers/forge.js';
import { installGitHubStub, removeGitHubStub, githubSays, githubSecret, githubCalls, runGitHubJobs, sendHook } from './helpers/github.js';

// #680. A new issue in a repository linked to a client's site comes into Forge
// as a loose item in Future Idea on that site, once, however often GitHub sends
// it. GitHub is the stand-in from tests/support/github-stub.php; the hook is
// signed as GitHub signs it, with the secret Forge made.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `${Date.now()}${Math.floor(Math.random() * 1e4)}`;
const ALPHA = 'blueworx-io/alpha';
const BETA = 'blueworx-io/beta';

// The test site is reused, and an issue id is linked for good: each run uses
// ids no earlier run did.
const IDS = Date.now() * 100 + Math.floor(Math.random() * 100);

test.describe.configure({ mode: 'serial' });

let admin;
let bare;
let made;
let SECRET;

/** Unlinks repos from whichever site holds them, so this spec can link them its own way. */
async function freeRepos(repos) {
  const rows = (await admin.api.get('/github/repos')).repos ?? [];

  for (const row of rows.filter((one) => repos.includes(one.repo) && one.site_id)) {
    const held = (await admin.api.get(`/client-sites/${row.site_id}/github-repos`)).repos;
    await admin.api.put(`/client-sites/${row.site_id}/github-repos`, { repos: held.filter((repo) => !repos.includes(repo)) });
  }
}

function opened(number, id, { repo = ALPHA, title = 'Fix the footer', body = 'It overlaps.', labels = [] } = {}) {
  return {
    action: 'opened',
    issue: { id, number, title, body, labels, user: { login: 'octo' } },
    repository: { full_name: repo },
  };
}

async function items() {
  return (await admin.api.get(`/work-items?client_site_id=${made.site.id}`)).items;
}

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGitHubStub();

  bare = await pwRequest.newContext({ baseURL });
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  made = await makeSite(admin.api, 'Inbound', RUN);

  await githubSays(admin.api.request, { down: false, denied: false, hooks_denied: false, reset: true, repos: [ALPHA, BETA] });
  const connected = await admin.api.post('/connections/github', { token: 'good-token-7007' });
  expect(connected.ok()).toBe(true);
  SECRET = await githubSecret(admin.api.request);

  await freeRepos([ALPHA, BETA]);
  const linked = await admin.api.put(`/client-sites/${made.site.id}/github-repos`, { repos: [ALPHA] });
  expect(linked.status(), await linked.text()).toBe(200);
});

test.afterAll(async () => {
  await admin?.api.put(`/client-sites/${made.site.id}/github-repos`, { repos: [] });
  await admin?.api.del('/connections/github');
  await admin?.context.close();
  await bare?.dispose();
  removeGitHubStub();
});

const FIRST = `in-${RUN}-1`;

test('a new issue lands in Future Idea, loose', async () => {
  const answer = await sendHook(bare, 'issues', opened(7, IDS + 7), { secret: SECRET, delivery: FIRST });

  expect(answer.status(), await answer.text()).toBe(200);
  expect((await answer.json()).outcome).toBe('created');

  const found = (await items()).filter((one) => one.title === 'Fix the footer');
  expect(found).toHaveLength(1);
  expect(found[0].stage).toBe('future-idea');
  expect(found[0].parent_id).toBe('');
  expect(found[0].github_repo).toBe(ALPHA);
  expect(found[0].problem).toBe('<p>It overlaps.</p>');

  const history = (await admin.api.get(`/work-items/${found[0].id}`)).history;
  const created = history.find((entry) => entry.action === 'created');
  expect(created.via).toBe('github');
  expect(created.detail).toContain(`${ALPHA}#7`);
  expect(created.detail).toContain('octo');
});

test('the same delivery twice makes one item', async () => {
  const answer = await sendHook(bare, 'issues', opened(7, IDS + 7), { secret: SECRET, delivery: FIRST });

  expect(answer.status()).toBe(200);
  expect((await answer.json()).outcome).toBe('duplicate');
  expect((await items()).filter((one) => one.title === 'Fix the footer')).toHaveLength(1);
});

test('the same issue under a new delivery makes one item', async () => {
  const answer = await sendHook(bare, 'issues', opened(7, IDS + 7), { secret: SECRET, delivery: `in-${RUN}-2` });

  expect(answer.status()).toBe(200);
  expect((await answer.json()).outcome).toBe('duplicate');
  expect((await items()).filter((one) => one.title === 'Fix the footer')).toHaveLength(1);
});

test('an issue in an unlinked repo is ignored', async () => {
  const before = (await items()).length;
  const answer = await sendHook(bare, 'issues', opened(8, IDS + 8, { repo: BETA, title: 'Not ours' }), { secret: SECRET });

  expect(answer.status()).toBe(200);
  expect((await answer.json()).outcome).toBe('ignored');
  expect(await items()).toHaveLength(before);
});

test('a very long title and an empty body still come across', async () => {
  const title = `Long ${RUN} ${'x'.repeat(250)}`.slice(0, 250);
  const answer = await sendHook(bare, 'issues', opened(9, IDS + 9, { title, body: null, labels: [{ name: 'bug' }] }), { secret: SECRET });

  expect(answer.status(), await answer.text()).toBe(200);
  expect((await answer.json()).outcome).toBe('created');

  const found = (await items()).filter((one) => one.title.startsWith(`Long ${RUN}`));
  expect(found).toHaveLength(1);
  expect(found[0].title).toHaveLength(191);
  expect(found[0].title).toBe(title.slice(0, 191));
  expect(found[0].problem).toBe('');
  expect(found[0].work_type).toBe('bug');
});

test('an issue Forge cannot take in is a failure, and can be sent again', async () => {
  const before = (await items()).length;
  const delivery = `in-${RUN}-blank`;
  const blank = opened(10, IDS + 10, { title: '   ' });

  const answer = await sendHook(bare, 'issues', blank, { secret: SECRET, delivery });
  expect(answer.status()).toBe(500);
  expect((await answer.json()).message).toContain('send it again');

  // GitHub's Redeliver sends the same delivery id: it is tried again, not
  // waved through as a repeat.
  const again = await sendHook(bare, 'issues', blank, { secret: SECRET, delivery });
  expect(again.status()).toBe(500);
  expect(await items()).toHaveLength(before);

  // The GitHub row on the Connections page says so.
  const github = (await admin.api.get('/connections')).services.find((service) => service.id === 'github');
  expect(github.error).toContain('could not take in a message from GitHub');
});

test('clients see it on their board', async ({ request }) => {
  const site = await asClientSite(admin.api, made.site.id, request);
  const board = await (await site.get('/client/board')).json();
  const mine = (await items()).find((one) => one.title === 'Fix the footer');

  expect(board.items.map((one) => one.id)).toContain(mine.id);
  expect(board.items.find((one) => one.id === mine.id).title).toBe('Fix the footer');
});

test('a huge issue still comes across, cut to fit', async () => {
  const body = Array.from({ length: 6000 }, (_, i) => `Paragraph ${i} & <more> words to fill it out.`).join('\n\n');
  const answer = await sendHook(bare, 'issues', opened(12, IDS + 12, { title: `Huge ${RUN}`, body }), { secret: SECRET });

  expect(answer.status(), await answer.text()).toBe(200);
  expect((await answer.json()).outcome).toBe('created');

  const found = (await items()).find((one) => one.title === `Huge ${RUN}`);
  expect(Buffer.byteLength(found.problem)).toBeLessThan(60000);
  expect(found.problem).toMatch(/The rest is on GitHub\.<\/p>$/);
});

test('deleting an item lets its issue come in again', async () => {
  const title = `Delete me ${RUN}`;
  const issue = opened(11, IDS + 11, { title });
  const first = await sendHook(bare, 'issues', issue, { secret: SECRET });
  expect((await first.json()).outcome).toBe('created');

  const mine = (await items()).find((one) => one.title === title);
  const deleted = await admin.api.del(`/work-items/${mine.id}`);
  expect(deleted.status(), await deleted.text()).toBe(200);

  // Its link went with it, so the issue is not mistaken for one already in.
  const again = await sendHook(bare, 'issues', issue, { secret: SECRET });
  expect((await again.json()).outcome).toBe('created');
  expect((await items()).filter((one) => one.title === title)).toHaveLength(1);
});

test('an issue raised in GitHub gets one comment linking to Forge', async () => {
  const number = 13;
  const answer = await sendHook(bare, 'issues', opened(number, IDS + number, { title: `Comment once ${RUN}` }), { secret: SECRET });
  expect((await answer.json()).outcome).toBe('created');

  const mine = (await items()).find((one) => one.title === `Comment once ${RUN}`);

  // Run twice: a job run again, or GitHub's answer lost, must not say it twice.
  await runGitHubJobs(admin.api.request);
  await runGitHubJobs(admin.api.request);

  const calls = await githubCalls(admin.api.request);
  const path = `/repos/${ALPHA}/issues/${number}/comments`;
  const posted = calls.filter((call) => call.method === 'POST' && call.path === path);

  expect(posted).toHaveLength(1);
  expect(JSON.parse(posted[0].body).body).toContain(`#item=${mine.id}`);
  expect(calls.filter((call) => call.method === 'PATCH' && call.path.startsWith(`/repos/${ALPHA}/issues/`))).toHaveLength(0);
});
