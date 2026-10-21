import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #590. Archiving, restoring and deleting projects and releases. Names carry a
// run id and each test makes what it needs.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const SLOW = { timeout: 60_000 };

let admin;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Archive Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

async function made(data) {
  const answer = await Forge.makeItem(admin.api, site.id, data);

  expect(answer.status(), await answer.text()).toBe(200);

  return (await answer.json()).item;
}

const move = async (item, path) =>
  admin.api.post(`/work-items/${item.id}/${path}`, { record_version: (await admin.api.get(`/work-items/${item.id}`)).item.record_version });

test('the server only deletes an archived project, and keeps what was under it', async () => {
  const project = await made({ title: `Server ${RUN_ID}`, level: 'project', work_type: 'feature' });
  const release = await made({ title: `Server release ${RUN_ID}`, level: 'milestone', work_type: 'feature', parent_id: project.id });
  const feature = await made({ title: `Server feature ${RUN_ID}`, level: 'feature', work_type: 'feature', parent_id: release.id });

  const early = await move(project, 'delete-archived');
  expect(early.status()).toBe(409);
  const direct = await admin.api.request.delete(`/wp-json/blueworx-forge/v1/work-items/${project.id}`, { headers: admin.api.headers });
  expect(direct.status()).toBe(409);

  expect((await move(project, 'archive')).status()).toBe(200);
  expect((await admin.api.get(`/work-items/${project.id}`)).item.archived).toBe(true);
  expect((await admin.api.get(`/work-items/${project.id}`)).item.stage).toBe(project.stage);

  expect((await move(project, 'restore')).status()).toBe(200);
  expect((await admin.api.get(`/work-items/${project.id}`)).item.archived).toBe(false);

  // The release goes first: its feature moves up to the project.
  expect((await move(release, 'archive')).status()).toBe(200);
  expect((await move(release, 'delete-archived')).status()).toBe(200);
  expect((await admin.api.get(`/work-items/${feature.id}`)).item.parent_id).toBe(project.id);

  // Then the project: the feature is left with no parent.
  expect((await move(project, 'archive')).status()).toBe(200);
  expect((await move(project, 'delete-archived')).status()).toBe(200);
  expect((await admin.api.get(`/work-items/${feature.id}`)).item.parent_id).toBe('');
  const gone = await admin.api.request.get(`/wp-json/blueworx-forge/v1/work-items/${project.id}`, { headers: admin.api.headers });
  expect(gone.status()).toBe(404);
});

test('an archived project leaves the lists, shows under Archived, and comes back', async () => {
  test.slow();

  const project = await made({ title: `Shelf ${RUN_ID}`, level: 'project', work_type: 'feature' });
  const page = await admin.context.newPage();

  await page.goto(`/blueworx-forge/#screen=project&project=${project.id}`);
  await expect(page.getByTestId('bwx-project')).toContainText(project.title, SLOW);
  await page.getByTestId('bwx-project-archive').click();
  await expect(page.getByTestId('bwx-project-archived')).toBeVisible(SLOW);

  await page.getByTestId('bwx-project-back').click();
  const rows = (id) => page.getByTestId(id).getByRole('row').filter({ hasText: project.title });
  await expect(page.getByTestId('bwx-projects-list')).toBeVisible(SLOW);
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await expect(rows('bwx-projects-list')).toHaveCount(0);

  await page.getByTestId('bwx-projects-archived').click();
  await expect(rows('bwx-projects-archived-list')).toHaveCount(1, SLOW);
  await rows('bwx-projects-archived-list').getByTestId('bwx-projects-restore').click();
  await expect(rows('bwx-projects-archived-list')).toHaveCount(0, SLOW);

  await page.getByTestId('bwx-projects-active').click();
  await expect(rows('bwx-projects-list')).toHaveCount(1, SLOW);
});

test('deleting asks first, and the release goes while its work stays', async () => {
  test.slow();

  const project = await made({ title: `Gone ${RUN_ID}`, level: 'project', work_type: 'feature' });
  const release = await made({ title: `Gone release ${RUN_ID}`, level: 'milestone', work_type: 'feature', parent_id: project.id });
  const feature = await made({ title: `Kept feature ${RUN_ID}`, level: 'feature', work_type: 'feature', parent_id: release.id });
  const page = await admin.context.newPage();

  await page.goto(`/blueworx-forge/#screen=project&project=${project.id}`);
  const row = page.getByTestId('bwx-project-release').filter({ hasText: release.title });
  await expect(row).toBeVisible(SLOW);
  await row.getByTestId('bwx-release-archive').click();

  const shelved = page.getByTestId('bwx-project-shelved-release').filter({ hasText: release.title });
  await expect(shelved).toBeVisible(SLOW);
  await shelved.getByTestId('bwx-release-delete').click();

  const dialog = page.getByTestId('bwx-delete-archived');
  await expect(dialog).toContainText('kept');
  await dialog.getByTestId('bwx-delete-cancel').click();
  await expect(dialog).toBeHidden();
  await expect(shelved).toBeVisible();

  await shelved.getByTestId('bwx-release-delete').click();
  await dialog.getByTestId('bwx-delete-confirm').click();
  await expect(shelved).toHaveCount(0, SLOW);
  await expect(page.getByTestId('bwx-project-node').filter({ hasText: feature.title })).toBeVisible(SLOW);
});

const PASSWORD = Forge.PASSWORD;

test('who may archive, restore and delete: staff archive and restore, only an admin deletes', async ({ browser, baseURL }) => {
  test.setTimeout(420_000);

  const { client, site: own } = await Forge.makeSite(admin.api, 'Archive Rights Co', RUN_ID);
  const one = async (data) => (await (await Forge.makeItem(admin.api, own.id, data)).json()).item;
  const project = await one({ title: `Rights ${RUN_ID}`, level: 'project', work_type: 'feature' });
  const task = await one({ title: `Rights task ${RUN_ID}`, level: 'sub-feature', work_type: 'task', parent_id: project.id });

  const call = async (who, item, path) =>
    (await who.api.post(`/work-items/${item.id}/${path}`, { record_version: (await admin.api.get(`/work-items/${item.id}`)).item.record_version })).status();

  const people = {};
  for (const role of ['client_admin', 'client_viewer', 'staff']) {
    const person = await Forge.makePerson(admin.api, client.id, role, `${role.replace('_', '')}${RUN_ID.slice(-6)}`);
    people[role] = await Forge.signedIn(browser, baseURL, person.login, PASSWORD);
  }

  for (const role of ['client_admin', 'client_viewer']) {
    expect(await call(people[role], project, 'archive'), `${role} archive`).toBe(403);
    expect(await call(people[role], project, 'restore'), `${role} restore`).toBe(403);
    expect(await call(people[role], project, 'delete-archived'), `${role} delete`).toBe(403);
  }

  expect(await call(people.staff, project, 'archive')).toBe(200);
  // Staff may not delete, archived or not.
  expect(await call(people.staff, project, 'delete-archived')).toBe(403);
  expect(await call(people.staff, project, 'restore')).toBe(200);

  // Restoring something that is not an archived project or release is refused.
  expect(await call(admin, project, 'restore')).toBe(409);
  expect(await call(admin, task, 'restore')).toBe(409);

  // Nothing new goes under an archived project.
  expect(await call(people.staff, project, 'archive')).toBe(200);
  const under = await Forge.makeItem(admin.api, own.id, { title: `Late ${RUN_ID}`, level: 'sub-feature', work_type: 'task', parent_id: project.id });
  expect(under.status()).toBe(409);

  // An archived project is not in what the client page and pickers read.
  const listed = await admin.api.get(`/work-items?client_site_id=${own.id}`);
  expect(listed.items.map((i) => i.id)).not.toContain(project.id);

  for (const who of Object.values(people)) await who.context.close();
});

test('a grandchild keeps its parent when its release is deleted', async () => {
  const project = await made({ title: `Deep ${RUN_ID}`, level: 'project', work_type: 'feature' });
  const release = await made({ title: `Deep release ${RUN_ID}`, level: 'milestone', work_type: 'feature', parent_id: project.id });
  const feature = await made({ title: `Deep feature ${RUN_ID}`, level: 'feature', work_type: 'feature', parent_id: release.id });
  const sub = await made({ title: `Deep sub ${RUN_ID}`, level: 'sub-feature', work_type: 'task', parent_id: feature.id });

  expect((await move(release, 'archive')).status()).toBe(200);
  expect((await move(release, 'delete-archived')).status()).toBe(200);

  expect((await admin.api.get(`/work-items/${feature.id}`)).item.parent_id).toBe(project.id);
  expect((await admin.api.get(`/work-items/${sub.id}`)).item.parent_id).toBe(feature.id);
});

test('deleting a release that holds support hours gives the hours back', async ({ browser, baseURL }) => {
  test.slow();

  const { client, site: paid } = await Forge.makeSite(admin.api, 'Archive Hours Co', RUN_ID);
  await Forge.onSupport(admin, paid.id, 200);
  const people = await Forge.team(admin.api, browser, baseURL, client.id);

  const project = (await (await Forge.makeItem(admin.api, paid.id, { title: `Paid ${RUN_ID}`, level: 'project', work_type: 'feature' })).json()).item;
  const release = (await (await Forge.makeItem(admin.api, paid.id, { title: `Paid release ${RUN_ID}`, level: 'milestone', work_type: 'feature', parent_id: project.id })).json()).item;

  const planned = await Forge.walkTo(admin.api, release, ['triage', 'documentation-period', 'up-next'], {
    seats: {
      primary_user_id: people.primary.id,
      reviewer_id: people.reviewer.id,
      deliverer_id: people.deliverer.id,
      planned_start: '2026-10-05',
      planned_due: '2026-10-09',
      hours_primary: 10,
    },
  });
  expect(planned.stage).toBe('up-next');

  const held = await Forge.hourLedger(admin, paid.id);
  expect(held.balance).toBeLessThan(200);

  const current = (await admin.api.get(`/work-items/${release.id}`)).item;
  expect((await admin.api.post(`/work-items/${release.id}/archive`, { record_version: current.record_version })).status()).toBe(200);
  expect((await move(release, 'delete-archived')).status()).toBe(200);

  const after = await Forge.hourLedger(admin, paid.id);
  expect(after.balance).toBe(200);
  expect(after.entries.some(([type]) => 'work-release' === type)).toBe(true);
});
