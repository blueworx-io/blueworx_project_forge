import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #176. The delivery numbers, against a real WordPress.
//
// The arithmetic is argued with in tests/php/ReportsDeliveryTest, against a
// changelog written by hand, which is the only place the edges can be reached:
// reproducing "a stage entered twice" here means walking an item up the board
// and back down it, and the failure would read as a timeout rather than as a
// wrong number.
//
// What only a real site can show is the rest of it — that the route answers,
// that the figures reconcile to work this spec actually moved, and that a
// window with nothing in it says so rather than drawing a screen of zeroes.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${ Date.now() }-${ Math.floor( Math.random() * 1e6 ) }`;

/** Today and a window that certainly contains it. */
const TODAY = new Date().toISOString().slice( 0, 10 );
const YEAR_AGO = new Date( Date.now() - 300 * 86400000 ).toISOString().slice( 0, 10 );

test('the numbers reconcile to work this run actually moved', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const { site } = await Forge.makeSite( admin.api, `Reports Co ${ RUN_ID }`, RUN_ID );

  const before = await admin.api.get( `/reports?from=${ YEAR_AGO }&to=${ TODAY }` );

  expect( before.ok ).toBe( true );

  const parked = before.reports.stage_distribution['future-idea'];

  const made = await Forge.makeItem( admin.api, site.id, { title: `Counted ${ RUN_ID }` } );

  expect( made.status(), await made.text() ).toBe( 200 );

  const after = await admin.api.get( `/reports?from=${ YEAR_AGO }&to=${ TODAY }` );

  // One more, and exactly one. A report that counts the work it can see is the
  // whole promise: there is nothing stored to fall out of step, so a new item
  // is on the report the moment it exists.
  expect( after.reports.stage_distribution['future-idea'] ).toBe( parked + 1 );

  // Every stage is present whether or not anything is in it, so a chart cannot
  // silently drop a column.
  expect( Object.keys( after.reports.time_in_stage ) ).toEqual(
    Object.keys( after.reports.stage_distribution )
  );

  await admin.context.close();
});

test('a window with nothing in it says so rather than reporting zeroes', async ({
  browser,
  baseURL,
}) => {
  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );

  // A window that ended before this product existed.
  const answer = await admin.api.get( '/reports?from=2019-01-01&to=2019-01-31' );

  expect( answer.ok ).toBe( true );
  expect( answer.reports.cycle_time.count ).toBe( 0 );

  // Null rather than zero. A median of nothing is not a fast delivery.
  expect( answer.reports.cycle_time.median_hours ).toBeNull();

  await admin.context.close();
});

test('a window that makes no sense is refused by name', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );

  const backwards = await admin.api.request.get(
    '/wp-json/blueworx-forge/v1/reports?from=2026-06-01&to=2026-01-01',
    { headers: admin.api.headers }
  );

  expect( backwards.status() ).toBe( 400 );
  expect( ( await backwards.json() ).code ).toBe( 'bwx_forge_bad_window' );

  const forever = await admin.api.request.get(
    '/wp-json/blueworx-forge/v1/reports?from=2000-01-01&to=2026-01-01',
    { headers: admin.api.headers }
  );

  expect( forever.status() ).toBe( 400 );
  expect( ( await forever.json() ).code ).toBe( 'bwx_forge_window_too_long' );

  await admin.context.close();
});

test('a signed-out caller gets nothing', async ({ request }) => {
  const answer = await request.get( '/wp-json/blueworx-forge/v1/reports' );

  expect( [ 401, 403 ] ).toContain( answer.status() );
});

test('the screen draws what the route answers', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const { site } = await Forge.makeSite( admin.api, `Reports Screen Co ${ RUN_ID }`, RUN_ID );

  await Forge.makeItem( admin.api, site.id, { title: `On the report ${ RUN_ID }` } );

  const page = await admin.context.newPage();

  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-reports' ).click();

  await expect( page.getByTestId( 'bwx-reports-header' ) ).toBeVisible();

  // The window the screen asks for by default is twelve weeks, which contains
  // the item just made.
  const table = page.getByTestId( 'bwx-report-stage-distribution' );

  await expect( table ).toBeVisible();

  const answer = await admin.api.get( '/reports' );
  const parked = answer.reports.stage_distribution['future-idea'];

  // The screen shows the server's figure rather than one of its own. A screen
  // that recalculates is a screen that can disagree with the gate.
  await expect( table.locator( '[data-bwx-stage="future-idea"]' ) ).toContainText(
    String( parked )
  );

  // And the sample size is never dropped: a median with nothing beside it is a
  // number that gets quoted.
  await expect( page.getByTestId( 'bwx-report-cycle-time' ) ).toContainText( /median of|not enough/ );

  await page.close();
  await admin.context.close();
});
