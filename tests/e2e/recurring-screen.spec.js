import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makePerson } from './helpers/forge.js';

// The Recurring tasks screen: add one through the form, make today's now,
// and find it on the studio's board.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('a weekly task added on screen becomes today’s card in Up Next', async ({ browser, baseURL, page }) => {
  test.slow();

  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const sites = await admin.api.get('/client-sites');
  const studio = sites.sites.find((one) => one.studio);
  const person = await makePerson(admin.api, studio.client_id, 'staff', 'weekly');

  // Today's weekday as the server sees it — its timezone, not this machine's.
  const today = (await admin.api.get('/standup')).today;
  const weekday = new Date(`${today}T12:00:00Z`).getUTCDay() || 7;

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-recurring').click();
  await expect(page.getByTestId('bwx-recurring')).toBeVisible({ timeout: 30_000 });

  await page.getByTestId('bwx-recurring-add').click();
  const form = page.getByTestId('bwx-recurring-form');
  await expect(form).toBeVisible();

  await page.getByTestId('bwx-recurring-title').fill(`Weekly tidy ${RUN_ID}`);
  // Nothing saves until what to do, who, and the hours are there (2026-09-19).
  await expect(page.getByTestId('bwx-recurring-save')).toBeDisabled();
  await page.getByTestId('bwx-recurring-description').click();
  await page.keyboard.type('Put things back where they live.');
  await page.getByTestId('bwx-recurring-checklist-add').click();
  await page.getByTestId('bwx-recurring-checklist-text').first().fill('Desks');
  await page.getByTestId('bwx-recurring-every').selectOption('week');

  // Only today's weekday ticked, so it is due today and the cadence text is
  // predictable.
  for (let day = 1; day <= 7; day++) {
    const box = page.getByTestId(`bwx-recurring-day-${day}`);
    if ((await box.isChecked()) !== (day === weekday)) {
      await box.click();
    }
  }

  await page.getByTestId(`bwx-recurring-assignee-${person.id}`).check();
  await page.getByTestId('bwx-recurring-hours_each').selectOption('1');
  await page.getByTestId('bwx-recurring-save').click();
  await expect(form).toBeHidden();

  const row = page.locator('[data-testid="bwx-recurring-table"] tr', { hasText: `Weekly tidy ${RUN_ID}` });
  await expect(row).toBeVisible();
  await expect(row).toContainText('Every ');

  await page.getByTestId('bwx-recurring-run').click();
  await expect(row.getByTestId('bwx-recurring-last')).toBeVisible();

  // On the board, in Up Next, on the studio's site.
  await page.getByTestId('bwx-screen-work').click();
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', studio.id);
  const card = page.locator('[data-testid="bwx-card"]', { hasText: `Weekly tidy ${RUN_ID}` });
  await expect(card).toBeVisible();
  await expect(card).toHaveAttribute('data-stage', 'up-next');

  await admin.context.close();
});
