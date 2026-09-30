import { test, expect } from '@playwright/test';
import { signedIn, makeSite, satisfy } from './helpers/forge.js';

// #453. Each board card says whether it could move to the next stage: a tick
// when it could, a cross (with what is missing) when it could not, by the same
// check a drag is refused by. Nothing in the last stage.

const RUN = `cardready${ Date.now() }`;

test( 'a card shows a cross until it is ready, then a tick, and a drag agrees', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await makeSite( admin.api, `Ready Co ${ RUN }`, RUN );

  const created = await admin.api.post( '/work-items', {
    client_site_id: site.id,
    title: `Ready item ${ RUN }`,
    problem: 'Something needs doing.',
    level: 'feature',
    work_type: 'feature',
  } );
  const item = ( await created.json() ).item;

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.waitForSelector( '[data-testid="bwx-board"]' );
  await page.selectOption( '[data-testid="bwx-client-choice"]', site.id );

  const card = page.getByTestId( 'bwx-card' );
  const icon = card.getByTestId( 'bwx-card-ready' );
  await expect( card ).toHaveCount( 1 );

  // Not ready: a cross that names what is missing, on hover.
  await expect( icon ).toHaveAttribute( 'data-ready', 'false' );
  await expect( icon ).toHaveAttribute( 'aria-label', /Not ready to move on\. Still needed: .+/ );
  await expect( card.getByTestId( 'bwx-card-missing' ) ).toBeHidden();
  await icon.hover();
  await expect( card.getByTestId( 'bwx-card-missing' ) ).toBeVisible();

  // Done what was missing: the tick, after the board reloads.
  await satisfy( admin.api, item, 'triage' );
  await page.reload();
  await page.waitForSelector( '[data-testid="bwx-board"]' );
  await page.selectOption( '[data-testid="bwx-client-choice"]', site.id );
  await expect( icon ).toHaveAttribute( 'data-ready', 'true' );
  await expect( icon ).toHaveAttribute( 'aria-label', 'Ready to move to the next stage' );

  // And the drag it promised is accepted.
  await card.dragTo( page.locator( '[data-stage="triage"][data-testid="bwx-column"]' ) );
  await expect( card ).toHaveAttribute( 'data-stage', 'triage' );

  await page.close();
  await admin.context.close();
} );
