import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #563. The studio-wide Projects page. The instance is kept between runs and
// holds old projects, so every name carries a run id and each test looks for
// the rows it made, never for totals.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

const SLOW = { timeout: 60_000 };
const THROUGH_REVIEW = ['triage', 'documentation-period', 'up-next', 'design-process', 'in-development', 'in-review', 'completed'];

let admin;
let one;
let two;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  one = await Forge.makeSite(admin.api, 'Projects Page One', RUN_ID);
  two = await Forge.makeSite(admin.api, 'Projects Page Two', RUN_ID);
});

test.afterAll(async () => {
  await admin?.context.close();
});

async function made(site, data) {
  const answer = await Forge.makeItem(admin.api, site.id, data);

  expect(answer.status(), await answer.text()).toBe(200);

  return (await answer.json()).item;
}

const rowFor = (page, title) => page.getByTestId('bwx-projects-list').getByRole('row').filter({ hasText: title });

async function openPage(page) {
  await page.goto('/blueworx-forge/#screen=projects');
  await expect(page.getByTestId('bwx-projects-list')).toBeVisible({ timeout: 30_000 });
}

test('lists projects across clients and filters by client', async () => {
  const a = await made(one.site, { title: `Alpha ${RUN_ID}`, level: 'project' });
  const b = await made(two.site, { title: `Bravo ${RUN_ID}`, level: 'project' });

  const page = await admin.context.newPage();

  await openPage(page);
  await page.selectOption('[data-testid="bwx-client-choice"]', 'all');

  await expect(rowFor(page, a.title)).toContainText(one.client.display_name, SLOW);
  await expect(rowFor(page, b.title)).toContainText(two.client.display_name, SLOW);

  await page.selectOption('[data-testid="bwx-client-choice"]', one.site.id);
  await expect(rowFor(page, a.title)).toBeVisible(SLOW);
  await expect(rowFor(page, b.title)).toHaveCount(0, SLOW);

  // The menu lights Projects on this page.
  await expect(page.getByTestId('bwx-screen-projects')).toHaveAttribute('aria-current', 'page');

  await page.selectOption('[data-testid="bwx-client-choice"]', 'all');
  await page.close();
});

test('opens a project from its row', async () => {
  const project = await made(one.site, { title: `Opened ${RUN_ID}`, level: 'project' });
  const page = await admin.context.newPage();

  await openPage(page);
  await page.selectOption('[data-testid="bwx-client-choice"]', one.site.id);
  await rowFor(page, project.title).click();

  await expect(page.getByTestId('bwx-project')).toContainText(project.title, { timeout: 30_000 });
  // The project's page lights Projects, not Clients.
  await expect(page.getByTestId('bwx-screen-projects')).toHaveAttribute('aria-current', 'page');

  await page.selectOption('[data-testid="bwx-client-choice"]', 'all');
  await page.close();
});

test('a project with nothing in it reads Ongoing and No work yet', async () => {
  const empty = await made(one.site, { title: `Empty ${RUN_ID}`, level: 'project' });
  const full = await made(one.site, { title: `Full ${RUN_ID}`, level: 'project' });
  await made(one.site, { title: `Release ${RUN_ID}`, level: 'milestone', parent_id: full.id, planned_start: '2026-07-01', planned_due: '2026-07-24' });

  const page = await admin.context.newPage();

  await openPage(page);
  await page.selectOption('[data-testid="bwx-client-choice"]', one.site.id);

  const row = rowFor(page, empty.title);
  await expect(row).toContainText('Ongoing', SLOW);
  await expect(row).toContainText('No work yet');
  await expect(row).not.toContainText('NaN');
  await expect(row).not.toContainText('0%');

  const other = rowFor(page, full.title);
  await expect(other).toContainText('0 of 1 done', SLOW);
  await expect(other).not.toContainText('Ongoing');

  await page.selectOption('[data-testid="bwx-client-choice"]', 'all');
  await page.close();
});

test('completed projects sit behind the Completed switch', async ({ browser, baseURL }) => {
  // Seven stages of gates against a single-threaded server, and a team of three.
  test.setTimeout(480_000);

  const team = await Forge.team(admin.api, browser, baseURL, one.client.id);
  await Forge.onSupport(admin, one.site.id, 200);

  const project = await made(one.site, { title: `Finished ${RUN_ID}`, level: 'project' });
  const current = await Forge.walkTo(admin.api, project, [...THROUGH_REVIEW, 'released'], { seats: team.seats, as: team.as });
  expect(current.stage).toBe('released');

  const dropped = await made(one.site, { title: `Dropped ${RUN_ID}`, level: 'project' });
  const ended = await admin.api.post(`/work-items/${dropped.id}/outcome`, { outcome: 'cancelled', reason: 'Not needed.', record_version: dropped.record_version });
  expect(ended.status(), await ended.text()).toBe(200);

  const open = await made(one.site, { title: `Still going ${RUN_ID}`, level: 'project' });

  const page = await admin.context.newPage();

  await openPage(page);
  await page.selectOption('[data-testid="bwx-client-choice"]', one.site.id);

  await expect(page.getByTestId('bwx-projects-active')).toHaveAttribute('aria-pressed', 'true');
  await expect(rowFor(page, open.title)).toBeVisible(SLOW);
  await expect(rowFor(page, project.title)).toHaveCount(0);
  await expect(rowFor(page, dropped.title)).toHaveCount(0);

  await page.getByTestId('bwx-projects-completed').click();
  await expect(rowFor(page, project.title)).toBeVisible(SLOW);
  await expect(rowFor(page, dropped.title)).toBeVisible();
  await expect(rowFor(page, open.title)).toHaveCount(0);

  await page.getByTestId('bwx-projects-active').click();
  await expect(rowFor(page, open.title)).toBeVisible();

  await page.selectOption('[data-testid="bwx-client-choice"]', 'all');
  await team.close();
  await page.close();
});
