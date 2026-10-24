import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #620. Today on My tasks is a planned day, 7am to 7pm: meetings at their
// times, tasks filling the open time by priority, what does not fit and what
// has no hours beneath, and an order the person can set that holds for the day.
// The clock is fixed at 8am so the plan does not depend on when the suite runs.

const RUN = `plan${ Date.now() }`;
const pad = ( n ) => String( n ).padStart( 2, '0' );
const localDate = ( offset = 0 ) => {
  const at = new Date();
  at.setDate( at.getDate() + offset );
  return `${ at.getFullYear() }-${ pad( at.getMonth() + 1 ) }-${ pad( at.getDate() ) }`;
};
const eightAm = ( offset = 0 ) => {
  const at = new Date();
  at.setDate( at.getDate() + offset );
  at.setHours( 8, 0, 0, 0 );
  return at;
};

async function makeTask( admin, siteId, title, patch ) {
  const made = await Forge.makeItem( admin.api, siteId, { title } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ item.id }`, { ...patch, record_version: item.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );
  return item;
}

async function setUp( browser, baseURL, label ) {
  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Plan Co ${ label } ${ RUN }`, `${ RUN }${ label }` );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `plan${ label }${ RUN }` );
  return { admin, client, site, person };
}

async function openToday( browser, baseURL, person, offset = 0 ) {
  const me = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const page = await me.context.newPage();
  await page.clock.setFixedTime( eightAm( offset ) );
  await page.goto( '/blueworx-forge/#screen=mytasks' );
  await expect( page.locator( '[data-testid="bwx-day"][data-ready="true"]' ) ).toBeVisible( { timeout: 90_000 } );
  return { me, page };
}

const titles = ( page, where ) => page.locator( `[data-testid="bwx-day-block"][data-where="${ where }"] [data-testid="bwx-mytasks-open"]` ).allInnerTexts();

test( 'Today is a planned day: meetings at their times, tasks by priority, the rest beneath, clashes counted', async ( { browser, baseURL } ) => {
  test.slow();
  test.setTimeout( 400_000 );

  const { admin, client, site, person } = await setUp( browser, baseURL, 'a' );
  const today = localDate();
  await makeTask( admin, site.id, `Urgent job ${ RUN }`, { primary_user_id: person.id, priority: 'urgent', hours_primary: 2, planned_due: today } );
  await makeTask( admin, site.id, `Normal job ${ RUN }`, { primary_user_id: person.id, priority: 'normal', hours_primary: 1, planned_due: today } );
  await makeTask( admin, site.id, `Low job ${ RUN }`, { primary_user_id: person.id, priority: 'low', hours_primary: 1, planned_due: today } );
  await makeTask( admin, site.id, `Huge job ${ RUN }`, { primary_user_id: person.id, priority: 'high', hours_primary: 11, planned_due: today } );
  await makeTask( admin, site.id, `Unsized job ${ RUN }`, { primary_user_id: person.id, priority: 'high', planned_due: today } );

  // A meeting from 10 to 11 in the viewer's own zone, which the person hosts,
  // and another that clashes with it, which they attend.
  const probe = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const probePage = await probe.context.newPage();
  await probePage.goto( '/blueworx-forge/' );
  const zone = await probePage.evaluate( () => Intl.DateTimeFormat().resolvedOptions().timeZone );
  await probe.context.close();

  const series = ( title, at, extra = {} ) => ( {
    title,
    frequency: 'weekly',
    starts_on: today,
    ends_on: '',
    time_of_day: at,
    duration_mins: 60,
    timezone: zone,
    host_user_id: person.id,
    attendees: '',
    planned_hours: 0,
    ...extra,
  } );
  const first = await admin.api.post( `/client-sites/${ site.id }/meetings/series`, series( `Client call ${ RUN }`, '10:00' ) );
  expect( first.status(), await first.text() ).toBe( 200 );
  const host = await Forge.makePerson( admin.api, client.id, 'staff', `host${ RUN }` );
  const second = await admin.api.post( `/client-sites/${ site.id }/meetings/series`, series( `Overlap chat ${ RUN }`, '10:30', { host_user_id: host.id, attendee_ids: [ person.id ] } ) );
  expect( second.status(), await second.text() ).toBe( 200 );

  const { me, page } = await openToday( browser, baseURL, person );
  const day = page.getByTestId( 'bwx-day' );

  // Tasks fill from 8am by priority: the urgent job, then normal, then low.
  await expect( day.locator( '[data-where="day"]' ).first() ).toBeVisible();
  expect( await titles( page, 'day' ) ).toEqual( [ `Urgent job ${ RUN }`, `Normal job ${ RUN }`, `Low job ${ RUN }` ] );

  // Each block carries the row's details.
  const urgent = day.locator( '[data-task]', { hasText: `Urgent job ${ RUN }` } );
  await expect( urgent ).toContainText( '2.0h' );
  await expect( urgent ).toContainText( 'urgent' );
  await expect( urgent ).toContainText( 'Owner' );
  await expect( urgent ).toContainText( 'Due today' );

  // Both meetings are there, and they overlap: red, and counted with the huge
  // job that is due today and has nowhere to go.
  await expect( day.getByTestId( 'bwx-day-meeting' ) ).toHaveCount( 2 );
  await expect( day.locator( '[data-testid="bwx-day-meeting"][data-clash="true"]' ) ).toHaveCount( 2 );
  expect( await titles( page, 'overflow' ) ).toEqual( [ `Huge job ${ RUN }` ] );
  await expect( page.locator( '[data-where="overflow"]' ) ).toHaveAttribute( 'data-clash', 'true' );
  await expect( day.getByTestId( 'bwx-day-clashes' ) ).toContainText( '3 clashes' );

  // No hours: listed, never placed.
  expect( await titles( page, 'unset' ) ).toEqual( [ `Unsized job ${ RUN }` ] );
  await expect( day.getByTestId( 'bwx-day-grid' ) ).not.toContainText( `Unsized job ${ RUN }` );

  // 8am to 7pm is eleven hours; four hours of work, two of meetings that
  // overlap by half an hour, leaves open time shown as its own block.
  await expect( day.getByTestId( 'bwx-day-planned' ) ).toContainText( '4h' );
  await expect( day.getByTestId( 'bwx-day-gap' ).first() ).toContainText( 'Open ·' );

  // A task opens as the row does.
  await urgent.getByRole( 'button', { name: `Urgent job ${ RUN }` } ).click();
  await expect( page.getByTestId( 'bwx-panel' ) ).toContainText( `Urgent job ${ RUN }` );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );

test( 'the order a person sets holds after a reload, and tomorrow starts fresh', async ( { browser, baseURL } ) => {
  test.slow();
  test.setTimeout( 400_000 );

  const { admin, site, person } = await setUp( browser, baseURL, 'b' );
  const today = localDate();
  await makeTask( admin, site.id, `First by priority ${ RUN }`, { primary_user_id: person.id, priority: 'urgent', hours_primary: 1, planned_due: today } );
  await makeTask( admin, site.id, `Second ${ RUN }`, { primary_user_id: person.id, priority: 'normal', hours_primary: 1, planned_due: today } );
  await makeTask( admin, site.id, `Third ${ RUN }`, { primary_user_id: person.id, priority: 'low', hours_primary: 1, planned_due: today } );
  await makeTask( admin, site.id, `No hours yet ${ RUN }`, { primary_user_id: person.id, priority: 'normal', planned_due: today } );

  const { me, page } = await openToday( browser, baseURL, person );
  const day = page.getByTestId( 'bwx-day' );
  const block = ( name ) => page.locator( '[data-task]', { hasText: `${ name } ${ RUN }` } );
  const order = [ `First by priority ${ RUN }`, `Second ${ RUN }`, `Third ${ RUN }` ];

  expect( await titles( page, 'day' ) ).toEqual( order );
  // Nothing moves outside Edit plan.
  await expect( day.getByTestId( 'bwx-day-up' ) ).toHaveCount( 0 );

  await day.getByTestId( 'bwx-day-edit' ).click();
  // Keyboard reachable: real buttons with names.
  await block( 'Third' ).getByRole( 'button', { name: `Move Third ${ RUN } up` } ).click();
  await expect.poll( () => titles( page, 'day' ) ).toEqual( [ order[ 0 ], order[ 2 ], order[ 1 ] ] );
  await block( 'Third' ).getByRole( 'button', { name: `Move Third ${ RUN } up` } ).click();
  await expect.poll( () => titles( page, 'day' ) ).toEqual( [ order[ 2 ], order[ 0 ], order[ 1 ] ] );

  // Send the top one down, then bring it back up.
  await block( 'Third' ).getByRole( 'button', { name: /^Send Third .* down/ } ).click();
  await expect.poll( () => titles( page, 'overflow' ) ).toEqual( [ order[ 2 ] ] );

  await page.reload();
  await page.getByTestId( 'bwx-screen-mytasks' ).click();
  await expect( day.and( page.locator( '[data-ready="true"]' ) ) ).toBeVisible( { timeout: 90_000 } );
  expect( await titles( page, 'day' ), 'the order holds after a reload' ).toEqual( [ order[ 0 ], order[ 1 ] ] );
  expect( await titles( page, 'overflow' ) ).toEqual( [ order[ 2 ] ] );

  await day.getByTestId( 'bwx-day-edit' ).click();
  await block( 'Third' ).getByRole( 'button', { name: /^Bring Third .* up/ } ).click();
  await expect.poll( () => titles( page, 'day' ) ).toEqual( [ order[ 2 ], order[ 0 ], order[ 1 ] ] );

  // A task with no hours is brought up by setting its hours: it opens the task.
  await block( 'No hours yet' ).getByRole( 'button', { name: /^Set hours for/ } ).click();
  await expect( page.getByTestId( 'bwx-panel' ) ).toContainText( `No hours yet ${ RUN }`, { timeout: 60_000 } );

  // The planner never touched the tasks themselves.
  const items = ( await admin.api.get( '/work-items-all' ) ).items.filter( ( one ) => one.title.endsWith( RUN ) && one.primary_user_id === person.id );
  expect( items.find( ( one ) => one.title.startsWith( 'Third' ) ).priority ).toBe( 'low' );

  // Tomorrow starts from scratch: the same tasks are not due then, so look at
  // the saved order itself, which belongs to one day only.
  const tomorrow = await me.api.get( `/day-plan?day=${ localDate( 1 ) }` );
  expect( tomorrow.order ).toEqual( [] );
  const todays = await me.api.get( `/day-plan?day=${ today }` );
  expect( todays.order.length ).toBeGreaterThan( 0 );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );

test( 'a day away has no open time: a banner, and everything with hours does not fit', async ( { browser, baseURL } ) => {
  test.slow();
  test.setTimeout( 300_000 );

  const { admin, site, person } = await setUp( browser, baseURL, 'c' );
  const today = localDate();
  await makeTask( admin, site.id, `While away ${ RUN }`, { primary_user_id: person.id, priority: 'normal', hours_primary: 1, planned_due: today } );
  const away = await admin.api.post( `/users/${ person.id }/leave`, { starts_on: today, ends_on: today, kind: 'leave', note: `Away ${ RUN }` } );
  expect( away.status(), await away.text() ).toBe( 200 );

  const { me, page } = await openToday( browser, baseURL, person );
  const day = page.getByTestId( 'bwx-day' );

  await expect( day.getByTestId( 'bwx-day-away' ) ).toBeVisible();
  await expect( day.getByTestId( 'bwx-day-open' ) ).toContainText( '0m' );
  expect( await titles( page, 'overflow' ) ).toEqual( [ `While away ${ RUN }` ] );
  await expect( day.getByTestId( 'bwx-day-gap' ) ).toHaveCount( 0 );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );

test( 'below the whole day, in boxes of their own: needs attention, then doesn’t fit, then no hours set, chores and reminders among them; the diary is on Today (#635, #637)', async ( { browser, baseURL } ) => {
  test.slow();
  test.setTimeout( 400_000 );

  const { admin, site, person } = await setUp( browser, baseURL, 'd' );
  const today = localDate();
  const serverToday = ( await admin.api.get( '/standup' ) ).today;
  await makeTask( admin, site.id, `Urgent unsized ${ RUN }`, { primary_user_id: person.id, priority: 'urgent', planned_due: today } );
  await makeTask( admin, site.id, `Overdue and big ${ RUN }`, { primary_user_id: person.id, priority: 'high', hours_primary: 8, planned_due: localDate( -2 ) } );
  await makeTask( admin, site.id, `Fits today ${ RUN }`, { primary_user_id: person.id, priority: 'normal', hours_primary: 1, planned_due: today } );
  await makeTask( admin, site.id, `Too long today ${ RUN }`, { primary_user_id: person.id, priority: 'normal', hours_primary: 6, planned_due: today } );
  await makeTask( admin, site.id, `Normal unsized ${ RUN }`, { primary_user_id: person.id, priority: 'normal', planned_due: today } );

  // A chore and a reminder of the person's: real tasks, in the day and the lists.
  const chore = await admin.api.post( '/recurring', {
    title: `Plan chore ${ RUN }`,
    description: '<p>Do it.</p>',
    rule: { every: 'day' },
    starts_on: serverToday,
    assignees: [ person.id ],
    hours_each: '0.5',
    client_site_id: site.id,
  } );
  expect( chore.status(), await chore.text() ).toBe( 200 );
  await admin.api.post( '/recurring/run', {} );
  const reminder = await admin.api.post( '/reminders', { client_site_id: site.id, title: `Plan reminder ${ RUN }`, assignees: [ person.id ], starts_on: serverToday, ends_on: '' } );
  expect( reminder.status(), await reminder.text() ).toBe( 200 );

  // A date in today's diary.
  const dated = await admin.api.post( '/calendar-dates', { title: `Diary date ${ RUN }`, kind: 'company-day', on_date: serverToday, people: 'all' } );
  expect( dated.status(), await dated.text() ).toBe( 200 );

  // Opened at 2pm: five hours left, so the eight- and six-hour jobs cannot fit.
  const me = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const page = await me.context.newPage();
  const twoPm = new Date();
  twoPm.setHours( 14, 0, 0, 0 );
  await page.clock.setFixedTime( twoPm );
  await page.goto( '/blueworx-forge/#screen=mytasks' );
  const day = page.getByTestId( 'bwx-day' );
  await expect( page.locator( '[data-testid="bwx-day"][data-ready="true"]' ) ).toBeVisible( { timeout: 90_000 } );

  // The whole day is shown, from 7am, with nothing folded away.
  const grid = day.getByTestId( 'bwx-day-grid' );
  await expect( grid.locator( '[data-hour="7"]' ) ).toBeVisible();
  await expect( page.getByTestId( 'bwx-day-earlier' ) ).toHaveCount( 0 );

  const placed = await titles( page, 'day' );
  expect( placed ).toContain( `Fits today ${ RUN }` );
  expect( placed.some( ( title ) => title.startsWith( `Plan chore ${ RUN }` ) ), 'the chore is planned into the day' ).toBe( true );

  // Three boxes of their own beneath the day, in this order, none inside the day's.
  const attention = page.getByTestId( 'bwx-day-attention' );
  const overflow = page.getByTestId( 'bwx-day-overflow' );
  const unset = page.getByTestId( 'bwx-day-unset' );
  for ( const box of [ attention, overflow, unset ] ) await expect( box ).toBeVisible();
  await expect( page.getByTestId( 'bwx-mytasks-table' ).locator( '[data-testid="bwx-day-attention"], [data-testid="bwx-day-overflow"], [data-testid="bwx-day-unset"]' ) ).toHaveCount( 0 );
  const top = async ( locator ) => ( await locator.boundingBox() ).y;
  expect( await top( grid ) ).toBeLessThan( await top( attention ) );
  expect( await top( attention ) ).toBeLessThan( await top( overflow ) );
  expect( await top( overflow ) ).toBeLessThan( await top( unset ) );

  // What each holds, with nothing repeated.
  expect( await titles( page, 'attention' ) ).toEqual( [ `Urgent unsized ${ RUN }`, `Overdue and big ${ RUN }` ] );
  expect( await titles( page, 'overflow' ) ).toEqual( [ `Too long today ${ RUN }` ] );
  const noHours = await titles( page, 'unset' );
  expect( noHours ).toContain( `Normal unsized ${ RUN }` );
  expect( noHours.some( ( title ) => title.startsWith( `Plan reminder ${ RUN }` ) ), 'the reminder is listed' ).toBe( true );

  // Today's diary is on Today.
  await expect( page.getByTestId( 'bwx-mytasks-diary' ) ).toContainText( `Diary date ${ RUN }` );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );

test( 'the day is planned once: a missed task keeps its slot as time passes, until the plan is edited (#635)', async ( { browser, baseURL } ) => {
  test.slow();
  test.setTimeout( 400_000 );

  const { admin, site, person } = await setUp( browser, baseURL, 'e' );
  const today = localDate();
  await makeTask( admin, site.id, `Morning first ${ RUN }`, { primary_user_id: person.id, priority: 'high', hours_primary: 1, planned_due: today } );
  await makeTask( admin, site.id, `Morning second ${ RUN }`, { primary_user_id: person.id, priority: 'normal', hours_primary: 1, planned_due: today } );

  // Planned at 8am: the first at 8, the second at 9.
  const { me, page } = await openToday( browser, baseURL, person );
  const grid = page.getByTestId( 'bwx-day-grid' );
  const inHour = ( hour, name ) => grid.locator( `[data-hour="${ hour }"] [data-task]`, { hasText: `${ name } ${ RUN }` } );
  await expect( inHour( 8, 'Morning first' ) ).toBeVisible();
  await expect( inHour( 9, 'Morning second' ) ).toBeVisible();
  await expect.poll( async () => ( await me.api.get( `/day-plan?day=${ today }` ) ).start, { timeout: 30_000 } ).toBe( 480 );

  // At 11am both have been missed, and both are still where they were.
  const eleven = eightAm();
  eleven.setHours( 11 );
  await page.clock.setFixedTime( eleven );
  await page.reload();
  await page.getByTestId( 'bwx-screen-mytasks' ).click();
  await expect( page.locator( '[data-testid="bwx-day"][data-ready="true"]' ) ).toBeVisible( { timeout: 90_000 } );
  await expect( inHour( 8, 'Morning first' ) ).toBeVisible();
  await expect( inHour( 9, 'Morning second' ) ).toBeVisible();

  // Editing plans the day again, from now.
  await page.getByTestId( 'bwx-day-edit' ).click();
  await page.getByRole( 'button', { name: `Move Morning second ${ RUN } up` } ).click();
  await expect( inHour( 11, 'Morning second' ) ).toBeVisible();
  await expect( inHour( 12, 'Morning first' ) ).toBeVisible();

  await page.close();
  await me.context.close();
  await admin.context.close();
} );
