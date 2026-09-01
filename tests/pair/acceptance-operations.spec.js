import { test, expect } from '@playwright/test';
import {
  asClientSite,
  connectedPair,
  requireEnvironment,
  signedIn,
  studioSite,
  STUDIO_URL,
} from './helpers/pair.js';
import { giveChecklistTo, publishChecklist } from './helpers/onboarding.js';
import * as Forge from '../e2e/helpers/forge.js';
import { asSite } from '../helpers/signing.js';

// #181. The brief's §16 onboarding and operations criteria — AC-18 to AC-23 in
// Acceptance\Criteria — against a real studio and a real client site.
//
// These six are the ones a mock would flatter most. Four of them are round
// trips: something happens on one site and has to become visible on the other,
// through a cache, a signature and a cron run that only fires when somebody
// visits. Asserting them against a stub of the far side would prove the stub.
//
// The manifest's last slot. With these named, every criterion the brief states
// that is buildable today has a spec, and AcceptanceCoverageTest fails if one
// of them stops having one.

const RUN = `ops${Date.now()}`;

const ASK = '/wp-admin/admin.php?page=blueworx-forge-client-ask';
const CHECKLIST = '/wp-admin/admin.php?page=blueworx-forge-client-checklist';
const BOARD = '/wp-admin/admin.php?page=blueworx-forge-client-board';
const TIMELINE = '/wp-admin/admin.php?page=blueworx-forge-client-timeline';
const SYNC = '/wp-admin/admin.php?page=blueworx-forge-sync';

const TO_RELEASE = [
  'triage',
  'documentation-period',
  'technical-audit',
  'design-process',
  'up-next',
  'in-development',
  'in-review',
  'completed',
];

test.beforeAll(requireEnvironment);

/** The cards on the day's list about one record. */
async function cardsAbout(studio, subjectId) {
  const list = await studio.get('/standup');

  return (list.cards ?? []).filter((card) => card.subject_id === subjectId);
}

/** What the studio has recorded about telling this client. */
async function told(studio, submissionId) {
  const queue = await studio.get('/submissions');

  return (queue.submissions ?? []).find((each) => each.id === submissionId)?.notifications ?? [];
}

test.describe('the onboarding and operations acceptance criteria', () => {
  test('AC-18: work joins the day\'s list when it needs attention and leaves when it does not', async ({
    browser,
  }) => {
    test.slow();

    // A date long past, so this is late whichever day the suite runs on.
    const pair = await connectedPair(browser, 'Standup Co', RUN, {
      title: `Late thing ${RUN}`,
      planned_start: '2020-01-01',
      planned_due: '2020-01-02',
    });

    await expect
      .poll(async () => (await cardsAbout(pair.studio, pair.work.id)).map((card) => card.rule))
      .toContain('overdue');

    // Resolved by making it untrue, not by dismissing anything. There is
    // nothing to dismiss, which is the whole design: the list is worked out
    // from what is true rather than from what somebody has ticked off.
    const current = await pair.studio.get(`/work-items/${pair.work.id}`);
    const moved = await pair.studio.patch(`/work-items/${pair.work.id}`, {
      planned_start: '2099-01-01',
      planned_due: '2099-12-31',
      record_version: current.item.record_version,
    });

    expect(moved.status(), await moved.text()).toBe(200);

    await expect
      .poll(async () => (await cardsAbout(pair.studio, pair.work.id)).map((card) => card.rule))
      .not.toContain('overdue');

    await pair.close();
  });

  test('AC-19: a client submits an onboarding step and our decision comes back to them', async ({
    browser,
  }) => {
    test.setTimeout(360_000);

    const step = `Delegate the domain ${RUN}`;
    const pair = await connectedPair(browser, 'Onboarding Co', RUN);

    await publishChecklist(pair.studio, [step], RUN);
    await giveChecklistTo(pair.studio, pair.site.id);

    const page = await pair.clientSite.context.newPage();

    await page.goto(CHECKLIST);
    await expect(page.locator('[data-testid="bwx-checklist-next"]')).toContainText(step);

    await page
      .locator('[data-testid="bwx-checklist-response"]')
      .first()
      .fill('I think that is done.');
    await page.locator('[data-testid="bwx-checklist-submit"]').first().click();

    await expect(page.locator('[data-testid="bwx-checklist-status"]').first()).toContainText(
      'With us to check'
    );

    // The studio's half of the round trip: it is here, and it can be sent back.
    const listed = await pair.studio.get(`/onboarding/sites/${pair.site.id}/steps`);
    const submitted = listed.steps.find((each) => each.title === step);

    expect(submitted, 'the studio sees the step the client submitted').toBeTruthy();

    const returned = await pair.studio.post(`/onboarding/steps/${submitted.id}/review`, {
      decision: 'return',
      reason: 'The invitation has not arrived — please resend it.',
    });

    expect(returned.status(), await returned.text()).toBe(200);

    // And the client's half: the decision arrives on their own screen, with the
    // reason. The client site holds its copy for a minute, and the decision
    // happened outside that window, so this waits it out rather than forcing a
    // refresh — nobody clicks anything to be told their step came back.
    await expect
      .poll(
        async () => {
          await page.goto(CHECKLIST);

          return page.locator('[data-testid="bwx-checklist-feedback"]').count();
        },
        { timeout: 120_000, intervals: [5000] }
      )
      .toBeGreaterThan(0);

    await expect(page.locator('[data-testid="bwx-checklist-feedback"]').first()).toContainText(
      'The invitation has not arrived'
    );
    await expect(page.locator('[data-testid="bwx-checklist-status"]').first()).toContainText(
      'Needs another look'
    );

    await page.close();
    await pair.close();
  });

  test('AC-20: a site cannot go live while a launch-critical step is outstanding', async ({
    browser,
  }) => {
    test.setTimeout(480_000);

    const step = `Point the domain at us ${RUN}`;
    const pair = await connectedPair(browser, 'Launch Co', RUN, { title: `First release ${RUN}` });
    const crew = await Forge.team(pair.studio, browser, STUDIO_URL, pair.client.id);

    await publishChecklist(pair.studio, [{ title: step, launchCritical: true }], RUN);
    await giveChecklistTo(pair.studio, pair.site.id);

    let item = await Forge.walkTo(pair.studio, pair.work, TO_RELEASE, {
      seats: crew.seats,
      as: crew.as,
    });

    await Forge.satisfy(pair.studio, item, 'released');
    item = (await pair.studio.get(`/work-items/${item.id}`)).item;

    const refused = await crew.as.released.post(`/work-items/${item.id}/transition`, {
      to: 'released',
      record_version: item.record_version,
    });

    expect(refused.status()).toBe(409);

    // Named, not counted. "Onboarding is not finished" sends somebody looking;
    // naming the step sends them to the right place.
    expect(JSON.stringify(await refused.json())).toContain(step);

    const listed = await pair.studio.get(`/onboarding/sites/${pair.site.id}/steps`);
    const blocking = listed.steps.find((each) => each.title === step);

    expect(blocking, 'the site has the launch-critical step').toBeTruthy();

    const answered = await pair.studio.patch(`/onboarding/steps/${blocking.id}`, {
      response: 'Pointed at your nameservers.',
      status: 'submitted',
    });

    expect(answered.status(), await answered.text()).toBe(200);

    const approved = await pair.studio.post(`/onboarding/steps/${blocking.id}/review`, {
      decision: 'approve',
      reason: '',
    });

    expect(approved.status(), await approved.text()).toBe(200);

    // The same move, now that the step is done — so the refusal was about the
    // onboarding and not about anything else standing in the way.
    const current = (await pair.studio.get(`/work-items/${item.id}`)).item;
    const allowed = await crew.as.released.post(`/work-items/${item.id}/transition`, {
      to: 'released',
      record_version: current.record_version,
    });

    expect(allowed.status(), await allowed.text()).toBe(200);

    await crew.close();
    await pair.close();
  });

  test('AC-21: a client is told once, however many times the site asks', async ({ browser }) => {
    test.slow();

    const pair = await connectedPair(browser, 'Told Once Co', RUN);
    const title = `A booking form would help ${RUN}`;

    // Somebody to write to. Without one the answer is `suppressed`, which is a
    // correct outcome for a client with nobody on it and proves nothing about
    // being told once.
    await Forge.makePerson(pair.studio, pair.client.id, 'client_admin', 'recipient');

    const page = await pair.clientSite.context.newPage();

    await page.goto(ASK);
    await page.fill('#bwx-title', title);
    await page.fill('#bwx-description', 'Because it would help.');
    await page.click('#submit');
    await expect(page.locator('[data-bwx-result="sent"]')).toHaveCount(1);

    const queue = await pair.studio.get('/submissions');
    const ours = (queue.submissions ?? []).find((one) => one.title.includes(title));

    expect(ours, 'the request reached the studio').toBeTruthy();

    const raised = await told(pair.studio, ours.id);

    expect(raised).toHaveLength(1);
    expect(raised[0].outcome).toBe('raised');

    // Loading a Forge screen on the client's site is what triggers a run:
    // WP-Cron on a quiet site only fires when somebody visits, and these two
    // installs are shared, so the site will not check more than once a minute
    // however many screens are opened.
    await expect
      .poll(
        async () => {
          await page.goto(BOARD);

          return (await told(pair.studio, ours.id))[0]?.outcome ?? '';
        },
        { timeout: 180_000, intervals: [5000, 10_000, 20_000, 30_000] }
      )
      .not.toBe('raised');

    // Several runs later, still one, and still the same one. Exactly once is
    // not "one attempt" — it is one email however many times the site woke up
    // and looked.
    await page.goto(BOARD);
    await page.goto(BOARD);

    const settled = await told(pair.studio, ours.id);

    expect(settled).toHaveLength(1);
    expect(settled[0].id).toBe(raised[0].id);
    expect(['sent', 'retrying', 'failed']).toContain(settled[0].outcome);

    await page.close();
    await pair.close();
  });

  test('AC-22: one piece of work reads the same on every view of it', async ({
    browser,
    request,
  }) => {
    test.slow();

    const title = `Reconciled ${RUN}`;
    const pair = await connectedPair(browser, 'Agreeing Co', RUN, {
      title,
      planned_start: '2026-09-10',
      planned_due: '2026-09-10',
    });

    const moved = await Forge.walkTo(pair.studio, pair.work, ['triage']);

    expect(moved.stage).toBe('triage');

    // What the studio holds.
    const studioItem = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

    // What the client site is handed.
    const site = asClientSite(request, pair.issued);
    const board = await (await site.get('/client/board')).json();
    const card = board.items.find((one) => one.id === pair.work.id);

    expect(card, 'the work is on the client board').toBeTruthy();
    expect(card.stage, 'the board agrees with the studio').toBe(studioItem.stage);
    expect(card.title).toBe(studioItem.title);

    // And what the client actually sees, on two different screens, which is
    // where a view built from its own copy of the truth would disagree.
    const page = await pair.clientSite.context.newPage();

    await page.goto(BOARD);
    await expect(page.locator(`[data-bwx-stage="${studioItem.stage}"]`)).toContainText(title);

    await page.goto(TIMELINE);
    await expect(page.locator('body')).toContainText(title);

    await page.close();
    await pair.close();
  });

  test('AC-23: a site that has stopped talking to us is visible, and clears when it is fixed', async ({
    browser,
    request,
  }) => {
    test.slow();

    /*
     * A registered site with a key, and deliberately not a connected one.
     *
     * Broken means the last failure came strictly after the last success, and
     * Tenancy\Health resolves a tie in favour of the success on purpose — these
     * are whole seconds, and a busy site produces ties routinely. Connecting the
     * real client site first would put a success and this failure in the same
     * second on a fast machine, which is a race in the spec rather than
     * anything wrong with the product. The criterion is about the studio
     * noticing, and the studio does not need the far side to be listening in
     * order to notice it has stopped.
     */
    const studio = await signedIn(browser, STUDIO_URL);
    const registered = await studioSite(studio, 'Stalled Co', RUN);
    const speaking = asClientSite(request, registered.issued);

    // The commonest real fault, produced the real way: a well-formed request
    // signed with a key the studio does not hold. Nothing is written into the
    // record by hand, so the screen shows what a site failing in the field
    // would actually put there.
    const wrong = asSite(
      request,
      'not-the-key-this-site-was-given',
      registered.issued.integration.registry_site_id
    );

    expect((await wrong.get('/client/notifications')).status()).toBe(401);

    const page = await studio.context.newPage();

    await page.goto(SYNC);

    const entry = page.locator(`[data-bwx-sync-site="${registered.site.id}"]`);

    await expect(entry).toBeVisible();
    await expect(entry).toHaveAttribute('data-bwx-sync-reasons', /broken/);

    // Visible is not enough on its own: what it has to carry is what went
    // wrong and what to try.
    await expect(entry).toContainText('Failing');
    await expect(entry).toContainText('key was rotated here and not on the site');

    // Recoverable, which here means the site calls again with the key it was
    // given. Nothing clears the queue by hand, because nothing can.
    expect((await speaking.get('/client/notifications')).status()).toBe(200);

    await page.goto(SYNC);

    await expect(page.locator(`[data-bwx-sync-site="${registered.site.id}"]`)).toHaveCount(0);
    await expect(page.locator(`[data-bwx-sync-row="${registered.site.id}"]`)).toHaveAttribute(
      'data-bwx-sync-state',
      'connected'
    );

    await page.close();
    await studio.context.close();
  });
});
