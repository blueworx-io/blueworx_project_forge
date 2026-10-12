import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #496. A request's Waiting time counts working days only. Seen a week after
// it was sent, a request sent on a weekday has waited five days, not seven.

const RUN = `wkdy${ Date.now() }`;

test( 'a request open across a weekend leaves Saturday and Sunday out', async ( { browser, baseURL, request } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await Forge.makeSite( admin.api, `Weekday Co ${ RUN }`, RUN );
  const asSite = await Forge.asClientSite( admin.api, site.id, request );
  const sent = await Forge.makeSubmission( asSite, { title: `A new contact form ${ RUN }` } );

  const queued = ( await admin.api.get( '/submissions' ) ).submissions.find( ( one ) => one.id === sent.id );
  const created = new Date( queued.created_at * 1000 );
  const weekend = 0 === created.getDay() || 6 === created.getDay();

  // The browser reads it a week later; a week always holds one weekend.
  const page = await admin.context.newPage();
  await page.clock.setFixedTime( new Date( ( queued.created_at + 7 * 86400 ) * 1000 ) );
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-requests' ).click();
  await page.getByTestId( 'bwx-queue-search' ).fill( RUN );

  const row = page.locator( `[data-testid="bwx-queue-row"][data-submission="${ sent.id }"]` );
  await expect( row ).toBeVisible( { timeout: 30_000 } );
  await expect( row.locator( '[data-label="Waiting"]' ) ).toHaveText( weekend ? '4 days' : '5 days' );

  await page.close();
  await admin.context.close();
} );
