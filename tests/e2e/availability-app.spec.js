import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson } from './helpers/forge.js';

// The availability screen in the app: pick a person, see their week, set
// their hours, record time off. Names carry a run id because the instance is
// reused between runs.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const NAME = `avail${STAMP}`;

test.describe.configure({ mode: 'serial' });

let admin;
let person;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await makeSite(admin.api, 'Availability app', RUN_ID);
  person = await makePerson(admin.api, where.client.id, 'staff', NAME);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
});

test('the rail offers Availability under Team, and it opens', async ({ page }) => {
  const entry = page.getByTestId('bwx-screen-availability');
  await expect(entry).toBeVisible();

  await entry.click();
  await expect(page.getByTestId('bwx-availability')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('h1')).toHaveText('Availability');
});

test('a person nobody has set up says so, rather than showing no time', async ({ page }) => {
  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);

  const state = page.getByTestId('bwx-availability-recorded');
  await expect(state).toHaveAttribute('data-recorded', 'no');
  await expect(state).toContainText('Nobody has said what');
  await expect(page.getByTestId('bwx-availability-no-leave')).toBeVisible();
});

test('a person with hours shows the week, the pattern, and the history', async ({ page }) => {
  // Set through REST; the screen's own form is the next test.
  await admin.api.post(`/users/${person.id}/availability/hours`, {
    effective_from: '2020-01-01',
    hours_sun: 0, hours_mon: 8, hours_tue: 8, hours_wed: 8, hours_thu: 8, hours_fri: 4, hours_sat: 0,
  });

  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);

  await expect(page.getByTestId('bwx-availability-recorded')).toHaveAttribute('data-recorded', 'yes');
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('36h');
  await expect(page.getByTestId('bwx-availability-day-hours_fri')).toHaveText('4');
  await expect(page.locator('[data-testid="bwx-availability-history"] tbody tr')).toHaveCount(1);
});

test('setting the week from the screen changes the total without a reload', async ({ page }) => {
  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('36h');

  await page.getByTestId('bwx-availability-set-hours').click();
  const form = page.getByTestId('bwx-availability-hours-form');
  await form.getByTestId('bwx-availability-effective-from').fill('2021-01-01');
  for (const day of ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']) {
    await form.getByTestId(`bwx-availability-hours-hours_${day}`).fill('5');
  }
  await form.getByTestId('bwx-availability-hours-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('35h');
  await expect(page.locator('[data-testid="bwx-availability-history"] tbody tr')).toHaveCount(2);
});

test('a week with no date is refused on the form, in words', async ({ page }) => {
  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);

  await page.getByTestId('bwx-availability-set-hours').click();
  const form = page.getByTestId('bwx-availability-hours-form');
  await form.getByTestId('bwx-availability-effective-from').fill('');
  await form.getByTestId('bwx-availability-hours-save').click();

  await expect(form.getByTestId('bwx-availability-hours-notice')).toContainText('date');
});

test('time off is added from the screen, listed, and taken out of the week', async ({ page }) => {
  const day = (offset) => {
    const d = new Date();
    d.setUTCDate(d.getUTCDate() + offset);

    return d.toISOString().slice(0, 10);
  };

  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('35h');

  await page.getByTestId('bwx-availability-add-leave').click();
  const form = page.getByTestId('bwx-availability-leave-form');
  await form.getByTestId('bwx-availability-leave-starts').fill(day(0));
  await form.getByTestId('bwx-availability-leave-ends').fill(day(6));
  await form.getByTestId('bwx-availability-leave-kind').selectOption('training');
  await form.getByTestId('bwx-availability-leave-note').fill(`Course ${STAMP}`);
  await form.getByTestId('bwx-availability-leave-save').click();

  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('0h');
  const row = page.locator('[data-testid="bwx-availability-leave"] tbody tr', { hasText: `Course ${STAMP}` });
  await expect(row).toBeVisible();
  await expect(row).toContainText('Training');
});

test('removing time off gives the week back', async ({ page }) => {
  await page.getByTestId('bwx-screen-availability').click();
  await page.getByTestId('bwx-availability-person').selectOption(person.id);

  const row = page.locator('[data-testid="bwx-availability-leave"] tbody tr', { hasText: `Course ${STAMP}` });
  page.once('dialog', (dialog) => dialog.accept());
  await row.getByTestId('bwx-availability-leave-remove').click();

  await expect(row).toHaveCount(0);
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('35h');
});

test('a link with the screen and person in the hash lands on them', async ({ page }) => {
  await page.goto(`/blueworx-forge/#screen=availability&person=${person.id}`);
  // The beforeEach already opened the app without a hash, so this is a
  // same-document fragment change, not a real navigation; a link from
  // outside always arrives as a fresh load, so force one to match that.
  await page.reload();

  await expect(page.getByTestId('bwx-availability')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-availability-person')).toHaveValue(person.id);
  await expect(page.getByTestId('bwx-availability-week-hours')).toHaveText('35h');
  // Read once and cleared, so a reload is a plain reload.
  expect(new URL(page.url()).hash).toBe('');
});
