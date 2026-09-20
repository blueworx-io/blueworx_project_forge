import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, onSupport, hourLedger, PASSWORD } from './helpers/forge.js';

// PR 6 of the move out of WordPress admin: a site's meetings over REST — its
// standing arrangements, the twelve weeks they imply, and the four things the
// admin page can do to them. Nothing here is deleted and the instance is
// reused, so every name carries a run id and every site is this run's own.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const BASE = '/wp-json/blueworx-forge/v1';
const TODAY = new Date().toISOString().slice(0, 10);
const GRANTED = 200;
const HORIZON_DAYS = 84;

/** A date some days on from a YYYY-MM-DD, as YYYY-MM-DD. */
function daysOn(from, days) {
  const when = new Date(`${from}T00:00:00Z`);
  when.setUTCDate(when.getUTCDate() + days);
  return when.toISOString().slice(0, 10);
}

/** Monday a fortnight from now, so a series always has meetings ahead of it. */
function comingMonday() {
  const day = new Date(Date.now() + 14 * 86400000);
  day.setUTCDate(day.getUTCDate() + ((8 - day.getUTCDay()) % 7));
  return day.toISOString().slice(0, 10);
}

/** The eleven fields a series is made from, less the site (that is the path). */
function weekly(host, startsOn, overrides = {}) {
  return {
    title: `Weekly catch-up ${RUN_ID}`,
    frequency: 'weekly',
    starts_on: startsOn,
    ends_on: '',
    time_of_day: '10:00',
    duration_mins: 120,
    timezone: 'Europe/London',
    host_user_id: host.id,
    attendees: 'The client team',
    planned_hours: 0,
    ...overrides,
  };
}

test.describe.configure({ mode: 'serial' });

let api;
let context;
let host;
let site;
let series;
let first;
let guest;

test.beforeAll(async ({ browser, baseURL }) => {
  ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
  const where = await makeSite(api, 'Meetings REST', RUN_ID);
  site = where.site;
  await onSupport({ api }, site.id, GRANTED);
  host = await makePerson(api, where.client.id, 'staff', `mtg${STAMP}`);
  guest = await makePerson(api, where.client.id, 'staff', `gst${STAMP}`);
  first = comingMonday();
});

test.afterAll(async () => {
  await context?.close();
});

test('a site with no standing meetings reads as empty, over a twelve-week horizon', async () => {
  const answer = await api.get(`/client-sites/${site.id}/meetings`);

  expect(answer.ok).toBe(true);
  expect(answer.site.id).toBe(site.id);
  expect(answer.site.name).toBe(site.name);
  expect(answer.series).toEqual([]);
  expect(answer.meetings).toEqual([]);
  expect(answer.horizon.from).toBe(TODAY);
  expect(answer.horizon.to).toBe(daysOn(TODAY, HORIZON_DAYS));

  const person = answer.people.find((one) => one.id === host.id);
  expect(person, 'the host is offered for the picker').toBeTruthy();
  expect(person.display_name).toBe(host.user.display_name);
});

test('a site that is not there is a 404', async () => {
  const read = await api.request.get(`${BASE}/client-sites/cs_${STAMP}/meetings`, { headers: api.headers });
  expect(read.status()).toBe(404);
  expect((await read.json()).code).toBe('bwx_forge_unknown_client_site');
});

test('somebody who is not an administrator cannot read a site\'s meetings, or add a series', async ({ browser, baseURL }) => {
  const other = await signedIn(browser, baseURL, host.login, PASSWORD);

  const read = await other.api.request.get(`${BASE}/client-sites/${site.id}/meetings`, { headers: other.api.headers });
  expect(read.status()).toBe(403);

  const wrote = await other.api.post(`/client-sites/${site.id}/meetings/series`, weekly(host, first));
  expect(wrote.status()).toBe(403);

  await other.context.close();
});

test('a series with no title, or a made-up frequency, is refused with the fields named', async () => {
  const untitled = await api.post(`/client-sites/${site.id}/meetings/series`, weekly(host, first, { title: '' }));
  expect(untitled.status()).toBe(400);

  const body = await untitled.json();
  expect(body.code).toBe('bwx_forge_invalid_series');
  expect(body.data.fields.title).toBeTruthy();

  const daily = await api.post(`/client-sites/${site.id}/meetings/series`, weekly(host, first, { frequency: 'daily' }));
  expect(daily.status()).toBe(400);
  expect((await daily.json()).data.fields.frequency).toBeTruthy();

  expect((await api.get(`/client-sites/${site.id}/meetings`)).series).toEqual([]);
});

test('adding a weekly series lists it, with the twelve weeks it implies holding their hours', async () => {
  // Who else comes (2026-09-19): people, each spending the meeting's hours.
  const wrote = await api.post(`/client-sites/${site.id}/meetings/series`, weekly(host, first, { attendee_ids: [guest.id, host.id] }));
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.series[0].attendee_ids).toEqual([guest.id]);

  expect(answer.ok).toBe(true);
  expect(answer.added.id).toMatch(/^mts_/);
  expect(answer.series).toHaveLength(1);

  const listed = answer.series[0];
  expect(listed.id).toBe(answer.added.id);
  expect(listed.title).toBe(`Weekly catch-up ${RUN_ID}`);
  expect(listed.frequency).toBe('weekly');
  expect(listed.frequency_label).toBe('Every week');
  expect(listed.host_user_id).toBe(host.id);
  expect(listed.host_name).toBe(host.user.display_name);
  expect(listed.hours_each).toBe(2);
  expect(listed.state).toBe('active');
  expect(listed.record_version).toBe(1);
  expect(listed.time_of_day).toBe('10:00');
  expect(listed.timezone).toBe('Europe/London');
  expect(listed.duration_mins).toBe(120);
  series = listed;

  // A weekly meeting from a fortnight out, over twelve weeks: nine or ten.
  expect(answer.meetings.length).toBeGreaterThan(8);
  expect(answer.meetings[0].on).toBe(first);
  expect(answer.meetings[0].time).toBe('10:00');
  expect(answer.meetings[0].status).toBe('scheduled');
  expect(answer.meetings[0].status_label).toBe('Scheduled');
  expect(answer.meetings[0].hours).toBe(2);
  expect(answer.meetings[0].series_id).toBe(listed.id);
  expect(answer.meetings[0].series_title).toBe(listed.title);
  expect(answer.meetings[0].slot).toBe(first);
  expect(answer.meetings[0].excepted_from).toBeNull();

  // Settled on the way out (MEET-4): every one inside the horizon is held,
  // and the site's balance shows it.
  for (const meeting of answer.meetings) {
    expect(meeting.ledger_state, `${meeting.on} is reserved`).toBe('reserved');
  }

  // Both people carry the two hours on the day; the client is charged once.
  const capacity = await api.get(`/capacity?from=${first}&to=${first}`);
  for (const who of [host, guest]) {
    const row = capacity.people.find((entry) => entry.user_id === who.id);
    expect(row, `${who.id} is on the capacity read`).toBeTruthy();
    expect(row.total.committed).toBe(2);
  }

  const ledger = await hourLedger(api, site.id);
  expect(ledger.balance).toBe(GRANTED - 2 * answer.meetings.length);

  // Each line on the site's ledger says which meeting it held the hours for
  // (2026-09-20), so a column of reservations can be told apart.
  const support = await api.get(`/client-sites/${site.id}/support`);
  const held = support.ledger.filter((entry) => 'meeting-reservation' === entry.event_type);
  expect(held.length).toBe(answer.meetings.length);
  expect(held.map((entry) => entry.about)).toContain(`${listed.title} on ${first}`);
});

test('moving the first meeting a day later moves that one, and says where it came from', async () => {
  const landing = daysOn(first, 1);
  const wrote = await api.post(`/client-sites/${site.id}/meetings/${series.id}/${first}/move`, { on: landing });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.meeting.on).toBe(landing);
  expect(answer.meeting.excepted_from).toBe(first);
  expect(answer.meeting.slot).toBe(first);
  expect(answer.meeting.status).toBe('scheduled');
  expect(answer.meeting.ledger_state).toBe('reserved');

  const dates = answer.meetings.map((one) => one.on);
  expect(dates).toContain(landing);
  expect(dates).not.toContain(first);
  expect(dates[1], 'the second meeting stayed where the rule put it').toBe(daysOn(first, 7));
  expect(answer.meetings[0].slot).toBe(first);
});

test('a move with nowhere to go is refused', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/meetings/${series.id}/${first}/move`, { on: '' });
  expect(wrote.status()).toBe(400);
  expect((await wrote.json()).code).toBe('bwx_forge_meeting_refused');
});

test('marking the moved meeting held spends its hours', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/meetings/${series.id}/${first}/settle`, { status: 'held' });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.meeting.slot).toBe(first);
  expect(answer.meeting.on).toBe(daysOn(first, 1));
  expect(answer.meeting.status).toBe('held');
  expect(answer.meeting.status_label).toBe('Held');
  expect(answer.meeting.ledger_state).toBe('used');

  const spent = (await hourLedger(api, site.id)).entries
    .filter(([type]) => 'meeting-usage' === type)
    .reduce((total, [, hours]) => total + Math.abs(hours), 0);
  expect(spent).toBe(2);
});

test('cancelling the next one gives its hours back', async () => {
  const second = daysOn(first, 7);
  const wrote = await api.post(`/client-sites/${site.id}/meetings/${series.id}/${second}/settle`, { status: 'cancelled' });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.meeting.slot).toBe(second);
  expect(answer.meeting.status).toBe('cancelled');
  expect(answer.meeting.ledger_state).toBe('released');

  const cancelled = answer.meetings.find((one) => one.slot === second);
  expect(cancelled.status).toBe('cancelled');
  expect(cancelled.ledger_state).toBe('released');
});

test('a status the domain does not know is refused', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/meetings/${series.id}/${daysOn(first, 14)}/settle`, { status: 'postponed' });
  expect(wrote.status()).toBe(400);
  expect((await wrote.json()).code).toBe('bwx_forge_invalid_status');
});

test('a series that belongs to another site is not found from this one', async () => {
  const elsewhere = (await makeSite(api, 'Elsewhere', `${RUN_ID}-e`)).site;

  const moved = await api.post(`/client-sites/${elsewhere.id}/meetings/${series.id}/${first}/move`, { on: daysOn(first, 2) });
  expect(moved.status()).toBe(404);
  expect((await moved.json()).code).toBe('bwx_forge_unknown_series');

  const settled = await api.post(`/client-sites/${elsewhere.id}/meetings/${series.id}/${first}/settle`, { status: 'held' });
  expect(settled.status()).toBe(404);

  const ended = await api.post(`/client-sites/${elsewhere.id}/meetings/series/${series.id}/end`, { record_version: 1 });
  expect(ended.status()).toBe(404);
  expect((await ended.json()).code).toBe('bwx_forge_unknown_series');
});

test('ending with a stale version is refused, and the series keeps running', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/meetings/series/${series.id}/end`, { record_version: 7 });
  expect(wrote.status()).toBe(409);
  expect((await wrote.json()).code).toBe('bwx_forge_stale_write');

  const missing = await api.post(`/client-sites/${site.id}/meetings/series/${series.id}/end`, {});
  expect(missing.status()).toBe(400);
  expect((await missing.json()).code).toBe('bwx_forge_missing_version');

  expect((await api.get(`/client-sites/${site.id}/meetings`)).series[0].state).toBe('active');
});

test('ending the series releases what its meetings were holding and stops generating more', async () => {
  const wrote = await api.post(`/client-sites/${site.id}/meetings/series/${series.id}/end`, { record_version: series.record_version });
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  expect(answer.series[0].state).toBe('ended');
  expect(answer.series[0].record_version).toBe(series.record_version + 1);

  // The rule generates nothing once ended: only meetings something happened
  // to (or that had hours held against them) remain, and none still holds.
  for (const meeting of answer.meetings) {
    expect(meeting.id, `${meeting.on} is a stored meeting, not a forecast`).toBeTruthy();
    expect(meeting.ledger_state, `${meeting.on} holds nothing`).not.toBe('reserved');
    expect(meeting.ledger_state).not.toBe('forecast');
  }

  // Back to where it started, less the one meeting that happened.
  expect((await hourLedger(api, site.id)).balance).toBe(GRANTED - 2);
});

test('a replayed add under one retry key makes one series, not two', async () => {
  const replayed = (await makeSite(api, 'Replayed', `${RUN_ID}-r`)).site;
  const headers = { ...api.headers, 'Idempotency-Key': `meetings-${RUN_ID}` };
  const body = weekly(host, first, { title: `Replayed ${RUN_ID}` });

  const one = await api.request.post(`${BASE}/client-sites/${replayed.id}/meetings/series`, { headers, data: body });
  const two = await api.request.post(`${BASE}/client-sites/${replayed.id}/meetings/series`, { headers, data: body });

  expect(one.status(), await one.text()).toBe(200);
  expect(two.status(), await two.text()).toBe(200);
  expect((await two.json()).added.id).toBe((await one.json()).added.id);

  expect((await api.get(`/client-sites/${replayed.id}/meetings`)).series).toHaveLength(1);
});
