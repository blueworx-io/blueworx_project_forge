import { test, expect } from '@playwright/test';
import { connectedPair, makeItem, requireEnvironment } from './helpers/pair.js';
import * as Forge from '../e2e/helpers/forge.js';

// #180. The brief's §16 capacity criteria — AC-11 and AC-12 in
// Acceptance\Criteria — with a real client site connected to the studio while
// they are asserted.
//
// The commercial half of this slot was split out to #246, because it tests
// rules M8 builds and M8 runs after M11. Those criteria are in the manifest,
// marked as M8's, so they read as scheduled rather than as forgotten.
//
// Both are slow for the same reason the workflow file is: capacity is only
// weighed at Up Next, so the only honest way to reach the thing being tested is
// to walk an item to it.

const RUN = `cap${Date.now()}`;
const STAMP = RUN.replace(/[^a-z0-9]/gi, '');
const PERSON = `paircapacity${STAMP}`;

// A fixed window in the future, so the arithmetic is the same whichever day the
// suite runs on.
const FROM = '2026-09-07';
const TO = '2026-09-11';
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

const TO_UP_NEXT = [
  'triage',
  'documentation-period',
  'technical-audit',
  'design-process',
  'up-next',
];

test.beforeAll(requireEnvironment);

/**
 * Working hours, set from the availability screen.
 *
 * #136 gave patterns no REST route on purpose — setting somebody up is
 * configuration rather than work (ARCH-7) — so this walks the screen. It does
 * not sign in again: the context already is, and a second sign-in would issue a
 * fresh session and quietly invalidate the REST nonce the rest of the spec
 * holds.
 */
async function setHoursThroughTheScreen(page, name, hours) {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-availability');
  await page.selectOption('#bwx-person', { label: name });
  await page.click('form[data-bwx-person-picker] input[type="submit"]');

  await expect(page.locator('[data-bwx-person-name]')).toHaveText(name);

  await page.fill('#bwx-effective-from', '2020-01-01');

  for (const day of DAYS) {
    await page.fill(`#bwx-hours_${day}`, String(hours));
  }

  await page.click('form[data-bwx-set-hours] input[type="submit"]');
  await expect(page.locator('[data-bwx-result="hours-set"]')).toBeVisible();
}

test.describe('the capacity acceptance criteria', () => {
  test('AC-11: a person on two clients is counted once, and the drill-down reconciles', async ({
    browser,
  }) => {
    test.setTimeout(420_000);

    const pair = await connectedPair(browser, 'Capacity one', RUN);

    // A second client on the same studio, and one person who works for both.
    // This is the shape the criterion is about: two clients that each look
    // comfortable on their own.
    const second = await (
      await pair.studio.post('/clients', {
        display_name: `Capacity two ${RUN}`,
        timezone: 'Europe/London',
      })
    ).json();

    const secondSite = await (
      await pair.studio.post(`/clients/${second.client.id}/sites`, {
        name: `Capacity two site ${RUN}`,
        url: process.env.BWX_CLIENT_BASE_URL,
      })
    ).json();

    const person = await Forge.makePerson(pair.studio, pair.client.id, 'staff', PERSON);

    const joined = await pair.studio.post(`/clients/${second.client.id}/memberships`, {
      user_id: person.id,
      role: 'staff',
    });

    expect(joined.status(), await joined.text()).toBe(200);

    const page = await pair.studio.context.newPage();
    await setHoursThroughTheScreen(page, PERSON, 8);
    await page.close();

    for (const where of [
      { clientId: pair.client.id, siteId: pair.site.id },
      { clientId: second.client.id, siteId: secondSite.site.id },
    ]) {
      // The gate at Up Next wants all three seats filled, and a reviewer who is
      // not the person who did the work (AUTH-3).
      const reviewer = await Forge.makePerson(pair.studio, where.clientId, 'staff', `rev${STAMP}`);
      const deliverer = await Forge.makePerson(pair.studio, where.clientId, 'staff', `del${STAMP}`);

      const item = await makeItem(pair.studio, where.siteId, { title: `Capacity work ${RUN}` });

      await Forge.walkTo(pair.studio, item, TO_UP_NEXT, {
        seats: {
          primary_user_id: person.id,
          reviewer_id: reviewer.id,
          deliverer_id: deliverer.id,
          planned_start: FROM,
          planned_due: TO,
          hours_primary: 6,
        },
      });
    }

    const capacity = await pair.studio.get(`/capacity?from=${FROM}&to=${TO}`);
    const row = capacity.people.find((entry) => entry.user_id === person.id);

    expect(row, 'the person appears in the capacity read').toBeTruthy();
    expect(row.total.committed, 'both clients, one figure').toBe(12);
    expect(row.total.available).toBe(40);
    expect(row.total.remaining).toBe(28);

    // And the figure the studio plans against is the one the records add up to.
    const drill = await pair.studio.get(`/capacity/person/${person.id}?from=${FROM}&to=${TO}`);

    expect(drill.allocations, 'both pieces of work are behind the number').toHaveLength(2);
    expect(
      new Set(drill.allocations.map((entry) => entry.client_id)).size,
      'one from each client'
    ).toBe(2);

    const summed = drill.allocations.reduce((total, entry) => total + entry.hours, 0);

    expect(summed, 'the drill-down reconciles to the total').toBe(row.total.committed);

    await pair.close();
  });

  test('AC-12: work with no room behind it is refused, and goes ahead only for a stated reason', async ({
    browser,
  }) => {
    test.setTimeout(420_000);

    const pair = await connectedPair(browser, 'Overbooked', RUN);

    const person = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `over${STAMP}`);
    const reviewer = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `orev${STAMP}`);
    const deliverer = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `odel${STAMP}`);

    const page = await pair.studio.context.newPage();
    await setHoursThroughTheScreen(page, `over${STAMP}`, 8);
    await page.close();

    const item = await makeItem(pair.studio, pair.site.id, { title: `Too much work ${RUN}` });

    const parked = await Forge.walkTo(pair.studio, item, TO_UP_NEXT, {
      seats: {
        primary_user_id: person.id,
        reviewer_id: reviewer.id,
        deliverer_id: deliverer.id,
        planned_start: FROM,
        planned_due: TO,
        hours_primary: 400,
      },
    });

    // Everything else the gate wants, so the only thing left to fail on is the
    // capacity.
    const ready = await Forge.satisfy(pair.studio, parked, 'in-development');

    const refused = await pair.studio.post(`/work-items/${ready.id}/transition`, {
      to: 'in-development',
      record_version: ready.record_version,
    });

    expect(refused.status(), await refused.text()).toBe(409);

    const body = await refused.json();
    const capacity = body.unmet.find((requirement) => 'G-UP-NEXT-8' === requirement.id);

    expect(capacity, 'the capacity check refused the move').toBeTruthy();
    expect(capacity.over[0].user_id).toBe(person.id);
    expect(capacity.over[0].excess).toBeGreaterThan(0);

    // It does not hard block. It costs a reason (CAP-4).
    const anyway = await pair.studio.post(`/work-items/${ready.id}/transition`, {
      to: 'in-development',
      record_version: ready.record_version,
      capacity_reason: 'The client has agreed the overtime for this release.',
    });

    expect(anyway.status(), await anyway.text()).toBe(200);

    const after = await pair.studio.get(`/work-items/${ready.id}`);

    expect(after.item.stage).toBe('in-development');
    expect(after.item.capacity_override_used, 'the item says it was over-booked').toBe(true);
    expect(after.item.capacity_override_reason).toContain('agreed the overtime');

    // And the reason is history rather than a field somebody can quietly clear.
    const entry = after.history.find((event) => 'over-allocated' === event.action);

    expect(entry, 'the decision is its own kind of history entry').toBeTruthy();
    expect(entry.reason).toContain('agreed the overtime');

    await pair.close();
  });
});
