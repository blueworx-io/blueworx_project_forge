import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';

// #648. Everything somebody is on that is due today or late is in Today on
// My tasks, for whoever's turn it is at its stage: ordinary work, recurring
// tasks (missed days and all), reminders and a reminder turned into a task.
// Work that has been cancelled is nobody's.

const RUN = `due648${ Date.now() }`;
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

const plus = ( day, days ) => new Date( Date.parse( `${ day }T12:00:00Z` ) + days * 86400000 ).toISOString().slice( 0, 10 );

test( 'due today or late is in Today for whoever’s turn it is, at every stage', async ( { browser, baseURL } ) => {
  test.setTimeout( 420_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const { client, site } = await Forge.makeSite( admin.api, `Due Today Co ${ RUN }`, RUN );
  const today = ( await admin.api.get( '/standup' ) ).today;
  const doer = await Forge.makePerson( admin.api, client.id, 'staff', `ddoer${ RUN }` );
  const designer = await Forge.makePerson( admin.api, client.id, 'staff', `ddsgn${ RUN }` );
  const checker = await Forge.makePerson( admin.api, client.id, 'staff', `dchk${ RUN }` );
  const builder = await Forge.makePerson( admin.api, client.id, 'staff', `dbld${ RUN }` );

  const move = async ( id, to ) => {
    const item = ( await admin.api.get( `/work-items/${ id }` ) ).item;
    const moved = await admin.api.post( `/work-items/${ id }/override`, { to, reason: 'Set up for the test.', record_version: item.record_version } );
    expect( moved.status(), await moved.text() ).toBe( 200 );
  };
  const patch = async ( id, values ) => {
    const item = ( await admin.api.get( `/work-items/${ id }` ) ).item;
    const edited = await admin.api.patch( `/work-items/${ id }`, { ...values, record_version: item.record_version } );
    expect( edited.status(), await edited.text() ).toBe( 200 );
  };
  const cancel = async ( id ) => {
    const item = ( await admin.api.get( `/work-items/${ id }` ) ).item;
    const ended = await admin.api.post( `/work-items/${ id }/outcome`, { outcome: 'cancelled', reason: 'Not needed now.', record_version: item.record_version } );
    expect( ended.status(), await ended.text() ).toBe( 200 );
  };
  const task = async ( title, values, stage ) => {
    const made = await Forge.makeItem( admin.api, site.id, { title } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const id = ( await made.json() ).item.id;
    await patch( id, values );
    if ( stage ) await move( id, stage );
    return id;
  };

  // Ordinary work at each stage, each with the person whose turn it is.
  const designing = `Designing ${ RUN }`;
  const doing = `Doing late ${ RUN }`;
  const checking = `Checking ${ RUN }`;
  const building = `Building late ${ RUN }`;
  const cancelled = `Cancelled late ${ RUN }`;
  await task( designing, { primary_user_id: doer.id, designer_id: designer.id, planned_due: today }, 'design-process' );
  await task( doing, { primary_user_id: doer.id, planned_due: plus( today, -2 ) }, 'in-development' );
  await task( checking, { primary_user_id: doer.id, reviewer_id: checker.id, planned_due: today }, 'in-review' );
  await task( building, { primary_user_id: doer.id, deliverer_id: builder.id, planned_due: plus( today, -1 ) }, 'completed' );
  await cancel( await task( cancelled, { primary_user_id: doer.id, planned_due: plus( today, -1 ) }, 'in-development' ) );

  // A recurring task that nobody opened for three days: four copies, three late.
  const chore = `Daily check ${ RUN }`;
  const source = await admin.api.post( '/recurring', {
    title: chore,
    description: '<p>Check it.</p>',
    rule: { every: 'day' },
    starts_on: plus( today, -3 ),
    assignees: [ doer.id ],
    reviewer_id: checker.id,
    deliverer_id: builder.id,
    hours_each: '0.5',
    client_site_id: site.id,
  } );
  expect( source.status(), await source.text() ).toBe( 200 );
  await admin.api.post( '/recurring/run', {} );
  const copies = ( await admin.api.get( `/work-items?client_site_id=${ site.id }` ) ).items
    .filter( ( item ) => item.title.startsWith( chore ) )
    .sort( ( a, b ) => a.planned_due.localeCompare( b.planned_due ) );
  expect( copies.map( ( item ) => item.planned_due ) ).toEqual( [ -3, -2, -1, 0 ].map( ( days ) => plus( today, days ) ) );

  // A reminder that started yesterday, and one turned into a task.
  const reminder = `Remember ${ RUN }`;
  const remembered = await admin.api.post( '/reminders', { client_site_id: site.id, title: reminder, assignees: [ doer.id ], starts_on: plus( today, -1 ), ends_on: today } );
  expect( remembered.status(), await remembered.text() ).toBe( 200 );
  const reminderCopy = ( await remembered.json() ).reminder.copies[ 0 ].item_id;
  const turned = `Turned into a task ${ RUN }`;
  const toTurn = await admin.api.post( '/reminders', { client_site_id: site.id, title: turned, assignees: [ doer.id ], starts_on: today } );
  expect( toTurn.status(), await toTurn.text() ).toBe( 200 );
  const converted = await admin.api.post( `/reminders/${ ( await toTurn.json() ).reminder.id }/convert`, {} );
  expect( converted.status(), await converted.text() ).toBe( 200 );
  const turnedTask = ( await converted.json() ).item.id;

  // Each person signs in once, and opens My tasks afresh for each look.
  // Only the login is needed here, not a nonce, so it skips the extra app load.
  const sessions = new Map();
  const today_ = async ( person ) => {
    const fresh = ! sessions.has( person.id );
    if ( fresh ) sessions.set( person.id, await browser.newContext( { baseURL } ) );
    const page = await sessions.get( person.id ).newPage();
    if ( fresh ) await signIn( page, person.login, Forge.PASSWORD );
    await page.goto( '/blueworx-forge/' );
    await page.getByTestId( 'bwx-screen-mytasks' ).click();
    await expect( page.getByTestId( 'bwx-mytasks-table' ) ).toBeVisible( { timeout: 60_000 } );
    const area = page.getByTestId( 'bwx-mytasks' );
    // Today is the view it opens on: wait for the list to arrive.
    await expect( area ).toContainText( RUN, { timeout: 60_000 } );
    const text = await area.innerText();
    await page.close();
    return text;
  };

  // Each person's Today, before anything moves on.
  let seen = await today_( doer );
  for ( const title of [ doing, reminder, turned, ...copies.map( ( item ) => item.title ) ] ) {
    expect( seen, `${ title } is in the doer's Today` ).toContain( title );
  }
  for ( const title of [ designing, checking, building, cancelled ] ) {
    expect( seen, `${ title } is not the doer's` ).not.toContain( title );
  }
  expect( await today_( designer ) ).toContain( designing );
  expect( await today_( checker ) ).toContain( checking );
  expect( await today_( builder ) ).toContain( building );

  // The recurring copies, the reminder and the task it became move on to
  // review and delivery: they go to the checker and the builder, as any
  // other work does, and one cancelled copy goes to nobody.
  await move( copies[ 0 ].id, 'in-review' );
  await move( copies[ 1 ].id, 'completed' );
  await cancel( copies[ 2 ].id );
  await patch( reminderCopy, { reviewer_id: checker.id } );
  await move( reminderCopy, 'in-review' );
  await patch( turnedTask, { deliverer_id: builder.id } );
  await move( turnedTask, 'completed' );

  seen = await today_( doer );
  expect( seen ).toContain( copies[ 3 ].title );
  for ( const title of [ copies[ 0 ].title, copies[ 1 ].title, copies[ 2 ].title, reminder, turned ] ) {
    expect( seen, `${ title } has left the doer's Today` ).not.toContain( title );
  }

  seen = await today_( checker );
  for ( const title of [ checking, copies[ 0 ].title, reminder ] ) {
    expect( seen, `${ title } is in the checker's Today` ).toContain( title );
  }
  expect( seen ).not.toContain( copies[ 2 ].title );

  seen = await today_( builder );
  for ( const title of [ building, copies[ 1 ].title, turned ] ) {
    expect( seen, `${ title } is in the builder's Today` ).toContain( title );
  }
  expect( seen ).not.toContain( copies[ 2 ].title );

  for ( const context of sessions.values() ) await context.close();
  await admin.context.close();
} );
