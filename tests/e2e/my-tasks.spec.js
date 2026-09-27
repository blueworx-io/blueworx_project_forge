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

// #412: a chore leaves Today's diary once everyone on it has ticked; partly
// done, it stays and says how many.
test( 'a finished chore leaves Today’s diary and stays gone, and a partly-done one stays with its count', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Done Co ${ RUN }`, `${ RUN }f` );
  const today = ( await admin.api.get( '/standup' ) ).today;
  const solo = await Forge.makePerson( admin.api, client.id, 'staff', `solo${ RUN }` );
  const one = await Forge.makePerson( admin.api, client.id, 'staff', `pone${ RUN }` );
  const two = await Forge.makePerson( admin.api, client.id, 'staff', `ptwo${ RUN }` );

  const soloTitle = `Solo chore ${ RUN }`;
  const pairTitle = `Pair chore ${ RUN }`;

  const madeSolo = await admin.api.post( '/recurring', {
    title: soloTitle,
    description: '<p>Only them.</p>',
    rule: { every: 'day' },
    starts_on: today,
    assignees: [ solo.id ],
    hours_each: '0.5',
    client_site_id: site.id,
  } );
  expect( madeSolo.status(), await madeSolo.text() ).toBe( 200 );
  await admin.api.post( '/recurring/run', {} );

  // A chore's own copy carries the day in its title (Materialise's "—
  // 14 Sep"), so match on the reminder's title, which does not.
  const soloItem = ( await admin.api.get( `/work-items?client_site_id=${ site.id }` ) ).items.find( ( item ) => item.title.startsWith( soloTitle ) );
  expect( soloItem, 'solo chore materialised' ).toBeTruthy();

  // Solo's chore, ticked once by the only person on it, leaves their diary
  // for good.
  const asSolo = await Forge.signedIn( browser, baseURL, solo.login, Forge.PASSWORD );
  const soloPage = await asSolo.context.newPage();
  await soloPage.goto( '/blueworx-forge/#screen=mytasks' );
  await expect( soloPage.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( soloPage.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( soloTitle );

  const ticked = await admin.api.post( `/work-items/${ soloItem.id }/tick`, { user_id: solo.id, done: true } );
  expect( ticked.status(), await ticked.text() ).toBe( 200 );

  await soloPage.goto( '/blueworx-forge/#screen=mytasks' );
  await soloPage.reload();
  await expect( soloPage.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( soloPage.getByTestId( 'bwx-mytasks-diary' ) ).not.toContainText( soloTitle );

  // Stays gone after a further reload (the hash is cleared once read, so
  // land on My tasks by it again rather than a bare reload).
  await soloPage.goto( '/blueworx-forge/#screen=mytasks' );
  await soloPage.reload();
  await expect( soloPage.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( soloPage.getByTestId( 'bwx-mytasks-diary' ) ).not.toContainText( soloTitle );
  await soloPage.close();
  await asSolo.context.close();

  // A reminder is a chore too (#412), and its copies share one diary entry
  // with a count, the same as the calendar already shows. Ticked by one of
  // two, it stays with "1 of 2"; ticked by the second, it leaves.
  const madePair = await admin.api.post( '/reminders', {
    client_site_id: site.id,
    title: pairTitle,
    assignees: [ one.id, two.id ],
    starts_on: today,
  } );
  expect( madePair.status(), await madePair.text() ).toBe( 200 );
  const pair = ( await madePair.json() ).reminder;
  const oneCopy = pair.copies.find( ( copy ) => copy.person === one.id );
  const twoCopy = pair.copies.find( ( copy ) => copy.person === two.id );

  const asOne = await Forge.signedIn( browser, baseURL, one.login, Forge.PASSWORD );
  const onePage = await asOne.context.newPage();
  await onePage.goto( '/blueworx-forge/#screen=mytasks' );
  await expect( onePage.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( onePage.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( pairTitle );

  const oneTicked = await admin.api.post( `/work-items/${ oneCopy.item_id }/tick`, { user_id: one.id, done: true } );
  expect( oneTicked.status(), await oneTicked.text() ).toBe( 200 );

  await onePage.goto( '/blueworx-forge/#screen=mytasks' );
  await onePage.reload();
  await expect( onePage.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( pairTitle );
  await expect( onePage.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( '1 of 2 done' );

  const twoTicked = await admin.api.post( `/work-items/${ twoCopy.item_id }/tick`, { user_id: two.id, done: true } );
  expect( twoTicked.status(), await twoTicked.text() ).toBe( 200 );

  await onePage.goto( '/blueworx-forge/#screen=mytasks' );
  await onePage.reload();
  await expect( onePage.getByTestId( 'bwx-mytasks-diary' ) ).toBeVisible( { timeout: 30_000 } );
  await expect( onePage.getByTestId( 'bwx-mytasks-diary' ) ).not.toContainText( pairTitle );
  await onePage.close();
  await asOne.context.close();

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

  await expect( table ).toContainText( `Late to ship ${ RUN }` );
  // Done work is under no view, Everything included (Luke, 2026-09-27).
  for ( const tab of [ /^Today/, /^Next seven days/, /^Further out/, /^Everything/ ] ) {
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
