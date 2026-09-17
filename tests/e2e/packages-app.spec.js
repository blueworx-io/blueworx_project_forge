import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makePackage } from './helpers/forge.js';

// The package catalogue in the app: see what is on offer, add one, revise
// it as a new version, retire and restore, reorder. Names carry a run id
// because the instance is reused between runs and already holds packages.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

test.describe.configure({ mode: 'serial' });

let admin;
let seeded;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  seeded = await makePackage(admin.api, `Seeded ${RUN_ID}`, { hours: 10, price: 900 });
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-packages').click();
  await expect(page.getByTestId('bwx-packages')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-packages-count')).toBeVisible({ timeout: 30_000 });
});

/** The catalogue row for one package, found by its name. */
function rowFor(page, name) {
  return page.getByTestId('bwx-packages-list').locator('tbody tr', { hasText: name });
}

test('the rail offers Packages under Insight, and the catalogue lists what is on offer', async ({ page }) => {
  await expect(page.locator('h1')).toHaveText('Support packages');

  const row = rowFor(page, seeded.name);
  await expect(row).toBeVisible();
  await expect(row).toContainText('On the shelf');
  await expect(row).toContainText('10h');
  await expect(row).toContainText('v1');
});

test('a package is added from a panel and appears on the shelf as version 1', async ({ page }) => {
  const name = `Added ${RUN_ID}`;

  await page.getByTestId('bwx-packages-add').click();
  const form = page.getByTestId('bwx-packages-form');
  await expect(form).toBeVisible();

  await form.getByTestId('bwx-packages-form-name').fill(name);
  await form.getByTestId('bwx-packages-form-hours').fill('20');
  await form.getByTestId('bwx-packages-form-price').fill('2000');
  await form.getByTestId('bwx-packages-form-months').fill('6');
  await form.getByTestId('bwx-packages-form-terms').fill('Twenty hours over six months.');
  await form.getByTestId('bwx-packages-form-save').click();

  await expect(form).toBeHidden();
  const row = rowFor(page, name);
  await expect(row).toBeVisible();
  await expect(row).toContainText('20h');
  await expect(row).toContainText('GBP 2,000');
  await expect(row).toContainText('6 months');
  await expect(row).toContainText('v1');
});

test('a package with no name is refused in the form, with the reason', async ({ page }) => {
  await page.getByTestId('bwx-packages-add').click();
  const form = page.getByTestId('bwx-packages-form');

  await form.getByTestId('bwx-packages-form-hours').fill('5');
  await form.getByTestId('bwx-packages-form-save').click();

  await expect(form.getByTestId('bwx-packages-form-notice')).toContainText('needs a name');
  await form.getByTestId('bwx-packages-form-cancel').click();
  await expect(form).toBeHidden();
});

test('picking a package shows every version; revising writes the next one and keeps the last', async ({ page }) => {
  await rowFor(page, seeded.name).click();

  const selected = page.getByTestId('bwx-packages-selected');
  await expect(selected).toHaveAttribute('data-package', seeded.id);
  await expect(selected.getByTestId('bwx-packages-history').locator('tbody tr')).toHaveCount(1);

  await page.getByTestId('bwx-packages-revise').click();
  const form = page.getByTestId('bwx-packages-form');
  await expect(form.getByTestId('bwx-packages-form-hint')).toContainText('version 2');
  await expect(form.getByTestId('bwx-packages-form-hours')).toHaveValue('10');

  await form.getByTestId('bwx-packages-form-hours').fill('12');
  await form.getByTestId('bwx-packages-form-save').click();

  await expect(form).toBeHidden();
  await expect(rowFor(page, seeded.name)).toContainText('v2');
  await expect(rowFor(page, seeded.name)).toContainText('12h');

  const history = selected.getByTestId('bwx-packages-history').locator('tbody tr');
  await expect(history).toHaveCount(2);
  await expect(history.filter({ hasText: 'v1' })).toContainText('10h');
  await expect(history.filter({ hasText: 'v2' })).toContainText('12h');
});

test('revising without changing anything writes no version, and says so', async ({ page }) => {
  await rowFor(page, seeded.name).click();
  await page.getByTestId('bwx-packages-revise').click();
  await page.getByTestId('bwx-packages-form-save').click();

  await expect(page.getByTestId('bwx-packages-form')).toBeHidden();
  await expect(page.getByTestId('bwx-packages-notice')).toContainText('Nothing changed');
  await expect(rowFor(page, seeded.name)).toContainText('v2');
});

test('a package is retired, and put back on the shelf', async ({ page }) => {
  await rowFor(page, seeded.name).click();

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('bwx-packages-status').click();
  await expect(rowFor(page, seeded.name)).toContainText('Retired');
  await expect(page.getByTestId('bwx-packages-status')).toHaveText('Put back on the shelf');

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('bwx-packages-status').click();
  await expect(rowFor(page, seeded.name)).toContainText('On the shelf');
  await expect(page.getByTestId('bwx-packages-status')).toHaveText('Retire');
});

test('a package moves up and down the catalogue, and the order is kept', async ({ page }) => {
  // Two of this run's own, added last, so they are neighbours at the end.
  const a = await makePackage(admin.api, `Order A ${RUN_ID}`);
  const b = await makePackage(admin.api, `Order B ${RUN_ID}`);
  // The rail's own click never writes the screen into the URL, so a bare
  // reload would land back on the default screen (see the availability
  // spec's same pattern) — go by a link with the screen in the hash first.
  await page.goto('/blueworx-forge/#screen=packages');
  await page.reload();
  await expect(page.getByTestId('bwx-packages-count')).toBeVisible({ timeout: 30_000 });

  const names = () => page.getByTestId('bwx-packages-list').locator('tbody tr td:first-child + td').allTextContents();
  const indexOf = async (name) => (await names()).findIndex((text) => text.includes(name));

  expect(await indexOf(a.name)).toBeLessThan(await indexOf(b.name));

  await rowFor(page, b.name).getByTestId('bwx-packages-up').click();
  await expect.poll(async () => (await indexOf(b.name)) < (await indexOf(a.name))).toBe(true);

  await page.goto('/blueworx-forge/#screen=packages');
  await page.reload();
  await expect(page.getByTestId('bwx-packages-count')).toBeVisible({ timeout: 30_000 });
  expect(await indexOf(b.name)).toBeLessThan(await indexOf(a.name));

  await rowFor(page, b.name).getByTestId('bwx-packages-down').click();
  await expect.poll(async () => (await indexOf(a.name)) < (await indexOf(b.name))).toBe(true);

  // The last row cannot go further down.
  await expect(rowFor(page, b.name).getByTestId('bwx-packages-down')).toBeDisabled();
});

test('a key on an ordering button moves the row and never selects it', async ({ page }) => {
  const c = await makePackage(admin.api, `Order C ${RUN_ID}`);
  const d = await makePackage(admin.api, `Order D ${RUN_ID}`);
  await page.goto('/blueworx-forge/#screen=packages');
  await page.reload();
  await expect(page.getByTestId('bwx-packages-count')).toBeVisible({ timeout: 30_000 });

  const names = () => page.getByTestId('bwx-packages-list').locator('tbody tr td:first-child + td').allTextContents();
  const indexOf = async (name) => (await names()).findIndex((text) => text.includes(name));

  expect(await indexOf(c.name)).toBeLessThan(await indexOf(d.name));
  await expect(page.getByTestId('bwx-packages-selected')).toHaveCount(0);

  await rowFor(page, d.name).getByTestId('bwx-packages-up').focus();
  await page.keyboard.press('Enter');
  await expect.poll(async () => (await indexOf(d.name)) < (await indexOf(c.name))).toBe(true);

  // The key belonged to the button, not the row, so nothing got selected.
  await expect(page.getByTestId('bwx-packages-selected')).toHaveCount(0);
});
