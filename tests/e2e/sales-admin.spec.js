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
// Selling and adjusting go over the routes the Support screen in the app is
// drawn from; the Sales list is still a WordPress admin screen and is walked.
//
// The instance is kept between runs, so every name carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

const SALES = '/wp-admin/admin.php?page=blueworx-forge-sales';
const GRANTED = 40;

/** A site on a forty-hour package, with a page to walk the admin on. */
async function withSite(browser, baseURL, { hours = GRANTED } = {}) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Sales Co ${RUN_ID}`, RUN_ID);

  if (hours > 0) {
    await Forge.onSupport(admin, site.id, hours);
  }

  const page = await admin.context.newPage();

  return { admin, site, page };
}

/** Sells hours to a site. */
async function topUp(admin, siteId, hours, reason = '') {
  const wrote = await admin.api.post(`/client-sites/${siteId}/support/top-up`, { hours, reason });
  expect(wrote.status(), await wrote.text()).toBe(200);

  return wrote.json();
}

/** Corrects a site's balance by hand, and returns the response unread. */
function adjust(admin, siteId, hours, reason) {
  return admin.api.post(`/client-sites/${siteId}/support/adjust`, { hours, reason });
}

test('hours can be sold, and they land on the ledger with an expiry', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page } = await withSite(browser, baseURL);

  const answer = await topUp(admin, site.id, 10, `Extra work agreed ${RUN_ID}`);
  expect(answer.entry.event_type).toBe('top-up');

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

  const refused = await adjust(admin, site.id, -5, '');
  expect(refused.status()).toBe(400);
  expect((await refused.json()).code).toBe('bwx_forge_reason_required');

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

  const made = await adjust(admin, site.id, -5, because);
  expect(made.status(), await made.text()).toBe(200);

  const ledger = await Forge.hourLedger(admin, site.id);

  expect(ledger.entries.filter(([type]) => 'adjustment' === type)).toHaveLength(1);
  expect(ledger.balance).toBe(GRANTED - 5);

  // And the reason is on the record the client's balance is queried from.
  const support = await admin.api.get(`/client-sites/${site.id}/support`);
  const entry = support.ledger.find((one) => 'adjustment' === one.event_type);
  expect(entry.reason).toBe(because);

  await page.close();
  await admin.context.close();
});

test('an adjustment can give hours back as well as take them away', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page } = await withSite(browser, baseURL);

  const made = await adjust(admin, site.id, 3, `Charged in error ${RUN_ID}`);
  expect(made.status(), await made.text()).toBe(200);

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

  // The row opens the site's Support screen in the app.
  await expect(row.locator('a', { hasText: 'Open' })).toHaveAttribute(
    'href',
    new RegExp(`#screen=support&site=${site.id}$`)
  );

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
  const made = await adjust(admin, site.id, -35, `Work done ${RUN_ID}`);
  expect(made.status(), await made.text()).toBe(200);

  await page.goto(SALES);
  await expect(
    page.locator(`[data-bwx-site="${site.id}"] [data-bwx-reason="low-hours"]`)
  ).toBeVisible();

  // Sell them some more, and the row goes.
  await topUp(admin, site.id, 20);

  await page.goto(SALES);
  await expect(page.locator(`[data-bwx-site="${site.id}"]`)).toHaveCount(0);

  await page.close();
  await admin.context.close();
});
