import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, PASSWORD } from './helpers/forge.js';

// PR 1 of the move out of WordPress admin: a person's working week and time
// off over REST. Nothing here is deleted and the instance is reused, so every
// name carries a run id.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const BASE = '/wp-json/blueworx-forge/v1';

const WEEK = { hours_sun: 0, hours_mon: 8, hours_tue: 8, hours_wed: 8, hours_thu: 8, hours_fri: 4, hours_sat: 0 };

test.describe.configure({ mode: 'serial' });

let api;
let context;
let person;
let clientId;

test.beforeAll(async ({ browser, baseURL }) => {
  ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
  const where = await makeSite(api, 'Availability REST', RUN_ID);
  clientId = where.client.id;
  person = await makePerson(api, clientId, 'staff', `avail${STAMP}`);
});

test.afterAll(async () => {
  await context?.close();
});

test('a person nobody has set up reads as unrecorded, not as no hours', async () => {
  const answer = await api.get(`/users/${person.id}/availability`);

  expect(answer.ok).toBe(true);
  expect(answer.person.id).toBe(person.id);
  expect(answer.recorded).toBe(false);
  expect(answer.current).toBeNull();
  expect(answer.history).toEqual([]);
  expect(answer.leave).toEqual([]);
  expect(answer.week.days).toHaveLength(7);
});

test('an unknown person is a 404, not an empty answer', async () => {
  const response = await api.request.get(`${BASE}/users/usr_nobody${STAMP}/availability`, { headers: api.headers });

  expect(response.status()).toBe(404);
  expect((await response.json()).code).toBe('bwx_forge_unknown_user');
});

test('recording a week makes it the pattern in force and totals the next seven days', async () => {
  const wrote = await api.post(`/users/${person.id}/availability/hours`, { effective_from: '2020-01-01', ...WEEK });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.pattern.hours_week).toBe(36);
  expect(answer.recorded).toBe(true);
  expect(answer.current.id).toBe(answer.pattern.id);
  expect(answer.history).toHaveLength(1);
  // Seven days from any day of the week cover each weekday exactly once.
  expect(answer.week.hours).toBe(36);
});

test('a second week from a later date wins, and the first stays in the history', async () => {
  const wrote = await api.post(`/users/${person.id}/availability/hours`, { effective_from: '2021-01-01', ...WEEK, hours_fri: 8 });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.current.hours_week).toBe(40);
  expect(answer.history).toHaveLength(2);
});

test('a week until a date hands back to the one before it, and is kept in the history', async () => {
  // Reduced hours for one past month only: the 2021 week is in force again today.
  const wrote = await api.post(`/users/${person.id}/availability/hours`, {
    effective_from: '2022-03-01',
    effective_to: '2022-03-31',
    ...WEEK,
    hours_mon: 4,
    hours_tue: 4,
  });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.pattern.effective_to).toBe('2022-03-31');
  expect(answer.current.effective_from).toBe('2021-01-01');
  expect(answer.current.hours_week).toBe(40);
  expect(answer.history).toHaveLength(3);
});

test('an end before the start, or a day over twelve hours, is refused by field', async () => {
  const backwards = await api.post(`/users/${person.id}/availability/hours`, { effective_from: '2022-05-01', effective_to: '2022-04-01', ...WEEK });
  expect(backwards.status()).toBe(400);
  expect((await backwards.json()).data.fields.effective_to).toContain('on or after');

  const long = await api.post(`/users/${person.id}/availability/hours`, { effective_from: '2022-05-01', ...WEEK, hours_wed: 13 });
  expect(long.status()).toBe(400);
  expect((await long.json()).data.fields.hours_wed).toContain('12');

  // Neither was written.
  expect((await api.get(`/users/${person.id}/availability`)).history).toHaveLength(3);
});

test('a week without a real date is refused by field', async () => {
  const wrote = await api.post(`/users/${person.id}/availability/hours`, { effective_from: '2021-02-30', ...WEEK });

  expect(wrote.status()).toBe(400);
  const body = await wrote.json();
  expect(body.code).toBe('bwx_forge_invalid_availability');
  expect(body.data.fields.effective_from).toBeTruthy();
});

test('somebody who is not an administrator cannot read or write another person’s hours', async ({ browser, baseURL }) => {
  // Their own they may, from their profile (2026-09-18); this is somebody else's.
  const stranger = await makePerson(api, clientId, 'staff', `stranger${STAMP}`);
  const other = await signedIn(browser, baseURL, stranger.login, PASSWORD);

  const read = await other.api.request.get(`${BASE}/users/${person.id}/availability`, { headers: other.api.headers });
  expect(read.status()).toBe(403);

  const wrote = await other.api.post(`/users/${person.id}/availability/hours`, { effective_from: '2020-01-01', ...WEEK });
  expect(wrote.status()).toBe(403);

  await other.context.close();
});

test('time off is recorded, listed, and taken out of the week', async () => {
  // The last day cannot be before the first (2026-09-19); the same day is fine.
  const backwardsLeave = await api.post(`/users/${person.id}/leave`, { starts_on: '2020-01-10', ends_on: '2020-01-06', kind: 'leave' });
  expect(backwardsLeave.status()).toBe(400);
  expect((await backwardsLeave.json()).data.fields.ends_on).toContain('before the first');
  const oneDay = await api.post(`/users/${person.id}/leave`, { starts_on: '2020-02-03', ends_on: '2020-02-03', kind: 'leave' });
  expect(oneDay.status(), await oneDay.text()).toBe(200);

  const added = await api.post(`/users/${person.id}/leave`, {
    starts_on: '2020-01-06',
    ends_on: '2020-01-10',
    kind: 'leave',
    note: `Away ${RUN_ID}`,
  });
  expect(added.status(), await added.text()).toBe(200);

  const answer = await added.json();
  expect(answer.record.id).toMatch(/^una_/);
  expect(answer.record.kind).toBe('leave');
  expect(answer.record.starts_on).toBe('2020-01-06');
  // Outside the year-either-side window the listing shows, so not listed —
  // which is the admin page's behaviour too.
  expect(answer.leave.find((one) => one.id === answer.record.id)).toBeUndefined();
});

test('time off that overlaps this week reduces the hours available', async () => {
  const from = new Date();
  const to = new Date();
  to.setUTCDate(to.getUTCDate() + 6);
  const day = (d) => d.toISOString().slice(0, 10);

  const added = await api.post(`/users/${person.id}/leave`, { starts_on: day(from), ends_on: day(to), kind: 'training' });
  expect(added.status(), await added.text()).toBe(200);

  const answer = await added.json();
  expect(answer.record.kind).toBe('training');
  expect(answer.week.hours).toBe(0);
  expect(answer.leave.some((one) => one.id === answer.record.id)).toBe(true);
});

test('removing time off gives the hours back', async () => {
  const before = await api.get(`/users/${person.id}/availability`);
  const thisWeek = before.leave.find((one) => one.kind === 'training');
  expect(thisWeek).toBeTruthy();

  const removed = await api.request.delete(`${BASE}/users/${person.id}/leave/${thisWeek.id}`, { headers: api.headers });
  expect(removed.status(), await removed.text()).toBe(200);

  const answer = await removed.json();
  expect(answer.week.hours).toBe(40);
  expect(answer.leave.find((one) => one.id === thisWeek.id)).toBeUndefined();
});

test('a record that is not there is a 404', async () => {
  const removed = await api.request.delete(`${BASE}/users/${person.id}/leave/una_nothere${STAMP}`, { headers: api.headers });

  expect(removed.status()).toBe(404);
  expect((await removed.json()).code).toBe('bwx_forge_unknown_leave');
});

test('removing somebody else\'s record through this person is refused', async () => {
  const other = await makePerson(api, clientId, 'staff', `other${STAMP}`);

  const today = new Date();
  const tomorrow = new Date();
  tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
  const day = (d) => d.toISOString().slice(0, 10);

  const added = await api.post(`/users/${other.id}/leave`, {
    starts_on: day(today),
    ends_on: day(tomorrow),
    kind: 'leave',
  });
  expect(added.status(), await added.text()).toBe(200);
  const otherRecord = (await added.json()).record;

  const removed = await api.request.delete(`${BASE}/users/${person.id}/leave/${otherRecord.id}`, { headers: api.headers });
  expect(removed.status()).toBe(404);
  expect((await removed.json()).code).toBe('bwx_forge_unknown_leave');

  const answer = await api.get(`/users/${other.id}/availability`);
  expect(answer.leave.some((one) => one.id === otherRecord.id)).toBe(true);
});

test('a replayed leave under one retry key makes one record, not two', async () => {
  const key = `leave-${RUN_ID}`;
  const body = { starts_on: '2019-06-01', ends_on: '2019-06-02', kind: 'other' };
  const headers = { ...api.headers, 'Idempotency-Key': key };

  const first = await api.request.post(`${BASE}/users/${person.id}/leave`, { headers, data: body });
  const again = await api.request.post(`${BASE}/users/${person.id}/leave`, { headers, data: body });

  expect(first.status()).toBe(200);
  expect(again.status()).toBe(200);
  expect((await again.json()).record.id).toBe((await first.json()).record.id);
});

test('a leave with its dates the wrong way round is stored the right way round', async () => {
  const added = await api.post(`/users/${person.id}/leave`, { starts_on: '2019-03-10', ends_on: '2019-03-08', kind: 'leave' });
  expect(added.status(), await added.text()).toBe(200);

  const { record } = await added.json();
  expect(record.starts_on).toBe('2019-03-08');
  expect(record.ends_on).toBe('2019-03-10');
});

test('a leave with no real dates is refused by field', async () => {
  const added = await api.post(`/users/${person.id}/leave`, { starts_on: 'soon', ends_on: '', kind: 'leave' });

  expect(added.status()).toBe(400);
  const body = await added.json();
  expect(body.code).toBe('bwx_forge_invalid_availability');
  expect(body.data.fields.starts_on).toBeTruthy();
  expect(body.data.fields.ends_on).toBeTruthy();
});
