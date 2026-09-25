import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #309. My tasks: every item that is the signed-in person's to act on at the
// stage it is at, once, on every client, over one table with four views — and
// the three dated views add up to Everything. Since 2026-09-24 a seat alone is
// not enough: the owner has it until review, the checker in review, the
// builder once completed.

const RUN = `mine${ Date.now() }`;
const DAY = 86400000;
const on = ( offset ) => new Date( Date.now() + offset * DAY ).toISOString().slice( 0, 10 );

test( 'the four views reconcile, and each opens the item', async ( { browser, baseURL } ) => {
  test.setTimeout( 300_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Mine Co ${ RUN }`, RUN );
  const other = await Forge.makeSite( admin.api, `Other Co ${ RUN }`, `${ RUN }b` );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `mine${ RUN }` );
  await admin.api.post( `/clients/${ other.client.id }/memberships`, { user_id: person.id, role: 'staff' } );

  // Late, this week and further out, one in review on another client so the
  // count is across clients, and the seats that are not theirs to act on yet.
  const seat = async ( siteId, title, patch, stage = '' ) => {
    const made = await Forge.makeItem( admin.api, siteId, { title } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const item = ( await made.json() ).item;
    const edited = await admin.api.patch( `/work-items/${ item.id }`, { ...patch, record_version: item.record_version } );
    expect( edited.status(), await edited.text() ).toBe( 200 );
    if ( '' !== stage ) {
      const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: stage, reason: 'Set up for the test.', record_version: ( await edited.json() ).item.record_version } );
      expect( moved.status(), await moved.text() ).toBe( 200 );
    }
  };
  // Captured work is the owner's: theirs, but not the checker's or builder's yet.
  await seat( site.id, `Late one ${ RUN }`, { primary_user_id: person.id, planned_due: on( -3 ) } );
  await seat( site.id, `This week ${ RUN }`, { primary_user_id: person.id, planned_due: on( 4 ) } );
  await seat( site.id, `Not yet checking ${ RUN }`, { reviewer_id: person.id, planned_due: on( 30 ) } );
  await seat( other.site.id, `Not yet building ${ RUN }`, { deliverer_id: person.id } );
  await seat( site.id, `Not theirs ${ RUN }`, { planned_due: on( 1 ) } );
  // Two seats on one task is one row, not two.
  await seat( site.id, `Both seats ${ RUN }`, { primary_user_id: person.id, deliverer_id: person.id, planned_due: on( 30 ) } );
  // In review it is the checker's, on another client, and no longer the owner's.
  await seat( other.site.id, `Checking ${ RUN }`, { reviewer_id: person.id }, 'in-review' );
  await seat( site.id, `Gone to review ${ RUN }`, { primary_user_id: person.id }, 'in-review' );

  const me = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const page = await me.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-mytasks' ).click();

  const table = page.getByTestId( 'bwx-mytasks-table' );
  await expect( table ).toBeVisible( { timeout: 60_000 } );

  const count = async ( name ) => {
    const pill = table.getByRole( 'button', { name: new RegExp( `^${ name }` ) } );
    return parseInt( ( await pill.innerText() ).replace( /\D/g, '' ), 10 );
  };
  const today = await count( 'Today' );
  const week = await count( 'Next seven days' );
  const later = await count( 'Further out' );
  const all = await count( 'Everything' );
  expect( all ).toBe( 4 );
  expect( today + week + later, 'the three views add up to everything' ).toBe( all );

  // Today holds the late one and the one in review with them.
  await expect( table ).toContainText( `Late one ${ RUN }` );
  await table.getByRole( 'button', { name: /^Everything/ } ).click();
  await expect( table.locator( 'tbody tr', { hasText: `Both seats ${ RUN }` } ) ).toHaveCount( 1 );
  await expect( table.locator( 'tbody tr', { hasText: `Checking ${ RUN }` } ) ).toContainText( 'Checker' );
  for ( const gone of [ 'Not yet checking', 'Not yet building', 'Not theirs', 'Gone to review' ] ) {
    await expect( table ).not.toContainText( `${ gone } ${ RUN }` );
  }

  // A row opens the record.
  await table.getByRole( 'button', { name: `Late one ${ RUN }` } ).click();
  await expect( page.getByTestId( 'bwx-panel' ) ).toContainText( `Late one ${ RUN }` );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );

// #386: Today's diary moved here from the standup, and shows only what is
// the signed-in person's — a chore named for somebody else is none of theirs.
test( 'two people with chores today each see only their own in their diary', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Diary Co ${ RUN }`, `${ RUN }d` );
  const today = ( await admin.api.get( '/standup' ) ).today;
  const one = await Forge.makePerson( admin.api, client.id, 'staff', `d1${ RUN }` );
  const two = await Forge.makePerson( admin.api, client.id, 'staff', `d2${ RUN }` );

  const madeOne = await admin.api.post( '/recurring', {
    title: `Ones chore ${ RUN }`,
    description: '<p>Do it.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [ one.id ],
    hours_each: '0.5',
    client_site_id: site.id,
  } );
  expect( madeOne.status(), await madeOne.text() ).toBe( 200 );

  const madeTwo = await admin.api.post( '/recurring', {
    title: `Twos chore ${ RUN }`,
    description: '<p>Do it too.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [ two.id ],
    hours_each: '0.5',
    client_site_id: site.id,
  } );
  expect( madeTwo.status(), await madeTwo.text() ).toBe( 200 );

  await admin.api.post( '/recurring/run', {} );

  const asOne = await Forge.signedIn( browser, baseURL, one.login, Forge.PASSWORD );
  const pageOne = await asOne.context.newPage();
  await pageOne.goto( '/blueworx-forge/#screen=mytasks' );
  await expect( pageOne.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( pageOne.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( `Ones chore ${ RUN }` );
  await expect( pageOne.getByTestId( 'bwx-mytasks-diary' ) ).not.toContainText( `Twos chore ${ RUN }` );
  await pageOne.close();
  await asOne.context.close();

  const asTwo = await Forge.signedIn( browser, baseURL, two.login, Forge.PASSWORD );
  const pageTwo = await asTwo.context.newPage();
  await pageTwo.goto( '/blueworx-forge/#screen=mytasks' );
  await expect( pageTwo.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( pageTwo.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( `Twos chore ${ RUN }` );
  await expect( pageTwo.getByTestId( 'bwx-mytasks-diary' ) ).not.toContainText( `Ones chore ${ RUN }` );
  await pageTwo.close();
  await asTwo.context.close();

  await admin.context.close();
} );

test( 'a company date for everyone shows in every person’s diary', async ( { browser, baseURL } ) => {
  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client } = await Forge.makeSite( admin.api, `Diary All Co ${ RUN }`, `${ RUN }a` );
  const today = ( await admin.api.get( '/standup' ) ).today;
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `da${ RUN }` );

  const made = await admin.api.post( '/calendar-dates', { title: `Studio day ${ RUN }`, kind: 'company-day', on_date: today, people: 'all' } );
  expect( made.status(), await made.text() ).toBe( 200 );

  const asPerson = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const page = await asPerson.context.newPage();
  await page.goto( '/blueworx-forge/#screen=mytasks' );
  await expect( page.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( page.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( `Studio day ${ RUN }` );
  await page.close();
  await asPerson.context.close();
  await admin.context.close();
} );
