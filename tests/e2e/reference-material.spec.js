import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #523. The Reference material box is gone from every task. What was already
// written in it stays in the database, so saving a task leaves it alone.

const RUN = `refs${ Date.now() }`;

test( 'no task shows a Reference material box, and old text survives a save', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await Forge.makeSite( admin.api, `Refs Co ${ RUN }`, RUN );
  const made = await Forge.makeItem( admin.api, site.id, { title: `Referenced ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  let item = ( await made.json() ).item;

  const edited = await admin.api.patch( `/work-items/${ item.id }`, { references: 'https://example.com/old-notes', record_version: item.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );
  item = ( await edited.json() ).item;
  expect( item.references ).toBe( 'https://example.com/old-notes' );

  // Documentation Period is where the box used to appear.
  item = await Forge.walkTo( admin.api, item, [ 'triage', 'documentation-period' ] );

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ item.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( `Referenced ${ RUN }`, { timeout: 60_000 } );

  await expect( panel.getByTestId( 'bwx-references' ) ).toHaveCount( 0 );
  await expect( panel.getByText( 'Reference material' ) ).toHaveCount( 0 );

  await page.locator( '#bwx-title' ).fill( `Referenced again ${ RUN }` );
  await page.getByTestId( 'bwx-save' ).click();
  await expect( page.getByTestId( 'bwx-panel-notice' ) ).toHaveText( 'Saved.', { timeout: 30_000 } );

  const saved = ( await admin.api.get( `/work-items/${ item.id }` ) ).item;
  expect( saved.title ).toBe( `Referenced again ${ RUN }` );
  expect( saved.references, 'the old text is kept, not wiped' ).toBe( 'https://example.com/old-notes' );

  await admin.context.close();
} );
