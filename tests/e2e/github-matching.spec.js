import { test, expect, request as pwRequest } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { installGitHubStub, removeGitHubStub, githubSays, githubState, githubCalls, githubSecret, runGitHubJobs, sendHook, issueJob } from './helpers/github.js';

// #682. A linked task's issue shows its Forge stage as one `forge:` label, and
// closes and reopens with the task. Labels that are not Forge's stay put.
// Titles match both ways; a description follows the side the issue started on.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const ALPHA = 'blueworx-io/alpha';

// The test site is reused, and an issue id is linked for good: each run uses
// ids no earlier run did.
const IDS = Date.now() * 100 + Math.floor(Math.random() * 100);

let admin;
let bare;
let site;
let SECRET;
let releasedTask;

test.describe.configure({ mode: 'serial' });

/**
 * A feature on this spec's site with its issue made, the way part 2 makes it:
 * in Documentation period, a repo chosen, the outbox run.
 */
async function linkedTask(title) {
  const made = (await (await Forge.makeItem(admin.api, site.id, { title: `${title} ${RUN_ID}`, level: 'feature' })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);

  const saved = await admin.api.patch(`/work-items/${item.id}`, { github_repo: ALPHA, record_version: item.record_version });
  expect(saved.status(), await saved.text()).toBe(200);

  // The second run sends what linking asked for: the stage label.
  await runGitHubJobs(admin.api.request);
  await runGitHubJobs(admin.api.request);

  const detail = await admin.api.get(`/work-items/${item.id}`);
  expect(detail.github.link, 'the task should have its issue').not.toBeNull();

  return detail.item;
}

/** The task's issue as the stand-in holds it. */
async function issueOf(id) {
  const { link } = (await admin.api.get(`/work-items/${id}`)).github;
  const issues = (await githubState(admin.api.request)).issues?.[link.repo] ?? [];

  return issues.find((issue) => issue.number === link.number);
}

async function labelsOf(id) {
  return (await issueOf(id)).labels.map((label) => label.name).sort();
}

async function current(id) {
  return (await admin.api.get(`/work-items/${id}`)).item;
}

/**
 * The issue as GitHub sends it in a message. Unless the spec says otherwise,
 * its author is someone on the repo, and it last changed when the stand-in
 * says it did, or now.
 */
function asSent(issue) {
  return {
    id: issue.id,
    number: issue.number,
    title: issue.title,
    body: issue.body,
    labels: [],
    user: issue.user ?? { login: 'octo' },
    author_association: issue.author_association ?? 'MEMBER',
    updated_at: issue.updated_at ?? new Date().toISOString(),
  };
}

/** What GitHub sends when an issue is edited: the issue as it is now, and which fields changed. */
function edited(issue, changes, login = 'octo') {
  return {
    action: 'edited',
    issue: asSent(issue),
    changes,
    repository: { full_name: ALPHA },
    sender: { login },
  };
}

/** An issue's time in GitHub, moved on by some seconds. */
function later(issue, seconds) {
  return new Date(Date.parse(issue.updated_at) + seconds * 1000).toISOString().replace(/\.\d+Z$/, 'Z');
}

async function hook(payload) {
  const answer = await sendHook(bare, 'issues', payload, { secret: SECRET });
  expect(answer.status(), await answer.text()).toBe(200);

  return (await answer.json()).outcome;
}

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGitHubStub();
  bare = await pwRequest.newContext({ baseURL });
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Match Co', RUN_ID));

  await githubSays(admin.api.request, { down: false, denied: false, hooks_denied: false, issues_refused: false, lose_create_answer: false, reset: true, hooks: {}, repos: [ALPHA] });
  expect((await admin.api.post('/connections/github', { token: 'good-token-6802' })).ok()).toBe(true);
  SECRET = await githubSecret(admin.api.request);
  expect((await admin.api.put(`/client-sites/${site.id}/github-repos`, { repos: [ALPHA] })).status()).toBe(200);
});

test.afterAll(async () => {
  await githubSays(admin.api.request, { down: false });
  await admin.api.put(`/client-sites/${site.id}/github-repos`, { repos: [] });
  await admin.api.del('/connections/github');
  await admin?.context.close();
  await bare?.dispose();
  removeGitHubStub();
});

test('the issue shows the stage as one label', async () => {
  test.slow();
  const task = await linkedTask('Stage label');

  // Its stage from the moment it is linked.
  expect(await labelsOf(task.id)).toEqual(['bug', 'forge: Documentation period']);

  await Forge.walkTo(admin.api, task, ['up-next']);
  await runGitHubJobs(admin.api.request);
  expect(await labelsOf(task.id)).toEqual(['bug', 'forge: Up next']);

  // The next stage takes the old label's place; `bug` is never touched.
  await Forge.walkTo(admin.api, await current(task.id), ['in-development']);
  await runGitHubJobs(admin.api.request);
  expect(await labelsOf(task.id)).toEqual(['bug', 'forge: In development']);
});

test('quick moves while GitHub is down leave only the latest label', async () => {
  test.slow();
  const task = await linkedTask('Down label');

  await githubSays(admin.api.request, { down: true });
  await Forge.walkTo(admin.api, task, ['up-next', 'in-development']);
  await githubSays(admin.api.request, { down: false });

  await runGitHubJobs(admin.api.request);
  const forgeLabels = (await labelsOf(task.id)).filter((name) => name.startsWith('forge: '));
  expect(forgeLabels).toEqual(['forge: In development']);
});

test('releasing closes the issue as completed', async () => {
  test.slow();
  const task = await linkedTask('Release closes');

  // An override reaches Released in one move; it is still a move.
  const released = await admin.api.post(`/work-items/${task.id}/override`, { to: 'released', reason: 'Set up for the test.', record_version: task.record_version });
  expect(released.status(), await released.text()).toBe(200);

  await runGitHubJobs(admin.api.request);
  const issue = await issueOf(task.id);
  expect(issue.state).toBe('closed');
  expect(issue.state_reason).toBe('completed');

  // Reopened by the last test.
  releasedTask = task.id;
});

test('cancelling closes it as not planned', async () => {
  test.slow();
  const task = await linkedTask('Cancel closes');

  const ended = await admin.api.post(`/work-items/${task.id}/outcome`, { outcome: 'cancelled', reason: 'Not needed.', record_version: task.record_version });
  expect(ended.status(), await ended.text()).toBe(200);

  await runGitHubJobs(admin.api.request);
  const issue = await issueOf(task.id);
  expect(issue.state).toBe('closed');
  expect(issue.state_reason).toBe('not_planned');
});

test('reopening the task reopens the issue', async () => {
  test.slow();
  expect(releasedTask, 'needs the released task from the test before').toBeTruthy();

  const task = await current(releasedTask);
  const reopened = await admin.api.post(`/work-items/${task.id}/reopen`, { to: 'in-development', reason: 'A case was missed.', record_version: task.record_version });
  expect(reopened.status(), await reopened.text()).toBe(200);

  await runGitHubJobs(admin.api.request);
  expect((await issueOf(task.id)).state).toBe('open');
});

test('renaming the task renames the issue, and the echo changes nothing', async () => {
  test.slow();
  const task = await linkedTask('Rename here');
  const title = `Renamed in Forge ${RUN_ID}`;

  const saved = await admin.api.patch(`/work-items/${task.id}`, { title, record_version: task.record_version });
  expect(saved.status(), await saved.text()).toBe(200);

  await runGitHubJobs(admin.api.request);
  const issue = await issueOf(task.id);
  expect(issue.title).toBe(title);

  // GitHub tells Forge about the rename Forge made.
  const version = (await current(task.id)).record_version;
  expect(await hook(edited(issue, { title: { from: task.title } }))).not.toBe('updated');
  expect((await current(task.id)).record_version).toBe(version);
});

test('renaming the issue renames the task', async () => {
  test.slow();
  const task = await linkedTask('Rename there');
  const issue = await issueOf(task.id);
  const title = `Renamed in GitHub ${RUN_ID}`;

  expect(await hook(edited({ ...issue, title }, { title: { from: issue.title } }))).toBe('updated');
  expect((await current(task.id)).title).toBe(title);

  // Nothing goes back: the new title is GitHub's already.
  const before = (await githubCalls(admin.api.request)).length;
  await runGitHubJobs(admin.api.request);
  const sent = (await githubCalls(admin.api.request)).slice(before);
  expect(sent.filter((call) => call.method === 'PATCH' && call.body.includes('"title"'))).toEqual([]);
  expect((await issueOf(task.id)).title).toBe(task.title);
});

test("editing a GitHub-raised issue's description updates the task", async () => {
  test.slow();
  const raised = { id: IDS + 1, number: 9001, title: `Raised in GitHub ${RUN_ID}`, body: 'The first words.' };

  expect(await hook({ ...edited(raised, {}), action: 'opened' })).toBe('created');
  const task = (await admin.api.get(`/work-items?client_site_id=${site.id}`)).items.find((one) => one.title === raised.title);
  expect(task, 'the issue should have come in').toBeTruthy();
  expect(task.problem).toBe('<p>The first words.</p>');

  expect(await hook(edited({ ...raised, body: 'Better words.' }, { body: { from: raised.body } }))).toBe('updated');
  expect((await current(task.id)).problem).toBe('<p>Better words.</p>');

  // An issue raised in GitHub is never written by Forge.
  const before = (await githubCalls(admin.api.request)).length;
  await runGitHubJobs(admin.api.request);
  const sent = (await githubCalls(admin.api.request)).slice(before);
  expect(sent.filter((call) => call.method === 'PATCH')).toEqual([]);
});

test("editing a Forge-made issue's description leaves a note, once", async () => {
  test.slow();
  const task = await linkedTask('Forge words');
  const issue = await issueOf(task.id);
  const changed = edited({ ...issue, body: 'Somebody rewrote this in GitHub.' }, { body: { from: issue.body } }, 'octo-editor');

  // Two deliveries of the same edit.
  expect(await hook(changed)).toBe('noted');
  expect(await hook(changed)).toBe('unchanged');

  const { comments } = await admin.api.get(`/work-items/${task.id}/comments`);
  const notes = comments.filter((one) => one.body.includes('changed in GitHub by octo-editor'));
  expect(notes).toHaveLength(1);
  expect(notes[0].visibility).toBe('internal');
  expect(notes[0].author_name).toBe('GitHub');
  expect((await current(task.id)).problem).toBe(task.problem);
});

test("an older rename's late message leaves the newest title", async () => {
  test.slow();
  const task = await linkedTask('Two renames');
  const middle = `Second name ${RUN_ID}`;
  const newest = `Third name ${RUN_ID}`;

  let saved = await admin.api.patch(`/work-items/${task.id}`, { title: middle, record_version: task.record_version });
  expect(saved.status(), await saved.text()).toBe(200);
  await runGitHubJobs(admin.api.request);
  const named = await issueOf(task.id);

  saved = await admin.api.patch(`/work-items/${task.id}`, { title: newest, record_version: (await saved.json()).item.record_version });
  expect(saved.status(), await saved.text()).toBe(200);
  await runGitHubJobs(admin.api.request);
  expect((await issueOf(task.id)).title).toBe(newest);

  // GitHub's two messages, the older one last.
  const version = (await current(task.id)).record_version;
  expect(await hook(edited({ ...named, title: newest }, { title: { from: middle } }))).toBe('unchanged');
  expect(await hook(edited({ ...named, title: middle }, { title: { from: task.title } }))).toBe('unchanged');

  const after = await current(task.id);
  expect(after.title).toBe(newest);
  expect(after.record_version).toBe(version);
});

test("a lost message from GitHub doesn't stop the next edit", async () => {
  test.slow();
  const task = await linkedTask('Lost message');
  const issue = await issueOf(task.id);
  const first = `Renamed once ${RUN_ID}`;
  const second = `Renamed twice ${RUN_ID}`;

  // GitHub's message about the first rename never arrives; the second does.
  expect(await hook(edited({ ...issue, title: second, updated_at: later(issue, 2) }, { title: { from: first } }))).toBe('updated');
  expect((await current(task.id)).title).toBe(second);
});

test("a change made in GitHub just before Forge's own still comes in", async () => {
  test.slow();
  const task = await linkedTask('Crossed edits');
  const { link } = (await admin.api.get(`/work-items/${task.id}`)).github;
  const title = `Renamed there first ${RUN_ID}`;

  // Someone renames the issue, and GitHub's message about it is slow.
  await githubSays(admin.api.request, { edit_issue: { repo: link.repo, number: link.number, title } });
  const theirs = await issueOf(task.id);

  // Forge writes to the issue meanwhile.
  const was = (await githubCalls(admin.api.request)).length;
  const saved = await admin.api.patch(`/work-items/${task.id}`, { scope: '<p>A wider scope.</p>', record_version: task.record_version });
  expect(saved.status(), await saved.text()).toBe(200);
  await runGitHubJobs(admin.api.request);
  const sent = (await githubCalls(admin.api.request)).slice(was);
  expect(sent.filter((call) => call.method === 'PATCH' && call.body.includes('"body"')).length).toBeGreaterThan(0);

  // Then the message arrives.
  expect(await hook(edited(theirs, { title: { from: task.title } }))).toBe('updated');
  expect((await current(task.id)).title).toBe(title);
});

test('a repo Forge can no longer reach keeps its changes waiting', async () => {
  test.slow();
  const task = await linkedTask('Lost access');
  const title = `Renamed while locked out ${RUN_ID}`;

  await githubSays(admin.api.request, { repos: [] });

  try {
    const saved = await admin.api.patch(`/work-items/${task.id}`, { title, record_version: task.record_version });
    expect(saved.status(), await saved.text()).toBe(200);
    await runGitHubJobs(admin.api.request);

    const job = await issueJob(admin.api.request, task.id, 'set_title');
    expect(job.status).toBe('pending');
    expect(job.last_error).toContain('404');

    const github = (await admin.api.get('/connections')).services.find((one) => one.id === 'github');
    expect(github.error ?? '').not.toContain(`The GitHub issue for ${title} is gone.`);
  } finally {
    await githubSays(admin.api.request, { repos: [ALPHA] });
  }

  // Access back: the change goes.
  await runGitHubJobs(admin.api.request);
  expect((await issueOf(task.id)).title).toBe(title);
});

test('a save made while the title is being sent still reaches GitHub', async () => {
  test.slow();
  const task = await linkedTask('Busy rename');
  const first = `Sent first ${RUN_ID}`;
  const meanwhile = `Saved meanwhile ${RUN_ID}`;

  // Someone saves the task again while Forge is sending the first rename.
  await githubSays(admin.api.request, { rename_during_patch: { item_id: task.id, title: meanwhile } });
  const saved = await admin.api.patch(`/work-items/${task.id}`, { title: first, record_version: task.record_version });
  expect(saved.status(), await saved.text()).toBe(200);

  await runGitHubJobs(admin.api.request);
  await runGitHubJobs(admin.api.request);
  expect((await current(task.id)).title).toBe(meanwhile);
  expect((await issueOf(task.id)).title).toBe(meanwhile);
});

test('a task that ended while its issue waited gets a closed issue', async () => {
  test.slow();
  const made = (await (await Forge.makeItem(admin.api, site.id, { title: `Ended while waiting ${RUN_ID}`, level: 'feature' })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);

  await githubSays(admin.api.request, { down: true });
  const saved = await admin.api.patch(`/work-items/${item.id}`, { github_repo: ALPHA, record_version: item.record_version });
  expect(saved.status(), await saved.text()).toBe(200);
  await runGitHubJobs(admin.api.request);

  const ended = await admin.api.post(`/work-items/${item.id}/outcome`, { outcome: 'cancelled', reason: 'Not needed.', record_version: (await saved.json()).item.record_version });
  expect(ended.status(), await ended.text()).toBe(200);

  await githubSays(admin.api.request, { down: false });
  await runGitHubJobs(admin.api.request);
  await runGitHubJobs(admin.api.request);

  const issue = await issueOf(item.id);
  expect(issue.state).toBe('closed');
  expect(issue.state_reason).toBe('not_planned');
  expect(issue.labels.map((label) => label.name)).toContain('forge: Documentation period');
});

test('an issue deleted in GitHub stops its changes, and says so', async () => {
  test.slow();
  const task = await linkedTask('Deleted issue');
  const { link } = (await admin.api.get(`/work-items/${task.id}`)).github;
  const title = `Renamed after delete ${RUN_ID}`;

  await githubSays(admin.api.request, { forget_issue: { repo: link.repo, number: link.number } });
  const saved = await admin.api.patch(`/work-items/${task.id}`, { title, record_version: task.record_version });
  expect(saved.status(), await saved.text()).toBe(200);
  await runGitHubJobs(admin.api.request);

  const github = (await admin.api.get('/connections')).services.find((one) => one.id === 'github');
  expect(github.error).toContain(`The GitHub issue for ${title} is gone.`);

  // Not tried again.
  const path = `/repos/${link.repo}/issues/${link.number}`;
  const before = (await githubCalls(admin.api.request)).length;
  await runGitHubJobs(admin.api.request);
  const sent = (await githubCalls(admin.api.request)).slice(before);
  expect(sent.filter((call) => call.path.startsWith(path))).toEqual([]);
});

test('renaming an issue with a very long title still renames the task', async () => {
  test.slow();
  const long = `Long title ${RUN_ID} ${'x'.repeat(250)}`.slice(0, 250);
  const raised = { id: IDS + 2, number: 9002, title: long, body: 'Words.' };

  expect(await hook({ ...edited(raised, {}), action: 'opened' })).toBe('created');
  const task = (await admin.api.get(`/work-items?client_site_id=${site.id}`)).items.find((one) => one.title === long.slice(0, 191));
  expect(task, 'the issue should have come in, cut to length').toBeTruthy();

  const renamed = `Longer still ${RUN_ID} ${'z'.repeat(250)}`.slice(0, 250);
  expect(await hook(edited({ ...raised, title: renamed }, { title: { from: long } }))).toBe('updated');
  expect((await current(task.id)).title).toBe(renamed.slice(0, 191));
});

/** What GitHub sends when an issue is closed or reopened. */
function stateChange(action, issue, { reason = 'completed', login = 'octo' } = {}) {
  return {
    action,
    issue: { ...asSent(issue), state: 'closed' === action ? 'closed' : 'open', state_reason: 'closed' === action ? reason : null },
    repository: { full_name: ALPHA },
    sender: { login },
  };
}

async function notesBy(id, words) {
  const { comments } = await admin.api.get(`/work-items/${id}/comments`);

  return comments.filter((one) => one.body.includes(words));
}

let echoed;

test('closing the issue moves finished development to review', async () => {
  test.slow();
  const task = await linkedTask('Close to review');
  const developed = await Forge.walkTo(admin.api, task, ['up-next', 'in-development']);
  await Forge.satisfy(admin.api, developed, 'in-review');
  await runGitHubJobs(admin.api.request);
  const issue = await issueOf(task.id);

  expect(await hook(stateChange('closed', issue))).toBe('moved');
  expect((await current(task.id)).stage).toBe('in-review');

  const { history } = await admin.api.get(`/work-items/${task.id}`);
  const move = history.find((entry) => entry.action === 'moved' && entry.to_stage === 'in-review');
  expect(move.via).toBe('github');

  // The task's history says the move came from GitHub.
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: task.title }).click();
  await page.getByTestId('bwx-history-wrap').locator('summary').click();
  await expect(page.locator('[data-testid="bwx-history"]')).toContainText('In development → In review. Closed in GitHub by octo.');
  await page.close();

  // The issue shows the new stage, and nothing reopens or closes it.
  const before = (await githubCalls(admin.api.request)).length;
  await runGitHubJobs(admin.api.request);
  const sent = (await githubCalls(admin.api.request)).slice(before);
  expect(sent.filter((call) => call.body.includes('"state"'))).toEqual([]);
  expect(await labelsOf(task.id)).toContain('forge: In review');

  // Nothing from GitHub takes it further, however often it is said.
  expect(await hook(stateChange('closed', issue))).toBe('nothing');
  expect((await current(task.id)).stage).toBe('in-review');

  // Approving it leaves the issue closed.
  const review = await current(task.id);
  const approved = await admin.api.post(`/work-items/${task.id}/override`, { to: 'completed', reason: 'Approved for the test.', record_version: review.record_version });
  expect(approved.status(), await approved.text()).toBe(200);
  const was = (await githubCalls(admin.api.request)).length;
  await runGitHubJobs(admin.api.request);
  const after = (await githubCalls(admin.api.request)).slice(was);
  expect(after.filter((call) => call.body.includes('"state"'))).toEqual([]);
});

test('closing the issue early leaves one note', async ({ request }) => {
  test.slow();
  const task = await linkedTask('Close early');
  const planned = await Forge.walkTo(admin.api, task, ['up-next']);
  const issue = await issueOf(task.id);
  const closed = stateChange('closed', issue, { login: 'octo-closer' });

  // Two deliveries of the same close.
  expect(await hook(closed)).toBe('noted');
  expect(await hook(closed)).toBe('nothing');

  expect((await current(task.id)).stage).toBe(planned.stage);
  const notes = await notesBy(task.id, 'closed in GitHub by octo-closer');
  expect(notes).toHaveLength(1);
  expect(notes[0].visibility).toBe('internal');
  expect(notes[0].author_name).toBe('GitHub');
  expect(notes[0].body).toBe('The issue was closed in GitHub by octo-closer. Forge has left this task where it is.');

  // The client never sees it.
  const theirs = await Forge.asClientSite(admin.api, site.id, request);
  const answer = await theirs.get(`/client/work-items/${task.id}/comments`);
  expect(answer.status(), await answer.text()).toBe(200);
  expect((await answer.json()).comments.filter((one) => one.body.includes('closed in GitHub'))).toEqual([]);

  // Reopened there by a person: Forge never closed it, so that is a note too.
  expect(await hook(stateChange('reopened', issue, { login: 'octo-reopener' }))).toBe('noted');
  expect(await notesBy(task.id, 'reopened in GitHub by octo-reopener')).toHaveLength(1);
});

test('sending work back from review reopens an issue closed in GitHub', async () => {
  test.slow();
  const task = await linkedTask('Back from review');
  const developed = await Forge.walkTo(admin.api, task, ['up-next', 'in-development']);
  await Forge.satisfy(admin.api, developed, 'in-review');
  await runGitHubJobs(admin.api.request);

  expect(await hook(stateChange('closed', await issueOf(task.id)))).toBe('moved');
  const review = await current(task.id);
  expect(review.stage).toBe('in-review');

  const before = (await githubCalls(admin.api.request)).length;
  const back = await admin.api.post(`/work-items/${task.id}/return`, { to: 'in-development', reason: 'One more thing to change.', feedback: 'One more thing to change.', record_version: review.record_version });
  expect(back.status(), await back.text()).toBe(200);
  await runGitHubJobs(admin.api.request);

  const sent = (await githubCalls(admin.api.request)).slice(before);
  expect(sent.filter((call) => call.method === 'PATCH' && call.body.includes('"state":"open"'))).toHaveLength(1);

  // GitHub's message about it is Forge's own reopen, and says nothing.
  expect(await hook(stateChange('reopened', await issueOf(task.id)))).toBe('nothing');
});

test("someone who isn't on the repo can't change the task from GitHub", async () => {
  test.slow();
  const raised = { id: IDS + 3, number: 9003, title: `A stranger's issue ${RUN_ID}`, body: 'Please help.', user: { login: 'stranger' }, author_association: 'NONE' };

  expect(await hook({ ...edited(raised, {}, 'stranger'), action: 'opened' })).toBe('created');
  const made = (await admin.api.get(`/work-items?client_site_id=${site.id}`)).items.find((one) => one.title === raised.title);
  expect(made, 'the issue should have come in').toBeTruthy();

  // Far enough along that a close by someone on the repo would move it.
  const developed = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period', 'up-next', 'in-development']);
  await Forge.satisfy(admin.api, developed, 'in-review');

  const renamed = { ...raised, title: `Renamed by a stranger ${RUN_ID}`, body: 'Do it my way.' };
  const edit = edited(renamed, { title: { from: raised.title }, body: { from: raised.body } }, 'stranger');

  // Two deliveries of the same edit.
  expect(await hook(edit)).toBe('noted');
  expect(await hook(edit)).toBe('unchanged');

  const after = await current(made.id);
  expect(after.title).toBe(raised.title);
  expect(after.problem).toBe('<p>Please help.</p>');

  const notes = await notesBy(made.id, 'changed in GitHub by stranger');
  expect(notes).toHaveLength(1);
  expect(notes[0].visibility).toBe('internal');
  expect(notes[0].body).toContain(`The issue's title was changed in GitHub by stranger to "${renamed.title}". Forge kept its own.`);

  expect(await hook(stateChange('closed', renamed, { login: 'stranger' }))).toBe('noted');
  expect((await current(made.id)).stage).toBe('in-development');
  expect(await notesBy(made.id, 'closed in GitHub by stranger')).toHaveLength(1);
});

test("the echo of Forge's own close says nothing", async () => {
  test.slow();
  const task = await linkedTask('Close echo');
  const released = await admin.api.post(`/work-items/${task.id}/override`, { to: 'released', reason: 'Set up for the test.', record_version: task.record_version });
  expect(released.status(), await released.text()).toBe(200);
  await runGitHubJobs(admin.api.request);

  const issue = await issueOf(task.id);
  expect(issue.state).toBe('closed');
  const said = (await admin.api.get(`/work-items/${task.id}/comments`)).comments.length;

  expect(await hook(stateChange('closed', issue))).toBe('nothing');
  expect((await admin.api.get(`/work-items/${task.id}/comments`)).comments).toHaveLength(said);

  echoed = task.id;
});

test("reopening in GitHub after release leaves a note; Forge's own reopen doesn't", async () => {
  test.slow();
  const task = await linkedTask('Reopen there');
  const released = await admin.api.post(`/work-items/${task.id}/override`, { to: 'released', reason: 'Set up for the test.', record_version: task.record_version });
  expect(released.status(), await released.text()).toBe(200);
  await runGitHubJobs(admin.api.request);

  const reopened = stateChange('reopened', await issueOf(task.id), { login: 'octo-opener' });
  expect(await hook(reopened)).toBe('noted');
  expect(await hook(reopened)).toBe('nothing');
  expect(await notesBy(task.id, 'reopened in GitHub by octo-opener')).toHaveLength(1);
  expect((await current(task.id)).stage).toBe('released');

  // Forge's own reopen, and GitHub saying so.
  expect(echoed, 'needs the released task from the test before').toBeTruthy();
  const mine = await current(echoed);
  const again = await admin.api.post(`/work-items/${mine.id}/reopen`, { to: 'in-development', reason: 'A case was missed.', record_version: mine.record_version });
  expect(again.status(), await again.text()).toBe(200);
  await runGitHubJobs(admin.api.request);

  const said = (await admin.api.get(`/work-items/${mine.id}/comments`)).comments.length;
  expect(await hook(stateChange('reopened', await issueOf(mine.id)))).toBe('nothing');
  expect((await admin.api.get(`/work-items/${mine.id}/comments`)).comments).toHaveLength(said);
});
