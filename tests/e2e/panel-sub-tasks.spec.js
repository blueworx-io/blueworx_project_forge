import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// The task panel lists what sits under a task, with each one's stage, and
// opens one when it is picked. A project that would not release because of
// work under it showed none of that work, only what it waited on.

const RUN = `subs${ Date.now() }`;

test( 'the panel lists a task’s sub-tasks with their stage, and opens one', async ( { browser, baseURL } ) => {
  test.slow();

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { site } = await Forge.makeSite( admin.api, `Sub Co ${ RUN }`, RUN );
  const make = async ( data ) => {
    const made = await Forge.makeItem( admin.api, site.id, data );
    expect( made.status(), await made.text() ).toBe( 200 );
    return ( await made.json() ).item;
  };

  const parent = await make( { title: `Launch ${ RUN }`, level: 'feature' } );
  await make( { title: `Pages ${ RUN }`, parent_id: parent.id } );
  await make( { title: `Emails ${ RUN }`, parent_id: parent.id } );
  const alone = await make( { title: `Alone ${ RUN }` } );

  const page = await admin.context.newPage();
  await page.goto( `/blueworx-forge/#item=${ parent.id }` );
  const panel = page.getByTestId( 'bwx-panel' );
  const subs = panel.getByTestId( 'bwx-subtasks' );
  await expect( subs ).toBeVisible( { timeout: 60_000 } );
  await expect( subs.getByTestId( 'bwx-subtask' ) ).toHaveCount( 2 );
  const pages = subs.getByTestId( 'bwx-subtask' ).filter( { hasText: `Pages ${ RUN }` } );
  await expect( pages ).toContainText( 'Future idea' );

  // Picked, it opens in the panel in place of its parent.
  await pages.getByRole( 'button', { name: `Pages ${ RUN }` } ).click();
  await expect( panel.locator( '.bwx-panel-title' ) ).toHaveText( `Pages ${ RUN }`, { timeout: 30_000 } );

  // Nothing under it, no box.
  await page.goto( `/blueworx-forge/#item=${ alone.id }` );
  await page.reload();
  await expect( panel.locator( '.bwx-panel-title' ) ).toHaveText( `Alone ${ RUN }`, { timeout: 60_000 } );
  await expect( panel.getByTestId( 'bwx-subtasks' ) ).toHaveCount( 0 );

  await page.close();
  await admin.context.close();
} );
