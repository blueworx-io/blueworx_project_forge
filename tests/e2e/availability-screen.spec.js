import { test, expect } from '@playwright/test';

// #136 walked as somebody setting a person up walks it. The numbers here are
// deliberately date-independent: hours are set on all seven days, so the next
// seven days come to the same total whichever day the suite happens to run on.
// A test that had to know what today is would be a test that fails on a Sunday.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const AVAILABILITY = '/wp-admin/admin.php?page=blueworx-forge-availability';
const PEOPLE = '/wp-admin/admin.php?page=blueworx-forge-people';

// Nothing is ever deleted and the instance is kept between runs, so a hardcoded
// address passes once and fails for ever after.
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const PERSON = `Availability ${RUN_ID}`;

const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function today() {
  return new Date().toISOString().slice(0, 10);
}

function daysFromToday(n) {
  const d = new Date();
  d.setUTCDate(d.getUTCDate() + n);

  return d.toISOString().slice(0, 10);
}

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

async function addPerson(page, name) {
  await page.goto(PEOPLE);
  await page.fill('#bwx-person-name', name);
  await page.fill('#bwx-person-email', `availability.${RUN_ID}@example.test`);
  await page.click('form[data-bwx-add-person] input[type="submit"]');
  await expect(page.locator(`[data-bwx-person-name]:text-is("${name}")`)).toBeVisible();
}

async function showPerson(page, name) {
  await page.goto(AVAILABILITY);
  await page.selectOption('#bwx-person', { label: name });
  await page.click('form[data-bwx-person-picker] input[type="submit"]');
  await expect(page.locator('[data-bwx-person-name]')).toHaveText(name);
}

async function setHours(page, hoursPerDay, from = today()) {
  await page.fill('#bwx-effective-from', from);

  for (const day of DAYS) {
    await page.fill(`#bwx-hours_${day}`, String(hoursPerDay));
  }

  await page.click('form[data-bwx-set-hours] input[type="submit"]');
  await expect(page.locator('[data-bwx-result="hours-set"]')).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage();
  await signIn(page);
  await addPerson(page, PERSON);
  await page.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test('the Forge menu offers the availability screen', async ({ page }) => {
  await page.goto(PEOPLE);

  await expect(page.locator('#adminmenu a[href$="page=blueworx-forge-availability"]')).toHaveCount(1);
});

test('a person nobody has set up says so, rather than showing no time', async ({ page }) => {
  await showPerson(page, PERSON);

  // The distinction the whole screen turns on: nothing recorded is not the
  // same as no hours, and a zero would read as the second.
  await expect(page.locator('[data-bwx-availability]')).toHaveAttribute('data-bwx-availability', 'unrecorded');
  await expect(page.locator('[data-bwx-availability]')).toContainText('different from having no time');
});

test('hours recorded for every day give a week of that many hours', async ({ page }) => {
  await showPerson(page, PERSON);
  await setHours(page, 8);

  await expect(page.locator('[data-bwx-availability]')).toHaveAttribute('data-bwx-availability', 'recorded');
  await expect(page.locator('[data-bwx-available-hours]')).toHaveText('56h');
  await expect(page.locator('[data-bwx-availability-days] tr[data-bwx-day]')).toHaveCount(7);
});

test('leave takes whole days out, including the day it ends on', async ({ page }) => {
  await showPerson(page, PERSON);

  // Today and tomorrow. Two days at 8 hours, so 56 becomes 40 — and the second
  // day only disappears if the end date is treated as inclusive.
  await page.fill('#bwx-leave-from', today());
  await page.fill('#bwx-leave-to', daysFromToday(1));
  await page.selectOption('#bwx-leave-kind', 'leave');
  await page.fill('#bwx-leave-note', `Booked by ${RUN_ID}`);
  await page.click('form[data-bwx-add-leave] input[type="submit"]');

  await expect(page.locator('[data-bwx-result="leave-added"]')).toBeVisible();
  await expect(page.locator('[data-bwx-available-hours]')).toHaveText('40h');

  // And the days say why they are empty rather than just being empty.
  await expect(page.locator(`tr[data-bwx-day="${today()}"]`)).toHaveAttribute('data-bwx-day-reason', 'leave');
  await expect(page.locator(`tr[data-bwx-day="${daysFromToday(1)}"]`)).toHaveAttribute('data-bwx-day-reason', 'leave');
  await expect(page.locator(`tr[data-bwx-day="${daysFromToday(2)}"]`)).toHaveAttribute('data-bwx-day-reason', '');
});

test('removing the leave gives the time back', async ({ page }) => {
  await showPerson(page, PERSON);

  await page.locator('[data-bwx-leave] [data-bwx-remove-leave]').first().click();

  await expect(page.locator('[data-bwx-result="leave-removed"]')).toBeVisible();
  await expect(page.locator('[data-bwx-available-hours]')).toHaveText('56h');
});

test('changing hours from a future date leaves today alone', async ({ page }) => {
  await showPerson(page, PERSON);

  // The point of effective dating. Four hours a day from three days out: the
  // first three days stay at 8, the last four drop to 4.
  await setHours(page, 4, daysFromToday(3));

  await expect(page.locator('[data-bwx-available-hours]')).toHaveText('40h');

  // Both statements are kept, rather than the second replacing the first.
  await expect(page.locator('[data-bwx-pattern-history] li')).toHaveCount(2);
});
