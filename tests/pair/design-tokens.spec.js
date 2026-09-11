import { test, expect } from '@playwright/test';
import { CLIENT_URL, requireEnvironment, signedIn } from './helpers/pair.js';

// #295, the client half. The client plugin loads the shared token file
// directly rather than compiling it in, so the alias layer has to be reached
// from there too — and the fonts it names have to ship with the client.

test.beforeAll( requireEnvironment );

test( 'the alias tokens and fonts reach a client screen', async ( { browser } ) => {
  const client = await signedIn( browser, CLIENT_URL );
  const page = await client.context.newPage();

  const fontRequests = [];
  page.on( 'request', ( request ) => {
    if ( 'font' === request.resourceType() || /fonts\.g(oogleapis|static)\.com/.test( request.url() ) ) {
      fontRequests.push( request.url() );
    }
  } );

  await page.goto( '/wp-admin/admin.php?page=blueworx-forge-client' );

  const ink = await page.evaluate( () =>
    getComputedStyle( document.documentElement ).getPropertyValue( '--ink-900' ).trim()
  );
  expect( ink, '--ink-900 is not defined on the client screen' ).not.toBe( '' );

  await page.evaluate( () => document.fonts.load( '12px "JetBrains Mono"' ) );

  const google = fontRequests.filter( ( url ) => /fonts\.g(oogleapis|static)\.com/.test( url ) );
  expect( google, `fonts still come from Google: ${ google.join( ', ' ) }` ).toEqual( [] );
  expect(
    fontRequests.some( ( url ) => url.includes( '/plugins/blueworx-forge-client/' ) && /jetbrains/i.test( url ) ),
    'JetBrains Mono was not fetched from the client plugin'
  ).toBe( true );

  await page.close();
  await client.context.close();
} );
