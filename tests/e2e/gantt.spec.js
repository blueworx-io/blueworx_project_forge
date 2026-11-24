import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';

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
      process.env.WP_ADMIN_PASS ?? 'admin'
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
    await signIn(
      page,
      process.env.WP_ADMIN_USER ?? 'admin',
      process.env.WP_ADMIN_PASS ?? 'admin'
    );
    await page.goto( '/blueworx-forge/' );
    await page.selectOption( '[data-testid="bwx-client-choice"]', world.site.id );
    await page.locator( '[data-testid="bwx-view-gantt"]' ).click();
    // The first read of a run goes through a single-threaded test server.
    await expect( page.locator( '[data-testid="bwx-gantt"]' ) ).toBeVisible( { timeout: 60_000 } );
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

  test( 'released work is left off the schedule, dated or not (#703)', async ( { page } ) => {
    // Walking real work through every gate to Released is minutes of setup for
    // a rule about what is drawn, so the list says two items are released.
    const released = [ world.late.id, world.undated.id ];

    await page.route( ( url ) => url.pathname.endsWith( '/work-items' ) && url.search.includes( `client_site_id=${ world.site.id }` ), async ( route ) => {
      if ( 'GET' !== route.request().method() ) {
        return route.fallback();
      }

      const response = await route.fetch();
      const body = await response.json();

      body.items = body.items.map( ( item ) => ( released.includes( item.id ) ? { ...item, stage: 'released' } : item ) );
      await route.fulfill( { response, json: body } );
    } );

    await openSchedule( page );

    await expect( page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` ) ).toBeVisible();
    await expect( page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.late.id }"]` ) ).toHaveCount( 0 );
    await expect( page.locator( `[data-testid="bwx-gantt-tray-item"][data-item="${ world.undated.id }"]` ) ).toHaveCount( 0 );
    await expect( page.locator( '[data-testid="bwx-gantt-tray-count"]' ) ).toHaveText( '1' );
  } );

  test( 'the day view gives every day its own column and marks today (#703)', async ( { page } ) => {
    await openSchedule( page );

    const chart = page.locator( '[data-testid="bwx-gantt-chart"]' );
    await expect( chart ).toHaveAttribute( 'data-zoom', 'week' );
    await expect( page.locator( '[data-testid="bwx-gantt-day"]' ) ).toHaveCount( 0 );
    const weeks = await page.locator( '.bwx-gantt-week' ).count();

    await page.locator( '[data-testid="bwx-gantt-zoom-day"]' ).click();
    await expect( chart ).toHaveAttribute( 'data-zoom', 'day' );
    await expect( page.locator( '[data-testid="bwx-gantt-zoom-day"]' ) ).toHaveAttribute( 'aria-pressed', 'true' );

    // The same stretch of time, a column to a day instead of a column to a week.
    await expect( page.locator( '[data-testid="bwx-gantt-day"]' ) ).toHaveCount( weeks * 7 );

    // Today is one column, and the line through the chart is still there.
    const todays = page.locator( '[data-testid="bwx-gantt-day"][data-today="true"]' );
    await expect( todays ).toHaveCount( 1 );
    await expect( todays ).toHaveAttribute( 'data-day', on( 0 ) );
    await expect( page.locator( '[data-testid="bwx-gantt-today"]' ) ).toBeVisible();

    // The bars are still drawn, and still say when.
    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` );
    await expect( bar ).toBeVisible();
    await expect( bar ).toHaveAttribute( 'data-start', on( 2 ) );

    // A day is wide enough to read, so the chart scrolls rather than squeezes.
    const day = await page.locator( '[data-testid="bwx-gantt-day"]' ).first().boundingBox();
    expect( day.width ).toBeGreaterThanOrEqual( 40 );

    await page.locator( '[data-testid="bwx-gantt-zoom-week"]' ).click();
    await expect( chart ).toHaveAttribute( 'data-zoom', 'week' );
    await expect( page.locator( '[data-testid="bwx-gantt-day"]' ) ).toHaveCount( 0 );
  } );

  test( 'the filter bar narrows the schedule like any other view', async ( { page } ) => {
    await openSchedule( page );
    await page.fill( '[data-testid="bwx-search"]', 'card form' );

    await expect( page.locator( '[data-testid="bwx-gantt-row"]' ) ).toHaveCount( 1 );
    await expect( page.locator( '[data-testid="bwx-gantt-tray-item"]' ) ).toHaveCount( 0 );
  } );

  test( 'a selection is dropped when its work is released, so nothing is left dimmed (#703)', async ( { page } ) => {
    let released = false;

    await page.route( ( url ) => url.pathname.endsWith( '/work-items' ) && url.search.includes( `client_site_id=${ world.site.id }` ), async ( route ) => {
      if ( 'GET' !== route.request().method() || ! released ) {
        return route.fallback();
      }

      const response = await route.fetch();
      const body = await response.json();

      body.items = body.items.map( ( item ) => ( item.id === world.second.id ? { ...item, stage: 'released' } : item ) );
      await route.fulfill( { response, json: body } );
    } );

    await openSchedule( page );
    await page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` ).click();
    await expect( page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.late.id }"]` ) ).toHaveAttribute( 'data-dimmed', 'true' );

    // The selected work is released while the schedule is open.
    released = true;
    await page.getByRole( 'button', { name: 'Refresh' } ).click();

    await expect( page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` ) ).toHaveCount( 0 );
    await expect( page.locator( '[data-testid="bwx-gantt-row"][data-dimmed="true"]' ) ).toHaveCount( 0 );
  } );

  test( 'the day view opens with today in view, and a long stretch is a window rather than every day (#703)', async ( { page } ) => {
    // Work that started a long way back, and some that is a mistyped year away.
    for ( const [ name, from, to ] of [ [ 'old', -120, -110 ], [ 'typo', 1400, 1410 ] ] ) {
      const made = await makeItem( world.admin.api, world.site.id, {
        title: `${ RUN } ${ name }`,
        planned_start: on( from ),
        planned_due: on( to ),
      } );
      expect( made.status(), await made.text() ).toBe( 200 );
    }

    await openSchedule( page );
    await page.locator( '[data-testid="bwx-gantt-zoom-day"]' ).click();

    // Seven columns to the week, at most twenty-six weeks of them.
    await expect( page.locator( '[data-testid="bwx-gantt-day"]' ) ).toHaveCount( 26 * 7 );

    // Today is on screen without scrolling to look for it.
    const today = page.locator( '[data-testid="bwx-gantt-day"][data-today="true"]' );
    await expect( today ).toHaveCount( 1 );
    await expect( async () => {
      const at = await today.boundingBox();
      const chart = await page.locator( '[data-testid="bwx-gantt-chart"]' ).boundingBox();

      expect( at.x ).toBeGreaterThanOrEqual( chart.x );
      expect( at.x + at.width ).toBeLessThanOrEqual( chart.x + chart.width + 1 );
    } ).toPass();

    // The work outside the window is named, not dropped.
    await expect( page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } typo") .bwx-gantt-outside` ) ).toBeVisible();

    // Moving along changes which days are drawn; Today brings them back.
    const first = page.locator( '[data-testid="bwx-gantt-day"]' ).first();
    const opening = await first.getAttribute( 'data-day' );
    await page.locator( '[data-testid="bwx-gantt-earlier"]' ).click();
    expect( await first.getAttribute( 'data-day' ) ).not.toBe( opening );
    await page.locator( '[data-testid="bwx-gantt-jump-today"]' ).click();
    await expect( first ).toHaveAttribute( 'data-day', opening );
  } );
} );
