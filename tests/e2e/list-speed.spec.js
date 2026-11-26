import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #712. Forge's work lists used to ask the database a question or two for every
// card on them, whether it had evidence and whether it had open questions, so a
// list of four thousand cards made about seventeen hundred queries and took the
// shared server down with it. A list now reads those once.
//
// Like #183, what this guards is a loop, not a slow query: the number of
// queries a list makes must not climb with the number of cards on it. The count
// comes from the must-use plugin tests/global-setup.js writes.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${ Date.now() }-${ Math.floor( Math.random() * 1e6 ) }`;

/** A bug waiting in Bug Tracking, the stage whose way out asks for evidence. */
async function bug( api, siteId, title ) {
  const made = await ( await Forge.makeItem( api, siteId, { title, work_type: 'bug' } ) ).json();

  return Forge.walkTo( api, made.item, [ 'triage', 'bug-tracking' ] );
}

/** One read, with what it cost and what it said. */
async function read( api, path ) {
  const response = await api.request.get( `/wp-json/blueworx-forge/v1${ path }`, { headers: api.headers } );

  expect( response.status(), `${ path }: ${ await response.text() }` ).toBe( 200 );

  const counted = response.headers()['x-bwx-queries'];

  expect( counted, `${ path } did not report a query count — is the harness must-use plugin installed?` ).toBeTruthy();

  return { queries: Number( counted ), body: await response.json() };
}

test.describe( 'work lists do not ask about every card on its own', () => {
  test( 'queries stay level as cards are added, and evidence still counts', async ( { browser, baseURL } ) => {
    test.setTimeout( 600_000 );

    const admin = await Forge.signedIn( browser, baseURL, ADMIN_USER, ADMIN_PASS );
    const { site } = await Forge.makeSite( admin.api, 'List speed', RUN_ID );
    const all = '/work-items-all';
    const one = `/work-items?client_site_id=${ site.id }`;

    const proved = await bug( admin.api, site.id, `Has evidence ${ RUN_ID }` );
    const bare = await bug( admin.api, site.id, `No evidence ${ RUN_ID }` );

    const added = await admin.api.post( `/work-items/${ proved.id }/comments`, {
      kind: 'evidence',
      visibility: 'internal',
      body: 'It reproduces.',
      url: 'https://example.test/evidence',
    } );

    expect( added.status(), await added.text() ).toBe( 200 );

    const few = {
      all: await read( admin.api, all ),
      one: await read( admin.api, one ),
    };

    // What the list says about evidence is what one card says: only the bug
    // with a link in a comment since it reached Bug Tracking has it.
    const missing = ( answer, id ) =>
      answer.body.items.find( ( item ) => item.id === id ).next_step?.missing ?? [];

    expect( missing( few.one, bare.id ).join( '|' ) ).toMatch( /evidence/i );
    expect( missing( few.one, proved.id ).join( '|' ) ).not.toMatch( /evidence/i );
    expect( missing( few.all, bare.id ).join( '|' ) ).toMatch( /evidence/i );
    expect( missing( few.all, proved.id ).join( '|' ) ).not.toMatch( /evidence/i );

    for ( let i = 0; i < 6; i += 1 ) {
      await bug( admin.api, site.id, `More ${ i } ${ RUN_ID }` );
    }

    const many = {
      all: await read( admin.api, all ),
      one: await read( admin.api, one ),
    };

    expect( many.one.body.items.length ).toBeGreaterThanOrEqual( few.one.body.items.length + 6 );

    // Six more cards, each of which used to cost its own query, cost none.
    for ( const key of [ 'all', 'one' ] ) {
      expect(
        many[ key ].queries,
        `${ key }: ${ few[ key ].queries } queries for a few cards, ${ many[ key ].queries } for more`
      ).toBeLessThanOrEqual( few[ key ].queries + 2 );
    }

    await admin.context.close();
  } );
} );
