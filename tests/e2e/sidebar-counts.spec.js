import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #450. My tasks and Daily standup show a count in the sidebar, the way
// Requests review does: the same number their screen shows, and nothing at zero.

const RUN = `side${ Date.now() }`;
const DAY = 86400000;
const on = ( offset ) => new Date( Date.now() + offset * DAY ).toISOString().slice( 0, 10 );

test( 'My tasks counts what its Today tab counts, and shows nothing at zero', async ( { browser, baseURL } ) => {
  test.setTimeout( 300_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Side Co ${ RUN }`, RUN );
  const busy = await Forge.makePerson( admin.api, client.id, 'staff', `busy${ RUN }` );
  const idle = await Forge.makePerson( admin.api, client.id, 'staff', `idle${ RUN }` );

  for ( const title of [ 'Late one', 'Later late one' ] ) {
    const made = await Forge.makeItem( admin.api, site.id, { title: `${ title } ${ RUN }` } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const item = ( await made.json() ).item;
    const edited = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: busy.id, planned_due: on( -2 ), record_version: item.record_version } );
    expect( edited.status(), await edited.text() ).toBe( 200 );
  }

  const badge = ( page, testId ) => page.getByTestId( testId ).locator( '.fs-rail-count' );

  const me = await Forge.signedIn( browser, baseURL, busy.login, Forge.PASSWORD );
  const page = await me.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-work' ).click();
  await expect( badge( page, 'bwx-screen-mytasks' ) ).toHaveText( '2', { timeout: 60_000 } );

  await page.getByTestId( 'bwx-screen-mytasks' ).click();
  const table = page.getByTestId( 'bwx-mytasks-table' );
  await expect( table ).toBeVisible( { timeout: 60_000 } );
  const today = await table.getByRole( 'button', { name: /^Today/ } ).innerText();
  expect( parseInt( today.replace( /\D/g, '' ), 10 ) ).toBe( 2 );
  await expect( badge( page, 'bwx-screen-mytasks' ) ).toHaveText( '2' );

  // Somebody with nothing on their list has no count at all.
  const nobody = await Forge.signedIn( browser, baseURL, idle.login, Forge.PASSWORD );
  const quiet = await nobody.context.newPage();
  await quiet.goto( '/blueworx-forge/' );
  await quiet.getByTestId( 'bwx-screen-mytasks' ).click();
  await expect( quiet.getByTestId( 'bwx-mytasks-table' ) ).toBeVisible( { timeout: 60_000 } );
  await expect( badge( quiet, 'bwx-screen-mytasks' ) ).toHaveCount( 0 );

  await quiet.close();
  await nobody.context.close();
  await page.close();
  await me.context.close();
  await admin.context.close();
} );

test( 'Daily standup counts what it lists plus meetings to settle', async ( { browser, baseURL } ) => {
  test.setTimeout( 300_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-standup' ).click();
  await expect( page.getByTestId( 'bwx-standup-count' ) ).toBeVisible( { timeout: 60_000 } );

  const things = parseInt( ( await page.getByTestId( 'bwx-standup-count' ).innerText() ).replace( /\D/g, '' ), 10 );
  const settle = page.getByTestId( 'bwx-standup-settle' );
  const toSettle = ( await settle.count() ) > 0 ? await settle.locator( 'li' ).count() : 0;
  const total = things + toSettle;

  // Leave the screen: the count is still there, and is the same number.
  await page.getByTestId( 'bwx-screen-mytasks' ).click();
  const badge = page.getByTestId( 'bwx-screen-standup' ).locator( '.fs-rail-count' );
  if ( 0 === total ) {
    await expect( badge ).toHaveCount( 0 );
  } else {
    await expect( badge ).toHaveText( String( total ), { timeout: 60_000 } );
  }

  await page.close();
  await admin.context.close();
} );
