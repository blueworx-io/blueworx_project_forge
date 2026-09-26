import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem, makePerson } from './helpers/forge.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #304. The studio shell: a rail down the left that opens every screen, the
// three ways of drawing one site's work as three entries, links to the two
// screens still in WordPress admin, and a top bar that says where you are.

const RUN = `shell${ Date.now() }`;

test( 'every screen opens from the rail, and the work views are the rail', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await makeSite( admin.api, `Shell Co ${ RUN }`, RUN );
  await makeItem( admin.api, site.id, { title: `Something to draw ${ RUN }` } );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await expect( page.getByTestId( 'bwx-forge-ready' ) ).toBeVisible();

  const rail = page.getByRole( 'navigation', { name: 'Screens' } );
  const title = page.locator( '.fs-title' );

  // Kanban is where it opens, and the rail says so.
  await expect( rail.getByTestId( 'bwx-screen-work' ) ).toHaveAttribute( 'aria-current', 'page' );
  await expect( title ).toHaveText( 'Kanban' );

  // Gantt and Calendar are the work screen in another view — the board's own
  // switch and the rail agree in both directions.
  await rail.getByTestId( 'bwx-screen-gantt' ).click();
  await expect( page.getByTestId( 'bwx-view-gantt' ) ).toHaveAttribute( 'aria-pressed', 'true' );
  await expect( title ).toHaveText( 'Gantt' );
  await page.getByTestId( 'bwx-view-calendar' ).click();
  await expect( rail.getByTestId( 'bwx-screen-calendar' ) ).toHaveAttribute( 'aria-current', 'page' );
  await expect( title ).toHaveText( 'Calendar' );

  await checkAccessibility( page, 'Studio shell', 'app' );

  // Every other screen opens from its entry.
  for ( const [ testId, heading ] of [
    [ 'bwx-screen-standup', 'Daily standup' ],
    [ 'bwx-screen-capacity', 'Capacity' ],
    [ 'bwx-screen-requests', 'Requests review' ],
    [ 'bwx-screen-onboarding', 'Onboarding board' ],
    [ 'bwx-screen-reports', 'Reports' ],
  ] ) {
    await rail.getByTestId( testId ).click();
    await expect( rail.getByTestId( testId ) ).toHaveAttribute( 'aria-current', 'page' );
    await expect( title ).toHaveText( heading );
    await expect( page.locator( '[data-state="loading"]' ) ).toHaveCount( 0, { timeout: 30_000 } );
  }

  // Sync health is the one screen still in WordPress admin; it is a link away.
  await expect( rail.getByTestId( 'bwx-link-sync' ) ).toHaveAttribute( 'href', /wp-admin\/admin\.php\?page=blueworx-forge-sync$/ );

  // "New task" in the top bar opens the board's own add form — from any
  // screen, going to the board first and waiting for a site to open it for.
  await page.getByTestId( 'bwx-new-task' ).click();
  await expect( rail.getByTestId( 'bwx-screen-work' ) ).toHaveAttribute( 'aria-current', 'page' );
  await expect( page.getByTestId( 'bwx-new-work' ) ).toBeVisible( { timeout: 30_000 } );

  await page.close();
  await admin.context.close();
} );

// #407: scrolling past the end never drags the page itself — the rail and
// top bar stay exactly where they are, and only the content area moves.
test( 'scrolling past the end leaves the rail and top bar in place; only the content scrolls', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await makeSite( admin.api, `Scroll Co ${ RUN }`, `${ RUN }s` );
  const person = await makePerson( admin.api, client.id, 'staff', `scroll${ RUN }` );
  const today = ( await admin.api.get( '/standup' ) ).today;

  // Reminders is one row per record; enough of its own makes this test's
  // overflow certain, rather than depending on how much a reused instance
  // happens to already hold.
  for ( let i = 0; i < 20; i++ ) {
    const made = await admin.api.post( '/reminders', { client_site_id: site.id, title: `Scroll reminder ${ i } ${ RUN }`, assignees: [ person.id ], starts_on: today } );
    expect( made.status(), await made.text() ).toBe( 200 );
  }

  const page = await admin.context.newPage();
  // A short viewport, so this test's own rows overflow it for certain.
  await page.setViewportSize( { width: 1280, height: 480 } );
  await page.goto( '/blueworx-forge/' );
  await expect( page.getByTestId( 'bwx-forge-ready' ) ).toBeVisible();

  await page.getByTestId( 'bwx-screen-reminders' ).click();
  await expect( page.getByTestId( 'bwx-reminders' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( page.getByTestId( 'bwx-reminders-table' ) ).toContainText( `Scroll reminder 19 ${ RUN }`, { timeout: 30_000 } );

  const rail = page.locator( '.fs-rail' );
  const topbar = page.locator( '.fs-topbar' );
  const before = { rail: await rail.boundingBox(), topbar: await topbar.boundingBox() };

  await page.mouse.move( 640, 300 );
  await page.mouse.wheel( 0, 100_000 );

  // The content area is the one that moved.
  await expect.poll( () => page.evaluate( () => document.querySelector( '.fs-main' )?.scrollTop ?? 0 ) ).toBeGreaterThan( 0 );

  // The window itself never moved, and neither did the rail or top bar.
  expect( await page.evaluate( () => window.scrollY ) ).toBe( 0 );
  expect( await rail.boundingBox() ).toEqual( before.rail );
  expect( await topbar.boundingBox() ).toEqual( before.topbar );

  await page.close();
  await admin.context.close();
} );
