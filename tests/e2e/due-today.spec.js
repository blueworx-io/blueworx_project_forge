import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// #527. A card due today is not late. The day after, it is a day late.

const RUN = `duetoday${ Date.now() }`;
const DAY = 86400000;

/** A date as the browser sees it, some days from today. */
function localDate( offset ) {
  const at = new Date( Date.now() + offset * DAY );
  const pad = ( value ) => String( value ).padStart( 2, '0' );

  return `${ at.getFullYear() }-${ pad( at.getMonth() + 1 ) }-${ pad( at.getDate() ) }`;
}

test( 'a card due today shows its date, and one due yesterday shows -1d', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await makeSite( admin.api, `Due Co ${ RUN }`, RUN );

  const dated = async ( title, due ) => {
    const made = await makeItem( admin.api, site.id, { title } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const item = ( await made.json() ).item;
    const edited = await admin.api.patch( `/work-items/${ item.id }`, { planned_start: due, planned_due: due, record_version: item.record_version } );
    expect( edited.status(), await edited.text() ).toBe( 200 );
  };

  await dated( `Due today ${ RUN }`, localDate( 0 ) );
  await dated( `Due yesterday ${ RUN }`, localDate( -1 ) );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.waitForSelector( '[data-testid="bwx-board"]' );
  await page.selectOption( '[data-testid="bwx-client-choice"]', site.id );

  const today = page.getByTestId( 'bwx-card' ).filter( { hasText: `Due today ${ RUN }` } ).locator( '.bwx-card-when' );
  const yesterday = page.getByTestId( 'bwx-card' ).filter( { hasText: `Due yesterday ${ RUN }` } ).locator( '.bwx-card-when' );

  await expect( today ).not.toHaveAttribute( 'data-late', 'true' );
  await expect( today ).not.toContainText( /-\d+d/ );
  await expect( yesterday ).toHaveAttribute( 'data-late', 'true' );
  await expect( yesterday ).toContainText( '-1d' );

  await page.close();
  await admin.context.close();
} );
