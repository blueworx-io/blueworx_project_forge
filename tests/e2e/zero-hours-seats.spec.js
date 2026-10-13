import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #512. Every seat shows the same hours drop-down. With the client reviewing,
// or no design needed, it is a greyed-out "0 hours" rather than a message.

const RUN = `zero${ Date.now() }`;

test( 'the client reviewing, or no design, shows a greyed-out 0 hours', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Zero Co ${ RUN }`, RUN );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `zp${ RUN }` );
  const made = await Forge.makeItem( admin.api, site.id, { title: `Zero ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ item.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( `Zero ${ RUN }`, { timeout: 60_000 } );

  // No design required: the designer's hours are there, at 0, and fixed.
  const design = page.locator( '#bwx-hours_designer' );
  await expect( design ).toBeDisabled();
  await expect( design.locator( 'option:checked' ) ).toHaveText( '0 hours' );

  // The client reviewing: the same, and no message in its place.
  await page.locator( '#bwx-reviewer_id' ).selectOption( 'client' );
  const review = page.locator( '#bwx-hours_review' );
  await expect( review ).toBeDisabled();
  await expect( review.locator( 'option:checked' ) ).toHaveText( '0 hours' );
  await expect( panel ).not.toContainText( 'takes nobody' );

  // A real person again, and the drop-down is usable.
  await page.locator( '#bwx-reviewer_id' ).selectOption( person.id );
  await expect( review ).toBeEnabled();
  await page.locator( '#bwx-designer_id' ).selectOption( person.id );
  await expect( design ).toBeEnabled();

  await admin.context.close();
} );
