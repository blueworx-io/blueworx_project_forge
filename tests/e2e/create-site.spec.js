import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installNetworkStub, removeNetworkStub, networkState, failPeopleOnce, NETWORK_URL, NETWORK_KEY } from './helpers/network.js';

// #621, Part B. An administrator connects the multisite network in Settings
// and creates a client's site on it from the client's page: the site, the
// link back to Forge, and the client's logins, a step at a time, trying a
// failed step again. The network is the stand-in from
// tests/support/network-stub.php, which checks every signature.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `${Date.now()}${Math.floor(Math.random() * 1e4)}`;
const SLUG = `acme-${RUN}`;

test.describe.configure({ mode: 'serial' });

/** Opens one screen of the app afresh: the app reads its hash once, as it loads. */
async function openScreen(page, hash) {
  await page.goto('about:blank');
  await page.goto(`/blueworx-forge/#${hash}`);
}

let admin;
let client;
let site;
let boss;
let reader;
let staff;

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installNetworkStub();

  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  await admin.api.del('/connections/network');

  ({ site, client } = await makeSite(admin.api, 'Deploy', RUN));
  boss = await makePerson(admin.api, client.id, 'client_admin', `boss${RUN}`);
  reader = await makePerson(admin.api, client.id, 'client_viewer', `reader${RUN}`);
  staff = await makePerson(admin.api, client.id, 'staff', `staff${RUN}`);
});

test.afterAll(async () => {
  await admin?.api.del('/connections/network');
  await admin?.context.close();
  removeNetworkStub();
});

test('an administrator connects the network in Settings, and a wrong key is not saved', async ({ page }) => {
  test.slow();

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'screen=settings');

  const row = page.getByTestId('bwx-connection-network');
  await expect(row).toHaveAttribute('data-connected', 'no', { timeout: 60000 });

  // Nothing is offered on a client's site until the network is connected.
  expect((await admin.api.get(`/client-sites/${site.id}/deploy`)).available).toBe(false);

  await page.getByTestId('bwx-connection-network-connect').click();
  await page.getByLabel('Network address').fill(NETWORK_URL);
  await page.getByLabel('Network key').fill('not-the-key');
  await page.getByTestId('bwx-network-save').click();
  await expect(page.getByTestId('bwx-network-notice')).toContainText('did not accept that key', { timeout: 60000 });
  await expect(row).toHaveAttribute('data-connected', 'no');

  await page.getByLabel('Network address').fill('http://network.forge.test');
  await page.getByLabel('Network key').fill(NETWORK_KEY);
  await page.getByTestId('bwx-network-save').click();
  await expect(page.getByTestId('bwx-network-notice')).toContainText('https://', { timeout: 60000 });

  await page.getByLabel('Network address').fill(NETWORK_URL);
  await page.getByTestId('bwx-network-save').click();
  await expect(row).toHaveAttribute('data-connected', 'yes', { timeout: 60000 });
  await expect(row).toContainText(NETWORK_KEY.slice(-4));

  const answer = await admin.api.request.get('/wp-json/blueworx-forge/v1/connections', { headers: admin.api.headers });
  expect(await answer.text()).not.toContain(NETWORK_KEY);
});

test('Create site makes the site, links it and sends logins, and a failed step is tried again', async ({ page }) => {
  test.slow();

  await failPeopleOnce(admin.api.request);

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, `screen=client&client=${client.id}`);

  const box = page.locator(`[data-testid="bwx-create-site"][data-site="${site.id}"]`);
  await box.getByTestId('bwx-create-site-open').click({ timeout: 60000 });
  await box.getByLabel('Address on the network').fill(SLUG);
  await expect(box).toContainText(`network.forge.test/${SLUG}`);
  await box.getByLabel('Site title').fill(`Acme ${RUN}`);
  await box.getByTestId('bwx-create-site-go').click();

  await expect(box.getByTestId('bwx-create-site-step-create')).toHaveAttribute('data-state', 'done', { timeout: 120000 });
  await expect(box.getByTestId('bwx-create-site-step-link')).toHaveAttribute('data-state', 'done', { timeout: 120000 });
  await expect(box.getByTestId('bwx-create-site-step-people')).toHaveAttribute('data-state', 'failed', { timeout: 120000 });
  await expect(box.getByTestId('bwx-create-site-notice')).toContainText('could not send email');

  await box.getByTestId('bwx-create-site-retry').click();
  await expect(box.getByTestId('bwx-create-site-step-people')).toHaveAttribute('data-state', 'done', { timeout: 120000 });

  await expect(box.getByRole('link', { name: 'Open the site' })).toHaveAttribute('href', `${NETWORK_URL}/${SLUG}`);
  await expect(box.getByRole('link', { name: 'Open its admin' })).toHaveAttribute('href', `${NETWORK_URL}/${SLUG}/wp-admin/`);
  await expect(box.getByTestId('bwx-create-site-people')).toContainText(`${boss.login}@example.test`);

  // What the network was asked for.
  const state = await networkState(admin.api.request);
  expect(state.sites[site.id]).toMatchObject({ slug: SLUG, title: `Acme ${RUN}` });
  expect(state.linked[site.id].key_length).toBe(64);
  expect(state.linked[site.id].registry_site_id).toMatch(/^site_/);

  const sent = state.people[site.id].map((person) => [person.email, person.access]).sort();
  expect(sent).toEqual([
    [`${boss.login}@example.test`, 'editor'],
    [`${reader.login}@example.test`, 'manager'],
  ].sort());
  expect(sent.map(([email]) => email)).not.toContain(`${staff.login}@example.test`);

  // The Forge site is connected now, with the key the network was given.
  const integration = (await admin.api.get(`/client-sites/${site.id}/integration`)).integration;
  expect(integration.key_state).toBe('active');
  expect(integration.registry_site_id).toBe(state.linked[site.id].registry_site_id);
});

test('a site that is connected, or finished, is not offered Create site again', async () => {
  test.slow();

  const finished = await admin.api.get(`/client-sites/${site.id}/deploy`);
  expect(finished.available).toBe(false);
  expect(finished.deploy.steps).toEqual({ create: 'done', link: 'done', people: 'done' });

  const again = await admin.api.post(`/client-sites/${site.id}/deploy`, { step: 'create', slug: 'another', title: 'Another' });
  expect(again.status()).toBe(409);

  // A site with a key issued the ordinary way has a WordPress site already.
  const other = await makeSite(admin.api, 'Deploy keyed', RUN);
  const issued = await admin.api.post(`/client-sites/${other.site.id}/integration/key`, {});
  expect(issued.status(), await issued.text()).toBe(200);
  expect((await admin.api.get(`/client-sites/${other.site.id}/deploy`)).available).toBe(false);
});

test('an address the network has already given away is refused, in its own words', async () => {
  test.slow();

  const fresh = await makeSite(admin.api, 'Deploy taken', RUN);
  const taken = await admin.api.post(`/client-sites/${fresh.site.id}/deploy`, { step: 'create', slug: 'taken', title: 'Taken' });

  expect(taken.status()).toBe(502);
  expect((await taken.json()).message).toContain('already taken on the network');
  expect((await admin.api.get(`/client-sites/${fresh.site.id}/deploy`)).deploy.steps.create).toBe('failed');
});
