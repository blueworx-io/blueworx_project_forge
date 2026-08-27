import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, requireEnvironment } from './helpers/pair.js';

// #135, the denial half: every way a client could try to move work, rewrite a
// record or reach past its own pipeline, attempted against the real client
// artifact with the real credential it holds.
//
// **A client site has exactly one authority: its signature.** So each of these
// is attempted with a valid one, which is what makes the refusals mean
// something — anything refuses a stranger. What is being proved is that a site
// the studio *has* vouched for still cannot do any of this.
//
// The workflow block is walked from the list rather than written out, because
// the list is the manifest: Tenancy\Denials names D-10 to D-19 and this walks
// every route they could be attempted by. A new workflow route added later gets
// covered by the same loop, which is the only kind of coverage that survives.
//
// Each test names the denials it proves. tests/php/DenialCoverageTest fails if
// one this milestone owes is not named in this directory.

const RUN = `deny${Date.now()}`;

test.beforeAll(requireEnvironment);

// Every route that moves work, and what a caller would send it. The ids are
// filled in per test; what matters is that all of them are attempted.
const MOVES = [
  { denial: 'D-10', path: 'transition', body: { to: 'triage' } },
  { denial: 'D-11', path: 'return', body: { to: 'future-idea', reason: 'Because.' } },
  { denial: 'D-12', path: 'block', body: { reason: 'Waiting.' } },
  { denial: 'D-12', path: 'unblock', body: { resolution: 'Done waiting.' } },
  { denial: 'D-13', path: 'outcome', body: { outcome: 'cancelled', reason: 'Because.' } },
  { denial: 'D-14', path: 'transition', body: { to: 'completed' } },
  { denial: 'D-15', path: 'transition', body: { to: 'released' } },
  { denial: 'D-16', path: 'reopen', body: { to: 'in-development', reason: 'Again.' } },
  { denial: 'D-18', path: 'gate', body: { requirement: 'G-FUTURE-IDEA-2', value: 'Yes.' } },
  { denial: 'D-19', path: 'override', body: { to: 'released', reason: 'Because I say so.' } },
];

test.describe('what a client site is refused', () => {
  test.beforeEach(() => {
    test.slow();
  });

  // ---- D-10 to D-19 ----------------------------------------------------

  test('D-10 to D-19: no route that moves work answers a client site', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Still Co', RUN);
    const site = asClientSite(request, pair.issued);

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    for (const move of MOVES) {
      const answer = await site.post(`/work-items/${pair.work.id}/${move.path}`, {
        ...move.body,
        record_version: before.record_version,
      });

      expect(
        answer.status(),
        `${move.denial}: /${move.path} answered a client site`
      ).toBe(401);
    }

    // The assertion the whole block is for: after every one of those, the work
    // is exactly where it started. A refusal that still moved something is not
    // a refusal.
    const after = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    expect(after.stage).toBe(before.stage);
    expect(after.record_version).toBe(before.record_version);
    expect(after.terminal_outcome).toBe(before.terminal_outcome);

    await pair.close();
  });

  test('D-17: the stage cannot be written as a field either', async ({ browser, request }) => {
    const pair = await connectedPair(browser, 'Direct Co', RUN);
    const site = asClientSite(request, pair.issued);

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    // From the client site: the edit route does not answer it at all.
    const asClient = await site.patch(`/work-items/${pair.work.id}`, {
      stage: 'released',
      record_version: before.record_version,
    });

    expect(asClient.status(), 'a client site reached the edit route').toBe(401);

    // And from the studio, where somebody *can* edit: the stage is not a field
    // an edit may set. This is the half of D-17 that is not about permission —
    // Work\Transition is the only door, and an edit naming a stage does not
    // become a quiet second one.
    const edited = await pair.studio.patch(`/work-items/${pair.work.id}`, {
      stage: 'released',
      prior_stage: 'completed',
      record_version: before.record_version,
    });

    const after = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    expect(after.stage, `an edit moved the work (${edited.status()})`).toBe(before.stage);

    await pair.close();
  });

  // ---- D-20, D-21, D-22, D-24 ------------------------------------------

  test('D-20, D-21: a client site cannot edit any field of its work', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Editing Co', RUN);
    const site = asClientSite(request, pair.issued);

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    // Accountability, planning and commercial in one attempt, plus the
    // definition — a client edits definition fields on the studio interface
    // while an item is still being documented (AUTH-2), and there is no route
    // on this connection through which any of it can be reached.
    const edit = {
      title: 'Rewritten by the client',
      priority: 'urgent',
      commercial_class: 'free_bug',
      primary_user_id: 'usr_theirs',
      planned_due: '2030-01-01',
      record_version: before.record_version,
    };

    for (const attempt of [site.patch(`/work-items/${pair.work.id}`, edit), site.post('/work-items', edit)]) {
      const answer = await attempt;

      expect(answer.status(), 'an edit route answered a client site').toBe(401);
    }

    const after = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    for (const field of ['title', 'priority', 'commercial_class', 'primary_user_id', 'planned_due']) {
      expect(after[field], `${field} was changed`).toBe(before[field]);
    }

    await pair.close();
  });

  test('D-22, D-24: the changelog and the derived state are nobody\'s to write', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Rewriting Co', RUN);
    const site = asClientSite(request, pair.issued);

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;
    const history = (await pair.studio.get(`/work-items/${pair.work.id}`)).history;

    expect(history.length, 'the item has no history to try to rewrite').toBeGreaterThan(0);

    // There is no route on this connection that names an event at all, so
    // these are 404s rather than 403s — which is the stronger answer. A route
    // that does not exist cannot be called.
    for (const route of [
      `/client/work-items/${pair.work.id}/history`,
      `/client/work-items/${pair.work.id}/events`,
      `/work-items/${pair.work.id}/events/${history[0].id}`,
    ]) {
      const answer = await site.post(route, { reason: 'Rewritten.' });

      expect([401, 404], `${route} answered a client site`).toContain(answer.status());
    }

    // And the derived fields are not columns anybody writes, from either side.
    const parent = await makeItem(pair.studio, pair.site.id, {
      title: `A parent ${RUN}`,
      level: 'feature',
    });

    const patched = await pair.studio.patch(`/work-items/${parent.id}`, {
      progress: 100,
      derived_state: 'completed',
      record_version: parent.record_version,
    });

    const after = (await pair.studio.get(`/work-items/${parent.id}`)).item;

    expect(after.progress, `progress was written (${patched.status()})`).not.toBe(100);

    // The history is still whole.
    const kept = (await pair.studio.get(`/work-items/${pair.work.id}`)).history;

    expect(kept.length).toBeGreaterThanOrEqual(history.length);
    expect(kept[0].id).toBe(history[0].id);
    expect((await pair.studio.get(`/work-items/${pair.work.id}`)).item.stage).toBe(before.stage);

    await pair.close();
  });

  // ---- D-25 ------------------------------------------------------------

  test('D-25: a write made against a version that has moved is refused', async ({ browser }) => {
    const pair = await connectedPair(browser, 'Stale Co', RUN);

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    const first = await pair.studio.patch(`/work-items/${pair.work.id}`, {
      title: `Changed once ${RUN}`,
      record_version: before.record_version,
    });

    expect(first.status(), await first.text()).toBe(200);

    // The same version again, carrying a different change. It was current when
    // it was read and is not now, which is exactly the case a lost update looks
    // like from inside.
    const stale = await pair.studio.patch(`/work-items/${pair.work.id}`, {
      title: `Changed twice ${RUN}`,
      record_version: before.record_version,
    });

    expect(stale.status(), 'a stale write was accepted').toBe(409);

    const after = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    expect(after.title, 'a stale write landed anyway').toBe(`Changed once ${RUN}`);

    await pair.close();
  });

  // ---- D-39, D-40 ------------------------------------------------------

  test('D-39: a submitted request cannot be edited afterwards', async ({ browser, request }) => {
    const pair = await connectedPair(browser, 'Fixed Co', RUN);
    const site = asClientSite(request, pair.issued);

    const sent = await site.post('/client/submissions', {
      type: 'request',
      title: `Exactly as written ${RUN}`,
      description: 'Every word of this, kept.',
      submitted_by: 'Someone here',
    });

    expect(sent.status(), await sent.text()).toBe(200);

    const submission = (await sent.json()).submission;

    // Every way it could be edited, from the side that wrote it. A POST to the
    // collection makes another request rather than replacing this one, which
    // is the product's own answer to changing your mind — so what is proved
    // here is that nothing addresses the existing record.
    const fresh = asClientSite(request, pair.issued);

    for (const route of [
      `/client/submissions/${submission.id}`,
      `/client/submissions/${submission.id}/edit`,
    ]) {
      const answer = await fresh.post(route, { title: 'Rewritten to match what we got' });

      expect([401, 404], `${route} answered a client site`).toContain(answer.status());
    }

    const mine = await (await asClientSite(request, pair.issued).get('/client/submissions')).json();
    const kept = mine.submissions.find((one) => one.id === submission.id);

    expect(kept.title).toBe(`Exactly as written ${RUN}`);
    expect(kept.description).toBe('Every word of this, kept.');

    await pair.close();
  });

  test('D-40: a client site cannot reach the conversion route at all', async ({
    browser,
    request,
  }) => {
    const pair = await connectedPair(browser, 'Converting Co', RUN);
    const site = asClientSite(request, pair.issued);

    const sent = await site.post('/client/submissions', {
      type: 'request',
      title: `Not theirs to convert ${RUN}`,
      description: 'A request.',
      submitted_by: 'Someone here',
    });

    expect(sent.status(), await sent.text()).toBe(200);

    const submission = (await sent.json()).submission;

    // A second client, whose pipeline this must never enter.
    const theirs = await (
      await pair.studio.post('/clients', {
        display_name: `Elsewhere Co ${RUN}`,
        timezone: 'Europe/London',
      })
    ).json();

    const theirSite = await (
      await pair.studio.post(`/clients/${theirs.client.id}/sites`, {
        name: `Elsewhere site ${RUN}`,
        url: 'https://elsewhere.test',
      })
    ).json();

    const fresh = asClientSite(request, pair.issued);
    const attempted = await fresh.post(`/submissions/${submission.id}/conversion`, {
      client_site_id: theirSite.site.id,
    });

    expect(attempted.status(), 'a client site reached the conversion route').toBe(401);

    // And from the studio, where conversion is allowed: naming another site in
    // the body changes nothing, because there is no such parameter.
    const converted = await pair.studio.post(`/submissions/${submission.id}/conversion`, {
      client_site_id: theirSite.site.id,
      client_id: theirs.client.id,
    });

    expect(converted.status(), await converted.text()).toBe(200);
    expect((await converted.json()).item.client_site_id).toBe(pair.site.id);

    await pair.close();
  });
});
