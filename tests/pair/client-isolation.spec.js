import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, requireEnvironment, STUDIO_URL } from './helpers/pair.js';

// #135, the isolation half: the tenant boundary proved against the real client
// artifact on its own WordPress, rather than against a role somebody set up on
// ours.
//
// That distinction is the whole reason this file exists rather than another
// studio spec. Every isolation test up to now has simulated a client by giving
// a studio user a client role — which proves the permission matrix, and proves
// nothing about the plugin a client actually runs. The client artifact holds
// one credential, reaches the studio over one connection, and this suite
// attempts every crossing *through that credential*.
//
// Each test names the denial it proves. tests/php/DenialCoverageTest walks
// Tenancy\Denials and fails if one this milestone owes is not named here, so a
// denial nobody wrote a test for shows up as a failing count rather than as
// nothing at all.

const RUN = `iso${Date.now()}`;

test.beforeAll(requireEnvironment);

/** A second client on the studio, with work of its own and no connection here. */
async function somebodyElse(pair, label) {
  const client = await (
    await pair.studio.post('/clients', {
      display_name: `${label} ${RUN}`,
      timezone: 'Europe/London',
    })
  ).json();

  const site = await (
    await pair.studio.post(`/clients/${client.client.id}/sites`, {
      name: `${label} site ${RUN}`,
      url: 'https://elsewhere.test',
    })
  ).json();

  const work = await makeItem(pair.studio, site.site.id, {
    title: `Their private work ${RUN}`,
  });

  return { client: client.client, site: site.site, work };
}

test.describe('what a client site cannot reach', () => {
  test.beforeEach(() => {
    test.slow();
  });

  // ---- D-1, D-2, D-3 ---------------------------------------------------

  test('D-1, D-2: a record on another site or client is answered as absent', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Isolated Co', RUN);
    const theirs = await somebodyElse(pair, 'Other Co');
    const site = asClientSite(request, pair.issued);

    // Its own work: fine.
    const mine = await site.get(`/client/work-items/${pair.work.id}/comments`);

    expect(mine.status(), await mine.text()).toBe(200);

    // Another client's, with a perfectly valid signature: not there.
    const theirWork = await site.get(`/client/work-items/${theirs.work.id}/comments`);

    expect(theirWork.status()).toBe(404);
    await expect(theirWork.text()).resolves.not.toContain(`Their private work ${RUN}`);

    // And it cannot be written to either.
    const written = await site.post(`/client/work-items/${theirs.work.id}/comments`, {
      body: 'Hello, other client.',
    });

    expect(written.status()).toBe(404);

    await pair.close();
  });

  test('D-3: guessed ids are all one answer, so nothing can be enumerated', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Guessing Co', RUN);
    const theirs = await somebodyElse(pair, 'Guessed Co');
    const site = asClientSite(request, pair.issued);

    const answers = [];

    // Well-formed ids, all of them. A malformed one does not match the route
    // at all and gets WordPress's own answer, which proves nothing either way —
    // what D-3 is about is ids that reach the callback and have to come back
    // indistinguishable.
    for (const id of [theirs.work.id, 'wrk_000000000000', 'wrk_zzzzzzzzzzzz', 'wrk_1', 'wrk_00000001']) {
      const answer = await site.get(`/client/work-items/${id}/comments`);

      answers.push({ status: answer.status(), code: (await answer.json()).code });
    }

    // One real id belonging to somebody else, and four that are nothing, and
    // the caller cannot tell which was which. That is the whole of D-3: an id
    // that answers differently is an id somebody can search for.
    const distinct = new Set(answers.map((one) => `${one.status}:${one.code}`));

    expect(distinct.size, `ids answered ${distinct.size} different ways`).toBe(1);
    expect(answers[0].status).toBe(404);

    await pair.close();
  });

  // ---- D-4, D-5, D-6 ---------------------------------------------------

  test('D-4, D-5: a filter or search cannot widen past this site', async ({ browser, request }) => {
    const pair = await connectedPair(browser, 'Narrow Co', RUN);
    const theirs = await somebodyElse(pair, 'Wide Co');
    const site = asClientSite(request, pair.issued);

    // Every way a caller might try to name somebody else's scope, on the one
    // route that returns a list.
    for (const query of [
      `?client_site_id=${theirs.site.id}`,
      `?client_id=${theirs.client.id}`,
      `?site_id=${theirs.site.id}`,
      '?search=private',
      '?include_archived=1',
      `?client_site_id[]=${theirs.site.id}`,
    ]) {
      const answer = await site.get(`/client/board${query}`);

      expect(answer.status(), `${query} was not answered`).toBe(200);

      const board = await answer.json();
      const titles = board.items.map((one) => one.title);

      expect(titles, `${query} widened the board`).not.toContain(`Their private work ${RUN}`);

      // Only this site's work, whatever was asked for.
      for (const item of board.items) {
        expect(item.id).not.toBe(theirs.work.id);
      }
    }

    await pair.close();
  });

  test('D-6: nothing a client site reads counts another client in', async ({ browser, request }) => {
    const pair = await connectedPair(browser, 'Counting Co', RUN);

    await somebodyElse(pair, 'Counted Co');

    const site = asClientSite(request, pair.issued);
    const board = await (await site.get('/client/board')).json();

    // One item: this client's own. A total that included anybody else's would
    // be a count somebody can watch move (D-6).
    expect(board.items).toHaveLength(1);
    expect(board.items[0].id).toBe(pair.work.id);

    // And the answer carries no cross-client figure at all — not a total, not
    // a queue length, not a position.
    const raw = JSON.stringify(board).toLowerCase();

    for (const leak of ['other_client', 'all_clients', 'across', 'queue_position']) {
      expect(raw, `the board carries ${leak}`).not.toContain(leak);
    }

    await pair.close();
  });

  // ---- D-7, D-8 --------------------------------------------------------

  test('D-7: a deactivated site acts no more, and what it did stays', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Deactivated Co', RUN);
    const site = asClientSite(request, pair.issued);

    const said = await site.post(`/client/work-items/${pair.work.id}/comments`, {
      body: `Said while active ${RUN}`,
      author_name: 'Someone here',
    });

    expect(said.status(), await said.text()).toBe(200);

    const current = (await pair.studio.get(`/client-sites/${pair.site.id}`)).site;
    const closed = await pair.studio.patch(`/client-sites/${pair.site.id}`, {
      status: 'inactive',
      record_version: current.record_version,
    });

    expect(closed.status(), await closed.text()).toBe(200);

    // Nothing more from this site — not a write, and not a read either. The
    // key still verifies; what has ended is the arrangement it was issued for.
    const after = await site.post(`/client/work-items/${pair.work.id}/comments`, {
      body: `Said after closing ${RUN}`,
    });

    expect(after.status(), 'a closed site could still write').toBe(403);

    for (const route of ['/client/workspace', '/client/board', '/client/submissions']) {
      const read = await site.get(route);

      expect(read.status(), `${route} still answers a closed site`).toBe(403);
    }

    // And what it said before is still there, still attributed. Past
    // attribution surviving deactivation is half of D-7 and the half that gets
    // dropped: an audit trail that forgets who did something when their access
    // ends is not an audit trail.
    const detail = await pair.studio.get(`/work-items/${pair.work.id}`);
    const kept = detail.comments.find((one) => one.body === `Said while active ${RUN}`);

    expect(kept, 'past attribution was lost with the site').toBeTruthy();
    expect(kept.from_client).toBe(true);
    expect(kept.author_name).toBe('Someone here');

    await pair.close();
  });

  test('D-8: a revoked key reaches nothing', async ({ browser, request }) => {
    const pair = await connectedPair(browser, 'Revoked Co', RUN);
    const site = asClientSite(request, pair.issued);

    const before = await site.get('/client/board');

    expect(before.status(), await before.text()).toBe(200);

    const revoked = await pair.studio.post(
      `/sites/${pair.issued.integration.registry_site_id}/revoke`,
      {}
    );

    expect(revoked.status(), await revoked.text()).toBe(200);

    // Every route it used to reach, with the same key that used to work.
    for (const route of [
      '/client/handshake',
      '/client/workspace',
      '/client/board',
      '/client/submissions',
      `/client/work-items/${pair.work.id}/comments`,
    ]) {
      const answer = await site.get(route);

      expect(answer.status(), `${route} still answers a revoked key`).toBe(401);
    }

    await pair.close();
  });

  // ---- D-9 -------------------------------------------------------------

  test('D-9: a captured signed request cannot be sent twice', async ({ browser, request }) => {
    const pair = await connectedPair(browser, 'Replaying Co', RUN);
    const site = asClientSite(request, pair.issued);

    const read = await site.replay('GET', '/client/board');

    expect(read.first.status(), await read.first.text()).toBe(200);
    expect(read.second.status(), 'a captured read was accepted twice').toBe(401);

    await pair.close();
  });

  test('D-9, D-26: a replayed write creates nothing the second time', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Duplicating Co', RUN);
    const site = asClientSite(request, pair.issued);

    const sent = await site.replay('POST', '/client/submissions', {
      type: 'request',
      title: `Only once ${RUN}`,
      description: 'Sent twice, recorded once.',
      submitted_by: 'Someone here',
    });

    expect(sent.first.status(), await sent.first.text()).toBe(200);
    expect(sent.second.status(), 'a captured write was accepted twice').toBe(401);

    // The one that matters: the studio holds one request, not two. A replay
    // refused after it has already written is a replay that worked.
    const fresh = asClientSite(request, pair.issued);
    const mine = await (await fresh.get('/client/submissions')).json();
    const matching = mine.submissions.filter((one) => one.title === `Only once ${RUN}`);

    expect(matching, 'a replayed write left a duplicate').toHaveLength(1);

    await pair.close();
  });

  // ---- The connection itself -------------------------------------------

  test('a signature from one site cannot be presented as another', async ({ browser, request }) => {
    const pair = await connectedPair(browser, 'Impersonating Co', RUN);
    const theirs = await somebodyElse(pair, 'Impersonated Co');

    const theirKey = await (
      await pair.studio.post(`/client-sites/${theirs.site.id}/integration/key`, {})
    ).json();

    // Their key, our site id on the header. The signature covers the site, so
    // this is not a request that verifies as either of them.
    const mixed = asClientSite(request, {
      key: theirKey.key,
      integration: { registry_site_id: pair.issued.integration.registry_site_id },
    });

    const answer = await mixed.get('/client/board');

    expect(answer.status(), 'a key from one site worked as another').toBe(401);

    await pair.close();
  });

  test('an unsigned request reaches none of it', async ({ request }) => {
    for (const route of ['/client/workspace', '/client/board', '/client/submissions']) {
      const answer = await request.get(`${STUDIO_URL}/wp-json/blueworx-forge/v1${route}`);

      expect(answer.status(), `${route} answers a stranger`).toBe(401);
    }
  });
});
