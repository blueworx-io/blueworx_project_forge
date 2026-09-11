import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #301. Bugs & requests on the workspace page: a bug and a request raised
// from the form land in the studio's queue and appear in the list as
// received; the studio's reply comes back against them; the list can be
// narrowed to one state and searched.

const RUN = `req${ Date.now() }`;
const PAGE = '/forge/#requests';

test.beforeAll( requireEnvironment );

async function raise( page, type, title, description, outcome ) {
  const form = page.getByTestId( 'bwx-new-submission' );
  await form.getByRole( 'radio', { name: type } ).click();
  await form.getByRole( 'textbox', { name: /What is going wrong|Title/ } ).fill( title );
  await form.getByRole( 'textbox', { name: /What happens|Problem or need/ } ).fill( description );
  if ( outcome ) await form.getByRole( 'textbox', { name: /Expected result|Desired outcome/ } ).fill( outcome );
  await form.getByRole( 'button', { name: /Report bug|Submit/ } ).click();
}

test( 'a client raises a bug and a request and sees them, with the studio reply', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const pair = await connectedPair( browser, 'Asking Co', RUN );
  const page = await pair.clientSite.context.newPage();
  await page.goto( PAGE );

  const screen = page.getByTestId( 'bwx-requests' );
  await expect( screen ).toBeVisible();
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );

  const bug = `The header overlaps on phones ${ RUN }`;
  await raise( page, 'Bug', bug, '1. Open the site on an iPhone\n2. The logo sits over the menu' );
  await expect( page.getByTestId( 'bwx-submission' ).filter( { hasText: bug } ) ).toBeVisible( { timeout: 60_000 } );

  const request = `A booking form that takes deposits ${ RUN }`;
  await raise( page, 'Request', request, 'Members cannot pay a deposit online.', 'A deposit taken at booking.' );
  const entry = page.getByTestId( 'bwx-submission' ).filter( { hasText: request } );
  await expect( entry ).toBeVisible( { timeout: 60_000 } );
  await expect( entry ).toHaveAttribute( 'data-state', 'received' );

  // Both are in the studio's queue, in the client's words.
  const queue = await pair.studio.get( '/submissions' );
  const titles = queue.submissions.map( ( one ) => one.title );
  expect( titles ).toContain( bug );
  expect( titles ).toContain( request );

  await checkAccessibility( page, 'Client requests', 'app' );

  // The studio replies, and the reply comes back against the request.
  const theirs = queue.submissions.find( ( one ) => one.title === request );
  const answered = await pair.studio.patch( `/submissions/${ theirs.id }`, { intake_state: 'accepted', response: 'Yes — going into the October release.' } );
  expect( answered.status(), await answered.text() ).toBe( 200 );

  // What the page shows is what this site last heard; asking again reads the
  // studio afresh, the same as the wp-admin screen's "check again".
  const list = page.getByTestId( 'bwx-submissions' );
  await list.getByRole( 'button', { name: 'Check again' } ).click();
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );
  await expect( list.getByTestId( 'bwx-submission' ).filter( { hasText: request } ) ).toContainText( 'going into the October release', { timeout: 60_000 } );

  // A saved view narrows the list to one state; search narrows it further.
  await list.getByRole( 'button', { name: /^Accepted/ } ).click();
  await expect( list.getByTestId( 'bwx-submission' ).filter( { hasText: bug } ) ).toHaveCount( 0 );
  await expect( list.getByTestId( 'bwx-submission' ).filter( { hasText: request } ) ).toBeVisible();
  await list.getByRole( 'button', { name: /^All/ } ).click();
  await list.getByRole( 'searchbox' ).fill( 'overlaps on phones' );
  await expect( list.getByTestId( 'bwx-submission' ).filter( { hasText: bug } ) ).toBeVisible();
  await expect( list.getByTestId( 'bwx-submission' ).filter( { hasText: request } ) ).toHaveCount( 0 );

  await page.close();
  await pair.close();
} );
