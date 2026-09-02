import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #150, COMM-3. Hours and capacity are two questions with two answers, and the
// gate at Up Next gives both of them every time.
//
// The unit tests settle what each answer should be. What is here is the thing
// only a real request can show: that the response a person actually receives
// carries both results and the figures behind the one that failed — because the
// failure this issue exists to prevent is somebody rearranging a week to fix a
// problem that was never about the week.
//
// The instance is kept between runs, so every name here carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');

const TO_UP_NEXT = ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next'];

// A fixed week in the future, so the arithmetic is the same whichever day the
// suite runs on.
const FROM = '2026-11-02';
const TO = '2026-11-06';
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

/**
 * A site, hours on its package, and three people to fill the seats.
 *
 * Its own people rather than the shared helper's, because one of these tests
 * has to pick a person out of a list by name — and the shared helper names
 * everybody it makes the same thing.
 */
async function withSite(browser, baseURL, hours) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site, client } = await Forge.makeSite(admin.api, `Gate Co ${RUN_ID}`, RUN_ID);

  if (hours > 0) {
    await Forge.onSupport(admin, site.id, hours);
  }

  const named = (role) => `${role}${STAMP}`;

  return {
    admin,
    api: admin.api,
    site,
    names: { primary: named('gateprimary') },
    people: {
      primary: await Forge.makePerson(admin.api, client.id, 'staff', named('gateprimary')),
      reviewer: await Forge.makePerson(admin.api, client.id, 'staff', named('gatereviewer')),
      deliverer: await Forge.makePerson(admin.api, client.id, 'staff', named('gatedeliverer')),
    },
  };
}

/**
 * Somebody's working week, written from the availability screen.
 *
 * #136 gave patterns no REST route, since setting somebody up is configuration
 * rather than work (ARCH-7). It matters here because a person with no pattern
 * is nobody the capacity check has an opinion about — so without this the
 * capacity answer cannot be made to fail, and the test that needs one failure
 * and one pass has nothing to test.
 */
async function giveWorkingHours(page, name, perDay) {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-availability');
  await page.selectOption('#bwx-person', { label: name });
  await page.click('form[data-bwx-person-picker] input[type="submit"]');

  await expect(page.locator('[data-bwx-person-name]')).toHaveText(name);
  await page.fill('#bwx-effective-from', '2020-01-01');

  for (const day of DAYS) {
    await page.fill(`#bwx-hours_${day}`, String(perDay));
  }

  await page.click('form[data-bwx-set-hours] input[type="submit"]');
  await expect(page.locator('[data-bwx-result="hours-set"]')).toBeVisible();
}

/** The seats and the plan. */
function planFor(people, hours) {
  return {
    primary_user_id: people.primary.id,
    reviewer_id: people.reviewer.id,
    deliverer_id: people.deliverer.id,
    planned_start: FROM,
    planned_due: TO,
    hours_primary: hours,
  };
}

test('the gate answers hours and capacity separately, and reports both', async ({
  browser,
  baseURL,
}) => {
  test.setTimeout(300_000);

  /*
   * Plenty of hours on the package and nowhere near enough room in the week:
   * 56 hours available across the seven days, and 90 asked of one person. So
   * the money answer passes while the time answer fails, which is the pair
   * this issue is about.
   */
  const { admin, api, site, people, names } = await withSite(browser, baseURL, 400);
  const page = await admin.context.newPage();

  await giveWorkingHours(page, names.primary, 8);
  await page.close();

  const created = await Forge.makeItem(api, site.id, { title: `Two answers ${RUN_ID}` });

  const planned = await Forge.walkTo(api, (await created.json()).item, TO_UP_NEXT, {
    seats: planFor(people, 90),
  });

  const ready = await Forge.satisfy(api, planned, 'in-development');

  const refused = await api.post(`/work-items/${ready.id}/transition`, {
    to: 'in-development',
    record_version: ready.record_version,
  });

  expect(refused.status(), await refused.text()).toBe(409);

  const body = await refused.json();
  const checks = Object.fromEntries(body.checks.map((check) => [check.id, check.result]));

  // Both, whichever way each went. One answer standing in for two is how
  // somebody fixes the wrong thing and is refused again for the other.
  expect(checks['G-UP-NEXT-8'], 'the capacity answer').toBe('fail');
  expect(checks['G-UP-NEXT-9'], 'the hours answer').toBe('pass');

  const unmet = body.unmet.map((requirement) => requirement.id);

  expect(unmet).toContain('G-UP-NEXT-8');
  expect(unmet, 'the hours were there, so they are not in the way').not.toContain('G-UP-NEXT-9');

  await admin.context.close();
});

test('a free bug passes the hours check on a site with no package at all', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  // The exemption written into the gate table: the check passes, *or* the item
  // is a COMM-5 free bug. Forge broke it, so there is nothing for a package to
  // authorise — and a client with no package still gets their bugs fixed.
  const { admin, api, site, people } = await withSite(browser, baseURL, 0);
  const created = await Forge.makeItem(api, site.id, {
    title: `Free bug ${RUN_ID}`,
    work_type: 'bug',
  });

  const triaged = await Forge.walkTo(api, (await created.json()).item, ['triage']);

  const reclassified = await api.patch(`/work-items/${triaged.id}`, {
    commercial_class: 'free-bug',
    record_version: triaged.record_version,
  });

  expect(reclassified.status(), await reclassified.text()).toBe(200);

  const planned = await Forge.walkTo(
    api,
    (await reclassified.json()).item,
    ['bug-tracking', 'documentation-period', 'technical-audit', 'design-process', 'up-next'],
    { seats: planFor(people, 10) }
  );

  const readiness = (await api.get(`/work-items/${planned.id}`)).readiness['in-development'];
  const answers = Object.fromEntries(readiness.checks.map((check) => [check.id, check.result]));

  expect(answers['G-UP-NEXT-9'], 'a free bug is not refused for hours').toBe('pass');
  expect(readiness.unmet.map((requirement) => requirement.id)).not.toContain('G-UP-NEXT-9');

  await admin.context.close();
});

test('a lapsed site cannot spend the hours it still has', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, api, site, people } = await withSite(browser, baseURL, 200);
  const created = await Forge.makeItem(api, site.id, { title: `Lapsed ${RUN_ID}` });

  const planned = await Forge.walkTo(api, (await created.json()).item, TO_UP_NEXT, {
    seats: planFor(people, 10),
  });

  // The package cancelled from today. COMM-4 freezes the balance rather than
  // voiding it, so the hours are still on the ledger and still not spendable —
  // which is the case a check that read the balance alone would get wrong.
  const page = await admin.context.newPage();

  await page.goto(`/wp-admin/admin.php?page=blueworx-forge-support&site=${site.id}`);

  // The form's date already reads today, and today is "the first day not
  // covered" — so from now on the site has hours and cannot spend them.
  await page.locator('#bwx-cancel').click();
  await expect(page.locator('[data-bwx-may-use-hours="no"]')).toBeVisible();
  await expect(page.locator('[data-bwx-balance]')).not.toHaveAttribute('data-bwx-balance', '0');
  await page.close();

  const ready = await Forge.satisfy(api, planned, 'in-development');

  const refused = await api.post(`/work-items/${ready.id}/transition`, {
    to: 'in-development',
    record_version: ready.record_version,
    capacity_reason: 'Nobody has a pattern on this test instance.',
  });

  expect(refused.status(), await refused.text()).toBe(409);

  const body = await refused.json();
  const hours = body.unmet.find((requirement) => 'G-UP-NEXT-9' === requirement.id);

  expect(hours, 'the hours check refused the move').toBeTruthy();
  expect(hours.hours.because).toBe('no_package');
  expect(hours.hours.needed).toBe(13);

  /*
   * And the capacity reason did not answer this question. It is a decision
   * about our own people's week; a client's package is not something a studio
   * administrator settles by explaining our overtime.
   */
  expect(body.unmet.map((requirement) => requirement.id)).not.toContain('G-UP-NEXT-8');

  await admin.context.close();
});
