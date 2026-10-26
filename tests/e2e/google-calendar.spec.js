import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, onSupport, hourLedger, PASSWORD } from './helpers/forge.js';
import { installGoogleStub, removeGoogleStub, putEvents, readsOf, expireSyncToken, connectGoogle, timedEvent } from './helpers/google.js';

// #606. Each staff member connects their own Google Calendar; its events
// come into Forge one way and are settled to a client. Google is a stand-in
// inside the test WordPress (tests/support/google-stub.php): nothing reaches
// the network. The instance is reused between runs, so every person, site
// and event id carries this run's id.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `${Date.now()}${Math.floor(Math.random() * 1e4)}`;
const SECRET = `GOCSPX-secret-${RUN}`;
const DAY = 86400000;
// A client on the invitation, so a meeting is not studio time (#657).
const GUEST = [{ email: `guest${RUN}@example.test`, responseStatus: 'accepted' }];

/** A day some days from today, as YYYY-MM-DD. */
function day(offset) {
  return new Date(Date.now() + offset * DAY).toISOString().slice(0, 10);
}

/** Noon UTC on a day some days from today, so the local day is the same. */
function noon(offset, hour = 12) {
  return `${day(offset)}T${String(hour).padStart(2, '0')}:00:00Z`;
}

test.describe.configure({ mode: 'serial' });

let admin;
let site;
let client;
let alice;
let bob;
let carol;
const as = {};
const who = { alice: `ga${RUN}`, bob: `gb${RUN}` };

/** Every synced meeting on the calendar for a window, as somebody sees it. */
async function googleEntries(api, from, to) {
  const answer = await api.get(`/calendar?from=${from}&to=${to}`);

  return (answer.entries ?? []).filter((entry) => 'google' === entry.source);
}

/** The synced meeting made from one event, by its title, as admin sees it. */
async function meetingTitled(title, from = day(-2), to = day(10)) {
  return (await googleEntries(admin.api, from, to)).find((entry) => entry.title === title);
}

async function syncNow(person) {
  const answer = await person.api.post('/me/google/sync', { force: true });
  expect(answer.status(), await answer.text()).toBe(200);

  return answer.json();
}

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGoogleStub();

  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await makeSite(admin.api, 'Google Calendar', RUN);
  site = where.site;
  client = where.client;
  await onSupport(admin, site.id, 200);

  alice = await makePerson(admin.api, where.client.id, 'staff', who.alice);
  bob = await makePerson(admin.api, where.client.id, 'staff', who.bob);
  carol = await makePerson(admin.api, where.client.id, 'staff', `gc${RUN}`);

  for (const [name, person] of Object.entries({ alice, bob, carol })) {
    as[name] = await signedIn(browser, baseURL, person.login, PASSWORD);
  }
});

test.afterAll(async () => {
  for (const one of [admin, ...Object.values(as)]) {
    await one?.context.close();
  }

  removeGoogleStub();
});

test('an administrator saves the studio\'s Google client, and its secret is never read back', async () => {
  test.slow();

  const refused = await admin.api.post('/google/settings', { client_id: '' });
  expect(refused.status()).toBe(400);

  const saved = await admin.api.post('/google/settings', { client_id: `client-${RUN}.apps.googleusercontent.com`, client_secret: SECRET });
  expect(saved.status(), await saved.text()).toBe(200);

  const text = await saved.text();
  expect(text).not.toContain(SECRET);

  const answer = JSON.parse(text);
  expect(answer.configured).toBe(true);
  expect(answer.client_id).toBe(`client-${RUN}.apps.googleusercontent.com`);
  expect(answer.secret_hint.endsWith(SECRET.slice(-4))).toBe(true);
  expect(answer.redirect_uri).toMatch(/wp-admin\/admin-post\.php\?action=bwx_forge_google$/);

  // Somebody who is not an administrator cannot read or change it.
  const staff = await as.carol.api.request.get('/wp-json/blueworx-forge/v1/google/settings', { headers: as.carol.api.headers });
  expect(staff.status()).toBe(403);
});

test('a person connects their own calendar and every event comes across', async () => {
  test.slow();

  await putEvents(as.alice.context.request, who.alice, [
    timedEvent(`a1${RUN}`, `u1${RUN}@google.com`, noon(-1), 70, { summary: `Kick-off ${RUN}`, attendees: GUEST }),
    timedEvent(`a2${RUN}`, `u2${RUN}@google.com`, noon(-1, 14), 60, {
      summary: `Declined ${RUN}`,
      attendees: [{ email: `${who.alice}@studio.test`, self: true, responseStatus: 'declined' }],
    }),
    { id: `a3${RUN}`, iCalUID: `u3${RUN}@google.com`, status: 'confirmed', summary: `Holiday ${RUN}`, start: { date: day(0) }, end: { date: day(1) } },
    timedEvent(`a4${RUN}`, `u4${RUN}@google.com`, noon(1, 10), 30, { summary: `Doctor ${RUN}`, visibility: 'private' }),
    timedEvent(`a5${RUN}`, `u5${RUN}@google.com`, noon(3), 60, { summary: `Planning ${RUN}` }),
  ]);

  const page = await as.alice.context.newPage();
  const landed = await connectGoogle(page, as.alice.api, who.alice);
  expect(landed).toContain('google=connected');
  await page.close();

  const mine = await as.alice.api.request.get('/wp-json/blueworx-forge/v1/me/google', { headers: as.alice.api.headers });
  const text = await mine.text();
  expect(text).not.toContain('rt-');
  expect(text).not.toContain('at-');

  const answer = JSON.parse(text);
  expect(answer.connected).toBe(true);
  expect(answer.email).toBe(`${who.alice}@studio.test`);

  const entries = await googleEntries(admin.api, day(-2), day(5));
  const titles = entries.map((entry) => entry.title);
  expect(titles).toEqual(expect.arrayContaining([`Kick-off ${RUN}`, `Declined ${RUN}`, `Holiday ${RUN}`, `Doctor ${RUN}`, `Planning ${RUN}`]));

  const kickoff = entries.find((entry) => entry.title === `Kick-off ${RUN}`);
  expect(kickoff.people).toEqual([alice.id]);
  expect(kickoff.site_id).toBe('');
  expect(kickoff.detail).toContain('Google Calendar');
});

test('declined and all-day events show but take no time and need no settling', async () => {
  test.slow();

  const kickoff = await meetingTitled(`Kick-off ${RUN}`);
  const declined = await meetingTitled(`Declined ${RUN}`);
  const holiday = await meetingTitled(`Holiday ${RUN}`);

  const capacity = await admin.api.get(`/capacity/person/${alice.id}?from=${day(-1)}&to=${day(0)}`);
  const google = capacity.allocations.filter((one) => [kickoff, declined, holiday].some((entry) => entry.google_id === one.item_id));

  expect(google.map((one) => one.item_id)).toEqual([kickoff.google_id]);
  expect(Number(google[0].hours)).toBe(1.5);

  const standup = await as.alice.api.get('/standup');
  const settle = (standup.to_settle ?? []).map((entry) => entry.google_id).filter(Boolean);
  expect(settle).toContain(kickoff.google_id);
  expect(settle).not.toContain(declined.google_id);
  expect(settle).not.toContain(holiday.google_id);
});

test('the same invitation on two connected calendars is one meeting with both people', async () => {
  test.slow();

  await putEvents(as.bob.context.request, who.bob, [
    timedEvent(`b1${RUN}`, `u1${RUN}@google.com`, noon(-1), 70, { summary: `Kick-off ${RUN}`, attendees: GUEST }),
  ]);

  const page = await as.bob.context.newPage();
  await connectGoogle(page, as.bob.api, who.bob);
  await page.close();

  const kickoffs = (await googleEntries(admin.api, day(-2), day(0))).filter((entry) => entry.title === `Kick-off ${RUN}`);
  expect(kickoffs).toHaveLength(1);
  expect([...kickoffs[0].people].sort()).toEqual([alice.id, bob.id].sort());
});

test('a private event reads as Busy to a colleague', async () => {
  test.slow();

  const seen = await googleEntries(as.bob.api, day(0), day(2));
  expect(seen.map((entry) => entry.title)).not.toContain(`Doctor ${RUN}`);
  expect(seen.map((entry) => entry.title)).toContain('Busy');

  const own = await googleEntries(as.alice.api, day(0), day(2));
  expect(own.map((entry) => entry.title)).toContain(`Doctor ${RUN}`);
});

test('a meeting is settled to a client with time off support, marked held, and charged once', async () => {
  test.slow();

  const kickoff = await meetingTitled(`Kick-off ${RUN}`);

  // Somebody not on it cannot settle it.
  const stranger = await as.carol.api.post(`/google-meetings/${kickoff.google_id}/settle`, { client_site_id: site.id, off_support: true, status: 'held' });
  expect(stranger.status()).toBe(403);

  for (let time = 0; time < 2; time++) {
    const settled = await as.alice.api.post(`/google-meetings/${kickoff.google_id}/settle`, { client_site_id: site.id, off_support: true, status: 'held' });
    expect(settled.status(), await settled.text()).toBe(200);
  }

  const ledger = await hourLedger(admin, site.id);
  const usage = ledger.entries.filter(([type, , source]) => 'meeting-usage' === type && source === `google-meeting:${kickoff.google_id}`);
  expect(usage).toHaveLength(1);
  expect(Math.abs(usage[0][1])).toBe(1.5);

  const standup = await as.alice.api.get('/standup');
  expect((standup.to_settle ?? []).map((entry) => entry.google_id)).not.toContain(kickoff.google_id);

  // Charged: its client cannot change now, even for an administrator.
  const moved = await admin.api.post(`/google-meetings/${kickoff.google_id}/settle`, { client_site_id: '', off_support: false });
  expect(moved.status()).toBe(409);
});

test('a client picked ahead holds hours, follows Google moving it, and gives them back when deleted', async () => {
  test.slow();

  const planning = await meetingTitled(`Planning ${RUN}`);
  const source = `google-meeting:${planning.google_id}`;
  const held = async () => (await hourLedger(admin, site.id)).entries
    .filter(([, , from]) => from === source)
    .reduce((sum, [type, hours]) => sum + ('meeting-reservation' === type || 'meeting-release' === type ? -hours : 0), 0);

  const picked = await as.alice.api.post(`/google-meetings/${planning.google_id}/settle`, { client_site_id: site.id, off_support: true });
  expect(picked.status(), await picked.text()).toBe(200);
  expect(await held()).toBe(1);

  // Moved and lengthened in Google: the time follows, the client stays.
  await putEvents(as.alice.context.request, who.alice, [
    timedEvent(`a5${RUN}`, `u5${RUN}@google.com`, noon(4), 120, { summary: `Planning ${RUN}` }),
  ]);
  await syncNow(as.alice);

  const after = await meetingTitled(`Planning ${RUN}`);
  expect(after.date).toBe(day(4));
  expect(after.site_id).toBe(site.id);
  expect(await held()).toBe(2);

  // Deleted in Google: it goes, and the hours come back.
  await putEvents(as.alice.context.request, who.alice, [{ id: `a5${RUN}`, status: 'cancelled' }]);
  await syncNow(as.alice);

  expect(await meetingTitled(`Planning ${RUN}`)).toBeUndefined();
  expect(await held()).toBe(0);
});

test('a Google event that is a standing meeting shows once, as the standing meeting', async () => {
  test.slow();

  const on = day(6);
  const series = await admin.api.post(`/client-sites/${site.id}/meetings/series`, {
    title: `Standing ${RUN}`,
    frequency: 'weekly',
    starts_on: on,
    ends_on: '',
    time_of_day: '12:00',
    duration_mins: 60,
    timezone: 'UTC',
    host_user_id: alice.id,
    attendees: '',
    planned_hours: 0,
  });
  expect(series.status(), await series.text()).toBe(200);

  await putEvents(as.alice.context.request, who.alice, [
    timedEvent(`a6${RUN}`, `u6${RUN}@google.com`, `${on}T12:30:00Z`, 60, { summary: `Standing in Google ${RUN}` }),
    timedEvent(`a7${RUN}`, `u7${RUN}@google.com`, `${on}T15:00:00Z`, 60, { summary: `Afterwards ${RUN}` }),
  ]);
  await syncNow(as.alice);

  const titles = (await googleEntries(admin.api, on, on)).map((entry) => entry.title);
  expect(titles).not.toContain(`Standing in Google ${RUN}`);
  expect(titles).toContain(`Afterwards ${RUN}`);
});

test('an expired sync token starts a full read rather than failing', async () => {
  test.slow();

  await expireSyncToken(as.alice.context.request, who.alice);
  const answer = await syncNow(as.alice);

  expect(answer.last_error).toBe('');
  expect((await readsOf(as.alice.context.request, who.alice)).slice(-2)).toEqual(['incremental', 'full']);
});

test('disconnecting stops the sync and removes only unsettled meetings', async () => {
  test.slow();

  const gone = await as.alice.api.del('/me/google');
  expect(gone.status()).toBe(200);
  expect((await gone.json()).connected).toBe(false);

  const titles = (await googleEntries(admin.api, day(-2), day(10))).map((entry) => entry.title);
  // Settled (held) stays; Alice's unsettled ones go; Bob's copy of the
  // shared meeting is untouched.
  expect(titles).toContain(`Kick-off ${RUN}`);
  expect(titles).not.toContain(`Declined ${RUN}`);
  expect(titles).not.toContain(`Afterwards ${RUN}`);
});

test('a meeting before the person first connected never shows, even after reconnecting', async ({ browser, baseURL }) => {
  test.slow();

  const handle = `gd${RUN}`;
  const dave = await makePerson(admin.api, client.id, 'staff', handle);
  const as_dave = await signedIn(browser, baseURL, dave.login, PASSWORD);

  await putEvents(as_dave.context.request, handle, [
    timedEvent(`d1${RUN}`, `ud1${RUN}@google.com`, noon(-3), 60, { summary: `Before ${RUN}` }),
    timedEvent(`d2${RUN}`, `ud2${RUN}@google.com`, noon(1), 60, { summary: `After ${RUN}` }),
  ]);

  const titles = async () => (await googleEntries(admin.api, day(-5), day(5))).map((entry) => entry.title).filter((title) => title.endsWith(RUN) && (title.startsWith('Before') || title.startsWith('After')));

  const page = await as_dave.context.newPage();
  expect(await connectGoogle(page, as_dave.api, handle, { since: null })).toContain('google=connected');
  expect(await titles()).toEqual([`After ${RUN}`]);

  // A reconnect keeps the first day, so the earlier meeting stays away.
  expect((await as_dave.api.del('/me/google')).status()).toBe(200);
  expect(await connectGoogle(page, as_dave.api, handle, { since: null })).toContain('google=connected');
  await syncNow(as_dave);
  expect(await titles()).toEqual([`After ${RUN}`]);

  await as_dave.context.close();
});

// #657. A recurring meeting remembers the client last picked for it.
test.describe('a recurring meeting', () => {
  const handle = `ge${RUN}`;
  const title = `Sprint ${RUN}`;
  let eve;
  let other;

  /** One weekly occurrence of the series, `week` weeks after tomorrow. */
  const weekly = (week) => timedEvent(`s${week}x${RUN}`, `us${RUN}@google.com`, noon(1 + 7 * week, 9), 60, {
    summary: title,
    recurringEventId: `s${RUN}`,
    originalStartTime: { dateTime: noon(1 + 7 * week, 9) },
  });

  /** The series' meetings, earliest first, as admin sees them. */
  const weeks = async () => (await googleEntries(admin.api, day(0), day(40)))
    .filter((entry) => entry.title === title)
    .sort((a, b) => a.date.localeCompare(b.date));

  const settle = async (meeting, body) => {
    const answer = await admin.api.post(`/google-meetings/${meeting.google_id}/settle`, body);
    expect(answer.status(), await answer.text()).toBe(200);
  };

  test.beforeAll(async ({ browser, baseURL }) => {
    test.setTimeout(300000);
    other = (await makeSite(admin.api, 'Google Calendar other', RUN)).site;
    await onSupport(admin, other.id, 200);

    const person = await makePerson(admin.api, client.id, 'staff', handle);
    eve = await signedIn(browser, baseURL, person.login, PASSWORD);
    await putEvents(eve.context.request, handle, [weekly(0), weekly(1), weekly(2)]);

    const page = await eve.context.newPage();
    expect(await connectGoogle(page, eve.api, handle)).toContain('google=connected');
    await page.close();
  });

  test.afterAll(async () => {
    await eve?.context.close();
  });

  test('picking a client for one meeting in a series fills in the later ones', async () => {
    test.slow();

    const [first] = await weeks();
    await settle(first, { client_site_id: site.id, off_support: true });

    const [, second, third] = await weeks();
    for (const later of [second, third]) {
      expect(later.site_id).toBe(site.id);
      expect(later.off_support).toBe(true);
      expect(later.status).toBe('scheduled');
    }
  });

  test('a new meeting in the series arrives filled in', async () => {
    test.slow();

    await putEvents(eve.context.request, handle, [weekly(3)]);
    await syncNow(eve);

    const all = await weeks();
    expect(all).toHaveLength(4);
    expect(all[3].site_id).toBe(site.id);
    expect(all[3].off_support).toBe(true);
  });

  test('changing only the support tick on one meeting stays on that meeting', async () => {
    test.slow();

    const [, second] = await weeks();
    await settle(second, { client_site_id: site.id, off_support: false });

    const [, again, third] = await weeks();
    expect(again.off_support).toBe(false);
    expect(third.off_support).toBe(true);
  });

  test('changing the client on a later meeting carries forward from there, and never moves a held one', async () => {
    test.slow();

    const [, second, third] = await weeks();
    await settle(third, { client_site_id: site.id, off_support: true, status: 'held' });
    await settle(second, { client_site_id: other.id, off_support: true });

    const all = await weeks();
    expect(all[0].site_id).toBe(site.id);
    expect(all[1].site_id).toBe(other.id);
    expect(all[2].site_id).toBe(site.id);
    expect(all[2].status).toBe('held');
    expect(all[3].site_id).toBe(other.id);
  });
});

// #657. A meeting with only studio staff on it fills itself in as studio time.
test('an all-staff meeting arrives as studio time, and goes back when a guest is added', async ({ browser, baseURL }) => {
  test.slow();

  const handle = `gf${RUN}`;
  const person = await makePerson(admin.api, client.id, 'staff', handle);
  const fay = await signedIn(browser, baseURL, person.login, PASSWORD);
  const title = `Studio huddle ${RUN}`;
  const staffOnly = [
    { email: `${handle}@studio.test`, self: true, responseStatus: 'accepted' },
    { email: `${who.bob}@studio.test`, responseStatus: 'accepted' },
  ];

  await putEvents(fay.context.request, handle, [timedEvent(`f1${RUN}`, `uf1${RUN}@google.com`, noon(2), 60, { summary: title, attendees: staffOnly })]);
  const page = await fay.context.newPage();
  expect(await connectGoogle(page, fay.api, handle)).toContain('google=connected');

  const studio = await meetingTitled(title, day(0), day(5));
  expect(studio.site_id).not.toBe('');
  expect(studio.site_id).not.toBe(site.id);
  expect(studio.needs_settling).toBe(true);
  expect(studio.status).toBe('scheduled');

  await putEvents(fay.context.request, handle, [{ id: `f1${RUN}`, attendees: [...staffOnly, { email: `client${RUN}@example.test`, responseStatus: 'needsAction' }] }]);
  await syncNow(fay);

  expect((await meetingTitled(title, day(0), day(5))).site_id).toBe('');

  await fay.context.close();
});
