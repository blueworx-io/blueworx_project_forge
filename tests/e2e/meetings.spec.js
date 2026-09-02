import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #152 to #155, against a real WordPress. Standing meetings, the meetings they
// imply, and the hours they draw.
//
// The unit tests settle every rule. What is here is whether the whole thing
// works when it is wired together: a series added on a screen produces meetings
// on that screen, moving one moves one, and marking one held is the only thing
// that takes a client's hours.
//
// The instance is kept between runs, so every name carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');

const MEETINGS = '/wp-admin/admin.php?page=blueworx-forge-meetings';
const GRANTED = 200;

/** Monday a fortnight from now, so a series always has meetings ahead of it. */
function comingMonday() {
  const day = new Date(Date.now() + 14 * 86400000);

  day.setUTCDate(day.getUTCDate() + ((8 - day.getUTCDay()) % 7));

  return day.toISOString().slice(0, 10);
}

/** A site on a package, with somebody to host its meetings. */
async function withSite(browser, baseURL) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site, client } = await Forge.makeSite(admin.api, `Meetings Co ${RUN_ID}`, RUN_ID);

  await Forge.onSupport(admin, site.id, GRANTED);

  const host = await Forge.makePerson(admin.api, client.id, 'staff', `mtghost${STAMP}`);
  const page = await admin.context.newPage();

  await page.goto(`${MEETINGS}&site=${site.id}`);

  return { admin, site, host, page };
}

/** Adds a weekly two-hour meeting starting on a given Monday. */
async function addWeekly(page, host, startsOn) {
  await page.fill('#bwx-title', `Weekly catch-up ${RUN_ID}`);
  await page.selectOption('#bwx-frequency', 'weekly');
  await page.fill('#bwx-starts_on', startsOn);
  await page.fill('#bwx-time_of_day', '10:00');
  await page.fill('#bwx-duration_mins', '120');
  await page.fill('#bwx-timezone', 'Europe/London');
  await page.selectOption('#bwx-host', host.id);
  await page.click('#bwx-add-series');

  await expect(page.locator('[data-bwx-result="added"]')).toBeVisible();
}

test('a standing meeting produces the meetings it implies', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, page, host } = await withSite(browser, baseURL);
  const first = comingMonday();

  await addWeekly(page, host, first);

  // One arrangement, and the meetings it means for the next twelve weeks.
  await expect(page.locator('[data-bwx-series="1"]')).toBeVisible();
  await expect(page.locator(`[data-bwx-meeting="${first}"]`)).toBeVisible();

  const listed = await page.locator('[data-bwx-meeting]').count();

  expect(listed, 'a weekly meeting over twelve weeks').toBeGreaterThan(8);

  // Two hours a week, and the ones inside the horizon are held rather than
  // merely forecast (MEET-4).
  await expect(page.locator('[data-bwx-series-hours="2"]')).toBeVisible();
  await expect(page.locator('[data-bwx-ledger-state="reserved"]').first()).toBeVisible();

  await page.close();
  await admin.context.close();
});

test('moving one meeting moves one meeting', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, page, host } = await withSite(browser, baseURL);
  const first = comingMonday();

  await addWeekly(page, host, first);

  const before = await page.locator('[data-bwx-meeting]').evaluateAll((rows) =>
    rows.map((row) => row.getAttribute('data-bwx-meeting'))
  );

  // The second one, pushed out by two days.
  const moving = before[1];
  const to = new Date(`${moving}T00:00:00Z`);

  to.setUTCDate(to.getUTCDate() + 2);

  const landing = to.toISOString().slice(0, 10);
  const row = page.locator(`[data-bwx-meeting="${moving}"]`);

  await row.locator('input[type="date"]').fill(landing);
  await row.locator('[name="bwx-move"]').click();

  await expect(page.locator('[data-bwx-result="moved"]')).toBeVisible();

  const after = await page.locator('[data-bwx-meeting]').evaluateAll((rows) =>
    rows.map((row) => row.getAttribute('data-bwx-meeting'))
  );

  /*
   * #153's acceptance, checked as the whole list rather than as the moved date.
   * Every wrong implementation gets the moved meeting right and something else
   * wrong: rewriting the rule moves them all, and the meetings after it are
   * where that shows.
   */
  expect(after).toEqual(before.map((date) => (date === moving ? landing : date)));

  // And it says where it came from, so the odd date on a Wednesday explains
  // itself.
  await expect(page.locator(`[data-bwx-moved-from="${moving}"]`)).toBeVisible();

  await page.close();
  await admin.context.close();
});

test('only a meeting that was held costs the client anything', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, site, page, host } = await withSite(browser, baseURL);
  const first = comingMonday();

  await addWeekly(page, host, first);

  const dates = await page.locator('[data-bwx-meeting]').evaluateAll((rows) =>
    rows.map((row) => row.getAttribute('data-bwx-meeting'))
  );

  /*
   * The balance is not simply the granted hours less the spent ones: every
   * meeting still coming up inside twelve weeks is holding its own hours
   * (MEET-4), which is the point of a reservation. So what is asserted is
   * *spend* — the entries that actually cost the client something — rather
   * than a total that legitimately moves for a second reason.
   */
  const spent = (ledger) =>
    ledger.entries
      .filter(([type]) => 'meeting-usage' === type)
      .reduce((total, [, hours]) => total + Math.abs(hours), 0);

  // Held: two hours are spent.
  await page.locator(`[data-bwx-meeting="${dates[0]}"] [data-bwx-settle="held"]`).click();
  await expect(page.locator('[data-bwx-result="held"]')).toBeVisible();

  expect(spent(await Forge.hourLedger(admin, site.id))).toBe(2);

  // Cancelled, and nobody came: neither costs a thing (MEET-5).
  await page.locator(`[data-bwx-meeting="${dates[1]}"] [data-bwx-settle="cancelled"]`).click();
  await expect(page.locator('[data-bwx-result="cancelled"]')).toBeVisible();

  await page.locator(`[data-bwx-meeting="${dates[2]}"] [data-bwx-settle="no-show"]`).click();
  await expect(page.locator('[data-bwx-result="no-show"]')).toBeVisible();

  const after = await Forge.hourLedger(admin, site.id);

  expect(spent(after), 'still only the one meeting that happened').toBe(2);

  // And the two that did not happen gave back what they were holding.
  expect(
    after.entries.filter(([type]) => 'meeting-release' === type).length,
    'the cancelled one and the no-show both released'
  ).toBeGreaterThanOrEqual(2);

  await page.close();
  await admin.context.close();
});

test('ending a series gives back the hours its meetings were holding', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, page, host } = await withSite(browser, baseURL);

  await addWeekly(page, host, comingMonday());

  const held = (await Forge.hourLedger(admin, site.id)).balance;

  expect(held, 'meetings inside the horizon are holding hours').toBeLessThan(GRANTED);

  await page.locator('[id^="bwx-end-"]').first().click();
  await expect(page.locator('[data-bwx-result="ended"]')).toBeVisible();

  /*
   * Back to where it started. A series ended while its meetings still held
   * hours would leave a client's balance committed to meetings that are never
   * going to happen, and nothing afterwards would notice.
   */
  expect((await Forge.hourLedger(admin, site.id)).balance).toBe(GRANTED);

  await page.close();
  await admin.context.close();
});
