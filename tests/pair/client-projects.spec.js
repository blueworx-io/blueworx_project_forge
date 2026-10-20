import { test, expect } from '@playwright/test';
import { connectedPair, makeItem, requireEnvironment, studioSite } from './helpers/pair.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #565. A client sees their own projects and releases in the workspace, read
// only: the list with dates and progress, and one project's releases with the
// weeks each runs and the work in it. Another client's projects never appear.

const RUN = `projects${ Date.now() }`;
const CLIENT_API = '/wp-json/blueworx-forge-client/v1';

test.beforeAll( requireEnvironment );

test( 'a client sees their projects and releases', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const projectTitle = `Member area ${ RUN }`;
  const pair = await connectedPair( browser, 'Projects Co', RUN, { title: projectTitle, level: 'project' } );
  const project = pair.work;
  const release = await makeItem( pair.studio, pair.site.id, {
    title: `First release ${ RUN }`,
    level: 'milestone',
    parent_id: project.id,
    planned_start: '2026-07-01',
    planned_due: '2026-07-24',
  } );
  const feature = await makeItem( pair.studio, pair.site.id, {
    title: `Sign-in page ${ RUN }`,
    level: 'feature',
    parent_id: release.id,
    planned_start: '2026-07-01',
    planned_due: '2026-07-10',
  } );
  const bug = await makeItem( pair.studio, pair.site.id, {
    title: `Password reset email ${ RUN }`,
    level: 'sub-feature',
    work_type: 'bug',
    parent_id: release.id,
  } );

  // What reaches the client site carries progress, and never which fixes a
  // release review raised.
  const board = await (
    await pair.clientSite.context.request.get( `${ CLIENT_API }/board?refresh=true`, { headers: { 'X-WP-Nonce': pair.clientSite.nonce } } )
  ).json();
  const sent = board.items.find( ( one ) => one.id === project.id );
  expect( sent.children_total ).toBe( 1 );
  expect( sent.derived_start ).toBe( '2026-07-01' );
  expect( sent.derived_due ).toBe( '2026-07-24' );
  for ( const one of board.items ) expect( one ).not.toHaveProperty( 'review_of' );

  const page = await pair.clientSite.context.newPage();
  await page.goto( '/forge/#projects' );

  const list = page.getByTestId( 'bwx-client-projects-list' );
  await expect( list ).toBeVisible( { timeout: 60_000 } );
  const row = list.getByTestId( 'bwx-client-project-row' ).filter( { hasText: projectTitle } );
  await expect( row ).toBeVisible();
  await expect( row ).toContainText( '01 Jul 2026 – 24 Jul 2026' );
  await expect( row ).toContainText( '0 of 1 done' );

  await checkAccessibility( page, 'Client projects', 'app' );

  // Opening it is a link, so it can be shared and the back button works.
  await row.getByRole( 'link', { name: projectTitle } ).click();
  await expect( page ).toHaveURL( new RegExp( `#projects/${ project.id }$` ) );

  const one = page.getByTestId( 'bwx-client-project' );
  await expect( one ).toBeVisible( { timeout: 60_000 } );
  const shown = one.getByTestId( 'bwx-client-release' ).filter( { hasText: release.title } );
  await expect( shown ).toBeVisible();
  await expect( shown.getByTestId( 'bwx-client-release-weeks' ) ).toHaveText( 'Weeks 27 → 30' );
  await expect( shown ).toContainText( feature.title );
  await expect( shown ).toContainText( bug.title );

  // Nothing here changes anything: no buttons to add or move work.
  await expect( one.getByRole( 'button', { name: /add|move|raise/i } ) ).toHaveCount( 0 );

  await checkAccessibility( page, 'Client project', 'app' );

  // Every row opens the existing item record.
  await shown.getByRole( 'link', { name: feature.title } ).click();
  await expect( page ).toHaveURL( new RegExp( `#board/${ feature.id }$` ) );
  await expect( page.getByTestId( 'bwx-item-record' ) ).toBeVisible( { timeout: 60_000 } );

  await page.close();
  await pair.close();
} );

test( "a client never sees another client's projects", async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const ours = `Our project ${ RUN }`;
  const theirs = `Their project ${ RUN }`;
  const pair = await connectedPair( browser, 'Own Projects Co', `${ RUN }b`, { title: ours, level: 'project' } );
  const other = await studioSite( pair.studio, 'Other Projects Co', `${ RUN }b` );
  await makeItem( pair.studio, other.site.id, { title: theirs, level: 'project' } );

  const page = await pair.clientSite.context.newPage();
  await page.goto( '/forge/#projects' );

  const list = page.getByTestId( 'bwx-client-projects-list' );
  await expect( list ).toBeVisible( { timeout: 60_000 } );
  await expect( list.getByTestId( 'bwx-client-project-row' ).filter( { hasText: ours } ) ).toBeVisible();
  await expect( page.getByText( theirs ) ).toHaveCount( 0 );

  await page.close();
  await pair.close();
} );
