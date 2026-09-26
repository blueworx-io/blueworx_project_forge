import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { asClientSite, makeItem, makePerson, makeSite, makeSubmission, PASSWORD, signedIn } from './helpers/forge.js';

// One Client picker in the top bar (#402): every client screen follows it,
// and it is remembered.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

/** Two clients with one piece of work each. */
async function twoClients(api, label) {
  const one = await makeSite(api, `${label} One`, RUN_ID);
  const two = await makeSite(api, `${label} Two`, RUN_ID);
  const first = (await (await makeItem(api, one.site.id, { title: `First ${label} ${RUN_ID}` })).json()).item;
  const second = (await (await makeItem(api, two.site.id, { title: `Second ${label} ${RUN_ID}` })).json()).item;

  return { one, two, first, second };
}

/** Opens the app with nothing remembered, as a first visit. */
async function freshVisit(page) {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.evaluate(() => window.localStorage.removeItem('bwx-forge-site'));
  await page.goto('/blueworx-forge/');
}

test.describe('the one Client picker', () => {
  test('narrows the board, is remembered, and follows a site link', async ({ browser, baseURL, page }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const world = await twoClients(admin.api, 'Board');

    await freshVisit(page);
    await page.waitForSelector('[data-testid="bwx-board"]');

    const picker = page.getByTestId('bwx-client-choice');
    // A first visit opens on every client.
    await expect(picker).toHaveValue('all');
    expect((await picker.locator('option').allTextContents())[0]).toBe('All clients');
    await expect(page.getByTestId('bwx-site')).toHaveCount(0);

    const first = page.locator(`[data-testid="bwx-card"][data-item="${world.first.id}"]`);
    const second = page.locator(`[data-testid="bwx-card"][data-item="${world.second.id}"]`);

    await picker.selectOption(world.one.site.id);
    await expect(first).toBeVisible();
    await expect(second).toHaveCount(0);

    // A reload keeps it.
    await page.reload();
    await page.waitForSelector('[data-testid="bwx-board"]');
    await expect(picker).toHaveValue(world.one.site.id);
    await expect(first).toBeVisible();
    await expect(second).toHaveCount(0);

    // A link naming the other site opens on it, and moves the top bar.
    await page.goto('/wp-admin/');
    await page.goto(`/blueworx-forge/#screen=work&site=${world.two.site.id}`);
    await page.waitForSelector('[data-testid="bwx-board"]');
    await expect(picker).toHaveValue(world.two.site.id);
    await expect(second).toBeVisible();
    await expect(first).toHaveCount(0);

    // And the link's site is remembered.
    await page.goto('/blueworx-forge/');
    await expect(picker).toHaveValue(world.two.site.id);

    await admin.context.close();
  });

  test('a remembered site that no longer exists falls back to All clients', async ({ page }) => {
    await signIn(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/blueworx-forge/');
    await page.evaluate(() => window.localStorage.setItem('bwx-forge-site', 'cs_gone_for_good'));
    await page.goto('/blueworx-forge/');
    await page.waitForSelector('[data-testid="bwx-board"]');

    await expect(page.getByTestId('bwx-client-choice')).toHaveValue('all');
  });
});

test.describe('Support with All clients', () => {
  test('lists every client\'s hours, and a row opens that client', async ({ browser, baseURL, page }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const world = await twoClients(admin.api, 'Support');

    await freshVisit(page);
    await page.getByTestId('bwx-screen-support').click();

    const table = page.getByTestId('bwx-support-summary');
    await expect(table).toBeVisible({ timeout: 30_000 });

    const row = table.locator(`[data-testid="bwx-support-summary-row"][data-site="${world.one.site.id}"]`);
    await expect(row).toBeVisible();
    await expect(table.locator(`[data-testid="bwx-support-summary-row"][data-site="${world.two.site.id}"]`)).toBeVisible();
    await expect(table.locator('thead')).toContainText('Hours this period');

    await row.click();
    await expect(page.getByTestId('bwx-client-choice')).toHaveValue(world.one.site.id);
    await expect(page.getByTestId('bwx-support-state')).toBeVisible({ timeout: 30_000 });
    await expect(table).toHaveCount(0);

    await admin.context.close();
  });

  test('the summary is the administrator\'s only', async ({ browser, baseURL }) => {
    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const where = await makeSite(admin.api, 'Support staff', RUN_ID);
    const person = await makePerson(admin.api, where.client.id, 'staff', `supsum${RUN_ID.replace('-', '')}`);
    const staff = await signedIn(browser, baseURL, person.login, PASSWORD);

    const answer = await staff.api.request.get('/wp-json/blueworx-forge/v1/support-summary', { headers: staff.api.headers });
    expect(answer.status()).toBe(403);

    const allowed = await admin.api.request.get('/wp-json/blueworx-forge/v1/support-summary', { headers: admin.api.headers });
    expect(allowed.status()).toBe(200);
    const body = await allowed.json();
    expect(body.sites.some((one) => one.site_id === where.site.id)).toBe(true);

    await staff.context.close();
    await admin.context.close();
  });
});

test('Meetings follows the picker: one client is its meetings, All is every client', async ({ browser, baseURL, page }) => {
  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await makeSite(admin.api, 'Meetings pick', RUN_ID);

  await freshVisit(page);
  await page.getByTestId('bwx-client-choice').selectOption(where.site.id);
  await page.getByTestId('bwx-screen-meetings').click();
  await expect(page.getByTestId('bwx-meetings-standing')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-meetings-all')).toHaveCount(0);

  await page.getByTestId('bwx-client-choice').selectOption('all');
  await expect(page.getByTestId('bwx-meetings-all')).toBeVisible({ timeout: 30_000 });

  await admin.context.close();
});

test.describe('screens that narrow what they load', () => {
  test('My Tasks, Standup and the diary follow the picker; company dates stay', async ({ browser, baseURL }) => {
    test.setTimeout(300_000);

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const one = await makeSite(admin.api, 'Narrow One', RUN_ID);
    const two = await makeSite(admin.api, 'Narrow Two', RUN_ID);
    const person = await makePerson(admin.api, one.client.id, 'staff', `narrow${RUN_ID.replace('-', '')}`);
    await admin.api.post(`/clients/${two.client.id}/memberships`, { user_id: person.id, role: 'staff' });

    const today = (await admin.api.get('/standup')).today;
    const late = new Date(Date.now() - 3 * 86400000).toISOString().slice(0, 10);

    // Late work on each client, owned by the person: on My Tasks and on the standup.
    for (const [where, title] of [[one, `Late here ${RUN_ID}`], [two, `Late there ${RUN_ID}`]]) {
      const made = (await (await makeItem(admin.api, where.site.id, { title })).json()).item;
      const edited = await admin.api.patch(`/work-items/${made.id}`, { primary_user_id: person.id, planned_due: late, record_version: made.record_version });
      expect(edited.status(), await edited.text()).toBe(200);
    }

    // A chore today on the second client, and a company date for everyone.
    const chore = await admin.api.post('/recurring', {
      title: `Chore there ${RUN_ID}`, description: '<p>Do it.</p>', rule: { every: 'day' }, starts_on: today,
      assignees: [person.id], hours_each: '0.5', client_site_id: two.site.id,
    });
    expect(chore.status(), await chore.text()).toBe(200);
    await admin.api.post('/recurring/run', {});
    const date = await admin.api.post('/calendar-dates', { title: `Company day ${RUN_ID}`, kind: 'company-day', on_date: today, people: 'all' });
    expect(date.status(), await date.text()).toBe(200);

    // The feed says which client each entry is for; a company date is for none.
    const feed = await admin.api.get(`/calendar?from=${today}&to=${today}`);
    expect(feed.entries.find((entry) => entry.title === `Company day ${RUN_ID}`).site_id).toBe('');
    expect(feed.entries.find((entry) => entry.title.startsWith(`Chore there ${RUN_ID}`)).site_id).toBe(two.site.id);

    const me = await signedIn(browser, baseURL, person.login, PASSWORD);
    const page = await me.context.newPage();
    await page.goto('/blueworx-forge/');
    await page.getByTestId('bwx-client-choice').selectOption(one.site.id);

    await page.getByTestId('bwx-screen-mytasks').click();
    const table = page.getByTestId('bwx-mytasks-table');
    await expect(table).toBeVisible({ timeout: 60_000 });
    await table.getByRole('button', { name: /^Everything/ }).click();
    await expect(table).toContainText(`Late here ${RUN_ID}`);
    await expect(table).not.toContainText(`Late there ${RUN_ID}`);
    const diary = page.getByTestId('bwx-mytasks-diary');
    await expect(diary).toContainText(`Company day ${RUN_ID}`);
    await expect(diary).not.toContainText(`Chore there ${RUN_ID}`);

    await page.getByTestId('bwx-screen-standup').click();
    const standup = page.getByTestId('bwx-standup');
    await expect(standup).toBeVisible({ timeout: 60_000 });
    // Sections start folded; open them to read the cards.
    await expect(page.getByTestId('bwx-standup-section-toggle').first()).toBeVisible();
    const folded = page.locator('[data-testid="bwx-standup-section-toggle"][aria-expanded="false"]');
    while ((await folded.count()) > 0) {
      await folded.first().click();
    }
    await expect(standup).toContainText(`Late here ${RUN_ID}`);
    await expect(standup).not.toContainText(`Late there ${RUN_ID}`);

    // All clients again: both.
    await page.getByTestId('bwx-client-choice').selectOption('all');
    await expect(standup).toContainText(`Late there ${RUN_ID}`);

    await me.context.close();
    await admin.context.close();
  });

  test('Recurring tasks, Reminders and Requests follow the picker, and say when a client has none', async ({ browser, baseURL, page, request }) => {
    test.setTimeout(300_000);

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const one = await makeSite(admin.api, 'Lists One', RUN_ID);
    const two = await makeSite(admin.api, 'Lists Two', RUN_ID);
    const empty = await makeSite(admin.api, 'Lists Empty', RUN_ID);
    const person = await makePerson(admin.api, one.client.id, 'staff', `lists${RUN_ID.replace('-', '')}`);
    await admin.api.post(`/clients/${two.client.id}/memberships`, { user_id: person.id, role: 'staff' });
    const today = (await admin.api.get('/standup')).today;

    for (const [where, name] of [[one, 'here'], [two, 'there']]) {
      const chore = await admin.api.post('/recurring', {
        title: `Repeat ${name} ${RUN_ID}`, description: '<p>Do it.</p>', rule: { every: 'day' }, starts_on: today,
        assignees: [person.id], hours_each: '0.5', client_site_id: where.site.id,
      });
      expect(chore.status(), await chore.text()).toBe(200);
      const reminder = await admin.api.post('/reminders', { client_site_id: where.site.id, title: `Remind ${name} ${RUN_ID}`, assignees: [person.id], starts_on: today });
      expect(reminder.status(), await reminder.text()).toBe(200);
      await makeSubmission(await asClientSite(admin.api, where.site.id, request), { title: `Asked ${name} ${RUN_ID}` });
    }

    await signIn(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/blueworx-forge/');
    await page.getByTestId('bwx-client-choice').selectOption(one.site.id);
    const app = page.getByTestId('bwx-forge-ready');

    for (const [screen, word] of [['bwx-screen-recurring', 'Repeat'], ['bwx-screen-reminders', 'Remind'], ['bwx-screen-requests', 'Asked']]) {
      await page.getByTestId(screen).click();
      await expect(app).toContainText(`${word} here ${RUN_ID}`, { timeout: 60_000 });
      await expect(app).not.toContainText(`${word} there ${RUN_ID}`);
    }

    // A client with nothing on a screen says so, by name.
    await page.getByTestId('bwx-client-choice').selectOption(empty.site.id);
    await expect(app).toContainText(`Nothing for Lists Empty ${RUN_ID} here.`, { timeout: 60_000 });

    // A new reminder starts on the picked client.
    await page.getByTestId('bwx-screen-reminders').click();
    await page.getByRole('button', { name: /Add reminder/i }).click();
    await expect(page.getByTestId('bwx-reminder-client')).toHaveValue(empty.site.id);

    await admin.context.close();
  });
});

test.describe('Reports and Capacity', () => {
  test('/reports narrows to one reachable client, and refuses one out of reach', async ({ browser, baseURL }) => {
    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const world = await twoClients(admin.api, 'Reports');
    await makeItem(admin.api, world.one.site.id, { title: `Another first ${RUN_ID}` });

    const counted = (answer) => Object.values(answer.reports.stage_distribution).reduce((sum, n) => sum + n, 0);

    const one = await admin.api.get(`/reports?client_site_id=${world.one.site.id}`);
    const two = await admin.api.get(`/reports?client_site_id=${world.two.site.id}`);
    const every = await admin.api.get('/reports');
    expect(counted(one)).toBe(2);
    expect(counted(two)).toBe(1);
    expect(counted(every)).toBeGreaterThanOrEqual(3);

    const person = await makePerson(admin.api, world.one.client.id, 'staff', `reports${RUN_ID.replace('-', '')}`);
    const staff = await signedIn(browser, baseURL, person.login, PASSWORD);
    const mine = await staff.api.request.get(`/wp-json/blueworx-forge/v1/reports?client_site_id=${world.one.site.id}`, { headers: staff.api.headers });
    expect(mine.status()).toBe(200);
    const theirs = await staff.api.request.get(`/wp-json/blueworx-forge/v1/reports?client_site_id=${world.two.site.id}`, { headers: staff.api.headers });
    expect(theirs.status()).toBe(403);

    await staff.context.close();
    await admin.context.close();
  });

  test('the Reports screen sends the picked client; Capacity shows everyone', async ({ browser, baseURL, page }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const where = await makeSite(admin.api, 'Reports screen', RUN_ID);

    await signIn(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/blueworx-forge/');
    await page.getByTestId('bwx-client-choice').selectOption(where.site.id);

    const asked = page.waitForRequest((req) => req.url().includes('/reports?') && req.url().includes(`client_site_id=${where.site.id}`));
    await page.getByTestId('bwx-screen-reports').click();
    await asked;

    await page.getByTestId('bwx-screen-capacity').click();
    await expect(page.getByTestId('bwx-capacity-scope')).toHaveText('Capacity counts all clients.', { timeout: 60_000 });

    await admin.context.close();
  });
});
