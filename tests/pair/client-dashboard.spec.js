import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';
import { giveChecklistTo, publishChecklist } from './helpers/onboarding.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #299. The client's home on the workspace page: what needs them, where they
// stand on hours, their contact, what is late and coming, and launch progress
// — every figure from the plugin's own reads, the same ones the wp-admin
// overview makes.

const RUN = `dash${ Date.now() }`;
const PAGE = '/forge/#dashboard';

test.beforeAll( requireEnvironment );

test( 'the dashboard shows the step that needs the client and the work that is late', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const step = `Send us the brand guidelines ${ RUN }`;
  const pair = await connectedPair( browser, 'Home Co', RUN, {
    title: `Late thing ${ RUN }`,
    planned_start: '2026-08-01',
    planned_due: '2026-08-10',
  } );

  await publishChecklist( pair.studio, [ step ], RUN );
  await giveChecklistTo( pair.studio, pair.site.id );

  const page = await pair.clientSite.context.newPage();
  await page.goto( PAGE );

  const dashboard = page.getByTestId( 'bwx-dashboard' );
  await expect( dashboard ).toBeVisible();

  // Four reads, each a round trip to the studio through PHP's one-at-a-time
  // built-in server: the page is up long before the last answer lands.
  await expect( dashboard.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );

  // The one block a client can act on lists the step that is theirs.
  const needsYou = page.getByTestId( 'bwx-needs-you' );
  await expect( needsYou.getByTestId( 'bwx-needs-you-step' ).filter( { hasText: step } ) ).toBeVisible();
  await expect( needsYou.getByRole( 'link', { name: 'Open step' } ).first() ).toHaveAttribute( 'href', /#onboarding\// );

  // Work past its date is named as needing attention, with us.
  const attention = page.getByTestId( 'bwx-attention' );
  await expect( attention.getByRole( 'link', { name: `Late thing ${ RUN }` } ) ).toBeVisible();
  await expect( attention ).toContainText( 'Overdue — with us' );

  // Hours, contact and launch are all drawn — even when there is nothing yet,
  // the condition is named rather than left blank.
  await expect( page.getByTestId( 'bwx-support-details' ) ).toBeVisible();
  await expect( page.getByTestId( 'bwx-contact' ) ).toContainText( /point of contact/i );
  await expect( page.getByTestId( 'bwx-launch-progress' ) ).toContainText( '1 step needs you' );

  await checkAccessibility( page, 'Client dashboard', 'app' );

  await page.close();
  await pair.close();
} );
