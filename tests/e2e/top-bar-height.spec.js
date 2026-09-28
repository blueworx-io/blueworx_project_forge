import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson } from './helpers/forge.js';
import { PHONE } from './helpers/phone.js';

// #463. The top bar is the same height on a long screen as on a short one,
// on a desktop and on a phone.

const RUN = `topbar${ Date.now() }`;

test( 'the top bar keeps its full height on a long screen, on a desktop and a phone', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await makeSite( admin.api, `Top Bar Co ${ RUN }`, RUN );
  const person = await makePerson( admin.api, client.id, 'staff', `topbar${ Date.now() }` );
  const today = ( await admin.api.get( '/standup' ) ).today;

  // Enough reminders of this test's own to make the screen longer than the window.
  for ( let i = 0; i < 20; i++ ) {
    const made = await admin.api.post( '/reminders', { client_site_id: site.id, title: `Top bar reminder ${ i } ${ RUN }`, assignees: [ person.id ], starts_on: today } );
    expect( made.status(), await made.text() ).toBe( 200 );
  }

  const page = await admin.context.newPage();

  for ( const size of [ { width: 1280, height: 480 }, PHONE ] ) {
    await page.setViewportSize( size );
    await page.goto( '/blueworx-forge/#screen=reminders' );
    await expect( page.getByTestId( 'bwx-reminders-table' ) ).toContainText( `Top bar reminder 19 ${ RUN }`, { timeout: 30_000 } );

    // The screen really is longer than the window.
    await expect.poll( () => page.evaluate( () => {
      const main = document.querySelector( '.fs-main' );
      return main.scrollHeight > main.clientHeight;
    } ) ).toBe( true );

    const bar = await page.locator( '.fs-topbar' ).boundingBox();
    expect( bar.height, `top bar at ${ size.width }px wide` ).toBe( 64 );
  }

  await page.close();
  await admin.context.close();
} );
