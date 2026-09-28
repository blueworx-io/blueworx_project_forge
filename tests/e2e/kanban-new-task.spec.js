import { test, expect } from '@playwright/test';
import { signedIn, makeSite } from './helpers/forge.js';

// #452. Opening the Kanban board shows just the board: the new task panel
// opens when somebody asks for a new task, and not again every time the
// board is opened after that.

const RUN = `kanbannew${ Date.now() }`;

test( 'opening Kanban after a new task leaves the new task panel closed', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  await makeSite( admin.api, `Kanban Co ${ RUN }`, RUN );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await expect( page.getByTestId( 'bwx-forge-ready' ) ).toBeVisible();

  const rail = page.getByRole( 'navigation', { name: 'Screens' } );
  const panel = page.getByTestId( 'bwx-new-work' );
  const boardReady = async () => {
    await expect( page.getByTestId( 'bwx-add' ) ).toBeEnabled( { timeout: 30_000 } );
  };

  // Asked for: it opens, and is put away again.
  await page.getByTestId( 'bwx-new-task' ).click();
  await expect( panel ).toBeVisible( { timeout: 30_000 } );
  await panel.getByRole( 'button', { name: 'Cancel' } ).click();
  await expect( panel ).toBeHidden();

  // Away and back to Kanban from the sidebar: just the board.
  await rail.getByTestId( 'bwx-screen-standup' ).click();
  await expect( page.locator( '.fs-title' ) ).toHaveText( 'Daily standup' );
  await rail.getByTestId( 'bwx-screen-work' ).click();
  await boardReady();
  await expect( panel ).toBeHidden();

  // Asking again still opens it.
  await page.getByTestId( 'bwx-new-task' ).click();
  await expect( panel ).toBeVisible( { timeout: 30_000 } );

  await page.close();
  await admin.context.close();
} );
