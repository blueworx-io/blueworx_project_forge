import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #513. Every assignment is done in a day: Capacity puts each person's hours
// on their own date, all of them on that day, and nothing on the days between.

const RUN = `seat${ Date.now() }`;

test( 'each person’s hours sit on their own date, and nowhere else', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Seat Co ${ RUN }`, RUN );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `seat${ RUN }` );
  await Forge.setHours( admin.api, person.id, 8 );

  // The site's today, and the days after it, as the server counts them.
  const today = ( await admin.api.get( '/standup' ) ).today;
  const day = ( offset ) => {
    const at = new Date( `${ today }T00:00:00Z` );
    at.setUTCDate( at.getUTCDate() + offset );
    return at.toISOString().slice( 0, 10 );
  };

  const made = await Forge.makeItem( admin.api, site.id, { title: `Per seat ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ item.id }`, {
    primary_user_id: person.id,
    hours_primary: 3,
    deliverer_id: person.id,
    hours_delivery: 1,
    planned_due: day( 2 ),
    release_target: day( 3 ),
    record_version: item.record_version,
  } );
  expect( edited.status(), await edited.text() ).toBe( 200 );
  const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'in-development', reason: 'Set up for the test.', record_version: ( await edited.json() ).item.record_version } );
  expect( moved.status(), await moved.text() ).toBe( 200 );

  const answer = await admin.api.get( `/capacity/person/${ person.id }?from=${ day( 0 ) }&to=${ day( 6 ) }` );
  const ours = answer.allocations.filter( ( one ) => one.item_id === item.id );
  const byDay = {};
  for ( const one of ours ) {
    for ( const [ date, hours ] of Object.entries( one.by_day ) ) {
      byDay[ date ] = ( byDay[ date ] ?? 0 ) + hours;
    }
  }

  expect( byDay ).toEqual( { [ day( 2 ) ]: 3, [ day( 3 ) ]: 1 } );

  await admin.context.close();
} );
