import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, makeItem, walkTo, satisfy } from './helpers/forge.js';

// #141, #142 and #143 against a real WordPress. The gate at the end of Up Next
// refuses work there is no room for, names who and when, and lets a studio
// administrator go ahead anyway for a reason that is then on the record.
//
// Nothing here is deleted and the instance is reused between runs, so every
// name carries a run id or the spec passes once and fails for ever after.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const PERSON = `overbooked${STAMP}`;

// A fixed week in the future, with hours on all seven days, so the arithmetic
// is the same whichever day the suite runs on. One working week at 8 hours a
// day is 56 hours available across the seven; the job asks for 90.
const FROM = '2026-10-05';
const TO = '2026-10-09';
const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const TOO_MANY_HOURS = 90;

// Patterns are written from the availability screen and nowhere else — #136
// gave them no REST route, since setting somebody up is configuration rather
// than work (ARCH-7). So this walks the screen.
//
// It does not sign in: the context already is. Signing in again would issue a
// fresh session and quietly invalidate the REST nonce the rest of the spec
// holds, which then fails several steps later as a cookie error with no
// visible cause.
async function setHoursThroughTheScreen(page, name, hours) {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-availability');
  await page.selectOption('#bwx-person', { label: name });
  await page.click('form[data-bwx-person-picker] input[type="submit"]');

  await expect(page.locator('[data-bwx-person-name]')).toHaveText(name);

  await page.fill('#bwx-effective-from', '2020-01-01');

  for (const day of DAYS) {
    await page.fill(`#bwx-hours_${day}`, String(hours));
  }

  await page.click('form[data-bwx-set-hours] input[type="submit"]');
  await expect(page.locator('[data-bwx-result="hours-set"]')).toBeVisible();
}

test('work with no room behind it is refused by name, and goes ahead for a reason', async ({
  browser,
  baseURL,
}) => {
  // Longer than the suite default, and not because anything is slow: the setup
  // walks an item the whole way up the board satisfying every gate, because
  // only work that has reached Up Next is weighed at all. That is the thing
  // being proved, so there is no shorter honest route to it.
  test.setTimeout(240_000);

  const { context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const where = await makeSite(api, 'Capacity gate', RUN_ID);
  const person = await makePerson(api, where.client.id, 'staff', PERSON);
  const reviewer = await makePerson(api, where.client.id, 'staff', `gatereviewer${STAMP}`);
  const deliverer = await makePerson(api, where.client.id, 'staff', `gatedeliverer${STAMP}`);

  const page = await context.newPage();
  await setHoursThroughTheScreen(page, PERSON, 8);
  await page.close();

  const created = await makeItem(api, where.site.id, { title: `Too much work ${RUN_ID}` });
  expect(created.status(), await created.text()).toBe(200);

  const parked = await walkTo(
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
        hours_primary: TOO_MANY_HOURS,
      },
    }
  );

  // Everything else the gate wants, so the only thing left is the capacity.
  const ready = await satisfy(api, parked, 'in-development');

  const refused = await api.post(`/work-items/${ready.id}/transition`, {
    to: 'in-development',
    record_version: ready.record_version,
  });

  expect(refused.status(), await refused.text()).toBe(409);

  const body = await refused.json();
  const capacity = body.unmet.find((requirement) => 'G-UP-NEXT-8' === requirement.id);

  expect(capacity, 'the capacity check refused the move').toBeTruthy();
  expect(capacity.over.length, 'somebody is named').toBeGreaterThan(0);
  expect(capacity.over[0].user_id).toBe(person.id);
  expect(capacity.over[0].display_name, 'named, not just identified').toBe(PERSON);
  expect(capacity.over[0].week_from, 'and the week is said').toBeTruthy();
  expect(capacity.over[0].excess).toBeGreaterThan(0);

  // Both system results are still reported, whichever way they went (#105).
  expect(body.checks.map((check) => check.id)).toContain('G-UP-NEXT-9');

  // CAP-4: it does not hard block. It costs a reason.
  const anyway = await api.post(`/work-items/${ready.id}/transition`, {
    to: 'in-development',
    record_version: ready.record_version,
    capacity_reason: 'The client has agreed the overtime for this release.',
  });

  expect(anyway.status(), await anyway.text()).toBe(200);
  expect((await anyway.json()).item.stage).toBe('in-development');

  const after = await api.get(`/work-items/${ready.id}`);

  expect(after.item.capacity_override_used, 'the item says it was over-booked').toBe(true);
  expect(after.item.capacity_override_reason).toContain('agreed the overtime');

  // CAP-E3: the workflow override is a different mark and must not be set.
  expect(after.item.override_used, 'not marked as a workflow correction').toBe(false);

  const entry = after.history.find((event) => 'over-allocated' === event.action);

  expect(entry, 'the decision is its own kind of history entry').toBeTruthy();
  expect(entry.reason).toContain('agreed the overtime');

  await context.close();
});

test('work that fits is not refused', async ({ browser, baseURL }) => {
  test.setTimeout(240_000);

  const { context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const where = await makeSite(api, 'Capacity gate fits', RUN_ID);
  const person = await makePerson(api, where.client.id, 'staff', `roomy${STAMP}`);
  const reviewer = await makePerson(api, where.client.id, 'staff', `roomyreviewer${STAMP}`);
  const deliverer = await makePerson(api, where.client.id, 'staff', `roomydeliverer${STAMP}`);

  const page = await context.newPage();
  await setHoursThroughTheScreen(page, `roomy${STAMP}`, 8);
  await page.close();

  const created = await makeItem(api, where.site.id, { title: `Reasonable work ${RUN_ID}` });

  const parked = await walkTo(
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
        hours_primary: 10,
      },
    }
  );

  const ready = await satisfy(api, parked, 'in-development');

  const moved = await api.post(`/work-items/${ready.id}/transition`, {
    to: 'in-development',
    record_version: ready.record_version,
  });

  expect(moved.status(), await moved.text()).toBe(200);

  const after = await api.get(`/work-items/${ready.id}`);

  expect(after.item.capacity_override_used, 'nothing to override').toBe(false);

  await context.close();
});
