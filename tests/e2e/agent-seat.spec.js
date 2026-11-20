import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';

// #689. The Agent is on the staff list with its own hours. It can hold any
// seat but deliverer, its hours land on its own row, and while it is the
// Agent's turn the task belongs to the deliverer.

const RUN = `agent${ Date.now() }`;
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

const plus = ( day, days ) => new Date( Date.parse( `${ day }T12:00:00Z` ) + days * 86400000 ).toISOString().slice( 0, 10 );

/** The Monday on or after a date. */
const nextMonday = ( day ) => {
  const weekday = new Date( `${ day }T12:00:00Z` ).getUTCDay();
  return plus( day, ( 8 - weekday ) % 7 );
};

async function theAgent( api ) {
  const people = ( await api.get( '/users?status=all' ) ).users;
  const agents = people.filter( ( person ) => true === person.is_agent );
  expect( agents, 'exactly one agent' ).toHaveLength( 1 );
  return agents[ 0 ];
}

test( 'the Agent is a member of staff, working 12 hours a weekday', async ( { browser, baseURL } ) => {
  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const agent = await theAgent( admin.api );

  expect( agent.display_name ).toBe( 'Agent' );

  const today = ( await admin.api.get( '/standup' ) ).today;
  const monday = nextMonday( plus( today, 1 ) );
  const week = await admin.api.get( `/capacity/person/${ agent.id }?from=${ monday }&to=${ plus( monday, 6 ) }` );

  expect( week.days.map( ( day ) => day.hours ) ).toEqual( [ 12, 12, 12, 12, 12, 0, 0 ] );
} );

test( 'a task with the Agent on it needs a person to deliver it', async ( { browser, baseURL } ) => {
  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const agent = await theAgent( admin.api );
  const { client, site } = await Forge.makeSite( admin.api, `Agent Rules Co ${ RUN }`, RUN );
  const builder = await Forge.makePerson( admin.api, client.id, 'staff', `abld${ RUN }` );

  const made = await Forge.makeItem( admin.api, site.id, { title: `Agent rules ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;

  const noDeliverer = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: agent.id, deliverer_id: '', record_version: item.record_version } );
  expect( noDeliverer.status() ).toBe( 400 );
  expect( ( await noDeliverer.json() ).data.fields.deliverer_id ).toBe( 'With the agent on this task, pick a person to deliver it. They look after it while the agent has it.' );

  const agentDelivers = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: builder.id, deliverer_id: agent.id, record_version: item.record_version } );
  expect( agentDelivers.status() ).toBe( 400 );
  expect( ( await agentDelivers.json() ).data.fields.deliverer_id ).toBe( 'The agent can\'t deliver work. Pick a person to deliver it.' );

  const standIn = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: builder.id, deliverer_id: builder.id, deliverer_substitute_id: agent.id, record_version: item.record_version } );
  expect( standIn.status() ).toBe( 400 );
  expect( ( await standIn.json() ).data.fields.deliverer_substitute_id ).toBe( 'The agent can\'t stand in for anyone. Pick a person.' );

  const fine = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: agent.id, deliverer_id: builder.id, record_version: item.record_version } );
  expect( fine.status(), await fine.text() ).toBe( 200 );
} );

test( 'the Agent never gets a login, and keeps its address', async ( { browser, baseURL } ) => {
  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const agent = await theAgent( admin.api );

  const login = await admin.api.post( `/users/${ agent.id }/account?record_version=${ agent.record_version }`, {} );
  expect( login.status() ).toBe( 400 );
  expect( ( await login.json() ).message ).toBe( 'The agent can\'t have a login.' );

  const address = await admin.api.patch( `/users/${ agent.id }?record_version=${ agent.record_version }`, { email: `agent${ RUN }@example.test` } );
  expect( address.status() ).toBe( 400 );
  expect( ( await address.json() ).message ).toBe( 'The agent\'s address can\'t be changed.' );

  // Its name is still the studio's to change.
  const renamed = await admin.api.patch( `/users/${ agent.id }?record_version=${ agent.record_version }`, { display_name: 'Agent' } );
  expect( renamed.status(), await renamed.text() ).toBe( 200 );
} );

test( 'when the Agent reviews, a staff deliverer approves for it, but not their own work', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const agent = await theAgent( admin.api );
  const { client, site } = await Forge.makeSite( admin.api, `Agent Review Co ${ RUN }`, RUN );
  const doer = await Forge.makePerson( admin.api, client.id, 'staff', `ardoer${ RUN }` );
  const builder = await Forge.makePerson( admin.api, client.id, 'staff', `arbld${ RUN }` );

  const made = await Forge.makeItem( admin.api, site.id, { title: `Reviewed by the agent ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  let item = ( await made.json() ).item;

  // The deliverer would be approving their own build: refused, as any self-review is.
  const ownWork = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: builder.id, reviewer_id: agent.id, deliverer_id: builder.id, record_version: item.record_version } );
  expect( ownWork.status() ).toBe( 400 );
  expect( ( await ownWork.json() ).data.fields.reviewer_id ).toBeTruthy();

  const seated = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: doer.id, reviewer_id: agent.id, deliverer_id: builder.id, record_version: item.record_version } );
  expect( seated.status(), await seated.text() ).toBe( 200 );
  item = ( await admin.api.get( `/work-items/${ item.id }` ) ).item;
  const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'in-review', reason: 'Set up for the test.', record_version: item.record_version } );
  expect( moved.status(), await moved.text() ).toBe( 200 );

  const asBuilder = await Forge.signedIn( browser, baseURL, builder.login, Forge.PASSWORD );
  const approved = await asBuilder.api.post( `/work-items/${ item.id }/gate`, { requirement: 'G-IN-REVIEW-4', value: 'Approved.' } );
  expect( approved.status(), await approved.text() ).toBe( 200 );
  await asBuilder.context.close();
} );

test( 'the Agent’s hours are its own, and the deliverer looks after its task', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const agent = await theAgent( admin.api );
  const { client, site } = await Forge.makeSite( admin.api, `Agent Work Co ${ RUN }`, RUN );
  const builder = await Forge.makePerson( admin.api, client.id, 'staff', `adlv${ RUN }` );
  await Forge.setHours( admin.api, builder.id, 8 );

  const today = ( await admin.api.get( '/standup' ) ).today;
  const title = `Built by the agent ${ RUN }`;
  const made = await Forge.makeItem( admin.api, site.id, { title } );
  expect( made.status(), await made.text() ).toBe( 200 );
  let item = ( await made.json() ).item;

  const edited = await admin.api.patch( `/work-items/${ item.id }`, {
    primary_user_id: agent.id,
    hours_primary: 3,
    deliverer_id: builder.id,
    planned_due: today,
    record_version: item.record_version,
  } );
  expect( edited.status(), await edited.text() ).toBe( 200 );

  item = ( await admin.api.get( `/work-items/${ item.id }` ) ).item;
  const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'in-development', reason: 'Set up for the test.', record_version: item.record_version } );
  expect( moved.status(), await moved.text() ).toBe( 200 );

  const window = `from=${ plus( today, -7 ) }&to=${ plus( today, 7 ) }`;
  const roles = async ( person ) => ( await admin.api.get( `/capacity/person/${ person }?${ window }` ) ).allocations
    .filter( ( allocation ) => allocation.item_id === item.id )
    .map( ( allocation ) => allocation.role );

  expect( await roles( agent.id ), 'the build hours are the agent’s' ).toContain( 'primary' );
  expect( await roles( builder.id ), 'and never the deliverer’s' ).not.toContain( 'primary' );

  // #693. While the agent builds it, it is on the Agents screen, never on
  // the deliverer's My tasks.
  const context = await browser.newContext( { baseURL } );
  const page = await context.newPage();
  await signIn( page, builder.login, Forge.PASSWORD );
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-mytasks' ).click();
  const mine = page.getByTestId( 'bwx-mytasks-table' );
  await expect( mine ).toBeVisible( { timeout: 60_000 } );
  await mine.getByRole( 'button', { name: /^Everything/ } ).click();
  await expect( mine ).not.toContainText( title );

  // Tagged Agent, with who looks after it, and none of its hours.
  const onAgents = async ( where ) => {
    await where.getByTestId( 'bwx-screen-agents' ).click();
    const table = where.getByTestId( 'bwx-agents-table' );
    await expect( table ).toBeVisible( { timeout: 60_000 } );
    await table.getByRole( 'button', { name: /^Everything/ } ).click();
    const row = table.locator( 'tr.fk-row' ).filter( { hasText: title } ).first();
    await expect( row ).toBeVisible( { timeout: 60_000 } );
    await expect( row.getByTestId( 'bwx-agents-tag' ) ).toHaveText( 'Agent' );
    await expect( row.getByTestId( 'bwx-agents-keeper' ) ).toHaveText( builder.user.display_name, { timeout: 30_000 } );
    await expect( row ).not.toContainText( '3.0h' );
  };
  await onAgents( page );
  await context.close();

  // The whole studio's agent work, not only what you look after.
  const adminPage = await admin.context.newPage();
  await adminPage.goto( '/blueworx-forge/' );
  await onAgents( adminPage );

  // On the standup it waits on the Agent, not on whoever looks after it.
  await adminPage.getByTestId( 'bwx-screen-standup' ).click();
  await expect( adminPage.getByTestId( 'bwx-standup' ) ).toBeVisible( { timeout: 60_000 } );
  await expect( adminPage.getByTestId( 'bwx-standup-section-toggle' ).first() ).toBeVisible();
  const folded = adminPage.locator( '[data-testid="bwx-standup-section-toggle"][aria-expanded="false"]' );
  while ( ( await folded.count() ) > 0 ) await folded.first().click();
  const onStandup = adminPage.locator( `[data-testid="bwx-standup-card"][data-subject="${ item.id }"]` );
  await expect( onStandup ).toHaveCount( 1, { timeout: 60_000 } );
  await expect( onStandup ).toContainText( 'Waiting on Agent' );
  await adminPage.close();

  // #695. With the list of people refused, the screen still lists the work,
  // and still names who looks after it.
  const refusedPage = await admin.context.newPage();
  await refusedPage.route( ( url ) => url.pathname.endsWith( '/people' ), ( route ) => route.fulfill( { status: 500, body: '{}' } ) );
  await refusedPage.goto( '/blueworx-forge/' );
  await onAgents( refusedPage );
  await refusedPage.close();

  // #695. Once they have left, they are still named.
  const held = ( await admin.api.get( `/users/${ builder.id }` ) ).memberships[ 0 ];
  const ended = await admin.api.patch( `/memberships/${ held.id }`, { status: 'inactive', record_version: held.record_version } );
  expect( ended.status(), await ended.text() ).toBe( 200 );
  const leftPage = await admin.context.newPage();
  await leftPage.goto( '/blueworx-forge/' );
  await onAgents( leftPage );
  await leftPage.close();

  // The Agent is offered for doing the work, never for delivering it.
  const panelPage = await admin.context.newPage();
  await panelPage.goto( `/blueworx-forge/#item=${ item.id }` );
  const assign = panelPage.getByTestId( 'bwx-panel' ).getByTestId( 'bwx-assign' );
  await expect( assign.locator( `#bwx-primary_user_id option[value="${ agent.id }"]` ) ).toHaveCount( 1, { timeout: 60_000 } );
  await expect( assign.locator( `#bwx-deliverer_id option[value="${ agent.id }"]` ) ).toHaveCount( 0 );
  await panelPage.close();
} );
