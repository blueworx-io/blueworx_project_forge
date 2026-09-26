import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #390: confirm a task's client before triage, and change it while work has
// not started.
//
// Tasks get added under the wrong client. A new idea's client is now
// confirmed on purpose before it goes to triage, and a task on the wrong
// client can be moved to the right one until In Development, where its hours
// start counting against the client.
//
// The instance is kept between runs, so every name carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

let admin;
let from;
let to;
let both;
let onlyFrom;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  from = await Forge.makeSite(admin.api, `Wrong Co ${RUN_ID}`, RUN_ID);
  to = await Forge.makeSite(admin.api, `Right Co ${RUN_ID}`, RUN_ID);

  // One of our people on both clients, and one on the first alone.
  both = await Forge.makePerson(admin.api, from.client.id, 'staff', `Both-${RUN_ID}`);
  const joined = await admin.api.post(`/clients/${to.client.id}/memberships`, { user_id: both.id, role: 'staff' });
  expect(joined.status(), await joined.text()).toBe(200);
  onlyFrom = await Forge.makePerson(admin.api, from.client.id, 'staff', `OnlyFrom-${RUN_ID}`);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(() => {
  test.slow();
});

/** A new idea on the first client, with everything but the client ready for triage. */
async function readyIdea(title, values = {}) {
  const made = await Forge.makeItem(admin.api, from.site.id, {
    title: `${title} ${RUN_ID}`,
    primary_user_id: both.id,
    reviewer_id: onlyFrom.id,
    ...values,
  });
  expect(made.status(), await made.text()).toBe(200);

  return (await made.json()).item;
}

/** The item as it now stands. */
async function current(id) {
  return (await admin.api.get(`/work-items/${id}`)).item;
}

/** Asks to move an item to a site. */
async function moveClient(api, item, siteId) {
  return api.post(`/work-items/${item.id}/move-client`, {
    client_site_id: siteId,
    record_version: item.record_version,
  });
}

test('a new idea waits for its client to be confirmed, then goes to triage', async () => {
  const item = await readyIdea('Unconfirmed');

  expect(item.client_confirmed_at).toBe(0);

  // Everything else is done; the confirmation is what is left.
  await admin.api.post(`/work-items/${item.id}/gate`, { requirement: 'G-FUTURE-IDEA-3', value: 'client-request' });
  let ready = await current(item.id);

  const refused = await admin.api.post(`/work-items/${item.id}/transition`, { to: 'triage', record_version: ready.record_version });
  expect(refused.status()).toBe(409);
  expect((await refused.json()).unmet.map((each) => each.id)).toEqual(['G-FUTURE-IDEA-7']);

  const confirmed = await admin.api.post(`/work-items/${item.id}/confirm-client`, { record_version: ready.record_version });
  expect(confirmed.status(), await confirmed.text()).toBe(200);
  ready = (await confirmed.json()).item;
  expect(ready.client_confirmed_at).toBeGreaterThan(0);
  expect(ready.client_confirmed_by).toBeGreaterThan(0);

  const moved = await admin.api.post(`/work-items/${item.id}/transition`, { to: 'triage', record_version: ready.record_version });
  expect(moved.status(), await moved.text()).toBe(200);

  const history = (await admin.api.get(`/work-items/${item.id}`)).history;
  expect(history.map((event) => event.action)).toContain('client-confirmed');
});

test('an up-next task moves to another client, taking its hours and leaving behind who cannot work there', async () => {
  await Forge.onSupport(admin, from.site.id, 200);
  await Forge.onSupport(admin, to.site.id, 200);

  const item = await readyIdea('Planned', {
    deliverer_id: both.id,
    commercial_class: 'chargeable',
    hours_primary: 4,
    hours_review: 2,
    hours_delivery: 1,
  });

  const jumped = await admin.api.post(`/work-items/${item.id}/override`, {
    to: 'up-next',
    reason: 'Came in part way along.',
    record_version: item.record_version,
  });
  expect(jumped.status(), await jumped.text()).toBe(200);
  const planned = (await jumped.json()).item;

  const fromBefore = (await Forge.hourLedger(admin, from.site.id)).balance;
  const toBefore = (await Forge.hourLedger(admin, to.site.id)).balance;

  const moved = await moveClient(admin.api, planned, to.site.id);
  expect(moved.status(), await moved.text()).toBe(200);
  const answer = await moved.json();

  expect(answer.item.client_site_id).toBe(to.site.id);
  expect(answer.item.client_id).toBe(to.client.id);
  expect(answer.item.client_confirmed_at).toBeGreaterThan(0);

  // The reviewer has no access to the new client, so the seat is cleared and named.
  expect(answer.item.reviewer_id).toBe('');
  expect(answer.item.primary_user_id).toBe(both.id);
  expect(answer.taken_off).toEqual([{ id: onlyFrom.id, name: `OnlyFrom-${RUN_ID}` }]);

  // The reserved hours went back to the first client and are held by the second.
  expect((await Forge.hourLedger(admin, from.site.id)).balance).toBe(fromBefore + 7);
  expect((await Forge.hourLedger(admin, to.site.id)).balance).toBe(toBefore - 7);

  // Its history came with it, with the move on the end.
  const detail = await admin.api.get(`/work-items/${item.id}`);
  const last = detail.history[detail.history.length - 1];
  expect(last.action).toBe('client-moved');
  expect(last.detail).toBe(`Taken off: OnlyFrom-${RUN_ID}`);
  expect(last.reason).toContain(`Wrong Co ${RUN_ID}`);
  expect(last.reason).toContain(`Right Co ${RUN_ID}`);

  const onNew = await admin.api.get(`/work-items?client_site_id=${to.site.id}`);
  expect(onNew.items.map((each) => each.id)).toContain(item.id);
  const onOld = await admin.api.get(`/work-items?client_site_id=${from.site.id}`);
  expect(onOld.items.map((each) => each.id)).not.toContain(item.id);
});

test('a task that has started keeps its client', async () => {
  const item = await readyIdea('Started');
  const jumped = await admin.api.post(`/work-items/${item.id}/override`, {
    to: 'in-development',
    reason: 'Already under way.',
    record_version: item.record_version,
  });
  expect(jumped.status(), await jumped.text()).toBe(200);

  const refused = await moveClient(admin.api, (await jumped.json()).item, to.site.id);
  expect(refused.status()).toBe(409);
  const body = await refused.json();
  expect(body.code).toBe('bwx_forge_work_started');
  expect(body.message).toBe("This task has started, so its client can't change. Hours are already counted against this client.");
  expect((await current(item.id)).client_site_id).toBe(from.site.id);
});

test('a task linked to other work moves on its own first', async () => {
  const parent = await readyIdea('Parent', { level: 'project' });
  const child = await readyIdea('Child', { parent_id: parent.id });
  const waits = await readyIdea('Waits');
  const upstream = await readyIdea('Upstream');

  const linked = await admin.api.post(`/work-items/${waits.id}/dependencies`, { depends_on_id: upstream.id });
  expect(linked.status(), await linked.text()).toBe(200);

  for (const item of [parent, child, waits, upstream]) {
    const refused = await moveClient(admin.api, await current(item.id), to.site.id);
    expect(refused.status(), item.title).toBe(409);
    expect((await refused.json()).message).toBe('Move it on its own first: it is linked to other work.');
  }
});

test('a client the caller cannot reach is not somewhere they can move work to', async ({ browser, baseURL }) => {
  const item = await readyIdea('Out of reach');
  const staff = await Forge.makePerson(admin.api, from.client.id, 'staff', `Staff-${RUN_ID}`);
  const signed = await Forge.signedIn(browser, baseURL, staff.login, Forge.PASSWORD);

  const refused = await moveClient(signed.api, item, to.site.id);
  expect(refused.status()).toBe(404);
  expect((await current(item.id)).client_site_id).toBe(from.site.id);

  // Confirming is theirs to do on work they can edit.
  const confirmed = await signed.api.post(`/work-items/${item.id}/confirm-client`, { record_version: item.record_version });
  expect(confirmed.status(), await confirmed.text()).toBe(200);

  await signed.context.close();
});

test('the panel confirms the client, and changes it with a picker', async () => {
  const item = await readyIdea('Panel');
  const page = await admin.context.newPage();

  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', from.site.id);
  await page.getByTestId('bwx-card').filter({ hasText: `Panel ${RUN_ID}` }).click();

  const box = page.getByTestId('bwx-client-confirm');
  await expect(box).toContainText(`Wrong Co ${RUN_ID}`);
  await expect(page.getByTestId('bwx-card').filter({ hasText: `Panel ${RUN_ID}` }).getByTestId('bwx-card-unconfirmed')).toBeVisible();

  await box.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByTestId('bwx-client-confirm')).toHaveCount(0, { timeout: 30_000 });
  await expect(page.getByTestId('bwx-client-line')).toContainText(`Wrong Co ${RUN_ID}`);

  await page.getByTestId('bwx-client-line').getByRole('button', { name: 'Change' }).click();
  await page.getByTestId('bwx-client-site').selectOption(to.site.id);
  await page.getByTestId('bwx-client-save').click();

  await expect(page.getByTestId('bwx-client-taken-off')).toHaveText(`Taken off: OnlyFrom-${RUN_ID} — they don't work on this client.`, { timeout: 30_000 });
  await expect(page.getByTestId('bwx-client-line')).toContainText(`Right Co ${RUN_ID}`);
  expect((await current(item.id)).client_site_id).toBe(to.site.id);

  await page.close();
});

test("the new client's own people do not read which client the task came from", async ({ browser, baseURL }) => {
  const item = await readyIdea('Private move');
  const confirmed = await Forge.confirmClient(admin.api, item);
  const moved = await moveClient(admin.api, confirmed, to.site.id);
  expect(moved.status(), await moved.text()).toBe(200);

  // Staff still read the whole story.
  const staffView = await admin.api.get(`/work-items/${item.id}`);
  expect(staffView.history.map((event) => event.action)).toContain('client-moved');

  const theirs = await Forge.makePerson(admin.api, to.client.id, 'client_admin', `Theirs-${RUN_ID}`);
  const signed = await Forge.signedIn(browser, baseURL, theirs.login, Forge.PASSWORD);
  const read = await signed.api.request.get(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, { headers: signed.api.headers });

  expect(read.status(), await read.text()).toBe(200);
  const body = await read.text();
  expect(body).not.toContain(`Wrong Co ${RUN_ID}`);
  const actions = JSON.parse(body).history.map((event) => event.action);
  expect(actions).not.toContain('client-moved');
  expect(actions).not.toContain('client-confirmed');

  await signed.context.close();
});

test('a move the new client cannot pay for is refused, and changes nothing', async () => {
  const broke = await Forge.makeSite(admin.api, `Broke Co ${RUN_ID}`, RUN_ID);
  await Forge.onSupport(admin, from.site.id, 200);

  const item = await readyIdea('Unaffordable', {
    deliverer_id: both.id,
    commercial_class: 'chargeable',
    hours_primary: 4,
    hours_review: 2,
    hours_delivery: 1,
  });
  const jumped = await admin.api.post(`/work-items/${item.id}/override`, {
    to: 'up-next',
    reason: 'Came in part way along.',
    record_version: item.record_version,
  });
  expect(jumped.status(), await jumped.text()).toBe(200);
  const planned = (await jumped.json()).item;

  const before = await admin.api.get(`/work-items/${item.id}`);
  const fromLedger = await Forge.hourLedger(admin, from.site.id);
  const brokeLedger = await admin.api.get(`/client-sites/${broke.site.id}/support`);

  const refused = await moveClient(admin.api, planned, broke.site.id);
  expect(refused.status()).toBe(409);
  expect((await refused.json()).code).toBe('bwx_forge_hours_not_available');

  const after = await admin.api.get(`/work-items/${item.id}`);
  expect(after.item).toEqual(before.item);
  expect(after.history).toHaveLength(before.history.length);
  expect(await Forge.hourLedger(admin, from.site.id)).toEqual(fromLedger);
  expect(await admin.api.get(`/client-sites/${broke.site.id}/support`)).toEqual(brokeLedger);
});

test("work the client asked for, or can see comments on, stays with them", async ({ request }) => {
  const asked = await Forge.makeSite(admin.api, `Asking Co ${RUN_ID}`, RUN_ID);
  const site = await Forge.asClientSite(admin.api, asked.site.id, request);
  const submission = await Forge.makeSubmission(site, { title: `Asked for ${RUN_ID}` });
  const converted = await admin.api.post(`/submissions/${submission.id}/conversion`, { entry_stage: 'future-idea' });
  expect(converted.status(), await converted.text()).toBe(200);

  const requested = await moveClient(admin.api, (await converted.json()).item, to.site.id);
  expect(requested.status()).toBe(409);
  expect((await requested.json()).code).toBe('bwx_forge_work_requested');

  const seen = await readyIdea('Seen');
  const said = await admin.api.post(`/work-items/${seen.id}/comments`, {
    body: 'Hello from us.',
    kind: 'comment',
    visibility: 'client',
  });
  expect(said.status(), await said.text()).toBe(200);

  const refused = await moveClient(admin.api, await current(seen.id), to.site.id);
  expect(refused.status()).toBe(409);
  expect((await refused.json()).code).toBe('bwx_forge_client_has_seen_it');
});
