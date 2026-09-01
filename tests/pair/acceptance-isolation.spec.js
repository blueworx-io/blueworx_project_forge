import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, requireEnvironment } from './helpers/pair.js';

// #179. The brief's §16 tenancy criteria — AC-7 to AC-10 in Acceptance\Criteria
// — proved against two real WordPress installs rather than against mocks, which
// is what the criteria themselves ask for: three of the four are statements
// about what crosses between the studio and a client site, and a mock of the
// far side would be a statement about the mock.
//
// The denial suite next door proves that individual routes refuse a client.
// These are the acceptance criteria above them: not "route X answers 401" but
// "the record appears once", "the edit reaches the right site", "another client
// cannot tell it exists", "the lock holds however it is approached".

const RUN = `iso${Date.now()}`;

const CLIENT_BOARD = '/wp-admin/admin.php?page=blueworx-forge-client-board';

test.beforeAll(requireEnvironment);

test.describe('the tenancy acceptance criteria', () => {
  test.beforeEach(() => {
    test.slow();
  });

  test('AC-7: work a client site creates appears once on the studio, and a replay adds nothing', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Asking Co', RUN);
    const site = asClientSite(request, pair.issued);
    const title = `Only once ${RUN}`;

    const sent = await site.replay('POST', '/client/submissions', {
      type: 'request',
      title,
      description: 'Sent twice, recorded once.',
      submitted_by: 'Someone here',
    });

    expect(sent.first.status(), await sent.first.text()).toBe(200);
    expect(sent.second.status(), 'a captured write was accepted twice').toBe(401);

    // Counted on the studio, which is where the criterion is about. Reading it
    // back as the client would prove the client's own view is consistent, which
    // is a different and weaker thing.
    const queue = await pair.studio.get('/submissions');
    const matching = queue.submissions.filter((one) => one.title === title);

    expect(matching, 'the studio holds one request, not two').toHaveLength(1);
    expect(matching[0].client_site_id).toBe(pair.site.id);

    await pair.close();
  });

  test('AC-8: an edit made on the studio reaches that site and no other', async ({
    browser,
    request,
  }) => {
    const mine = await connectedPair(browser, 'Edited Co', RUN, { title: `Edited ${RUN}` });
    const theirs = await somebodyElse(mine, RUN);

    const current = (await mine.studio.get(`/work-items/${mine.work.id}`)).item;
    const edited = await mine.studio.patch(`/work-items/${mine.work.id}`, {
      title: `Renamed on the studio ${RUN}`,
      record_version: current.record_version,
    });

    expect(edited.status(), await edited.text()).toBe(200);

    // The site the work belongs to sees the new title.
    const site = asClientSite(request, mine.issued);
    const board = await (await site.get('/client/board')).json();
    const card = board.items.find((one) => one.id === mine.work.id);

    expect(card, 'the work is on its own site').toBeTruthy();
    expect(card.title).toBe(`Renamed on the studio ${RUN}`);

    // And none of it reaches the other client's site, by the only route that
    // site has: its own signature.
    const other = asClientSite(request, theirs.issued);
    const otherBoard = await (await other.get('/client/board')).json();

    expect(
      otherBoard.items.find((one) => one.id === mine.work.id),
      'the edit reached another site'
    ).toBeFalsy();

    await mine.close();
  });

  test('AC-9: another client cannot read the record, and cannot infer that it exists', async ({
    browser,
    request,
  }) => {
    const mine = await connectedPair(browser, 'Private Co', RUN, { title: `Not yours ${RUN}` });
    const theirs = await somebodyElse(mine, RUN);

    const other = asClientSite(request, theirs.issued);

    // Answered as absent rather than as forbidden: "you may not see this" still
    // says it is there.
    const direct = await other.get(`/client/work-items/${mine.work.id}/comments`);

    expect(direct.status()).toBe(404);
    await expect(direct.text()).resolves.not.toContain(`Not yours ${RUN}`);

    // A real id belonging to somebody else answers exactly as an id that is
    // nothing at all. One that answers differently is one somebody can search
    // for.
    const invented = await other.get('/client/work-items/wrk_000000000000/comments');

    expect(
      `${direct.status()}:${(await direct.json()).code}`,
      'a real id is distinguishable from an invented one'
    ).toBe(`${invented.status()}:${(await invented.json()).code}`);

    // And nothing they can read counts it in.
    const board = await (await other.get('/client/board')).json();

    expect(board.items.find((one) => one.id === mine.work.id)).toBeFalsy();
    expect(JSON.stringify(board), 'the other client is named in the answer').not.toContain(
      `Not yours ${RUN}`
    );

    await mine.close();
  });

  test('AC-10: a client cannot move work by a control, a signed call, or a replay of one', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Locked Co', RUN, { title: `Held still ${RUN}` });
    const site = asClientSite(request, pair.issued);

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    // The screen offers nothing to move it with. A lock the interface invites
    // people to test is a lock people will keep testing.
    const page = await pair.clientSite.context.newPage();

    await page.goto(CLIENT_BOARD);
    await expect(page.locator('[data-testid="bwx-column"]').first()).toBeVisible();

    const work = page.locator('.bwx-work');

    await expect(work.locator('button')).toHaveCount(0);
    await expect(work.locator('select')).toHaveCount(0);
    await expect(work.locator('[draggable="true"]')).toHaveCount(0);

    await page.close();

    // The direct call, made with the real signature the site holds — which is
    // what makes the refusal mean something. Anything refuses a stranger.
    const called = await site.post(`/work-items/${pair.work.id}/transition`, {
      to: 'triage',
      record_version: before.record_version,
    });

    expect(called.status()).toBe(401);

    // And the same call replayed.
    const replayed = await site.replay('POST', `/work-items/${pair.work.id}/transition`, {
      to: 'triage',
      record_version: before.record_version,
    });

    expect(replayed.first.status()).toBe(401);
    expect(replayed.second.status()).toBe(401);

    // The assertion the other three are for: it never moved.
    const after = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    expect(after.stage).toBe(before.stage);
    expect(after.record_version).toBe(before.record_version);

    await pair.close();
  });
});

/**
 * A second client, site and key on the same studio, sharing the first pair's
 * signed-in browsers.
 *
 * Only the far side differs, so a whole second connectedPair would sign in to
 * both WordPress installs again for nothing.
 */
async function somebodyElse(first, runId) {
  const label = `Other ${runId}`;

  const client = await (
    await first.studio.post('/clients', { display_name: label, timezone: 'Europe/London' })
  ).json();

  const site = await (
    await first.studio.post(`/clients/${client.client.id}/sites`, {
      name: `${label} site`,
      url: process.env.BWX_CLIENT_BASE_URL,
    })
  ).json();

  const issued = await (
    await first.studio.post(`/client-sites/${site.site.id}/integration/key`, {})
  ).json();

  return {
    client: client.client,
    site: site.site,
    issued,
    work: await makeItem(first.studio, site.site.id, { title: `Theirs ${runId}` }),
  };
}
