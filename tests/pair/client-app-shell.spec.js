import { test, expect } from '@playwright/test';
import { CLIENT_URL, requireEnvironment, signedIn } from './helpers/pair.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #297. The client workspace is a page on the client's own site, served by
// the client plugin with the theme kept out, reached from their wp-admin menu
// — and closed to anybody who cannot use the wp-admin screens beside it.

const PAGE = '/forge/';

test.beforeAll( requireEnvironment );

test( 'a signed-in client sees the workspace shell on their own site', async ( { browser } ) => {
  const client = await signedIn( browser, CLIENT_URL );
  const page = await client.context.newPage();

  await page.goto( PAGE );

  const shell = page.getByTestId( 'bwx-client-app' );
  await expect( shell ).toBeVisible();

  const nav = shell.getByRole( 'navigation', { name: 'Workspace' } );
  for ( const label of [ 'Dashboard', 'Your work', 'Bugs & requests', 'Support & hours', 'Launch readiness' ] ) {
    await expect( nav.getByRole( 'link', { name: label } ) ).toBeVisible();
  }
  await expect( shell.getByRole( 'link', { name: 'Sign out' } ) ).toBeVisible();

  // The page is the plugin's own: nothing from the theme reaches it.
  const strangers = await page.evaluate( () =>
    [ ...document.querySelectorAll( 'link[rel="stylesheet"]' ) ]
      .map( ( el ) => el.href )
      .filter( ( href ) => ! href.includes( '/plugins/blueworx-forge-client/' ) )
  );
  expect( strangers, `something else is styling the workspace: ${ strangers.join( ', ' ) }` ).toEqual( [] );

  await checkAccessibility( page, 'Client workspace', 'app' );

  // Moving between screens is the URL, so a screen can be linked to.
  await nav.getByRole( 'link', { name: 'Support & hours' } ).click();
  await expect( page ).toHaveURL( /#sales$/ );
  await expect( shell.getByRole( 'heading', { level: 1, name: 'Support & hours' } ) ).toBeVisible();

  await page.close();
  await client.context.close();
} );

test( 'the wp-admin menu links to the workspace', async ( { browser } ) => {
  const client = await signedIn( browser, CLIENT_URL );
  const page = await client.context.newPage();

  await page.goto( '/wp-admin/admin.php?page=blueworx-forge-client' );
  const link = page.locator( '#adminmenu' ).getByRole( 'link', { name: 'Open workspace' } );
  await expect( link ).toBeVisible();
  await expect( link ).toHaveAttribute( 'href', /\/forge\/?$/ );

  await page.close();
  await client.context.close();
} );

test( 'somebody not signed in is sent to sign in', async ( { browser } ) => {
  const context = await browser.newContext( { baseURL: CLIENT_URL } );
  const page = await context.newPage();

  await page.goto( PAGE );

  await expect( page ).toHaveURL( /wp-login\.php/ );
  await expect( page.getByTestId( 'bwx-client-app' ) ).toHaveCount( 0 );

  await context.close();
} );
