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
