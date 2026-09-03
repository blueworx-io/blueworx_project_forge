import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, requireEnvironment } from './helpers/pair.js';
import * as Forge from '../e2e/helpers/forge.js';

// #158, COMM-3. Both interfaces agree about money, always.
//
// This is the spec that makes a divergence *fail* rather than hide. The studio
// and the client each show a client's balance, and the whole commercial design
// rests on those never being two numbers — so the assertion is made after every
// kind of movement there is, not once at the start when nothing has happened.
//
// It also checks the drill-down: the entries against one piece of work have to
// hold together on their own. A reservation released twice, or a usage booked
// without its release, does not make a balance look wrong — it makes it wrong
// by a few hours, on one client, found when somebody queries an invoice months
// later.

const RUN = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const GRANTED = 200;
const TO_UP_NEXT = ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next'];

test.beforeAll(requireEnvironment);

/** Both figures, read the way each side actually reads them. */
async function bothSides(pair, signed) {
  const studio = await Forge.hourLedger(pair.studio, pair.site.id);
  const client = await (await signed.get('/client/sales')).json();

  return { studio: studio.balance, client: client.balance, entries: studio.entries };
}

/** What one source has put through the ledger, from the studio's own screen. */
function forSource(entries, source) {
  const mine = entries.filter(([, , against]) => against.endsWith(`:${source}`));

  const sum = (kind) =>
    mine
      .filter(([type]) => type === kind)
      .reduce((total, [, hours]) => total + Math.abs(hours), 0);

  return {
    reserved: sum('work-reservation') + sum('meeting-reservation'),
    released: sum('work-release') + sum('meeting-release'),
    used: sum('work-usage') + sum('meeting-usage'),
  };
}

test('the two interfaces agree at every step of a piece of work', async ({ browser, request }) => {
  test.setTimeout(600_000);

  const pair = await connectedPair(browser, 'Reconciled', RUN);
  const signed = asClientSite(request, pair.issued);

  await Forge.onSupport(pair.studio, pair.site.id, GRANTED);

  // Nothing has happened yet, and they already have to agree.
  let both = await bothSides(pair, signed);

  expect(both.client, 'after the package was assigned').toBe(both.studio);
  expect(both.studio).toBe(GRANTED);

  const primary = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `recprimary${RUN}`);
  const reviewer = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `recreviewer${RUN}`);
  const deliverer = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `recdeliverer${RUN}`);

  const item = await makeItem(pair.studio, pair.site.id, { title: `Reconciled work ${RUN}` });

  const planned = await Forge.walkTo(pair.studio, item, TO_UP_NEXT, {
    seats: {
      primary_user_id: primary.id,
      reviewer_id: reviewer.id,
      deliverer_id: deliverer.id,
      planned_start: '2026-11-02',
      planned_due: '2026-11-06',
      hours_primary: 10,
    },
  });

  // Planned: thirteen hours are set aside, and both sides say so.
  both = await bothSides(pair, signed);

  expect(both.client, 'after the work was planned').toBe(both.studio);
  expect(both.studio).toBe(GRANTED - 13);

  // Started: the reservation becomes spend, and the balance does not move.
  const ready = await Forge.satisfy(pair.studio, planned, 'in-development');

  const started = await pair.studio.post(`/work-items/${ready.id}/transition`, {
    to: 'in-development',
    record_version: ready.record_version,
    capacity_reason: 'Nobody has a pattern on this test instance.',
  });

  expect(started.status(), await started.text()).toBe(200);

  both = await bothSides(pair, signed);

  expect(both.client, 'after the work started').toBe(both.studio);
  expect(both.studio, 'converting a reservation costs nothing extra').toBe(GRANTED - 13);

  /*
   * And the drill-down holds together on its own. Thirteen reserved, thirteen
   * released, thirteen spent — so nothing is still held and the client has been
   * charged exactly once.
   */
  const drill = forSource(both.entries, planned.id);

  expect(drill.reserved).toBe(13);
  expect(drill.released).toBe(13);
  expect(drill.used).toBe(13);
  expect(drill.reserved - drill.released, 'nothing left held against started work').toBe(0);

  await pair.close();
});

test('a client with no package is shown nought by both sides', async ({ browser, request }) => {
  test.setTimeout(300_000);

  // The edge where "we do not know" and "you have nothing" are easiest to mix
  // up, and the two sides are most likely to answer differently.
  const pair = await connectedPair(browser, 'Nothing at all', RUN);
  const signed = asClientSite(request, pair.issued);

  const both = await bothSides(pair, signed);

  expect(both.client).toBe(0);
  expect(both.studio).toBe(0);

  await pair.close();
});
