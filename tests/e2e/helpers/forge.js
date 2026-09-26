import { expect } from '@playwright/test';
import { signIn } from '../../helpers/sign-in.js';
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

/**
 * A browser context signed in as somebody, with the REST nonce that context's
 * requests need. The nonce identifies the logged-in user to WordPress, so a
 * request made with somebody else's is a request as somebody else.
 */
export async function signedIn(browser, baseURL, user, pass) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await signIn(page, user, pass);
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
    put: (path, data) => request.put(`${BASE}${path}`, { headers, data }),
    del: (path) => request.delete(`${BASE}${path}`, { headers }),
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
    case 'design_url':
      return 'https://example.test/design';
    case 'test_description':
      return '<p>Open it and look.</p>';
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
        if (undefined !== patch[field]) {
          continue;
        }

        // The client is confirmed on purpose, not typed in (#390).
        if ('client_confirmed_at' === field) {
          await confirmClient(api, item);
          continue;
        }

        // A seat holds a person (2026-09-19: two of them before triage), and
        // 'Written down.' is not one. A spec that named nobody gets people.
        if (SEAT_FIELDS.includes(field)) {
          patch[field] = (await seatsFor(api, item))[field];
          continue;
        }

        patch[field] = answerFor(field);
      }
      continue;
    }

    if ('record' === requirement.by) {
      // A pick is answered with its first choice; a box with words.
      const first = requirement.options?.[0]?.value ?? 'Done.';

      await api.post(`/work-items/${item.id}/gate`, {
        requirement: requirement.id,
        value: 'pick' === requirement.control ? first : 'Done.',
        evidence: '',
      });
      continue;
    }

    // Worked out from the task (2026-09-18): evidence is a comment with a
    // link, hours are the three seats' hours. Anything else resolves itself.
    if ('auto' === requirement.by && 'attachment' === requirement.type) {
      await api.post(`/work-items/${item.id}/comments`, {
        body: 'Evidence.',
        url: 'https://example.test/evidence',
        kind: 'evidence',
        visibility: 'internal',
      });
    }

    if ('auto' === requirement.by && 'G-UP-NEXT-4' === requirement.id) {
      for (const field of ['hours_primary', 'hours_review', 'hours_delivery']) {
        if (undefined === patch[field]) {
          patch[field] = 1;
        }
      }
    }
  }

  if (0 < Object.keys(patch).length) {
    const current = await api.get(`/work-items/${item.id}`);
    let edited = await api.patch(`/work-items/${item.id}`, {
      ...patch,
      record_version: current.item.record_version,
    });

    /*
     * #149. Planned hours reserve support hours, and a site with no package
     * has none to give. The hours item is met by the seats' hours since
     * 2026-09-18, so a spec that was never about support now needs some:
     * put the site on a package and say it again, rather than making that
     * every spec's problem.
     */
    if (409 === edited.status() && (await edited.text()).includes('hours_not_available')) {
      await onSupport(api, item.client_site_id, 200);
      edited = await api.patch(`/work-items/${item.id}`, {
        ...patch,
        record_version: current.item.record_version,
      });
    }

    expect(edited.status(), `filling in ${Object.keys(patch).join(', ')}: ${await edited.text()}`).toBe(200);
  }

  return (await api.get(`/work-items/${item.id}`)).item;
}

const SEAT_FIELDS = ['primary_user_id', 'reviewer_id', 'deliverer_id'];

/** Confirms an item's client (#390), as the Confirm button on the task does. */
export async function confirmClient(api, item) {
  const current = await api.get(`/work-items/${item.id}`);
  const confirmed = await api.post(`/work-items/${item.id}/confirm-client`, {
    record_version: current.item.record_version,
  });

  expect(confirmed.status(), `confirming the client: ${await confirmed.text()}`).toBe(200);

  return (await confirmed.json()).item;
}

/**
 * Three people for the seats a walk needs: made once per run (a WordPress
 * account each is the slow part) and given a membership on each client the
 * first time work there asks for them.
 */
let crew = null;
const seated = new Map();

export async function seatsFor(api, item) {
  if (null === crew) {
    crew = Promise.all(['primary', 'reviewer', 'deliverer'].map((label) => makePerson(api, item.client_id, 'staff', `${label}-${Date.now()}`))).then(
      ([primary, reviewer, deliverer]) => {
        seated.set(item.client_id, Promise.resolve());

        return { primary_user_id: primary.id, reviewer_id: reviewer.id, deliverer_id: deliverer.id };
      }
    );
  }

  const seats = await crew;

  if (!seated.has(item.client_id)) {
    seated.set(
      item.client_id,
      Promise.all(Object.values(seats).map((id) => api.post(`/clients/${item.client_id}/memberships`, { user_id: id, role: 'staff' })))
    );
  }

  await seated.get(item.client_id);

  return seats;
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
    current = await satisfy(api, current, stage, seats);

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
 * Adds a package to the catalogue over REST, and returns it.
 *
 * A name of its own each time, because the instance is shared between runs
 * and a name reused across specs leaves several identical rows with no way
 * to say which is this one's. `api` is any caller with `.post` — a
 * `signedIn()` admin's `api`, or the pair helper's `studio`.
 */
export async function makePackage(api, label, { hours = 12, price = 1200, validity_months = 12 } = {}) {
  const wrote = await api.post('/packages', { name: label, hours, price, currency: 'GBP', validity_months, terms: '' });
  expect(wrote.status(), await wrote.text()).toBe(200);

  return (await wrote.json()).package;
}

/**
 * Gives a site the current published checklist over REST, and returns the
 * assignment.
 *
 * #160. A client onboards once, so the route answers 409 to a second call —
 * a spec that needs a site onboarding starts it here and never again. The
 * checklist itself is still published through the template screen (#159 put
 * that behind the screen). `api` is any caller with `.post` — a `signedIn()`
 * admin's `api`, or the pair helper's `studio` — or a bare `{ context, nonce }`,
 * which some pair specs build for themselves.
 */
export async function startOnboarding(api, siteId) {
  const post = api.post
    ? (path, data) => api.post(path, data)
    : (path, data) => api.context.request.post(`${BASE}${path}`, { headers: { 'X-WP-Nonce': api.nonce }, data });
  const started = await post(`/client-sites/${siteId}/onboarding`, {});
  expect(started.status(), await started.text()).toBe(200);

  return (await started.json()).onboarding;
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
 * Both halves go over REST now (PR 5). A package of its own each time,
 * because the instance is shared between runs and a name reused across
 * specs leaves several identical packages with no way to say which is this
 * one's. `admin` is a `signedIn()` result or any caller with `.post`.
 */
export async function onSupport(admin, siteId, hours = 200) {
  const label = `Hours ${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const api = admin.api ?? admin;
  const pkg = await makePackage(api, label, { hours, price: 1000 });

  await assignSupport(api, siteId, pkg.current.id, new Date().toISOString().slice(0, 10));

  return { label, hours };
}

/**
 * Puts a site on one package version from a date, over REST, and asserts it
 * is on support afterwards. `api` is any caller with `.post`.
 */
export async function assignSupport(api, siteId, packageVersionId, from) {
  const wrote = await api.post(`/client-sites/${siteId}/support`, { package_version: packageVersionId, starts_on: from });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(['active', 'scheduled'], 'the site is on support').toContain(answer.position.state);

  return answer.assignment;
}

/**
 * What the ledger holds against one site, read over REST. Each entry is
 * `[event_type, hours, source]`, the shape the studio's screen used to
 * give, so a spec reading `[type, hours]` pairs reads the same as before.
 */
export async function hourLedger(admin, siteId) {
  const answer = await (admin.api ?? admin).get(`/client-sites/${siteId}/support`);

  expect(answer.ok, `no support answer for ${siteId}: ${JSON.stringify(answer)}`).toBe(true);

  return {
    balance: Number(answer.position.balance),
    entries: answer.ledger.map((entry) => [entry.event_type, Number(entry.hours), entry.source ?? '']),
  };
}

/**
 * Somebody's working week, the same hours every day, from a date far enough
 * back to cover any window a spec uses.
 *
 * Works with the pair suite's studio caller too: both expose `post`.
 */
export async function setHours(api, personId, perDay, from = '2020-01-01') {
  const wrote = await api.post(`/users/${personId}/availability/hours`, {
    effective_from: from,
    hours_sun: perDay,
    hours_mon: perDay,
    hours_tue: perDay,
    hours_wed: perDay,
    hours_thu: perDay,
    hours_fri: perDay,
    hours_sat: perDay,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  return (await wrote.json()).pattern;
}
