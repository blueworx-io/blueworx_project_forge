import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #391: the client as a task's reviewer.
//
// "The client" can review from Up Next on. Their review time counts against
// nobody's capacity and not against the support allowance. An admin can
// record the client's answer for them, approving or sending it back.
//
// The instance is kept between runs, so every name carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const FROM = '2031-03-03';
const TO = '2031-03-07';

let admin;
let made;
let primary;
let deliverer;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  made = await Forge.makeSite(admin.api, `Reviewing Co ${RUN_ID}`, RUN_ID);
  primary = await Forge.makePerson(admin.api, made.client.id, 'staff', `Doer-${RUN_ID}`);
  deliverer = await Forge.makePerson(admin.api, made.client.id, 'staff', `Shipper-${RUN_ID}`);
  await Forge.onSupport(admin, made.site.id, 200);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(() => {
  test.slow();
});

/** The item as it now stands, with everything the panel reads. */
async function detail(id) {
  return admin.api.get(`/work-items/${id}`);
}

/** A task put at a stage for the test, with its people and dates. */
async function taskAt(title, stage) {
  const created = await Forge.makeItem(admin.api, made.site.id, {
    title: `${title} ${RUN_ID}`,
    primary_user_id: primary.id,
    deliverer_id: deliverer.id,
    planned_start: FROM,
    planned_due: TO,
    commercial_class: 'chargeable',
  });
  expect(created.status(), await created.text()).toBe(200);
  const item = (await created.json()).item;

  const moved = await admin.api.post(`/work-items/${item.id}/override`, {
    to: stage,
    reason: 'Set up for the test.',
    record_version: item.record_version,
  });
  expect(moved.status(), await moved.text()).toBe(200);

  return (await moved.json()).item;
}

/** Edits a task. */
function edit(item, values) {
  return admin.api.patch(`/work-items/${item.id}`, { ...values, record_version: item.record_version });
}

/** A task in review, with the client reviewing it. */
async function inClientReview(title) {
  const item = await taskAt(title, 'up-next');
  const edited = await edit(item, { reviewer_id: 'client', hours_primary: 4 });
  expect(edited.status(), await edited.text()).toBe(200);

  const moved = await admin.api.post(`/work-items/${item.id}/override`, {
    to: 'in-review',
    reason: 'Set up for the test.',
    record_version: (await edited.json()).item.record_version,
  });
  expect(moved.status(), await moved.text()).toBe(200);

  return (await moved.json()).item;
}

test('the client can review from Up Next on, and not before', async () => {
  const early = await taskAt('Too early', 'design-process');
  const refused = await edit(early, { reviewer_id: 'client' });

  expect(refused.status()).toBe(400);
  expect((await refused.json()).data.fields.reviewer_id).toBe('The client can be the reviewer from Up Next onwards.');

  const ready = await taskAt('Up next', 'up-next');
  const chosen = await edit(ready, { reviewer_id: 'client' });

  expect(chosen.status(), await chosen.text()).toBe(200);
  expect((await chosen.json()).item.reviewer_id).toBe('client');

  // The client holds no other seat.
  const primaryRefused = await edit((await detail(ready.id)).item, { primary_user_id: 'client' });
  expect(primaryRefused.status()).toBe(400);
});

test('the review takes no hours: none in capacity, none from the allowance', async () => {
  const item = await taskAt('No review hours', 'up-next');
  const before = await Forge.hourLedger(admin, made.site.id);

  const edited = await edit(item, { reviewer_id: 'client', hours_primary: 10, hours_review: 5 });
  expect(edited.status(), await edited.text()).toBe(200);

  const saved = (await edited.json()).item;
  expect(saved.hours_review).toBe(0);
  expect(saved.hours_delivery).toBe(1);

  // The allowance holds the work and the delivery, and nothing for the review.
  const after = await Forge.hourLedger(admin, made.site.id);
  expect(before.balance - after.balance).toBeCloseTo(11, 5);

  // Capacity has nobody called "client", and the review is on nobody's week.
  const capacity = await admin.api.get(`/capacity?from=${FROM}&to=${TO}`);
  expect(capacity.people.some((row) => 'client' === row.user_id)).toBe(false);

  for (const person of [primary, deliverer]) {
    const drill = await admin.api.get(`/capacity/person/${person.id}?from=${FROM}&to=${TO}`);
    const mine = drill.allocations.filter((entry) => entry.item_id === item.id);

    expect(mine.length).toBe(1);
    expect(mine.every((entry) => 'review' !== entry.role)).toBe(true);
  }
});

test('an admin records the client\'s approval, and the item is completed', async () => {
  const item = await inClientReview('Approved by email');

  // Nobody at the studio can approve as the reviewer.
  const moved = await admin.api.post(`/work-items/${item.id}/transition`, { to: 'completed', record_version: item.record_version });
  expect(moved.status()).not.toBe(200);

  const approved = await admin.api.post(`/work-items/${item.id}/client-review`, {
    decision: 'approve',
    record_version: item.record_version,
  });
  expect(approved.status(), await approved.text()).toBe(200);
  expect((await approved.json()).item.stage).toBe('completed');

  const now = await detail(item.id);
  const entry = now.history.find((one) => 'moved' === one.action && 'completed' === one.to_stage);

  expect(entry.via).toBe('client');
  expect(entry.reason).toMatch(/^Approved by the client, recorded by .+ on the client's behalf$/);

  // The approval still counts when it is time to release.
  const releasing = (now.readiness.released?.unmet ?? []).map((row) => row.id);
  expect(releasing).not.toContain('G-COMPLETED-1');

  // A second decision is refused.
  const again = await admin.api.post(`/work-items/${item.id}/client-review`, {
    decision: 'approve',
    record_version: now.item.record_version,
  });
  expect(again.status()).toBe(409);
  expect((await again.json()).message).toBe('Already decided.');
});

test('an admin records the client sending it back, with a note', async () => {
  const item = await inClientReview('Sent back by email');

  const bare = await admin.api.post(`/work-items/${item.id}/client-review`, {
    decision: 'send_back',
    note: ' ',
    record_version: item.record_version,
  });
  expect(bare.status()).toBe(400);

  const back = await admin.api.post(`/work-items/${item.id}/client-review`, {
    decision: 'send_back',
    note: `The logo is the old one ${RUN_ID}`,
    record_version: item.record_version,
  });
  expect(back.status(), await back.text()).toBe(200);

  const now = await detail(item.id);
  expect(now.item.stage).toBe('in-development');
  expect(now.item.review_attempt).toBe(item.review_attempt + 1);

  const entry = now.history.find((one) => 'returned' === one.action);
  expect(entry.via).toBe('client');
  expect(entry.detail).toBe(`The logo is the old one ${RUN_ID}`);
  expect(entry.reason).toMatch(/^Sent back by the client, recorded by /);
});

test('open questions to the client still hold up an approval', async () => {
  const item = await inClientReview('Still asking');

  const asked = await admin.api.post(`/work-items/${item.id}/comments`, { kind: 'question', body: 'Which colour?' });
  expect(asked.status(), await asked.text()).toBe(200);

  const current = (await detail(item.id)).item;
  const refused = await admin.api.post(`/work-items/${item.id}/client-review`, {
    decision: 'approve',
    record_version: current.record_version,
  });
  expect(refused.status()).toBe(409);
  expect(JSON.stringify(await refused.json())).toContain('G-IN-REVIEW-3');
  expect((await detail(item.id)).item.stage).toBe('in-review');
});

test('only an admin can record for the client', async ({ browser, baseURL }) => {
  const item = await inClientReview('Staff cannot');
  const staff = await Forge.signedIn(browser, baseURL, primary.login, Forge.PASSWORD);

  const refused = await staff.api.post(`/work-items/${item.id}/client-review`, {
    decision: 'approve',
    record_version: item.record_version,
  });
  expect(refused.status()).toBe(403);

  await staff.context.close();
});

/** A fresh page on one item's panel: a hash change alone does not reopen it. */
async function panelFor(item, title) {
  const page = await admin.context.newPage();
  await page.goto(`/blueworx-forge/#item=${item.id}`);
  await expect(page.getByTestId('bwx-panel')).toContainText(`${title} ${RUN_ID}`, { timeout: 30_000 });

  return page;
}

test('the panel offers the client as reviewer from Up Next, and records their answer', async () => {
  // Before Up Next the choice is there, greyed out, with the reason.
  const early = await panelFor(await taskAt('Panel early', 'triage'), 'Panel early');
  await expect(early.getByTestId('bwx-reviewer-client')).toBeDisabled();
  await expect(early.getByTestId('bwx-reviewer-client-hint')).toHaveText('The client can be the reviewer from Up Next onwards.');
  await early.close();

  // From Up Next it can be chosen, and the review takes no hours.
  const ready = await taskAt('Panel ready', 'up-next');
  const readyPage = await panelFor(ready, 'Panel ready');
  await expect(readyPage.getByTestId('bwx-reviewer-client')).toBeEnabled();
  await readyPage.locator('#bwx-reviewer_id').selectOption('client');
  await expect(readyPage.getByTestId('bwx-client-no-hours')).toBeVisible();
  await readyPage.getByTestId('bwx-save').click();
  await expect.poll(async () => (await detail(ready.id)).item.reviewer_id, { timeout: 30_000 }).toBe('client');
  await readyPage.close();

  // In review, the rows wait on the client and an admin can record for them.
  const waiting = await inClientReview('Panel review');
  const page = await panelFor(waiting, 'Panel review');
  await expect(page.getByTestId('bwx-waiting-on-client').first()).toHaveText('Waiting on the client to review');
  await expect(page.getByTestId('bwx-client-reviewing')).toBeVisible();
  await expect(page.locator('[data-testid="bwx-move"][data-to="completed"]')).toHaveCount(0);

  await page.getByTestId('bwx-client-approved').click();
  await expect.poll(async () => (await detail(waiting.id)).item.stage, { timeout: 30_000 }).toBe('completed');

  await page.close();
});

test('the client site decides over its signed route, and only while it waits on them', async ({ request }) => {
  const site = await Forge.asClientSite(admin.api, made.site.id, request);

  // Not waiting on the client: refused, and nothing moves.
  const other = await taskAt('Not theirs', 'in-review');
  const refused = await site.post(`/client/work-items/${other.id}/review`, { decision: 'approve', author_name: 'Jo' });
  expect(refused.status()).toBe(409);
  expect((await detail(other.id)).item.stage).toBe('in-review');

  // The board says which are waiting on them.
  const waiting = await inClientReview('Signed approve');
  const board = await (await site.get('/client/board')).json();
  const mine = board.items.find((one) => one.id === waiting.id);
  expect(mine.awaiting_review).toBe(true);

  const approved = await site.post(`/client/work-items/${waiting.id}/review`, { decision: 'approve', author_name: 'Jo Client' });
  expect(approved.status(), await approved.text()).toBe(200);
  expect((await approved.json()).item.awaiting_review).toBe(false);

  const now = await detail(waiting.id);
  expect(now.item.stage).toBe('completed');
  expect(now.history.find((one) => 'moved' === one.action && 'completed' === one.to_stage).reason).toBe('Approved by the client (Jo Client)');

  // A second click is already decided.
  const again = await site.post(`/client/work-items/${waiting.id}/review`, { decision: 'approve', author_name: 'Jo Client' });
  expect(again.status()).toBe(409);
  expect((await again.json()).message).toBe('Already decided.');

  // Sending back needs a note, and starts a new review.
  const back = await inClientReview('Signed send back');
  const bare = await site.post(`/client/work-items/${back.id}/review`, { decision: 'send_back', author_name: 'Jo' });
  expect(bare.status()).toBe(400);

  const sent = await site.post(`/client/work-items/${back.id}/review`, { decision: 'send_back', note: 'Wrong photo.', author_name: 'Jo' });
  expect(sent.status(), await sent.text()).toBe(200);
  const returned = (await detail(back.id)).item;
  expect(returned.stage).toBe('in-development');
  expect(returned.review_attempt).toBe(back.review_attempt + 1);
});

test('the client is emailed for each review they are asked for', async () => {
  const asked = (id) => detail(id).then((one) => (one.notifications ?? []).filter((event) => 'review-requested' === event.event_kind));

  const item = await inClientReview('Emailed');
  expect(await asked(item.id)).toHaveLength(1);

  // Sent back and back in review: a second review, so a second email.
  const back = await admin.api.post(`/work-items/${item.id}/client-review`, {
    decision: 'send_back',
    note: 'Not yet.',
    record_version: item.record_version,
  });
  expect(back.status(), await back.text()).toBe(200);

  const again = await admin.api.post(`/work-items/${item.id}/override`, {
    to: 'in-review',
    reason: 'Set up for the test.',
    record_version: (await back.json()).item.record_version,
  });
  expect(again.status(), await again.text()).toBe(200);

  expect(await asked(item.id)).toHaveLength(2);

  // Somebody else reviewing: no email to the client.
  const staffed = await taskAt('Not emailed', 'in-review');
  expect(await asked(staffed.id)).toHaveLength(0);
});
