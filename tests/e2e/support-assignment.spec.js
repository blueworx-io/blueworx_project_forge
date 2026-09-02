import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #146. A client's commercial position over time.
//
// "A client's entitlement on any past date can be reconstructed from the
// record" is the criterion, and the record is a run of dated periods: every
// change closes one and opens the next. So the test that matters is the one
// that suspends a site and puts it back, then reads the history — because that
// is the case the obvious design, a suspended flag on one row, cannot answer.
//
// It also closes two things left open by #147 and #148. Assigning a package
// grants its hours through the ledger, so the ledger has a screen at last; and
// a part-year assignment shows its sum before it is written, so the pro-rata
// preview has somewhere to be displayed. Both are checked here.
//
// The instance is kept between runs and other specs leave sites behind, so
// every assertion is scoped to this run's own.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const PACKAGES = '/wp-admin/admin.php?page=blueworx-forge-packages';
const SUPPORT = '/wp-admin/admin.php?page=blueworx-forge-support';

/** Today and a date some days from it, as the screen writes them. */
function day(offset = 0) {
  const at = new Date(Date.now() + offset * 86400000);

  return at.toISOString().slice(0, 10);
}

/** A site, and a package on offer to put it on. */
let made = 0;

async function withSiteAndPackage(browser, baseURL) {
  // A name of its own per test. Every test here adds a package to a shared
  // instance, so one name shared between them leaves several identical options
  // in the list and no way to say which is this test's.
  const label = `Standard ${RUN_ID}-${++made}`;
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Support Co ${RUN_ID}`, RUN_ID);
  const page = await admin.context.newPage();

  await page.goto(PACKAGES);

  const form = page.locator('form').filter({ has: page.locator('input[value="bwx_forge_add_package"]') });

  await form.locator('input[name="name"]').fill(label);
  await form.locator('input[name="hours"]').fill('12');
  await form.locator('input[name="price"]').fill('1200');
  await form.locator('input[name="validity_months"]').fill('12');
  await form.locator('#bwx-add').click();
  await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();

  return { admin, site, page, label };
}

/** Opens one site's support screen. */
async function openSupport(page, siteId) {
  await page.goto(`${SUPPORT}&site=${siteId}`);
  await expect(page.locator('[data-bwx-support-state]')).toBeVisible();
}

/**
 * Chooses this run's own package.
 *
 * The instance is shared and earlier specs leave packages behind, so the first
 * option in the list belongs to somebody else — and a test that silently
 * assigns a ten-hour package while asserting about a twelve-hour one fails for
 * a reason that has nothing to do with what it is checking.
 */
async function chooseOurPackage(page, label) {
  const option = page.locator('#bwx-assign-package option', { hasText: label });
  const value = await option.getAttribute('value');

  expect(value, 'this test’s package is on offer').toBeTruthy();

  await page.locator('#bwx-assign-package').selectOption(value);
}

test('a site with no package says so, and says it is not lapsed', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, site, page } = await withSiteAndPackage(browser, baseURL);

  await openSupport(page, site.id);

  // "None" rather than "lapsed": nothing has run out because nothing has begun,
  // and reading it the other way would put this client on a renewal list for a
  // package they have never held.
  await expect(page.locator('[data-bwx-support-state="none"]')).toBeVisible();
  await expect(page.locator('[data-bwx-may-use-hours="no"]')).toBeVisible();
  await expect(page.locator('[data-bwx-periods="0"]')).toBeVisible();

  await page.close();
  await admin.context.close();
});

test('assigning a package puts the site on support and its hours on the ledger', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page, label } = await withSiteAndPackage(browser, baseURL);

  await openSupport(page, site.id);
  await chooseOurPackage(page, label);
  await page.locator('#bwx-assign-from').fill(day(0));
  await page.locator('#bwx-assign').click();

  await expect(page.locator('[data-bwx-result="assigned"]')).toBeVisible();
  await expect(page.locator('[data-bwx-support-state="active"]')).toBeVisible();
  await expect(page.locator('[data-bwx-may-use-hours="yes"]')).toBeVisible();

  /*
   * The hours arrived through the ledger, which is the only way hours ever
   * arrive. A balance that moved by some other route would be a second path by
   * which a client's entitlement can change, and there is not one.
   */
  await expect(page.locator('[data-bwx-balance="12"]')).toBeVisible();
  await expect(page.locator('[data-bwx-entry="allocation"]')).toHaveCount(1);
  await expect(page.locator('[data-bwx-entry="allocation"]')).toHaveAttribute(
    'data-bwx-entry-hours', '12');

  await page.close();
  await admin.context.close();
});

test('suspending leaves the hours alone, and resuming can be told apart from it later', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page, label } = await withSiteAndPackage(browser, baseURL);

  await openSupport(page, site.id);
  await chooseOurPackage(page, label);
  await page.locator('#bwx-assign-from').fill(day(-30));
  await page.locator('#bwx-assign').click();
  await expect(page.locator('[data-bwx-result="assigned"]')).toBeVisible();

  // Stopped a fortnight ago.
  await page.locator('input[name="from"]').first().fill(day(-14));
  await page.locator('#bwx-suspend').click();

  await expect(page.locator('[data-bwx-result="suspended"]')).toBeVisible();
  await expect(page.locator('[data-bwx-support-state="suspended"]')).toBeVisible();

  // COMM-4: the balance is frozen, not voided. Hours a client paid for are
  // theirs whatever their package is doing.
  await expect(page.locator('[data-bwx-balance="12"]')).toBeVisible();
  await expect(page.locator('[data-bwx-entry="allocation"]')).toHaveCount(1);

  // And back on, a week ago.
  await page.locator('input[name="from"]').first().fill(day(-7));
  await page.locator('#bwx-resume').click();

  await expect(page.locator('[data-bwx-result="resumed"]')).toBeVisible();
  await expect(page.locator('[data-bwx-support-state="active"]')).toBeVisible();

  /*
   * Three periods, which is the criterion. A suspended flag on a single row
   * would now say only that this site is active, and the fortnight it was not
   * would be gone — along with any way to answer for what it was entitled to
   * then.
   */
  await expect(page.locator('[data-bwx-periods="3"]')).toBeVisible();
  await expect(page.locator('[data-bwx-period-state="suspended"]')).toHaveCount(1);
  await expect(page.locator('[data-bwx-period-state="active"]')).toHaveCount(2);

  // Still granted once, however many times the position changed.
  await expect(page.locator('[data-bwx-entry="allocation"]')).toHaveCount(1);

  await page.close();
  await admin.context.close();
});

test('a part-year assignment grants the pro-rated hours, not a full year', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page, label } = await withSiteAndPackage(browser, baseURL);

  await openSupport(page, site.id);

  /*
   * A client asking to align with a shared renewal date — the one case COMM-1
   * applies pro-rata to. Half a term, so half the hours: six of twelve,
   * arriving on the ledger as exactly that.
   */
  const from = '2026-01-01';
  const to = '2026-07-01';

  await chooseOurPackage(page, label);
  await page.locator('#bwx-assign-from').fill(from);
  await page.locator('#bwx-assign-until').fill(to);
  await page.locator('#bwx-assign').click();

  await expect(page.locator('[data-bwx-result="assigned"]')).toBeVisible();

  const allocation = page.locator('[data-bwx-entry="allocation"]');

  await expect(allocation).toHaveCount(1);
  await expect(allocation).toHaveAttribute('data-bwx-entry-hours', '6');
  await expect(page.locator('[data-bwx-balance="6"]')).toBeVisible();

  await page.close();
  await admin.context.close();
});

test('cancelling ends the cover and leaves the hours to be dealt with deliberately', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page, label } = await withSiteAndPackage(browser, baseURL);

  await openSupport(page, site.id);
  await chooseOurPackage(page, label);
  await page.locator('#bwx-assign-from').fill(day(-30));
  await page.locator('#bwx-assign').click();
  await expect(page.locator('[data-bwx-result="assigned"]')).toBeVisible();

  await page.locator('form:has(#bwx-cancel) input[name="from"]').fill(day(-1));
  await page.locator('#bwx-cancel').click();

  await expect(page.locator('[data-bwx-result="cancelled"]')).toBeVisible();

  // Lapsed rather than gone: the record still shows what they had, and the
  // hours are still there to be written off with a reason if that is what was
  // agreed. Taking them back quietly is not something cancelling does.
  await expect(page.locator('[data-bwx-support-state="lapsed"]')).toBeVisible();
  await expect(page.locator('[data-bwx-balance="12"]')).toBeVisible();
  await expect(page.locator('[data-bwx-periods="1"]')).toBeVisible();

  await page.close();
  await admin.context.close();
});
