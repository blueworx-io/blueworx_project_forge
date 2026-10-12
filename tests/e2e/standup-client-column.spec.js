import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #490. Each row on the standup names its client, in a column just before Priority.

const RUN = `stcl${ Date.now() }`;
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const today = new Date().toISOString().slice( 0, 10 );

test( 'the standup shows each row’s client, before its priority', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const name = `Client Col ${ RUN }`;
  const { client: made_client, site } = await Forge.makeSite( admin.api, name, RUN );

  const made = await Forge.makeItem( admin.api, site.id, { title: `Col task ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const created = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ created.id }`, { planned_due: today, priority: 'high', record_version: created.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/#screen=standup' );
  await expect( page.getByTestId( 'bwx-standup' ) ).toBeVisible( { timeout: 30_000 } );

  const section = page.locator( '[data-testid="bwx-standup-section"][data-section="work"]' );
  const toggle = section.getByTestId( 'bwx-standup-section-toggle' );
  if ( 'false' === ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
    await toggle.click();
  }

  const card = section.locator( `[data-testid="bwx-standup-card"][data-subject="${ created.id }"]` ).first();
  await expect( card ).toBeVisible( { timeout: 30_000 } );

  const client = card.getByTestId( 'bwx-standup-client' );
  await expect( client ).toHaveText( made_client.display_name );

  // Just before Priority, on the same line.
  const clientBox = await client.boundingBox();
  const priorityBox = await card.getByTestId( 'bwx-standup-priority' ).boundingBox();
  expect( clientBox.x + clientBox.width ).toBeLessThanOrEqual( priorityBox.x );

  await page.close();
  await admin.context.close();
} );
