import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

// #182. One way of asking "is this view usable by everyone", so both suites ask
// the same question of both artifacts.
//
// **What is checked, and what a passing check does not mean.** This runs the
// WCAG 2.1 A and AA rules axe can decide automatically, which is most of what
// the issue names — real form labels, readable contrast, heading order, alt
// text, names on controls. It cannot decide whether a label is *useful*, or
// whether the keyboard order makes sense, and nothing here pretends otherwise:
// those are asserted by hand in the specs that use this.
//
// A check that only ever runs on the screens somebody remembered is not a
// check, so both callers walk a list of every view their artifact has, and a
// new screen added without a row in that list is the kind of gap that survives
// for months.

/**
 * Rules that are switched off, and why.
 *
 * The issue asks for exceptions to be recorded and justified, so they live here
 * in one list rather than as `.disableRules()` scattered through the specs.
 * Anything not named here is enforced.
 *
 * `region` is the one exception, and only inside WordPress admin: it wants
 * every piece of content inside a landmark, and the page's own wrappers belong
 * to WordPress rather than to Forge. Fixing it would mean this plugin
 * restructuring core's admin markup around itself, which is a worse idea than
 * the finding.
 */
export const EXCEPTIONS = {
  'wp-admin': [ 'region' ],
  app: [],
};

/**
 * Fails with the actual findings rather than with a count.
 *
 * "3 violations" sends somebody to a report they have to go and find. The rule
 * id, the impact and the element are what they need to act, so they go in the
 * failure itself.
 *
 * @param {import('@playwright/test').Page} page  The page to check.
 * @param {string} name  What this view is called, for the failure message.
 * @param {'wp-admin'|'app'} kind  Which exception list applies.
 */
export async function checkAccessibility( page, name, kind = 'wp-admin' ) {
  const results = await new AxeBuilder( { page } )
    .withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
    .disableRules( EXCEPTIONS[ kind ] ?? [] )
    .analyze();

  const found = results.violations.map(
    ( violation ) =>
      `${ violation.id } (${ violation.impact }): ${ violation.help }\n    ${ violation.nodes
        .slice( 0, 3 )
        .map( ( node ) => node.target.join( ' ' ) )
        .join( '\n    ' ) }`
  );

  expect( found, `${ name } has accessibility failures:\n  ${ found.join( '\n  ' ) }` ).toEqual( [] );
}
