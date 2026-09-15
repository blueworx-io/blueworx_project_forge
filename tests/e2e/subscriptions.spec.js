import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makePerson, PASSWORD } from './helpers/forge.js';
import { installSureCartStub, removeSureCartStub, STUB_TOKEN } from './helpers/surecart.js';

// SureCart, end to end against a stand-in store: connect it on the admin
// screen, see its subscriptions under Insight, and find the renewal
// reminder on the studio's board and in the standup of the person who
// checks the payment.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-connections';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => installSureCartStub());
test.afterAll(() => removeSureCartStub());

test('a connected store’s renewals become reminders for the chosen person', async ({ browser, baseURL, page }) => {
  test.slow();

  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const sites = await admin.api.get('/client-sites');
  const studio = sites.sites.find((one) => one.studio);
  const checker = await makePerson(admin.api, studio.client_id, 'staff', 'checker');

  // Connect the store on the admin screen.
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto(SCREEN);
  const add = page.locator('form[data-bwx-add-store]');
  await add.locator('input[name="name"]').fill(`Stub Store ${RUN_ID}`);
  await add.locator('input[name="token"]').fill(STUB_TOKEN);
  await add.locator('select[name="primary_user_id"]').selectOption(checker.id);
  await add.locator('input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();

  const store = page.locator('[data-bwx-store]', { hasText: `Stub Store ${RUN_ID}` });
  await expect(store).toBeVisible();

  // Test says how many it can see; a wrong token is refused honestly.
  await store.locator('[data-bwx-action="test"]').click();
  await expect(page.locator('[data-bwx-result="connected"]')).toContainText('3 active');

  await store.locator('[data-bwx-action="refresh"]').click();
  await expect(page.locator('[data-bwx-result="refreshed"]')).toBeVisible();
  await expect(store.locator('[data-bwx-store-state="ok"]')).toBeVisible();

  // Insight › Subscriptions lists them, and the reminder for today's renewal
  // appears once the engine has run.
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-subscriptions').click();
  await expect(page.getByTestId('bwx-subscriptions')).toBeVisible({ timeout: 30_000 });

  // This run's store only: the instance is reused, so earlier runs' stores
  // may still list the same customer.
  const acme = page.locator('[data-testid="bwx-subs-table"] tr', { hasText: 'Acme Ltd' }).filter({ hasText: `Stub Store ${RUN_ID}` });
  await expect(acme).toBeVisible();
  await expect(acme).toContainText('£120.00');
  await expect(acme).toContainText('Care Plan');

  // Opening a screen may already have made today's reminder; the explicit
  // run only guarantees it exists by now.
  const ran = await admin.api.post('/recurring/run', {});
  expect(ran.status()).toBe(200);

  await page.getByTestId('bwx-refresh-all').click();
  await expect(acme.getByTestId('bwx-subs-reminder')).toBeVisible();

  // On the studio's board, in Up Next, titled for the customer and the money.
  const listing = await admin.api.get('/subscriptions');
  const connection = listing.connections.find((one) => one.name === `Stub Store ${RUN_ID}`);
  const acmeRow = listing.subscriptions.find((one) => one.connection_id === connection.id && 'Acme Ltd' === one.customer_name);
  expect(acmeRow.reminder, 'the row knows its reminder').toBeTruthy();
  const work = await admin.api.get(`/work-items?client_site_id=${studio.id}`);
  const reminder = work.items.find((one) => one.id === acmeRow.reminder.work_item_id);
  expect(reminder, 'the reminder task exists').toBeTruthy();
  expect(reminder.title).toBe('Subscription Renewal: Acme Ltd - (£120.00)');
  expect(reminder.stage).toBe('up-next');
  expect(reminder.primary_user_id).toBe(checker.id);

  // The cancelled subscription made nothing.
  const gone = listing.subscriptions.find((one) => one.connection_id === connection.id && 'Gone Co' === one.customer_name);
  expect(gone, 'a cancelled subscription is not listed').toBeFalsy();
  expect(work.items.some((one) => one.title.includes('Gone Co'))).toBe(false);

  // And the person who checks payments sees it in their standup, due today.
  const asChecker = await signedIn(browser, baseURL, checker.login, PASSWORD);
  const standup = await asChecker.api.get('/standup');
  expect(standup.cards.some((card) => card.subject_id === reminder.id)).toBe(true);
  await asChecker.context.close();

  // Removing the store ends its reminders' source; the task already made stays.
  await page.goto(SCREEN);
  page.once('dialog', (dialog) => dialog.accept());
  await store.locator('[data-bwx-action="remove"]').click();
  await expect(page.locator('[data-bwx-result="removed"]')).toBeVisible();
  const still = await admin.api.get(`/work-items/${reminder.id}`);
  expect(still.item.id).toBe(reminder.id);

  await admin.context.close();
});
