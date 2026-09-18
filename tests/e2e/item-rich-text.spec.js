import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// Item description and Completed when take formatting — and only the
// formatting the editor offers. Anything else is dropped on the way in, and
// formatting with nothing inside it is nothing.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;
let item;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await makeSite(admin.api, 'Rich Co', RUN_ID));
  item = (await (await makeItem(admin.api, site.id, { title: `Formatted ${RUN_ID}`, problem: 'Plain to begin with.' })).json()).item;
});

test.afterAll(async () => {
  await admin?.context.close();
});

async function openThePanel(page) {
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: `Formatted ${RUN_ID}` }).click();
  await expect(page.getByTestId('bwx-panel')).toBeVisible();
  await expect(page.getByTestId('bwx-save')).toBeVisible();
}

test('a word made bold in the panel is stored bold and comes back bold', async () => {
  const page = await admin.context.newPage();
  await openThePanel(page);

  const editor = page.getByTestId('bwx-problem');
  await expect(editor).toContainText('Plain to begin with.');
  await editor.click();
  await page.keyboard.press('Control+a');
  await page.locator('.bwx-task').getByRole('button', { name: 'Bold' }).first().click();
  await page.getByTestId('bwx-save').click();
  await expect(page.getByTestId('bwx-panel-notice')).toHaveText('Saved.');

  const saved = await admin.api.get(`/work-items/${item.id}`);
  expect(saved.item.problem).toContain('<strong>');
  expect(saved.item.problem).toContain('Plain to begin with.');

  await page.reload();
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.getByTestId('bwx-card').filter({ hasText: `Formatted ${RUN_ID}` }).click();
  await expect(page.getByTestId('bwx-problem').locator('strong')).toContainText('Plain to begin with.');
  await page.close();
});

test('the server keeps the allowed formatting and drops the rest', async () => {
  const before = (await admin.api.get(`/work-items/${item.id}`)).item;

  const kept = await admin.api.patch(`/work-items/${item.id}`, {
    problem: '<p>Hi <em>there</em></p><script>alert(1)</script><img src="x"><ul><li>one</li></ul>',
    record_version: before.record_version,
  });
  expect(kept.status()).toBe(200);
  const after = (await admin.api.get(`/work-items/${item.id}`)).item;
  expect(after.problem).not.toContain('<script');
  expect(after.problem).not.toContain('<img');
  expect(after.problem).toContain('<em>there</em>');
  expect(after.problem).toContain('<li>one</li>');

  // An empty paragraph is an empty field: it cannot satisfy anything.
  const emptied = await admin.api.patch(`/work-items/${item.id}`, {
    acceptance_criteria: '<p></p><p><br></p>',
    record_version: after.record_version,
  });
  expect(emptied.status()).toBe(200);
  expect((await admin.api.get(`/work-items/${item.id}`)).item.acceptance_criteria).toBe('');
});
