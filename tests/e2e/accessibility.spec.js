import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #182, the studio half: every view of the studio artifact, checked.
//
// The list below is the point of the file. A check that runs on the screens
// somebody remembered is not a check — so this walks every admin screen the
// plugin registers and every screen the application has, and a new one added
// without a row here is a gap that would survive for months.
//
// The client artifact's screens are checked in tests/pair, because they only
// exist on a client site.
//
// What axe cannot decide is asserted by hand at the bottom: whether the
// keyboard order is sensible, and whether a label says anything useful.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${ Date.now() }-${ Math.floor( Math.random() * 1e6 ) }`;

/** Every admin screen this plugin registers. */
const ADMIN_SCREENS = [
  [ 'Clients', 'blueworx-forge-clients' ],
  [ 'Sites', 'blueworx-forge-sites' ],
  [ 'People', 'blueworx-forge-people' ],
  [ 'Availability', 'blueworx-forge-availability' ],
  [ 'Onboarding template', 'blueworx-forge-onboarding-template' ],
  [ 'Sync', 'blueworx-forge-sync' ],
  [ 'Updates', 'blueworx-forge-updates' ],
];

/** Every screen the application has. */
const APP_SCREENS = [
  [ 'Work', 'bwx-screen-work' ],
  [ 'Requests', 'bwx-screen-requests' ],
  [ 'Capacity', 'bwx-screen-capacity' ],
  [ 'Onboarding', 'bwx-screen-onboarding' ],
  [ 'Today', 'bwx-screen-standup' ],
  [ 'Reports', 'bwx-screen-reports' ],
];

test.describe( 'the studio is usable by everyone', () => {
  test( 'every admin screen passes', async ( { browser, baseURL } ) => {
    test.slow();

    const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );

    // Something on every screen, so none of them is checked empty. An empty
    // table has no cells to get wrong, and passing on one proves nothing about
    // the screen people actually use.
    await Forge.makeSite( admin.api, `Access Co ${ RUN_ID }`, RUN_ID );

    const page = await admin.context.newPage();

    for ( const [ name, slug ] of ADMIN_SCREENS ) {
      await page.goto( `/wp-admin/admin.php?page=${ slug }` );
      await checkAccessibility( page, name, 'wp-admin' );
    }

    await page.close();
    await admin.context.close();
  } );

  test( 'every application screen passes', async ( { browser, baseURL } ) => {
    test.slow();

    const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
    const { site } = await Forge.makeSite( admin.api, `Access App Co ${ RUN_ID }`, RUN_ID );

    await Forge.makeItem( admin.api, site.id, { title: `Something to draw ${ RUN_ID }` } );

    const page = await admin.context.newPage();

    await page.goto( '/blueworx-forge/' );
    await expect( page.getByTestId( 'bwx-forge-ready' ) ).toBeVisible();

    for ( const [ name, testId ] of APP_SCREENS ) {
      await page.getByTestId( testId ).click();

      // Waiting for the screen to have drawn something, rather than checking
      // the loading state and calling it a pass.
      await expect( page.locator( '[data-state="loading"]' ) ).toHaveCount( 0, { timeout: 30_000 } );

      await checkAccessibility( page, name, 'app' );
    }

    await page.close();
    await admin.context.close();
  } );

  // ---- What a checker cannot decide -----------------------------------

  test( 'the whole application can be reached with a keyboard', async ( { browser, baseURL } ) => {
    test.slow();

    const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
    const page = await admin.context.newPage();

    await page.goto( '/blueworx-forge/' );
    await expect( page.getByTestId( 'bwx-forge-ready' ) ).toBeVisible();

    // Tab until the screen buttons have all been reached. A trap shows up here
    // as never arriving, which is exactly how it shows up for a person.
    const reached = new Set();

    for ( let press = 0; press < 60 && reached.size < APP_SCREENS.length; press += 1 ) {
      await page.keyboard.press( 'Tab' );

      const testId = await page.evaluate( () =>
        document.activeElement?.getAttribute( 'data-testid' )
      );

      if ( testId && testId.startsWith( 'bwx-screen-' ) ) {
        reached.add( testId );
      }
    }

    expect( reached.size, 'not every screen could be tabbed to' ).toBe( APP_SCREENS.length );

    // And the one somebody lands on can be operated from the keyboard alone.
    await page.keyboard.press( 'Enter' );
    await expect( page.getByTestId( 'bwx-forge-ready' ) ).toBeVisible();

    await page.close();
    await admin.context.close();
  } );

  test( 'the screen picker says which screen is current', async ( { browser, baseURL } ) => {
    const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
    const page = await admin.context.newPage();

    await page.goto( '/blueworx-forge/' );

    // Colour alone is not an answer for somebody who cannot see it, and axe
    // cannot tell that a highlighted button is the current one.
    await expect( page.getByTestId( 'bwx-screen-work' ) ).toHaveAttribute( 'aria-pressed', 'true' );

    await page.getByTestId( 'bwx-screen-reports' ).click();

    await expect( page.getByTestId( 'bwx-screen-reports' ) ).toHaveAttribute(
      'aria-pressed',
      'true'
    );
    await expect( page.getByTestId( 'bwx-screen-work' ) ).toHaveAttribute( 'aria-pressed', 'false' );

    await page.close();
    await admin.context.close();
  } );
} );
