import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeItem, makePerson, makeSite, PASSWORD } from './helpers/forge.js';

// One Client picker in the top bar (#402): every client screen follows it,
// and it is remembered.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

/** Two clients with one piece of work each. */
async function twoClients(api, label) {
  const one = await makeSite(api, `${label} One`, RUN_ID);
  const two = await makeSite(api, `${label} Two`, RUN_ID);
  const first = (await (await makeItem(api, one.site.id, { title: `First ${label} ${RUN_ID}` })).json()).item;
  const second = (await (await makeItem(api, two.site.id, { title: `Second ${label} ${RUN_ID}` })).json()).item;

  return { one, two, first, second };
}

/** Opens the app with nothing remembered, as a first visit. */
async function freshVisit(page) {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.evaluate(() => window.localStorage.removeItem('bwx-forge-site'));
  await page.goto('/blueworx-forge/');
}

test.describe('the one Client picker', () => {
  test('narrows the board, is remembered, and follows a site link', async ({ browser, baseURL, page }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const world = await twoClients(admin.api, 'Board');

    await freshVisit(page);
    await page.waitForSelector('[data-testid="bwx-board"]');

    const picker = page.getByTestId('bwx-client-choice');
    // A first visit opens on every client.
    await expect(picker).toHaveValue('all');
    expect((await picker.locator('option').allTextContents())[0]).toBe('All clients');
    await expect(page.getByTestId('bwx-site')).toHaveCount(0);

    const first = page.locator(`[data-testid="bwx-card"][data-item="${world.first.id}"]`);
    const second = page.locator(`[data-testid="bwx-card"][data-item="${world.second.id}"]`);

    await picker.selectOption(world.one.site.id);
    await expect(first).toBeVisible();
    await expect(second).toHaveCount(0);

    // A reload keeps it.
    await page.reload();
    await page.waitForSelector('[data-testid="bwx-board"]');
    await expect(picker).toHaveValue(world.one.site.id);
    await expect(first).toBeVisible();
    await expect(second).toHaveCount(0);

    // A link naming the other site opens on it, and moves the top bar.
    await page.goto('/wp-admin/');
    await page.goto(`/blueworx-forge/#screen=work&site=${world.two.site.id}`);
    await page.waitForSelector('[data-testid="bwx-board"]');
    await expect(picker).toHaveValue(world.two.site.id);
    await expect(second).toBeVisible();
    await expect(first).toHaveCount(0);

    // And the link's site is remembered.
    await page.goto('/blueworx-forge/');
    await expect(picker).toHaveValue(world.two.site.id);

    await admin.context.close();
  });

  test('a remembered site that no longer exists falls back to All clients', async ({ page }) => {
    await signIn(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/blueworx-forge/');
    await page.evaluate(() => window.localStorage.setItem('bwx-forge-site', 'cs_gone_for_good'));
    await page.goto('/blueworx-forge/');
    await page.waitForSelector('[data-testid="bwx-board"]');

    await expect(page.getByTestId('bwx-client-choice')).toHaveValue('all');
  });
});

test.describe('Support with All clients', () => {
  test('lists every client\'s hours, and a row opens that client', async ({ browser, baseURL, page }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const world = await twoClients(admin.api, 'Support');

    await freshVisit(page);
    await page.getByTestId('bwx-screen-support').click();

    const table = page.getByTestId('bwx-support-summary');
    await expect(table).toBeVisible({ timeout: 30_000 });

    const row = table.locator(`[data-testid="bwx-support-summary-row"][data-site="${world.one.site.id}"]`);
    await expect(row).toBeVisible();
    await expect(table.locator(`[data-testid="bwx-support-summary-row"][data-site="${world.two.site.id}"]`)).toBeVisible();
    await expect(table.locator('thead')).toContainText('Hours this period');

    await row.click();
    await expect(page.getByTestId('bwx-client-choice')).toHaveValue(world.one.site.id);
    await expect(page.getByTestId('bwx-support-state')).toBeVisible({ timeout: 30_000 });
    await expect(table).toHaveCount(0);

    await admin.context.close();
  });

  test('the summary is the administrator\'s only', async ({ browser, baseURL }) => {
    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const where = await makeSite(admin.api, 'Support staff', RUN_ID);
    const person = await makePerson(admin.api, where.client.id, 'staff', `supsum${RUN_ID.replace('-', '')}`);
    const staff = await signedIn(browser, baseURL, person.login, PASSWORD);

    const answer = await staff.api.request.get('/wp-json/blueworx-forge/v1/support-summary', { headers: staff.api.headers });
    expect(answer.status()).toBe(403);

    const allowed = await admin.api.request.get('/wp-json/blueworx-forge/v1/support-summary', { headers: admin.api.headers });
    expect(allowed.status()).toBe(200);
    const body = await allowed.json();
    expect(body.sites.some((one) => one.site_id === where.site.id)).toBe(true);

    await staff.context.close();
    await admin.context.close();
  });
});

test('Meetings follows the picker: one client is its meetings, All is every client', async ({ browser, baseURL, page }) => {
  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await makeSite(admin.api, 'Meetings pick', RUN_ID);

  await freshVisit(page);
  await page.getByTestId('bwx-client-choice').selectOption(where.site.id);
  await page.getByTestId('bwx-screen-meetings').click();
  await expect(page.getByTestId('bwx-meetings-standing')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-meetings-all')).toHaveCount(0);

  await page.getByTestId('bwx-client-choice').selectOption('all');
  await expect(page.getByTestId('bwx-meetings-all')).toBeVisible({ timeout: 30_000 });

  await admin.context.close();
});
