import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #304. The studio shell: a rail down the left that opens every screen, the
// three ways of drawing one site's work as three entries, links to the two
// screens still in WordPress admin, and a top bar that says where you are.

const RUN = `shell${ Date.now() }`;

test( 'every screen opens from the rail, and the work views are the rail', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw' );
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

  // The two screens still in WordPress admin are a link away.
  await expect( rail.getByTestId( 'bwx-link-packages' ) ).toHaveAttribute( 'href', /wp-admin\/admin\.php\?page=blueworx-forge-packages$/ );
  await expect( rail.getByTestId( 'bwx-link-sync' ) ).toHaveAttribute( 'href', /wp-admin\/admin\.php\?page=blueworx-forge-sync$/ );

  // "New task" in the top bar opens the board's own add form.
  await rail.getByTestId( 'bwx-screen-work' ).click();
  await expect( page.getByTestId( 'bwx-add' ) ).toBeEnabled();
  await page.getByTestId( 'bwx-new-task' ).click();
  await expect( page.getByTestId( 'bwx-new-work' ) ).toBeVisible();

  await page.close();
  await admin.context.close();
} );
