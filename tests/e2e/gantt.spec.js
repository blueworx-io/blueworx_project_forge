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
    await row.locator( '.bwx-gantt-label' ).hover();
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

  test( 'the full name of the last row fits inside the chart, not cut off below it (review of #720)', async ( { page } ) => {
    const long = `${ RUN } zz the very last row has a name so long that it runs over several lines of the left column before it ends`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 30 ), planned_due: on( 32 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await openSchedule( page );

    const row = page.locator( '[data-testid="bwx-gantt-row"]:has-text("the very last row")' );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.scrollIntoViewIfNeeded();
    await row.locator( '.bwx-gantt-label' ).hover();
    await expect( tip ).toBeVisible();
    await expect( tip ).toHaveText( long );

    await expect( async () => {
      const inner = await tip.boundingBox();
      const frame = await page.getByTestId( 'bwx-gantt-chart' ).evaluate( ( node ) => {
        const box = node.getBoundingClientRect();
        return { top: box.top, bottom: box.top + node.clientHeight };
      } );

      expect( inner.y ).toBeGreaterThanOrEqual( frame.top - 1 );
      expect( inner.y + inner.height ).toBeLessThanOrEqual( frame.bottom + 1 );
    } ).toPass();
  } );

  test( 'closing the item a bar opened puts focus back on the bar (review of #720)', async ( { page } ) => {
    await openSchedule( page );

    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` );
    await bar.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();

    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();
    await expect( bar ).toBeFocused();
  } );

  test( 'a name taller than the chart still shows whole, over the screen (review of #720)', async ( { page } ) => {
    const long = `${ RUN } tall ${ 'a name that goes on and on '.repeat( 5 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 390, height: 520 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } tall")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.locator( '.bwx-gantt-label' ).hover();
    await expect( tip ).toBeVisible();
    await expect( tip ).toHaveText( long );
    await expect( tip ).toBeInViewport( { ratio: 1 } );
  } );

  test( 'deleting an item from its panel leaves focus on the chart, not nowhere (review of #720)', async ( { page } ) => {
    const made = await makeItem( world.admin.api, world.site.id, { title: `${ RUN } doomed bar`, planned_start: on( 4 ), planned_due: on( 6 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const doomed = ( await made.json() ).item;

    await openSchedule( page );
    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ doomed.id }"]` );
    await bar.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();

    page.once( 'dialog', ( dialog ) => dialog.accept() );
    await page.getByTestId( 'bwx-item-delete' ).click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden( { timeout: 60_000 } );
    await expect( bar ).toHaveCount( 0 );
    await expect( page.getByTestId( 'bwx-gantt-chart' ) ).toBeFocused();
  } );

  test( 'closing an item opened from the Without dates tray keeps focus in the tray (review of #720)', async ( { page } ) => {
    const made = await makeItem( world.admin.api, world.site.id, { title: `${ RUN } doomed tray`, work_type: 'task' } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await openSchedule( page );
    const entry = page.locator( '[data-testid="bwx-gantt-tray-item"]', { hasText: `${ RUN } doomed tray` } );
    await entry.click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();
    await expect( entry ).toBeFocused();

    // And when the work is gone, focus stays at the tray rather than jumping up to the chart.
    await entry.click();
    page.once( 'dialog', ( dialog ) => dialog.accept() );
    await page.getByTestId( 'bwx-item-delete' ).click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden( { timeout: 60_000 } );
    await expect( entry ).toHaveCount( 0 );
    await expect( page.getByTestId( 'bwx-gantt-tray-toggle' ) ).toBeFocused();
  } );

  test( 'focus goes back only to what opened the item: a bar, then the Blocked strip (review of #720)', async ( { page } ) => {
    const made = await makeItem( world.admin.api, world.site.id, { title: `${ RUN } stuck`, planned_start: on( 4 ), planned_due: on( 6 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const stuck = ( await made.json() ).item;
    const blocked = await world.admin.api.post( `/work-items/${ stuck.id }/block`, { owner: 'The client', record_version: stuck.record_version } );
    expect( blocked.status(), await blocked.text() ).toBe( 200 );

    await openSchedule( page );

    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` );
    await bar.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( bar ).toBeFocused();

    // Opened from the strip under the chart, the bar it was last on is not where focus belongs.
    const strip = page.locator( `[data-testid="bwx-blocked-item"][data-item="${ stuck.id }"]` );
    await strip.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();
    await expect( bar ).not.toBeFocused();
    await expect( strip ).toBeFocused();
  } );

  test( 'opening a bar while another item is open sends focus back to that bar, not the Blocked strip (review of #720)', async ( { page } ) => {
    const made = await makeItem( world.admin.api, world.site.id, { title: `${ RUN } stuck again`, planned_start: on( 4 ), planned_due: on( 6 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const stuck = ( await made.json() ).item;
    const blocked = await world.admin.api.post( `/work-items/${ stuck.id }/block`, { owner: 'The client', record_version: stuck.record_version } );
    expect( blocked.status(), await blocked.text() ).toBe( 200 );

    await openSchedule( page );

    const strip = page.locator( `[data-testid="bwx-blocked-item"][data-item="${ stuck.id }"]` );
    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` );

    await strip.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();

    // A different item from the chart, with the first panel still open.
    await bar.dispatchEvent( 'click' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toContainText( `${ RUN } guest checkout` );

    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();
    await expect( bar ).toBeFocused();
    await expect( strip ).not.toBeFocused();
  } );

  test( 'a long name stays while the pointer moves onto it, and Escape puts it away (review of #720)', async ( { page } ) => {
    const long = `${ RUN } esc ${ 'a name that goes on and on '.repeat( 4 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } esc")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );
    const title = row.locator( '.bwx-gantt-title' );
    const label = row.locator( '.bwx-gantt-label' );

    await label.hover();
    await expect( tip ).toBeVisible();

    // Down onto the part that runs over the next row: still there.
    const at = await tip.boundingBox();
    const own = await row.boundingBox();
    expect( at.y + at.height, 'the name runs below its own row' ).toBeGreaterThan( own.y + own.height + 4 );
    await page.mouse.move( at.x + 20, at.y + at.height - 4 );
    await expect( tip ).toBeVisible();

    // Escape closes it with the pointer where it is, and it comes back on the next visit.
    await page.keyboard.press( 'Escape' );
    await expect( tip ).toBeHidden();
    await page.mouse.move( 0, 0 );
    await label.hover();
    await expect( tip ).toBeVisible();

    // From the keyboard, focus stays where it is.
    await page.mouse.move( 0, 0 );
    await title.focus();
    await expect( tip ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( tip ).toBeHidden();
    await expect( title ).toBeFocused();

    // Hovered as well as focused, then the pointer leaves: focus is still on the
    // name, so it stays put away rather than coming back.
    await title.blur();
    await title.focus();
    await label.hover();
    await expect( tip ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( tip ).toBeHidden();
    await page.mouse.move( 0, 0 );
    await expect( tip ).toBeHidden();
    await expect( title ).toBeFocused();
  } );

  test( 'a name put away with Escape stays away while the pointer is on it, even after focus moves on (review of #720)', async ( { page } ) => {
    const long = `${ RUN } hov ${ 'a name that goes on and on '.repeat( 4 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } hov")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );
    const title = row.locator( '.bwx-gantt-title' );

    await title.focus();
    await row.locator( '.bwx-gantt-label' ).hover();
    await expect( tip ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( tip ).toBeHidden();

    // Focus moves on to the bar; the pointer has not left the name.
    await page.keyboard.press( 'Tab' );
    await expect( title ).not.toBeFocused();
    await expect( tip ).toBeHidden();
  } );

  test( 'every day names its month, so a day is never a bare number while scrolling (review of #720)', async ( { page } ) => {
    await openSchedule( page );

    const labels = await page.locator( '.bwx-gantt-day-label' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => node.textContent ) );
    expect( labels.length ).toBeGreaterThan( 20 );

    for ( const label of labels ) {
      expect( label ).toMatch( /^[A-Z][a-z]{2}\d{1,2} [A-Z][a-z]{2}$/ );
    }
  } );

  test( 'a bar is named after its item, not only its dates (review of #720)', async ( { page } ) => {
    await openSchedule( page );

    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.first.id }"]` );
    await expect( bar ).toHaveAttribute( 'aria-label', new RegExp( `^${ RUN } guest checkout: ` ) );
    await expect( page.getByRole( 'button', { name: new RegExp( `^${ RUN } guest checkout: ` ) } ) ).toHaveCount( 1 );
  } );

  test( 'unblocking an item from its panel and pressing Escape leaves focus in the strip or the view, not nowhere (review of #720)', async ( { page } ) => {
    const make = async ( name ) => {
      const made = await makeItem( world.admin.api, world.site.id, { title: `${ RUN } ${ name }`, planned_start: on( 4 ), planned_due: on( 6 ) } );
      expect( made.status(), await made.text() ).toBe( 200 );
      const item = ( await made.json() ).item;
      const blocked = await world.admin.api.post( `/work-items/${ item.id }/block`, { owner: 'The client', record_version: item.record_version } );
      expect( blocked.status(), await blocked.text() ).toBe( 200 );
      return item;
    };

    const one = await make( 'unblock one' );
    await make( 'unblock two' );

    await openSchedule( page );

    const strip = page.locator( `[data-testid="bwx-blocked-item"][data-item="${ one.id }"]` );
    await strip.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await page.getByTestId( 'bwx-resolution' ).fill( 'Sorted.' );
    await page.getByTestId( 'bwx-unblock' ).click();
    await expect( strip ).toHaveCount( 0, { timeout: 60_000 } );

    // The Unblock button went with the state, so focus is put back inside the panel to press Escape there.
    await page.locator( '.bwx-panel-head .bwx-icon-button[aria-label="Close"]' ).focus();
    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();
    // The button it came from is gone, so focus goes to what is left in the strip.
    await expect( page.getByTestId( 'bwx-blocked-item' ).first() ).toBeFocused();
  } );

  test( 'clicking a bar again keeps its marks; clicking the empty chart clears them (review of #720)', async ( { page } ) => {
    await openSchedule( page );

    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` );
    const row = page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.second.id }"]` );
    const close = page.locator( '.bwx-panel-head .bwx-icon-button[aria-label="Close"]' );

    await bar.click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await expect( row ).toHaveAttribute( 'data-chain', 'selected' );
    await close.click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();

    // The same bar again opens it again and the marks stay.
    await bar.click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await expect( row ).toHaveAttribute( 'data-chain', 'selected' );
    await close.click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();

    await page.locator( '.bwx-gantt-rows' ).dispatchEvent( 'click' );
    await expect( row ).not.toHaveAttribute( 'data-chain', /.+/ );
  } );

  test( 'closing an item with Escape from a row with no bar leaves its full name showing (review of #720)', async ( { page } ) => {
    const long = `${ RUN } far ${ 'a name that goes on and on '.repeat( 4 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 100 ), planned_due: on( 102 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } far")` );
    const open = row.getByTestId( 'bwx-gantt-title-open' );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await open.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();
    await expect( open ).toBeFocused();
    await expect( tip ).toBeVisible();

    // It sorts last on the chart and would change which row is the last for the
    // tests after it, so it goes again.
    await tip.click();
    page.once( 'dialog', ( dialog ) => dialog.accept() );
    await page.getByTestId( 'bwx-item-delete' ).click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden( { timeout: 60_000 } );
    await expect( row ).toHaveCount( 0 );
  } );

  test( 'a name drawn over the screen follows its row when the chart scrolls (review of #720)', async ( { page } ) => {
    const long = `${ RUN } follow ${ 'a name that goes on and on '.repeat( 5 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 390, height: 520 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } follow")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.locator( '.bwx-gantt-label' ).hover();
    await expect( tip ).toBeVisible();
    await expect( tip ).toHaveAttribute( 'data-flip', 'screen' );

    // Stand in for where it was first drawn, then scroll: it is placed again.
    await tip.evaluate( ( node ) => { node.style.top = '1234px'; } );
    await page.getByTestId( 'bwx-gantt-chart' ).evaluate( ( node ) => { node.scrollLeft += 40; } );
    await expect.poll( () => tip.evaluate( ( node ) => node.style.top ) ).not.toBe( '1234px' );
    await expect( tip ).toBeInViewport( { ratio: 1 } );
  } );

  test( 'Escape on the chart clears the marks a bar left, so the keyboard can (review of #720)', async ( { page } ) => {
    await openSchedule( page );

    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` );
    const row = page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.second.id }"]` );

    await bar.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await expect( row ).toHaveAttribute( 'data-chain', 'selected' );

    // The first Escape closes the panel and leaves the marks; the next, from the bar, clears them.
    await page.keyboard.press( 'Escape' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();
    await expect( bar ).toBeFocused();
    await expect( row ).toHaveAttribute( 'data-chain', 'selected' );
    await page.keyboard.press( 'Escape' );
    await expect( row ).not.toHaveAttribute( 'data-chain', /.+/ );
  } );

  test( 'a name on show is placed again whenever the chart scrolls, not only when it was drawn over the screen (review of #720)', async ( { page } ) => {
    const long = `${ RUN } back ${ 'a name that goes on and on '.repeat( 4 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } back")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.locator( '.bwx-gantt-label' ).hover();
    await expect( tip ).toBeVisible();
    await expect( tip ).not.toHaveAttribute( 'data-flip', 'screen' );

    await tip.evaluate( ( node ) => { node.style.top = '1234px'; } );
    await page.getByTestId( 'bwx-gantt-chart' ).evaluate( ( node ) => { node.scrollLeft += 40; } );
    await expect.poll( () => tip.evaluate( ( node ) => node.style.top ) ).not.toBe( '1234px' );
  } );

  test( 'a focused name on a row half cut off by the chart still shows whole (review of #720)', async ( { page } ) => {
    const long = `${ RUN } halfcut ${ 'the last row has a name so long that it wraps ' .repeat( 3 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 31 ), planned_due: on( 33 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 1000, height: 560 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } halfcut")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );
    const chart = page.getByTestId( 'bwx-gantt-chart' );

    // Scrolled so the bottom of the row is 12 pixels below the chart's edge.
    await row.scrollIntoViewIfNeeded();
    await chart.evaluate( ( node, id ) => {
      const mine = node.querySelector( `[data-item="${ id }"]` );
      const over = mine.getBoundingClientRect().bottom - ( node.getBoundingClientRect().top + node.clientHeight );
      node.scrollTop += over - 12;
    }, ( await made.json() ).item.id );

    await row.locator( '.bwx-gantt-title' ).evaluate( ( node ) => node.focus( { preventScroll: true } ) );
    await expect( tip ).toBeVisible();
    await expect( tip ).toBeInViewport( { ratio: 1 } );
  } );

  test( 'a name goes when its row scrolls out of view, and comes back with the row (review of #720)', async ( { page } ) => {
    const long = `${ RUN } leaves ${ 'a name that goes on and on '.repeat( 4 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const id = ( await made.json() ).item.id;

    // A short screen, so the chart is short and its rows scroll well out of view.
    await page.setViewportSize( { width: 1000, height: 440 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"][data-item="${ id }"]` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );
    const chart = page.getByTestId( 'bwx-gantt-chart' );

    await row.scrollIntoViewIfNeeded();
    await row.locator( '.bwx-gantt-title' ).evaluate( ( node ) => node.focus( { preventScroll: true } ) );
    await expect( tip ).toBeVisible();

    // Scrolled until the row is wholly above the chart's edge: the name must not stay hanging there.
    const moved = await chart.evaluate( ( node, item ) => {
      const mine = node.querySelector( `[data-item="${ item }"]` );
      const before = node.scrollTop;
      node.scrollTop += mine.getBoundingClientRect().bottom - node.getBoundingClientRect().top + 4;
      return [ before, node.scrollTop ];
    }, id );
    expect( moved[ 1 ], 'the chart scrolls up and down' ).toBeGreaterThan( moved[ 0 ] );
    await expect( tip ).toBeHidden();

    // And back: the row is in view again, so is its name (focus is still on it).
    await chart.evaluate( ( node, top ) => { node.scrollTop = top; }, moved[ 0 ] );
    await expect( tip ).toBeVisible();
  } );

  test( 'deleting an item opened from the Blocked strip leaves focus in the strip, not on the page (review of #720)', async ( { page } ) => {
    const make = async ( name ) => {
      const made = await makeItem( world.admin.api, world.site.id, { title: `${ RUN } ${ name }`, planned_start: on( 4 ), planned_due: on( 6 ) } );
      expect( made.status(), await made.text() ).toBe( 200 );
      const item = ( await made.json() ).item;
      const blocked = await world.admin.api.post( `/work-items/${ item.id }/block`, { owner: 'The client', record_version: item.record_version } );
      expect( blocked.status(), await blocked.text() ).toBe( 200 );
      return item;
    };

    const one = await make( 'strip doomed' );
    await make( 'strip kept' );

    await openSchedule( page );

    const strip = page.locator( `[data-testid="bwx-blocked-item"][data-item="${ one.id }"]` );
    await strip.focus();
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();

    page.once( 'dialog', ( dialog ) => dialog.accept() );
    await page.getByTestId( 'bwx-item-delete' ).click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden( { timeout: 60_000 } );
    await expect( strip ).toHaveCount( 0, { timeout: 60_000 } );
    await expect( page.getByTestId( 'bwx-blocked-item' ).first() ).toBeFocused();
  } );

  test( 'a click on blank chart clears the marks, the strip after the last day included (review of #720)', async ( { page } ) => {
    await openSchedule( page );

    const bar = page.locator( `[data-testid="bwx-gantt-bar"][data-item="${ world.second.id }"]` );
    const row = page.locator( `[data-testid="bwx-gantt-row"][data-item="${ world.second.id }"]` );

    await bar.click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeVisible();
    await expect( row ).toHaveAttribute( 'data-chain', 'selected' );
    await page.locator( '.bwx-panel-head .bwx-icon-button[aria-label="Close"]' ).click();
    await expect( page.getByTestId( 'bwx-panel' ) ).toBeHidden();

    // The blank strip is the chart's own, not a row's.
    await page.getByTestId( 'bwx-gantt-chart' ).dispatchEvent( 'click' );
    await expect( row ).not.toHaveAttribute( 'data-chain', /.+/ );
  } );

  test( 'a name on show is fitted again when the screen is resized (review of #720)', async ( { page } ) => {
    const long = `${ RUN } resize ${ 'a name that goes on and on '.repeat( 5 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 390, height: 640 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } resize")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.locator( '.bwx-gantt-title' ).focus();
    await expect( tip ).toBeVisible();
    await expect( tip ).toBeInViewport( { ratio: 1 } );

    // The screen gets shorter with no scroll at all, so only the resize can refit the name.
    await page.setViewportSize( { width: 390, height: 580 } );
    await expect( async () => {
      const box = await tip.boundingBox();
      const bar = await page.getByTestId( 'bwx-menu-toggle' ).evaluate( ( node ) => node.parentElement.getBoundingClientRect().top );
      expect( box.y + box.height ).toBeLessThanOrEqual( bar + 1 );
    } ).toPass();
  } );

  test( 'a Blocked title with no spaces wraps inside its button on a phone (review of #720)', async ( { page } ) => {
    const item = await makeItem( world.admin.api, world.site.id, { title: `${ RUN }-${ 'unbroken'.repeat( 12 ) }`, planned_start: on( 4 ), planned_due: on( 6 ) } );
    expect( item.status(), await item.text() ).toBe( 200 );
    const made = ( await item.json() ).item;
    const blocked = await world.admin.api.post( `/work-items/${ made.id }/block`, { owner: 'The client', record_version: made.record_version } );
    expect( blocked.status(), await blocked.text() ).toBe( 200 );

    await page.setViewportSize( { width: 390, height: 700 } );
    await openSchedule( page );

    const button = page.locator( `[data-testid="bwx-blocked-item"][data-item="${ made.id }"]` );
    await expect( button ).toBeVisible();
    // The words wrap inside the button, rather than running past it.
    expect( await button.evaluate( ( node ) => node.scrollWidth - node.clientWidth ) ).toBeLessThanOrEqual( 1 );
    expect( await page.evaluate( () => document.documentElement.scrollWidth ) ).toBeLessThanOrEqual( 390 );
  } );

  test( 'a name on a row part behind the top bar starts below the bar, so no first line is lost (review of #720)', async ( { page } ) => {
    const long = `${ RUN } behind ${ 'a name that goes on and on '.repeat( 4 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 1000, height: 460 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } behind")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    // Room under the chart, as a long list below it would give, so the page can scroll the row up under the bar.
    await page.getByTestId( 'bwx-gantt' ).evaluate( ( node ) => { node.style.paddingBottom = '900px'; } );

    await row.scrollIntoViewIfNeeded();
    // Whatever scrolls the page is moved until the row is partly under the bar.
    const bar = await page.evaluate( ( id ) => {
      const mine = document.querySelector( `[data-testid="bwx-gantt-row"][data-item="${ id }"]` );
      const ceiling = document.querySelector( '.fs-topbar' ).getBoundingClientRect().bottom;
      // The row just below the chart's own header, not scrolled up under it.
      const chart = mine.closest( '[data-testid="bwx-gantt-chart"]' );
      chart.scrollTop += mine.getBoundingClientRect().top - ( chart.getBoundingClientRect().top + chart.querySelector( '.bwx-gantt-axis' ).getBoundingClientRect().height );

      let node = mine.parentElement;

      while ( node ) {
        const flow = getComputedStyle( node ).overflowY;

        if ( ( 'auto' === flow || 'scroll' === flow ) && node.scrollHeight > node.clientHeight && ! node.matches( '[data-testid="bwx-gantt-chart"]' ) ) {
          node.scrollTop += mine.getBoundingClientRect().top - ( ceiling - 14 );
        }

        node = node.parentElement;
      }

      window.scrollBy( 0, mine.getBoundingClientRect().top - ( ceiling - 14 ) );
      return { ceiling, top: mine.getBoundingClientRect().top };
    }, ( await made.json() ).item.id );
    expect( bar.top, 'the row is part behind the bar' ).toBeLessThan( bar.ceiling );

    // Focus, not the pointer, so what is under the pointer cannot matter, and nothing scrolls it clear.
    const at = await row.locator( '.bwx-gantt-label' ).boundingBox();
    await row.locator( '.bwx-gantt-title' ).evaluate( ( node ) => node.focus( { preventScroll: true } ) );
    await expect( tip ).toBeVisible();
    await expect( async () => {
      const box = await tip.boundingBox();
      expect( box.y ).toBeGreaterThanOrEqual( bar.ceiling - 1 );
      // Over its own column, not across the navigation beside it.
      expect( box.x ).toBeGreaterThanOrEqual( at.x - 1 );
      expect( box.x + box.width ).toBeLessThanOrEqual( at.x + at.width + 1 );
    } ).toPass();
  } );

  test( 'scrolling inside a tall name keeps its place (review of #720)', async ( { page } ) => {
    const long = `${ RUN } scrolled ${ 'a name that goes on and on '.repeat( 4 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 390, height: 520 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } scrolled")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.locator( '.bwx-gantt-title' ).focus();
    await expect( tip ).toBeVisible();

    // A name longer than the title limit allows, as the room above the bar can be shorter than any name:
    // the name is made taller than its room, then placed again by the chart moving.
    await tip.evaluate( ( node ) => { node.textContent = 'a name that goes on and on '.repeat( 60 ); } );
    await page.getByTestId( 'bwx-gantt-chart' ).evaluate( ( node ) => { node.scrollLeft += 30; } );
    await expect( tip ).toHaveAttribute( 'data-flip', 'screen' );
    await expect.poll( () => tip.evaluate( ( node ) => node.scrollHeight > node.clientHeight ) ).toBe( true );

    const at = await tip.boundingBox();
    await page.mouse.move( at.x + 40, at.y + 40 );
    await page.mouse.wheel( 0, 60 );
    await page.waitForTimeout( 500 );
    expect( await tip.evaluate( ( node ) => node.scrollTop ) ).toBeGreaterThan( 20 );
  } );

  test( 'a name too tall for its column never spreads across the navigation beside the chart, on either side (review of #720)', async ( { page } ) => {
    const long = `${ RUN } wide ${ 'a name that goes on and on '.repeat( 4 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 3 ), planned_due: on( 5 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 1000, height: 600 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } wide")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.locator( '.bwx-gantt-title' ).focus();
    await expect( tip ).toBeVisible();

    // Larger text than any title the form allows would make: a name taller than the room in its column.
    await tip.evaluate( ( node ) => { node.textContent = 'a name that goes on and on '.repeat( 60 ); } );
    await page.getByTestId( 'bwx-gantt-chart' ).evaluate( ( node ) => { node.scrollLeft += 30; } );
    await expect( tip ).toHaveAttribute( 'data-flip', 'screen' );

    const label = await row.locator( '.bwx-gantt-label' ).boundingBox();
    const box = await tip.boundingBox();
    expect( box.x, 'it stays clear of the navigation to the left of the chart' ).toBeGreaterThanOrEqual( label.x - 1 );
    // Nor across whatever is beside the chart on its other side (a right-to-left site has its navigation there).
    const chart = await page.getByTestId( 'bwx-gantt-chart' ).boundingBox();
    expect( box.x + box.width ).toBeLessThanOrEqual( chart.x + chart.width + 1 );
  } );

  test( 'hidden names add no blank scroll space, and the days leave room after the last (review of #720)', async ( { page } ) => {
    const long = `${ RUN } zzz ${ 'the last row has a name so long that it wraps ' .repeat( 3 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 30 ), planned_due: on( 32 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await openSchedule( page );
    await expect( page.locator( '[data-testid="bwx-gantt-row"]:has-text("zzz")' ) ).toBeVisible();

    const room = await page.getByTestId( 'bwx-gantt-chart' ).evaluate( ( chart ) => {
      const frame = chart.getBoundingClientRect();
      const rows = chart.querySelector( '.bwx-gantt-rows' ).getBoundingClientRect();

      return {
        below: chart.scrollHeight - ( rows.bottom - frame.top + chart.scrollTop ),
        after: chart.scrollWidth - ( rows.right - frame.left + chart.scrollLeft ),
      };
    } );

    expect( room.below, 'nothing to scroll to below the last row' ).toBeLessThanOrEqual( 2 );
    expect( room.after, 'room after the last day' ).toBeGreaterThanOrEqual( 90 );
  } );

  test( 'a long name is fitted to what is on the screen, not to the whole chart (review of #720)', async ( { page } ) => {
    const long = `${ RUN } yyy ${ 'the last row has a name so long that it wraps ' .repeat( 3 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 31 ), planned_due: on( 33 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 1000, height: 560 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } yyy")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.locator( '.bwx-gantt-label' ).hover();
    await expect( tip ).toBeVisible();
    await expect( tip ).toBeInViewport( { ratio: 1 } );
  } );

  test( 'on a phone a long name stays above the navigation bar (review of #720)', async ( { page } ) => {
    const long = `${ RUN } nav ${ 'the last row has a name so long that it wraps ' .repeat( 3 ) }end`;
    const made = await makeItem( world.admin.api, world.site.id, { title: long, planned_start: on( 32 ), planned_due: on( 34 ) } );
    expect( made.status(), await made.text() ).toBe( 200 );

    await page.setViewportSize( { width: 390, height: 700 } );
    await openSchedule( page );

    const row = page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } nav")` );
    const tip = row.locator( '[data-testid="bwx-gantt-name-tip"]' );

    await row.scrollIntoViewIfNeeded();
    await row.locator( '.bwx-gantt-label' ).hover();
    await expect( tip ).toBeVisible();

    const bar = await page.getByTestId( 'bwx-menu-toggle' ).evaluate( ( node ) => node.parentElement.getBoundingClientRect().top );
    await expect( async () => {
      const box = await tip.boundingBox();
      expect( box.y + box.height ).toBeLessThanOrEqual( bar + 1 );
    } ).toPass();
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

    await late.locator( '.bwx-gantt-label' ).hover();
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
    await page.locator( `[data-testid="bwx-gantt-row"]:has-text("${ RUN } old") .bwx-gantt-label` ).click();
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

    test( 'a date stays readable beside the names however the days are scrolled (review of #720)', async ( { page } ) => {
      await openSchedule( page );
      await page.locator( '[data-testid="bwx-gantt-jump-today"]' ).click();

      const chart = page.getByTestId( 'bwx-gantt-chart' );

      // Across a run of scroll positions, so the view straddles two days at some of them.
      for ( const step of [ 0, 60, 130, 200, 264, 330 ] ) {
        await chart.evaluate( ( node, by ) => { node.scrollLeft += by; }, step );

        const seen = await page.evaluate( () => {
          const frame = document.querySelector( '[data-testid="bwx-gantt-chart"]' ).getBoundingClientRect();
          const names = document.querySelector( '.bwx-gantt-axis-gutter' ).getBoundingClientRect();

          return [ ...document.querySelectorAll( '.bwx-gantt-day-label' ) ].filter( ( label ) => {
            const box = label.getBoundingClientRect();
            return box.left >= names.right - 1 && box.right <= frame.right + 1;
          } ).length;
        } );

        expect( seen, `a date shows after scrolling ${ step }px more` ).toBeGreaterThan( 0 );
      }
    } );

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
