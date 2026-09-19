import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// The profile page (2026-09-18): a staff member's own details, Slack, working
// week and time off, reached from the pill in the top-right corner. What they
// set there is what the administrator sees on Availability, and nobody else
// can set it for them.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let client;
let person;
let asPerson;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client } = await Forge.makeSite(admin.api, 'Profile Co', RUN_ID));
  person = await Forge.makePerson(admin.api, client.id, 'staff', 'Selfie');
  asPerson = await Forge.signedIn(browser, baseURL, person.login, Forge.PASSWORD);
});

test.afterAll(async () => {
  await asPerson?.context.close();
  await admin?.context.close();
});

test('the pill opens your profile, and Slack preferences save from it', async () => {
  const page = await asPerson.context.newPage();

  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 30_000 });
  await page.getByTestId('bwx-profile').click();

  await expect(page.getByTestId('bwx-profile-screen')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-me-name')).toHaveText('Selfie');
  await expect(page.getByTestId('bwx-profile-logout')).toHaveAttribute('href', /action=logout/);
  await expect(page.getByTestId('bwx-profile-slack-status')).toHaveAttribute('data-connected', 'no');

  // Preferences save without a webhook: nothing to connect to yet, but the
  // choice is kept for when there is.
  await page.getByTestId('bwx-profile-slack-pref-morning').uncheck();
  await page.getByTestId('bwx-profile-slack-save').click();
  await expect(page.getByTestId('bwx-profile-notice')).toHaveText('Saved.');

  // A webhook that is not Slack's is refused by field.
  await page.getByTestId('bwx-profile-slack-url').fill('https://example.test/not-slack');
  await page.getByTestId('bwx-profile-slack-save').click();
  await expect(page.getByTestId('bwx-profile-notice')).toContainText('hooks.slack.com');

  await page.close();
});

test('a working week and a day off set on the profile are what Availability shows', async () => {
  const page = await asPerson.context.newPage();

  await page.goto('/blueworx-forge/#screen=profile');
  await expect(page.getByTestId('bwx-profile-screen')).toBeVisible({ timeout: 30_000 });

  // No picker: the person is whoever is signed in.
  await expect(page.getByTestId('bwx-availability-person')).toHaveCount(0);
  await expect(page.getByTestId('bwx-availability-recorded')).toHaveAttribute('data-recorded', 'no');

  await page.getByTestId('bwx-availability-set-hours').click();
  for (const day of ['mon', 'tue', 'wed', 'thu']) {
    await page.getByTestId(`bwx-availability-hours-hours_${day}`).fill('7.5');
  }
  await page.getByTestId('bwx-availability-hours-save').click();
  await expect(page.getByTestId('bwx-availability-recorded')).toHaveAttribute('data-recorded', 'yes');

  await page.getByTestId('bwx-availability-add-leave').click();
  await page.getByTestId('bwx-availability-leave-note').fill(`Dentist ${RUN_ID}`);
  await page.getByTestId('bwx-availability-leave-save').click();
  await expect(page.getByTestId('bwx-availability-leave')).toContainText(`Dentist ${RUN_ID}`);

  // The administrator's Availability screen reads the same record.
  const seen = await admin.api.get(`/users/${person.id}/availability`);

  expect(seen.current.hours_week).toBe(30);
  expect(seen.leave.map((one) => one.note)).toContain(`Dentist ${RUN_ID}`);

  await page.close();
});

test('somebody else on staff cannot set your hours', async ({ browser, baseURL }) => {
  const other = await Forge.makePerson(admin.api, client.id, 'staff', 'Other');
  const asOther = await Forge.signedIn(browser, baseURL, other.login, Forge.PASSWORD);

  const wrote = await asOther.api.post(`/users/${person.id}/availability/hours`, {
    effective_from: '2020-01-01',
    hours_mon: 1,
  });

  expect(wrote.status()).toBe(403);

  // Their own, they may.
  const own = await asOther.api.post(`/users/${other.id}/availability/hours`, {
    effective_from: '2020-01-01',
    hours_mon: 1,
  });

  expect(own.status(), await own.text()).toBe(200);

  await asOther.context.close();
});
