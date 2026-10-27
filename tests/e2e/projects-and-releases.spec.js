import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #562. A client's work as projects made of releases: built from the client's
// page, checked on the server, and moved into place from an item's panel.
//
// The instance is kept between runs, so every name carries a run id and every
// test makes what it needs.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

// A screen reads again after every add, from a single-threaded server.
// Each read can wait behind the app's own background reads.
const SLOW = { timeout: 60_000 };

const UP_TO_COMPLETED = ['triage', 'documentation-period', 'up-next', 'design-process', 'in-development', 'in-review', 'completed'];

let admin;
let client;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client, site } = await Forge.makeSite(admin.api, 'Projects Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

/** One item over REST, failing loudly if it is refused. */
async function made(data) {
  const answer = await Forge.makeItem(admin.api, site.id, data);

  expect(answer.status(), await answer.text()).toBe(200);

  return (await answer.json()).item;
}

/** Fills in the add form and adds what it describes. */
async function add(page, { title, start = '', due = '', type = '' }) {
  const form = page.getByTestId('bwx-new-work');

  await expect(form).toBeVisible();
  await form.getByLabel('Title').fill(title);
  if (start) await form.getByTestId('bwx-new-start').fill(start);
  if (due) await form.getByTestId('bwx-new-due').fill(due);
  if (type) await form.getByTestId('bwx-new-type').selectOption(type);
  await form.getByTestId('bwx-create').click();
  await expect(form).toBeHidden(SLOW);
}

test("builds a project from a client's page", async () => {
  // Six adds, each followed by the page reading itself again.
  test.setTimeout(480_000);

  const page = await admin.context.newPage();
  const name = `App 2026 ${RUN_ID}`;

  await page.goto(`/blueworx-forge/#screen=client&client=${client.id}`);
  await expect(page.getByTestId('bwx-client-profile')).toContainText(client.display_name, { timeout: 30_000 });

  await page.getByTestId('bwx-client-add-project').click();
  await add(page, { title: name });

  const listed = page.getByTestId('bwx-client-project').filter({ hasText: name });
  await expect(listed).toBeVisible(SLOW);
  await listed.click();

  const project = page.getByTestId('bwx-project');
  await expect(project).toContainText(name, { timeout: 30_000 });
  await expect(project).toContainText(client.display_name);
  await expect(project).toContainText('Ongoing');

  await page.getByTestId('bwx-project-add-release').click();
  await add(page, { title: `Leaderboards ${RUN_ID}`, start: '2026-07-01', due: '2026-07-24' });
  await page.getByTestId('bwx-project-add-release').click();
  await add(page, { title: `Premium ${RUN_ID}`, start: '2026-08-03', due: '2026-08-28' });

  const releases = page.getByTestId('bwx-project-release');
  await expect(releases).toHaveCount(2, SLOW);
  await expect(releases.nth(0)).toContainText(`Leaderboards ${RUN_ID}`);
  await expect(releases.nth(0)).toContainText('Weeks 27 → 30');
  await expect(releases.nth(1)).toContainText('Weeks 32 → 35');

  // The project's dates are its releases' dates now.
  await expect(project).not.toContainText('Ongoing', SLOW);

  const first = releases.nth(0);
  await first.getByTestId('bwx-project-toggle').click();
  await first.getByTestId('bwx-project-add-feature').first().click();
  await add(page, { title: `Scoreboard ${RUN_ID}` });

  const feature = first.getByTestId('bwx-project-node').filter({ hasText: `Scoreboard ${RUN_ID}` });
  await expect(feature).toBeVisible(SLOW);
  await feature.getByTestId('bwx-project-add-leaf').first().click();
  await add(page, { title: `Wrong total ${RUN_ID}`, type: 'bug' });

  await expect(feature).toContainText(`Wrong total ${RUN_ID}`, SLOW);

  // Opening any row opens its panel.
  await feature.getByRole('button', { name: `Wrong total ${RUN_ID}`, exact: true }).click();
  await expect(page.getByTestId('bwx-panel')).toContainText(`Wrong total ${RUN_ID}`, SLOW);

  await page.close();
});

test("a project can't be released while a release is open", async ({ browser, baseURL }) => {
  // Seven stages of gates against a single-threaded server, and a team of three.
  test.setTimeout(480_000);

  const team = await Forge.team(admin.api, browser, baseURL, client.id);
  await Forge.onSupport(admin, site.id, 200);

  const project = await made({ title: `Walked ${RUN_ID}`, level: 'project' });
  await made({ title: `Still open ${RUN_ID}`, level: 'milestone', parent_id: project.id });

  // No dates asked of a project on the way: they come from its releases.
  let current = await Forge.walkTo(admin.api, project, UP_TO_COMPLETED, { seats: team.seats, as: team.as });
  expect(current.stage).toBe('completed');
  expect(current.planned_due).toBe('');

  current = await Forge.satisfy(admin.api, current, 'released', team.seats);

  const refused = await team.as.released.post(`/work-items/${current.id}/transition`, {
    to: 'released',
    record_version: current.record_version,
  });

  expect(refused.status()).toBe(409);
  expect((await refused.json()).unmet.map((row) => row.id)).toContain('G-COMPLETED-8');

  await team.close();
});

test('changing level is checked against parent and children', async () => {
  const project = await made({ title: `Shaped ${RUN_ID}`, level: 'project' });
  const release = await made({ title: `Shaped release ${RUN_ID}`, level: 'milestone', parent_id: project.id });
  const feature = await made({ title: `Shaped feature ${RUN_ID}`, level: 'feature', parent_id: release.id });

  // A feature cannot become a project under its own release.
  const raised = await admin.api.patch(`/work-items/${feature.id}`, { level: 'project', record_version: feature.record_version });
  expect(raised.status()).toBe(400);
  expect((await raised.json()).code).toBe('bwx_forge_invalid_parent');

  // Nor can a release drop below the feature it holds.
  const dropped = await admin.api.patch(`/work-items/${release.id}`, { level: 'sub-feature', record_version: release.record_version });
  expect(dropped.status()).toBe(400);
  expect((await dropped.json()).code).toBe('bwx_forge_invalid_level');

  // Nor become a bug: a release always holds work, so it stays a feature (#665).
  const leaf = await admin.api.patch(`/work-items/${release.id}`, { work_type: 'bug', record_version: release.record_version });
  expect(leaf.status(), await leaf.text()).toBe(200);
  expect((await leaf.json()).item.work_type).toBe('feature');

  // Nor move under its own feature.
  const looped = await admin.api.patch(`/work-items/${release.id}`, { parent_id: feature.id, record_version: release.record_version });
  expect(looped.status()).toBe(400);
  expect((await looped.json()).message).toBe('Work cannot sit under work beneath it.');

  // A level that still fits is fine.
  const fine = await admin.api.patch(`/work-items/${feature.id}`, { level: 'sub-feature', record_version: feature.record_version });
  expect(fine.status(), await fine.text()).toBe(200);
});

test('a project or release is always a feature, so it holds work whatever type it was given (#665)', async () => {
  const project = await made({ title: `Typed as a task ${RUN_ID}`, level: 'project', work_type: 'task' });
  expect(project.work_type).toBe('feature');

  const release = await made({ title: `Typed as a bug ${RUN_ID}`, level: 'milestone', work_type: 'bug', parent_id: project.id });
  expect(release.work_type).toBe('feature');

  const feature = await made({ title: `Held ${RUN_ID}`, level: 'feature', parent_id: release.id });
  expect(feature.parent_id).toBe(release.id);

  // A feature raised to a project becomes one, type and all.
  const loose = await made({ title: `Raised task ${RUN_ID}`, level: 'feature', work_type: 'task' });
  const raised = await admin.api.patch(`/work-items/${loose.id}`, { level: 'project', record_version: loose.record_version });
  expect(raised.status(), await raised.text()).toBe(200);
  expect((await raised.json()).item.work_type).toBe('feature');
});

test('a bug holds nothing, and can sit under a sub-feature', async () => {
  const part = await made({ title: `Lowest rung ${RUN_ID}`, level: 'sub-feature' });
  const bug = await made({ title: `Sits low ${RUN_ID}`, level: 'sub-feature', work_type: 'bug', parent_id: part.id });

  const under = await Forge.makeItem(admin.api, site.id, { title: `Under a bug ${RUN_ID}`, level: 'sub-feature', work_type: 'task', parent_id: bug.id });
  expect(under.status()).toBe(400);
  expect((await under.json()).message).toBe("Bugs, tasks and feedback can't have work beneath them.");
});

/** Ends an item without it being done. */
async function cancel(item) {
  const answer = await admin.api.post(`/work-items/${item.id}/outcome`, { outcome: 'cancelled', reason: 'Not needed.', record_version: item.record_version });

  expect(answer.status(), await answer.text()).toBe(200);
}

test('ended work is tagged Ended, and Back returns to the client', async () => {
  const live = await made({ title: `Live project ${RUN_ID}`, level: 'project' });
  const dropped = await made({ title: `Dropped project ${RUN_ID}`, level: 'project' });
  const release = await made({ title: `Tagged release ${RUN_ID}`, level: 'milestone', parent_id: live.id });
  const open = await made({ title: `Open bug ${RUN_ID}`, level: 'sub-feature', work_type: 'bug', parent_id: release.id });
  const gone = await made({ title: `Cancelled bug ${RUN_ID}`, level: 'sub-feature', work_type: 'bug', parent_id: release.id });
  await cancel(dropped);
  await cancel(gone);

  const page = await admin.context.newPage();

  await page.goto(`/blueworx-forge/#screen=client&client=${client.id}`);
  const listed = (title) => page.getByTestId('bwx-client-project').filter({ hasText: title });
  await expect(listed(dropped.title)).toContainText('Ended', { timeout: 30_000 });
  await expect(listed(live.title)).not.toContainText('Ended');

  await listed(live.title).click();
  const row = page.getByTestId('bwx-project-release').filter({ hasText: release.title });
  await expect(row).toBeVisible({ timeout: 30_000 });
  await row.getByTestId('bwx-project-toggle').click();

  const node = (one) => row.locator(`[data-testid="bwx-project-node"][data-item="${one.id}"]`);
  await expect(node(gone)).toContainText('Ended', SLOW);
  await expect(node(open)).not.toContainText('Ended');

  // Opened from the client's page, Back goes there.
  await expect(page.getByTestId('bwx-project-back')).toContainText(client.display_name);
  await page.getByTestId('bwx-project-back').click();
  await expect(page.getByTestId('bwx-client-profile')).toContainText(client.display_name, SLOW);

  await page.close();
});

test('moves an existing task under a release from its panel', async () => {
  const project = await made({ title: `Moving ${RUN_ID}`, level: 'project' });
  const release = await made({ title: `Landing ${RUN_ID}`, level: 'milestone', parent_id: project.id });
  const task = await made({ title: `Loose task ${RUN_ID}`, level: 'sub-feature', work_type: 'task' });

  const page = await admin.context.newPage();

  await page.goto(`/blueworx-forge/#item=${task.id}`);
  const panel = page.getByTestId('bwx-panel');
  await expect(panel).toContainText(`Loose task ${RUN_ID}`, { timeout: 30_000 });

  const picker = panel.getByTestId('bwx-sits-under');
  await expect(picker.locator(`option[value="${release.id}"]`)).toHaveCount(1, SLOW);
  await picker.selectOption(release.id);
  await expect.poll(async () => (await admin.api.get(`/work-items/${task.id}`)).item.parent_id, SLOW).toBe(release.id);

  await page.close();

  // A fresh load of the project page, by its link.
  const fresh = await admin.context.newPage();

  await fresh.goto(`/blueworx-forge/#screen=project&project=${project.id}`);
  const row = fresh.getByTestId('bwx-project-release').filter({ hasText: `Landing ${RUN_ID}` });
  await expect(row).toBeVisible({ timeout: 30_000 });
  await row.getByTestId('bwx-project-toggle').click();
  await expect(row).toContainText(`Loose task ${RUN_ID}`, SLOW);

  await fresh.close();
});

test('existing work is added to a release and a project from the project page, and taken out again (#607)', async () => {
  test.slow();

  const project = await made({ title: `Gathering ${RUN_ID}`, level: 'project' });
  const release = await made({ title: `Gathered ${RUN_ID}`, level: 'milestone', parent_id: project.id });
  const task = await made({ title: `Picked task ${RUN_ID}`, level: 'sub-feature', work_type: 'task' });
  const feature = await made({ title: `Picked feature ${RUN_ID}`, level: 'feature' });

  const page = await admin.context.newPage();
  await page.goto(`/blueworx-forge/#screen=project&project=${project.id}`);
  const row = page.getByTestId('bwx-project-release').filter({ hasText: release.title });
  await expect(row).toBeVisible({ timeout: 30_000 });
  await row.getByTestId('bwx-project-toggle').click();

  // The release offers what can sit in it, and never the project above it.
  const picker = row.getByTestId('bwx-project-add-existing');
  await expect(picker).toHaveAccessibleName(`Add existing work to ${release.title}`);
  await expect(picker.locator(`option[value="${task.id}"]`)).toHaveCount(1, SLOW);
  await expect(picker.locator(`option[value="${project.id}"]`)).toHaveCount(0);

  await picker.selectOption(task.id);
  const node = row.locator(`[data-testid="bwx-project-node"][data-item="${task.id}"]`);
  await expect(node).toBeVisible(SLOW);
  expect((await admin.api.get(`/work-items/${task.id}`)).item.parent_id).toBe(release.id);
  // Counted now: the release has work.
  await expect(row).toContainText('0 of 1 done', SLOW);
  // And it is not offered twice.
  await expect(picker.locator(`option[value="${task.id}"]`)).toHaveCount(0);

  // The project takes a feature straight in.
  const projectPicker = page.getByRole('combobox', { name: `Add existing work to ${project.title}` });
  await projectPicker.selectOption(feature.id);
  await expect(page.locator(`[data-testid="bwx-project-node"][data-item="${feature.id}"]`)).toBeVisible(SLOW);

  // Taking the task out leaves it on the site, under nothing.
  await node.getByTestId('bwx-project-take-out').click();
  await expect(node).toHaveCount(0, SLOW);
  const after = (await admin.api.get(`/work-items/${task.id}`)).item;
  expect(after.parent_id).toBe('');
  expect(after.archived).toBeFalsy();

  await page.close();
});
