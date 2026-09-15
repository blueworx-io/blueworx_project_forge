import { test, expect } from '@playwright/test';
import { CLIENT_URL, requireEnvironment, signedIn } from './helpers/pair.js';

// #348. The client plugin's menu entry carries the same Forge icon as the
// studio's, drawn by WordPress like every neighbouring plugin's — not the
// hammer dashicon it started with.

test.beforeAll( requireEnvironment );

test( 'the client site shows the Forge icon in its admin menu', async ( { browser } ) => {
  const client = await signedIn( browser, CLIENT_URL );
  const page = await client.context.newPage();
  await page.goto( '/wp-admin/index.php' );

  const forge = page.locator( '#toplevel_page_blueworx-forge-client' );
  await expect( forge ).toBeVisible();

  // An SVG of ours as the entry's background, and no dashicon glyph.
  const image = forge.locator( '.wp-menu-image' );
  await expect( image ).toHaveClass( /\bsvg\b/ );
  await expect( image ).not.toHaveClass( /dashicons-hammer/ );
  await expect( image ).toHaveCSS( 'background-image', /data:image\/svg\+xml/ );
} );
