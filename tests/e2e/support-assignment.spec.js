import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #146. A client's commercial position over time.
//
// "A client's entitlement on any past date can be reconstructed from the
// record" is the criterion, and the record is a run of dated periods: every
// change closes one and opens the next. So the test that matters is the one
// that suspends a site and puts it back, then reads the history — because that
// is the case the obvious design, a suspended flag on one row, cannot answer.
//
// It also closes two things left open by #147 and #148. Assigning a package
// grants its hours through the ledger, so the ledger is read back with the
// position; and a part-year assignment grants its pro-rated sum, so the
// pro-rata preview has somewhere to be written. Both are checked here, over
// the routes the Support screen in the app is drawn from.
//
// The instance is kept between runs and other specs leave sites behind, so
// every assertion is scoped to this run's own.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** Today and a date some days from it, as the screen writes them. */
function day(offset = 0) {
  const at = new Date(Date.now() + offset * 86400000);

  return at.toISOString().slice(0, 10);
}

/** A site, and a package on offer to put it on. */
let made = 0;

async function withSiteAndPackage(browser, baseURL) {
  // A package of its own per test. Every test here adds a package to a shared
  // instance, so one name shared between them leaves several identical
  // packages and no way to say which is this test's.
  const label = `Standard ${RUN_ID}-${++made}`;
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Support Co ${RUN_ID}`, RUN_ID);

  const pkg = await Forge.makePackage(admin.api, label, { hours: 12, price: 1200 });

  return { admin, site, pkg };
}

/** One site's support: position, periods and ledger, as one answer. */
async function support(admin, siteId) {
  const answer = await admin.api.get(`/client-sites/${siteId}/support`);
  expect(answer.ok, JSON.stringify(answer)).toBe(true);

  return answer;
}

/** The allocation entries on a ledger — the hours a package granted. */
function allocations(answer) {
  return answer.ledger.filter((entry) => 'allocation' === entry.event_type);
}

/** Does one of the six things to a site's support, from a date. */
async function act(admin, siteId, what, from) {
  const wrote = await admin.api.post(`/client-sites/${siteId}/support/${what}`, { from });
  expect(wrote.status(), await wrote.text()).toBe(200);

  return wrote.json();
}

test('a site with no package says so, and says it is not lapsed', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, site } = await withSiteAndPackage(browser, baseURL);

  const answer = await support(admin, site.id);

  // "None" rather than "lapsed": nothing has run out because nothing has begun,
  // and reading it the other way would put this client on a renewal list for a
  // package they have never held.
  expect(answer.position.state).toBe('none');
  expect(answer.position.may_use_hours).toBe(false);
  expect(answer.periods).toHaveLength(0);

  await admin.context.close();
});

test('assigning a package puts the site on support and its hours on the ledger', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, pkg } = await withSiteAndPackage(browser, baseURL);

  await Forge.assignSupport(admin.api, site.id, pkg.current.id, day(0));

  const answer = await support(admin, site.id);
  expect(answer.position.state).toBe('active');
  expect(answer.position.may_use_hours).toBe(true);

  /*
   * The hours arrived through the ledger, which is the only way hours ever
   * arrive. A balance that moved by some other route would be a second path by
   * which a client's entitlement can change, and there is not one.
   */
  expect(Number(answer.position.balance)).toBe(12);
  expect(allocations(answer)).toHaveLength(1);
  expect(Number(allocations(answer)[0].hours)).toBe(12);

  await admin.context.close();
});

test('suspending leaves the hours alone, and resuming can be told apart from it later', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, pkg } = await withSiteAndPackage(browser, baseURL);

  await Forge.assignSupport(admin.api, site.id, pkg.current.id, day(-30));

  // Stopped a fortnight ago.
  const suspended = await act(admin, site.id, 'suspend', day(-14));
  expect(suspended.position.state).toBe('suspended');

  // COMM-4: the balance is frozen, not voided. Hours a client paid for are
  // theirs whatever their package is doing.
  expect(Number(suspended.position.balance)).toBe(12);
  expect(allocations(suspended)).toHaveLength(1);

  // And back on, a week ago.
  const resumed = await act(admin, site.id, 'resume', day(-7));
  expect(resumed.position.state).toBe('active');

  /*
   * Three periods, which is the criterion. A suspended flag on a single row
   * would now say only that this site is active, and the fortnight it was not
   * would be gone — along with any way to answer for what it was entitled to
   * then.
   */
  const answer = await support(admin, site.id);
  expect(answer.periods).toHaveLength(3);
  expect(answer.periods.filter((period) => 'suspended' === period.state)).toHaveLength(1);
  expect(answer.periods.filter((period) => 'active' === period.state)).toHaveLength(2);

  // Still granted once, however many times the position changed.
  expect(allocations(answer)).toHaveLength(1);

  await admin.context.close();
});

test('a part-year assignment grants the pro-rated hours, not a full year', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, pkg } = await withSiteAndPackage(browser, baseURL);

  /*
   * A client asking to align with a shared renewal date — the one case COMM-1
   * applies pro-rata to. Half a term, so half the hours: six of twelve,
   * arriving on the ledger as exactly that.
   */
  const from = '2026-01-01';
  const to = '2026-07-01';

  const wrote = await admin.api.post(`/client-sites/${site.id}/support`, {
    package_version: pkg.current.id,
    starts_on: from,
    ends_on: to,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await support(admin, site.id);

  expect(allocations(answer)).toHaveLength(1);
  expect(Number(allocations(answer)[0].hours)).toBe(6);
  expect(Number(answer.position.balance)).toBe(6);

  await admin.context.close();
});

test('cancelling ends the cover and leaves the hours to be dealt with deliberately', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, pkg } = await withSiteAndPackage(browser, baseURL);

  await Forge.assignSupport(admin.api, site.id, pkg.current.id, day(-30));

  const cancelled = await act(admin, site.id, 'cancel', day(-1));

  // Lapsed rather than gone: the record still shows what they had, and the
  // hours are still there to be written off with a reason if that is what was
  // agreed. Taking them back quietly is not something cancelling does.
  expect(cancelled.position.state).toBe('lapsed');
  expect(Number(cancelled.position.balance)).toBe(12);
  expect(cancelled.periods).toHaveLength(1);

  await admin.context.close();
});
