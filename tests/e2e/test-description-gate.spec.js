import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #550. A task cannot leave In Development for In Review until "How to test
// it" has something in it. The test steps stay optional.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let client;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client, site } = await Forge.makeSite(admin.api, 'Testing Co', RUN_ID));
  await Forge.onSupport(admin, site.id, 200);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('How to test it has to be written before a task goes to review', async ({ browser, baseURL }) => {
  test.slow();

  const crew = await Forge.team(admin.api, browser, baseURL, client.id);
  const title = `Untested ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const developing = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period', 'technical-audit', 'up-next', 'design-process', 'in-development'], { seats: crew.seats });

  // Refused with the box empty, and the reason names it.
  const refused = await admin.api.post(`/work-items/${developing.id}/transition`, { to: 'in-review', record_version: developing.record_version });
  expect(refused.status()).toBe(409);
  const why = await refused.json();
  expect(why.unmet.map((row) => row.id)).toEqual(['G-IN-DEVELOPMENT-2']);
  expect(why.unmet[0].label).toBe('How to test it');

  // The card's check and the panel's marker say so.
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);

  const card = page.getByTestId('bwx-card').filter({ hasText: title });
  await expect(card.getByTestId('bwx-card-ready')).toHaveAttribute('data-ready', 'false');
  await expect(card.getByTestId('bwx-card-missing')).toContainText('How to test it');

  await card.click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
  await expect(page.locator('[data-testid="bwx-needed"][data-field="test_description"]')).toContainText('needed to leave In development');
  await page.close();

  // Written, and the move goes ahead; the test steps were never asked for.
  const current = (await admin.api.get(`/work-items/${developing.id}`)).item;
  const written = await admin.api.patch(`/work-items/${developing.id}`, { test_description: '<p>Open it and press the button.</p>', record_version: current.record_version });
  expect(written.status(), await written.text()).toBe(200);

  const moved = await admin.api.post(`/work-items/${developing.id}/transition`, { to: 'in-review', record_version: (await written.json()).item.record_version });
  expect(moved.status(), await moved.text()).toBe(200);
  expect((await moved.json()).item.stage).toBe('in-review');

  await crew.close();
});
