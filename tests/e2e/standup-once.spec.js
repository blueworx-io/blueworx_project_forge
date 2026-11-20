import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// A task shows once on the standup, under its most pressing reason, with the
// others said on the same row (Luke, 2026-11-20). Finished and due today, it
// was listed twice: once as due today and once as ready to ship.

const RUN = `once${ Date.now() }`;

test( 'a task due today and ready to ship is on the standup once', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Once Co ${ RUN }`, RUN );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `once${ RUN }` );
  const today = ( await admin.api.get( '/standup' ) ).today;

  const made = await Forge.makeItem( admin.api, site.id, { title: `Ship it ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  let item = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: person.id, deliverer_id: person.id, planned_due: today, record_version: item.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );
  item = ( await admin.api.get( `/work-items/${ item.id }` ) ).item;
  const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'completed', reason: 'Set up for the test.', record_version: item.record_version } );
  expect( moved.status(), await moved.text() ).toBe( 200 );

  // The rules still report both reasons; the board shows them on one row.
  const rules = ( await admin.api.get( '/standup' ) ).cards.filter( ( card ) => card.subject_id === item.id ).map( ( card ) => card.rule );
  expect( rules.sort() ).toEqual( [ 'awaiting-release', 'due-today' ] );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/#screen=standup' );
  await expect( page.getByTestId( 'bwx-standup' ) ).toBeVisible( { timeout: 60_000 } );
  await expect( page.getByTestId( 'bwx-standup-section-toggle' ).first() ).toBeVisible();
  const folded = page.locator( '[data-testid="bwx-standup-section-toggle"][aria-expanded="false"]' );
  while ( ( await folded.count() ) > 0 ) await folded.first().click();

  const rows = page.locator( `[data-testid="bwx-standup-card"][data-subject="${ item.id }"]` );
  await expect( rows ).toHaveCount( 1, { timeout: 60_000 } );
  await expect( rows ).toHaveAttribute( 'data-rule', 'due-today' );
  await expect( rows.getByTestId( 'bwx-standup-also' ) ).toHaveText( 'Also ready to ship' );

  await page.close();
  await admin.context.close();
} );
