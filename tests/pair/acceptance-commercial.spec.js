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

const SUPPORT = '/wp-admin/admin.php?page=blueworx-forge-support';
const PACKAGES = '/wp-admin/admin.php?page=blueworx-forge-packages';
const MEETINGS = '/wp-admin/admin.php?page=blueworx-forge-meetings';

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
    const page = await pair.studio.context.newPage();
    const label = `Exact ${RUN}`;

    // A package whose terms are unambiguous: forty hours, twelve months.
    await page.goto(PACKAGES);

    const form = page
      .locator('form')
      .filter({ has: page.locator('input[value="bwx_forge_add_package"]') });

    await form.locator('input[name="name"]').fill(label);
    await form.locator('input[name="hours"]').fill('40');
    await form.locator('input[name="price"]').fill('1200');
    await form.locator('input[name="validity_months"]').fill('12');
    await form.locator('#bwx-add').click();
    await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();

    /*
     * Assigned across a leap-year boundary. The whole term is a year whichever
     * year it is, so the criterion is that the hours are exactly the package's
     * — not a figure a day-count arrived at, and not one short because 2028 has
     * an extra day in it.
     */
    await page.goto(`${SUPPORT}&site=${pair.site.id}`);

    const option = page.locator('#bwx-assign-package option', { hasText: label });

    await page.locator('#bwx-assign-package').selectOption(await option.getAttribute('value'));
    await page.locator('#bwx-assign-from').fill('2028-01-01');
    await page.locator('#bwx-assign').click();

    await expect(page.locator('[data-bwx-result="assigned"]')).toBeVisible();
    await expect(page.locator('[data-bwx-balance="40"]')).toBeVisible();

    const entries = (await Forge.hourLedger(pair.studio, pair.site.id)).entries;
    const granted = entries.filter(([type]) => 'allocation' === type);

    expect(granted, 'granted once').toHaveLength(1);
    expect(granted[0][1], 'exactly the package hours').toBe(40);

    await page.close();
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
    const page = await pair.studio.context.newPage();

    await page.goto(`${MEETINGS}&site=${pair.site.id}`);

    // A weekly two-hour meeting, which is the ordinary shape of the thing.
    await page.fill('#bwx-title', `Weekly catch-up ${RUN}`);
    await page.selectOption('#bwx-frequency', 'weekly');
    await page.fill('#bwx-starts_on', comingMonday());
    await page.fill('#bwx-time_of_day', '10:00');
    await page.fill('#bwx-duration_mins', '120');
    await page.fill('#bwx-timezone', 'Europe/London');
    await page.selectOption('#bwx-host', host.id);
    await page.click('#bwx-add-series');

    await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();

    // Reserved, before anybody has met: the hours are held against meetings
    // that have not happened, which is what makes a balance mean anything.
    await expect(page.locator('[data-bwx-ledger-state="reserved"]').first()).toBeVisible();

    const reserved = await Forge.hourLedger(pair.studio, pair.site.id);

    expect(reserved.balance, 'held against the meetings to come').toBeLessThan(GRANTED);
    expect(meetingHours(reserved, 'meeting-usage'), 'and nothing spent yet').toBe(0);

    const dates = await page
      .locator('[data-bwx-meeting]')
      .evaluateAll((rows) => rows.map((row) => row.getAttribute('data-bwx-meeting')));

    // Held: two hours, and only those two.
    await page.locator(`[data-bwx-meeting="${dates[0]}"] [data-bwx-settle="held"]`).click();
    await expect(page.locator('[data-bwx-result="held"]')).toBeVisible();

    const afterHeld = await Forge.hourLedger(pair.studio, pair.site.id);

    expect(meetingHours(afterHeld, 'meeting-usage'), 'spent only when it is held').toBe(2);

    /*
     * And released when it is not. Two meetings that did not happen — one
     * called off, one nobody came to — give back exactly the four hours they
     * were holding. Asserted as the movement rather than as a total, because
     * every meeting still ahead is legitimately holding its own hours and a
     * total moves for that second reason too.
     */
    await page.locator(`[data-bwx-meeting="${dates[1]}"] [data-bwx-settle="cancelled"]`).click();
    await expect(page.locator('[data-bwx-result="cancelled"]')).toBeVisible();

    await page.locator(`[data-bwx-meeting="${dates[2]}"] [data-bwx-settle="no-show"]`).click();
    await expect(page.locator('[data-bwx-result="no-show"]')).toBeVisible();

    const afterMissed = await Forge.hourLedger(pair.studio, pair.site.id);

    expect(afterMissed.balance - afterHeld.balance, 'given back, both of them').toBeCloseTo(4, 2);
    expect(meetingHours(afterMissed, 'meeting-usage'), 'and neither one charged').toBe(2);

    await page.close();
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
