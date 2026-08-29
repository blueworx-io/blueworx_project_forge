import { test, expect } from '@playwright/test';
import { signedIn, signInPage, makeSite, makeItem } from './helpers/forge.js';

// The calendar view (#121). Four kinds of date live on a work item — when it
// starts, when it is due, when it is meant to be reviewed and when it is meant
// to ship — and a calendar that showed only one of them would be answering a
// narrower question than the one people open a calendar to ask.
//
// So an item appears once per date it carries, and each appearance says which
// date it is. An item with no dates at all does not appear, which is why this
// view cannot be reconciled against the others by counting.

const RUN = `cal${ Date.now() }`;

const DAY = 86400000;

/**
 * A day of this week, Monday being 0.
 *
 * Not "today plus n". The month view draws whole weeks, so it runs from the
 * Monday on or before the 1st to the Sunday on or after the last — which means
 * today's own week is always drawn in full, and a day counted forward from
 * today is not. A fixture dated today + 9 sits outside the grid whenever today
 * falls in the last week of a month, and the spec then fails on those days and
 * only those days.
 */
const on = ( index ) => {
  const now = new Date();
  const midnight = Date.UTC( now.getFullYear(), now.getMonth(), now.getDate() );
  const monday = midnight - ( ( new Date( midnight ).getUTCDay() + 6 ) % 7 ) * DAY;

  return new Date( monday + index * DAY ).toISOString().slice( 0, 10 );
};

test.describe( 'the calendar', () => {
  test.describe.configure( { mode: 'serial' } );

  let world;

  test.beforeAll( async ( { browser, baseURL } ) => {
    const admin = await signedIn(
      browser,
      baseURL,
      process.env.WP_ADMIN_USER ?? 'admin',
      process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw'
    );

    const { client, site } = await makeSite( admin.api, `${ RUN } calendar`, RUN );

    const make = async ( data ) => {
      const made = await makeItem( admin.api, site.id, data );
      expect( made.status(), await made.text() ).toBe( 200 );
      return ( await made.json() ).item;
    };

    // One item carrying all four dates, each on a different day, so each kind
    // can be found on a day of its own.
    const full = await make( {
      title: `${ RUN } full dates`,
      planned_start: on( 0 ),
      planned_due: on( 1 ),
      review_target: on( 2 ),
      release_target: on( 3 ),
    } );

    // Two dates, so "appears once per date" is a claim about the same item
    // rather than about two different ones.
    const twice = await make( {
      title: `${ RUN } twice over`,
      planned_start: on( 4 ),
      planned_due: on( 5 ),
    } );

    // No dates. It is on the board and it is not on the calendar, and that is
    // correct rather than a loss.
    const never = await make( { title: `${ RUN } no dates at all` } );

    world = { admin, client, site, full, twice, never };
  } );

  test.afterAll( async () => {
    await world?.admin.context.close();
  } );

  async function openCalendar( page ) {
    await signInPage(
      page,
      process.env.WP_ADMIN_USER ?? 'admin',
      process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw'
    );
    await page.goto( '/blueworx-forge/' );
    await page.selectOption( '[data-testid="bwx-site"]', world.site.id );
    await page.locator( '[data-testid="bwx-view-calendar"]' ).click();
    await expect( page.locator( '[data-testid="bwx-calendar"]' ) ).toBeVisible();
  }

  /** Every entry for one item, whatever day it landed on. */
  const entriesFor = ( page, id ) =>
    page.locator( `[data-testid="bwx-calendar-entry"][data-item="${ id }"]` );

  test( 'it is a fourth view beside the board, the list and the schedule', async ( { page } ) => {
    await openCalendar( page );

    await expect( page.locator( '[data-testid="bwx-view-calendar"]' ) ).toHaveAttribute( 'aria-pressed', 'true' );
    await expect( page.locator( '[data-testid="bwx-board"]' ) ).toHaveCount( 0 );
    await expect( page.locator( '[data-testid="bwx-gantt"]' ) ).toHaveCount( 0 );
  } );

  test( 'each of the four dates lands on its own day, saying which date it is', async ( { page } ) => {
    await openCalendar( page );

    for ( const [ kind, date ] of [
      [ 'starts', on( 0 ) ],
      [ 'due', on( 1 ) ],
      [ 'review', on( 2 ) ],
      [ 'release', on( 3 ) ],
    ] ) {
      const entry = page.locator(
        `[data-testid="bwx-calendar-day"][data-date="${ date }"] [data-testid="bwx-calendar-entry"][data-item="${ world.full.id }"][data-kind="${ kind }"]`
      );

      await expect( entry, `${ kind } should be on ${ date }` ).toHaveCount( 1 );
    }
  } );

  test( 'an item with two dates appears twice, not once', async ( { page } ) => {
    await openCalendar( page );

    await expect( entriesFor( page, world.twice.id ) ).toHaveCount( 2 );
  } );

  test( 'an item with no dates is on the board but not on the calendar', async ( { page } ) => {
    await openCalendar( page );

    await expect( entriesFor( page, world.never.id ) ).toHaveCount( 0 );

    await page.locator( '[data-testid="bwx-view-board"]' ).click();
    await expect( page.locator( `[data-testid="bwx-card"][data-item="${ world.never.id }"]` ) ).toHaveCount( 1 );
  } );

  test( 'the same date is the same date in month, week and day', async ( { page } ) => {
    await openCalendar( page );

    // The due date, found three ways. A view that put it on a different day in
    // one mode would be three calendars rather than one.
    for ( const mode of [ 'month', 'week', 'day' ] ) {
      await page.locator( `[data-testid="bwx-calendar-mode-${ mode }"]` ).click();
      await page.locator( '[data-testid="bwx-calendar-goto"]' ).fill( on( 1 ) );

      await expect(
        page.locator(
          `[data-testid="bwx-calendar-day"][data-date="${ on( 1 ) }"] [data-testid="bwx-calendar-entry"][data-item="${ world.full.id }"][data-kind="due"]`
        ),
        `the due date should be on ${ on( 1 ) } in ${ mode }`
      ).toHaveCount( 1 );
    }
  } );

  test( 'the filter bar narrows the calendar like any other view', async ( { page } ) => {
    await openCalendar( page );

    await page.fill( '[data-testid="bwx-search"]', 'twice over' );

    await expect( entriesFor( page, world.twice.id ) ).toHaveCount( 2 );
    await expect( entriesFor( page, world.full.id ) ).toHaveCount( 0 );
  } );

  test( 'the calendar and the board hold the same work, counted the only way they can be', async ( { page } ) => {
    await openCalendar( page );

    // Not entry against card: one item makes up to four entries and an undated
    // one makes none, so those two numbers are never meant to match. What has
    // to hold is that every item on the calendar is an item the board has, and
    // that nothing dated is missing from it.
    const onCalendar = new Set(
      await page.locator( '[data-testid="bwx-calendar-entry"]' ).evaluateAll(
        ( nodes ) => nodes.map( ( node ) => node.getAttribute( 'data-item' ) )
      )
    );

    await page.locator( '[data-testid="bwx-view-board"]' ).click();
    const onBoard = new Set(
      await page.locator( '[data-testid="bwx-card"]' ).evaluateAll(
        ( nodes ) => nodes.map( ( node ) => node.getAttribute( 'data-item' ) )
      )
    );

    for ( const id of onCalendar ) {
      expect( onBoard.has( id ), `${ id } is on the calendar but not on the board` ).toBe( true );
    }

    expect( onCalendar.has( world.full.id ) ).toBe( true );
    expect( onCalendar.has( world.twice.id ) ).toBe( true );
    expect( onCalendar.has( world.never.id ) ).toBe( false );
  } );
} );
