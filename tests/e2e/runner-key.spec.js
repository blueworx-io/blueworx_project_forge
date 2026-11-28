import { test, expect, request as requests } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// ARCH-9. The Forge Runner on Luke's PC builds the cards the Agent is doing.
// It has no login, so it sends the runner key an administrator made, and the
// key reaches the Agent's cards through five routes of their own and nothing
// else. Every test makes what it needs; the instance is reused between runs,
// so every name carries a run id.

const RUN = `runner${ Date.now() }`;
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const BASE = '/wp-json/blueworx-forge/v1';

/** A day some weeks out, different each run, so the Agent's week has room. */
const plus = ( day, days ) => new Date( Date.parse( `${ day }T12:00:00Z` ) + days * 86400000 ).toISOString().slice( 0, 10 );
const DUE = plus( '2029-01-08', 7 * Math.floor( Math.random() * 150 ) );

/**
 * The runner: no cookies, no nonce, no login. Only the key, in its header,
 * when there is one.
 */
async function runnerWith( baseURL, key ) {
  const context = await requests.newContext( { baseURL, extraHTTPHeaders: key ? { 'X-Forge-Runner-Key': key } : {} } );

  return {
    context,
    get: ( path ) => context.get( `${ BASE }${ path }` ),
    post: ( path, data = {} ) => context.post( `${ BASE }${ path }`, { data } ),
  };
}

/** A fresh runner key, made by the administrator. */
async function makeKey( admin ) {
  const made = await admin.api.post( '/runner/key', {} );
  expect( made.status(), await made.text() ).toBe( 200 );

  const answer = await made.json();
  expect( answer.key ).toMatch( /^bwxr_[A-Za-z0-9_-]{43,}$/ );
  expect( made.headers()[ 'cache-control' ] ).toContain( 'no-store' );

  return answer.key;
}

async function theAgent( api ) {
  const agents = ( await api.get( '/users?status=all' ) ).users.filter( ( person ) => true === person.is_agent );
  expect( agents, 'exactly one agent' ).toHaveLength( 1 );
  return agents[ 0 ];
}

/**
 * A card at Up Next with everything its gate asks for, done by `doer`: the
 * Agent, or a person for the cards the runner must not see.
 */
async function cardAtUpNext( admin, site, doer, people, title ) {
  const made = await Forge.makeItem( admin.api, site.id, { title, acceptance_criteria: '<p>It works.</p>', checklist: [ { text: 'Build it' }, { text: 'Check it' } ] } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;

  const seated = await admin.api.patch( `/work-items/${ item.id }`, {
    primary_user_id: doer,
    reviewer_id: people.reviewer.id,
    deliverer_id: people.deliverer.id,
    hours_primary: 1,
    hours_review: 1,
    hours_delivery: 1,
    planned_due: DUE,
    review_target: plus( DUE, 1 ),
    release_target: plus( DUE, 2 ),
    priority: 'normal',
    record_version: item.record_version,
  } );
  expect( seated.status(), await seated.text() ).toBe( 200 );

  const placed = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'up-next', reason: 'Set up for the runner test.', record_version: ( await seated.json() ).item.record_version } );
  expect( placed.status(), await placed.text() ).toBe( 200 );

  return ( await placed.json() ).item;
}

/** A site on support, with a reviewer and a deliverer who have time. */
async function workshop( admin, label ) {
  const { client, site } = await Forge.makeSite( admin.api, label, RUN );
  await Forge.onSupport( admin, site.id, 200 );

  const reviewer = await Forge.makePerson( admin.api, client.id, 'staff', `rrev${ RUN }` );
  const deliverer = await Forge.makePerson( admin.api, client.id, 'staff', `rdel${ RUN }` );
  await Forge.setHours( admin.api, reviewer.id, 8 );
  await Forge.setHours( admin.api, deliverer.id, 8 );

  return { client, site, people: { reviewer, deliverer } };
}

test( 'with the key, the runner lists, reads, claims, hands over and comments on the Agent’s card', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const agent = await theAgent( admin.api );
  const { site, people } = await workshop( admin, 'Runner Co' );

  const mine = await cardAtUpNext( admin, site, agent.id, people, `Agent builds this ${ RUN }` );
  const theirs = await cardAtUpNext( admin, site, people.deliverer.id, people, `A person builds this ${ RUN }` );

  const runner = await runnerWith( baseURL, await makeKey( admin ) );

  // The list: the Agent's card, and nobody else's, light.
  const listed = await runner.get( '/runner/cards?stage=up-next' );
  expect( listed.status(), await listed.text() ).toBe( 200 );
  const cards = ( await listed.json() ).cards;
  const ids = cards.map( ( card ) => card.id );
  expect( ids ).toContain( mine.id );
  expect( ids ).not.toContain( theirs.id );

  const light = cards.find( ( card ) => card.id === mine.id );
  expect( light.has_acceptance_criteria ).toBe( true );
  expect( light.record_version ).toBe( mine.record_version );
  expect( light ).not.toHaveProperty( 'client_id' );
  expect( light ).not.toHaveProperty( 'problem' );

  // In development is empty of it until it is claimed.
  expect( ( await ( await runner.get( '/runner/cards?stage=in-development' ) ).json() ).cards.map( ( card ) => card.id ) ).not.toContain( mine.id );
  expect( ( await runner.get( '/runner/cards?stage=released' ) ).status() ).toBe( 400 );

  // One card in full: its own definition, its next move, its own comments.
  const read = await runner.get( `/runner/cards/${ mine.id }` );
  expect( read.status(), await read.text() ).toBe( 200 );
  const card = ( await read.json() ).card;
  expect( card.problem ).toBe( 'Something needs doing.' );
  expect( card.checklist.map( ( line ) => line.text ) ).toEqual( [ 'Build it', 'Check it' ] );
  expect( card.next.to ).toBe( 'in-development' );
  // #718. Open, so no outcome; and a site that has reported nothing.
  expect( card.terminal_outcome ).toBe( '' );
  expect( card.site_plugins ).toEqual( [] );
  expect( card.site_reported_at ).toBe( 0 );
  expect( Array.isArray( card.comments ) ).toBe( true );
  for ( const leak of [ 'children', 'history', 'dependencies', 'parent_id', 'client_id', 'commercial_class' ] ) {
    expect( card, leak ).not.toHaveProperty( leak );
  }

  // Claimed: Up Next to In development, through the gate.
  const claimed = await runner.post( `/runner/cards/${ mine.id }/claim`, { record_version: card.record_version } );
  expect( claimed.status(), await claimed.text() ).toBe( 200 );
  const building = ( await claimed.json() ).card;
  expect( building.stage ).toBe( 'in-development' );

  // A claim against the old version is refused as stale.
  const stale = await runner.post( `/runner/cards/${ mine.id }/hand-over`, { record_version: card.record_version, hours_primary: 2, test_description: 'x' } );
  expect( stale.status() ).toBe( 409 );

  // A hand-over that leaves a line unticked is refused in Forge's words, and saves nothing.
  const unready = await runner.post( `/runner/cards/${ mine.id }/hand-over`, {
    record_version: building.record_version,
    hours_primary: 2,
    test_description: '<p>Open it and look.</p>',
    tick: [ 0 ],
  } );
  expect( unready.status(), await unready.text() ).toBe( 409 );
  const refusal = await unready.json();
  expect( refusal.unmet.map( ( one ) => one.id ) ).toContain( 'G-IN-DEVELOPMENT-1' );
  expect( refusal.unmet[ 0 ].satisfied_by ).toBeTruthy();
  const untouched = ( await admin.api.get( `/work-items/${ mine.id }` ) ).item;
  expect( untouched.record_version ).toBe( building.record_version );
  expect( untouched.hours_primary ).toBe( 1 );
  expect( untouched.test_description ).toBe( '' );

  // More than 8 hours is refused on that field, and nothing is written.
  const tooLong = await runner.post( `/runner/cards/${ mine.id }/hand-over`, {
    record_version: building.record_version,
    hours_primary: 9,
    test_description: '<p>Open it and look.</p>',
    tick: [ 0, 1 ],
  } );
  expect( tooLong.status() ).toBe( 400 );
  expect( ( await tooLong.json() ).data.fields.hours_primary ).toBe( 'The hours have to be more than 0 and no more than 8.' );
  expect( ( await admin.api.get( `/work-items/${ mine.id }` ) ).item.record_version ).toBe( building.record_version );

  // Anything but the four fields is refused by name.
  const tooMuch = await runner.post( `/runner/cards/${ mine.id }/hand-over`, {
    record_version: building.record_version,
    hours_primary: 2,
    test_description: 'x',
    title: 'Renamed by the runner',
  } );
  expect( tooMuch.status() ).toBe( 400 );
  expect( ( await tooMuch.json() ).data.fields.title ).toBeTruthy();

  // Handed over: the real hours and how to test it, every line ticked.
  const handed = await runner.post( `/runner/cards/${ mine.id }/hand-over`, {
    record_version: building.record_version,
    hours_primary: 2.5,
    test_description: '<p>Open it and look.</p>',
    test_steps: [ 'Open the page', 'Look at it' ],
    tick: [ 0, 1 ],
  } );
  expect( handed.status(), await handed.text() ).toBe( 200 );
  expect( ( await handed.json() ).card.stage ).toBe( 'in-review' );

  // A comment, internal, as the Agent, marked as the runner's.
  const said = await runner.post( `/runner/cards/${ mine.id }/comments`, { body: `Built and handed over ${ RUN }` } );
  expect( said.status(), await said.text() ).toBe( 200 );
  const note = ( await said.json() ).comment;
  expect( note.visibility ).toBe( 'internal' );
  expect( note.author_name ).toBe( 'Agent' );
  expect( note.from_runner ).toBe( true );

  const clientNote = await runner.post( `/runner/cards/${ mine.id }/comments`, { body: 'For the client', visibility: 'client' } );
  expect( clientNote.status() ).toBe( 400 );

  // The studio sees it all, as the runner's, with nobody behind it.
  const after = await admin.api.get( `/work-items/${ mine.id }` );
  expect( after.item.stage ).toBe( 'in-review' );
  expect( after.item.hours_primary ).toBe( 2.5 );
  expect( after.item.checklist.every( ( line ) => line.done ) ).toBe( true );
  expect( after.item.test_steps.map( ( line ) => line.text ) ).toEqual( [ 'Open the page', 'Look at it' ] );

  const byRunner = after.history.filter( ( entry ) => 'runner' === entry.via );
  expect( byRunner.map( ( entry ) => entry.to_stage ) ).toEqual( expect.arrayContaining( [ 'in-development', 'in-review' ] ) );
  expect( byRunner.map( ( entry ) => entry.field ) ).toEqual( expect.arrayContaining( [ 'hours_primary', 'test_description' ] ) );
  expect( byRunner.every( ( entry ) => 0 === Number( entry.actor ) ) ).toBe( true );

  const written = after.comments.find( ( comment ) => comment.body === `Built and handed over ${ RUN }` );
  expect( written.from_runner ).toBe( true );
  expect( written.visibility ).toBe( 'internal' );

  // The panel says who did it.
  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ mine.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  await expect( panel ).toContainText( 'Agent, by the runner', { timeout: 60_000 } );
  await expect( panel ).toContainText( 'internal, by the runner' );
  await page.close();

  // Nothing further: no move out of review, and In review is only read.
  expect( ( await runner.post( `/runner/cards/${ mine.id }/claim`, { record_version: after.item.record_version } ) ).status() ).toBe( 409 );
  expect( ( await runner.get( '/runner/cards?stage=in-review' ) ).status() ).toBe( 200 );

  await runner.context.dispose();
  await admin.context.close();
} );

test( 'a card somebody else is doing answers as if it did not exist, on every runner route', async ( { browser, baseURL } ) => {
  test.setTimeout( 180_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const { site, people } = await workshop( admin, 'Runner Other Co' );
  const theirs = await cardAtUpNext( admin, site, people.deliverer.id, people, `Not the Agent's ${ RUN }` );

  const runner = await runnerWith( baseURL, await makeKey( admin ) );
  const nobody = await runner.get( '/runner/cards/wrk_doesnotexist0000000000000000' );
  const missing = await nobody.json();
  expect( nobody.status() ).toBe( 404 );

  const answers = [
    await runner.get( `/runner/cards/${ theirs.id }` ),
    await runner.post( `/runner/cards/${ theirs.id }/claim`, { record_version: theirs.record_version } ),
    await runner.post( `/runner/cards/${ theirs.id }/hand-over`, { record_version: theirs.record_version, hours_primary: 1, test_description: 'x' } ),
    await runner.post( `/runner/cards/${ theirs.id }/comments`, { body: 'Hello' } ),
  ];

  for ( const answer of answers ) {
    expect( answer.status() ).toBe( 404 );
    expect( await answer.json() ).toEqual( missing );
  }

  const still = ( await admin.api.get( `/work-items/${ theirs.id }` ) ).item;
  expect( still.stage ).toBe( 'up-next' );
  expect( still.record_version ).toBe( theirs.record_version );

  await runner.context.dispose();
  await admin.context.close();
} );

test( 'no key, a wrong key, a turned-off key and a removed key are all refused alike', async ( { browser, baseURL } ) => {
  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const key = await makeKey( admin );

  const ask = async ( sent ) => {
    const runner = await runnerWith( baseURL, sent );
    const answer = await runner.get( '/runner/cards' );
    const body = { status: answer.status(), body: await answer.json() };
    await runner.context.dispose();
    return body;
  };

  expect( ( await ask( key ) ).status ).toBe( 200 );

  const none = await ask( '' );
  const wrong = await ask( `${ key }x` );

  const off = await admin.api.patch( '/runner/key', { enabled: false } );
  expect( off.status(), await off.text() ).toBe( 200 );
  expect( ( await off.json() ).enabled ).toBe( false );
  const turnedOff = await ask( key );

  const on = await admin.api.patch( '/runner/key', { enabled: true } );
  expect( ( await on.json() ).enabled ).toBe( true );
  expect( ( await ask( key ) ).status ).toBe( 200 );

  const removed = await admin.api.del( '/runner/key' );
  expect( ( await removed.json() ).exists ).toBe( false );
  const gone = await ask( key );

  for ( const refused of [ none, wrong, turnedOff, gone ] ) {
    expect( refused.status ).toBe( 401 );
    expect( refused.body ).toEqual( none.body );
  }

  // The status an administrator reads never carries the key.
  await makeKey( admin );
  const status = await admin.api.get( '/runner/key' );
  expect( status.exists ).toBe( true );
  expect( status ).not.toHaveProperty( 'key' );
  expect( status ).not.toHaveProperty( 'hash' );

  // In the address, it is not even looked at.
  const fresh = await makeKey( admin );
  const runner = await runnerWith( baseURL, '' );
  expect( ( await runner.context.get( `${ BASE }/runner/cards?x_forge_runner_key=${ fresh }` ) ).status() ).toBe( 401 );
  await runner.context.dispose();

  await admin.context.close();
} );

test( 'the key alone opens nothing else in Forge, and managing it is for administrators', async ( { browser, baseURL } ) => {
  test.setTimeout( 180_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  const runner = await runnerWith( baseURL, await makeKey( admin ) );

  for ( const path of [ '/work-items-all', '/users', '/runner/key', '/clients', '/standup' ] ) {
    const answer = await runner.get( path );
    expect( [ 401, 403 ], `${ path } with the key alone: ${ answer.status() }` ).toContain( answer.status() );
  }

  for ( const [ method, path ] of [ [ 'post', '/runner/key' ], [ 'post', '/work-items' ] ] ) {
    const answer = await runner.context[ method ]( `${ BASE }${ path }`, { data: {} } );
    expect( [ 401, 403 ], `${ method } ${ path } with the key alone` ).toContain( answer.status() );
  }

  // A Forge: Manager — every new member of staff is one — is let into Forge,
  // and still cannot see or make the key.
  const { client } = await Forge.makeSite( admin.api, 'Runner Manager Co', RUN );
  const manager = await Forge.makePerson( admin.api, client.id, 'staff', `rmgr${ RUN }` );

  const asManager = await Forge.signedIn( browser, baseURL, manager.login, Forge.PASSWORD );
  expect( ( await asManager.api.post( '/runner/key', {} ) ).status() ).toBe( 403 );
  const read = await asManager.api.request.get( `${ BASE }/runner/key`, { headers: asManager.api.headers } );
  expect( read.status() ).toBe( 403 );

  await asManager.context.close();
  await runner.context.dispose();
  await admin.context.close();
} );

test( 'an administrator makes, turns off, replaces and removes the key in Settings', async ( { browser, baseURL } ) => {
  test.setTimeout( 180_000 );

  const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
  await admin.api.del( '/runner/key' );

  const page = await admin.context.newPage();
  page.on( 'dialog', ( dialog ) => void dialog.accept() );
  await page.goto( '/blueworx-forge/#screen=settings' );

  const section = page.getByTestId( 'bwx-runner-key' );
  await expect( section ).toBeVisible( { timeout: 60_000 } );
  await expect( section.getByTestId( 'bwx-runner-key-status' ) ).toContainText( 'There is no runner key yet.', { timeout: 60_000 } );

  await section.getByRole( 'button', { name: 'Make key' } ).click();
  const shown = section.getByTestId( 'bwx-runner-key-shown' );
  await expect( shown ).toContainText( 'You will not see it again', { timeout: 60_000 } );
  const key = await section.getByLabel( 'Runner key', { exact: true } ).inputValue();
  expect( key ).toMatch( /^bwxr_/ );
  await expect( section.getByTestId( 'bwx-runner-key-status' ) ).toContainText( 'On', { timeout: 60_000 } );

  // The key the screen showed is the one Forge takes.
  const runner = await runnerWith( baseURL, key );
  expect( ( await runner.get( '/runner/cards' ) ).status() ).toBe( 200 );

  // Turned off and on, from the keyboard.
  await section.getByRole( 'button', { name: 'Turn off' } ).focus();
  await page.keyboard.press( 'Enter' );
  await expect( section.getByTestId( 'bwx-runner-key-status' ) ).toContainText( 'Off', { timeout: 60_000 } );
  expect( ( await runner.get( '/runner/cards' ) ).status() ).toBe( 401 );
  await section.getByRole( 'button', { name: 'Turn on' } ).click();
  await expect( section.getByTestId( 'bwx-runner-key-status' ) ).toContainText( 'On', { timeout: 60_000 } );

  // Replaced: a new key, and the old one stops working.
  await section.getByRole( 'button', { name: 'Replace key' } ).click();
  await expect( section.getByLabel( 'Runner key', { exact: true } ) ).not.toHaveValue( key, { timeout: 60_000 } );
  expect( ( await runner.get( '/runner/cards' ) ).status() ).toBe( 401 );

  // Shown once: opening Settings again does not bring it back.
  await page.goto( 'about:blank' );
  await page.goto( '/blueworx-forge/#screen=settings' );
  await expect( page.getByTestId( 'bwx-runner-key-status' ) ).toContainText( 'On', { timeout: 60_000 } );
  await expect( page.getByTestId( 'bwx-runner-key-shown' ) ).toHaveCount( 0 );

  await page.getByTestId( 'bwx-runner-key' ).getByRole( 'button', { name: 'Remove key' } ).click();
  await expect( page.getByTestId( 'bwx-runner-key-status' ) ).toContainText( 'There is no runner key yet.', { timeout: 60_000 } );

  await runner.context.dispose();
  await page.close();
  await admin.context.close();
} );
