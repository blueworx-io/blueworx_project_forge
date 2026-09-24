import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// The workflow rules of 2026-09-19: two people before triage, approvals that
// are the reviewer's, dates that keep their order, a checklist that has to be
// finished (how to test and the design link optional since 2026-09-24), links, images and a
// dependencies card.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let client;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client, site } = await Forge.makeSite(admin.api, 'Rules Co', RUN_ID));
  await Forge.onSupport(admin, site.id, 200);
});

test.afterAll(async () => {
  await admin?.context.close();
});

async function fresh(title, extra = {}) {
  return (await (await Forge.makeItem(admin.api, site.id, { title, ...extra })).json()).item;
}

async function patch(item, values) {
  const current = (await admin.api.get(`/work-items/${item.id}`)).item;

  return admin.api.patch(`/work-items/${item.id}`, { ...values, record_version: current.record_version });
}

test('an idea cannot leave Captured until somebody is doing it and somebody is reviewing it', async () => {
  const item = await fresh(`Two people ${RUN_ID}`);
  const detail = await admin.api.get(`/work-items/${item.id}`);
  const ids = detail.readiness.triage.unmet.map((row) => row.id);

  expect(ids).toContain('G-FUTURE-IDEA-5');
  expect(ids).toContain('G-FUTURE-IDEA-6');

  // Nothing at triage asks about a parent any more.
  expect(Object.values(detail.readiness).flatMap((one) => one.all.map((row) => row.id))).not.toContain('G-TRIAGE-3');

  const triaged = await Forge.walkTo(admin.api, item, ['triage']);
  expect(triaged.primary_user_id).not.toBe('');
  expect(triaged.reviewer_id).not.toBe('');
});

test('the approvals belong to the reviewer, and an administrator may stand in', async ({ browser, baseURL }) => {
  test.slow();

  const crew = await Forge.team(admin.api, browser, baseURL, client.id);
  const item = await fresh(`Reviewer signs ${RUN_ID}`);
  const atDocs = await Forge.walkTo(admin.api, item, ['triage', 'documentation-period'], { seats: crew.seats });

  // The audit is two rows now: risks, and the reviewer's approval.
  const detail = await admin.api.get(`/work-items/${atDocs.id}`);
  const audit = detail.readiness['technical-audit'].all.map((row) => row.id);
  expect(detail.readiness['technical-audit'].all.find((row) => 'G-DOCUMENTATION-9' === row.id).who).toBe('REV');

  // The person doing the work is refused; the reviewer is not.
  const asPrimary = await Forge.signedIn(browser, baseURL, crew.primary.login, Forge.PASSWORD);
  const refused = await asPrimary.api.post(`/work-items/${atDocs.id}/gate`, { requirement: 'G-DOCUMENTATION-9', value: 'approved', evidence: '' });
  expect(refused.status()).toBe(403);
  expect(await refused.text()).toContain('reviewer');
  await asPrimary.context.close();

  const asReviewer = await Forge.signedIn(browser, baseURL, crew.reviewer.login, Forge.PASSWORD);
  const approved = await asReviewer.api.post(`/work-items/${atDocs.id}/gate`, { requirement: 'G-DOCUMENTATION-9', value: 'approved', evidence: '' });
  expect(approved.status(), await approved.text()).toBe(200);
  await asReviewer.context.close();

  // An administrator answers the technical approval for them.
  const atAudit = await Forge.walkTo(admin.api, atDocs, ['technical-audit'], { seats: crew.seats });
  const after = await admin.api.get(`/work-items/${atAudit.id}`);
  expect(after.readiness['design-process'].all.map((row) => row.id)).toEqual(['G-TECHNICAL-AUDIT-7', 'G-TECHNICAL-AUDIT-8']);
  expect(audit).not.toContain('G-TECHNICAL-AUDIT-1');
  const stoodIn = await admin.api.post(`/work-items/${atAudit.id}/gate`, { requirement: 'G-TECHNICAL-AUDIT-8', value: 'approved', evidence: '' });
  expect(stoodIn.status(), await stoodIn.text()).toBe(200);

  await crew.close();
});

test('the dates keep their order, whichever one is sent', async () => {
  const item = await fresh(`Dates ${RUN_ID}`);

  const backwards = await patch(item, { planned_start: '2026-09-10', planned_due: '2026-09-05' });
  expect(backwards.status()).toBe(400);
  expect((await backwards.json()).data.fields.planned_due).toContain('before');

  expect((await patch(item, { planned_start: '2026-09-10', planned_due: '2026-09-20' })).status()).toBe(200);

  // A single date is held against the ones already there.
  const early = await patch(item, { release_target: '2026-09-15' });
  expect(early.status()).toBe(400);
  expect((await early.json()).data.fields.release_target).toContain('before');

  // The same day is fine, and so is the past.
  expect((await patch(item, { review_target: '2026-09-20', release_target: '2026-09-20' })).status()).toBe(200);
  expect((await patch(item, { planned_start: '2020-01-01' })).status()).toBe(200);
});

test('leaving In Development takes a finished checklist, and neither how to test, evidence nor hours still to do', async ({ browser, baseURL }) => {
  test.slow();

  const crew = await Forge.team(admin.api, browser, baseURL, client.id);
  const item = await fresh(`Finish it ${RUN_ID}`);
  const path = ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next', 'in-development'];
  const developing = await Forge.walkTo(admin.api, item, path, { seats: crew.seats });

  await patch(developing, { checklist: [{ text: 'Wire it up', done: false }] });
  const before = await admin.api.get(`/work-items/${developing.id}`);
  const ids = before.readiness['in-review'].unmet.map((row) => row.id);
  expect(ids).toContain('G-IN-DEVELOPMENT-1');
  // How to test and work evidence are not asked for (2026-09-24).
  expect(before.readiness['in-review'].all.map((row) => row.id)).not.toContain('G-IN-DEVELOPMENT-3');
  expect(before.readiness['in-review'].all.map((row) => row.id)).not.toContain('G-IN-DEVELOPMENT-2');
  expect(before.readiness['in-review'].all.map((row) => row.id)).not.toContain('G-IN-DEVELOPMENT-4');
  expect(before.readiness['in-review'].all.map((row) => row.id)).not.toContain('G-IN-DEVELOPMENT-5');
  expect(before.item.remaining_estimate).toBeUndefined();

  await patch(developing, {
    checklist: [{ text: 'Wire it up', done: true }],
    test_steps: [{ text: 'Open the page', done: false }, { text: 'Press the button', done: false }],
  });

  const after = await admin.api.get(`/work-items/${developing.id}`);
  expect(after.readiness['in-review'].unmet).toEqual([]);
  expect(after.item.test_steps).toHaveLength(2);

  await crew.close();
});

test('links are saved with the task, an image goes up on its own, and dependencies come from a switch', async () => {
  test.slow();

  const item = await fresh(`Extras ${RUN_ID}`);
  const other = await fresh(`Waited on ${RUN_ID}`);

  const linked = await patch(item, { links: [{ label: 'The brief', url: 'https://example.test/brief' }, { label: '', url: 'https://example.test/notes' }] });
  expect(linked.status(), await linked.text()).toBe(200);
  expect((await linked.json()).item.links).toEqual([
    { label: 'The brief', url: 'https://example.test/brief' },
    { label: '', url: 'https://example.test/notes' },
  ]);

  const notAnAddress = await patch(item, { links: [{ label: 'Nope', url: 'brief.pdf' }] });
  expect(notAnAddress.status()).toBe(400);

  // A one-pixel PNG, as a real upload.
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
  const uploaded = await admin.api.request.post(`/wp-json/blueworx-forge/v1/work-items/${item.id}/images`, {
    headers: admin.api.headers,
    multipart: { image: { name: 'dot.png', mimeType: 'image/png', buffer: png } },
  });
  expect(uploaded.status(), await uploaded.text()).toBe(200);
  const withImage = (await uploaded.json()).item;
  expect(withImage.images).toHaveLength(1);
  expect(withImage.images[0].url).toContain('dot');

  const removed = await admin.api.request.delete(`/wp-json/blueworx-forge/v1/work-items/${item.id}/images/${withImage.images[0].id}`, { headers: admin.api.headers });
  expect(removed.status()).toBe(200);
  expect((await removed.json()).item.images).toEqual([]);

  // The dependencies card, on the panel.
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: `Extras ${RUN_ID}` }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  const toggle = page.getByTestId('bwx-dependencies-toggle');
  await expect(toggle).not.toBeChecked();
  await expect(page.getByTestId('bwx-dependency-add')).toHaveCount(0);

  await toggle.click();
  await page.getByTestId('bwx-dependency-add').selectOption(other.id);
  await expect(page.getByTestId('bwx-dependency')).toHaveCount(1, { timeout: 30_000 });
  expect((await admin.api.get(`/work-items/${item.id}`)).dependencies.upstream.map((row) => row.id)).toEqual([other.id]);

  // The links the save carries.
  await expect(page.getByTestId('bwx-link-row')).toHaveCount(2);

  // Off again lets go of them.
  await toggle.click();
  await expect(page.getByTestId('bwx-dependency')).toHaveCount(0, { timeout: 30_000 });
  await expect.poll(async () => (await admin.api.get(`/work-items/${item.id}`)).dependencies.upstream.length, { timeout: 30_000 }).toBe(0);

  await page.close();
});

test('a before-row takes you to its field, and a checklist counts what is ticked', async () => {
  test.slow();

  await fresh(`Go to ${RUN_ID}`);
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: `Go to ${RUN_ID}` }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  // Fold Who and when away, then let the row unfold it and land on the seat.
  await page.getByTestId('bwx-section-assign').click();
  await expect(page.locator('#bwx-primary_user_id')).toBeHidden();
  await page.locator('[data-requirement="G-FUTURE-IDEA-5"] [data-testid="bwx-unmet-go"]').click();
  await expect(page.locator('#bwx-primary_user_id')).toBeVisible();
  await expect(page.locator('#bwx-primary_user_id')).toBeFocused();

  // No count until there is a line; then it says how many are ticked.
  await expect(page.getByTestId('bwx-checklist-count')).toHaveCount(0);
  await page.getByTestId('bwx-checklist-add').click();
  await page.getByTestId('bwx-checklist-text').first().fill('One thing');
  await expect(page.getByTestId('bwx-checklist-count')).toHaveText('0 of 1 done');
  await page.getByTestId('bwx-checklist-done').first().check();
  await expect(page.getByTestId('bwx-checklist-count')).toHaveText('1 of 1 done');

  await page.close();
});

test('work with something outstanding is on the standup from the day it is captured', async () => {
  const item = await fresh(`Early bird ${RUN_ID}`);
  const board = await admin.api.get('/standup');
  const card = board.cards.find((one) => 'gate-unmet' === one.rule && one.subject_id === item.id);

  expect(card, 'a captured item with unmet requirements is on the board').toBeTruthy();
});
