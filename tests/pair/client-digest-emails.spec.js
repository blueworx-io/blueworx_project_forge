import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, requireEnvironment, CLIENT_URL } from './helpers/pair.js';

// #673. A client with an email on its record gets a daily email on a weekday
// that involves it, and a weekly round-up — raised when its site asks what to
// send, once each, and never without an address.

const RUN = `digest${ Date.now() }`;

/** The date and hour it is somewhere, as the studio will work it out. */
function clock( zone ) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat( 'en-GB', { timeZone: zone, weekday: 'short', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23' } )
      .formatToParts( new Date() )
      .map( ( part ) => [ part.type, part.value ] )
  );

  return { zone, day: `${ parts.year }-${ parts.month }-${ parts.day }`, hour: Number( parts.hour ), weekend: [ 'Sat', 'Sun' ].includes( parts.weekday ) };
}

/**
 * Somewhere it is a weekday, comfortably after eight and before midnight, so
 * the day cannot turn over mid-test. Null on the stretch of a weekend when it
 * is the weekend everywhere — then the right answer is that nothing is sent.
 */
function workingClock() {
  return (
    [ 'Europe/London', 'Asia/Tokyo', 'America/Los_Angeles', 'Pacific/Auckland', 'Pacific/Honolulu', 'Asia/Kolkata' ]
      .map( clock )
      .find( ( one ) => ! one.weekend && one.hour >= 9 && one.hour <= 22 ) ?? null
  );
}

const digests = ( answer ) => ( answer.send ?? [] ).filter( ( one ) => [ 'client-daily', 'client-weekly' ].includes( one.kind ) );

test.beforeAll( requireEnvironment );

test( 'a client with an email gets the daily and the weekly, once each', async ( { browser, request } ) => {
  test.setTimeout( 360_000 );

  const when = workingClock();
  const title = `Opt-in wording ${ RUN }`;
  const address = `client-${ RUN }@example.com`;

  // Due this week wherever "this week" is being read from, so the weekly has something coming.
  const pair = await connectedPair( browser, 'Digest Co', RUN, { title, planned_due: ( when ?? clock( 'Europe/London' ) ).day } );
  const site = asClientSite( request, pair.issued );

  // Something is waiting on the client: a question nobody has answered.
  const asked = await pair.studio.post( `/work-items/${ pair.work.id }/comments`, { kind: 'question', body: `Tick box or automatic? ${ RUN }` } );
  expect( asked.status(), await asked.text() ).toBe( 200 );

  // No address on the client: nothing is raised, however much there is to say.
  expect( digests( await ( await site.get( '/client/notifications' ) ).json() ) ).toEqual( [] );

  // An address that is not one is refused.
  const client = ( await pair.studio.get( `/clients/${ pair.client.id }` ) ).client;
  const refused = await pair.studio.patch( `/clients/${ pair.client.id }`, { contact_email: 'not an address', record_version: client.record_version } );
  expect( refused.status() ).toBe( 400 );

  const saved = await pair.studio.patch( `/clients/${ pair.client.id }`, {
    contact_email: address,
    timezone: ( when ?? clock( 'Europe/London' ) ).zone,
    record_version: client.record_version,
  } );
  expect( saved.status(), await saved.text() ).toBe( 200 );
  expect( ( await saved.json() ).client.contact_email ).toBe( address );

  const first = digests( await ( await site.get( '/client/notifications' ) ).json() );

  if ( null === when ) {
    // The weekend, everywhere we looked: neither goes.
    expect( first ).toEqual( [] );
    await pair.close();
    return;
  }

  const daily = first.filter( ( one ) => 'client-daily' === one.kind );
  const weekly = first.filter( ( one ) => 'client-weekly' === one.kind );
  const link = `${ CLIENT_URL.replace( /\/$/, '' ) }/?forge_item=${ pair.work.id }`;

  expect( daily ).toHaveLength( 1 );
  expect( daily[ 0 ].to ).toEqual( [ address ] );
  expect( daily[ 0 ].subject ).toContain( 'Waiting on you today' );
  expect( daily[ 0 ].body ).toContain( 'Waiting on you' );
  expect( daily[ 0 ].body ).toContain( `- ${ title } (1 question to answer)` );
  expect( daily[ 0 ].body ).toContain( link );
  expect( daily[ 0 ].body ).not.toContain( 'Being worked on today' );

  expect( weekly ).toHaveLength( 1 );
  expect( weekly[ 0 ].to ).toEqual( [ address ] );
  expect( weekly[ 0 ].subject ).toContain( 'Your week, from Monday' );
  expect( weekly[ 0 ].body ).toContain( 'This week' );
  expect( weekly[ 0 ].body ).toContain( `- ${ title } (due ` );
  expect( weekly[ 0 ].body ).toContain( link );

  // Asking again offers the same two, not two more.
  const second = digests( await ( await site.get( '/client/notifications' ) ).json() );
  expect( second.map( ( one ) => one.event_id ).sort() ).toEqual( first.map( ( one ) => one.event_id ).sort() );

  // Once the address is taken away, what was waiting is dropped rather than sent.
  const now = ( await pair.studio.get( `/clients/${ pair.client.id }` ) ).client;
  const cleared = await pair.studio.patch( `/clients/${ pair.client.id }`, { contact_email: '', record_version: now.record_version } );
  expect( cleared.status(), await cleared.text() ).toBe( 200 );
  expect( digests( await ( await site.get( '/client/notifications' ) ).json() ) ).toEqual( [] );

  await pair.close();
} );
