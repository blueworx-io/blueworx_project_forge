import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, makeItem, walkTo } from './helpers/forge.js';

// #138's acceptance in one spec: a person on two clients shows one combined
// commitment, not two pictures that each look comfortable. Nothing here is
// deleted and the instance is reused between runs, so every name carries a run
// id or the spec passes once and fails for ever after.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const PERSON = `capacity${STAMP}`;

// A fixed window in the future, and hours set on all seven days, so the
// arithmetic is the same whichever day the suite happens to run.
const FROM = '2026-09-07';
const TO = '2026-09-11';
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

// Patterns are written from the availability screen and nowhere else — #136
// deliberately gave them no REST route, since setting somebody up is
// configuration rather than work (ARCH-7). So this walks the screen.
//
// It does not sign in: the context already is. Signing in again would issue a
// fresh session and quietly invalidate the REST nonce the rest of the spec
// holds, which then fails several steps later as a cookie error with no
// visible cause.
async function setHoursThroughTheScreen(page, name, hours) {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-availability');
  await page.selectOption('#bwx-person', { label: name });
  await page.click('form[data-bwx-person-picker] input[type="submit"]');

  await page.fill('#bwx-effective-from', '2020-01-01');

  for (const day of DAYS) {
    await page.fill(`#bwx-hours_${day}`, String(hours));
  }

  await page.click('form[data-bwx-set-hours] input[type="submit"]');
  await expect(page.locator('[data-bwx-result="hours-set"]')).toBeVisible();
}

test('a person on two clients shows one combined commitment', async ({ browser, baseURL }) => {
  // Longer than the suite default, and not because anything here is slow: the
  // setup walks two items the whole way up the board, satisfying every gate on
  // the way, because only work at Up Next commits anybody's time. That is the
  // thing being proved, so there is no shorter honest route to it.
  test.setTimeout(240_000);

  const { context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  // Two clients, and one person working on both. The membership is per client
  // and the person is not, which is exactly what makes the double-count
  // possible if capacity is read per client.
  const first = await makeSite(api, 'Capacity one', RUN_ID);
  const second = await makeSite(api, 'Capacity two', RUN_ID);

  const person = await makePerson(api, first.client.id, 'staff', PERSON);

  const joined = await api.post(`/clients/${second.client.id}/memberships`, {
    user_id: person.id,
    role: 'staff',
  });
  expect(joined.status(), await joined.text()).toBe(200);

  const page = await context.newPage();
  await setHoursThroughTheScreen(page, PERSON, 8);
  await page.close();

  for (const where of [first, second]) {
    // The gate at Up Next wants all three seats filled, and a reviewer who is
    // not the person who did the work (AUTH-3).
    const reviewer = await makePerson(api, where.client.id, 'staff', `reviewer${STAMP}`);
    const deliverer = await makePerson(api, where.client.id, 'staff', `deliverer${STAMP}`);

    const created = await makeItem(api, where.site.id, { title: `Capacity work ${RUN_ID}` });
    expect(created.status(), await created.text()).toBe(200);

    await walkTo(
      api,
      (await created.json()).item,
      ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next'],
      {
        seats: {
          primary_user_id: person.id,
          reviewer_id: reviewer.id,
          deliverer_id: deliverer.id,
          planned_start: FROM,
          planned_due: TO,
          hours_primary: 6,
        },
      }
    );
  }

  const capacity = await api.get(`/capacity?from=${FROM}&to=${TO}`);
  const row = capacity.people.find((entry) => entry.user_id === person.id);

  expect(row, 'the person appears in the capacity read').toBeTruthy();
  expect(row.total.committed, 'both clients, one figure').toBe(12);
  expect(row.total.available).toBe(40);
  expect(row.total.remaining).toBe(28);

  const drill = await api.get(`/capacity/person/${person.id}?from=${FROM}&to=${TO}`);

  expect(drill.allocations, 'both pieces of work are behind the number').toHaveLength(2);
  expect(new Set(drill.allocations.map((entry) => entry.client_id)).size, 'one from each client').toBe(2);

  const summed = drill.allocations.reduce((total, entry) => total + entry.hours, 0);

  expect(summed, 'the drill-down reconciles to the total').toBe(row.total.committed);

  await context.close();
});

test('a signed-out caller gets nothing', async ({ request }) => {
  const response = await request.get(`/wp-json/blueworx-forge/v1/capacity?from=${FROM}&to=${TO}`);

  expect([401, 403]).toContain(response.status());
});
