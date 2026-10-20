import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #564. A release is reviewed as a whole once its features are done. Bugs and
// feedback raised in that review have to be completed before it can pass.
//
// The instance is kept between runs, so every name carries a run id and every
// test makes what it needs.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

const SLOW = { timeout: 60_000 };

const TO_DEVELOPMENT = ['triage', 'documentation-period', 'up-next', 'design-process', 'in-development'];
const TO_COMPLETED = [...TO_DEVELOPMENT, 'in-review', 'completed'];
const BUG_TO_COMPLETED = ['triage', 'bug-tracking', 'documentation-period', 'up-next', 'design-process', 'in-development', 'in-review', 'completed'];

let admin;
let client;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client, site } = await Forge.makeSite(admin.api, 'Release Review Co', RUN_ID));
  await Forge.onSupport(admin, site.id, 400);
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

/** A project with one release, and one feature in that release. */
async function aRelease(name) {
  const project = await made({ title: `${name} project ${RUN_ID}`, level: 'project' });
  const release = await made({ title: `${name} ${RUN_ID}`, level: 'milestone', parent_id: project.id });
  const feature = await made({ title: `${name} feature ${RUN_ID}`, level: 'feature', parent_id: release.id });

  return { project, release, feature };
}

/** Asks the release's reviewer to approve its review. */
async function approve(team, release) {
  const current = await Forge.satisfy(admin.api, release, 'completed', team.seats);

  return team.as.completed.post(`/work-items/${current.id}/transition`, {
    to: 'completed',
    record_version: current.record_version,
  });
}

test('raising a fix in review blocks approval until it is completed', async ({ browser, baseURL }) => {
  // A feature, a release and a bug walked through every gate, one server thread.
  test.setTimeout(1_200_000);

  const team = await Forge.team(admin.api, browser, baseURL, client.id);
  const { project, release, feature } = await aRelease('Reviewed');

  await Forge.walkTo(admin.api, feature, TO_COMPLETED, { seats: team.seats, as: team.as });
  const inReview = await Forge.walkTo(admin.api, release, [...TO_DEVELOPMENT, 'in-review'], { seats: team.seats, as: team.as });
  expect(inReview.stage).toBe('in-review');

  const page = await admin.context.newPage();
  const bugTitle = `Wrong colour ${RUN_ID}`;

  await page.goto(`/blueworx-forge/#screen=project&project=${project.id}`);
  const row = page.getByTestId('bwx-project-release').filter({ hasText: release.title });
  await expect(row).toBeVisible({ timeout: 30_000 });
  await row.getByTestId('bwx-project-toggle').click();

  const box = row.getByTestId('bwx-release-review');
  await expect(box).toBeVisible(SLOW);
  await box.locator('[data-testid="bwx-release-raise"][data-type="bug"]').click();

  const form = page.getByTestId('bwx-new-work');
  await expect(form).toBeVisible();
  await expect(form.getByTestId('bwx-new-type-fixed')).toHaveValue('Bug');
  await form.getByLabel('Title').fill(bugTitle);
  await form.getByTestId('bwx-create').click();
  await expect(form).toBeHidden(SLOW);

  await expect(box).toContainText(bugTitle, SLOW);
  await expect(box.getByTestId('bwx-release-review-count')).toHaveText('0 of 1 done before this can be released');
  // It shows in the review box and not again with the release's other work.
  await expect(row.getByTestId('bwx-project-node').filter({ hasText: bugTitle })).toHaveCount(0);

  const detail = await admin.api.get(`/work-items/${release.id}`);
  const bug = detail.children.find((one) => one.title === bugTitle);
  expect(bug.review_of).toBe(release.id);
  expect(bug.work_type).toBe('bug');

  // Closed before the long walk, so its background reads don't slow the server.
  await page.close();

  const refused = await approve(team, release);
  expect(refused.status(), await refused.text()).toBe(409);
  expect((await refused.json()).unmet.map((one) => one.label)).toContain('Review fixes completed');

  await Forge.walkTo(admin.api, bug, BUG_TO_COMPLETED, { seats: team.seats, as: team.as });

  const approved = await approve(team, release);
  expect(approved.status(), await approved.text()).toBe(200);
  expect((await approved.json()).item.stage).toBe('completed');

  await team.close();
});

test('only bugs and feedback, only while in review', async ({ browser, baseURL }) => {
  test.setTimeout(600_000);

  const team = await Forge.team(admin.api, browser, baseURL, client.id);
  const { release, feature } = await aRelease('Gated');

  await Forge.walkTo(admin.api, feature, TO_COMPLETED, { seats: team.seats, as: team.as });
  const developing = await Forge.walkTo(admin.api, release, TO_DEVELOPMENT, { seats: team.seats, as: team.as });
  expect(developing.stage).toBe('in-development');

  const early = await Forge.makeItem(admin.api, site.id, {
    title: `Too early ${RUN_ID}`,
    level: 'sub-feature',
    work_type: 'bug',
    parent_id: release.id,
    review_of: release.id,
  });
  expect(early.status()).toBe(400);
  expect((await early.json()).code).toBe('bwx_forge_invalid_review_fix');

  await Forge.walkTo(admin.api, developing, ['in-review'], { seats: team.seats, as: team.as });

  const task = await Forge.makeItem(admin.api, site.id, {
    title: `A task ${RUN_ID}`,
    level: 'sub-feature',
    work_type: 'task',
    parent_id: release.id,
    review_of: release.id,
  });
  expect(task.status()).toBe(400);
  const body = await task.json();
  expect(body.code).toBe('bwx_forge_invalid_review_fix');
  expect(body.message).toBe("Only bugs and feedback can be raised in a release's review, while it is in review.");

  await team.close();
});

test('a feature can be released before its release is', async ({ browser, baseURL }) => {
  test.setTimeout(600_000);

  const team = await Forge.team(admin.api, browser, baseURL, client.id);
  const { release, feature } = await aRelease('Early');

  await Forge.walkTo(admin.api, release, TO_DEVELOPMENT, { seats: team.seats, as: team.as });
  const released = await Forge.walkTo(admin.api, feature, [...TO_COMPLETED, 'released'], { seats: team.seats, as: team.as });

  expect(released.stage).toBe('released');
  expect((await admin.api.get(`/work-items/${release.id}`)).item.stage).toBe('in-development');

  await team.close();
});
