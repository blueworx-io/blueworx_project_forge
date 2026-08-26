import { test, expect } from '@playwright/test';
import {
  asClientSite,
  makeItem,
  makePerson,
  makeSite,
  makeSubmission,
  signedIn,
  PASSWORD,
} from './helpers/forge.js';

// #132, against a real WordPress: an accepted request becomes real work, for
// the right client, without losing what was asked.
//
// The unit tests in tests/php/WorkConversionTest prove the rules; this proves
// the route enforces them, which is a different claim. A rule that is right and
// a controller that asks it in the wrong order are exactly the pair that ships
// a boundary hole, so the two halves are tested separately on purpose.
//
// The isolation test is the one worth the runtime. Two clients are created, one
// request is sent from the first, and every id belonging to the second is
// offered to the conversion in turn.

const RUN = `conv${Date.now()}`;

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'wptest-admin-pw';

/**
 * A client, a site, a signing key and one request sent from it.
 *
 * The request is sent the way a client site sends one — signed, over the client
 * route — rather than written into the table, because which client a submission
 * belongs to is decided by that signature and nothing else. A fixture that
 * inserted the row would be testing the conversion against a claim the product
 * never makes.
 */
async function clientWithARequest(api, request, label, values = {}) {
  const { client, site } = await makeSite(api, label, RUN);
  const as = await asClientSite(api, site.id, request);
  const submission = await makeSubmission(as, values);

  return { client, site, as, submission };
}

test.describe('turning a request into work', () => {
  // Every test here signs in, registers a client and a site, issues a key and
  // sends a signed submission before it asserts anything, and the harness runs
  // WordPress on PHP's single-threaded built-in server. That preamble was
  // landing the odd run just the wrong side of the default timeout — at the
  // login, before the spec had done anything worth timing.
  test.beforeEach(() => {
    test.slow();
  });

  test('an accepted request becomes work, linked, with the words intact', async ({ browser, baseURL, request }) => {
    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const mine = await clientWithARequest(studio.api, request, 'Converting Co');

    const converted = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      entry_stage: 'future-idea',
    });

    expect(converted.status(), await converted.text()).toBe(200);

    const answer = await converted.json();

    // The work landed on the site the request came from, and nothing was asked
    // for it to.
    expect(answer.item.client_site_id).toBe(mine.site.id);
    expect(answer.item.client_id).toBe(mine.client.id);
    expect(answer.item.stage).toBe('future-idea');

    // And the two records are linked in both directions.
    expect(answer.submission.converted_item_id).toBe(answer.item.id);
    expect(answer.submission.intake_state).toBe('converted');

    await studio.context.close();
  });

  test('the original request survives conversion unchanged', async ({ browser, baseURL, request }) => {
    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const mine = await clientWithARequest(studio.api, request, 'Unchanged Co', {
      title: `Exactly what they typed ${RUN}`,
      description: 'Every word of this, kept.',
      desired_outcome: 'Nothing edits it.',
      evidence: 'https://example.test/proof',
    });

    // A title for the card that is deliberately not the request's, which is the
    // case where a careless conversion would write the new one back.
    const converted = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      entry_stage: 'future-idea',
      title: 'A different name for the card',
    });

    expect(converted.status(), await converted.text()).toBe(200);

    const answer = await converted.json();

    expect(answer.item.title).toBe('A different name for the card');

    for (const field of ['title', 'description', 'desired_outcome', 'evidence', 'submitted_by']) {
      expect(answer.submission[field], `${field} changed`).toBe(mine.submission[field]);
    }

    await studio.context.close();
  });

  test('a parent is created and the work hangs under it', async ({ browser, baseURL, request }) => {
    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const mine = await clientWithARequest(studio.api, request, 'Parented Co');

    const converted = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      entry_stage: 'future-idea',
      parent_title: `Bookings ${RUN}`,
      parent_level: 'project',
    });

    expect(converted.status(), await converted.text()).toBe(200);

    const item = (await converted.json()).item;

    expect(item.parent_id).not.toBe('');

    const parent = (await studio.api.get(`/work-items/${item.parent_id}`)).item;

    expect(parent.level).toBe('project');
    expect(parent.client_site_id).toBe(mine.site.id);

    await studio.context.close();
  });

  test('entering at Triage records what the conversion decided, rather than skipping the gate', async ({
    browser,
    baseURL,
    request,
  }) => {
    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const mine = await clientWithARequest(studio.api, request, 'Triaged Co');

    const converted = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      entry_stage: 'triage',
    });

    expect(converted.status(), await converted.text()).toBe(200);

    const item = (await converted.json()).item;

    expect(item.stage).toBe('triage');

    // The three the conversion answered, each a record with a person and a time
    // on it rather than a stage the system waved through.
    const detail = await studio.api.get(`/work-items/${item.id}`);
    const recorded = Object.keys(detail.records ?? {});

    for (const requirement of ['G-FUTURE-IDEA-2', 'G-FUTURE-IDEA-3', 'G-FUTURE-IDEA-4']) {
      expect(recorded, `${requirement} was not recorded`).toContain(requirement);
      expect(detail.records[requirement].actor, `${requirement} has no person on it`).toBeGreaterThan(0);
    }

    await studio.context.close();
  });

  test('an existing item can be linked instead, and stays where it is', async ({ browser, baseURL, request }) => {
    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const mine = await clientWithARequest(studio.api, request, 'Linking Co');

    const existing = (
      await (await makeItem(studio.api, mine.site.id, { title: `Already being done ${RUN}` })).json()
    ).item;

    const converted = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      item_id: existing.id,
    });

    expect(converted.status(), await converted.text()).toBe(200);

    const answer = await converted.json();

    expect(answer.item.id).toBe(existing.id);
    expect(answer.item.stage).toBe(existing.stage);
    expect(answer.submission.converted_item_id).toBe(existing.id);

    await studio.context.close();
  });

  test('a request is converted once', async ({ browser, baseURL, request }) => {
    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const mine = await clientWithARequest(studio.api, request, 'Once Co');

    const first = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {});

    expect(first.status(), await first.text()).toBe(200);

    const again = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {});

    expect(again.status()).toBe(409);
    expect((await again.json()).code).toBe('bwx_forge_already_converted');

    await studio.context.close();
  });

  // ---- D-40 -----------------------------------------------------------

  test('no id from another client can pull the work into their pipeline', async ({ browser, baseURL, request }) => {

    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

    const mine = await clientWithARequest(studio.api, request, 'Asking Co');
    const theirs = await makeSite(studio.api, 'Other Co', RUN);

    const theirParent = (
      await (
        await makeItem(studio.api, theirs.site.id, {
          title: `Their project ${RUN}`,
          level: 'feature',
        })
      ).json()
    ).item;

    // A parent belonging to somebody else.
    const withParent = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      parent_id: theirParent.id,
    });

    expect(withParent.status()).toBe(404);
    expect((await withParent.json()).code).toBe('bwx_forge_unknown_parent');

    // Linking to somebody else's work.
    const withLink = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      item_id: theirParent.id,
    });

    expect(withLink.status()).toBe(404);
    expect((await withLink.json()).code).toBe('bwx_forge_unknown_target');

    // A site named in the body, in every way it could plausibly be spelled.
    // The route declares none of these, so each is simply ignored — and the
    // work still lands on the site the request came from.
    const named = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      client_site_id: theirs.site.id,
      client_id: theirs.client.id,
      site_id: theirs.site.id,
    });

    expect(named.status(), await named.text()).toBe(200);
    expect((await named.json()).item.client_site_id).toBe(mine.site.id);

    await studio.context.close();
  });

  test("a parent that does not exist and one that is somebody else's answer identically", async ({
    browser,
    baseURL,
    request,
  }) => {
    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

    const mine = await clientWithARequest(studio.api, request, 'Comparing Co');
    const theirs = await makeSite(studio.api, 'Hidden Co', RUN);
    const theirItem = (await (await makeItem(studio.api, theirs.site.id, { title: `Hidden work ${RUN}`, level: 'feature' })).json()).item;

    const missing = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      parent_id: 'wrk_nothing_at_all',
    });

    const hidden = await studio.api.post(`/submissions/${mine.submission.id}/conversion`, {
      parent_id: theirItem.id,
    });

    expect(missing.status()).toBe(hidden.status());
    expect((await missing.json()).code).toBe((await hidden.json()).code);
    expect((await missing.json()).message).toBe((await hidden.json()).message);

    await studio.context.close();
  });

  test('a request out of reach is answered as one that is not there', async ({ browser, baseURL, request }) => {

    const studio = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const mine = await clientWithARequest(studio.api, request, 'Private Co');

    // Somebody with no membership on that client at all.
    const elsewhere = await makeSite(studio.api, 'Elsewhere Co', RUN);
    const outsider = await makePerson(studio.api, elsewhere.client.id, 'staff', 'outsider');
    const asOutsider = await signedIn(browser, baseURL, outsider.login, PASSWORD);

    const refused = await asOutsider.api.post(`/submissions/${mine.submission.id}/conversion`, {});

    expect(refused.status()).toBe(404);
    expect((await refused.json()).code).toBe('bwx_forge_no_such_submission');

    await asOutsider.context.close();
    await studio.context.close();
  });
});
