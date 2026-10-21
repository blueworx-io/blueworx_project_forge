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

  // #544. The dates save without Save changes, so the record is what is waited for.
  const read = () => admin.api.get( `/work-items/${ item.id }` ).then( ( answer ) => answer.item );

  await dates.getByLabel( 'Design by' ).fill( on( 2 ) );
  await dates.getByLabel( 'Deliver by' ).fill( on( 3 ) );
  // #567. Saved together once the dates are left.
  await dates.getByLabel( 'Deliver by' ).blur();
  await expect.poll( () => read().then( ( saved ) => [ saved.design_target, saved.planned_due, saved.planned_start ] ), { timeout: 30_000 } ).toEqual( [ on( 2 ), on( 3 ), on( 2 ) ] );

  // No design needed: no Design by, and it goes with the designer so no
  // Design by is left behind to trip the order check.
  await page.locator( '#bwx-designer_id' ).selectOption( '' );
  await expect( dates.getByLabel( 'Design by' ) ).toHaveCount( 0 );
  await expect( dates.getByLabel( 'Deliver by' ) ).toBeVisible();
  await expect.poll( () => read().then( ( cleared ) => cleared.design_target ), { timeout: 30_000 } ).toBe( '' );

  await dates.getByLabel( 'Deliver by' ).fill( on( 1 ) );
  await dates.getByLabel( 'Deliver by' ).blur();
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

// #567. Moving several dates is checked once the dates are left, not after
// each one: a date that is out of order for a moment does not snap back.
test( 'several dates can be moved into a new order without any snapping back', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await Forge.makeSite( admin.api, `Move Co ${ RUN }`, RUN );
  const made = await Forge.makeItem( admin.api, site.id, { title: `Moved ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;
  const dated = await admin.api.patch( `/work-items/${ item.id }`, { planned_due: on( 10 ), review_target: on( 12 ), release_target: on( 14 ), record_version: item.record_version } );
  expect( dated.status(), await dated.text() ).toBe( 200 );

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ item.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( `Moved ${ RUN }`, { timeout: 60_000 } );
  const dates = panel.getByTestId( 'bwx-dates' );
  const read = () => admin.api.get( `/work-items/${ item.id }` ).then( ( answer ) => answer.item );

  // Deliver by first, past both of the others: out of order for now.
  await dates.getByLabel( 'Deliver by' ).fill( on( 20 ) );
  await expect( panel.getByTestId( 'bwx-date-order' ) ).toHaveCount( 2 );

  // Nothing is sent while the dates are still being changed, so nothing snaps back.
  await page.waitForTimeout( 3_000 );
  await expect( dates.getByLabel( 'Deliver by' ) ).toHaveValue( on( 20 ) );
  expect( ( await read() ).planned_due ).toBe( on( 10 ) );

  await dates.getByLabel( 'Review by' ).fill( on( 22 ) );
  await dates.getByLabel( 'Release by' ).fill( on( 24 ) );
  await expect( dates.getByLabel( 'Deliver by' ) ).toHaveValue( on( 20 ) );
  await expect( panel.getByTestId( 'bwx-date-order' ) ).toHaveCount( 0 );

  // Leaving the dates saves all three together.
  await page.locator( '#bwx-title' ).click();
  await expect.poll( () => read().then( ( saved ) => [ saved.planned_due, saved.review_target, saved.release_target ] ), { timeout: 30_000 } ).toEqual( [ on( 20 ), on( 22 ), on( 24 ) ] );
  await expect( dates.getByLabel( 'Deliver by' ) ).toHaveValue( on( 20 ) );
  await expect( panel.getByTestId( 'bwx-field-error' ) ).toHaveCount( 0 );

  // Left out of order: kept on screen with the reason, and not sent.
  await dates.getByLabel( 'Release by' ).fill( on( 21 ) );
  await page.locator( '#bwx-title' ).click();
  await expect( panel.getByTestId( 'bwx-date-order' ) ).toHaveAttribute( 'data-field', 'release_target' );
  await page.waitForTimeout( 3_000 );
  await expect( dates.getByLabel( 'Release by' ) ).toHaveValue( on( 21 ) );
  expect( ( await read() ).release_target ).toBe( on( 24 ) );

  await admin.context.close();
} );

// #567. Dates typed and left by closing the panel are kept: Escape closes it
// with focus still in a date, and the date is saved anyway.
test( 'a date typed and then closed with Escape is saved', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await Forge.makeSite( admin.api, `Escape Co ${ RUN }`, RUN );
  const made = await Forge.makeItem( admin.api, site.id, { title: `Escaped ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ item.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( `Escaped ${ RUN }`, { timeout: 60_000 } );

  const deliver = panel.getByTestId( 'bwx-dates' ).getByLabel( 'Deliver by' );
  await deliver.fill( on( 5 ) );
  await expect( deliver ).toBeFocused();
  await page.keyboard.press( 'Escape' );
  await expect( panel ).toHaveCount( 0 );

  await expect.poll( () => admin.api.get( `/work-items/${ item.id }` ).then( ( answer ) => answer.item.planned_due ), { timeout: 30_000 } ).toBe( on( 5 ) );

  await admin.context.close();
} );
