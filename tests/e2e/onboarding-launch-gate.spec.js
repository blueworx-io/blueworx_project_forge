import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #166. A site cannot go live with launch-critical onboarding outstanding —
// and once it is live, this gate stays out of the way.
//
// The second half is the decision this issue turned on, and it is the half most
// worth a test: gating every release would mean an unticked box stopping a bug
// fix months after launch, and a gate standing between somebody and an urgent
// fix is one people learn to route around.
//
// Two sites, because the two halves need genuinely different histories. One is
// given its checklist before it has ever released anything; the other goes live
// first and is given a checklist afterwards, which is the only honest way to
// arrive at "already live, with something outstanding" — a checklist is fixed at
// the moment it is assigned and cannot be swapped afterwards (ONB-1).
//
// Nothing is ever deleted and the instance is kept between runs, so every name
// carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';
const CLIENTS = '/wp-admin/admin.php?page=blueworx-forge-clients';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

const UP_TO_COMPLETED = [
  'triage',
  'documentation-period',
  'technical-audit',
  'design-process',
  'up-next',
  'in-development',
  'in-review',
  'completed',
];

// Nothing signs in again here, and that is load-bearing. The context this
// spec's pages come from is already signed in, and logging in a second time
// rotates WordPress's cookies — which silently invalidates the REST nonce the
// API caller is holding, so every later write comes back "Cookie check failed"
// from somewhere nowhere near the login.

/** Publishes a checklist carrying one launch-critical step nobody has done. */
async function publishAChecklist(page, title) {
  await page.goto(TEMPLATE);

  const start = page.locator('[data-bwx-start-draft="1"]');

  if (await start.count()) {
    await page.fill('#bwx-template-name', `Gate ${RUN_ID}`);
    await start.locator('input[type="submit"]').click();
  } else if (await page.locator('[data-bwx-copy-template="1"]').count()) {
    await page.locator('[data-bwx-copy-template="1"] input[type="submit"]').first().click();
  }

  // Wait for the draft to actually be on screen before counting anything.
  // count() does not wait, so a page still navigating answers zero — which
  // silently skips the emptying below and leaves the accumulated steps in place.
  await expect(page.locator('[data-bwx-add-step="1"]')).toBeVisible();

  // Empty the draft first. The instance is kept between runs and a draft is
  // opened as a copy of what is published, so without this the template
  // accumulates a launch-critical step per run — and a test that finishes its
  // own step still finds the gate shut, for reasons that have nothing to do
  // with what it is testing.
  for (let guard = 0; guard < 50; guard += 1) {
    const remove = page.locator('[data-bwx-remove-step="1"]');

    if (!(await remove.count())) {
      break;
    }

    await remove.first().locator('input[type="submit"]').click();
    await page.waitForLoadState();
  }

  await page.fill('#bwx-step-title', title);
  await page.check('#bwx-step-launch-critical');
  await page.locator('[data-bwx-add-step="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="step-added"]')).toBeVisible();

  await page.locator('[data-bwx-publish-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="published"]')).toBeVisible();
}

/** Gives a site the current checklist, through the screen the studio uses. */
async function giveChecklistTo(page, siteId) {
  await page.goto(CLIENTS);

  const assign = page.locator(`li[data-bwx-site="${siteId}"] [data-bwx-assign-onboarding="1"]`);

  await expect(assign).toBeVisible();

  page.once('dialog', (dialog) => dialog.accept());
  await assign.locator('button').click();

  await expect(page.locator(`[data-bwx-onboarding="${siteId}"]`)).toBeVisible();
}

/**
 * A fresh item at Completed with the release gate already satisfied.
 *
 * Satisfying it here rather than leaving it to the move is what makes the
 * assertions below mean anything: with every ordinary requirement met, the only
 * thing left that can refuse the release is the onboarding gate. Without this
 * the refusal is a 409 either way and the test proves nothing.
 */
async function itemReadyToRelease(api, crew, siteId, title) {
  const created = await Forge.makeItem(api, siteId, { title });
  const body = await created.json();

  expect(body.item, `creating "${title}": ${JSON.stringify(body)}`).toBeTruthy();

  const completed = await Forge.walkTo(api, body.item, UP_TO_COMPLETED, {
    seats: crew.seats,
    as: crew.as,
  });

  expect(completed.stage).toBe('completed');

  return Forge.satisfy(api, completed, 'released');
}

test('a first go-live waits for onboarding', async ({ browser, baseURL }) => {
  // Nine gates, and a checklist built through the studio's own screens.
  test.setTimeout(300_000);

  const me = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(me.api, 'Launch Gate Co', RUN_ID);
  const crew = await Forge.team(me.api, browser, baseURL, client.id);

  const page = await me.context.newPage();
  const step = `Delegate the domain ${RUN_ID}`;

  await publishAChecklist(page, step);
  await giveChecklistTo(page, site.id);

  const ready = await itemReadyToRelease(me.api, crew, site.id, `First release ${RUN_ID}`);

  const refused = await crew.as.released.post(`/work-items/${ready.id}/transition`, {
    to: 'released',
    record_version: ready.record_version,
  });

  expect(refused.status()).toBe(409);

  // Named, not counted. "Onboarding is not finished" sends somebody looking;
  // naming the step sends them to the right place.
  expect(JSON.stringify(await refused.json())).toContain(step);

  // Finish the step, and the same move goes through — so the refusal was about
  // the onboarding and not about anything else in the way.
  const listed = await me.api.get(`/onboarding/sites/${site.id}/steps`);
  const blocking = listed.steps.find((each) => each.title === step);

  expect(blocking, 'the site should have the launch-critical step').toBeTruthy();

  const answered = await me.api.patch(`/onboarding/steps/${blocking.id}`, {
    response: 'Invited the named account.',
    status: 'submitted',
  });

  expect(answered.status(), await answered.text()).toBe(200);

  const approved = await me.api.post(`/onboarding/steps/${blocking.id}/review`, {
    decision: 'approve',
    reason: '',
  });

  expect(approved.status(), await approved.text()).toBe(200);

  const allowed = await crew.as.released.post(`/work-items/${ready.id}/transition`, {
    to: 'released',
    record_version: ready.record_version,
  });

  expect(allowed.status(), await allowed.text()).toBe(200);

  await crew.close();
  await page.close();
});

test('a site that is already live is not held up by its onboarding', async ({
  browser,
  baseURL,
}) => {
  // Two full walks: one to get the site live, one to prove the gate then stays
  // out of the way.
  test.setTimeout(420_000);

  const me = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(me.api, 'Already Live Co', RUN_ID);
  const crew = await Forge.team(me.api, browser, baseURL, client.id);

  // Live first, with no checklist at all. A site nobody has onboarded is not a
  // site failing its onboarding, so nothing stands in the way of this one.
  const firstReady = await itemReadyToRelease(me.api, crew, site.id, `Went live ${RUN_ID}`);

  const wentLive = await crew.as.released.post(`/work-items/${firstReady.id}/transition`, {
    to: 'released',
    record_version: firstReady.record_version,
  });

  expect(wentLive.status(), await wentLive.text()).toBe(200);

  // Now give them a checklist with something outstanding on it.
  const page = await me.context.newPage();

  await publishAChecklist(page, `Settle the mail provider ${RUN_ID}`);
  await giveChecklistTo(page, site.id);

  const secondReady = await itemReadyToRelease(me.api, crew, site.id, `Later fix ${RUN_ID}`);

  const later = await crew.as.released.post(`/work-items/${secondReady.id}/transition`, {
    to: 'released',
    record_version: secondReady.record_version,
  });

  // The whole decision, in one assertion: something outstanding, and the fix
  // ships anyway.
  expect(later.status(), await later.text()).toBe(200);

  await crew.close();
  await page.close();
});
