import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #308. The request queue with selection and a bulk bar: two requests picked
// and declined together, one reason, recorded on each of them word for word —
// the same route and the same record as declining one by hand.

const RUN = `bulk${ Date.now() }`;

test( 'a bulk decline records the reason on every request picked', async ( { browser, baseURL, request } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw' );
  const { site } = await Forge.makeSite( admin.api, `Bulk Co ${ RUN }`, RUN );
  const asSite = await Forge.asClientSite( admin.api, site.id, request );

  const first = await Forge.makeSubmission( asSite, { title: `A second staging site ${ RUN }` } );
  const second = await Forge.makeSubmission( asSite, { title: `A darker theme ${ RUN }` } );
  const kept = await Forge.makeSubmission( asSite, { title: `Members-only downloads ${ RUN }` } );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-requests' ).click();
  await page.getByTestId( 'bwx-queue-search' ).fill( RUN );

  const row = ( id ) => page.locator( `[data-testid="bwx-queue-row"][data-submission="${ id }"]` );
  await expect( row( kept.id ) ).toBeVisible();

  // Picking two puts up the bar with the count; declining asks for one reason.
  await row( first.id ).getByRole( 'checkbox' ).click();
  await row( second.id ).getByRole( 'checkbox' ).click();
  const bar = page.getByTestId( 'bwx-queue-bulk' );
  await expect( bar ).toContainText( '2 requests selected' );
  await bar.getByRole( 'button', { name: 'Decline…' } ).click();

  const reason = `Out of scope for the current package ${ RUN }`;
  const dialog = page.getByTestId( 'bwx-queue-decline' );
  await dialog.getByLabel( 'Reason' ).fill( reason );
  await dialog.getByRole( 'button', { name: 'Decline them' } ).click();

  // Both are declined with the reason on them; the third is untouched.
  await expect( row( first.id ) ).toHaveAttribute( 'data-state', 'declined' );
  await expect( row( second.id ) ).toHaveAttribute( 'data-state', 'declined' );
  await expect( row( kept.id ) ).toHaveAttribute( 'data-state', 'received' );
  await expect( bar ).toHaveCount( 0 );

  const queue = await admin.api.get( '/submissions' );
  for ( const id of [ first.id, second.id ] ) {
    const one = queue.submissions.find( ( each ) => each.id === id );
    expect( one.intake_state ).toBe( 'declined' );
    expect( one.response ).toBe( reason );
  }
  expect( queue.submissions.find( ( each ) => each.id === kept.id ).response ).toBe( '' );

  await page.close();
  await admin.context.close();
} );
