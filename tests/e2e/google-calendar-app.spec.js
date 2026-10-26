import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, onSupport, hourLedger, PASSWORD } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGoogleStub, removeGoogleStub, putEvents, timedEvent, connectGoogle } from './helpers/google.js';

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

// #654. First, and with a person of its own, so it stands on its own.
test('a Google meeting at 1pm sits at 1pm on My tasks (#654)', async ({ browser, baseURL }) => {
  test.slow();

  const planner = `gp${RUN}`;
  const them = await makePerson(admin.api, client.id, 'staff', planner);
  const as = await signedIn(browser, baseURL, them.login, PASSWORD);
  const page = await as.context.newPage();

  expect(await connectGoogle(page, as.api, planner)).toContain('google=connected');

  // 1pm today where the browser is, which is the day My tasks draws.
  const onePm = await page.evaluate(() => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 13, 0).toISOString();
  });

  await putEvents(page.request, planner, [timedEvent(`one${RUN}`, `one${RUN}@google.com`, onePm, 60, { summary: `One o'clock ${RUN}` })]);
  const synced = await as.api.post('/me/google/sync', { force: true });
  expect(synced.status(), await synced.text()).toBe(200);

  // The app reads its hash once, as it loads.
  await page.goto('/blueworx-forge/#screen=mytasks');
  await page.reload();
  const meeting = page.getByTestId('bwx-day-meeting').filter({ hasText: `One o'clock ${RUN}` });
  await expect(meeting).toBeVisible({ timeout: 90000 });

  // 80px an hour from 7am: six hours down.
  await expect(meeting.locator('xpath=ancestor::div[contains(@class, "bwx-day-item")][1]')).toHaveCSS('top', '480px');

  await as.context.close();
});

test('a person connects Google Calendar from their own page, then settles a meeting from the standup', async ({ page }) => {
  test.slow();

  await putEvents(page.request, who, [
    timedEvent(`ev${RUN}`, `uid${RUN}@google.com`, `${YESTERDAY}T12:00:00Z`, 45, { summary: `Client call ${RUN}`, attendees: [{ email: `guest${RUN}@example.test` }] }),
  ]);
  // Backdated, so yesterday's call comes across (#657).
  await page.request.post('/wp-json/bwx-forge-test/v1/google/next', { data: { who, since: '2000-01-01' } });

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


// #657. The Meetings page: every client with its hours, and a client's page
// shows its Google meetings beside the manual ones, settled from there.
test('the Meetings page lists each client\'s hours, and a client\'s page settles its Google meetings', async ({ browser, baseURL }) => {
  test.slow();

  const handle = `gm${RUN}`;
  const them = await makePerson(admin.api, client.id, 'staff', handle);
  const as = await signedIn(browser, baseURL, them.login, PASSWORD);
  await putEvents(as.context.request, handle, [
    timedEvent(`pg${RUN}`, `pg${RUN}@google.com`, `${YESTERDAY}T11:00:00Z`, 60, { summary: `Page call ${RUN}`, attendees: [{ email: `guest${RUN}@example.test` }] }),
  ]);
  const own = await as.context.newPage();
  expect(await connectGoogle(own, as.api, handle)).toContain('google=connected');
  await as.context.close();

  const call = (await admin.api.get(`/calendar?from=${YESTERDAY}&to=${YESTERDAY}`)).entries.find((entry) => entry.title === `Page call ${RUN}`);
  const given = await admin.api.post(`/google-meetings/${call.google_id}/settle`, { client_site_id: site.id, off_support: false });
  expect(given.status(), await given.text()).toBe(200);

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-meetings').click();
  await page.getByTestId('bwx-client-choice').selectOption('all');

  const row = page.locator(`[data-testid="bwx-meetings-all-row"][data-site="${site.id}"]`);
  await expect(row).toBeVisible({ timeout: 60000 });
  await expect(row).toHaveAttribute('data-hours', '1');
  await row.click();
  await expect(page.getByTestId('bwx-meetings-standing')).toBeVisible({ timeout: 60000 });

  const past = page.getByTestId('bwx-meetings-past-table').getByRole('row').filter({ hasText: `Page call ${RUN}` });
  await expect(past.getByTestId('bwx-meetings-meeting')).toHaveAttribute('data-source', 'google');
  await expect(past).toContainText('Google');
  await expect(past.getByTestId('bwx-meetings-move')).toHaveCount(0);

  await past.getByTestId('bwx-meetings-settle').click();
  const panel = page.getByTestId('bwx-google-meeting');
  await expect(panel).toBeVisible();
  await panel.getByTestId('bwx-google-settle-held').click();

  await expect(past.getByTestId('bwx-meetings-meeting')).toHaveAttribute('data-status', 'held', { timeout: 60000 });
  await page.close();
});
