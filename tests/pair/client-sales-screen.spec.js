import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';
import { checkAccessibility } from '../helpers/accessibility.js';
import * as Forge from '../e2e/helpers/forge.js';

// #302. Support & hours on the workspace page: the plan, the balance, what is
// on offer and what has been bought — every figure the studio's own, and the
// balance the same number the studio shows. Nothing on it sells anything
// (COMM-2): packages are assigned by the studio, so the screen is a
// conversation with the point of contact, never a checkout.

const RUN = `sales${ Date.now() }`;
const PAGE = '/forge/#sales';
const GRANTED = 40;

test.beforeAll( requireEnvironment );

test( 'a client sees their plan, their balance and the offer, and nothing to buy', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const pair = await connectedPair( browser, 'Hours Co', RUN );
  await Forge.onSupport( pair.studio, pair.site.id, GRANTED );

  const page = await pair.clientSite.context.newPage();
  await page.goto( PAGE );

  const screen = page.getByTestId( 'bwx-sales' );
  await expect( screen ).toBeVisible();
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );

  // The balance is the studio's figure, to the hour.
  const ledger = await Forge.hourLedger( pair.studio, pair.site.id );
  await expect( page.getByTestId( 'bwx-balance' ) ).toHaveText( `${ ledger.balance.toFixed( 1 ) }h` );
  await expect( page.getByTestId( 'bwx-plan' ) ).toContainText( 'On support' );

  // The package assigned is in the history, and the offer lists what is on sale.
  await expect( page.getByTestId( 'bwx-purchases' ) ).toContainText( 'Your package' );
  await expect( page.getByTestId( 'bwx-package' ).first() ).toBeVisible();

  // Nothing reads as a purchase.
  const words = ( await screen.innerText() ).toLowerCase();
  for ( const forbidden of [ 'buy now', 'checkout', 'pay now', 'add to basket', 'purchase' ] ) {
    expect( words, `the screen must not say "${ forbidden }"` ).not.toContain( forbidden );
  }
  await expect( screen.locator( 'form' ) ).toHaveCount( 0 );

  await checkAccessibility( page, 'Client support and hours', 'app' );

  await page.close();
  await pair.close();
} );

test( 'a client with no package is told what they could have', async ( { browser } ) => {
  test.setTimeout( 240_000 );

  const pair = await connectedPair( browser, 'Nothing Yet Co', RUN );
  const page = await pair.clientSite.context.newPage();
  await page.goto( PAGE );

  const screen = page.getByTestId( 'bwx-sales' );
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );

  await expect( page.getByTestId( 'bwx-support-refused' ) ).toContainText( 'until a support package is in place' );
  await expect( page.getByTestId( 'bwx-sales-contact' ) ).toContainText( 'point of contact' );

  await page.close();
  await pair.close();
} );
