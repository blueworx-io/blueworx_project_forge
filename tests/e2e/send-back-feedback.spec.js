import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #549. Sending a task back asks for one thing: where it goes, and a Feedback
// box. What is written there is what the history shows. Nothing written, no
// send-back.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Feedback Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('the send-back form is Back to and one Feedback box, and the feedback is what the history shows', async () => {
  test.slow();

  const title = `Sent back ${RUN_ID}`;
  const made = (await (await Forge.makeItem(admin.api, site.id, { title })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);

  // Over REST, an empty send-back is refused in plain words.
  const empty = await admin.api.post(`/work-items/${item.id}/return`, { to: 'triage', reason: '', feedback: '', record_version: item.record_version });
  expect(empty.status()).toBe(400);
  expect(await empty.text()).toContain('Say what needs to change');

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', site.id);
  await page.getByTestId('bwx-card').filter({ hasText: title }).click();
  await expect(page.getByTestId('bwx-save')).toBeVisible();

  await page.getByTestId('bwx-show-return').click();
  const form = page.locator('div[data-testid="bwx-return"]');
  await expect(form).toBeVisible();

  // One box, labelled Feedback; no separate Why.
  await expect(form.getByLabel('Back to')).toBeVisible();
  await expect(form.getByLabel('Feedback')).toBeVisible();
  await expect(form.getByTestId('bwx-return-reason')).toHaveCount(0);
  await expect(form.getByLabel('Why')).toHaveCount(0);

  await form.getByLabel('Back to').selectOption('triage');
  const send = form.getByRole('button', { name: 'Send back' });
  await expect(send).toBeDisabled();

  await form.getByLabel('Feedback').fill('The scope changed: the client wants deposits too.');
  await expect(send).toBeEnabled();
  await send.click();
  await expect.poll(async () => (await admin.api.get(`/work-items/${item.id}`)).item.stage, { timeout: 30_000 }).toBe('triage');

  // The history carries the feedback.
  await page.getByTestId('bwx-section-evidence').click();
  await expect(page.getByTestId('bwx-history')).toContainText('The scope changed: the client wants deposits too.');
  await page.close();

  const after = await admin.api.get(`/work-items/${item.id}`);
  expect(after.item.stage).toBe('triage');
  const returned = after.history.find((event) => 'returned' === event.action);
  expect(returned.reason).toBe('The scope changed: the client wants deposits too.');
});
