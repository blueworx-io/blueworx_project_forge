import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #526. A recurring task's card shows the people assigned, and its check is
// green unless somebody is over capacity: an assignee is every role.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

test('a chore card shows its assignee and a green check, red only over capacity', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'Chore cards', RUN_ID);
  const today = (await admin.api.get('/standup')).today;

  const roomy = await Forge.makePerson(admin.api, client.id, 'staff', `roomy${RUN_ID}`);
  const full = await Forge.makePerson(admin.api, client.id, 'staff', `full${RUN_ID}`);
  await Forge.setHours(admin.api, roomy.id, 8);
  await Forge.setHours(admin.api, full.id, 0.25);

  const chore = async (title, person, hours) => {
    const made = await admin.api.post('/recurring', {
      title, description: '<p>Do it.</p>', rule: { every: 'day' }, starts_on: today,
      assignees: [person.id], hours_each: hours, client_site_id: site.id,
    });
    expect(made.status(), await made.text()).toBe(200);
  };
  await chore(`Light chore ${RUN_ID}`, roomy, '0.5');
  await chore(`Heavy chore ${RUN_ID}`, full, '4');
  await admin.api.post('/recurring/run', {});

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);

  const light = page.getByTestId('bwx-card').filter({ hasText: `Light chore ${RUN_ID}` });
  const heavy = page.getByTestId('bwx-card').filter({ hasText: `Heavy chore ${RUN_ID}` });
  await expect(light).toHaveCount(1);
  await expect(heavy).toHaveCount(1);

  // The person, not three empty seats.
  await expect(light.locator('.fk-avatar')).toHaveCount(1);
  await expect(light.locator('.fk-avatar[data-empty]')).toHaveCount(0);
  await expect(light.locator('.fk-avatar')).not.toHaveText('?');

  // Room: green. No room: red, for that reason alone.
  await expect(light.getByTestId('bwx-card-ready')).toHaveAttribute('data-ready', 'true');
  await expect(heavy.getByTestId('bwx-card-ready')).toHaveAttribute('data-ready', 'false');
  await expect(heavy.getByTestId('bwx-card-ready')).toHaveAttribute('aria-label', 'Not ready to move on. Still needed: Capacity check');

  await page.close();
  await admin.context.close();
});
