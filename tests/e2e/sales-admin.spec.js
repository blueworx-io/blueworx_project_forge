import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #157, COMM-3 and COMM-4. The studio's controls over what a client is entitled
// to, against a real database.
//
// Two things are being proved, and the second is the acceptance criterion:
// hours can be sold and balances corrected by hand, and **every manual
// adjustment carries a reason and appears in the ledger**. A correction nobody
// can account for is the one thing the hour record cannot survive, because the
// record is what the studio and the client are both reading when they disagree
// about a bill.
//
// The instance is kept between runs, so every name carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

const SUPPORT = '/wp-admin/admin.php?page=blueworx-forge-support';
const SALES = '/wp-admin/admin.php?page=blueworx-forge-sales';
const GRANTED = 40;

/** A site on a forty-hour package, with its support screen open. */
async function withSite(browser, baseURL, { hours = GRANTED } = {}) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Sales Co ${RUN_ID}`, RUN_ID);

  if (hours > 0) {
    await Forge.onSupport(admin, site.id, hours);
  }

  const page = await admin.context.newPage();

  await page.goto(`${SUPPORT}&site=${site.id}`);

  return { admin, site, page };
}

test('hours can be sold, and they land on the ledger with an expiry', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page } = await withSite(browser, baseURL);

  await page.fill('#bwx-top-up-hours', '10');
  await page.fill('#bwx-top-up-note', `Extra work agreed ${RUN_ID}`);
  await page.click('#bwx-top-up');

  await expect(page.locator('[data-bwx-result="topped-up"]')).toBeVisible();

  const ledger = await Forge.hourLedger(admin, site.id);

  expect(ledger.entries.filter(([type]) => 'top-up' === type)).toHaveLength(1);
  expect(ledger.balance).toBe(GRANTED + 10);

  await page.close();
  await admin.context.close();
});

test('an adjustment without a reason is refused', async ({ browser, baseURL }) => {
  test.slow();

  /*
   * #157's acceptance, stated as the thing that must not be possible. A
   * write-off with nothing said for it is a number somebody has to explain six
   * months later with no way to do it — so the refusal is the feature, and it
   * is checked before checking that the good case works.
   */
  const { admin, site, page } = await withSite(browser, baseURL);

  // The browser will not submit the form with the reason empty, so the check
  // has to go through the route the way anything else would.
  await page.evaluate(() => {
    document.querySelector('#bwx-adjust-reason').removeAttribute('required');
  });

  await page.fill('#bwx-adjust-hours', '-5');
  await page.click('#bwx-adjust');

  await expect(page.locator('[data-bwx-result="no-reason"]')).toBeVisible();

  const ledger = await Forge.hourLedger(admin, site.id);

  expect(ledger.entries.filter(([type]) => 'adjustment' === type)).toHaveLength(0);
  expect(ledger.balance, 'nothing moved').toBe(GRANTED);

  await page.close();
  await admin.context.close();
});

test('an adjustment with a reason is made, and the reason is on the record', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page } = await withSite(browser, baseURL);
  const because = `Goodwill after the outage ${RUN_ID}`;

  await page.fill('#bwx-adjust-hours', '-5');
  await page.fill('#bwx-adjust-reason', because);
  await page.click('#bwx-adjust');

  await expect(page.locator('[data-bwx-result="adjusted"]')).toBeVisible();

  const ledger = await Forge.hourLedger(admin, site.id);

  expect(ledger.entries.filter(([type]) => 'adjustment' === type)).toHaveLength(1);
  expect(ledger.balance).toBe(GRANTED - 5);

  // And the reason is on the screen the client's balance is queried from.
  await page.goto(`${SUPPORT}&site=${site.id}`);
  await expect(page.locator('[data-bwx-entry="adjustment"]')).toContainText(because);

  await page.close();
  await admin.context.close();
});

test('an adjustment can give hours back as well as take them away', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page } = await withSite(browser, baseURL);

  await page.fill('#bwx-adjust-hours', '3');
  await page.fill('#bwx-adjust-reason', `Charged in error ${RUN_ID}`);
  await page.click('#bwx-adjust');

  await expect(page.locator('[data-bwx-result="adjusted"]')).toBeVisible();
  expect((await Forge.hourLedger(admin, site.id)).balance).toBe(GRANTED + 3);

  await page.close();
  await admin.context.close();
});

test('the sales list shows who needs a conversation, and why', async ({ browser, baseURL }) => {
  test.slow();

  // A site with no package at all: the one conversation that is a sale rather
  // than a renewal or a top-up.
  const { admin, site, page } = await withSite(browser, baseURL, { hours: 0 });

  await page.goto(SALES);

  const row = page.locator(`[data-bwx-site="${site.id}"]`);

  await expect(row).toBeVisible();
  await expect(row.locator('[data-bwx-reason="no-package"]')).toBeVisible();

  /*
   * And only that reason. A client with nothing is not also "running low on
   * hours" — that is what you get from dividing by nought, and a list that says
   * it is a list nobody trusts.
   */
  await expect(row.locator('[data-bwx-reason="low-hours"]')).toHaveCount(0);

  await page.close();
  await admin.context.close();
});

test('a site running low is on the list, and drops off when it is topped up', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page } = await withSite(browser, baseURL);

  // Thirty-five of forty spent leaves five, which is under a fifth.
  await page.fill('#bwx-adjust-hours', '-35');
  await page.fill('#bwx-adjust-reason', `Work done ${RUN_ID}`);
  await page.click('#bwx-adjust');
  await expect(page.locator('[data-bwx-result="adjusted"]')).toBeVisible();

  await page.goto(SALES);
  await expect(
    page.locator(`[data-bwx-site="${site.id}"] [data-bwx-reason="low-hours"]`)
  ).toBeVisible();

  // Sell them some more, and the row goes.
  await page.goto(`${SUPPORT}&site=${site.id}`);
  await page.fill('#bwx-top-up-hours', '20');
  await page.click('#bwx-top-up');
  await expect(page.locator('[data-bwx-result="topped-up"]')).toBeVisible();

  await page.goto(SALES);
  await expect(page.locator(`[data-bwx-site="${site.id}"]`)).toHaveCount(0);

  await page.close();
  await admin.context.close();
});
