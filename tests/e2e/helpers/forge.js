import { expect } from '@playwright/test';
import { asSite } from '../../helpers/signing.js';

// What every workflow spec needs to get an item as far as the thing it is
// actually testing.
//
// It lives here rather than in each spec because since #105 and #112 that
// preamble is real work — satisfying a gate, assigning three seats, and signing
// in as the person the item names — and three copies of it drift. A spec should
// read as the rule it is proving, not as a recipe for getting to it.
//
// Not matched by testMatch (`**/*.spec.js`), so it is a module rather than a
// suite that asserts nothing.

const BASE = '/wp-json/blueworx-forge/v1';

/** The password every person this module creates signs in with. */
export const PASSWORD = 'forge-test-pw-4471';

export function signInPage(page, user, pass) {
  return (async () => {
    await page.goto('/wp-login.php?loggedout=true');
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await page.click('#wp-submit');
    await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
  })();
}

/**
 * A browser context signed in as somebody, with the REST nonce that context's
 * requests need. The nonce identifies the logged-in user to WordPress, so a
 * request made with somebody else's is a request as somebody else.
 */
export async function signedIn(browser, baseURL, user, pass) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await signInPage(page, user, pass);
  await page.goto('/blueworx-forge/');

  const nonce = await page.evaluate(() => window.bwxForgeData?.nonce);
  expect(nonce, `no REST nonce was localised for ${user}`).toBeTruthy();

  await page.close();

  return { context, nonce, api: forge(context.request, nonce) };
}

/** One caller, so every helper below reads the same. */
export function forge(request, nonce) {
  const headers = { 'X-WP-Nonce': nonce };

  return {
    headers,
    request,
    get: (path) => request.get(`${BASE}${path}`, { headers }).then((r) => r.json()),
    post: (path, data) => request.post(`${BASE}${path}`, { headers, data }),
    patch: (path, data) => request.patch(`${BASE}${path}`, { headers, data }),
  };
}

export async function makeSite(api, label, runId) {
  const client = await (
    await api.post('/clients', { display_name: `${label} ${runId}`, timezone: 'Europe/London' })
  ).json();
  const site = await (
    await api.post(`/clients/${client.client.id}/sites`, {
      name: `${label} site ${runId}`,
      url: 'https://example.test',
    })
  ).json();

  return { client: client.client, site: site.site };
}

/**
 * A real person: a WordPress account, the Forge user joined to it, and a
 * membership giving them a role with this client. All three, because the
 * permission layer reads all three — a Forge user with no membership holds
 * nothing, and a membership with no WordPress account behind it never signs in.
 */
export async function makePerson(api, clientId, role, label) {
  const login = `${label}${Date.now()}${Math.floor(Math.random() * 1000)}`;

  const wp = await api.request.post('/wp-json/wp/v2/users', {
    headers: api.headers,
    data: {
      username: login,
      email: `${login}@example.test`,
      password: PASSWORD,
      roles: ['subscriber'],
    },
  });
  expect(wp.status(), await wp.text()).toBe(201);

  const created = await api.post('/users', {
    email: `${login}@example.test`,
    display_name: label,
    wp_user_id: (await wp.json()).id,
  });
  expect(created.status(), await created.text()).toBe(200);

  const user = (await created.json()).user;

  const membership = await api.post(`/clients/${clientId}/memberships`, {
    user_id: user.id,
    role,
  });
  expect(membership.status(), await membership.text()).toBe(200);

  return { login, user, id: user.id };
}

export function makeItem(api, siteId, data) {
  return api.post('/work-items', {
    client_site_id: siteId,
    level: 'sub-feature',
    work_type: 'feature',
    problem: 'Something needs doing.',
    ...data,
  });
}

/** A plausible answer for each field a gate asks for. */
export function answerFor(field) {
  switch (field) {
    case 'planned_start':
      return '2026-09-01';
    case 'planned_due':
      return '2026-09-30';
    case 'priority':
      return 'normal';
    case 'commercial_class':
      return 'chargeable';
    case 'release_method':
      return 'software';
    case 'remaining_estimate':
      return 2;
    default:
      return 'Written down.';
  }
}

/**
 * Does whatever stands between an item and a stage, the way a person would:
 * fills in the fields, records the completions, attaches evidence where the
 * requirement asks for it.
 *
 * `seats` is passed through for the fields a plausible answer cannot invent —
 * the three seats hold people, and 'Written down.' is not a person.
 */
export async function satisfy(api, item, to, seats = {}) {
  const detail = await api.get(`/work-items/${item.id}`);
  const patch = { ...seats };

  for (const requirement of detail.readiness[to]?.unmet ?? []) {
    if ('field' === requirement.by) {
      for (const field of requirement.fields) {
        if (undefined === patch[field]) {
          patch[field] = answerFor(field);
        }
      }
      continue;
    }

    if ('record' === requirement.by) {
      await api.post(`/work-items/${item.id}/gate`, {
        requirement: requirement.id,
        value: 'Done.',
        evidence: requirement.evidence ? 'https://example.test/evidence' : '',
      });
    }
  }

  if (0 < Object.keys(patch).length) {
    const current = await api.get(`/work-items/${item.id}`);
    const edited = await api.patch(`/work-items/${item.id}`, {
      ...patch,
      record_version: current.item.record_version,
    });
    expect(edited.status(), `filling in ${Object.keys(patch).join(', ')}: ${await edited.text()}`).toBe(200);
  }

  return (await api.get(`/work-items/${item.id}`)).item;
}

/**
 * Walks an item up the path, satisfying each gate on the way.
 *
 * `as` maps a stage to the caller who may enter it. Since #112 two of the moves
 * belong to the person the item names rather than to whoever is driving, so a
 * walk that does not change hands cannot reach Released.
 */
export async function walkTo(api, item, stages, { seats = {}, as = {} } = {}) {
  let current = item;

  for (const stage of stages) {
    current = await satisfy(api, current, stage, 'up-next' === stage ? seats : {});

    const caller = as[stage] ?? api;
    const moved = await caller.post(`/work-items/${current.id}/transition`, {
      to: stage,
      record_version: current.record_version,
    });

    expect(moved.status(), `moving to ${stage}: ${await moved.text()}`).toBe(200);
    current = (await moved.json()).item;
  }

  return current;
}

/**
 * The three seats, filled by real people, and the callers who may make the two
 * moves that belong to them.
 *
 * One person cannot hold all three: a reviewer is somebody other than the
 * person who did the work unless they hold the Principal grant (AUTH-3), and
 * the seats are what the authority rules read.
 */
export async function team(api, browser, baseURL, clientId) {
  const primary = await makePerson(api, clientId, 'staff', 'primary');
  const reviewer = await makePerson(api, clientId, 'staff', 'reviewer');
  const deliverer = await makePerson(api, clientId, 'staff', 'deliverer');

  const asReviewer = await signedIn(browser, baseURL, reviewer.login, PASSWORD);
  const asDeliverer = await signedIn(browser, baseURL, deliverer.login, PASSWORD);

  return {
    primary,
    reviewer,
    deliverer,
    seats: {
      primary_user_id: primary.id,
      reviewer_id: reviewer.id,
      deliverer_id: deliverer.id,
    },
    as: {
      completed: asReviewer.api,
      released: asDeliverer.api,
    },
    async close() {
      await asReviewer.context.close();
      await asDeliverer.context.close();
    },
  };
}

/**
 * A client site's signing key, and a caller that speaks as that site.
 *
 * ARCH-6 says a client site proves who it is with a signature rather than with
 * a login, so anything a spec wants to do *as a client site* has to be signed.
 * The signing itself lives in tests/helpers/signing.js, above both suites,
 * because the two-instance suite needs exactly the same thing and a second copy
 * would be a second thing to get wrong.
 */
export async function asClientSite(api, siteId, request) {
  const issued = await (await api.post(`/client-sites/${siteId}/integration/key`, {})).json();

  return {
    key: issued.key,
    registrySiteId: issued.integration.registry_site_id,
    ...asSite(request, issued.key, issued.integration.registry_site_id),
  };
}

/** Something a client site has asked for, sent the way a client site sends it. */
export async function makeSubmission(site, values) {
  const sent = await site.post('/client/submissions', {
    type: 'request',
    title: 'A booking form that takes deposits',
    description: 'People ring up to pay and half of them never call back.',
    submitted_by: 'Someone at the client',
    ...values,
  });

  expect(sent.status(), await sent.text()).toBe(200);

  return (await sent.json()).submission;
}

/**
 * Puts a site on a package with enough hours to plan work against.
 *
 * #149. Chargeable work reserves its hours the moment it reaches Up Next, and
 * the ledger refuses an entry that would take a site below nought — so a spec
 * that plans real hours against a site with no package is refused, whatever it
 * was actually about. This is the one line that stops that being every spec's
 * problem.
 *
 * A package of its own each time, because the instance is shared between runs
 * and a name reused across specs leaves several identical options in the list
 * with no way to say which is this one's.
 */
export async function onSupport(admin, siteId, hours = 200) {
  const label = `Hours ${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const page = await admin.context.newPage();

  await page.goto('/wp-admin/admin.php?page=blueworx-forge-packages');

  const form = page.locator('form').filter({ has: page.locator('input[value="bwx_forge_add_package"]') });

  await form.locator('input[name="name"]').fill(label);
  await form.locator('input[name="hours"]').fill(String(hours));
  await form.locator('input[name="price"]').fill('1000');
  await form.locator('input[name="validity_months"]').fill('12');
  await form.locator('#bwx-add').click();
  await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();

  await page.goto(`/wp-admin/admin.php?page=blueworx-forge-support&site=${siteId}`);

  const option = page.locator('#bwx-assign-package option', { hasText: label });
  const value = await option.getAttribute('value');

  expect(value, 'the package just added is on offer').toBeTruthy();

  await page.locator('#bwx-assign-package').selectOption(value);
  await page.locator('#bwx-assign-from').fill(new Date().toISOString().slice(0, 10));
  await page.locator('#bwx-assign').click();

  await expect(page.locator('[data-bwx-support-state="active"]')).toBeVisible();

  await page.close();

  return { label, hours };
}

/** What the ledger holds against one site, read from the studio's own screen. */
export async function hourLedger(admin, siteId) {
  const page = await admin.context.newPage();

  await page.goto(`/wp-admin/admin.php?page=blueworx-forge-support&site=${siteId}`);
  await expect(page.locator('[data-bwx-support-state]')).toBeVisible();

  const balance = await page.locator('[data-bwx-balance]').getAttribute('data-bwx-balance');
  const entries = await page.locator('[data-bwx-entry]').evaluateAll((rows) =>
    rows.map((row) => [row.getAttribute('data-bwx-entry'), Number(row.getAttribute('data-bwx-entry-hours'))])
  );

  await page.close();

  return { balance: Number(balance), entries };
}
