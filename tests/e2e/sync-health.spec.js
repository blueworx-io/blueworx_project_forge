import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { asSite } from '../helpers/signing.js';

// #177. A broken client site is noticed by us, not by the client.
//
// "A stalled site appears in the queue with enough detail to act on" is the
// criterion, and the second half of it is what these tests spend their time on.
// A queue that says a site is broken and nothing else has told somebody what
// they already knew — that the client rang. What it has to carry is what went
// wrong, how long it has been wrong, and what to try, and those are what is
// asserted here.
//
// A site *breaking* is provoked rather than waited for: the spec signs a
// request with the wrong key, which is exactly the shape of the commonest real
// fault — a key rotated here and not on the site. Going quiet cannot be
// provoked in a browser, because it takes three days by design; that half is
// proved in tests/php/SyncTest.php, where the clock can be moved.
//
// The instance is kept between runs and other specs leave sites behind, so
// every assertion here is scoped to this run's own site.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-sync';

/** A connected client site, and a way to speak as it. */
async function withConnectedSite(browser, baseURL, request) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Sync Health Co ${RUN_ID}`, RUN_ID);
  const speaking = await Forge.asClientSite(admin.api, site.id, request);

  return { admin, site, speaking };
}

test('a site that is talking to us stays out of the queue', async ({
  browser,
  baseURL,
  request,
}) => {
  test.slow();

  const { admin, site, speaking } = await withConnectedSite(browser, baseURL, request);

  // One good call, so it has been heard from.
  const said = await speaking.get('/client/notifications');

  expect(said.status(), await said.text()).toBe(200);

  const page = await admin.context.newPage();

  await page.goto(SCREEN);

  // Listed among every site, and not among the ones needing somebody. Both
  // halves matter: a queue nobody can see the full list behind is a queue you
  // cannot tell from a broken check.
  await expect(page.locator(`[data-bwx-sync-row="${site.id}"]`)).toBeVisible();
  await expect(page.locator(`[data-bwx-sync-site="${site.id}"]`)).toHaveCount(0);

  await page.close();
  await admin.context.close();
});

test('a site whose key stopped working appears with what went wrong and what to try', async ({
  browser,
  baseURL,
  request,
}) => {
  test.slow();

  const { admin, site, speaking } = await withConnectedSite(browser, baseURL, request);

  /*
   * The commonest real fault, produced the real way: a request that is
   * perfectly formed and signed with a key the studio does not hold. Nothing is
   * written into the record by hand, so what the screen shows is what a site
   * failing in the field would actually put there.
   */
  const wrong = asSite(request, 'not-the-key-this-site-was-given', speaking.registrySiteId);
  const refused = await wrong.get('/client/notifications');

  expect(refused.status()).toBe(401);

  const page = await admin.context.newPage();

  await page.goto(SCREEN);

  const entry = page.locator(`[data-bwx-sync-site="${site.id}"]`);

  await expect(entry).toBeVisible();
  await expect(entry).toHaveAttribute('data-bwx-sync-reasons', /broken/);

  // Enough to act on: what the site said, and what to do about it.
  await expect(entry).toContainText('Failing');
  await expect(entry).toContainText('key was rotated here and not on the site');

  await page.close();
  await admin.context.close();
});

test('the queue empties itself when the site starts working again', async ({
  browser,
  baseURL,
  request,
}) => {
  test.slow();

  const { admin, site, speaking } = await withConnectedSite(browser, baseURL, request);
  const wrong = asSite(request, 'not-the-key-this-site-was-given', speaking.registrySiteId);

  expect((await wrong.get('/client/notifications')).status()).toBe(401);

  const page = await admin.context.newPage();

  await page.goto(SCREEN);
  await expect(page.locator(`[data-bwx-sync-site="${site.id}"]`)).toBeVisible();

  // The site is fixed, which here means it calls again with the key it was
  // given. Nothing clears the queue by hand, because nothing can: the state is
  // worked out from the record every time somebody looks.
  expect((await speaking.get('/client/notifications')).status()).toBe(200);

  await page.goto(SCREEN);
  await expect(page.locator(`[data-bwx-sync-site="${site.id}"]`)).toHaveCount(0);
  await expect(page.locator(`[data-bwx-sync-row="${site.id}"]`)).toHaveAttribute(
    'data-bwx-sync-state',
    'connected'
  );

  await page.close();
  await admin.context.close();
});

test('the day’s list says the same thing the sync screen does', async ({
  browser,
  baseURL,
  request,
}) => {
  test.slow();

  const { admin, site, speaking } = await withConnectedSite(browser, baseURL, request);
  const wrong = asSite(request, 'not-the-key-this-site-was-given', speaking.registrySiteId);

  expect((await wrong.get('/client/notifications')).status()).toBe(401);

  /*
   * There is one definition of a connection being in trouble and both screens
   * read it. This is the assertion that keeps it that way: a board saying a site
   * is fine while the screen next door says it is broken is the disagreement the
   * shared module exists to prevent.
   */
  const list = await admin.api.get('/standup');
  const card = (list.cards ?? []).find(
    (one) => 'needs-intervention' === one.rule && one.detail?.about === site.id
  );

  expect(card, 'the broken site is on the day’s list too').toBeTruthy();
  expect(card.detail.reasons).toContain('broken');

  // Carrying the same sentence, not a second wording of it.
  expect(card.detail.detail).toContain('Failing');

  await admin.context.close();
});
