import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, onSupport, hourLedger } from './helpers/forge.js';

// Editing a standing meeting's length (2026-09-27, #427): the meetings it has
// coming follow the new length and time, and the hours the old length held
// come back. The instance is reused, so the site and names are this run's own.
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const GRANTED = 200;

/** Monday a fortnight from now, so a series always has meetings ahead of it. */
function comingMonday() {
  const day = new Date(Date.now() + 14 * 86400000);
  day.setUTCDate(day.getUTCDate() + ((8 - day.getUTCDay()) % 7));
  return day.toISOString().slice(0, 10);
}

/** The eleven fields a series is made from, less the site (that is the path). */
function weekly(host, startsOn, overrides = {}) {
  return {
    title: `Sprint meeting ${RUN_ID}`,
    frequency: 'weekly',
    starts_on: startsOn,
    ends_on: '',
    time_of_day: '13:00',
    duration_mins: 60,
    timezone: 'Europe/London',
    host_user_id: host.id,
    attendees: '',
    planned_hours: 0,
    ...overrides,
  };
}

test('shortening a standing meeting shortens the meetings it has coming, and gives the hours back', async ({ browser, baseURL }) => {
  const { context, api } = await signedIn(browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin');
  const where = await makeSite(api, 'Meeting length', RUN_ID);
  await onSupport({ api }, where.site.id, GRANTED);
  const host = await makePerson(api, where.client.id, 'staff', `len${STAMP}`);
  const first = comingMonday();

  const added = await api.post(`/client-sites/${where.site.id}/meetings/series`, weekly(host, first));
  expect(added.status(), await added.text()).toBe(200);
  const before = await added.json();
  const series = before.series[0];
  expect(before.meetings.length).toBeGreaterThan(0);
  expect(before.meetings.every((meeting) => 1 === meeting.hours)).toBe(true);
  expect(before.meetings.every((meeting) => 'reserved' === meeting.ledger_state)).toBe(true);

  // One meeting moved a day later first: it keeps its new day, and follows the
  // series' new length and time like the rest.
  const landing = new Date(`${first}T00:00:00Z`);
  landing.setUTCDate(landing.getUTCDate() + 1);
  const movedTo = landing.toISOString().slice(0, 10);
  const moved = await api.post(`/client-sites/${where.site.id}/meetings/${series.id}/${first}/move`, { on: movedTo });
  expect(moved.status(), await moved.text()).toBe(200);

  const edited = await api.post(
    `/client-sites/${where.site.id}/meetings/series/${series.id}`,
    weekly(host, first, { duration_mins: 30, time_of_day: '14:00', record_version: series.record_version })
  );
  expect(edited.status(), await edited.text()).toBe(200);
  const after = await edited.json();

  expect(after.series[0].hours_each).toBe(0.5);
  expect(after.meetings.length).toBe(before.meetings.length);
  expect(after.meetings.map((meeting) => meeting.on)).toContain(movedTo);
  for (const meeting of after.meetings) {
    expect(meeting.hours, `${meeting.on} follows the new length`).toBe(0.5);
    expect(meeting.time, `${meeting.on} follows the new time`).toBe('14:00');
  }

  // Half an hour per meeting, not the hour the first version held.
  const ledger = await hourLedger(api, where.site.id);
  expect(ledger.balance).toBe(GRANTED - 0.5 * after.meetings.length);

  await context.close();
});
