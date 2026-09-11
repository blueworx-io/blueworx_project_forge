import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #300. The client's work on the workspace page: every card in the stage the
// studio has it in, a drag that is refused in words, and a card that opens to
// its conversation — where a comment reaches the studio without moving
// anything.

const RUN = `board${ Date.now() }`;
const PAGE = '/forge/#board';

test.beforeAll( requireEnvironment );

test( 'a client can read the board and comment on a card, but not move it', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const title = `Rebuild the member area ${ RUN }`;
  const pair = await connectedPair( browser, 'Board Co', RUN, { title, planned_due: '2026-10-10' } );
  const before = ( await pair.studio.get( `/work-items/${ pair.work.id }` ) ).item.stage;

  const page = await pair.clientSite.context.newPage();
  await page.goto( PAGE );

  const screen = page.getByTestId( 'bwx-board-screen' );
  await expect( screen ).toBeVisible();
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );

  // The card sits in the column of the stage the studio has it in.
  const card = page.getByTestId( 'bwx-work-card' ).filter( { hasText: title } );
  await expect( card ).toBeVisible();
  await expect( page.locator( `.fc-column[data-stage="${ before }"]` ).getByTestId( 'bwx-work-card' ).filter( { hasText: title } ) ).toBeVisible();
  await expect( card ).toContainText( 'due 10 Oct 2026' );

  // Dragging it is refused, in words, and nothing changes stage.
  await card.dispatchEvent( 'dragstart' );
  const refusal = page.getByTestId( 'bwx-board-refusal' );
  await expect( refusal ).toContainText( 'Cards cannot be moved here' );
  expect( ( await pair.studio.get( `/work-items/${ pair.work.id }` ) ).item.stage ).toBe( before );

  await checkAccessibility( page, 'Client board', 'app' );

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
