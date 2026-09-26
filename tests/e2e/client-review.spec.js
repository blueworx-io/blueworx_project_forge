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
