import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #183. The cross-client views, against a dataset with more than one client in
// it — which is the only condition under which a per-client query model fails.
//
// **The failure this exists to catch is a loop, not a slow query.** A view that
// fetches once per client is fine on the two clients a developer has and
// unusable on forty, and it is invisible until then. So the budget is a query
// count as much as a time: a count that climbs with the number of clients is
// the bug, whatever the wall clock says on a laptop.
//
// The count comes from a must-use plugin the harness writes (tests/global-setup.js),
// which counts every query the request makes and returns it as a header. It
// misses the handful WordPress makes before must-use plugins load; those are
// core's own and constant.
//
// The budgets below are deliberately generous against what these actually
// measure. They are there to fail when something starts looping, not to police
// a few queries either way — a budget set at today's exact number is a budget
// somebody raises rather than investigates.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${ Date.now() }-${ Math.floor( Math.random() * 1e6 ) }`;

/**
 * How many clients the dataset has.
 *
 * Enough that a per-client loop is unmistakable against a fixed cost, and few
 * enough that building it does not take longer than the suite it belongs to.
 * The instances are reused between runs, so real runs see this run's clients on
 * top of every earlier run's — which only makes the test stricter.
 */
const CLIENTS = 8;

/** Pieces of work on each of those clients. */
const ITEMS_EACH = 3;

/**
 * The budgets, per cross-client read.
 *
 * `queries` is the number that matters. `ms` is a guard against something
 * pathological rather than a performance target: CI runners vary by more than
 * any sensible threshold, so it is set where only a real problem trips it.
 */
const BUDGET = {
  '/capacity': { queries: 60, ms: 5000 },
  '/standup': { queries: 400, ms: 15000 },
  '/onboarding/board': { queries: 120, ms: 8000 },
  '/reports': { queries: 60, ms: 5000 },
};

const WINDOW = ( () => {
  const from = new Date();
  const to = new Date();

  to.setDate( from.getDate() + 55 );

  return {
    from: from.toISOString().slice( 0, 10 ),
    to: to.toISOString().slice( 0, 10 ),
  };
} )();

/** One read, with what it cost. */
async function measure( api, path ) {
  const started = Date.now();
  const response = await api.request.get( `/wp-json/blueworx-forge/v1${ path }`, {
    headers: api.headers,
  } );
  const ms = Date.now() - started;

  expect( response.status(), `${ path }: ${ await response.text() }` ).toBe( 200 );

  const counted = response.headers()['x-bwx-queries'];

  expect(
    counted,
    `${ path } did not report a query count — is the harness must-use plugin installed?`
  ).toBeTruthy();

  return { queries: Number( counted ), ms };
}

test.describe( 'the cross-client views hold up as clients accumulate', () => {
  test( 'every cross-client read stays inside its budget', async ( { browser, baseURL } ) => {
    // Building eight clients with work on each, one request at a time against a
    // single-threaded PHP server, is the whole cost here.
    test.setTimeout( 600_000 );

    const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );

    for ( let client = 0; client < CLIENTS; client += 1 ) {
      const { site } = await Forge.makeSite( admin.api, `Perf ${ client } ${ RUN_ID }`, RUN_ID );

      for ( let item = 0; item < ITEMS_EACH; item += 1 ) {
        await Forge.makeItem( admin.api, site.id, {
          title: `Perf work ${ client }-${ item } ${ RUN_ID }`,
          planned_start: WINDOW.from,
          planned_due: WINDOW.to,
        } );
      }
    }

    const measured = {};

    measured['/capacity'] = await measure(
      admin.api,
      `/capacity?from=${ WINDOW.from }&to=${ WINDOW.to }`
    );
    measured['/standup'] = await measure( admin.api, '/standup' );
    measured['/onboarding/board'] = await measure( admin.api, '/onboarding/board' );
    measured['/reports'] = await measure( admin.api, '/reports' );

    // Reported whatever happens, so a run that passes still says what it cost.
    // A budget nobody sees the numbers behind is a budget that drifts.
    // eslint-disable-next-line no-console
    console.log(
      'Cross-client reads:\n' +
        Object.entries( measured )
          .map(
            ( [ path, result ] ) =>
              `  ${ path.padEnd( 20 ) } ${ String( result.queries ).padStart( 4 ) } queries  ${ String(
                result.ms
              ).padStart( 6 ) } ms`
          )
          .join( '\n' )
    );

    for ( const [ path, budget ] of Object.entries( BUDGET ) ) {
      expect(
        measured[ path ].queries,
        `${ path } made ${ measured[ path ].queries } queries, budget ${ budget.queries }`
      ).toBeLessThanOrEqual( budget.queries );

      expect(
        measured[ path ].ms,
        `${ path } took ${ measured[ path ].ms }ms, budget ${ budget.ms }ms`
      ).toBeLessThanOrEqual( budget.ms );
    }

    await admin.context.close();
  } );

  test( 'the reports read does not get dearer as the window widens', async ( {
    browser,
    baseURL,
  } ) => {
    test.setTimeout( 300_000 );

    const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );

    // Two queries whatever the window is asked for, by design: the reports are
    // one pass over one fetch. A window that costs more queries than a narrow
    // one means something started fetching per item.
    const narrow = await measure( admin.api, '/reports?from=2026-08-25&to=2026-09-01' );
    const wide = await measure( admin.api, '/reports?from=2026-01-01&to=2026-09-01' );

    expect(
      wide.queries,
      `a year cost ${ wide.queries } queries against a week's ${ narrow.queries }`
    ).toBeLessThanOrEqual( narrow.queries );

    await admin.context.close();
  } );
} );
