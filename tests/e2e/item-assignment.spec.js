import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// Planning a piece of work from the panel it is opened in.
//
// Until now the item panel edited the prose and nothing else: the seats, the
// hours, the dates, the priority and the classification were in the record and
// in the API with no control anywhere, so the panel could list what a stage was
// waiting for and offer nowhere to put it. Nothing could be moved past Triage
// without somebody calling the API.
//
// So the first test is the whole point rather than a detail of it: take a piece
// of work to Up Next by clicking, which is the thing that was impossible.
//
// The instance is kept between runs, so every name carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

const TO_DESIGN = ['triage', 'documentation-period', 'technical-audit', 'design-process'];

/** A site with hours, a team, and one piece of work standing at a given stage. */
async function withWork(browser, baseURL, stages) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site, client } = await Forge.makeSite(admin.api, `Assign Co ${RUN_ID}`, RUN_ID);

  await Forge.onSupport(admin, site.id, 200);

  const people = await Forge.team(admin.api, browser, baseURL, client.id);
  const made = await Forge.makeItem(admin.api, site.id, { title: `Planning ${RUN_ID}` });
  const item = await Forge.walkTo(admin.api, (await made.json()).item, stages, {});

  return { admin, site, people, item };
}

/** The board, on one site, with that site's single card open. */
async function openTheItem(admin, siteId) {
  const page = await admin.context.newPage();

  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', siteId);
  await page.locator('[data-testid="bwx-card"]').click();
  await expect(page.locator('[data-testid="bwx-panel"]')).toBeVisible();

  return page;
}

/**
 * Ticks every requirement the next move is waiting somebody to confirm.
 *
 * By what is on the screen rather than by a list of ids: the requirements
 * belong to the stage the work is leaving, and hard-coding them here would tie
 * a test about the planning fields to whichever stage it happened to start in.
 */
async function recordWhatIsAsked(page) {
  const buttons = page.locator('[data-testid="bwx-open-record"]');

  for (let left = await buttons.count(); 0 < left; left -= 1) {
    /*
     * Held by its id rather than by "the first one still closed". Opening a
     * requirement takes its Record button away, so a locator defined by having
     * one stops pointing at the row the moment it is opened.
     */
    const id = await page
      .locator('li[data-requirement]')
      .filter({ has: page.locator('[data-testid="bwx-open-record"]') })
      .first()
      .getAttribute('data-requirement');

    const row = page.locator(`li[data-requirement="${id}"]`);

    await row.locator('[data-testid="bwx-open-record"]').click();

    // The second box, where there is one, wants a link to the evidence.
    const boxes = row.locator('.bwx-input');

    for (let box = 0, boxen = await boxes.count(); box < boxen; box += 1) {
      await boxes.nth(box).fill(0 === box ? 'Confirmed.' : 'https://example.test/evidence');
    }

    await row.locator('[data-testid="bwx-record"]').click();
    await expect(buttons).toHaveCount(left - 1);

    /*
     * Waiting for the open row to close, not just for the recorded one to go.
     * The panel clears which row is open once its reload finishes, and opening
     * the next one before that lands gets it closed again underneath us.
     */
    await expect(page.locator('[data-testid="bwx-record"]')).toHaveCount(0);
  }
}

test('a piece of work can be planned and moved to Up Next without leaving the board', async ({
  browser,
  baseURL,
}) => {
  test.setTimeout(600_000);

  const { admin, site, people, item } = await withWork(browser, baseURL, TO_DESIGN);
  const page = await openTheItem(admin, site.id);

  // Everything Up Next wants, filled in on the screen it is asked for on.
  await page.selectOption('#bwx-primary_user_id', people.primary.id);
  await page.fill('#bwx-hours_primary', '6');
  await page.selectOption('#bwx-reviewer_id', people.reviewer.id);
  await page.fill('#bwx-hours_review', '1');
  await page.selectOption('#bwx-deliverer_id', people.deliverer.id);
  await page.fill('#bwx-hours_delivery', '1');
  await page.fill('#bwx-planned_start', '2026-10-05');
  await page.fill('#bwx-planned_due', '2026-10-09');
  await page.locator('[data-testid="bwx-save"]').click();

  await expect(page.locator('[data-testid="bwx-panel-notice"]')).toHaveText('Saved.');

  // And the things the move asks somebody to confirm rather than to type.
  await recordWhatIsAsked(page);

  await page.locator('[data-testid="bwx-move"][data-to="up-next"]').click();

  /*
   * CAP-4. Nobody on a fresh instance has an availability pattern, so any
   * figure at all over-books them and the move costs a reason rather than
   * being refused. Tolerated rather than required: somebody with room would
   * pass straight through, and this test is about the planning fields.
   */
  const overrun = page.locator('[data-testid="bwx-overrun"]');

  await overrun.waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});

  if (0 < (await overrun.count())) {
    await page.fill('[data-testid="bwx-overrun-reason"]', 'Planned deliberately over.');
    await page.locator('[data-testid="bwx-overrun-go"]').click();
  }

  await expect(page.locator('[data-testid="bwx-panel-stage"]')).toHaveText('Up next');

  // And the plan is on the record, not only on the screen it was typed into.
  const saved = await admin.api.get(`/work-items/${item.id}`);

  expect(saved.item.primary_user_id).toBe(people.primary.id);
  expect(saved.item.hours_primary).toBe(6);
  expect(saved.item.planned_due).toBe('2026-10-09');

  await page.close();
  await people.close();
  await admin.context.close();
});

test('the panel says which field is holding the work up, and only that field', async ({
  browser,
  baseURL,
}) => {
  test.setTimeout(600_000);

  // At Triage, where the classification is what stands in the way. An item
  // arrives here unclassified, which the gate counts as unanswered.
  const { admin, site, people } = await withWork(browser, baseURL, ['triage']);
  const page = await openTheItem(admin, site.id);

  const marker = page.locator('[data-testid="bwx-needed"][data-field="commercial_class"]');

  await expect(marker).toContainText('needed to leave Triage');

  /*
   * And nothing is marked that is not wanted yet. A due date is wanted at Up
   * Next, four stages away — marking it here would put a warning on almost
   * every field of a new item, and a screen where everything is flagged flags
   * nothing.
   */
  await expect(page.locator('[data-testid="bwx-needed"][data-field="planned_due"]')).toHaveCount(0);

  // The marker goes when the field is answered, without waiting for a save.
  await page.selectOption('#bwx-commercial_class', 'chargeable');

  await expect(marker).toHaveCount(0);

  await page.close();
  await people.close();
  await admin.context.close();
});
