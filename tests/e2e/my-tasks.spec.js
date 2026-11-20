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
  await seat( site.id, `Tomorrow one ${ RUN }`, { primary_user_id: person.id, planned_due: on( 1 ) } );
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
  const tomorrow = await count( 'Tomorrow' );
  const week = await count( 'Next seven days' );
  const later = await count( 'Further out' );
  const all = await count( 'Everything' );
  expect( all ).toBe( 5 );
  expect( today + tomorrow + week + later, 'the four dated views add up to everything' ).toBe( all );

  // Today is due today or late, and nothing else (#481): the late one, and
  // not tomorrow's or the undated one in review.
  // Today's lists sit in boxes of their own beside the table (#637).
  const area = page.getByTestId( 'bwx-mytasks' );
  await expect( area ).toContainText( `Late one ${ RUN }` );
  await expect( area ).not.toContainText( `Tomorrow one ${ RUN }` );
  await expect( area ).not.toContainText( `Checking ${ RUN }` );
  await table.getByRole( 'button', { name: /^Tomorrow/ } ).click();
  await expect( table.locator( 'tbody tr', { hasText: `Tomorrow one ${ RUN }` } ) ).toHaveCount( 1 );
  await expect( table ).not.toContainText( `Late one ${ RUN }` );
  await table.getByRole( 'button', { name: /^Everything/ } ).click();
  await expect( table.locator( 'tbody tr', { hasText: `Both seats ${ RUN }` } ) ).toHaveCount( 1 );
  await expect( table.locator( 'tbody tr', { hasText: `Checking ${ RUN }` } ) ).toContainText( 'Checker' );
  for ( const gone of [ 'Not yet checking', 'Not yet building', 'Not theirs', 'Gone to review' ] ) {
    await expect( table ).not.toContainText( `${ gone } ${ RUN }` );
  }

  // A row opens the record.
  await table.getByRole( 'button', { name: `Late one ${ RUN }` } ).click();
  await expect( page.getByTestId( 'bwx-panel' ) ).toContainText( `Late one ${ RUN }`, { timeout: 60_000 } );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );

// #507. Urgent work is Today whatever its date, at the top, and only for the
// person whose turn it is.
test( 'an urgent task due next month is at the top of Today, for whoever’s turn it is', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Urgent Co ${ RUN }`, `${ RUN }u` );
  const owner = await Forge.makePerson( admin.api, client.id, 'staff', `uown${ RUN }` );
  const checker = await Forge.makePerson( admin.api, client.id, 'staff', `uchk${ RUN }` );

  const make = async ( title, patch ) => {
    const made = await Forge.makeItem( admin.api, site.id, { title } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const item = ( await made.json() ).item;
    const edited = await admin.api.patch( `/work-items/${ item.id }`, { ...patch, record_version: item.record_version } );
    expect( edited.status(), await edited.text() ).toBe( 200 );
  };
  await make( `Due today ${ RUN }`, { primary_user_id: owner.id, planned_due: on( 0 ) } );
  await make( `Urgent later ${ RUN }`, { primary_user_id: owner.id, reviewer_id: checker.id, priority: 'urgent', planned_due: on( 30 ) } );
  await make( `Urgent undated ${ RUN }`, { primary_user_id: owner.id, priority: 'urgent' } );

  const look = async ( person ) => {
    const me = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
    const page = await me.context.newPage();
    await page.goto( '/blueworx-forge/#screen=mytasks' );
    const table = page.getByTestId( 'bwx-mytasks-table' );
    await expect( table ).toBeVisible( { timeout: 60_000 } );
    return { me, page, table };
  };

  // The owner's turn: both urgent ones in Today, above what is merely due today.
  const owned = await look( owner );
  const rows = owned.page.locator( '[data-testid="bwx-mytasks-table"] tbody tr.fk-row' );
  await expect( rows ).toHaveCount( 3 );
  await expect( rows.nth( 0 ) ).toContainText( 'Urgent' );
  await expect( rows.nth( 1 ) ).toContainText( 'Urgent' );
  await expect( rows.nth( 2 ) ).toContainText( `Due today ${ RUN }` );
  await owned.me.context.close();

  // Not yet the checker's, so not on their list at all.
  const checking = await look( checker );
  await checking.table.getByRole( 'button', { name: /^Everything/ } ).click();
  await expect( checking.table ).not.toContainText( `Urgent later ${ RUN }` );
  await checking.me.context.close();

  await admin.context.close();
} );

/** A standing meeting today at 9am, so a diary is known to have loaded. */
async function standingMeetingToday( admin, siteId, today, host, title, attendees = [] ) {
  await Forge.onSupport( admin, siteId, 100 );
  const made = await admin.api.post( `/client-sites/${ siteId }/meetings/series`, {
    title,
    frequency: 'weekly',
    starts_on: today,
    ends_on: '',
    time_of_day: '09:00',
    duration_mins: 30,
    timezone: 'Europe/London',
    host_user_id: host.id,
    attendee_ids: attendees.map( ( one ) => one.id ),
    attendees: 'The client',
    planned_hours: 0.5,
  } );
  expect( made.status(), await made.text() ).toBe( 200 );
}

// #693. Today's diary shows meetings only: a chore is in the person's own
// list, never their diary, and a chore named for somebody else is in neither.
// A meeting you attend but do not host is in your diary too.
test( 'a chore today is in its person’s list and not in anyone’s diary', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Diary Co ${ RUN }`, `${ RUN }d` );
  const today = ( await admin.api.get( '/standup' ) ).today;
  const one = await Forge.makePerson( admin.api, client.id, 'staff', `d1${ RUN }` );
  const two = await Forge.makePerson( admin.api, client.id, 'staff', `d2${ RUN }` );

  for ( const [ who, title ] of [ [ one, `Ones chore ${ RUN }` ], [ two, `Twos chore ${ RUN }` ] ] ) {
    const made = await admin.api.post( '/recurring', {
      title,
      description: '<p>Do it.</p>',
      rule: { every: 'day' },
      starts_on: today,
      assignees: [ who.id ],
      hours_each: '0.5',
      client_site_id: site.id,
    } );
    expect( made.status(), await made.text() ).toBe( 200 );
  }
  await admin.api.post( '/recurring/run', {} );
  await standingMeetingToday( admin, site.id, today, two, `Their sync ${ RUN }`, [ one ] );

  const asOne = await Forge.signedIn( browser, baseURL, one.login, Forge.PASSWORD );
  const page = await asOne.context.newPage();
  await page.goto( '/blueworx-forge/#screen=mytasks' );
  const table = page.getByTestId( 'bwx-mytasks-table' );
  await expect( table ).toContainText( `Ones chore ${ RUN }`, { timeout: 60_000 } );
  await expect( table ).not.toContainText( `Twos chore ${ RUN }` );
  const diary = page.getByTestId( 'bwx-mytasks-diary' );
  await expect( diary ).toContainText( `Their sync ${ RUN }`, { timeout: 30_000 } );
  await expect( diary ).not.toContainText( `Ones chore ${ RUN }` );
  await page.close();
  await asOne.context.close();

  await admin.context.close();
} );

test( 'a company date is not in Today’s diary', async ( { browser, baseURL } ) => {
  test.slow();
  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Diary All Co ${ RUN }`, `${ RUN }a` );
  const today = ( await admin.api.get( '/standup' ) ).today;
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `da${ RUN }` );
  await standingMeetingToday( admin, site.id, today, person, `Own sync ${ RUN }` );

  const made = await admin.api.post( '/calendar-dates', { title: `Studio day ${ RUN }`, kind: 'company-day', on_date: today, people: 'all' } );
  expect( made.status(), await made.text() ).toBe( 200 );

  const asPerson = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const page = await asPerson.context.newPage();
  await page.goto( '/blueworx-forge/#screen=mytasks' );
  const diary = page.getByTestId( 'bwx-mytasks-diary' );
  await expect( diary ).toContainText( `Own sync ${ RUN }`, { timeout: 60_000 } );
  await expect( diary ).not.toContainText( `Studio day ${ RUN }` );
  await page.close();
  await asPerson.context.close();
  await admin.context.close();
} );

test( 'overdue work is under Today, and released work is under none of the dated views', async ( { browser, baseURL } ) => {
  test.setTimeout( 180_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Late Co ${ RUN }`, `${ RUN }l` );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `late${ RUN }` );

  // Completed and late is the builder's, and late means Today (Luke, 2026-09-26).
  const place = async ( title, stage ) => {
    const made = await Forge.makeItem( admin.api, site.id, { title } );
    const item = ( await made.json() ).item;
    const edited = await admin.api.patch( `/work-items/${ item.id }`, { deliverer_id: person.id, planned_due: on( -5 ), record_version: item.record_version } );
    expect( edited.status(), await edited.text() ).toBe( 200 );
    const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: stage, reason: 'Set up for the test.', record_version: ( await edited.json() ).item.record_version } );
    expect( moved.status(), await moved.text() ).toBe( 200 );
  };
  await place( `Late to ship ${ RUN }`, 'completed' );
  await place( `Shipped ${ RUN }`, 'released' );

  // Two reminders for them today, one already ticked off.
  const today = ( await admin.api.get( '/standup' ) ).today;
  const remind = async ( title ) => {
    const made = await admin.api.post( '/reminders', { client_site_id: site.id, title, assignees: [ person.id ], starts_on: today } );
    expect( made.status(), await made.text() ).toBe( 200 );
    return ( await made.json() ).reminder.copies[ 0 ].item_id;
  };
  await remind( `Still to do ${ RUN }` );
  const doneId = await remind( `Ticked off ${ RUN }` );
  const ticked = await admin.api.post( `/work-items/${ doneId }/tick`, { user_id: person.id, done: true } );
  expect( ticked.status(), await ticked.text() ).toBe( 200 );

  const me = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const page = await me.context.newPage();
  await page.goto( '/blueworx-forge/#screen=mytasks' );
  const table = page.getByTestId( 'bwx-mytasks-table' );
  await expect( table ).toBeVisible( { timeout: 60_000 } );

  // Today's lists sit in boxes of their own beside the table (#637).
  await expect( page.getByTestId( 'bwx-mytasks' ) ).toContainText( `Late to ship ${ RUN }` );
  // Done work is under no view, Everything included (Luke, 2026-09-27).
  for ( const tab of [ /^Today/, /^Tomorrow/, /^Next seven days/, /^Further out/, /^Everything/ ] ) {
    await table.getByRole( 'button', { name: tab } ).click();
    await expect( table ).not.toContainText( `Shipped ${ RUN }` );
    await expect( table ).not.toContainText( `Ticked off ${ RUN }` );
  }

  // A reminder's count sits beside its title, with no tick box: it is ticked
  // off from inside the task.
  const open = table.locator( 'tbody tr', { hasText: `Still to do ${ RUN }` } );
  await expect( open ).toContainText( 'Done 0 of 1' );
  await expect( open.locator( 'input[type="checkbox"]' ) ).toHaveCount( 0 );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );

// #601: a meeting marked held leaves Today's diary, and its time is counted
// as done in capacity rather than still to do.
test( 'a held meeting leaves Today’s diary and counts as done', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Held Co ${ RUN }`, `${ RUN }h` );
  await Forge.onSupport( admin, site.id, 100 );
  const today = ( await admin.api.get( '/standup' ) ).today;
  const host = await Forge.makePerson( admin.api, client.id, 'staff', `host${ RUN }` );
  const title = `Catch-up ${ RUN }`;

  const made = await admin.api.post( `/client-sites/${ site.id }/meetings/series`, {
    title,
    frequency: 'weekly',
    starts_on: today,
    ends_on: '',
    time_of_day: '09:00',
    duration_mins: 30,
    timezone: 'Europe/London',
    host_user_id: host.id,
    attendees: 'The client',
    planned_hours: 0.5,
  } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const series = ( await made.json() ).added;

  const asHost = await Forge.signedIn( browser, baseURL, host.login, Forge.PASSWORD );
  const page = await asHost.context.newPage();
  await page.goto( '/blueworx-forge/#screen=mytasks' );
  await expect( page.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( page.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( title );

  const held = await admin.api.post( `/client-sites/${ site.id }/meetings/${ series.id }/${ today }/settle`, { status: 'held' } );
  expect( held.status(), await held.text() ).toBe( 200 );

  const meetingToday = async () => {
    const answer = await admin.api.get( `/capacity/person/${ host.id }?from=${ today }&to=${ today }` );

    return answer.allocations.filter( ( one ) => one.title === title );
  };

  expect( ( await meetingToday() ).map( ( one ) => one.status ) ).toEqual( [ 'completed' ] );

  const feed = await admin.api.get( `/calendar?from=${ today }&to=${ today }` );
  expect( feed.entries.find( ( entry ) => 'meeting' === entry.kind && entry.title === title )?.done ).toBe( true );

  // A fresh load, so the diary is read again.
  await page.goto( 'about:blank' );
  await page.goto( '/blueworx-forge/#screen=mytasks' );
  // Other specs leave dates for everyone today, so the diary need not be
  // empty; only this meeting has to have gone from it.
  await expect( page.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( page.getByTestId( 'bwx-mytasks-diary' ) ).not.toContainText( title );

  await page.close();
  await asHost.context.close();
  await admin.context.close();
} );
