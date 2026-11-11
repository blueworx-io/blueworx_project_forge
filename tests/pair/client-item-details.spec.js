import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment, CLIENT_URL, STUDIO_URL } from './helpers/pair.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #671. A client opening an item sees what it is — description, type, stage,
// dates, checklist — read-only, with the conversation underneath. And the
// studio can copy a link that takes a client straight to it, through sign-in.

const RUN = `details${ Date.now() }`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test.beforeAll( requireEnvironment );

test( 'a client sees an item\'s details, and a copied link opens it after sign-in', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const title = `Opt people in ${ RUN }`;
  const pair = await connectedPair( browser, 'Details Co', RUN, {
    title,
    problem: `<p>Goal: people are <strong>opted in</strong> ${ RUN }.</p>`,
    checklist: [
      { text: `Agree the wording ${ RUN }`, done: true },
      { text: `Build it ${ RUN }`, done: false },
    ],
    planned_start: '2026-10-01',
    planned_due: '2026-10-10',
  } );
  const link = `${ CLIENT_URL.replace( /\/$/, '' ) }/?forge_item=${ pair.work.id }`;

  // The studio's panel offers the client's link beside its own.
  await pair.studio.context.grantPermissions( [ 'clipboard-read', 'clipboard-write' ], { origin: new URL( STUDIO_URL ).origin } );
  const studioPage = await pair.studio.context.newPage();
  await studioPage.goto( `/blueworx-forge/#item=${ pair.work.id }` );
  const copy = studioPage.getByTestId( 'bwx-copy-client-link' );
  await expect( copy ).toBeVisible( { timeout: 60_000 } );
  await expect( copy ).toHaveAttribute( 'data-link', link );
  await copy.click();
  await expect( studioPage.getByTestId( 'bwx-copy-client-link-done' ) ).toHaveText( 'Client link copied' );
  await studioPage.close();

  // Signed in on the client's site, the link opens the item.
  const page = await pair.clientSite.context.newPage();
  await page.goto( link );
  await expect( page ).toHaveURL( new RegExp( `#board/${ pair.work.id }$` ) );

  const record = page.getByTestId( 'bwx-item-record' );
  const details = record.getByTestId( 'bwx-item-details' );
  await expect( details ).toBeVisible( { timeout: 60_000 } );
  await expect( page.getByRole( 'dialog' ) ).toContainText( title );
  await expect( details.getByTestId( 'bwx-item-description' ) ).toContainText( `people are opted in ${ RUN }` );
  await expect( details ).toContainText( 'Planned start' );
  await expect( details ).toContainText( '01 Oct 2026' );
  await expect( details ).toContainText( '10 Oct 2026' );

  const lines = details.getByTestId( 'bwx-item-checklist' ).getByRole( 'listitem' );
  await expect( lines ).toHaveCount( 2 );
  await expect( lines.nth( 0 ) ).toContainText( 'Done' );
  await expect( lines.nth( 1 ) ).toContainText( 'To do' );

  // Nothing in the details can be typed into or ticked.
  await expect( details.locator( 'input, textarea, select, button, [contenteditable="true"]' ) ).toHaveCount( 0 );

  // The conversation is still there to add to.
  await expect( record.getByTestId( 'bwx-say-form' ) ).toBeVisible();

  await checkAccessibility( page, 'Client item details', 'app' );
  await page.close();

  // Signed out, the same link asks for sign-in and then opens the item.
  const stranger = await browser.newContext( { baseURL: CLIENT_URL } );
  const fresh = await stranger.newPage();
  await fresh.goto( link );
  await expect( fresh ).toHaveURL( /wp-login\.php/ );
  await fresh.locator( '#user_login' ).fill( ADMIN_USER );
  await fresh.locator( '#user_pass' ).fill( ADMIN_PASS );
  await fresh.locator( '#wp-submit' ).click();
  await expect( fresh ).toHaveURL( new RegExp( `#board/${ pair.work.id }$` ), { timeout: 60_000 } );
  await expect( fresh.getByTestId( 'bwx-item-details' ) ).toBeVisible( { timeout: 60_000 } );
  await stranger.close();

  await pair.close();
} );
