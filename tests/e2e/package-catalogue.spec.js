import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #145, COMM-1. Editing a package leaves every existing client's terms alone.
//
// That is the acceptance criterion, and there are no clients yet — assignment
// is #146 — so it cannot be checked by assigning one. What can be checked is
// the thing an assignment will rest on, which is the same claim one step
// earlier: a version, once written, never changes. An assignment holds a
// version id and nothing else, so if that row is immutable then a catalogue
// edit cannot reach anybody's terms, whenever assignment arrives.
//
// So the second test is the important one. It edits a package and then reads
// the old version back, field by field, to prove it is byte for byte what it
// was.
//
// The instance is kept between runs and other specs leave packages behind, so
// every assertion here is scoped to this run's own.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-packages';

/** Adds a package through the screen, the way a person would. */
async function addPackage(page, name, values = {}) {
  const terms = { hours: '10', price: '1200', currency: 'GBP', validity_months: '12', ...values };

  await page.goto(SCREEN);

  const form = page.locator('form').filter({ has: page.locator('input[value="bwx_forge_add_package"]') });

  await form.locator('input[name="name"]').fill(name);
  await form.locator('input[name="hours"]').fill(terms.hours);
  await form.locator('input[name="price"]').fill(terms.price);
  await form.locator('input[name="currency"]').fill(terms.currency);
  await form.locator('input[name="validity_months"]').fill(terms.validity_months);
  await form.locator('textarea[name="terms"]').fill(`Written for ${name}.`);
  await form.locator('#bwx-add').click();

  await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();

  const card = page.locator('[data-bwx-package]').filter({ hasText: name });

  await expect(card).toHaveCount(1);

  return card;
}

/** What one version row says, read off the history table. */
async function versionRow(card, number) {
  const row = card.locator(`[data-bwx-package-version="${number}"]`);

  return {
    name: (await row.locator('td').nth(1).textContent())?.trim(),
    hours: await row.locator('[data-bwx-hours]').getAttribute('data-bwx-hours'),
    price: await row.locator('[data-bwx-price]').getAttribute('data-bwx-price'),
    runs: (await row.locator('td').nth(4).textContent())?.trim(),
  };
}

test('a package can be added, and it starts at version one', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  const name = `Standard ${RUN_ID}`;

  const card = await addPackage(page, name);

  await expect(card).toHaveAttribute('data-bwx-version', '1');
  await expect(card).toHaveAttribute('data-bwx-status', 'active');

  expect(await versionRow(card, 1)).toEqual({
    name,
    hours: '10',
    price: '1200',
    runs: '12',
  });

  await page.close();
  await admin.context.close();
});

test('editing a package writes a new version and leaves the old one exactly as it was', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  const name = `Editable ${RUN_ID}`;

  const card = await addPackage(page, name);
  const before = await versionRow(card, 1);

  // A price rise and more hours, the ordinary reason anybody opens this screen.
  const editing = card.locator('form').filter({ has: page.locator('input[value="bwx_forge_revise_package"]') });

  await editing.locator('input[name="hours"]').fill('20');
  await editing.locator('input[name="price"]').fill('2400');
  await editing.locator('#bwx-revise').click();

  await expect(page.locator('[data-bwx-result="revised"]')).toBeVisible();

  const after = page.locator('[data-bwx-package]').filter({ hasText: name });

  await expect(after).toHaveAttribute('data-bwx-version', '2');

  // The new terms are the new terms.
  expect(await versionRow(after, 2)).toEqual({ name, hours: '20', price: '2400', runs: '12' });

  /*
   * And version one is untouched, field by field. This is the criterion: an
   * assignment holds a version id, so a version that cannot change is a
   * client's terms that cannot change.
   */
  expect(await versionRow(after, 1)).toEqual(before);

  await page.close();
  await admin.context.close();
});

test('a save that changes nothing does not write a version', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  const name = `Unchanged ${RUN_ID}`;

  const card = await addPackage(page, name);
  const editing = card.locator('form').filter({ has: page.locator('input[value="bwx_forge_revise_package"]') });

  // Opened and saved with nothing touched, which is what happens when somebody
  // checks a package rather than changes one. Six identical versions in a
  // history is a history nobody reads.
  await editing.locator('#bwx-revise').click();

  await expect(page.locator('[data-bwx-result="unchanged"]')).toBeVisible();

  const after = page.locator('[data-bwx-package]').filter({ hasText: name });

  await expect(after).toHaveAttribute('data-bwx-version', '1');
  await expect(after.locator('[data-bwx-package-version]')).toHaveCount(1);

  await page.close();
  await admin.context.close();
});

test('a package with no hours in it is refused', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  const name = `Empty ${RUN_ID}`;

  await page.goto(SCREEN);

  const form = page.locator('form').filter({ has: page.locator('input[value="bwx_forge_add_package"]') });

  await form.locator('input[name="name"]').fill(name);
  await form.locator('input[name="hours"]').fill('0');
  await form.locator('#bwx-add').click();

  await expect(page.locator('[data-bwx-result="refused"]')).toBeVisible();
  await expect(page.locator('[data-bwx-package]').filter({ hasText: name })).toHaveCount(0);

  await page.close();
  await admin.context.close();
});

test('retiring stops it being offered without touching what it has been', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();
  const name = `Retiring ${RUN_ID}`;

  const card = await addPackage(page, name);
  const before = await versionRow(card, 1);

  await card.locator('#bwx-status').click();
  await expect(page.locator('[data-bwx-result="retired"]')).toBeVisible();

  const retired = page.locator('[data-bwx-package]').filter({ hasText: name });

  await expect(retired).toHaveAttribute('data-bwx-status', 'retired');

  // The terms are exactly as they were. Retiring is about what can be sold
  // next, and says nothing about what has been.
  expect(await versionRow(retired, 1)).toEqual(before);

  // And a mis-click is undoable, in the same place.
  await retired.locator('#bwx-status').click();
  await expect(page.locator('[data-bwx-result="restored"]')).toBeVisible();
  await expect(page.locator('[data-bwx-package]').filter({ hasText: name })).toHaveAttribute(
    'data-bwx-status',
    'active'
  );

  await page.close();
  await admin.context.close();
});
