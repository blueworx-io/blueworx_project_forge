import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, makeItem, asClientSite, makeSubmission, onSupport, hourLedger, PASSWORD } from './helpers/forge.js';

// #458. An administrator deletes a client and everything attached to it:
// its site, work, requests, meetings, recurring tasks, reminders, hours, its
// connection and its own people. A second client, set up the same way, is
// left exactly as it was. Names carry a run id; the instance is reused.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const BASE = '/wp-json/blueworx-forge/v1';
const TODAY = new Date().toISOString().slice(0, 10);
const YEAR_AGO = new Date(Date.now() - 300 * 86400000).toISOString().slice(0, 10);

/** Monday a fortnight from now, so a series always has meetings ahead of it. */
function comingMonday() {
  const day = new Date(Date.now() + 14 * 86400000);
  day.setUTCDate(day.getUTCDate() + ((8 - day.getUTCDay()) % 7));
  return day.toISOString().slice(0, 10);
}

/** One client with something of every kind attached to it. */
async function clientWithWork(admin, request, label) {
  const { client, site } = await makeSite(admin.api, label, RUN_ID);
  await onSupport(admin, site.id, 50);

  const staff = await makePerson(admin.api, client.id, 'staff', `del${label.slice(0, 4).toLowerCase()}${STAMP}`);
  const own = await makePerson(admin.api, client.id, 'client_admin', `own${label.slice(0, 4).toLowerCase()}${STAMP}`);

  const made = await makeItem(admin.api, site.id, { title: `${label} task ${RUN_ID}` });
  expect(made.status(), await made.text()).toBe(200);
  const item = (await made.json()).item;

  const signed = await asClientSite(admin.api, site.id, request);
  const submission = await makeSubmission(signed, { title: `${label} request ${RUN_ID}` });

  const series = await admin.api.post(`/client-sites/${site.id}/meetings/series`, {
    title: `${label} catch-up ${RUN_ID}`,
    frequency: 'weekly',
    starts_on: comingMonday(),
    ends_on: '',
    time_of_day: '10:00',
    duration_mins: 60,
    timezone: 'Europe/London',
    host_user_id: staff.id,
    attendees: 'The client team',
    planned_hours: 0,
  });
  expect(series.status(), await series.text()).toBe(200);

  const recurring = await admin.api.post('/recurring', {
    title: `${label} backups ${RUN_ID}`,
    description: '<p>Check the backups.</p>',
    work_type: 'task',
    rule: { every: 'week', days: [1] },
    starts_on: comingMonday(),
    assignees: [staff.id],
    hours_each: '0.5',
    client_site_id: site.id,
  });
  expect(recurring.status(), await recurring.text()).toBe(200);

  const reminder = await admin.api.post('/reminders', {
    client_site_id: site.id,
    title: `${label} renew the domain ${RUN_ID}`,
    assignees: [staff.id],
    starts_on: TODAY,
  });
  expect(reminder.status(), await reminder.text()).toBe(200);

  return {
    client,
    site,
    signed,
    own,
    item,
    submission,
    series: (await series.json()).added,
    source: (await recurring.json()).source,
    reminder: (await reminder.json()).reminder,
  };
}

test.describe('deleting a client', () => {
  test('an administrator deletes one client and everything on it; the other is untouched', async ({ browser, baseURL, page, request }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const doomed = await clientWithWork(admin, request, 'Doomed');
    const kept = await clientWithWork(admin, request, 'Kept');

    const keptLedger = await hourLedger(admin, kept.site.id);
    const keptReport = (await admin.api.get(`/reports?from=${YEAR_AGO}&to=${TODAY}&client_site_id=${kept.site.id}`)).reports;

    // Staff may not count it or delete it.
    const staff = await makePerson(admin.api, doomed.client.id, 'staff', `nodel${STAMP}`);
    const asStaff = await signedIn(browser, baseURL, staff.login, PASSWORD);
    expect((await asStaff.api.request.get(`${BASE}/clients/${doomed.client.id}/deletion`, { headers: asStaff.api.headers })).status()).toBe(403);
    expect((await asStaff.api.del(`/clients/${doomed.client.id}`)).status()).toBe(403);
    await asStaff.context.close();

    // What goes, counted before anything does.
    const counted = await admin.api.get(`/clients/${doomed.client.id}/deletion`);
    expect(counted.refusal).toBe('');
    expect(counted.counts.sites).toBe(1);
    expect(counted.counts.tasks).toBeGreaterThanOrEqual(2);
    expect(counted.counts.requests).toBe(1);
    expect(counted.counts.meetings).toBe(1);
    expect(counted.counts.recurring).toBe(1);
    expect(counted.counts.reminders).toBe(1);
    expect(counted.counts.time).toBeGreaterThanOrEqual(1);
    expect(counted.counts.connections).toBe(1);
    expect(counted.counts.people).toBe(1);

    // Deleted from the Clients screen, through the confirmation.
    await signIn(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/blueworx-forge/#screen=clients');
    await expect(page.getByTestId('bwx-clients-count')).toBeVisible({ timeout: 30_000 });
    await page.getByTestId('bwx-clients-list').locator('tbody tr', { hasText: doomed.client.display_name }).click();
    await page.getByTestId('bwx-clients-selected').getByTestId('bwx-clients-delete').click();

    const dialog = page.getByTestId('bwx-clients-delete-form');
    await expect(dialog).toContainText(`Delete ${doomed.client.display_name}?`);
    await expect(dialog.getByTestId('bwx-clients-delete-count-tasks')).toHaveAttribute('data-count', String(counted.counts.tasks));
    await expect(dialog.getByTestId('bwx-clients-delete-count-requests')).toContainText('1 request');
    await dialog.getByTestId('bwx-clients-delete-confirm').click();

    await expect(dialog).toBeHidden();
    await expect(page.getByTestId('bwx-clients-notice')).toContainText(`${doomed.client.display_name} has been deleted`);
    await expect(page.getByTestId('bwx-clients-list').locator('tbody tr', { hasText: doomed.client.display_name })).toHaveCount(0);

    // Nothing of it answers any more.
    const status = async (path) => (await admin.api.request.get(`${BASE}${path}`, { headers: admin.api.headers })).status();
    expect(await status(`/clients/${doomed.client.id}`)).toBe(404);
    expect(await status(`/work-items/${doomed.item.id}`)).toBe(404);
    expect(await status(`/client-sites/${doomed.site.id}/meetings`)).toBe(404);
    expect(await status(`/users/${doomed.own.id}`)).toBe(404);
    expect(await status(`/reports?client_site_id=${doomed.site.id}`)).toBe(403);

    const everywhere = JSON.stringify([
      await admin.api.get('/clients?status=all'),
      await admin.api.get('/client-sites'),
      await admin.api.get('/submissions'),
      await admin.api.get('/recurring'),
      await admin.api.get('/reminders'),
      await admin.api.get('/meetings'),
      await admin.api.get('/standup'),
    ]);
    for (const id of [doomed.client.id, doomed.site.id, doomed.submission.id, doomed.source.id, doomed.reminder.id, doomed.series.id]) {
      expect(everywhere, `${id} still shows`).not.toContain(id);
    }

    // Its site's key no longer signs anything.
    const late = await doomed.signed.post('/client/submissions', { type: 'request', title: 'Too late', description: 'x', submitted_by: 'x' });
    expect(late.status()).not.toBe(200);

    // The other client is exactly as it was.
    expect(await status(`/clients/${kept.client.id}`)).toBe(200);
    expect(await status(`/work-items/${kept.item.id}`)).toBe(200);
    expect(await status(`/users/${kept.own.id}`)).toBe(200);
    for (const id of [kept.client.id, kept.site.id, kept.submission.id, kept.source.id, kept.reminder.id, kept.series.id]) {
      expect(everywhere, `${id} was lost`).toContain(id);
    }
    expect(await hourLedger(admin, kept.site.id)).toEqual(keptLedger);
    expect((await admin.api.get(`/reports?from=${YEAR_AGO}&to=${TODAY}&client_site_id=${kept.site.id}`)).reports.stage_distribution).toEqual(keptReport.stage_distribution);

    await admin.context.close();
  });

  test('the studio\'s own client cannot be deleted', async ({ browser, baseURL }) => {
    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const studio = (await admin.api.get('/client-sites')).sites.find((one) => one.studio);
    expect(studio, 'the studio has a site').toBeTruthy();

    const counted = await admin.api.get(`/clients/${studio.client_id}/deletion`);
    expect(counted.refusal).not.toBe('');

    const refused = await admin.api.del(`/clients/${studio.client_id}`);
    expect(refused.status()).toBe(400);
    expect((await admin.api.request.get(`${BASE}/clients/${studio.client_id}`, { headers: admin.api.headers })).status()).toBe(200);

    await admin.context.close();
  });
});
