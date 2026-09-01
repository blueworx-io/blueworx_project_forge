import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment, STUDIO_URL } from './helpers/pair.js';
import * as Forge from '../e2e/helpers/forge.js';

// #178. The brief's §16 workflow criteria — AC-1 to AC-6 in Acceptance\Criteria
// — proved against a real studio with a real client site connected to it.
//
// The rules themselves are already covered by the unit suite and by
// tests/e2e/workflow-rest.spec.js. What is different here is what the milestone
// asked for: the same criteria asserted with the client artifact present and
// connected, on its own WordPress, so that nothing being proved depends on the
// client half being absent.
//
// Each test names the criteria it proves in its title. AcceptanceCoverageTest
// reads those ids back out of this directory and fails if one the programme
// owes is not claimed anywhere.
//
// The board walk is why these are slow: a criterion about In Review is only
// reachable by satisfying every gate below it, one request at a time against a
// single-threaded PHP server. There is no shorter honest route to the stage the
// criterion is about.

const RUN = `acc${Date.now()}`;

const TO_REVIEW = [
  'triage',
  'documentation-period',
  'technical-audit',
  'design-process',
  'up-next',
  'in-development',
  'in-review',
];

test.beforeAll(requireEnvironment);

test.describe('the workflow acceptance criteria', () => {
  test('AC-1: a gate with an unmet requirement refuses the move and names all of them', async ({
    browser,
  }) => {
    const pair = await connectedPair(browser, 'Gate Co', RUN, { title: `Bare idea ${RUN}` });

    // Nothing filled in but what makeItem sets, so the first gate has plenty to
    // say — and the point is that it says all of it, not the first thing it
    // finds.
    const refused = await pair.studio.post(`/work-items/${pair.work.id}/transition`, {
      to: 'triage',
      record_version: pair.work.record_version,
    });

    expect(refused.status()).toBe(409);

    const body = await refused.json();

    expect(body.ok).toBe(false);
    expect(body.stage).toBe('future-idea');
    expect(body.attempted).toBe('triage');
    expect(body.unmet.length).toBeGreaterThanOrEqual(3);

    // All of them, not the first. Checked against what the item itself says is
    // outstanding rather than against a list written here, because a list
    // written here would go on passing after the gate changed.
    const outstanding = (await pair.studio.get(`/work-items/${pair.work.id}`)).readiness.triage
      .unmet;

    expect(body.unmet.map((each) => each.id).sort()).toEqual(
      outstanding.map((each) => each.id).sort()
    );

    // Every unmet requirement says how it would be met. A refusal nobody can
    // act on is a dead end rather than a gate.
    for (const requirement of body.unmet) {
      expect(requirement.satisfied_by, `${requirement.id} says how to satisfy it`).toBeTruthy();
    }

    // And the refusal moved nothing.
    const after = await pair.studio.get(`/work-items/${pair.work.id}`);

    expect(after.item.stage).toBe('future-idea');
    expect(after.item.record_version).toBe(pair.work.record_version);

    await pair.close();
  });

  test('AC-2: a transition is recorded with who moved it, when, and between which stages', async ({
    browser,
  }) => {
    const pair = await connectedPair(browser, 'Recorded Co', RUN, { title: `Moved once ${RUN}` });

    const moved = await Forge.walkTo(pair.studio, pair.work, ['triage']);

    expect(moved.stage).toBe('triage');

    const history = (await pair.studio.get(`/work-items/${pair.work.id}`)).history;
    const entry = history.find((event) => 'moved' === event.action);

    expect(entry, JSON.stringify(history)).toBeTruthy();
    expect(entry.from_stage).toBe('future-idea');
    expect(entry.to_stage).toBe('triage');
    expect(Number(entry.actor), 'a person, not a system').toBeGreaterThan(0);
    expect(entry.occurred_at, 'and when').toBeGreaterThan(0);

    await pair.close();
  });

  test('AC-3: only the assigned Reviewer can approve In Review to Completed', async ({
    browser,
  }) => {
    test.setTimeout(360_000);

    const pair = await connectedPair(browser, 'Reviewed Co', RUN, { title: `Needs review ${RUN}` });
    const crew = await Forge.team(pair.studio, browser, STUDIO_URL, pair.client.id);

    let item = await Forge.walkTo(pair.studio, pair.work, TO_REVIEW, { seats: crew.seats });

    await Forge.satisfy(pair.studio, item, 'completed');
    item = (await pair.studio.get(`/work-items/${item.id}`)).item;

    // The administrator is not the Reviewer. Rank does not stand in for
    // assignment.
    const byAdmin = await pair.studio.post(`/work-items/${item.id}/transition`, {
      to: 'completed',
      record_version: item.record_version,
    });

    expect(byAdmin.status()).toBe(403);
    expect((await byAdmin.json()).data.capability).toBe('approve_review');

    // The person the item names may.
    const approved = await crew.as.completed.post(`/work-items/${item.id}/transition`, {
      to: 'completed',
      record_version: item.record_version,
    });

    expect(approved.status(), await approved.text()).toBe(200);
    expect((await approved.json()).item.stage).toBe('completed');

    await crew.close();
    await pair.close();
  });

  test('AC-4: only the assigned Deliverer can confirm Completed to Released', async ({
    browser,
  }) => {
    test.setTimeout(360_000);

    const pair = await connectedPair(browser, 'Delivered Co', RUN, { title: `Needs release ${RUN}` });
    const crew = await Forge.team(pair.studio, browser, STUDIO_URL, pair.client.id);

    let item = await Forge.walkTo(pair.studio, pair.work, [...TO_REVIEW, 'completed'], {
      seats: crew.seats,
      as: crew.as,
    });

    await Forge.satisfy(pair.studio, item, 'released');
    item = (await pair.studio.get(`/work-items/${item.id}`)).item;

    const byAdmin = await pair.studio.post(`/work-items/${item.id}/transition`, {
      to: 'released',
      record_version: item.record_version,
    });

    expect(byAdmin.status()).toBe(403);
    expect((await byAdmin.json()).data.capability).toBe('confirm_release');

    const released = await crew.as.released.post(`/work-items/${item.id}/transition`, {
      to: 'released',
      record_version: item.record_version,
    });

    expect(released.status(), await released.text()).toBe(200);
    expect((await released.json()).item.stage).toBe('released');

    await crew.close();
    await pair.close();
  });

  test('AC-5: a failed review sends the work back carrying the feedback that failed it', async ({
    browser,
  }) => {
    test.setTimeout(360_000);

    const pair = await connectedPair(browser, 'Bounced Co', RUN, { title: `Sent back ${RUN}` });
    const crew = await Forge.team(pair.studio, browser, STUDIO_URL, pair.client.id);

    const item = await Forge.walkTo(pair.studio, pair.work, TO_REVIEW, { seats: crew.seats });

    // Part way through the review, then back it goes.
    await pair.studio.post(`/work-items/${item.id}/gate`, {
      requirement: 'G-IN-REVIEW-1',
      value: 'Checked the first half.',
    });

    // Sending work back is not a thing that can be done silently: the feedback
    // is what the next attempt is against.
    const silent = await pair.studio.post(`/work-items/${item.id}/return`, {
      to: 'in-development',
      reason: 'Not good enough.',
      record_version: item.record_version,
    });

    expect(silent.status()).toBe(400);
    expect((await silent.json()).code).toBe('bwx_forge_feedback_required');

    const bounced = await pair.studio.post(`/work-items/${item.id}/return`, {
      to: 'in-development',
      reason: 'The empty state is missing.',
      feedback: 'Two of the acceptance criteria are not met, and there is no empty state.',
      record_version: item.record_version,
    });

    expect(bounced.status(), await bounced.text()).toBe(200);

    const after = await pair.studio.get(`/work-items/${item.id}`);

    expect(after.item.stage).toBe('in-development');
    expect(after.item.review_attempt).toBe(2);

    // The half-finished review does not count towards the next one.
    expect(after.records['G-IN-REVIEW-1']).toBeUndefined();

    const feedback = after.history[after.history.length - 1];

    expect(feedback.detail).toContain('no empty state');

    await crew.close();
    await pair.close();
  });

  test('AC-6: work leaving Blocked returns to exactly the stage it was blocked in', async ({
    browser,
  }) => {
    const pair = await connectedPair(browser, 'Blocked Co', RUN, { title: `Waiting ${RUN}` });

    const item = await Forge.walkTo(pair.studio, pair.work, ['triage', 'documentation-period']);

    const blocked = await pair.studio.post(`/work-items/${item.id}/block`, {
      reason: 'Waiting on the client.',
      owner: 'Jo',
      dependency: 'Their sign-off on the copy.',
      target_date: '2026-09-15',
      next_action: 'Chase on Monday.',
      record_version: item.record_version,
    });

    expect(blocked.status(), await blocked.text()).toBe(200);

    const paused = (await blocked.json()).item;

    expect(paused.stage).toBe('blocked');
    expect(paused.prior_stage).toBe('documentation-period');

    // Blocked work does not go forward while it waits.
    const forward = await pair.studio.post(`/work-items/${item.id}/transition`, {
      to: 'technical-audit',
      record_version: paused.record_version,
    });

    expect(forward.status()).toBe(409);

    const resumed = await pair.studio.post(`/work-items/${item.id}/unblock`, {
      resolution: 'They signed it off.',
      record_version: paused.record_version,
    });

    expect(resumed.status(), await resumed.text()).toBe(200);

    const back = (await resumed.json()).item;

    // Exactly it — not the start of the stage list, and not the next one along.
    expect(back.stage).toBe('documentation-period');
    expect(back.prior_stage).toBe('');

    await pair.close();
  });
});
