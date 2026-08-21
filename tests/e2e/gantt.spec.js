import { test, expect } from '@playwright/test';
import { signedIn, signInPage, makeSite, makeItem } from './helpers/forge.js';

// The Gantt view (#120). What it has to say is the schedule — and, just as
// much, what has no schedule. Work with no dates is the thing a Gantt
// traditionally drops on the floor: it cannot be drawn on a time axis, so it
// stops being visible, and nobody notices it was never planned.
//
// These drive the real app against a real WordPress, like the board specs, and
// read the same records the other two views read.

const RUN = `gt${Date.now()}`;

/** Dates either side of today, so overdue means overdue rather than "in 2019". */
const DAY = 86400000;
const on = ( offset ) => new Date( Date.now() + offset * DAY ).toISOString().slice( 0, 10 );

test.describe( 'the schedule', () => {
  test.describe.configure( { mode: 'serial' } );

  let world;

  test.beforeAll( async ( { browser, baseURL } ) => {
    const admin = await signedIn(
      browser,
      baseURL,
      process.env.WP_ADMIN_USER ?? 'admin',
      process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw'
    );

    const { client, site } = await makeSite( admin.api, `${ RUN } schedule`, RUN );

    const make = async ( data ) => {
      const made = await makeItem( admin.api, site.id, data );
      expect( made.status(), await made.text() ).toBe( 200 );
      return ( await made.json() ).item;
    };

    // A parent with no dates of its own, and two children that have them. What
    // the parent spans is a question about its children, not about itself.
    const parent = await make( { title: `${ RUN } checkout`, level: 'feature' } );
    const first = await make( {
      title: `${ RUN } guest checkout`,
      parent_id: parent.id,
      planned_start: on( 2 ),
      planned_due: on( 9 ),
    } );
    const second = await make( {
      title: `${ RUN } address lookup`,
      parent_id: parent.id,
      planned_start: on( 10 ),
      planned_due: on( 20 ),
    } );

    // Past its due date and nowhere near done.
    const late = await make( {
      title: `${ RUN } card form`,
      work_type: 'bug',
      planned_start: on( -20 ),
      planned_due: on( -3 ),
    } );

    // No dates at either end. The whole point of the tray.
    const undated = await make( { title: `${ RUN } rotate the keys`, work_type: 'task' } );
    const alsoUndated = await make( { title: `${ RUN } promo banner`, work_type: 'feedback' } );

    // Second waits on first, so the schedule has a sequence in it.
    const waiting = await admin.api.post( `/work-items/${ second.id }/dependencies`, {
      depends_on_id: first.id,
    } );
    expect( waiting.status(), await waiting.text() ).toBe( 200 );

    world = { admin, client, site, parent, first, second, late, undated, alsoUndated };
  } );

  test.afterAll( async () => {
    await world?.admin.context.close();
  } );

  /*
   * Opened per test rather than in a beforeEach, because one of these is about
   * the API and has no page in it. A beforeEach that drove the browser would
   * fail that test for a reason it is not about.
   */
  async function openSchedule( page ) {
    await signInPage(
      page,
      process.env.WP_ADMIN_USER ?? 'admin',
      process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw'
    );
    await page.goto( '/blueworx-forge/' );
    await page.selectOption( '[data-testid="bwx-site"]', world.site.id );
    await page.locator( '[data-testid="bwx-view-gantt"]' ).click();
    await expect( page.locator( '[data-testid="bwx-gantt"]' ) ).toBeVisible();
  }

  test( 'the list says what each item waits on, so a schedule can draw a sequence', async () => {
    // The single-item read has carried dependencies since #103. A schedule
    // needs them for everything on screen at once, and one request per bar is
    // not a way to draw a chart.
    const listed = await world.admin.api.get( `/work-items?client_site_id=${ world.site.id }` );
    const second = listed.items.find( ( item ) => item.id === world.second.id );
    const first = listed.items.find( ( item ) => item.id === world.first.id );

    expect( second.waits_on ).toEqual( [ world.first.id ] );
    expect( first.waits_on ).toEqual( [] );
  } );

  test( 'it is a third view beside the board and the list', async ( { page } ) => {
    await openSchedule( page );
    await expect( page.locator( '[data-testid="bwx-view-gantt"]' ) ).toHaveAttribute( 'aria-pressed', 'true' );
    await expect( page.locator( '[data-testid="bwx-board"]' ) ).toHaveCount( 0 );
    await expect( page.locator( '[data-testid="bwx-list"]' ) ).toHaveCount( 0 );
  } );

  test( 'work with dates is drawn, work without dates is not', async ( { page } ) => {
    await openSchedule( page );
    await expect( page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` ) ).toBeVisible();
    await expect( page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` ) ).toBeVisible();
    await expect( page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.undated.id }"]` ) ).toHaveCount( 0 );
  } );

  test( 'undated work is in the tray rather than missing', async ( { page } ) => {
    await openSchedule( page );
    const tray = page.locator( '[data-testid="bwx-gantt-tray"]' );

    // Open, because the issue asks for visible rather than merely reachable.
    await expect( tray ).toBeVisible();
    await expect( tray.locator( '[data-testid="bwx-gantt-tray-item"]' ) ).toHaveCount( 2 );
    await expect( tray ).toContainText( 'rotate the keys' );
    await expect( tray ).toContainText( 'promo banner' );
  } );

  test( 'the tray still says how much has no schedule when it is closed', async ( { page } ) => {
    await openSchedule( page );
    await page.locator( '[data-testid="bwx-gantt-tray-toggle"]' ).click();

    await expect( page.locator( '[data-testid="bwx-gantt-tray-item"]' ) ).toHaveCount( 0 );
    await expect( page.locator( '[data-testid="bwx-gantt-tray-count"]' ) ).toHaveText( '2' );
  } );

  test( 'work past its due date says so', async ( { page } ) => {
    await openSchedule( page );
    const late = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.late.id }"]` );
    await expect( late ).toHaveAttribute( 'data-overdue', 'true' );

    const early = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` );
    await expect( early ).not.toHaveAttribute( 'data-overdue', 'true' );
  } );

  test( 'a parent with no dates of its own spans its children', async ( { page } ) => {
    await openSchedule( page );
    const parent = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.parent.id }"]` );

    // It has no planned dates, so anything it spans it got from beneath it.
    await expect( parent ).toBeVisible();
    await expect( parent ).toHaveAttribute( 'data-derived', 'true' );
    await expect( parent ).toHaveAttribute( 'data-start', on( 2 ) );
    await expect( parent ).toHaveAttribute( 'data-due', on( 20 ) );
  } );

  test( 'work waiting on other work says what it waits on', async ( { page } ) => {
    await openSchedule( page );
    const row = page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.second.id }"]` );
    await expect( row ).toHaveAttribute( 'data-waiting', 'true' );

    // And the chain is readable from the schedule rather than only from the
    // panel: selecting it marks both ends.
    await row.locator( '[data-testid="bwx-gantt-bar"]' ).click();
    await expect( page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.first.id }"]` ) )
      .toHaveAttribute( 'data-chain', 'upstream' );
    await expect( row ).toHaveAttribute( 'data-chain', 'selected' );
  } );

  test( 'the filter bar narrows the schedule like any other view', async ( { page } ) => {
    await openSchedule( page );
    await page.fill( '[data-testid="bwx-search"]', 'card form' );

    await expect( page.locator( '[data-testid="bwx-gantt-row"]' ) ).toHaveCount( 1 );
    await expect( page.locator( '[data-testid="bwx-gantt-tray-item"]' ) ).toHaveCount( 0 );
  } );
} );
