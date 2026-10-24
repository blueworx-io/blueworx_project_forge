import { test, expect } from '@playwright/test';
import { signedIn, makePerson } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installCloudwaysStub, removeCloudwaysStub, cloudwaysSays, serverReads, server } from './helpers/cloudways.js';

// #621. An administrator connects Cloudways in Settings, sees every server
// and which clients sit on each, and a stopped server is flagged on their
// standup — and on nobody else's. Cloudways is the stand-in from
// tests/support/cloudways-stub.php.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `${Date.now()}${Math.floor(Math.random() * 1e4)}`;
const KEY = `good-${RUN}-wxyz`;

test.describe.configure({ mode: 'serial' });

/** Opens one screen of the app afresh: the app reads its hash once, as it loads. */
async function openScreen(page, screen) {
  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#screen=${screen}`);
}

let admin;
let asManager;


test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installCloudwaysStub();

  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  await admin.api.del('/connections/cloudways');

  const client = (await (await admin.api.post('/clients', { display_name: `Servers ${RUN}`, timezone: 'Europe/London' })).json()).client;
  await admin.api.post(`/clients/${client.id}/sites`, { name: `Acme ${RUN}`, url: `https://www.acme-${RUN}.test/` });

  const manager = await makePerson(admin.api, client.id, 'staff', `cwmanager${RUN}`);
  asManager = await signedIn(browser, baseURL, manager.login, 'forge-test-pw-4471');

  await cloudwaysSays(admin.api.request, {
    down: false,
    reset: true,
    servers: [
      server(`up${RUN}`, `Up ${RUN}`, 'running', [{ id: '1', label: 'Staging', app_fqdn: `wordpress-${RUN}.cloudwaysapps.com` }]),
      server(`down${RUN}`, `Down ${RUN}`, 'stopped', [{ id: '2', label: 'Acme live', cname: `acme-${RUN}.test` }]),
    ],
  });
});

test.afterAll(async () => {
  await admin?.api.del('/connections/cloudways');
  await admin?.context.close();
  await asManager?.context.close();
  removeCloudwaysStub();
});

test('an administrator connects Cloudways in Settings, and a refused key is not saved', async ({ page }) => {
  test.slow();

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'settings');

  const row = page.getByTestId('bwx-connection-cloudways');
  await expect(row).toHaveAttribute('data-connected', 'no', { timeout: 60000 });
  await expect(page.getByTestId('bwx-connection-surecart-link')).toHaveAttribute('href', /page=blueworx-forge-connections/);

  await page.getByTestId('bwx-connection-cloudways-connect').click();
  await page.getByLabel('Cloudways account email').fill('ops@studio.test');
  await page.getByLabel('API key').fill('bad-key');
  await page.getByTestId('bwx-cloudways-save').click();
  await expect(page.getByTestId('bwx-cloudways-notice')).toContainText('did not accept', { timeout: 60000 });
  await expect(row).toHaveAttribute('data-connected', 'no');

  await page.getByLabel('API key').fill(KEY);
  await page.getByTestId('bwx-cloudways-save').click();
  await expect(row).toHaveAttribute('data-connected', 'yes', { timeout: 60000 });
  await expect(row).toContainText('••••wxyz');

  // The key never comes back, from any route the app reads.
  const answer = await admin.api.request.get('/wp-json/blueworx-forge/v1/connections', { headers: admin.api.headers });
  expect(await answer.text()).not.toContain(KEY);
});

test('the Servers page lists each server, matches applications to clients, and asks Cloudways once', async ({ page }) => {
  test.slow();

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'servers');

  const down = page.getByTestId('bwx-server').filter({ hasText: `Down ${RUN}` });
  await expect(down).toHaveAttribute('data-state', 'stopped', { timeout: 60000 });
  await expect(down.getByTestId('bwx-server-state')).toContainText('Stopped');
  await expect(down.getByTestId('bwx-server-app')).toContainText(`Acme ${RUN}`);

  const up = page.getByTestId('bwx-server').filter({ hasText: `Up ${RUN}` });
  await expect(up).toHaveAttribute('data-state', 'running');
  await expect(up.getByTestId('bwx-server-app')).toContainText('Not matched to a client');

  // Opening it again a moment later shows the same check.
  await openScreen(page, 'servers');
  await expect(page.getByTestId('bwx-server').first()).toBeVisible({ timeout: 60000 });
  expect(await serverReads(admin.api.request)).toBe(1);
});

test('a stopped server is on the standup for an administrator, and not for a Manager', async ({ page }) => {
  test.slow();

  const subject = `server-down${RUN}`;
  const forAdmin = await admin.api.get('/standup');
  expect(forAdmin.cards.map((card) => card.subject_id)).toContain(subject);
  expect(forAdmin.cards.map((card) => card.subject_id)).not.toContain(`server-up${RUN}`);

  const forManager = await asManager.api.get('/standup');
  expect((forManager.cards ?? []).map((card) => card.subject_id)).not.toContain(subject);

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'standup');
  // The sections start folded; the studio's own problems are under their heading.
  await page.getByRole('button', { name: 'The studio itself' }).click({ timeout: 120000 });

  const card = page.locator(`[data-testid="bwx-standup-card"][data-subject="${subject}"]`);
  await expect(card).toBeVisible({ timeout: 120000 });
  await expect(card).toContainText(`Down ${RUN}`);
  await expect(card).toContainText(`Affects Acme ${RUN}`);
});

test('when Cloudways cannot be reached, servers show as unreachable and the standup keeps the last report', async ({ page }) => {
  test.slow();

  await cloudwaysSays(admin.api.request, { down: true });

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'servers');

  // The last check is under fifteen seconds old only if this ran very fast;
  // wait it out so Check now really asks.
  await page.waitForTimeout(16000);
  await page.getByTestId('bwx-servers-check').click();

  await expect(page.getByTestId('bwx-servers-error')).toContainText('Cloudways could not be asked', { timeout: 60000 });
  const down = page.getByTestId('bwx-server').filter({ hasText: `Down ${RUN}` });
  await expect(down).toHaveAttribute('data-state', 'unreachable');
  await expect(down).toContainText('Last reported stopped');

  const forAdmin = await admin.api.get('/standup');
  expect(forAdmin.cards.map((card) => card.subject_id)).toContain(`server-down${RUN}`);
  expect(forAdmin.cards.map((card) => card.subject_id)).not.toContain(`server-up${RUN}`);
});
