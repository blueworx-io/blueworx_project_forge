import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';
import { giveChecklistTo, publishChecklist } from './helpers/onboarding.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #182, the client half: every view of the client artifact, checked on a real
// client site.
//
// It has to be here rather than in the single-instance suite because these
// screens only exist on a client's own WordPress, drawing work fetched from the
// studio. Checking them anywhere else would be checking markup nobody is served.
//
// The list is the point, the same as the studio's: every screen the client
// plugin registers, so one added without a row here shows up as a gap rather
// than as silence.

const RUN = `a11y${Date.now()}`;

/** Every screen the client plugin registers. */
const CLIENT_SCREENS = [
  [ 'Dashboard', 'blueworx-forge-client' ],
  [ 'Board', 'blueworx-forge-client-board' ],
  [ 'Timeline', 'blueworx-forge-client-timeline' ],
  [ 'Calendar', 'blueworx-forge-client-calendar' ],
  [ 'Checklist', 'blueworx-forge-client-checklist' ],
  [ 'Ask', 'blueworx-forge-client-ask' ],
  [ 'Asked', 'blueworx-forge-client-asked' ],
  [ 'Connection', 'blueworx-forge-client-connection' ],
];

test.beforeAll( requireEnvironment );

test.describe( 'the client site is usable by everyone', () => {
  test( 'every client screen passes', async ( { browser } ) => {
    test.setTimeout( 360_000 );

    // A connected site with work on it and a checklist to work through, so no
    // screen is checked empty. An empty table has no cells to get wrong.
    const pair = await connectedPair( browser, 'Access Co', RUN, {
      title: `Something to draw ${ RUN }`,
      planned_start: '2026-09-10',
      planned_due: '2026-09-12',
    } );

    await publishChecklist( pair.studio, [ `Delegate the domain ${ RUN }` ], RUN );
    await giveChecklistTo( pair.studio, pair.site.id );

    const page = await pair.clientSite.context.newPage();

    for ( const [ name, slug ] of CLIENT_SCREENS ) {
      await page.goto( `/wp-admin/admin.php?page=${ slug }` );
      await checkAccessibility( page, name, 'wp-admin' );
    }

    await page.close();
    await pair.close();
  } );

  test( 'a client can work through their checklist with a keyboard alone', async ( {
    browser,
  } ) => {
    test.setTimeout( 360_000 );

    const step = `Give us access to the hosting ${ RUN }`;
    const pair = await connectedPair( browser, 'Keyboard Co', RUN );

    await publishChecklist( pair.studio, [ step ], RUN );
    await giveChecklistTo( pair.studio, pair.site.id );

    const page = await pair.clientSite.context.newPage();

    await page.goto( '/wp-admin/admin.php?page=blueworx-forge-client-checklist' );

    // The one thing a client has to be able to do on their own site, done
    // without a mouse: type an answer and send it.
    const response = page.locator( '[data-testid="bwx-checklist-response"]' ).first();

    await response.focus();
    await page.keyboard.type( 'Invited your account as an administrator.' );

    // Tab to the submit and press it. A control that can be reached but not
    // operated is not reachable in any sense that matters.
    for ( let press = 0; press < 10; press += 1 ) {
      await page.keyboard.press( 'Tab' );

      const testId = await page.evaluate( () =>
        document.activeElement?.getAttribute( 'data-testid' )
      );

      if ( 'bwx-checklist-submit' === testId ) {
        break;
      }
    }

    expect(
      await page.evaluate( () => document.activeElement?.getAttribute( 'data-testid' ) ),
      'the submit could not be tabbed to'
    ).toBe( 'bwx-checklist-submit' );

    await page.keyboard.press( 'Enter' );

    /*
     * Submitting is a form post that goes out to the studio and comes back, and
     * a key press does not wait for the navigation the way a click does. It is
     * also slower than an ordinary page load — two WordPress installs and a
     * signed call between them — so this waits for the screen to come back
     * rather than for a load state, and gives it room to.
     */
    await page.waitForURL( /page=blueworx-forge-client-checklist/, { timeout: 60_000 } );

    await expect( page.locator( '[data-testid="bwx-checklist-outcome"]' ) ).toContainText(
      'Saved',
      { timeout: 30_000 }
    );

    await page.close();
    await pair.close();
  } );
} );
