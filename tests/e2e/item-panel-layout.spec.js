import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// The task panel's frame (Luke's feedback of 2026-09-17, block 2): a fixed
// footer with Delete and Save; history folded, honest and signed; Who pays
// with four answers where Site bug means we delivered the cause; and a client
// pick on the New task form.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;
let other;
let item;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await makeSite(admin.api, 'Panel Co', RUN_ID));
  ({ site: other } = await makeSite(admin.api, 'Other Co', RUN_ID));
  item = (await (await makeItem(admin.api, site.id, { title: `Framed ${RUN_ID}` })).json()).item;
});

test.afterAll(async () => {
  await admin?.context.close();
});

// Every test drives its own page in the administrator's signed-in context.
async function openThePanel(page) {
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: `Framed ${RUN_ID}` }).click();
  await expect(page.getByTestId('bwx-panel')).toBeVisible();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
}

test('the footer holds Delete and Save, and history stays folded until opened', async () => {
  const page = await admin.context.newPage();
  await openThePanel(page);

  const foot = page.getByTestId('bwx-panel-foot');
  await expect(foot.getByTestId('bwx-item-delete')).toBeVisible();
  await expect(foot.getByTestId('bwx-save')).toBeVisible();

  // Scope and Requirements are gone, and so are Not covered and Completed when (#493, #494).
  await expect(page.locator('#bwx-scope')).toHaveCount(0);
  await expect(page.locator('#bwx-requirements')).toHaveCount(0);
  await expect(page.locator('label[for="bwx-problem"]')).toHaveText('Item description');
  await expect(page.locator('#bwx-acceptance_criteria')).toHaveCount(0);
  await expect(page.locator('#bwx-non_goals')).toHaveCount(0);

  const history = page.getByTestId('bwx-history-wrap');
  await expect(history).not.toHaveAttribute('open', /.*/);
  await history.locator('summary').click();
  await expect(history).toHaveAttribute('open', /.*/);
  await expect(page.locator('[data-testid="bwx-history"] li')).toHaveCount(1);
  await expect(page.getByTestId('bwx-history-who').first()).toHaveText('admin');
  await page.close();
});

test('a pick saves on its own and typed text with Save changes, both signed in the history', async () => {
  test.slow();

  const read = () => admin.api.get(`/work-items/${item.id}`).then((answer) => answer.item);
  const page = await admin.context.newPage();
  await openThePanel(page);

  // #544. The priority saves the moment it is picked; the title waits for Save changes.
  await page.fill('#bwx-title', `Framed ${RUN_ID} renamed`);
  await page.selectOption('#bwx-priority', 'high');
  await expect.poll(() => read().then((now) => [now.priority, now.title]), { timeout: 30_000 }).toEqual(['high', `Framed ${RUN_ID}`]);

  await page.getByTestId('bwx-save').click();
  await expect.poll(() => read().then((now) => now.title), { timeout: 30_000 }).toBe(`Framed ${RUN_ID} renamed`);

  // Both edits are in the history, signed. Two edits close together may
  // share a line, so the text is what is checked rather than the count.
  await page.getByTestId('bwx-history-wrap').locator('summary').click();
  const history = page.getByTestId('bwx-history');
  await expect(history).toContainText('priority', { timeout: 30_000 });
  await expect(history).toContainText('title', { timeout: 30_000 });
  await expect(history.getByTestId('bwx-history-who').last()).toHaveText('admin');
  await page.close();
});

test('Who pays offers four answers, and Site bug means we delivered the cause', async () => {
  const page = await admin.context.newPage();
  await openThePanel(page);

  const pick = page.locator('#bwx-commercial_class');
  await expect(pick.locator('option')).toHaveText([
    'To be confirmed',
    'Site bug — no charge to client',
    'Client — charge to client',
    'No charge — general item',
  ]);
  await expect(page.locator('#bwx-delivered_by_forge')).toHaveCount(0);

  await pick.selectOption('free-bug');
  await page.getByTestId('bwx-save').click();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.', { timeout: 30_000 });

  const saved = await admin.api.get(`/work-items/${item.id}`);
  expect(saved.item.commercial_class).toBe('free-bug');
  expect(saved.item.delivered_by_forge).toBe(true);

  // The general answer is accepted too, and is not chargeable.
  await pick.selectOption('free-general');
  await page.getByTestId('bwx-save').click();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.', { timeout: 30_000 });
  expect((await admin.api.get(`/work-items/${item.id}`)).item.commercial_class).toBe('free-general');
  await page.close();
});

// #505. Other green banners close themselves after five seconds; Save's stays
// until the next thing happens, so nobody is left wondering whether it worked.
test('Saved stays on screen after a save', async () => {
  const page = await admin.context.newPage();
  await openThePanel(page);

  await page.fill('#bwx-title', `Framed ${RUN_ID} kept`);
  await page.getByTestId('bwx-save').click();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.', { timeout: 30_000 });

  await page.waitForTimeout(6_000);
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.', { timeout: 1_000 });
  await page.close();
});

test('New task asks which client, offers them all, and lands the item where it was pointed', async () => {
  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-add').click();

  const client = page.getByTestId('bwx-new-site');
  await expect(client).toBeVisible();
  await expect(client).toHaveValue(site.id);
  await expect(client.locator(`option[value="${other.id}"]`)).toHaveCount(1);

  await client.selectOption(other.id);
  await page.fill('#bwx-new-title', `Pointed ${RUN_ID}`);
  await page.locator('[data-testid="bwx-new-work"] button.bwx-button').first().click();
  await expect(page.getByTestId('bwx-new-work')).toBeHidden();

  const listed = await admin.api.get(`/work-items?client_site_id=${other.id}`);
  expect(listed.items.some((one) => one.title === `Pointed ${RUN_ID}`)).toBe(true);
  await page.close();
});
