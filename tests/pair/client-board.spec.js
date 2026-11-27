import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #300. The client's work on the workspace page: every card in the stage the
// studio has it in, a drag that is refused in words, and a card that opens to
// its conversation — where a comment reaches the studio without moving
// anything.

const RUN = `board${ Date.now() }`;
const PAGE = '/forge/#board';

/**
 * A date either side of today in the browser's own calendar, as the schedule
 * marks it. Today is read once, when the file loads, so the dates saved on a
 * task and the dates asserted afterwards cannot disagree if the run crosses
 * midnight.
 */
const TODAY = new Date();
const pad = ( number ) => String( number ).padStart( 2, '0' );
const on = ( offset ) => {
  const at = new Date( TODAY );

  at.setDate( at.getDate() + offset );

  return `${ at.getFullYear() }-${ pad( at.getMonth() + 1 ) }-${ pad( at.getDate() ) }`;
};
// The way the app writes a date (British English, as the browser formats it),
// so September reads however this browser spells it.
const spoken = ( iso ) => new Date( `${ iso }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' } );

test.beforeAll( requireEnvironment );

test( 'a client can read the board and comment on a card, but not move it', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const title = `Rebuild the member area ${ RUN }`;
  const pair = await connectedPair( browser, 'Board Co', RUN, { title, planned_start: on( -3 ), planned_due: on( 5 ) } );
  const before = ( await pair.studio.get( `/work-items/${ pair.work.id }` ) ).item.stage;

  const page = await pair.clientSite.context.newPage();

  // #703. The studio's answer, with one more item in it that has already been
  // released: shipped work has to stay off the schedule.
  const shipped = `Shipped last month ${ RUN }`;
  const faraway = `Planned long ago ${ RUN }`;
  await page.route( /blueworx-forge-client(\/|%2F)v1(\/|%2F)board/, async ( route ) => {
    const response = await route.fetch();
    const body = await response.json();

    if ( body?.items?.length ) {
      body.items = [ ...body.items, { ...body.items[ 0 ], id: `${ body.items[ 0 ].id }x`, title: shipped, stage: 'released' } ];
      // Work dated well beyond both limits, and some wholly before them.
      body.items.push(
        { ...body.items[ 0 ], id: `${ body.items[ 0 ].id }y`, title: `Long running ${ RUN }`, planned_start: on( -120 ), planned_due: on( 300 ) },
        { ...body.items[ 0 ], id: `${ body.items[ 0 ].id }z`, title: faraway, planned_start: on( -120 ), planned_due: on( -110 ) }
      );
    }

    await route.fulfill( { response, json: body } );
  } );

  // The browser's clock is the one the dates above were worked out on, so a
  // run that crosses midnight still sees the chart it was written for.
  await page.clock.setFixedTime( TODAY );
  await page.goto( PAGE );

  const screen = page.getByTestId( 'bwx-board-screen' );
  await expect( screen ).toBeVisible();
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );

  // The card sits in the column of the stage the studio has it in.
  const card = page.getByTestId( 'bwx-work-card' ).filter( { hasText: title } );
  await expect( card ).toBeVisible();
  await expect( page.locator( `.fc-column[data-stage="${ before }"]` ).getByTestId( 'bwx-work-card' ).filter( { hasText: title } ) ).toBeVisible();
  await expect( card ).toContainText( `due ${ spoken( on( 5 ) ) }` );

  // Dragging it is refused, in words, and nothing changes stage.
  await card.dispatchEvent( 'dragstart' );
  const refusal = page.getByTestId( 'bwx-board-refusal' );
  await expect( refusal ).toContainText( 'Cards cannot be moved here' );
  expect( ( await pair.studio.get( `/work-items/${ pair.work.id }` ) ).item.stage ).toBe( before );

  await checkAccessibility( page, 'Client board', 'app' );

  // The schedule draws the same item as a bar on a time axis, still a link.
  await page.getByRole( 'button', { name: 'Schedule' } ).click();
  const bar = page.getByTestId( 'bwx-schedule-row' ).filter( { hasText: title } );
  await expect( bar ).toBeVisible();
  await expect( bar ).toContainText( `due ${ spoken( on( 5 ) ) }` );
  await expect( page.getByTestId( 'bwx-schedule' ) ).not.toContainText( shipped );

  // #703. A day view, a column to a day, and it is how the schedule opens. The
  // chart looks two weeks back and six ahead at most, so the long bar added
  // above (it runs from long ago to well ahead) is cut at both ends.
  await expect( page.getByTestId( 'bwx-schedule-chart' ) ).toHaveAttribute( 'data-zoom', 'day' );
  const dayColumns = page.getByTestId( 'bwx-schedule-day' );
  await expect( dayColumns ).toHaveCount( 57 );
  await expect( dayColumns.first() ).toHaveAttribute( 'data-day', on( -14 ) );
  await expect( dayColumns.last() ).toHaveAttribute( 'data-day', on( 42 ) );
  await expect( page.locator( '[data-testid="bwx-schedule-day"][data-today="true"]' ) ).toHaveCount( 1 );
  await expect( page.locator( '[data-testid="bwx-schedule-day"][data-today="true"]' ) ).toHaveAttribute( 'data-day', on( 0 ) );
  await expect( page.locator( '[data-testid="bwx-schedule-day"][data-today="true"]' ) ).toContainText( 'Today' );
  await expect( page.getByTestId( 'bwx-schedule-today-note' ) ).toContainText( 'Today is' );
  await expect( bar ).toBeVisible();
  await expect( page.getByTestId( 'bwx-schedule' ) ).not.toContainText( shipped );

  // Work wholly outside those weeks is named, not drawn.
  const outside = page.getByTestId( 'bwx-schedule-row' ).filter( { hasText: faraway } );
  await expect( outside.locator( '.fc-schedule-outside' ) ).toContainText( 'outside the weeks shown' );
  await expect( outside.locator( '.fc-schedule-bar' ) ).toHaveCount( 0 );
  await checkAccessibility( page, 'Client schedule', 'app' );

  // The weeks are one press away, inside the same limits.
  await page.getByTestId( 'bwx-schedule-zoom-week' ).click();
  await expect( page.getByTestId( 'bwx-schedule-chart' ) ).toHaveAttribute( 'data-zoom', 'week' );
  await expect( dayColumns ).toHaveCount( 0 );
  // The whole weeks that hold the limits: eight or nine of them, by the day of the week.
  const weekCount = await page.locator( '.fc-schedule-weeks > span' ).count();
  expect( weekCount === 8 || weekCount === 9 ).toBe( true );

  // On a phone: the controls wrap inside the card, a name stays beside the days
  // once Today has scrolled to them, and the notice fits the screen.
  await page.setViewportSize( { width: 390, height: 844 } );
  await page.getByTestId( 'bwx-schedule-zoom-day' ).click();
  await page.getByTestId( 'bwx-schedule-jump-today' ).click();
  const box = await page.getByTestId( 'bwx-schedule' ).boundingBox();
  for ( const control of await page.getByTestId( 'bwx-schedule' ).locator( '.fc-schedule-head button' ).all() ) {
    const at = await control.boundingBox();

    expect( at.x ).toBeGreaterThanOrEqual( box.x );
    expect( at.x + at.width ).toBeLessThanOrEqual( box.x + box.width );
  }
  // The chart is below the fold on a phone, so bring it up first.
  await bar.scrollIntoViewIfNeeded();
  await expect( bar.locator( '.fc-schedule-label' ) ).toBeInViewport( { ratio: 1 } );
  await expect( bar.locator( '.fc-schedule-label .fc-link' ) ).toBeVisible();
  await outside.scrollIntoViewIfNeeded();
  await expect( outside.locator( '.fc-schedule-outside' ) ).toBeInViewport( { ratio: 1 } );
  await page.setViewportSize( { width: 1280, height: 800 } );
  await page.getByRole( 'button', { name: 'Kanban' } ).click();

  // Opening the card is a link, so it can be shared and the back button works.
  await card.click();
  await expect( page ).toHaveURL( new RegExp( `#board/${ pair.work.id }$` ) );
  const record = page.getByTestId( 'bwx-item-record' );
  await expect( record.getByTestId( 'bwx-no-moves' ) ).toBeVisible( { timeout: 60_000 } );
  await expect( page.getByRole( 'dialog' ) ).toContainText( title );

  // A comment reaches the studio and comes back in the thread.
  const said = `Any news on this? ${ RUN }`;
  const form = record.getByTestId( 'bwx-say-form' );
  await form.getByLabel( 'Your comment' ).fill( said );
  await form.getByRole( 'button', { name: 'Send to the studio' } ).click();
  await expect( record.getByTestId( 'bwx-thread' ) ).toContainText( said, { timeout: 60_000 } );

  const detail = await pair.studio.get( `/work-items/${ pair.work.id }` );
  expect( detail.comments.map( ( one ) => one.body ) ).toContain( said );
  expect( detail.item.stage ).toBe( before );

  await checkAccessibility( page, 'Client item record', 'app' );

  // Closing goes back to the board.
  await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Close' } ).click();
  await expect( page ).toHaveURL( /#board$/ );
  await expect( record ).toHaveCount( 0 );

  await page.close();
  await pair.close();
} );
