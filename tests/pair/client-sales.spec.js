import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, requireEnvironment } from './helpers/pair.js';
import * as Forge from '../e2e/helpers/forge.js';

// #156, COMM-2. What a client can see about their own hours, on their own site.
//
// The assertion that matters is the boring one: **the balance the client is
// shown is the same figure the studio is shown**. Not close, not kept in step —
// the same number, because there is one calculation and both ends read it. Two
// figures that agree today are two figures that will disagree eventually, and
// the first anybody hears of it is a client querying a bill.
//
// The instance is kept between runs, so every name carries a run id.

const RUN = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const HOURS = '/wp-admin/admin.php?page=blueworx-forge-client-sales';
const GRANTED = 40;

test.beforeAll(requireEnvironment);

test('the client sees their own hours, and the figure is the studio\'s', async ({
  browser,
  request,
}) => {
  test.setTimeout(300_000);

  const pair = await connectedPair(browser, 'Client hours', RUN);

  await Forge.onSupport(pair.studio, pair.site.id, GRANTED);

  const signed = asClientSite(request, pair.issued);
  const sales = await (await signed.get('/client/sales')).json();

  expect(sales.entitlement.state).toBe('active');
  expect(sales.balance).toBe(GRANTED);

  /*
   * The same number the studio reads, from the studio's own screen. This is
   * #158's whole premise and it is asserted here because this is the first
   * point at which both figures exist.
   */
  const studio = await Forge.hourLedger(pair.studio, pair.site.id);

  expect(sales.balance, 'the client and the studio agree').toBe(studio.balance);

  await pair.close();
});

test('the client site shows the hours on a screen of its own', async ({ browser }) => {
  test.setTimeout(300_000);

  const pair = await connectedPair(browser, 'Hours screen', RUN);

  await Forge.onSupport(pair.studio, pair.site.id, GRANTED);

  const page = await pair.clientSite.context.newPage();

  await page.goto(HOURS);

  await expect(page.locator('[data-bwx-panel="hours"]')).toBeVisible();
  await expect(page.locator('[data-bwx-balance="40"]')).toBeVisible();
  await expect(page.locator('[data-bwx-state="active"]')).toBeVisible();

  // What they have been given, so "what have we actually paid for" has an
  // answer without ringing anybody.
  await expect(page.locator('[data-bwx-purchase="allocation"]')).toHaveCount(1);

  await page.close();
  await pair.close();
});

test('a client with no package is told what they could have', async ({ browser, request }) => {
  test.setTimeout(300_000);

  // No package at all. The screen is a sales conversation rather than an error,
  // and the packages on offer are the point of it.
  const pair = await connectedPair(browser, 'Nothing yet', RUN);
  const signed = asClientSite(request, pair.issued);
  const sales = await (await signed.get('/client/sales')).json();

  expect(sales.entitlement.state).toBe('none');
  expect(sales.balance).toBe(0);
  expect(sales.support.refused).toEqual(['chargeable-work']);

  const page = await pair.clientSite.context.newPage();

  await page.goto(HOURS);

  await expect(page.locator('[data-bwx-purchases="0"]')).toBeVisible();
  await expect(page.locator('[data-bwx-ask-hours="1"]')).toBeVisible();

  await page.close();
  await pair.close();
});

test('nothing on the client side pretends to sell anything', async ({ browser }) => {
  test.setTimeout(300_000);

  /*
   * COMM-2 keeps assignment manual, so this screen must not look like a
   * checkout. Asserted as an absence, because the failure would be somebody
   * adding a button that reads as a purchase and nobody noticing until a client
   * believed they had bought something.
   */
  const pair = await connectedPair(browser, 'No checkout', RUN);

  await Forge.onSupport(pair.studio, pair.site.id, GRANTED);

  const page = await pair.clientSite.context.newPage();

  await page.goto(HOURS);

  const words = (await page.locator('[data-bwx-panel="offer"]').innerText()).toLowerCase();

  for (const forbidden of ['buy now', 'checkout', 'pay now', 'add to basket']) {
    expect(words, `the screen must not say "${forbidden}"`).not.toContain(forbidden);
  }

  await expect(page.locator('[data-bwx-panel="offer"] form')).toHaveCount(0);

  await page.close();
  await pair.close();
});
