import { test, expect } from '@playwright/test';
import { checkAccessibility } from '../helpers/accessibility.js';

// #296. The shared component kit, shown on one page so every piece can be seen
// and checked in one place. The gallery is the kit's own proof: a component
// missing from it is a component nobody has looked at.

const GALLERY = '/blueworx-forge/#kit';

/** Every section the gallery has to show. */
const SECTIONS = [
  'buttons',
  'tags',
  'stage-chips',
  'cards',
  'page-header',
  'dataview',
  'dialog',
  'fields',
  'meter',
  'people',
  'toast',
  'empty-state',
];

test( 'every component renders on the kit page', async ( { page } ) => {
  await page.goto( GALLERY );

  const gallery = page.getByTestId( 'fk-gallery' );
  await expect( gallery ).toBeVisible();

  for ( const section of SECTIONS ) {
    await expect( gallery.locator( `[data-section="${ section }"]` ), `${ section } is missing` ).toBeVisible();
  }
} );

test( 'the kit page is usable by everyone', async ( { page } ) => {
  await page.goto( GALLERY );
  await expect( page.getByTestId( 'fk-gallery' ) ).toBeVisible();
  await checkAccessibility( page, 'Kit', 'app' );
} );

test( 'the data view selects rows and shows the bulk bar', async ( { page } ) => {
  await page.goto( GALLERY );
  const view = page.getByTestId( 'fk-gallery' ).locator( '[data-section="dataview"]' );

  await expect( view.getByRole( 'status' ) ).toHaveCount( 0 );
  await view.getByRole( 'checkbox', { name: 'Select row 1' } ).click();
  await view.getByRole( 'checkbox', { name: 'Select row 2' } ).click();

  const bar = view.getByTestId( 'fk-bulk-bar' );
  await expect( bar ).toBeVisible();
  await expect( bar ).toContainText( '2 items selected' );

  await bar.getByRole( 'button', { name: 'Clear selection' } ).click();
  await expect( bar ).toBeHidden();
} );

test( 'saved views filter the rows and search narrows them', async ( { page } ) => {
  await page.goto( GALLERY );
  const view = page.getByTestId( 'fk-gallery' ).locator( '[data-section="dataview"]' );
  const rows = view.locator( 'tbody tr' );

  const before = await rows.count();
  expect( before ).toBeGreaterThan( 2 );

  await view.getByRole( 'button', { name: /^Blocked/ } ).click();
  await expect( rows ).toHaveCount( 1 );

  await view.getByRole( 'button', { name: /^Everything/ } ).click();
  await expect( rows ).toHaveCount( before );

  await view.getByRole( 'searchbox' ).fill( 'redirect' );
  await expect( rows ).toHaveCount( 1 );
  await expect( view.getByTestId( 'fk-dataview-footer' ) ).toContainText( `1 of ${ before }` );
} );

test( 'a dialog opens, traps focus on its close control and closes with Escape', async ( { page } ) => {
  await page.goto( GALLERY );
  const section = page.getByTestId( 'fk-gallery' ).locator( '[data-section="dialog"]' );

  await section.getByRole( 'button', { name: 'Open a dialog' } ).click();
  const dialog = page.getByRole( 'dialog' );
  await expect( dialog ).toBeVisible();

  // The action stays off until a reason is written — never a bare confirm.
  const submit = dialog.getByRole( 'button', { name: 'Return with feedback' } );
  await expect( submit ).toBeDisabled();
  await dialog.getByLabel( /Reason/ ).fill( 'The evidence does not show the mobile case.' );
  await expect( submit ).toBeEnabled();

  await page.keyboard.press( 'Escape' );
  await expect( dialog ).toBeHidden();
} );

test( 'a toast says what happened and goes away', async ( { page } ) => {
  await page.goto( GALLERY );
  const section = page.getByTestId( 'fk-gallery' ).locator( '[data-section="toast"]' );

  await section.getByRole( 'button', { name: 'Show a toast' } ).click();
  const toast = page.getByRole( 'status' ).filter( { hasText: 'Comment added' } );
  await expect( toast ).toBeVisible();
  await toast.getByRole( 'button', { name: 'Dismiss' } ).click();
  await expect( toast ).toBeHidden();
} );
