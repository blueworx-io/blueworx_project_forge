import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #483. My tasks and the standup show each task's priority, and both lists
// run by due date first, then priority: urgent, high, normal, low, none.

const RUN = `prio${ Date.now() }`;
const DAY = 86400000;
const on = ( offset ) => new Date( Date.now() + offset * DAY ).toISOString().slice( 0, 10 );
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

/** One item on the site, with the fields given, returned as saved. */
async function item( api, siteId, title, patch ) {
  const made = await Forge.makeItem( api, siteId, { title } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const created = ( await made.json() ).item;
  const edited = await api.patch( `/work-items/${ created.id }`, { ...patch, record_version: created.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );
  return ( await edited.json() ).item;
}

test( 'My tasks has a Priority column and runs by date, then priority', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const { client, site } = await Forge.makeSite( admin.api, `Prio Co ${ RUN }`, RUN );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `prio${ RUN }` );

  // Made out of order, so the order on screen is the screen's own.
  await item( admin.api, site.id, `Today none ${ RUN }`, { primary_user_id: person.id, planned_due: on( 0 ) } );
  await item( admin.api, site.id, `Today low ${ RUN }`, { primary_user_id: person.id, planned_due: on( 0 ), priority: 'low' } );
  await item( admin.api, site.id, `Late low ${ RUN }`, { primary_user_id: person.id, planned_due: on( -2 ), priority: 'low' } );
  await item( admin.api, site.id, `Today urgent ${ RUN }`, { primary_user_id: person.id, planned_due: on( 0 ), priority: 'urgent' } );

  const me = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const page = await me.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-mytasks' ).click();

  const table = page.getByTestId( 'bwx-mytasks-table' );
  await expect( table.locator( 'tbody tr', { hasText: `Today urgent ${ RUN }` } ) ).toBeVisible( { timeout: 60_000 } );
  await expect( table.locator( 'thead' ) ).toContainText( 'Priority' );

  const rows = table.locator( 'tbody tr' );
  await expect( rows ).toHaveCount( 4 );
  await expect( rows.nth( 0 ) ).toContainText( `Late low ${ RUN }` );
  await expect( rows.nth( 1 ) ).toContainText( `Today urgent ${ RUN }` );
  await expect( rows.nth( 2 ) ).toContainText( `Today low ${ RUN }` );
  await expect( rows.nth( 3 ) ).toContainText( `Today none ${ RUN }` );

  await expect( rows.nth( 1 ).getByTestId( 'bwx-mytasks-priority' ) ).toHaveText( /urgent/i );
  await expect( rows.nth( 3 ).getByTestId( 'bwx-mytasks-priority' ) ).toHaveText( '—' );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );

test( 'the standup shows each task’s priority and runs by date, then priority', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const { site } = await Forge.makeSite( admin.api, `Prio Stand ${ RUN }`, `${ RUN }s` );

  const low = await item( admin.api, site.id, `Stand low ${ RUN }`, { planned_due: on( 0 ), priority: 'low' } );
  const late = await item( admin.api, site.id, `Stand late ${ RUN }`, { planned_due: on( -2 ), priority: 'low' } );
  const urgent = await item( admin.api, site.id, `Stand urgent ${ RUN }`, { planned_due: on( 0 ), priority: 'urgent' } );
  const none = await item( admin.api, site.id, `Stand none ${ RUN }`, { planned_due: on( 0 ) } );
  const high = await item( admin.api, site.id, `Stand high ${ RUN }`, { planned_due: on( 0 ), priority: 'high' } );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/#screen=standup' );
  await expect( page.getByTestId( 'bwx-standup' ) ).toBeVisible( { timeout: 30_000 } );

  const section = page.locator( '[data-testid="bwx-standup-section"][data-section="work"]' );
  const toggle = section.getByTestId( 'bwx-standup-section-toggle' );
  if ( 'false' === ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
    await toggle.click();
  }

  const card = ( id ) => section.locator( `[data-testid="bwx-standup-card"][data-subject="${ id }"]` ).first();
  await expect( card( high.id ) ).toBeVisible( { timeout: 30_000 } );
  await expect( card( urgent.id ).getByTestId( 'bwx-standup-priority' ) ).toHaveText( /urgent/i );
  await expect( card( none.id ).getByTestId( 'bwx-standup-priority' ) ).toHaveText( '—' );

  // Where each of ours first appears in the section, top to bottom.
  const ours = [ late.id, urgent.id, high.id, low.id, none.id ];
  const subjects = await section.getByTestId( 'bwx-standup-card' ).evaluateAll( ( els ) => els.map( ( el ) => el.getAttribute( 'data-subject' ) ) );
  const seen = [ ...new Set( subjects.filter( ( id ) => ours.includes( id ) ) ) ];
  expect( seen ).toEqual( ours );

  await page.close();
  await admin.context.close();
} );
