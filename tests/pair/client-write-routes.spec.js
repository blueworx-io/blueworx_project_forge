import { test, expect } from '@playwright/test';
import { CLIENT_URL, connectedPair, requireEnvironment } from './helpers/pair.js';
import { giveChecklistTo, publishChecklist } from './helpers/onboarding.js';

// #298, the writes. The client app sends a comment, evidence, a submission
// and a checklist answer through its own plugin, and each lands on the studio
// exactly as it would from the wp-admin form — and the work does not move.

const RUN = `writes${ Date.now() }`;
const CLIENT_API = '/wp-json/blueworx-forge-client/v1';

// A 1×1 transparent PNG: the smallest real image an upload will accept.
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

test.beforeAll( requireEnvironment );

test( 'a comment and evidence reach the studio, and the work does not move', async ( { browser } ) => {
  test.setTimeout( 240_000 );
  const pair = await connectedPair( browser, 'Writes Co', RUN, { title: `A thing being done ${ RUN }` } );
  const { request, headers } = pair.clientSite;
  const before = ( await pair.studio.get( `/work-items/${ pair.work.id }` ) ).item.stage;

  const comment = await request.post( `${ CLIENT_API }/items/${ pair.work.id }/discussion`, {
    headers,
    data: { body: `Any news on this? ${ RUN }` },
  } );
  expect( comment.status(), await comment.text() ).toBe( 200 );
  expect( ( await comment.json() ).ok ).toBe( true );

  const evidence = await request.post( `${ CLIENT_API }/items/${ pair.work.id }/discussion`, {
    headers,
    data: { body: `Here is the export ${ RUN }`, url: 'https://example.com/export.csv' },
  } );
  expect( ( await evidence.json() ).ok ).toBe( true );

  const detail = await pair.studio.get( `/work-items/${ pair.work.id }` );
  expect( detail.comments.map( ( one ) => one.body ) ).toContain( `Any news on this? ${ RUN }` );
  expect( detail.comments.find( ( one ) => 'evidence' === one.kind )?.url ).toBe( 'https://example.com/export.csv' );
  expect( detail.item.stage ).toBe( before );

  // And the app can read the discussion back through its own plugin.
  const read = await ( await request.get( `${ CLIENT_API }/items/${ pair.work.id }/discussion`, { headers } ) ).json();
  expect( JSON.stringify( read ) ).toContain( `Any news on this? ${ RUN }` );

  await pair.close();
} );

test( 'a submission lands in the studio queue', async ( { browser } ) => {
  test.setTimeout( 240_000 );
  const pair = await connectedPair( browser, 'Asking Co', RUN );
  const title = `The header overlaps on phones ${ RUN }`;

  const sent = await pair.clientSite.request.post( `${ CLIENT_API }/submissions`, {
    headers: pair.clientSite.headers,
    data: { type: 'bug', title, description: 'On an iPhone the logo sits over the menu.' },
  } );
  expect( sent.status(), await sent.text() ).toBe( 200 );
  expect( ( await sent.json() ).ok ).toBe( true );

  const queue = await pair.studio.get( '/submissions' );
  expect( queue.submissions.map( ( one ) => one.title ) ).toContain( title );

  await pair.close();
} );

test( 'a checklist answer hands the step to the studio', async ( { browser } ) => {
  test.setTimeout( 360_000 );
  const step = `Delegate the domain ${ RUN }`;
  const pair = await connectedPair( browser, 'Steps Co', RUN );

  await publishChecklist( pair.studio, [ step ], RUN );
  await giveChecklistTo( pair.studio, pair.site.id );

  const listed = await ( await pair.clientSite.request.get( `${ CLIENT_API }/checklist?refresh=true`, { headers: pair.clientSite.headers } ) ).json();
  const mine = ( listed.steps ?? [] ).find( ( each ) => each.title === step );
  expect( mine, 'the client can see the step' ).toBeTruthy();

  // A file first, while the step is still theirs to add to.
  const attached = await pair.clientSite.request.post( `${ CLIENT_API }/checklist/${ mine.id }/evidence`, {
    headers: pair.clientSite.headers,
    multipart: {
      evidence: { name: `invite-${ RUN }.png`, mimeType: 'image/png', buffer: Buffer.from( PNG, 'base64' ) },
    },
  } );
  expect( attached.status(), await attached.text() ).toBe( 200 );
  expect( ( await attached.json() ).ok, await attached.text() ).toBe( true );

  const answered = await pair.clientSite.request.post( `${ CLIENT_API }/checklist/${ mine.id }/answer`, {
    headers: pair.clientSite.headers,
    data: { response: 'I think that is done.', intent: 'submit' },
  } );
  expect( answered.status(), await answered.text() ).toBe( 200 );
  expect( ( await answered.json() ).ok ).toBe( true );

  const studioSide = await pair.studio.get( `/onboarding/sites/${ pair.site.id }/steps` );
  const submitted = studioSide.steps.find( ( each ) => each.title === step );
  expect( submitted?.status, 'the studio sees the step as handed over' ).toBe( 'submitted' );

  await pair.close();
} );

test( 'nobody signed out can send anything', async ( { browser } ) => {
  const context = await browser.newContext( { baseURL: CLIENT_URL } );

  const attempts = [
    context.request.post( `${ CLIENT_API }/items/wrk_000000000000/discussion`, { data: { body: 'x' } } ),
    context.request.post( `${ CLIENT_API }/submissions`, { data: { type: 'bug', title: 'x' } } ),
    context.request.post( `${ CLIENT_API }/checklist/step/answer`, { data: { response: 'x' } } ),
    context.request.post( `${ CLIENT_API }/checklist/step/evidence` ),
  ];
  for ( const attempt of await Promise.all( attempts ) ) {
    expect( attempt.status() ).toBe( 401 );
  }

  await context.close();
} );
