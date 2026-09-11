import { test, expect } from '@playwright/test';
import { CLIENT_URL, connectedPair, requireEnvironment } from './helpers/pair.js';

// #298, the reads. The client app asks its own plugin for the board, the
// hours, the checklist and the submissions, and gets back exactly what the
// wp-admin screen for each already shows — through the same cache, so the
// two can never disagree. Nobody who is not signed in gets any of it.

const RUN = `reads${ Date.now() }`;
const CLIENT_API = '/wp-json/blueworx-forge-client/v1';
const ROUTES = [ 'board', 'sales', 'checklist', 'submissions' ];

test.beforeAll( requireEnvironment );

test( 'a connected site answers every read for a signed-in client', async ( { browser } ) => {
  test.setTimeout( 180_000 );
  const pair = await connectedPair( browser, 'Reads Co', RUN, { title: `Something to read ${ RUN }` } );

  for ( const route of ROUTES ) {
    const response = await pair.clientSite.request.get( `${ CLIENT_API }/${ route }?refresh=true`, { headers: pair.clientSite.headers } );
    expect( response.status(), `${ route } did not answer` ).toBe( 200 );
    const body = await response.json();
    expect( body, `${ route } has no ok flag` ).toHaveProperty( 'ok' );
  }

  // The board is the one read whose content the pair can prove: the item the
  // studio made is on it.
  const board = await ( await pair.clientSite.request.get( `${ CLIENT_API }/board?refresh=true`, { headers: pair.clientSite.headers } ) ).json();
  expect( JSON.stringify( board ) ).toContain( `Something to read ${ RUN }` );

  await pair.close();
} );

test( 'nobody signed out can read anything', async ( { browser } ) => {
  const context = await browser.newContext( { baseURL: CLIENT_URL } );

  for ( const route of ROUTES ) {
    const response = await context.request.get( `${ CLIENT_API }/${ route }` );
    expect( response.status(), `${ route } answered a stranger` ).toBe( 401 );
  }

  await context.close();
} );
