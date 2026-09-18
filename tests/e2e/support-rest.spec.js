import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, makePackage, PASSWORD } from './helpers/forge.js';

// PR 5 of the move out of WordPress admin: a site's support over REST — what
// it is on, every period it has been in, every hour it has, and the six
// things the admin page can do to it. Nothing here is deleted and the
// instance is reused, so every name carries a run id and every site is this
// run's own.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const BASE = '/wp-json/blueworx-forge/v1';
const TODAY = new Date().toISOString().slice(0, 10);
const HOURS = 40;

/** A date some days on from today, as YYYY-MM-DD. */
function daysOn(days) {
  const when = new Date();
  when.setUTCDate(when.getUTCDate() + days);
  return when.toISOString().slice(0, 10);
}

test.describe.configure({ mode: 'serial' });

let api;
let context;
let person;
let site;
let pkg;

test.beforeAll(async ({ browser, baseURL }) => {
  ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
  const where = await makeSite(api, 'Support REST', RUN_ID);
  site = where.site;
  person = await makePerson(api, where.client.id, 'staff', `sup${STAMP}`);
  pkg = await makePackage(api, `Support ${RUN_ID}`, { hours: HOURS, price: 1000 });
});

test.afterAll(async () => {
  await context?.close();
});

test('a site that has never been on a package reads as none, with nothing on the ledger', async () => {
  const answer = await api.get(`/client-sites/${site.id}/support`);

  expect(answer.ok).toBe(true);
  expect(answer.site.id).toBe(site.id);
  expect(answer.site.client_id).toBe(site.client_id);
  expect(answer.position.state).toBe('none');
  expect(answer.position.may_use_hours).toBe(false);
  expect(answer.position.covered_until).toBeNull();
  expect(answer.position.balance).toBe(0);
  expect(answer.periods).toEqual([]);
  expect(answer.ledger).toEqual([]);

  const offered = answer.packages.find((one) => one.id === pkg.id);
  expect(offered, 'the package just added is on offer').toBeTruthy();
  expect(offered.current.id).toBe(pkg.current.id);
  expect(offered.current.hours).toBe(HOURS);
});

test('a site that is not there is a 404', async () => {
  const read = await api.request.get(`${BASE}/client-sites/cs_${STAMP}/support`, { headers: api.headers });
  expect(read.status()).toBe(404);
  expect((await read.json()).code).toBe('bwx_forge_unknown_client_site');
});

test('somebody who is not an administrator cannot read a site\'s support, or put it on a package', async ({ browser, baseURL }) => {
  const other = await signedIn(browser, baseURL, person.login, PASSWORD);

  const read = await other.api.request.get(`${BASE}/client-sites/${site.id}/support`, { headers: other.api.headers });
  expect(read.status()).toBe(403);

  const wrote = await other.api.post(`/client-sites/${site.id}/support`, { package_version: pkg.current.id, starts_on: TODAY });
  expect(wrote.status()).toBe(403);

  await other.context.close();
});

test('the preview of a part-year assignment is exactly what assigning then writes (COMM-2)', async () => {
  const aligned = (await makeSite(api, 'Aligned', `${RUN_ID}-a`)).site;
  const until = daysOn(120);

  const preview = await api.get(
    `/client-sites/${aligned.id}/support/preview?package_version=${pkg.current.id}&from=${TODAY}&until=${until}`
  );
  expect(preview.ok).toBe(true);
  expect(preview.prorated).toBe(true);
  expect(preview.ends_on).toBe(until);
  expect(preview.hours).toBeGreaterThan(0);
  expect(preview.hours).toBeLessThan(HOURS);
  expect(preview.currency).toBe('GBP');

  const wrote = await api.post(`/client-sites/${aligned.id}/support`, {
    package_version: pkg.current.id,
    starts_on: TODAY,
    ends_on: until,
    note: `Aligned ${RUN_ID}`,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.assignment.hours_granted).toBe(preview.hours);
  expect(answer.assignment.price_charged).toBe(preview.price);
  expect(answer.assignment.prorated).toBe(true);
  expect(answer.assignment.ends_on).toBe(until);
  expect(answer.position.balance).toBe(preview.hours);
});

test('the preview with no end date is the full grant', async () => {
  const preview = await api.get(`/client-sites/${site.id}/support/preview?package_version=${pkg.current.id}&from=${TODAY}&until=`);

  expect(preview.ok).toBe(true);
  expect(preview.prorated).toBe(false);
  expect(preview.hours).toBe(HOURS);
  expect(preview.price).toBe(1000);
  expect(preview.ends_on).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(preview.ends_on > TODAY).toBe(true);

  const unknown = await api.request.get(`${BASE}/client-sites/${site.id}/support/preview?package_version=pkv_${STAMP}&from=${TODAY}&until=`, {
    headers: api.headers,
  });
  expect(unknown.status()).toBe(404);
});

test('assigning puts the site on support, with the package hours granted once through the ledger', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support`, { package_version: pkg.current.id, starts_on: TODAY });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.assignment.id).toMatch(/^spk_/);
  expect(answer.assignment.package_name).toBe(pkg.name);
  expect(answer.position.state).toBe('active');
  expect(answer.position.may_use_hours).toBe(true);
  expect(answer.position.covered_until).toBe(answer.assignment.ends_on);
  expect(answer.position.balance).toBe(HOURS);
  expect(answer.periods).toHaveLength(1);
  expect(answer.periods[0].id).toBe(answer.assignment.id);
  expect(answer.periods[0].package_name).toBe(pkg.name);
  expect(answer.ledger).toHaveLength(1);
  expect(answer.ledger[0].event_type).toBe('allocation');
  expect(answer.ledger[0].hours).toBe(HOURS);
  expect(answer.ledger[0].when).toBe(TODAY);
  expect(answer.ledger[0].source).toBe(`assignment:${answer.assignment.id}`);
});

test('assigning with a package that is not there is refused', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support`, { package_version: `pkv_${STAMP}`, starts_on: TODAY });
  expect(wrote.status()).toBe(400);
  expect((await wrote.json()).code).toBe('bwx_forge_support_refused');
});

test('a top-up adds the hours, and says what was bought', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/top-up`, { hours: 5, reason: `Bought ${RUN_ID}` });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.entry.event_type).toBe('top-up');
  expect(answer.entry.hours).toBe(5);
  expect(answer.position.balance).toBe(HOURS + 5);
  expect(answer.ledger.filter((one) => one.event_type === 'top-up')).toHaveLength(1);
  expect(answer.ledger.find((one) => one.event_type === 'top-up').reason).toBe(`Bought ${RUN_ID}`);
});

test('a top-up of no hours is refused', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/top-up`, { hours: 0, reason: 'Nothing' });
  expect(wrote.status()).toBe(400);
  expect((await wrote.json()).code).toBe('bwx_forge_support_refused');
});

test('an adjustment with a reason moves the balance either way', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/adjust`, { hours: -2, reason: `Written off ${RUN_ID}` });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.entry.event_type).toBe('adjustment');
  expect(answer.entry.hours).toBe(-2);
  expect(answer.entry.reason).toBe(`Written off ${RUN_ID}`);
  expect(answer.position.balance).toBe(HOURS + 3);
});

test('an adjustment without a reason is refused, and told why', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/adjust`, { hours: -1, reason: '' });
  expect(wrote.status()).toBe(400);

  const body = await wrote.json();
  expect(body.code).toBe('bwx_forge_reason_required');
  expect(body.message).toBe('Say why.');

  expect((await api.get(`/client-sites/${site.id}/support`)).position.balance).toBe(HOURS + 3);
});

test('an adjustment that would take the balance below nought is refused', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/adjust`, { hours: -(HOURS + 4), reason: 'Too much' });
  expect(wrote.status()).toBe(400);
  expect((await wrote.json()).code).toBe('bwx_forge_support_refused');

  expect((await api.get(`/client-sites/${site.id}/support`)).position.balance).toBe(HOURS + 3);
});

test('suspending stops the cover and leaves the hours alone', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/suspend`, { note: `Paused ${RUN_ID}` });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.position.state).toBe('suspended');
  expect(answer.position.may_use_hours).toBe(false);
  expect(answer.position.balance).toBe(HOURS + 3);
  expect(answer.periods).toHaveLength(2);
  expect(answer.periods[0].ended_because).toBe('suspended');
  expect(answer.periods[1].state).toBe('suspended');
  expect(answer.periods[1].note).toBe(`Paused ${RUN_ID}`);
});

test('suspending a site that is already suspended is refused', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/suspend`, {});
  expect(wrote.status()).toBe(400);
  expect((await wrote.json()).code).toBe('bwx_forge_support_refused');
});

test('resuming puts it back on support', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/resume`, {});
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.position.state).toBe('active');
  expect(answer.position.may_use_hours).toBe(true);
  expect(answer.position.balance).toBe(HOURS + 3);
  expect(answer.periods).toHaveLength(3);
  expect(answer.periods[2].began_because).toBe('resumed');
});

test('resuming a site that is not suspended is refused', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/resume`, {});
  expect(wrote.status()).toBe(400);
  expect((await wrote.json()).code).toBe('bwx_forge_support_refused');
});

test('cancelling ends the cover for good; the site reads as lapsed and its hours are frozen', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/cancel`, {});
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.position.state).toBe('lapsed');
  expect(answer.position.may_use_hours).toBe(false);
  expect(answer.position.balance).toBe(HOURS + 3);
  expect(answer.periods).toHaveLength(3);
  expect(answer.periods[2].ended_because).toBe('cancelled');
});

test('a second cancel has nothing to end and is refused', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/support/cancel`, {});
  expect(wrote.status()).toBe(400);
  expect((await wrote.json()).code).toBe('bwx_forge_support_refused');
});

test('a replayed assign under one retry key makes one period, not two', async () => {
  const replayed = (await makeSite(api, 'Replayed', `${RUN_ID}-r`)).site;
  const headers = { ...api.headers, 'Idempotency-Key': `support-${RUN_ID}` };
  const body = { package_version: pkg.current.id, starts_on: TODAY };

  const first = await api.request.post(`${BASE}/client-sites/${replayed.id}/support`, { headers, data: body });
  const again = await api.request.post(`${BASE}/client-sites/${replayed.id}/support`, { headers, data: body });

  expect(first.status(), await first.text()).toBe(200);
  expect(again.status(), await again.text()).toBe(200);
  expect((await again.json()).assignment.id).toBe((await first.json()).assignment.id);

  const answer = await api.get(`/client-sites/${replayed.id}/support`);
  expect(answer.periods).toHaveLength(1);
  expect(answer.ledger).toHaveLength(1);
  expect(answer.position.balance).toBe(HOURS);
});
