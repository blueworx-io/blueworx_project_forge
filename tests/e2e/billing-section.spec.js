import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #525. Who pays for it and Priority sit in their own section, directly above
// Who and hours, which holds only the people and their hours.

const RUN = `billing${ Date.now() }`;

test( 'Who pays and Priority have their own section above Who and hours', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await Forge.makeSite( admin.api, `Billing Co ${ RUN }`, RUN );
  const made = await Forge.makeItem( admin.api, site.id, { title: `Billed ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ item.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( `Billed ${ RUN }`, { timeout: 60_000 } );

  const billing = panel.getByTestId( 'bwx-billing' );
  const assign = panel.getByTestId( 'bwx-assign' );

  await expect( billing.getByTestId( 'bwx-section-billing' ) ).toHaveText( 'Billing and priority' );
  await expect( billing.locator( '#bwx-commercial_class' ) ).toBeVisible();
  await expect( billing.locator( '#bwx-priority' ) ).toBeVisible();
  await expect( billing.locator( '#bwx-primary_user_id' ) ).toHaveCount( 0 );

  await expect( assign.getByTestId( 'bwx-section-assign' ) ).toHaveText( 'Who and hours' );
  await expect( assign.locator( '#bwx-primary_user_id' ) ).toBeVisible();
  await expect( assign.locator( '#bwx-commercial_class' ) ).toHaveCount( 0 );
  await expect( assign.locator( '#bwx-priority' ) ).toHaveCount( 0 );

  const above = await billing.boundingBox();
  const below = await assign.boundingBox();
  expect( above.y ).toBeLessThan( below.y );

  // The fields still save from where they now sit.
  await billing.locator( '#bwx-priority' ).selectOption( 'urgent' );
  await page.getByTestId( 'bwx-save' ).click();
  await expect( page.getByTestId( 'bwx-panel-notice' ) ).toHaveText( 'Saved.', { timeout: 30_000 } );
  expect( ( await admin.api.get( `/work-items/${ item.id }` ) ).item.priority ).toBe( 'urgent' );

  await admin.context.close();
} );
