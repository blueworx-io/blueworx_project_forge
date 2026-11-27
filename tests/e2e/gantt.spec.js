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

/**
 * Dates either side of today, so overdue means overdue rather than "in 2019".
 * Today is the machine's own calendar date, which is what the browser the tests
 * drive uses, not the UTC date `toISOString` would give: near midnight the two
 * are a day apart, and the chart marks the viewer's day.
 */
const pad = ( number ) => String( number ).padStart( 2, '0' );
const on = ( offset ) => {
  const at = new Date();

  at.setDate( at.getDate() + offset );

  return `${ at.getFullYear() }-${ pad( at.getMonth() + 1 ) }-${ pad( at.getDate() ) }`;
};

/** The date in a named timezone, worked out apart from anything the app does. */
const inZone = ( timeZone ) => new Intl.DateTimeFormat( 'en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' } ).format( new Date() );

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
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
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

  test( 'the chart opens by the day, with today marked, and the weeks are one click away (#703)', async ( { page } ) => {
    await openSchedule( page );

    const chart = page.locator( '[data-testid="bwx-gantt-chart"]' );
    await expect( chart ).toHaveAttribute( 'data-zoom', 'day' );
    await expect( page.locator( '[data-testid="bwx-gantt-zoom-day"]' ) ).toHaveAttribute( 'aria-pressed', 'true' );

    // Never further back than two weeks, and a column to a day.
    const days = page.locator( '[data-testid="bwx-gantt-day"]' );
    await expect( days.first() ).toHaveAttribute( 'data-day', on( -14 ) );
    const last = await days.last().getAttribute( 'data-day' );
    expect( last >= on( 20 ) && last <= on( 42 ) ).toBe( true );

    // Today is one column, and the line through the chart is still there.
    const todays = page.locator( '[data-testid="bwx-gantt-day"][data-today="true"]' );
    await expect( todays ).toHaveCount( 1 );
    await expect( todays ).toHaveAttribute( 'data-day', on( 0 ) );
    await expect( page.locator( '[data-testid="bwx-gantt-today"]' ) ).toBeVisible();

    // The bars are still drawn, and still say when.
    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` );
    await expect( bar ).toBeVisible();
    await expect( bar ).toHaveAttribute( 'data-start', on( 2 ) );

    // A day is six times what it was, 264px, so the chart scrolls rather than squeezes.
    // Measured from one day to the next after the first, which has no hairline of its own.
    const first = await days.nth( 1 ).boundingBox();
    const second = await days.nth( 2 ).boundingBox();
    expect( Math.round( second.x - first.x ) ).toBe( 264 );

    // The weeks cover the same stretch, the weeks that hold the limits drawn whole.
    await page.locator( '[data-testid="bwx-gantt-zoom-week"]' ).click();
    await expect( chart ).toHaveAttribute( 'data-zoom', 'week' );
    await expect( days ).toHaveCount( 0 );
    const weeks = page.locator( '.bwx-gantt-week' );
    expect( ( await weeks.first().getAttribute( 'data-week' ) ) >= on( -20 ) ).toBe( true );
    expect( ( await weeks.last().getAttribute( 'data-week' ) ) <= on( 42 ) ).toBe( true );

    await page.locator( '[data-testid="bwx-gantt-zoom-day"]' ).click();
    await expect( todays ).toHaveCount( 1 );
  } );

  test( 'one click on a bar opens the item (Luke, 27 Nov)', async ( { page } ) => {
    await openSchedule( page );
    await expect( page.getByTestId( 'bwx-panel' ) ).toHaveCount( 0 );

    await page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` ).click();

    const panel = page.getByTestId( 'bwx-panel' );
    await expect( panel ).toBeVisible();
    await expect( panel ).toContainText( 'guest checkout' );
  } );

  test( 'hovering a name, or focusing it, shows the full name (Luke, 27 Nov)', async ( { page } ) => {
    const long = `${ RUN } a name long enough to be cut short by the left column of the chart`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("a name long enough")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await expect( tip ).toBeHidden();
    await row.locator( '.bwx-gantt-title' ).hover();
    await expect( tip ).toBeVisible();
    await expect( tip ).toHaveText( long );

    // Away again, it goes; focus brings it back for the keyboard.
    await page.mouse.move( 0, 0 );
    await expect( tip ).toBeHidden();
    await row.locator( '.bwx-gantt-title' ).focus();
    await expect( tip ).toBeVisible();
    await expect( tip ).toHaveText( long );
  } );

  test( 'the chart scrolls sideways, so the keyboard can reach it and it has a name (review of #720)', async ( { page } ) => {
    await openSchedule( page );

    const chart = page.getByTestId( 'bwx-gantt-chart' );
    await expect( chart ).toHaveAttribute( 'tabindex', '0' );
    await expect( chart ).toHaveAttribute( 'role', 'region' );
    await expect( chart ).toHaveAttribute( 'aria-label', /schedule/i );

    await chart.focus();
    await expect( chart ).toBeFocused();
  } );

  test( 'a name still shows in full over a dimmed row (review of #720)', async ( { page } ) => {
    await openSchedule( page );

    // Picking a bar dims every row it is not linked to, and stays after the panel closes.
    await page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` ).click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();

    const late = page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.late.id }"]` );
    await expect( late ).toHaveAttribute( 'data-dimmed', 'true' );

    await late.locator( '.bwx-gantt-title' ).hover();
    const tip = late.locator( '[data-testid="bwx-gantt-name-tip"]' );
    await expect( tip ).toBeVisible();

    // Nothing from the tip up to the page fades it, so it is as readable as on any other row.
    const faded = await tip.evaluate( ( node ) => {
      let opacity = 1;

      for ( let at = node; at; at = at.parentElement ) {
        opacity *= Number( getComputedStyle( at ).opacity );
      }

      return opacity;
    } );
    expect( faded ).toBe( 1 );
  } );

  // Today is the viewer's own date. A browser a day ahead of UTC and one a day
  // behind it cannot both agree with it, so one of the two always checks it.
  for ( const zone of [ 'Pacific/Kiritimati', 'Pacific/Pago_Pago' ] ) {
    test.describe( `in ${ zone }`, () => {
      test.use( { timezoneId: zone } );

      test( 'today is the date there, not the UTC date (#703)', async ( { page } ) => {
        await openSchedule( page );

        await expect( page.locator( '[data-testid="bwx-gantt-day"][data-today="true"]' ) ).toHaveAttribute( 'data-day', inZone( zone ) );
      } );
    } );
  }

  test( 'the filter bar narrows the schedule like any other view', async ( { page } ) => {
    await openSchedule( page );
    await page.fill( '[data-testid="bwx-search"]', 'card form' );

    await expect( page.locator( '[data-testid="bwx-gantt-row"]' ) ).toHaveCount( 1 );
    await expect( page.locator( '[data-testid="bwx-gantt-tray-item"]' ) ).toHaveCount( 0 );
  } );

  test( 'a selection is dropped when its work is released, so nothing is left dimmed (#703)', async ( { page } ) => {
    // Something to delete from its panel: a change the screen takes in place,
    // where the header's Refresh would start the whole screen again and lose
    // the selection whether or not it is handled.
    const throwaway = await makeItem( world.admin.api, world.site.id, { title: `${ RUN } throwaway`, work_type: 'task' } );
    expect( throwaway.status(), await throwaway.text() ).toBe( 200 );

    let released = false;

    await page.route( ( url ) => url.pathname.endsWith( '/work-items' ) && url.search.includes( `client_site_id=${ world.site.id }` ), async ( route ) => {
      if ( 'GET' !== route.request().method() || ! released ) {
        return route.fallback();
      }

      try {
        const response = await route.fetch();
        const body = await response.json();

        body.items = body.items.map( ( item ) => ( item.id === world.second.id ? { ...item, stage: 'released' } : item ) );
        await route.fulfill( { response, json: body } );
      } catch {
        // A read still on its way when the page closes has nobody left to answer.
      }
    } );

    await openSchedule( page );
    await page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` ).click();
    // The click opens the item; put its panel away to get at the chart again.
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();
    const late = page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.late.id }"]` );
    await expect( late ).toHaveAttribute( 'data-dimmed', 'true' );

    // Marks the screen, to show later that it was never drawn again from scratch.
    await page.evaluate( () => document.querySelector( '[data-testid="bwx-gantt"]' ).setAttribute( 'data-kept', 'yes' ) );

    // The selected work is released by the time the list is read again.
    released = true;
    await page.locator( '[data-testid="bwx-gantt-tray-item"]', { hasText: 'throwaway' } ).click();
    page.once( 'dialog', ( dialog ) => dialog.accept() );
    await page.getByTestId( 'bwx-item-delete' ).click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden( { timeout: 60_000 } );

    await expect( page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` ) ).toHaveCount( 0 );
    await expect( late ).toBeVisible();
    await expect( late ).not.toHaveAttribute( 'data-dimmed', 'true' );
    await expect( page.locator( '[data-testid="bwx-gantt-row"][data-dimmed="true"]' ) ).toHaveCount( 0 );
    await expect( page.locator( '[data-testid="bwx-gantt"]' ) ).toHaveAttribute( 'data-kept', 'yes' );

    // Nothing is left reading the list when the page goes.
    await page.unrouteAll( { behavior: 'ignoreErrors' } );
  } );

  test( 'work outside the two weeks back and six ahead is named, not drawn, and Today stays in view (#703)', async ( { page } ) => {
    // Work that finished long ago, and some that is a mistyped year away.
    // Each date is worked out once and the same one is saved and checked, so a
    // run that crosses midnight cannot disagree with itself.
    const outsideDue = {};

    for ( const [ name, from, to ] of [ [ 'old', -120, -110 ], [ 'typo', 1400, 1410 ] ] ) {
      outsideDue[ name ] = on( to );

      const made = await makeItem( world.admin.api, world.site.id, {
        title: `${ RUN } ${ name }`,
        planned_start: on( from ),
        planned_due: outsideDue[ name ],
      } );
      expect( made.status(), await made.text() ).toBe( 200 );
    }

    // Work on the last day inside the limit, in a week the limit cuts in two.
    const edge = await makeItem( world.admin.api, world.site.id, { title: `${ RUN } edge`, planned_start: on( 41 ), planned_due: on( 42 ) } );
    expect( edge.status(), await edge.text() ).toBe( 200 );
    const edgeBar = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } edge") [data-testid="bwx-gantt-bar"]` );

    await openSchedule( page );

    // Fifty-seven days at most: two weeks back to six weeks ahead.
    const days = page.locator( '[data-testid="bwx-gantt-day"]' );
    expect( await days.count() ).toBeLessThanOrEqual( 57 );
    await expect( days.first() ).toHaveAttribute( 'data-day', on( -14 ) );
    expect( ( await days.last().getAttribute( 'data-day' ) ) <= on( 42 ) ).toBe( true );

    // The work outside is named, not dropped, and its bar is not drawn.
    for ( const name of [ 'old', 'typo' ] ) {
      const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } ${ name }")` );

      await expect( row.locator( '.bwx-gantt-outside' ) ).toBeVisible();
      // The year is said, so a mistyped one can be seen without opening the work.
      await expect( row.locator( '.bwx-gantt-outside' ) ).toContainText( outsideDue[ name ].slice( 0, 4 ) );
      await expect( row.locator( '[data-testid="bwx-gantt-bar"]' ) ).toHaveCount( 0 );
    }

    // Unfinished work that ran out long ago still says it is overdue.
    await expect( page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } old") .bwx-gantt-outside` ) ).toContainText( 'overdue' );

    // Today is on screen without scrolling to look for it, and again after
    // going to the weeks and back.
    const today = page.locator( '[data-testid="bwx-gantt-day"][data-today="true"]' );
    const inView = async () => {
      const at = await today.boundingBox();
      const chart = await page.locator( '[data-testid="bwx-gantt-chart"]' ).boundingBox();

      expect( at.x ).toBeGreaterThanOrEqual( chart.x );
      expect( at.x + at.width ).toBeLessThanOrEqual( chart.x + chart.width + 1 );
    };

    await expect( today ).toHaveCount( 1 );
    await expect( inView ).toPass();

    await expect( edgeBar ).toHaveCount( 1 );
    await page.locator( '[data-testid="bwx-gantt-zoom-week"]' ).click();
    await expect( edgeBar ).toHaveCount( 1 );
    await page.locator( '[data-testid="bwx-gantt-zoom-day"]' ).click();
    await expect( inView ).toPass();

    // A name with no bar still opens its work, as the tray does.
    await page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } old") [data-testid="bwx-gantt-title-open"]` ).click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await page.keyboard.press( 'Escape' );

    // Nothing to page through: there is no further to go.
    await expect( page.locator( '[data-testid="bwx-gantt-earlier"], [data-testid="bwx-gantt-later"]' ) ).toHaveCount( 0 );
  } );

  test.describe( 'on the narrowest phone', () => {
    test.use( { viewport: { width: 320, height: 640 } } );

    test( 'the word Today on the line is whole on screen (review of #720)', async ( { page } ) => {
      await openSchedule( page );
      await page.locator( '[data-testid="bwx-gantt-jump-today"]' ).click();

      const tag = page.locator( '.bwx-gantt-today-tag' );
      await expect( tag ).toBeVisible();

      const chart = page.locator( '[data-testid="bwx-gantt-chart"]' );

      await expect( async () => {
        const box = await tag.boundingBox();
        const frame = await chart.boundingBox();
        const names = await page.locator( '.bwx-gantt-axis-gutter' ).boundingBox();

        // Clear of the pinned names on the left and of the edge on the right.
        expect( box.x ).toBeGreaterThanOrEqual( names.x + names.width );
        expect( box.x + box.width ).toBeLessThanOrEqual( frame.x + frame.width );
      } ).toPass();
    } );
  } );

  test.describe( 'on a phone', () => {
    test.use( { viewport: { width: 390, height: 844 } } );

    test( 'task names stay visible in the day view, and notices fit the screen (#703)', async ( { page } ) => {
      const made = await makeItem( world.admin.api, world.site.id, {
        title: `${ RUN } phone old`,
        planned_start: on( -120 ),
        planned_due: on( -110 ),
      } );
      expect( made.status(), await made.text() ).toBe( 200 );

      await openSchedule( page );
      await page.locator( '[data-testid="bwx-gantt-jump-today"]' ).click();

      const chart = await page.locator( '[data-testid="bwx-gantt-chart"]' ).boundingBox();

      // Scrolled to today, a name is still beside the days, not scrolled away.
      const label = page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.first.id }"] .bwx-gantt-label` );
      await expect( label ).toBeInViewport( { ratio: 1 } );
      await expect( label.locator( '.bwx-gantt-title' ) ).toBeVisible();

      // A day is wider than the room beside the names, and today is still on screen.
      await expect( page.locator( '[data-testid="bwx-gantt-today"]' ) ).toBeInViewport();
      await expect( page.locator( '[data-testid="bwx-gantt-day"][data-today="true"]' ) ).toBeInViewport();
      expect( ( await label.boundingBox() ).x ).toBeGreaterThanOrEqual( chart.x );

      // The notice for work outside the weeks wraps on the screen rather than running off it.
      const notice = page.locator( '[data-testid="bwx-gantt-row"]:has-text("phone old") .bwx-gantt-outside' );
      await expect( notice ).toBeInViewport( { ratio: 1 } );
      const box = await notice.boundingBox();
      expect( box.x + box.width ).toBeLessThanOrEqual( 390 );
    } );
  } );
} );
