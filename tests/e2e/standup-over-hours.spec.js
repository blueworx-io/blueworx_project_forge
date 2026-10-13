import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #508. Over hours on the standup says which day is over, by the same figures
// the capacity grid shows, and the tasks that fill it.

const RUN = `over${ Date.now() }`;

test( 'Over hours names the day and the tasks that fill it', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Over Co ${ RUN }`, RUN );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `busy${ RUN }` );
  await Forge.setHours( admin.api, person.id, 8 );

  // Twelve hours of work today, against eight.
  const today = ( await admin.api.get( '/standup' ) ).today;
  const made = await Forge.makeItem( admin.api, site.id, { title: `Too much today ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: person.id, hours_primary: 12, planned_start: today, planned_due: today, record_version: item.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );
  const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'in-development', reason: 'Set up for the test.', record_version: ( await edited.json() ).item.record_version } );
  expect( moved.status(), await moved.text() ).toBe( 200 );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/#screen=standup' );
  await page.getByRole( 'button', { name: 'The studio itself' } ).click( { timeout: 60_000 } );
  const card = page.locator( `[data-testid="bwx-standup-card"][data-rule="over-committed"][data-subject="${ person.id }"]` );
  await expect( card ).toBeVisible( { timeout: 60_000 } );
  await expect( card ).toContainText( '12h of 8h' );

  await card.getByTestId( 'bwx-standup-show' ).click();
  const days = card.getByTestId( 'bwx-standup-over-days' );
  await expect( days ).toContainText( `Too much today ${ RUN }`, { timeout: 30_000 } );
  await expect( card.getByTestId( 'bwx-standup-capacity' ) ).toBeVisible();

  await days.getByRole( 'button', { name: `Too much today ${ RUN }` } ).click();
  await expect( page.getByTestId( 'bwx-panel' ) ).toContainText( `Too much today ${ RUN }`, { timeout: 30_000 } );

  // See their capacity lands on that person's own breakdown.
  const landed = await admin.context.newPage();
  await landed.goto( `/blueworx-forge/#screen=capacity&person=${ person.id }` );
  const drilldown = landed.getByTestId( 'bwx-capacity-drilldown' );
  await expect( drilldown ).toBeVisible( { timeout: 60_000 } );
  await expect( drilldown ).toContainText( `busy${ RUN }` );
  await expect( drilldown ).toContainText( `Too much today ${ RUN }`, { timeout: 30_000 } );

  await admin.context.close();
} );
