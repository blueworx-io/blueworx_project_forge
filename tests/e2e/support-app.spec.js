import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePackage } from './helpers/forge.js';

// The support screen in the app (PR 5 of the move out of WordPress admin):
// pick a site, see what it is on, put it on a package with the sum shown
// before anything is written, sell it hours, correct the record, suspend,
// resume, cancel. The site and the package are this run's own because the
// instance is reused between runs.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const HOURS = 40;

test.describe.configure({ mode: 'serial' });

let admin;
let site;
let pkg;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await makeSite(admin.api, 'Support app', RUN_ID));
  pkg = await makePackage(admin.api, `Support app ${RUN_ID}`, { hours: HOURS, price: 1000 });
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-support').click();
  await expect(page.getByTestId('bwx-support')).toBeVisible({ timeout: 30_000 });
});

/** Picks this run's site and waits for its position to be drawn. */
async function onSite(page) {
  const picker = page.getByTestId('bwx-support-site');
  await expect(picker).toBeEnabled({ timeout: 30_000 });
  await picker.selectOption(site.id);
  await expect(page.getByTestId('bwx-support-state')).toBeVisible({ timeout: 30_000 });
}

/** The ledger row for one entry, found by what it says. */
function ledgerRow(page, text) {
  return page.getByTestId('bwx-support-ledger').locator('tbody tr', { hasText: text });
}

test('the rail offers Support under Clients, and a site nobody has put on a package says so', async ({ page }) => {
  await expect(page.locator('h1')).toHaveText('Support');
  await expect(page.getByTestId('bwx-support')).toContainText('Choose a site to see what it is on');

  await onSite(page);

  const state = page.getByTestId('bwx-support-state');
  await expect(state).toHaveAttribute('data-state', 'none');
  await expect(state).toContainText('No support package');
  await expect(page.getByTestId('bwx-support-balance')).toHaveAttribute('data-balance', '0');
  await expect(page.getByTestId('bwx-support-assign')).toBeVisible();
  await expect(page.getByTestId('bwx-support-suspend')).toHaveCount(0);
  await expect(page.getByTestId('bwx-support-cancel')).toHaveCount(0);
  await expect(page.getByTestId('bwx-support-periods')).toContainText('Never on a package');
  await expect(page.getByTestId('bwx-support-ledger')).toContainText('Nothing on the ledger');
});

test('assigning shows the sum before it is written, and the answer is the new position', async ({ page }) => {
  await onSite(page);
  await page.getByTestId('bwx-support-assign').click();

  const form = page.getByTestId('bwx-support-assign-form');
  await expect(form).toBeVisible();

  // Nothing can be saved until the server has said what saving would grant.
  const save = form.getByTestId('bwx-support-assign-save');
  await form.getByTestId('bwx-support-assign-package').selectOption(pkg.current.id);
  await expect(form.getByTestId('bwx-support-assign-preview')).toContainText(`${HOURS}h`);
  await expect(form.getByTestId('bwx-support-assign-preview')).toContainText('1,000');
  await expect(save).toHaveText(`Assign ${HOURS}h`);
  await expect(save).toBeEnabled();

  await form.getByTestId('bwx-support-assign-note').fill('From the app');
  await save.click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-support-state')).toHaveAttribute('data-state', 'active');
  await expect(page.getByTestId('bwx-support-state')).toContainText('On support');
  await expect(page.getByTestId('bwx-support-balance')).toHaveAttribute('data-balance', String(HOURS));
  // A covered site can still change package mid-term, as it can on the admin page.
  await expect(page.getByTestId('bwx-support-assign')).toHaveText('Change package');
  await expect(page.getByTestId('bwx-support-suspend')).toBeVisible();
  await expect(page.getByTestId('bwx-support-cancel')).toBeVisible();

  const period = page.getByTestId('bwx-support-periods').locator('tbody tr');
  await expect(period).toHaveCount(1);
  await expect(period).toContainText(pkg.name);
  await expect(period).toContainText('On support');
  await expect(ledgerRow(page, 'Package hours')).toContainText(`+${HOURS}h`);
});

test('selling hours adds them to the balance and to the ledger', async ({ page }) => {
  await onSite(page);
  await page.getByTestId('bwx-support-top-up').click();

  const form = page.getByTestId('bwx-support-topup-form');
  await expect(form).toContainText('twelve months');
  await form.getByTestId('bwx-support-topup-hours').fill('5');
  await form.getByTestId('bwx-support-topup-reason').fill('Five more');
  await form.getByTestId('bwx-support-topup-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-support-balance')).toHaveAttribute('data-balance', String(HOURS + 5));
  const row = ledgerRow(page, 'Five more');
  await expect(row).toContainText('Bought hours');
  await expect(row).toContainText('+5h');
});

test('an adjustment needs a reason, and with one it moves the balance', async ({ page }) => {
  await onSite(page);
  await page.getByTestId('bwx-support-adjust').click();

  const form = page.getByTestId('bwx-support-adjust-form');
  await form.getByTestId('bwx-support-adjust-hours').fill('-2');
  await form.getByTestId('bwx-support-adjust-save').click();
  await expect(form.getByTestId('bwx-support-adjust-notice')).toHaveText('Say why.');
  await expect(form).toBeVisible();

  await form.getByTestId('bwx-support-adjust-reason').fill('Two written off');
  await form.getByTestId('bwx-support-adjust-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-support-balance')).toHaveAttribute('data-balance', String(HOURS + 3));
  const row = ledgerRow(page, 'Two written off');
  await expect(row).toContainText('Adjustment');
  await expect(row).toContainText('-2h');
});

test('suspending stops the hours being spent, and resuming starts them again', async ({ page }) => {
  await onSite(page);
  await page.getByTestId('bwx-support-suspend').click();

  const form = page.getByTestId('bwx-support-suspend-form');
  await form.getByTestId('bwx-support-suspend-note').fill('On hold');
  await form.getByTestId('bwx-support-suspend-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-support-state')).toHaveAttribute('data-state', 'suspended');
  await expect(page.getByTestId('bwx-support-suspend')).toHaveCount(0);
  // The hours are untouched by a suspension.
  await expect(page.getByTestId('bwx-support-balance')).toHaveAttribute('data-balance', String(HOURS + 3));

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('bwx-support-resume').click();

  await expect(page.getByTestId('bwx-support-state')).toHaveAttribute('data-state', 'active');
  await expect(page.getByTestId('bwx-support-resume')).toHaveCount(0);
  await expect(page.getByTestId('bwx-support-suspend')).toBeVisible();
});

test('cancelling ends the period, freezes the hours, and offers a package again', async ({ page }) => {
  await onSite(page);

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('bwx-support-cancel').click();

  // Cancelled is why the period ended; the site's own position is lapsed.
  await expect(page.getByTestId('bwx-support-state')).toHaveAttribute('data-state', 'lapsed');
  await expect(page.getByTestId('bwx-support-state')).toContainText('Lapsed');
  await expect(page.getByTestId('bwx-support-balance')).toHaveAttribute('data-balance', String(HOURS + 3));
  await expect(page.getByTestId('bwx-support-assign')).toHaveText('Assign a package');
  await expect(page.getByTestId('bwx-support-cancel')).toHaveCount(0);
  // Assign, suspend and resume each opened a period; the last one is the
  // one cancel closed, and the table says why it ended.
  const periods = page.getByTestId('bwx-support-periods').locator('tbody tr');
  await expect(periods).toHaveCount(3);
  await expect(periods.filter({ hasText: 'cancelled' })).toHaveCount(1);
});

test('a link with the screen and site in the hash lands on them', async ({ page }) => {
  await page.goto(`/blueworx-forge/#screen=support&site=${site.id}`);
  // The beforeEach already opened the app without a hash, so this is a
  // same-document fragment change, not a real navigation; a link from
  // outside always arrives as a fresh load, so force one to match that.
  await page.reload();

  await expect(page.getByTestId('bwx-support')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-support-state')).toHaveAttribute('data-state', 'lapsed', { timeout: 30_000 });
  await expect(page.getByTestId('bwx-support-site')).toHaveValue(site.id);
  // Read once and cleared, so a reload is a plain reload.
  expect(new URL(page.url()).hash).toBe('');
});
