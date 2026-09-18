import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, requireEnvironment } from './helpers/pair.js';
import * as Forge from '../e2e/helpers/forge.js';

// #246. The brief's §16 commercial criteria — AC-13 to AC-17 in
// Acceptance\Criteria — asserted with a real client site connected to a real
// studio.
//
// These were split out of #180 because they test rules M8 builds, and M8 runs
// after M11. They sat in the manifest marked as M8's, which is what kept them
// reading as scheduled rather than forgotten. M8 has now built them.
//
// Each test states one criterion in the words it is written in. They are slow
// for the honest reason: hours only move when work or a meeting actually moves,
// so the only way to assert what a client is charged is to charge them.

const RUN = `comm${Date.now()}`;
const STAMP = RUN.replace(/[^a-z0-9]/gi, '');
const GRANTED = 200;

const TO_UP_NEXT = [
  'triage',
  'documentation-period',
  'technical-audit',
  'design-process',
  'up-next',
];

test.beforeAll(requireEnvironment);

/** The three seats, filled, with a real plan on them. */
async function seatsFor(pair, label, hours = 10) {
  const primary = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `${label}p${STAMP}`);
  const reviewer = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `${label}r${STAMP}`);
  const deliverer = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `${label}d${STAMP}`);

  return {
    primary_user_id: primary.id,
    reviewer_id: reviewer.id,
    deliverer_id: deliverer.id,
    planned_start: '2026-11-02',
    planned_due: '2026-11-06',
    hours_primary: hours,
  };
}

/** Monday a fortnight out, so a series always has meetings ahead of it. */
function comingMonday() {
  const day = new Date(Date.now() + 14 * 86400000);

  day.setUTCDate(day.getUTCDate() + ((8 - day.getUTCDay()) % 7));

  return day.toISOString().slice(0, 10);
}

/** What the meetings on a site have actually cost, by kind of entry. */
function meetingHours(ledger, kind) {
  return ledger.entries
    .filter(([type]) => kind === type)
    .reduce((total, [, hours]) => total + Math.abs(hours), 0);
}

test.describe('the commercial acceptance criteria', () => {
  test('AC-13: assigning a package produces exactly the hours its terms say', async ({
    browser,
  }) => {
    test.setTimeout(420_000);

    const pair = await connectedPair(browser, 'Exact hours', RUN);
    const label = `Exact ${RUN}`;

    // A package whose terms are unambiguous: forty hours, twelve months.
    const pkg = await Forge.makePackage(pair.studio, label, { hours: 40, price: 1200, validity_months: 12 });

    /*
     * Assigned across a leap-year boundary. The whole term is a year whichever
     * year it is, so the criterion is that the hours are exactly the package's
     * — not a figure a day-count arrived at, and not one short because 2028 has
     * an extra day in it.
     */
    await Forge.assignSupport(pair.studio, pair.site.id, pkg.current.id, '2028-01-01');

    const ledger = await Forge.hourLedger(pair.studio, pair.site.id);
    const granted = ledger.entries.filter(([type]) => 'allocation' === type);

    expect(ledger.balance).toBe(40);
    expect(granted, 'granted once').toHaveLength(1);
    expect(granted[0][1], 'exactly the package hours').toBe(40);

    await pair.close();
  });

  test('AC-14: hours are reserved when work is planned, spent when it starts, released when it is cancelled', async ({
    browser,
  }) => {
    test.setTimeout(600_000);

    const pair = await connectedPair(browser, 'Work hours', RUN);

    await Forge.onSupport(pair.studio, pair.site.id, GRANTED);

    const seats = await seatsFor(pair, 'ac14');
    const first = await makeItem(pair.studio, pair.site.id, { title: `Planned ${RUN}` });
    const planned = await Forge.walkTo(pair.studio, first, TO_UP_NEXT, { seats });

    // Reserved on planning.
    expect((await Forge.hourLedger(pair.studio, pair.site.id)).balance).toBe(GRANTED - 13);

    // Spent on starting, and the balance does not move again — the reservation
    // became the spend rather than being charged on top of it.
    const ready = await Forge.satisfy(pair.studio, planned, 'in-development');
    const started = await pair.studio.post(`/work-items/${ready.id}/transition`, {
      to: 'in-development',
      record_version: ready.record_version,
      capacity_reason: 'Nobody has a pattern on this test instance.',
    });

    expect(started.status(), await started.text()).toBe(200);
    expect((await Forge.hourLedger(pair.studio, pair.site.id)).balance).toBe(GRANTED - 13);

    // And a second piece of work, cancelled while still planned, gives back
    // every hour it was holding.
    const second = await makeItem(pair.studio, pair.site.id, { title: `Cancelled ${RUN}` });
    const alsoPlanned = await Forge.walkTo(pair.studio, second, TO_UP_NEXT, {
      seats: await seatsFor(pair, 'ac14b'),
    });

    expect((await Forge.hourLedger(pair.studio, pair.site.id)).balance).toBe(GRANTED - 26);

    const cancelled = await pair.studio.post(`/work-items/${alsoPlanned.id}/outcome`, {
      outcome: 'cancelled',
      reason: 'The client changed their mind.',
      record_version: alsoPlanned.record_version,
    });

    expect(cancelled.status(), await cancelled.text()).toBe(200);
    expect(
      (await Forge.hourLedger(pair.studio, pair.site.id)).balance,
      'without drift'
    ).toBe(GRANTED - 13);

    await pair.close();
  });

  test('AC-15: a meeting reserves its hours, spends them only when it is held, and releases them when it is not', async ({
    browser,
  }) => {
    test.setTimeout(600_000);

    const pair = await connectedPair(browser, 'Meeting hours', RUN);

    await Forge.onSupport(pair.studio, pair.site.id, GRANTED);

    const host = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `ac15h${STAMP}`);

    // A weekly two-hour meeting, which is the ordinary shape of the thing.
    const added = await pair.studio.post(`/client-sites/${pair.site.id}/meetings/series`, {
      title: `Weekly catch-up ${RUN}`,
      frequency: 'weekly',
      starts_on: comingMonday(),
      ends_on: '',
      time_of_day: '10:00',
      duration_mins: 120,
      timezone: 'Europe/London',
      host_user_id: host.id,
      attendees: '',
      planned_hours: 0,
    });
    expect(added.status(), await added.text()).toBe(200);

    const { series, meetings } = await added.json();

    // Reserved, before anybody has met: the hours are held against meetings
    // that have not happened, which is what makes a balance mean anything.
    expect(meetings.length).toBeGreaterThan(2);
    expect(meetings[0].ledger_state).toBe('reserved');

    const reserved = await Forge.hourLedger(pair.studio, pair.site.id);

    expect(reserved.balance, 'held against the meetings to come').toBeLessThan(GRANTED);
    expect(meetingHours(reserved, 'meeting-usage'), 'and nothing spent yet').toBe(0);

    const dates = meetings.map((meeting) => meeting.slot);
    const settle = async (slot, status) => {
      const wrote = await pair.studio.post(
        `/client-sites/${pair.site.id}/meetings/${series[0].id}/${slot}/settle`,
        { status }
      );
      expect(wrote.status(), await wrote.text()).toBe(200);
      expect((await wrote.json()).meeting.status).toBe(status);
    };

    // Held: two hours, and only those two.
    await settle(dates[0], 'held');

    const afterHeld = await Forge.hourLedger(pair.studio, pair.site.id);

    expect(meetingHours(afterHeld, 'meeting-usage'), 'spent only when it is held').toBe(2);

    /*
     * And released when it is not. Two meetings that did not happen — one
     * called off, one nobody came to — give back exactly the four hours they
     * were holding. Asserted as the movement rather than as a total, because
     * every meeting still ahead is legitimately holding its own hours and a
     * total moves for that second reason too.
     */
    await settle(dates[1], 'cancelled');
    await settle(dates[2], 'no-show');

    const afterMissed = await Forge.hourLedger(pair.studio, pair.site.id);

    expect(afterMissed.balance - afterHeld.balance, 'given back, both of them').toBeCloseTo(4, 2);
    expect(meetingHours(afterMissed, 'meeting-usage'), 'and neither one charged').toBe(2);

    await pair.close();
  });

  test('AC-16: a client with no package is refused chargeable work at the API, and can still report a bug and reach Sales', async ({
    browser,
    request,
  }) => {
    test.setTimeout(420_000);

    // No package anywhere in this test.
    const pair = await connectedPair(browser, 'No package', RUN);
    const seats = await seatsFor(pair, 'ac16');

    const item = await makeItem(pair.studio, pair.site.id, { title: `Chargeable ${RUN}` });

    const ready = await Forge.walkTo(
      pair.studio,
      item,
      ['triage', 'documentation-period', 'technical-audit', 'design-process'],
      {}
    );

    const planned = await Forge.satisfy(pair.studio, ready, 'up-next', seats);

    const refused = await pair.studio.post(`/work-items/${planned.id}/transition`, {
      to: 'up-next',
      record_version: planned.record_version,
    });

    expect(refused.status(), 'refused at the API').toBe(409);
    expect((await pair.studio.get(`/work-items/${planned.id}`)).item.stage).toBe('design-process');

    // And the doors that matter stay open.
    const signed = asClientSite(request, pair.issued);

    const bug = await signed.post('/client/submissions', {
      type: 'bug',
      title: `Something is broken ${RUN}`,
      description: 'It stopped working this morning.',
      submitted_by: 'Someone at the client',
    });

    expect(bug.status(), 'can still report a bug').toBe(200);

    const sales = await (await signed.get('/client/sales')).json();

    expect(sales.entitlement.state, 'can still reach Sales').toBe('none');
    expect(sales.support.allowed).toContain('sales');
    expect(sales.support.allowed).toContain('point-of-contact');

    await pair.close();
  });

  test('AC-17: the client and the studio are shown the same figure, and both reconcile to the ledger', async ({
    browser,
    request,
  }) => {
    test.setTimeout(600_000);

    const pair = await connectedPair(browser, 'Same figure', RUN);
    const signed = asClientSite(request, pair.issued);

    await Forge.onSupport(pair.studio, pair.site.id, GRANTED);

    const seats = await seatsFor(pair, 'ac17');
    const item = await makeItem(pair.studio, pair.site.id, { title: `Shared figure ${RUN}` });

    await Forge.walkTo(pair.studio, item, TO_UP_NEXT, { seats });

    const studio = await Forge.hourLedger(pair.studio, pair.site.id);
    const client = await (await signed.get('/client/sales')).json();

    expect(client.balance, 'the same figure on both sides').toBe(studio.balance);

    /*
     * And both reconcile to the ledger: the figure is the sum of the entries,
     * not a total either side is keeping. Summed here from the studio's own
     * rendered rows, which is the closest a test can get to reading what a
     * person reads.
     */
    const summed = studio.entries.reduce((total, [, hours]) => total + hours, 0);

    expect(Math.round(summed * 100) / 100, 'the studio figure is its entries').toBe(studio.balance);
    expect(Math.round(summed * 100) / 100, 'and so is the client figure').toBe(client.balance);

    await pair.close();
  });
});
