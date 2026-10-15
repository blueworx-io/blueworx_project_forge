import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #513. A task's dates have their own section, one per person: Design by,
// Deliver by, Review by, Release by. Design by goes when no design is needed,
// and Forge sets the start itself from the earliest of them.

const RUN = `dates${ Date.now() }`;
const DAY = 86400000;
const on = ( offset ) => new Date( Date.now() + offset * DAY ).toISOString().slice( 0, 10 );

test( 'the dates sit in their own section, one per person', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Dates Co ${ RUN }`, RUN );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `dp${ RUN }` );
  const made = await Forge.makeItem( admin.api, site.id, { title: `Dated ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ item.id }`, { designer_id: person.id, record_version: item.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ item.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( `Dated ${ RUN }`, { timeout: 60_000 } );

  const dates = panel.getByTestId( 'bwx-dates' );
  await expect( dates ).toBeVisible();
  for ( const label of [ 'Design by', 'Deliver by', 'Review by', 'Release by' ] ) {
    await expect( dates.getByLabel( label ) ).toBeVisible();
  }
  await expect( panel.getByLabel( 'Starts' ) ).toHaveCount( 0 );
  await expect( panel.getByLabel( 'Due' ) ).toHaveCount( 0 );

  // Who and hours holds no dates any more.
  const assign = panel.getByTestId( 'bwx-assign' );
  await expect( assign.getByTestId( 'bwx-section-assign' ) ).toHaveText( 'Who and hours' );
  await expect( assign.locator( 'input[type="date"]' ) ).toHaveCount( 0 );

  // #544. Each date saves as it is picked, so the record is what is waited for.
  const read = () => admin.api.get( `/work-items/${ item.id }` ).then( ( answer ) => answer.item );

  await dates.getByLabel( 'Design by' ).fill( on( 2 ) );
  await dates.getByLabel( 'Deliver by' ).fill( on( 3 ) );
  await expect.poll( () => read().then( ( saved ) => [ saved.design_target, saved.planned_due, saved.planned_start ] ), { timeout: 30_000 } ).toEqual( [ on( 2 ), on( 3 ), on( 2 ) ] );

  // No design needed: no Design by, and it goes with the designer so no
  // Design by is left behind to trip the order check.
  await page.locator( '#bwx-designer_id' ).selectOption( '' );
  await expect( dates.getByLabel( 'Design by' ) ).toHaveCount( 0 );
  await expect( dates.getByLabel( 'Deliver by' ) ).toBeVisible();
  await expect.poll( () => read().then( ( cleared ) => cleared.design_target ), { timeout: 30_000 } ).toBe( '' );

  await dates.getByLabel( 'Deliver by' ).fill( on( 1 ) );
  await expect.poll( () => read().then( ( cleared ) => [ cleared.design_target, cleared.planned_start ] ), { timeout: 30_000 } ).toEqual( [ '', on( 1 ) ] );

  await admin.context.close();
} );

// #522. The pickers refuse days before the date that comes first, and a date
// left behind when an earlier one moves past it is flagged on the spot.
test( 'the date pickers keep the dates in order, and say so when they fall out of it', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Order Co ${ RUN }`, RUN );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `op${ RUN }` );
  const made = await Forge.makeItem( admin.api, site.id, { title: `Ordered ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ item.id }`, { designer_id: person.id, record_version: item.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ item.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( `Ordered ${ RUN }`, { timeout: 60_000 } );
  const dates = panel.getByTestId( 'bwx-dates' );

  // Design by is the earliest: nothing before it. The rest cannot be earlier than it.
  await dates.getByLabel( 'Design by' ).fill( on( 2 ) );
  await expect( dates.getByLabel( 'Design by' ) ).not.toHaveAttribute( 'min', /./ );
  for ( const label of [ 'Deliver by', 'Review by', 'Release by' ] ) {
    await expect( dates.getByLabel( label ) ).toHaveAttribute( 'min', on( 2 ) );
  }

  // In order: no complaint.
  await dates.getByLabel( 'Deliver by' ).fill( on( 3 ) );
  await dates.getByLabel( 'Review by' ).fill( on( 4 ) );
  await expect( dates.getByLabel( 'Review by' ) ).toHaveAttribute( 'min', on( 3 ) );
  await expect( panel.getByTestId( 'bwx-date-order' ) ).toHaveCount( 0 );

  // Deliver by moved past Review by: Review by is told, straight away.
  await dates.getByLabel( 'Deliver by' ).fill( on( 6 ) );
  const flagged = panel.getByTestId( 'bwx-date-order' );
  await expect( flagged ).toHaveCount( 1 );
  await expect( flagged ).toHaveAttribute( 'data-field', 'review_target' );
  await expect( flagged ).toHaveText( 'Review by cannot be before deliver by.' );
  await expect( dates.getByLabel( 'Review by' ) ).toHaveAttribute( 'aria-invalid', 'true' );
  await dates.getByLabel( 'Review by' ).fill( on( 7 ) );
  await expect( flagged ).toHaveCount( 0 );

  // No design: Deliver by is the earliest and holds nothing back.
  await page.locator( '#bwx-designer_id' ).selectOption( '' );
  await expect( dates.getByLabel( 'Deliver by' ) ).not.toHaveAttribute( 'min', /./ );
  await expect( dates.getByLabel( 'Review by' ) ).toHaveAttribute( 'min', on( 6 ) );

  await admin.context.close();
} );
