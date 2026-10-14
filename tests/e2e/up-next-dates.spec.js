import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #547. A task cannot leave Up Next until every person's date is set: Deliver
// by, Review by and Release by, plus Design by when it has a designer.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const TO_UP_NEXT = ['triage', 'documentation-period', 'technical-audit', 'up-next'];

let admin;
let client;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ client, site } = await Forge.makeSite(admin.api, 'Dated Co', RUN_ID));
  await Forge.onSupport(admin, site.id, 200);
});

test.afterAll(async () => {
  await admin?.context.close();
});

/** An item parked at Up Next with people, hours and only Deliver by set. */
async function parked(title, extra = {}) {
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const item = await Forge.walkTo(admin.api, made, TO_UP_NEXT, { seats: await Forge.seatsFor(admin.api, made) });
  const current = (await admin.api.get(`/work-items/${item.id}`)).item;
  const edited = await admin.api.patch(`/work-items/${item.id}`, {
    hours_primary: 2,
    hours_review: 1,
    hours_delivery: 0.5,
    planned_due: '2026-11-10',
    priority: 'normal',
    ...extra,
    record_version: current.record_version,
  });

  expect(edited.status(), await edited.text()).toBe(200);

  return (await edited.json()).item;
}

// #548. Out of Up Next: into Design with a designer, straight to In Development without.
const tryMove = (item) => admin.api.post(`/work-items/${item.id}/transition`, { to: item.designer_id ? 'design-process' : 'in-development', record_version: item.record_version });

test('with no designer, Deliver by alone is refused and the three dates let it go', async () => {
  test.slow();

  const title = `Plain ${RUN_ID}`;
  const item = await parked(title);

  const refused = await tryMove(item);
  expect(refused.status()).toBe(409);
  const unmet = (await refused.json()).unmet.map((row) => row.label);
  expect(unmet).toContain('Review by date');
  expect(unmet).toContain('Release by date');
  expect(unmet).not.toContain('Design by date');
  expect(unmet).not.toContain('Deliver by date');

  // The card and the panel name the missing dates.
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);

  const card = page.getByTestId('bwx-card').filter({ hasText: title });
  await expect(card.getByTestId('bwx-card-missing')).toContainText('Review by date');
  await card.click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
  await page.getByTestId('bwx-section-dates').click();
  await expect(page.locator('[data-testid="bwx-needed"][data-field="review_target"]')).toContainText('needed to leave Up next');
  await expect(page.locator('[data-testid="bwx-needed"][data-field="release_target"]')).toContainText('needed to leave Up next');
  await expect(page.locator('[data-testid="bwx-needed"][data-field="design_target"]')).toHaveCount(0);
  await page.close();

  const dated = await admin.api.patch(`/work-items/${item.id}`, { review_target: '2026-11-11', release_target: '2026-11-12', record_version: item.record_version });
  expect(dated.status(), await dated.text()).toBe(200);

  const moved = await tryMove((await dated.json()).item);
  expect(moved.status(), await moved.text()).toBe(200);
});

test('with a designer, Design by is needed too', async () => {
  test.slow();

  const designer = await Forge.makePerson(admin.api, client.id, 'staff', `designer${RUN_ID}`);
  const item = await parked(`Designed ${RUN_ID}`, {
    designer_id: designer.id,
    hours_designer: 1,
    review_target: '2026-11-11',
    release_target: '2026-11-12',
  });

  const refused = await tryMove(item);
  expect(refused.status()).toBe(409);
  expect((await refused.json()).unmet.map((row) => row.label)).toEqual(['Design by date']);

  const dated = await admin.api.patch(`/work-items/${item.id}`, { design_target: '2026-11-09', record_version: item.record_version });
  expect(dated.status(), await dated.text()).toBe(200);

  const moved = await tryMove((await dated.json()).item);
  expect(moved.status(), await moved.text()).toBe(200);
});
