import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #418. Released is done: moving there asks for no release details, and when
// it happened is recorded by the move itself.

const RUN = `rel${ Date.now() }`;

test( 'releasing asks nothing about where or when, and the panel shows when it went', async ( { browser, baseURL } ) => {
  test.setTimeout( 180_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await Forge.makeSite( admin.api, `Release Co ${ RUN }`, RUN );
  const made = await Forge.makeItem( admin.api, site.id, { title: `Ship it ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  let item = ( await made.json() ).item;

  const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'completed', reason: 'Set up for the test.', record_version: item.record_version } );
  expect( moved.status(), await moved.text() ).toBe( 200 );

  // None of the four release details is asked for on the way out of Completed.
  const read = await admin.api.get( `/work-items/${ item.id }` );
  const asked = ( read.readiness.released?.unmet ?? [] ).map( ( row ) => row.label );
  for ( const gone of [ 'Release method', 'Target environment, version or destination', 'Release window', 'Release date and time', 'Environment and version, or handover destination' ] ) {
    expect( asked ).not.toContain( gone );
  }
  expect( ( read.readiness.released?.unmet ?? [] ).map( ( row ) => row.id ).filter( ( id ) => id.startsWith( 'G-RELEASED' ) ) ).toEqual( [] );

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ item.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( `Ship it ${ RUN }`, { timeout: 60_000 } );
  await expect( panel.locator( '#bwx-release_destination' ) ).toHaveCount( 0 );
  await expect( panel.locator( '#bwx-release_method' ) ).toHaveCount( 0 );
  await page.close();

  item = read.item;
  const released = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'released', reason: 'Set up for the test.', record_version: item.record_version } );
  expect( released.status(), await released.text() ).toBe( 200 );

  const after = await admin.context.newPage();
  await after.goto( `/blueworx-forge/#item=${ item.id }` );
  await expect( after.getByTestId( 'bwx-panel-released' ) ).toContainText( /released \d{1,2} \w{3,4} \d{4}/, { timeout: 60_000 } );
  await after.close();
  await admin.context.close();
} );
