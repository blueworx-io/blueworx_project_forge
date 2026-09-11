import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';
import { giveChecklistTo, publishChecklist } from './helpers/onboarding.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #303. Launch readiness on the workspace page: the checklist in its
// sections, each step's status, and the one thing a client can do — fill a
// step in, attach a file, and send it back. The studio sees it as handed
// over; nothing on the page can approve it.

const RUN = `launch${ Date.now() }`;
const PAGE = '/forge/#onboarding';
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

test.beforeAll( requireEnvironment );

test( 'a client fills a step in, attaches a file and sends it to the studio', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const step = `Delegate the domain ${ RUN }`;
  const pair = await connectedPair( browser, 'Launch Co', RUN );
  await publishChecklist( pair.studio, [ step ], RUN );
  await giveChecklistTo( pair.studio, pair.site.id );

  const page = await pair.clientSite.context.newPage();
  await page.goto( PAGE );

  const screen = page.getByTestId( 'bwx-onboarding' );
  await expect( screen ).toBeVisible();
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );

  // The step is listed, in its section, as the client's to do.
  await expect( page.getByTestId( 'bwx-launch-summary' ) ).toContainText( 'Needs you' );
  const row = page.getByTestId( 'bwx-step' ).filter( { hasText: step } );
  await expect( row ).toBeVisible();
  await expect( row ).toHaveAttribute( 'data-theirs', 'true' );

  await checkAccessibility( page, 'Client launch readiness', 'app' );

  // Filling it in is a link, so the dashboard can point straight at it.
  await row.getByTestId( 'bwx-step-open' ).click();
  await expect( page ).toHaveURL( /#onboarding\/[A-Za-z0-9_-]+$/ );
  const form = page.getByTestId( 'bwx-step-form' );
  await form.getByLabel( 'What have you done?' ).fill( 'Invited forge@studio.example to the registrar.' );
  await form.getByLabel( 'Attach something (optional)' ).setInputFiles( { name: `invite-${ RUN }.png`, mimeType: 'image/png', buffer: Buffer.from( PNG, 'base64' ) } );

  await checkAccessibility( page, 'Client checklist step', 'app' );

  await form.getByRole( 'button', { name: 'Send to us' } ).click();

  // Back on the list, the step is with the studio and the file is on it.
  await expect( page ).toHaveURL( /#onboarding$/ );
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );
  await expect( row ).toHaveAttribute( 'data-status', 'submitted', { timeout: 60_000 } );
  await expect( row ).toContainText( `invite-${ RUN }.png` );
  await expect( row.getByTestId( 'bwx-step-open' ) ).toHaveCount( 0 );

  // And the studio's own board says the same.
  const theirs = await pair.studio.get( `/onboarding/sites/${ pair.site.id }/steps` );
  const handed = theirs.steps.find( ( each ) => each.title === step );
  expect( handed?.status, 'the studio sees the step as handed over' ).toBe( 'submitted' );
  expect( handed?.response ).toContain( 'Invited forge@studio.example' );

  await page.close();
  await pair.close();
} );
