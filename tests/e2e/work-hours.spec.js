import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #149, COMM-3. Hours reserved when work is planned, spent when it starts,
// released when it stops — against a real database, which is the half the unit
// tests deliberately cannot reach.
//
// WorkHoursTest settles every rule about *what* should be written. What is here
// is whether it actually is: that the entries land, that the balance moves by
// the right amount, and that a move the ledger refuses leaves the item where it
// was rather than half-moved.
//
// The instance is kept between runs and other specs leave sites behind, so
// every site here is this run's own and every assertion is scoped to it.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

// Ten for the Primary User, and CAP-2 seeds two and one for the other seats.
// Thirteen is the figure every assertion below is about.
const PRIMARY_HOURS = 10;
const PLANNED = 13;
const GRANTED = 200;

/** A site with a team, and hours to plan work against. */
async function withSiteOnSupport(browser, baseURL, { hours = GRANTED } = {}) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site, client } = await Forge.makeSite(admin.api, `Hours Co ${RUN_ID}`, RUN_ID);

  if (hours > 0) {
    await Forge.onSupport(admin, site.id, hours);
  }

  const people = await Forge.team(admin.api, browser, baseURL, client.id);

  return { admin, api: admin.api, site, people };
}

/** The seats and the plan, as the Up Next gate wants them. */
function planFor(people, hours = PRIMARY_HOURS) {
  return {
    primary_user_id: people.primary.id,
    reviewer_id: people.reviewer.id,
    deliverer_id: people.deliverer.id,
    planned_start: '2026-10-05',
    planned_due: '2026-10-09',
    hours_primary: hours,
  };
}

/** Only the entries one item put through the ledger. */
function ofWork(entries) {
  return entries.filter(([type]) => type.startsWith('work-'));
}

/**
 * Starts planned work.
 *
 * The move out of Up Next runs the capacity check, and nobody here has an
 * availability pattern — so everybody is over-booked by any figure at all.
 * CAP-4 says that costs a reason rather than blocking, and giving one keeps
 * this spec about hours instead of about availability.
 */
async function start(api, item) {
  const ready = await Forge.satisfy(api, item, 'in-development');

  const moved = await api.post(`/work-items/${ready.id}/transition`, {
    to: 'in-development',
    record_version: ready.record_version,
    capacity_reason: 'Nobody has a pattern on this test instance.',
  });

  expect(moved.status(), await moved.text()).toBe(200);

  return (await moved.json()).item;
}

test('planning work reserves its hours, and starting it turns them into spend', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, api, site, people } = await withSiteOnSupport(browser, baseURL);
  const item = await Forge.makeItem(api, site.id, { title: `Planned ${RUN_ID}` });

  const planned = await Forge.walkTo(
    api,
    (await item.json()).item,
    ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next'],
    { seats: planFor(people) }
  );

  expect(planned.stage).toBe('up-next');

  // Reserved, not spent. Nobody has started, and the client's balance says so
  // while still showing the hours are committed.
  const atUpNext = await Forge.hourLedger(admin, site.id);

  expect(ofWork(atUpNext.entries)).toEqual([['work-reservation', -PLANNED]]);
  expect(atUpNext.balance).toBe(GRANTED - PLANNED);

  const started = await start(api, planned);

  expect(started.stage).toBe('in-development');

  /*
   * Both entries, and the balance unmoved. Booking the usage without releasing
   * the reservation is the obvious implementation and it bills every client
   * twice for the same work — which is why the assertion is on the balance as
   * well as on the entries.
   */
  const started_ledger = await Forge.hourLedger(admin, site.id);

  expect(ofWork(started_ledger.entries)).toEqual([
    ['work-reservation', -PLANNED],
    ['work-release', PLANNED],
    ['work-usage', -PLANNED],
  ]);
  expect(started_ledger.balance).toBe(GRANTED - PLANNED);
});

test('cancelling planned work gives every hour back', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, api, site, people } = await withSiteOnSupport(browser, baseURL);
  const item = await Forge.makeItem(api, site.id, { title: `Cancelled ${RUN_ID}` });

  const planned = await Forge.walkTo(
    api,
    (await item.json()).item,
    ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next'],
    { seats: planFor(people) }
  );

  expect((await Forge.hourLedger(admin, site.id)).balance).toBe(GRANTED - PLANNED);

  const cancelled = await api.post(`/work-items/${planned.id}/outcome`, {
    outcome: 'cancelled',
    reason: 'The client changed their mind.',
    record_version: planned.record_version,
  });

  expect(cancelled.status(), await cancelled.text()).toBe(200);

  // Back where it started. Hours held against work that will never happen are
  // hours the client paid for and cannot use.
  const after = await Forge.hourLedger(admin, site.id);

  expect(ofWork(after.entries)).toEqual([
    ['work-reservation', -PLANNED],
    ['work-release', PLANNED],
  ]);
  expect(after.balance).toBe(GRANTED);
});

test('re-planning appends the difference rather than the new total', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, api, site, people } = await withSiteOnSupport(browser, baseURL);
  const item = await Forge.makeItem(api, site.id, { title: `Re-planned ${RUN_ID}` });

  const planned = await Forge.walkTo(
    api,
    (await item.json()).item,
    ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next'],
    { seats: planFor(people) }
  );

  // Seventeen for the Primary User now, and the other two seats keep the
  // figures they already have: twenty planned against thirteen reserved.
  const raised = await api.patch(`/work-items/${planned.id}`, {
    hours_primary: 17,
    record_version: planned.record_version,
  });

  expect(raised.status(), await raised.text()).toBe(200);

  const after = await Forge.hourLedger(admin, site.id);

  expect(ofWork(after.entries)).toEqual([
    ['work-reservation', -PLANNED],
    ['work-reservation', -7],
  ]);
  expect(after.balance).toBe(GRANTED - 20);
});

test('a free bug never touches the ledger', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, api, site, people } = await withSiteOnSupport(browser, baseURL);
  const item = await Forge.makeItem(api, site.id, {
    title: `Free bug ${RUN_ID}`,
    work_type: 'bug',
  });

  const triaged = await Forge.walkTo(api, (await item.json()).item, ['triage']);

  // COMM-5: Forge delivered it and Forge broke it, so the client is not paying
  // to have it fixed. Reclassified after Triage, because the gate's own answer
  // is 'chargeable'.
  const reclassified = await api.patch(`/work-items/${triaged.id}`, {
    commercial_class: 'free-bug',
    record_version: triaged.record_version,
  });

  expect(reclassified.status(), await reclassified.text()).toBe(200);

  const planned = await Forge.walkTo(
    api,
    (await reclassified.json()).item,
    ['bug-tracking', 'documentation-period', 'technical-audit', 'design-process', 'up-next'],
    { seats: planFor(people) }
  );

  const started = await start(api, planned);

  expect(started.stage).toBe('in-development');

  const after = await Forge.hourLedger(admin, site.id);

  expect(ofWork(after.entries)).toEqual([]);
  expect(after.balance).toBe(GRANTED);
});

test('work the site cannot pay for does not move, and leaves nothing behind', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  // No package at all, so there is nothing to reserve against.
  const { admin, api, site, people } = await withSiteOnSupport(browser, baseURL, { hours: 0 });
  const item = await Forge.makeItem(api, site.id, { title: `Unaffordable ${RUN_ID}` });

  const ready = await Forge.walkTo(
    api,
    (await item.json()).item,
    ['triage', 'documentation-period', 'technical-audit', 'design-process'],
    { seats: planFor(people) }
  );

  const filled = await Forge.satisfy(api, ready, 'up-next', planFor(people));

  const refused = await api.post(`/work-items/${filled.id}/transition`, {
    to: 'up-next',
    record_version: filled.record_version,
  });

  expect(refused.status()).toBe(409);

  /*
   * The whole point of doing it in one transaction. A refused entry that left
   * the item at Up Next would be a piece of work everybody can see is planned
   * and the ledger has never heard of.
   */
  const unmoved = await api.get(`/work-items/${filled.id}`);

  expect(unmoved.item.stage).toBe('design-process');
  expect((await Forge.hourLedger(admin, site.id)).entries).toEqual([]);
});
