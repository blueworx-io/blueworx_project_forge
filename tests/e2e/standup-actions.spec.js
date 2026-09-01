import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #171. Fix the thing from where you find it.
//
// "An action a user may not take is refused there exactly as it is elsewhere"
// is the criterion, and it is a criterion about sameness rather than about
// refusal. Standup adds no route and no permission check of its own: opening a
// card opens the same panel the board opens, and recording an outstanding
// requirement posts to the same route the panel posts to. So the last test does
// not check that the refusal is good — it checks that it is the same one, in
// the same words, on both screens.
//
// The instance is kept between runs and other specs leave work behind, so every
// assertion here is scoped to this run's own item.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

const UP_TO_NEXT = [
  'triage',
  'documentation-period',
  'technical-audit',
  'design-process',
  'up-next',
];

/**
 * A piece of work parked at Up Next, which is where the day's list can say
 * something useful about it.
 *
 * Up Next is chosen rather than any earlier stage for two reasons that are the
 * same reason. It is work somebody has committed to, so an outstanding
 * requirement there is a real answer to "why has this not moved" — which is
 * exactly the test the rule itself applies (#251). And the gate it is sitting
 * behind is satisfied by recording completions rather than by filling in
 * fields, so there is something on the card for a person to actually do.
 */
async function withSomethingOutstanding(browser, baseURL) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, `Standup Actions Co ${RUN_ID}`, RUN_ID);
  const crew = await Forge.team(admin.api, browser, baseURL, client.id);

  const made = await Forge.makeItem(admin.api, site.id, {
    title: `Parked thing ${RUN_ID}`,
  });

  expect(made.status(), await made.text()).toBe(200);

  const item = await Forge.walkTo(admin.api, (await made.json()).item, UP_TO_NEXT, {
    seats: crew.seats,
  });

  return { admin, client, crew, item };
}

/** Opens the day's list and waits for it to arrive. */
async function openStandup(page) {
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-standup').click();

  // The first request after a page load pays this instance's cold start, which
  // is longer than the default expect timeout and nothing to do with the test.
  await expect(page.getByTestId('bwx-standup')).toBeVisible({ timeout: 30_000 });
}

/** This run's card about the work being held up. */
function stuckCard(page, itemId) {
  return page.locator(
    `[data-testid="bwx-standup-card"][data-subject="${itemId}"][data-rule="gate-unmet"]`
  );
}

/** One outstanding requirement somebody can record, off the item itself. */
async function outstanding(api, item) {
  const detail = await api.get(`/work-items/${item.id}`);
  const next = Object.keys(detail.readiness)[0];
  const found = (detail.readiness[next]?.unmet ?? []).find((one) => 'record' === one.by);

  expect(found, 'the parked work has something to record').toBeTruthy();

  return found;
}

test('a card about a piece of work opens that work', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, crew, item } = await withSomethingOutstanding(browser, baseURL);
  const page = await admin.context.newPage();

  await openStandup(page);

  const card = stuckCard(page, item.id);

  await expect(card).toBeVisible();

  // The same panel the board opens, on the item the card was about.
  await card.getByTestId('bwx-standup-open').click();

  const panel = page.getByTestId('bwx-panel');

  await expect(panel).toBeVisible({ timeout: 30_000 });
  await expect(panel).toContainText(`Parked thing ${RUN_ID}`);

  await page.close();
  await crew.close();
  await admin.context.close();
});

test('an outstanding requirement can be recorded from the card that named it', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, crew, item } = await withSomethingOutstanding(browser, baseURL);
  const requirement = await outstanding(admin.api, item);
  const page = await admin.context.newPage();

  await openStandup(page);

  const card = stuckCard(page, item.id);
  const row = card.locator(`[data-requirement="${requirement.id}"]`);

  await expect(row).toBeVisible();

  await row.getByTestId('bwx-open-record').click();
  await row.getByRole('textbox').first().fill('Done, from the day’s list.');
  await row.getByTestId('bwx-record').click();

  /*
   * Gone from the card, because the list was worked out again rather than
   * crossed off in the browser. Whether that was the last thing holding the
   * work up is the server's answer, and this is how the screen asks for it.
   */
  await expect(row).toHaveCount(0);

  // And still gone after a full reload, which a screen that only redrew itself
  // would not manage.
  await openStandup(page);
  await expect(card.locator(`[data-requirement="${requirement.id}"]`)).toHaveCount(0);

  await page.close();
  await crew.close();
  await admin.context.close();
});

test('somebody who may not record one is refused here in the same words as anywhere', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, client, crew, item } = await withSomethingOutstanding(browser, baseURL);
  const requirement = await outstanding(admin.api, item);

  // A viewer on our own side: allowed to read the work, not to sign anything
  // off. The distinction this test exists for.
  const viewer = await Forge.makePerson(admin.api, client.id, 'internal_viewer', 'viewer');
  const asViewer = await Forge.signedIn(browser, baseURL, viewer.login, Forge.PASSWORD);
  const page = await asViewer.context.newPage();

  await openStandup(page);

  const card = stuckCard(page, item.id);
  const row = card.locator(`[data-requirement="${requirement.id}"]`);

  await expect(row).toBeVisible();

  await row.getByTestId('bwx-open-record').click();
  await row.getByRole('textbox').first().fill('Signing this off anyway.');
  await row.getByTestId('bwx-record').click();

  const fromCard = await page.getByTestId('bwx-standup-notice').textContent();

  expect(fromCard, 'the card says why it would not').toBeTruthy();

  // Nothing was recorded. The refusal is not a message over a change that
  // happened anyway.
  await openStandup(page);
  await expect(stuckCard(page, item.id).locator(`[data-requirement="${requirement.id}"]`)).toBeVisible();

  /*
   * The same words on the item's own screen. This is the criterion: Standup
   * does not decide who may do what, so it cannot disagree with the screen that
   * does — there is only one refusal, arriving from one route.
   */
  await stuckCard(page, item.id).getByTestId('bwx-standup-open').click();

  const panel = page.getByTestId('bwx-panel');

  await expect(panel).toBeVisible({ timeout: 30_000 });

  const inPanel = panel.locator(`[data-requirement="${requirement.id}"]`).first();

  await inPanel.getByTestId('bwx-open-record').click();
  await inPanel.getByRole('textbox').first().fill('Signing this off anyway.');
  await inPanel.getByTestId('bwx-record').click();

  await expect(panel.getByTestId('bwx-panel-notice')).toContainText(fromCard.trim());

  await page.close();
  await asViewer.context.close();
  await crew.close();
  await admin.context.close();
});
