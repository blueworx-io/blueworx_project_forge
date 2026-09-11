import { test, expect } from '@playwright/test';

// #295. The v3 design's alias names resolve on the studio app page, and its
// two typefaces come from the plugin rather than from Google.
//
// The alias layer is what the design kit is written against, so a screen built
// from the kit paints nothing if a name here is missing. Checking one name from
// each group is enough to prove the file is loaded; the names themselves are
// the kit's, not chosen here.

const ALIASES = [ '--ink-900', '--surface-page', '--accent-signal', '--stage-triage-ink', '--state-ok-wash' ];

test( 'the alias tokens resolve on the app page', async ( { page } ) => {
  await page.goto( '/blueworx-forge/' );

  const values = await page.evaluate( ( names ) => {
    const style = getComputedStyle( document.documentElement );
    return Object.fromEntries( names.map( ( n ) => [ n, style.getPropertyValue( n ).trim() ] ) );
  }, ALIASES );

  for ( const name of ALIASES ) {
    expect( values[ name ], `${ name } is not defined` ).not.toBe( '' );
  }
} );

test( 'the fonts are served by the plugin, not Google', async ( { page } ) => {
  const fontRequests = [];
  page.on( 'request', ( request ) => {
    if ( 'font' === request.resourceType() || /fonts\.g(oogleapis|static)\.com/.test( request.url() ) ) {
      fontRequests.push( request.url() );
    }
  } );

  await page.goto( '/blueworx-forge/' );

  // Ask for both faces so the browser has a reason to fetch them, then wait
  // until it has.
  await page.evaluate( () =>
    Promise.all( [ document.fonts.load( '15px Inter' ), document.fonts.load( '12px "JetBrains Mono"' ) ] )
  );

  const google = fontRequests.filter( ( url ) => /fonts\.g(oogleapis|static)\.com/.test( url ) );
  expect( google, `fonts still come from Google: ${ google.join( ', ' ) }` ).toEqual( [] );

  const local = fontRequests.filter( ( url ) => url.includes( '/plugins/blueworx-forge/' ) );
  expect( local.some( ( url ) => /inter/i.test( url ) ), 'Inter was not fetched from the plugin' ).toBe( true );
  expect( local.some( ( url ) => /jetbrains/i.test( url ) ), 'JetBrains Mono was not fetched from the plugin' ).toBe( true );
} );
