import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// Once work is handed over for review, the person who did it is finished with
// it: their hours are done, on the day they were planned for, and no longer
// land on today as overdue work waiting on somebody else.

const RUN = `handed${ Date.now() }`;

test( 'a doer’s overdue hours leave their to-do once the work is in review', async ( { browser, baseURL } ) => {
  test.setTimeout( 240_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Handed Co ${ RUN }`, RUN );
  const doer = await Forge.makePerson( admin.api, client.id, 'staff', `doer${ RUN }` );
  const reviewer = await Forge.makePerson( admin.api, client.id, 'staff', `rev${ RUN }` );
  await Forge.setHours( admin.api, doer.id, 8 );
  await Forge.setHours( admin.api, reviewer.id, 8 );

  const today = ( await admin.api.get( '/standup' ) ).today;
  const day = ( offset ) => {
    const at = new Date( `${ today }T00:00:00Z` );
    at.setUTCDate( at.getUTCDate() + offset );
    return at.toISOString().slice( 0, 10 );
  };

  const made = await Forge.makeItem( admin.api, site.id, { title: `Handed over ${ RUN }` } );
  expect( made.status(), await made.text() ).toBe( 200 );
  const item = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ item.id }`, {
    primary_user_id: doer.id,
    hours_primary: 3,
    reviewer_id: reviewer.id,
    hours_review: 1,
    planned_due: day( -3 ),
    review_target: day( 1 ),
    record_version: item.record_version,
  } );
  expect( edited.status(), await edited.text() ).toBe( 200 );

  const doersToday = async () => {
    const answer = await admin.api.get( `/capacity/person/${ doer.id }?from=${ day( -6 ) }&to=${ day( 0 ) }` );

    return answer.allocations.filter( ( one ) => one.item_id === item.id );
  };

  // Still being built: the overdue hours are the doer's, on today.
  const building = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'in-development', reason: 'Set up for the test.', record_version: ( await edited.json() ).item.record_version } );
  expect( building.status(), await building.text() ).toBe( 200 );
  const before = await doersToday();
  expect( before.map( ( one ) => one.status ) ).toEqual( [ 'committed' ] );
  expect( before[ 0 ].by_day ).toEqual( { [ day( 0 ) ]: 3 } );

  // In review: the doer's part is done, on the day it was planned for.
  const reviewing = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'in-review', reason: 'Set up for the test.', record_version: ( await building.json() ).item.record_version } );
  expect( reviewing.status(), await reviewing.text() ).toBe( 200 );
  const after = await doersToday();
  expect( after.map( ( one ) => one.status ) ).toEqual( [ 'completed' ] );
  expect( after[ 0 ].by_day ).toEqual( { [ day( -3 ) ]: 3 } );

  // The reviewer still has their part to do.
  const review = await admin.api.get( `/capacity/person/${ reviewer.id }?from=${ day( 0 ) }&to=${ day( 2 ) }` );
  expect( review.allocations.filter( ( one ) => one.item_id === item.id ).map( ( one ) => one.status ) ).toEqual( [ 'committed' ] );

  await admin.context.close();
} );
