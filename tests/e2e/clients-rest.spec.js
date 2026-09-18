import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, PASSWORD } from './helpers/forge.js';

// The endpoints assembled against a real WordPress: unit tests can prove the
// rules, but only a real site proves the routes are registered, the tables are
// there, and the conventions are actually applied rather than merely available.

// Idempotency keys are remembered for 24 hours (see Idempotency::TTL), and
// nothing here is ever deleted — a hardcoded key or display name reused on a
// later run can replay a response, or match a row, from a run that came
// before it. RUN_ID keeps this run's writes from being confused with any
// other run's.
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const BASE = '/wp-json/blueworx-forge/v1';

async function signedInContext(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await signIn(page);

  await page.goto('/blueworx-forge/');
  const nonce = await page.evaluate(() => window.bwxForgeData?.nonce);
  expect(nonce, 'no REST nonce was localised for the signed-in user').toBeTruthy();

  await page.close();
  return { context, nonce };
}

async function createClient(request, nonce, name) {
  const response = await request.post('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
    data: { display_name: name, timezone: 'Europe/London' },
  });

  expect(response.status()).toBe(200);
  return (await response.json()).client;
}

async function createSite(request, nonce, clientId, name) {
  const response = await request.post(`/wp-json/blueworx-forge/v1/clients/${clientId}/sites`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { name, url: 'https://example.test' },
  });

  expect(response.status()).toBe(200);
  return (await response.json()).site;
}

test('a stranger cannot list or create clients', async ({ request }) => {
  expect([401, 403]).toContain((await request.get('/wp-json/blueworx-forge/v1/clients')).status());

  const created = await request.post('/wp-json/blueworx-forge/v1/clients', {
    data: { display_name: 'Trespass' },
  });
  expect([401, 403]).toContain(created.status());
});

test('a client with two sites has two independent workspaces', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Acme Ltd');

  const sites = [];
  for (const name of ['Acme Main', 'Acme Shop']) {
    const response = await context.request.post(
      `/wp-json/blueworx-forge/v1/clients/${client.id}/sites`,
      { headers: { 'X-WP-Nonce': nonce }, data: { name, url: 'https://example.test' } },
    );
    expect(response.status()).toBe(200);
    sites.push((await response.json()).site);
  }

  expect(sites[0].id).not.toEqual(sites[1].id);

  // Each site answers for itself and never for its sibling.
  for (const site of sites) {
    const response = await context.request.get(
      `/wp-json/blueworx-forge/v1/client-sites/${site.id}`,
      { headers: { 'X-WP-Nonce': nonce } },
    );
    const body = await response.json();
    expect(body.site.id).toBe(site.id);
    expect(body.site.client_id).toBe(client.id);
  }

  await context.close();
});

test('an edit made against an old version is refused, not merged', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Stale Ltd');

  const first = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'Stale Limited', record_version: client.record_version },
  });
  expect(first.status()).toBe(200);

  const second = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'Something else', record_version: client.record_version },
  });
  expect(second.status()).toBe(409);

  const body = await second.json();
  expect(body.code).toBe('bwx_forge_stale_write');
  // The rejection carries the current state, so the person can see what moved.
  expect(body.data.current.legal_name).toBe('Stale Limited');

  await context.close();
});

test('a write with no version at all is refused', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Versionless Ltd');

  const response = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { legal_name: 'No version' },
  });

  expect(response.status()).toBe(400);
  expect((await response.json()).code).toBe('bwx_forge_missing_version');

  await context.close();
});

test('a retried create produces one client, not two', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);

  const name = `Retry Ltd ${RUN_ID}`;

  const send = () =>
    context.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { 'X-WP-Nonce': nonce, 'Idempotency-Key': `clients-retry-${RUN_ID}` },
      data: { display_name: name, timezone: 'UTC' },
    });

  const first = await send();
  const second = await send();

  expect((await first.json()).client.id).toBe((await second.json()).client.id);

  const listed = await context.request.get('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
  });
  const named = (await listed.json()).clients.filter((c) => c.display_name === name);
  expect(named).toHaveLength(1);

  await context.close();
});

test('an idempotency key is scoped per client, not shared across them', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const clientA = await createClient(context.request, nonce, 'Scoped A Ltd');
  const clientB = await createClient(context.request, nonce, 'Scoped B Ltd');

  const createSite = (client) =>
    context.request.post(`/wp-json/blueworx-forge/v1/clients/${client.id}/sites`, {
      headers: { 'X-WP-Nonce': nonce, 'Idempotency-Key': 'shared-retry-key' },
      data: { name: `${client.display_name} Main` },
    });

  const responseA = await createSite(clientA);
  const responseB = await createSite(clientB);

  expect(responseA.status()).toBe(200);
  expect(responseB.status()).toBe(200);

  const siteA = (await responseA.json()).site;
  const siteB = (await responseB.json()).site;

  expect(siteA.id).not.toEqual(siteB.id);
  expect(siteA.client_id).toBe(clientA.id);
  expect(siteB.client_id).toBe(clientB.id);

  await context.close();
});

test('PATCH /clients/{id} that deactivates also saves the rest of the request', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, `Closing Fields Ltd ${RUN_ID}`);

  const response = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', display_name: 'Closing Fields (closed)', record_version: client.record_version },
  });

  expect(response.status()).toBe(200);
  const body = await response.json();
  // Every field the request named is saved, not only the status column: a
  // PATCH that deactivates is still a write of everything else it carried.
  expect(body.client.status).toBe('inactive');
  expect(body.client.display_name).toBe('Closing Fields (closed)');

  await context.close();
});

test('PATCH /client-sites/{id} edits a site', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, `Site Edit Ltd ${RUN_ID}`);
  const site = await createSite(context.request, nonce, client.id, 'Site Edit Main');

  const response = await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: 'Site Edit Main — renamed', record_version: site.record_version },
  });

  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.site.name).toBe('Site Edit Main — renamed');
  expect(body.site.record_version).toBe(site.record_version + 1);

  await context.close();
});

test('PATCH /client-sites/{id} against a stale version is refused, not merged', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, `Site Stale Ltd ${RUN_ID}`);
  const site = await createSite(context.request, nonce, client.id, 'Site Stale Main');

  const first = await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: 'Renamed once', record_version: site.record_version },
  });
  expect(first.status()).toBe(200);

  const second = await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: 'Renamed twice', record_version: site.record_version },
  });
  expect(second.status()).toBe(409);

  const body = await second.json();
  expect(body.code).toBe('bwx_forge_stale_write');
  expect(body.data.current.name).toBe('Renamed once');

  await context.close();
});

test('PATCH /client-sites/{id} with no version at all is refused', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, `Site Versionless Ltd ${RUN_ID}`);
  const site = await createSite(context.request, nonce, client.id, 'Site Versionless Main');

  const response = await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: 'No version' },
  });

  expect(response.status()).toBe(400);
  expect((await response.json()).code).toBe('bwx_forge_missing_version');

  await context.close();
});

test('PATCH /client-sites/{id} that deactivates also saves the rest of the request', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, `Site Deactivate Ltd ${RUN_ID}`);
  const site = await createSite(context.request, nonce, client.id, 'Site Deactivate Main');

  const response = await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', name: 'Closed Main', record_version: site.record_version },
  });

  expect(response.status()).toBe(200);
  const body = await response.json();
  // Every field the request named is saved, not only the status column: a
  // PATCH that deactivates is still a write of everything else it carried.
  expect(body.site.status).toBe('inactive');
  expect(body.site.name).toBe('Closed Main');

  await context.close();
});

test('a site cannot be created under an inactive client', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, `Closed Client Ltd ${RUN_ID}`);

  const closed = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: client.record_version },
  });
  expect(closed.status()).toBe(200);

  const response = await context.request.post(`/wp-json/blueworx-forge/v1/clients/${client.id}/sites`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: 'Should not exist' },
  });

  expect(response.status()).toBe(409);
  expect((await response.json()).code).toBe('bwx_forge_inactive_client');

  await context.close();
});

test('deactivating a client deactivates its sites', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, 'Closing Ltd');

  const created = await context.request.post(
    `/wp-json/blueworx-forge/v1/clients/${client.id}/sites`,
    { headers: { 'X-WP-Nonce': nonce }, data: { name: 'Closing Main' } },
  );
  const site = (await created.json()).site;

  const closed = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: client.record_version },
  });
  expect(closed.status()).toBe(200);

  const after = await context.request.get(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect((await after.json()).site.status).toBe('inactive');

  await context.close();
});

test('a deactivated client can be reactivated', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, `Reactivate Client Ltd ${RUN_ID}`);

  const closed = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: client.record_version },
  });
  expect(closed.status()).toBe(200);
  const closedBody = (await closed.json()).client;

  const reopened = await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${client.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'active', record_version: closedBody.record_version },
  });
  expect(reopened.status()).toBe(200);
  expect((await reopened.json()).client.status).toBe('active');

  // Back in the default listing, which shows active clients only.
  const listed = await context.request.get('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
  });
  const clients = (await listed.json()).clients;
  expect(clients.some((one) => one.id === client.id)).toBe(true);

  await context.close();
});

test('a deactivated site can be reactivated', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await createClient(context.request, nonce, `Reactivate Site Ltd ${RUN_ID}`);
  const site = await createSite(context.request, nonce, client.id, 'Reactivate Main');

  const closed = await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: site.record_version },
  });
  expect(closed.status()).toBe(200);
  const closedBody = (await closed.json()).site;

  const reopened = await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'active', record_version: closedBody.record_version },
  });
  expect(reopened.status()).toBe(200);
  expect((await reopened.json()).site.status).toBe('active');

  // Back in the client's default listing of sites, which is also active-only.
  const listed = await context.request.get(`/wp-json/blueworx-forge/v1/clients/${client.id}/sites`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  const sites = (await listed.json()).sites;
  expect(sites.some((one) => one.id === site.id)).toBe(true);

  await context.close();
});

// ---------------------------------------------------------------------------
// PR 4: what the Clients screen in the app needs that the admin page had —
// the contact, starting onboarding, the studio's own name, and the facts the
// list and a client's sites carry so the screen draws them in one read.
// ---------------------------------------------------------------------------

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const STAMP = RUN_ID.replace('-', '');
const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';

/**
 * Publishes a checklist with one launch-critical step, through the template
 * screen — #159 put the whole of it behind the screen and nothing writes one
 * over REST. The same walk onboarding-assign.spec.js makes.
 */
async function publishAChecklist(page) {
  await page.goto(TEMPLATE);

  const start = page.locator('[data-bwx-start-draft="1"]');

  if (await start.count()) {
    await page.fill('#bwx-template-name', `Clients REST ${RUN_ID}`);
    await start.locator('input[type="submit"]').click();
  } else {
    await page.locator('[data-bwx-copy-template="1"] input[type="submit"]').first().click();
  }

  await page.fill('#bwx-step-title', `Delegate the registrar ${RUN_ID}`);
  await page.check('#bwx-step-launch-critical');
  await page.locator('[data-bwx-add-step="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="step-added"]')).toBeVisible();

  await page.locator('[data-bwx-publish-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="published"]')).toBeVisible();
}

test.describe('the clients screen, over REST', () => {
  test.describe.configure({ mode: 'serial' });

  let context;
  let api;
  let where;
  let staff;

  test.beforeAll(async ({ browser, baseURL }) => {
    ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
    where = await makeSite(api, 'Clients REST', RUN_ID);
    staff = await makePerson(api, where.client.id, 'staff', `staff${STAMP}`);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  async function clientInList(clientId) {
    const listed = await api.get('/clients?status=all');
    expect(listed.ok).toBe(true);
    return listed.clients.find((one) => one.id === clientId);
  }

  async function siteInList(clientId, siteId) {
    const listed = await api.get(`/clients/${clientId}/sites?status=all`);
    expect(listed.ok).toBe(true);
    return listed.sites.find((one) => one.id === siteId);
  }

  test('the contact is somebody active, can be nobody, and never somebody who has left', async () => {
    // Never had one: the list says so, and says the studio stands in.
    const before = await clientInList(where.client.id);
    expect(before.contact).toEqual({ contact: null, needs_reassignment: true, fallback: 'studio' });

    const named = await api.put(`/clients/${where.client.id}/contact`, { user_id: staff.id });
    expect(named.status(), await named.text()).toBe(200);

    const answer = await named.json();
    expect(answer.ok).toBe(true);
    expect(answer.client.id).toBe(where.client.id);
    expect(answer.contact.contact.display_name).toBe(staff.user.display_name);
    expect(answer.contact.needs_reassignment).toBe(false);
    expect(answer.contact.fallback).toBe('');

    // A name, an id and whether they are still here — and nothing else about
    // them. The list is open to anybody signed in; the person's record is not.
    expect(Object.keys(answer.contact.contact).sort()).toEqual(['display_name', 'id', 'status']);

    const listed = (await clientInList(where.client.id)).contact.contact;
    expect(listed.id).toBe(staff.id);
    expect(Object.keys(listed).sort()).toEqual(['display_name', 'id', 'status']);

    // Somebody who has left cannot be named.
    const leaver = await makePerson(api, where.client.id, 'staff', `leaver${STAMP}`);
    const gone = await api.post(`/users/${leaver.id}/offboard`, { record_version: leaver.user.record_version });
    expect(gone.status(), await gone.text()).toBe(200);

    const refused = await api.put(`/clients/${where.client.id}/contact`, { user_id: leaver.id });
    expect(refused.status()).toBe(400);
    expect((await refused.json()).code).toBe('bwx_forge_invalid_contact');

    // Nor somebody who does not exist.
    const nobody = await api.put(`/clients/${where.client.id}/contact`, { user_id: 'usr_nobody' });
    expect(nobody.status()).toBe(400);
    expect((await nobody.json()).code).toBe('bwx_forge_invalid_contact');

    // Still the same person after both refusals.
    expect((await clientInList(where.client.id)).contact.contact.id).toBe(staff.id);

    // Cleared: nobody, and needing reassigning — which is not the same as
    // never having had one, because the history says who it was.
    const cleared = await api.put(`/clients/${where.client.id}/contact`, { user_id: '' });
    expect(cleared.status(), await cleared.text()).toBe(200);
    expect((await cleared.json()).contact).toEqual({ contact: null, needs_reassignment: true, fallback: 'studio' });

    // An unknown client is absent.
    const missing = await api.put('/clients/cli_nobody/contact', { user_id: '' });
    expect(missing.status()).toBe(404);
    expect((await missing.json()).code).toBe('bwx_forge_unknown_client');
  });

  test('a retried contact assignment under one key names them once', async () => {
    const send = () =>
      api.request.put(`${BASE}/clients/${where.client.id}/contact`, {
        headers: { ...api.headers, 'Idempotency-Key': `contact-${RUN_ID}` },
        data: { user_id: staff.id },
      });

    const first = await send();
    const second = await send();
    expect(first.status(), await first.text()).toBe(200);
    expect(second.status(), await second.text()).toBe(200);

    expect((await first.json()).assignment.id).toBe((await second.json()).assignment.id);
  });

  test('onboarding starts once, with the published checklist, and the sites list says where it is', async () => {
    test.setTimeout(180_000);

    const { site } = await makeSite(api, 'Onboarding REST', RUN_ID);

    let started = await api.post(`/client-sites/${site.id}/onboarding`, {});

    if (409 === started.status()) {
      // A fresh instance: nothing has been published yet, and the route says
      // so rather than assigning nothing.
      const body = await started.json();
      expect(body.code).toBe('bwx_forge_no_checklist');

      expect((await siteInList(site.client_id, site.id)).onboarding).toBeNull();

      const page = await context.newPage();
      await publishAChecklist(page);
      await page.close();

      started = await api.post(`/client-sites/${site.id}/onboarding`, {});
    } else {
      // A reused instance already has one published, so the no-checklist
      // answer cannot be proved here; the rest can.
      test.info().annotations.push({ type: 'note', description: 'a checklist was already published on this instance' });
    }

    expect(started.status(), await started.text()).toBe(200);

    const answer = await started.json();
    expect(answer.ok).toBe(true);
    expect(answer.site.id).toBe(site.id);
    expect(answer.onboarding.client_site_id).toBe(site.id);
    expect(answer.onboarding.client_id).toBe(site.client_id);
    expect(answer.onboarding.template_version).toBeGreaterThan(0);

    // Once. The second call is the refusal, not a second checklist.
    const again = await api.post(`/client-sites/${site.id}/onboarding`, {});
    expect(again.status()).toBe(409);
    expect((await again.json()).code).toBe('bwx_forge_already_onboarding');

    const listed = await siteInList(site.client_id, site.id);
    expect(listed.onboarding.started).toBe(true);
    expect(listed.onboarding.ready).toBe(false);
    expect(listed.onboarding.template_version).toBe(answer.onboarding.template_version);
    expect(listed.onboarding.completion).toBe(0);
    expect(listed.onboarding.blocking).toBeGreaterThan(0);

    // A site not yet started, now that a checklist exists, is offered it.
    const other = await makeSite(api, 'Not Started REST', RUN_ID);
    const offered = await siteInList(other.site.client_id, other.site.id);
    expect(offered.onboarding).toEqual(expect.objectContaining({ started: false, ready: false }));
    expect(offered.onboarding.template_version).toBe(answer.onboarding.template_version);

    // The connection the admin page also shows comes with each site already.
    expect(offered.integration).toBeNull();

    const missing = await api.post('/client-sites/cst_nobody/onboarding', {});
    expect(missing.status()).toBe(404);
    expect((await missing.json()).code).toBe('bwx_forge_unknown_client_site');
  });

  test('exactly one client is the studio, and it can be renamed against its version', async () => {
    const listed = await api.get('/clients?status=all');
    const studios = listed.clients.filter((one) => true === one.is_studio);
    expect(studios).toHaveLength(1);
    expect(listed.clients.filter((one) => 'boolean' !== typeof one.is_studio)).toHaveLength(0);

    const studio = studios[0];
    const name = `Studio ${RUN_ID}`;

    const renamed = await api.put('/studio', { display_name: name, record_version: studio.record_version });
    expect(renamed.status(), await renamed.text()).toBe(200);

    const answer = await renamed.json();
    expect(answer.ok).toBe(true);
    expect(answer.client.id).toBe(studio.id);
    expect(answer.client.display_name).toBe(name);
    expect(answer.client.record_version).toBe(studio.record_version + 1);

    const stale = await api.put('/studio', { display_name: 'Too late', record_version: studio.record_version });
    expect(stale.status()).toBe(409);
    expect((await stale.json()).code).toBe('bwx_forge_stale_write');

    const blank = await api.put('/studio', { display_name: '   ', record_version: answer.client.record_version });
    expect(blank.status()).toBe(400);
    expect((await blank.json()).code).toBe('bwx_forge_invalid_client');

    // Put the name back: the instance is shared, and the studio's name is
    // something other specs may read.
    const restored = await api.put('/studio', {
      display_name: studio.display_name,
      record_version: answer.client.record_version,
    });
    expect(restored.status(), await restored.text()).toBe(200);
  });

  test('somebody who is not an administrator can do none of it', async ({ browser, baseURL }) => {
    const other = await signedIn(browser, baseURL, staff.login, PASSWORD);

    const contact = await other.api.put(`/clients/${where.client.id}/contact`, { user_id: staff.id });
    expect(contact.status()).toBe(403);

    const onboarding = await other.api.post(`/client-sites/${where.site.id}/onboarding`, {});
    expect(onboarding.status()).toBe(403);

    const studio = await other.api.put('/studio', { display_name: 'Not theirs', record_version: 1 });
    expect(studio.status()).toBe(403);

    await other.context.close();
  });
});
