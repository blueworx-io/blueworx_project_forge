#!/usr/bin/env node
// Fills a fresh local studio with enough to review by (2026-09-20).
//
//   npm run wp:up
//   npm run wp:seed
//
// Three clients with sites, a team with logins, packages on support, standing
// meetings, work at several stages, recurring chores and diary dates — the
// same shapes the specs make, built through the same helpers, so what the
// review site shows is what the product does. Run it once on an empty
// instance; running it twice makes everything twice.
//
// Sign in afterwards as admin / admin, or as alice / ben / chloe with the
// helpers' PASSWORD, and open /blueworx-forge/.

import { chromium } from '@playwright/test';
import { signedIn, makePackage, assignSupport, setHours, PASSWORD, walkTo } from '../tests/e2e/helpers/forge.js';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8892';
const adminUser = process.env.WP_ADMIN_USER ?? 'admin';
const adminPass = process.env.WP_ADMIN_PASS ?? 'admin';

const iso = (d) => d.toISOString().slice(0, 10);
const plus = (days) => {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d;
};

const browser = await chromium.launch();
const { api } = await signedIn(browser, baseURL, adminUser, adminPass);
const ok = async (p, what) => {
  const r = await p;
  if (r.status() !== 200) throw new Error(`${what}: ${r.status()} ${await r.text()}`);
  return r.json();
};
const serverToday = (await api.get('/standup')).today;

// The admin is a person too, so My tasks, the profile page and chores work for them.
const luke = (await ok(api.post('/users', { email: 'admin@example.test', display_name: 'Luke McFarland', wp_user_id: 1 }), 'admin person')).user;

// Packages.
const starter = await makePackage(api, 'Starter care', { hours: 4, price: 400, validity_months: 12 });
const standard = await makePackage(api, 'Standard care', { hours: 10, price: 900, validity_months: 12 });
const premium = await makePackage(api, 'Premium care', { hours: 25, price: 2000, validity_months: 12 });
await ok(api.post('/packages', { name: 'Monthly retainer', hours: 6, price: 540, currency: 'GBP', validity_months: 12, terms: '', hours_per: 'month' }), 'monthly package');
console.log('packages done');

// Clients and sites.
const clients = [];
for (const [name, sites] of [
  ['Harbour Dental', ['harbourdental.co.uk']],
  ['Northwind Traders', ['northwind.com', 'shop.northwind.com']],
  ['Meadow Bakery', ['meadowbakery.co.uk']],
  ['Pinecrest Architects', []],
]) {
  const client = (await ok(api.post('/clients', { display_name: name, timezone: 'Europe/London' }), name)).client;
  const made = [];
  for (const host of sites) {
    made.push((await ok(api.post(`/clients/${client.id}/sites`, { name: host, url: `https://${host}` }), host)).site);
  }
  clients.push({ client, sites: made });
}
const [harbour, northwind, meadow] = clients;
console.log('clients done');

// People with logins.
const person = async (clientId, role, name, login) => {
  const wp = await api.request.post('/wp-json/wp/v2/users', {
    headers: api.headers,
    data: { username: login, email: `${login}@example.test`, password: PASSWORD, roles: ['subscriber'] },
  });
  if (wp.status() !== 201) throw new Error(`wp ${login}: ${await wp.text()}`);
  const user = (await ok(api.post('/users', { email: `${login}@example.test`, display_name: name, wp_user_id: (await wp.json()).id }), name)).user;
  await ok(api.post(`/clients/${clientId}/memberships`, { user_id: user.id, role }), `${name} membership`);
  return user;
};
const alice = await person(harbour.client.id, 'staff', 'Alice Morgan', 'alice');
const ben = await person(harbour.client.id, 'staff', 'Ben Okafor', 'ben');
const chloe = await person(northwind.client.id, 'staff', 'Chloe Reyes', 'chloe');
await person(northwind.client.id, 'client_admin', 'Dan Whitfield', 'dan');
await person(meadow.client.id, 'internal_viewer', 'Erin Novak', 'erin');
await ok(api.post(`/clients/${northwind.client.id}/memberships`, { user_id: alice.id, role: 'staff' }), 'alice northwind');
await ok(api.post(`/clients/${meadow.client.id}/memberships`, { user_id: ben.id, role: 'staff' }), 'ben meadow');
for (const c of [harbour, northwind, meadow]) {
  await ok(api.post(`/clients/${c.client.id}/memberships`, { user_id: luke.id, role: 'staff' }), 'luke membership');
}
await ok(api.post('/users', { email: 'freya.lund@example.test', display_name: 'Freya Lund' }), 'freya');
console.log('people done');

// Availability: usual weeks, one reduced month, and some leave.
await setHours(api, luke.id, 8, '2026-01-01');
await setHours(api, alice.id, 7.5, '2026-01-01');
await ok(api.post(`/users/${alice.id}/availability/hours`, {
  effective_from: iso(plus(14)), effective_to: iso(plus(44)),
  hours_sun: 0, hours_mon: 4, hours_tue: 4, hours_wed: 4, hours_thu: 4, hours_fri: 0, hours_sat: 0,
  note: 'Reduced hours for a month',
}), 'alice reduced');
await setHours(api, ben.id, 8, '2026-01-01');
await setHours(api, chloe.id, 6, '2026-01-01');
await ok(api.post(`/users/${alice.id}/leave`, { starts_on: iso(plus(7)), ends_on: iso(plus(11)), kind: 'leave', note: 'Cornwall' }), 'alice leave');
await ok(api.post(`/users/${ben.id}/leave`, { starts_on: serverToday, ends_on: serverToday, kind: 'leave', note: 'Dentist' }), 'ben leave');
console.log('availability done');

// Support.
await assignSupport(api, harbour.sites[0].id, premium.current.id, iso(plus(-60)));
await assignSupport(api, northwind.sites[0].id, standard.current.id, iso(plus(-10)));
await assignSupport(api, northwind.sites[1].id, starter.current.id, iso(plus(3)));
console.log('support done');

// Standing meetings.
const series = async (siteId, title, frequency, host, time, mins, hours) => ok(api.post(`/client-sites/${siteId}/meetings/series`, {
  title, frequency, starts_on: serverToday, ends_on: '', time_of_day: time, duration_mins: mins,
  timezone: 'Europe/London', host_user_id: host.id, attendees: 'Client team', planned_hours: hours,
}), title);
await series(harbour.sites[0].id, 'Weekly check-in', 'weekly', alice, '10:00', 30, 0.5);
await series(harbour.sites[0].id, 'Monthly review', 'monthly', ben, '14:00', 60, 1);
await series(northwind.sites[0].id, 'Fortnightly planning', 'fortnightly', chloe, '11:00', 45, 0.75);
console.log('meetings done');

// Work at several stages.
const item = async (siteId, data) => (await ok(api.post('/work-items', {
  client_site_id: siteId, level: 'sub-feature', work_type: 'task', problem: '<p>Something needs doing.</p>', ...data,
}), data.title)).item;
const seats = (primary, reviewer, deliverer, start, due, [work, review, delivery] = [3, 1, 1]) => ({
  seats: { primary_user_id: primary.id, reviewer_id: reviewer.id, deliverer_id: deliverer.id, hours_primary: work, hours_review: review, hours_delivery: delivery, planned_start: start, planned_due: due },
});

await item(harbour.sites[0].id, { title: 'New booking page' });
const bug = await item(harbour.sites[0].id, { title: 'Contact form drops the phone number', work_type: 'bug' });
await walkTo(api, bug, ['triage', 'bug-tracking']);
const planned = await item(harbour.sites[0].id, { title: 'Refresh the homepage hero' });
await walkTo(api, planned, ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next'], seats(alice, ben, chloe, serverToday, iso(plus(5))));
await item(northwind.sites[0].id, { title: 'Checkout redesign' });

// One in development with a checklist and how to test it, for the review card.
const building = await item(harbour.sites[0].id, { title: 'Speed up the gallery page' });
await walkTo(api, building, ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next', 'in-development'], seats(ben, luke, chloe, iso(plus(-2)), iso(plus(-1))));
const current = (await api.get(`/work-items/${building.id}`)).item;
await ok(api.patch(`/work-items/${building.id}`, {
  record_version: current.record_version,
  checklist: [{ text: 'Lazy-load the images', done: true }, { text: 'Serve WebP', done: true }, { text: 'Check Lighthouse score', done: false }],
  test_description: '<p>Open the gallery on a phone and watch the images arrive.</p>',
  test_steps: [{ text: 'Open /gallery on mobile', done: false }, { text: 'Scroll to the bottom', done: false }, { text: 'Lighthouse performance above 90', done: false }],
  links: [{ label: 'Before and after', url: 'https://example.test/gallery-timings' }],
}), 'building extras');

const mine = await item(northwind.sites[0].id, { title: 'Newsletter signup block' });
// Northwind's package is the smaller one, and its meetings have first call on it.
await walkTo(api, mine, ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next'], seats(luke, chloe, alice, serverToday, iso(plus(3)), [2, 0.5, 0.5]));
console.log('work done');

// Recurring chores, and diary dates.
await ok(api.post('/recurring', { title: 'Clear the support inbox', rule: { every: 'weekday' }, starts_on: serverToday, assignees: [alice.id, ben.id, chloe.id, luke.id], hours_each: '0.5', description: '<p>Nothing left unread by 10am.</p>' }), 'chore 1');
await ok(api.post('/recurring', { title: 'Log your hours', rule: { every: 'weekday' }, starts_on: serverToday, assignees: [alice.id, ben.id], hours_each: '0.17', description: '<p>Before you leave.</p>' }), 'chore 2');
await ok(api.post('/recurring', {
  title: 'Friday site checks', rule: { every: 'week', days: [5] }, starts_on: serverToday, assignees: [alice.id, chloe.id], hours_each: '1',
  description: '<p>Every client site, once a week.</p>',
  checklist: [{ text: 'Plugins up to date', done: false }, { text: 'Backups ran', done: false }, { text: 'Uptime clean', done: false }],
}), 'chore 3');
await ok(api.post('/recurring/run', {}), 'run');
await ok(api.post('/calendar-dates', { title: 'Company day out', kind: 'company-day', on_date: iso(plus(9)), people: 'all', note: 'Bring a coat' }), 'date 1');
await ok(api.post('/calendar-dates', { title: "Alice's birthday", kind: 'birthday', on_date: iso(plus(3)), people: [alice.id] }), 'date 2');
await ok(api.post('/calendar-dates', { title: 'Autumn campaign', kind: 'campaign', on_date: iso(plus(1)), ends_on: iso(plus(12)), people: 'all' }), 'date 3');
console.log('diary done');

await browser.close();
console.log(`\nSeeded. Sign in at ${baseURL}/wp-login.php as ${adminUser}, or as alice / ben / chloe with the test password, then open ${baseURL}/blueworx-forge/`);
