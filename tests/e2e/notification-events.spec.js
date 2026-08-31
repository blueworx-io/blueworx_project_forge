import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #172, NOTIF-3. Exactly one email per qualifying event, whatever happens
// underneath.
//
// The guarantee is a primary key on a real database, so it is proved against
// one. The unit tests prove the id is worked out from what happened rather than
// from when it was noticed; nothing but a real insert can prove that two
// attempts at the same event leave one row, because that is the database's
// answer and not the code's.
//
// The interesting half is the second test. Work that fails review and is sent
// back reaches Completed twice, in the same cycle, by two separate journeys
// through the one door that moves work. That is a genuine second arrival — not
// a simulated replay — and the client must be told once.
//
// Nothing is ever deleted and the instance is kept between runs, so every name
// carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

const UP_TO_COMPLETED = [
  'triage',
  'documentation-period',
  'technical-audit',
  'design-process',
  'up-next',
  'in-development',
  'in-review',
  'completed',
];

/** Everything one of these tests needs: a site, a team, and an item. */
async function world(browser, baseURL, label) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, `${label} ${RUN_ID}`, RUN_ID);
  const team = await Forge.team(admin.api, browser, baseURL, client.id);
  const made = await Forge.makeItem(admin.api, site.id, { title: `${label} ${RUN_ID}` });

  expect(made.status(), await made.text()).toBe(200);

  return { admin, client, site, team, item: (await made.json()).item };
}

/** What the client has been told about one item, of one kind. */
async function toldAbout(api, itemId, kind) {
  const detail = await api.get(`/work-items/${itemId}`);

  return (detail.notifications ?? []).filter((one) => one.event_kind === kind);
}

test('arriving somewhere the client hears about raises exactly one event', async ({
  browser,
  baseURL,
}) => {
  // Eight stages of gates, one request each against a single-threaded server.
  test.slow();

  const { admin, item, team } = await world(browser, baseURL, 'Notify Co');

  let current = await Forge.walkTo(admin.api, item, UP_TO_COMPLETED, {
    seats: team.seats,
    as: team.as,
  });

  // NOTIF-2. Completed still tells the client something — worded as ready
  // rather than as done — and Released is the final word.
  const completed = await toldAbout(admin.api, current.id, 'work-completed');

  expect(completed).toHaveLength(1);
  expect(completed[0].id).toMatch(/^nev_[0-9a-f]{26}$/);
  expect(completed[0].occurrence).toBe(1);
  expect(completed[0].outcome).toBe('raised');
  expect(completed[0].subject_id).toBe(current.id);

  // Nothing has been raised about the release yet, because it has not happened.
  expect(await toldAbout(admin.api, current.id, 'work-released')).toHaveLength(0);

  current = await Forge.walkTo(admin.api, current, ['released'], {
    seats: team.seats,
    as: team.as,
  });

  const released = await toldAbout(admin.api, current.id, 'work-released');

  expect(released).toHaveLength(1);

  // Two different messages, so two different ids. Sharing one would mean
  // sending the first suppressed the second for ever.
  expect(released[0].id).not.toBe(completed[0].id);

  await team.close();
  await admin.context.close();
});

test('reaching the same point twice still tells the client once', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, item, team } = await world(browser, baseURL, 'Twice Co');

  let current = await Forge.walkTo(admin.api, item, UP_TO_COMPLETED, {
    seats: team.seats,
    as: team.as,
  });

  const first = await toldAbout(admin.api, current.id, 'work-completed');

  expect(first).toHaveLength(1);

  // Sent back for another look. A return is not a new cycle (#113) — the work
  // is the same piece of work, having a second attempt at the same review.
  const sent = await admin.api.post(`/work-items/${current.id}/return`, {
    to: 'in-review',
    reason: 'One more thing to change.',
    record_version: current.record_version,
  });

  expect(sent.status(), await sent.text()).toBe(200);
  current = (await sent.json()).item;
  expect(current.stage).toBe('in-review');

  // And approved again, which is a second genuine arrival at Completed through
  // the same door — not a replay of the first.
  current = await Forge.walkTo(admin.api, current, ['completed'], {
    seats: team.seats,
    as: team.as,
  });

  const second = await toldAbout(admin.api, current.id, 'work-completed');

  // One event, not two, and the same one as before. This is the whole issue:
  // the client is told they are ready once, however many times we arrive at
  // being ready.
  expect(second).toHaveLength(1);
  expect(second[0].id).toBe(first[0].id);
  expect(second[0].raised_at).toBe(first[0].raised_at);

  await team.close();
  await admin.context.close();
});

test('the stages nobody hears about raise nothing', async ({ browser, baseURL }) => {
  const { admin, item } = await world(browser, baseURL, 'Quiet Co');

  const current = await Forge.walkTo(admin.api, item, ['triage', 'documentation-period']);
  const detail = await admin.api.get(`/work-items/${current.id}`);

  // An item halfway through is an item the client has not been emailed about.
  // A notification raised here would be an email saying nothing has happened.
  expect(detail.notifications).toEqual([]);

  await admin.context.close();
});
