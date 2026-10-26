import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, onSupport, hourLedger, PASSWORD } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGoogleStub, removeGoogleStub, putEvents, timedEvent } from './helpers/google.js';

// #606, in the app: a person connects Google Calendar from their own page and
// settles a synced meeting from the standup, choosing its client and whether
// its time comes off support. Google is the stand-in from
// tests/support/google-stub.php.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `${Date.now()}${Math.floor(Math.random() * 1e4)}`;
const YESTERDAY = new Date(Date.now() - 86400000).toISOString().slice(0, 10);

test.describe.configure({ mode: 'serial' });

let admin;
let site;
let client;
let person;
const who = `gu${RUN}`;

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGoogleStub();

  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site, client } = await makeSite(admin.api, 'Google app', RUN));
  await onSupport(admin, site.id, 200);
  person = await makePerson(admin.api, client.id, 'staff', who);

  const saved = await admin.api.post('/google/settings', { client_id: `client-${RUN}`, client_secret: `secret-${RUN}` });
  expect(saved.status(), await saved.text()).toBe(200);
});

test.afterAll(async () => {
  await admin?.context.close();
  removeGoogleStub();
});

test('a person connects Google Calendar from their own page, then settles a meeting from the standup', async ({ page }) => {
  test.slow();

  await putEvents(page.request, who, [
    timedEvent(`ev${RUN}`, `uid${RUN}@google.com`, `${YESTERDAY}T12:00:00Z`, 45, { summary: `Client call ${RUN}` }),
  ]);
  await page.request.post('/wp-json/bwx-forge-test/v1/google/next', { data: { who } });

  await signIn(page, person.login, PASSWORD);
  // The app reads its hash once, as it loads, so each screen is a fresh load.
  await page.goto('/blueworx-forge/#screen=profile');
  await page.reload();

  const panel = page.getByTestId('bwx-profile-google');
  await expect(panel).toHaveAttribute('data-connected', 'no', { timeout: 60000 });
  await page.getByTestId('bwx-profile-google-connect').click();

  await expect(page.getByTestId('bwx-profile-google-notice')).toContainText('Google Calendar is connected', { timeout: 120000 });
  await expect(page.getByTestId('bwx-profile-google-status')).toContainText(`Connected as ${who}@studio.test`);

  await page.goto('/blueworx-forge/#screen=standup');
  await page.reload();
  const line = page.getByTestId('bwx-standup-settle').getByTestId('bwx-diary-entry').filter({ hasText: `Client call ${RUN}` });
  await expect(line).toBeVisible({ timeout: 60000 });

  const held = line.getByTestId('bwx-google-settle-held');
  await expect(held).toBeDisabled();

  await line.getByTestId('bwx-google-settle-client').selectOption(site.id);
  await line.getByTestId('bwx-google-settle-support').check();
  await held.click();

  await expect(line).toHaveCount(0, { timeout: 60000 });

  const ledger = await hourLedger(admin, site.id);
  const usage = ledger.entries.filter(([type, , source]) => 'meeting-usage' === type && source.startsWith('google-meeting:'));
  expect(usage).toHaveLength(1);
  expect(Math.abs(usage[0][1])).toBe(1);
});

test('a Google meeting at 1pm sits at 1pm on My tasks (#654)', async ({ page }) => {
  test.slow();

  await signIn(page, person.login, PASSWORD);
  await page.goto('/blueworx-forge/#screen=mytasks');

  // 1pm today where the browser is, which is the day My tasks draws.
  const onePm = await page.evaluate(() => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 13, 0).toISOString();
  });

  await putEvents(page.request, who, [timedEvent(`one${RUN}`, `one${RUN}@google.com`, onePm, 60, { summary: `One o'clock ${RUN}` })]);
  const synced = await page.request.post('/wp-json/blueworx-forge/v1/me/google/sync', {
    data: { force: true },
    headers: { 'X-WP-Nonce': await page.evaluate(() => window.bwxForgeData?.nonce ?? '') },
  });
  expect(synced.status(), await synced.text()).toBe(200);

  await page.reload();
  const meeting = page.getByTestId('bwx-day-meeting').filter({ hasText: `One o'clock ${RUN}` });
  await expect(meeting).toBeVisible({ timeout: 90000 });

  // 80px an hour from 7am: six hours down.
  await expect(meeting.locator('xpath=ancestor::div[contains(@class, "bwx-day-item")][1]')).toHaveCSS('top', '480px');
});
