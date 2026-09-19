import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// Turning a request into work from the app (2026-09-19): the form asks what
// to call it, what kind it is and where it enters — and never for a parent,
// because nobody wants work hung under other work at triage.

const RUN = `convapp${ Date.now() }`;

test( 'the form makes standalone work without asking for a parent', async ( { browser, baseURL, request } ) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await Forge.makeSite( admin.api, `Convert Co ${ RUN }`, RUN );
  const asSite = await Forge.asClientSite( admin.api, site.id, request );
  const submission = await Forge.makeSubmission( asSite, { title: `A booking form ${ RUN }` } );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-requests' ).click();
  await page.getByTestId( 'bwx-queue-search' ).fill( RUN );

  const row = page.locator( `[data-testid="bwx-queue-row"][data-submission="${ submission.id }"]` );
  await row.getByRole( 'button', { name: `A booking form ${ RUN }` } ).click();

  const form = page.getByTestId( 'bwx-request-convert' );
  await expect( form ).toBeVisible();
  await expect( form.getByTestId( 'bwx-convert-title' ) ).toHaveValue( `A booking form ${ RUN }` );
  await expect( form.getByText( 'Sits under' ) ).toHaveCount( 0 );
  await expect( page.locator( '#bwx-convert-parent' ) ).toHaveCount( 0 );

  await form.getByTestId( 'bwx-convert-save' ).click();
  await expect( row ).toHaveAttribute( 'data-state', 'converted', { timeout: 30_000 } );

  const queue = await admin.api.get( '/submissions' );
  const made = queue.submissions.find( ( each ) => each.id === submission.id );
  expect( made.intake_state ).toBe( 'converted' );

  const item = ( await admin.api.get( `/work-items/${ made.converted_item_id }` ) ).item;
  expect( item.parent_id ).toBe( '' );
  expect( item.title ).toBe( `A booking form ${ RUN }` );

  await page.close();
  await admin.context.close();
} );
