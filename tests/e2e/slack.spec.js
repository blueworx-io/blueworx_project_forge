import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, makeItem, PASSWORD } from './helpers/forge.js';
import { installSlackStub, removeSlackStub, stubWebhook, slackMessages, clearSlackMessages } from './helpers/slack.js';

// Slack for staff, against a stand-in webhook: connect on the profile page,
// be told once when assigned, once when commented on, and once each morning.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => installSlackStub());
test.afterAll(() => removeSlackStub());

test('a person connects Slack and hears about work once', async ({ browser, baseURL, page }) => {
  test.slow();

  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  await clearSlackMessages(admin.api.request);

  const { client, site } = await makeSite(admin.api, 'Slack Co', RUN_ID);
  const person = await makePerson(admin.api, client.id, 'staff', 'slacker');
  const who = `p${RUN_ID.replace(/\D/g, '')}`;

  // Connect on the profile page, as the person.
  await signIn(page, person.login, PASSWORD);
  await page.goto('/wp-admin/profile.php');
  await expect(page.locator('[data-bwx-slack="not-connected"]')).toBeVisible();
  await page.locator('#bwx-forge-slack-url').fill(stubWebhook(baseURL, who));
  await page.locator('#submit').click();
  // The first call after the stand-in lands is a slow one on the test server.
  await expect(page.locator('[data-bwx-slack="connected"]')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('[data-bwx-slack="connected"]')).toContainText('Last message');

  let messages = await slackMessages(admin.api.request, who);
  expect(messages).toHaveLength(1);
  expect(messages[0].body.text).toContain('Forge is connected');

  // Assigned: one message, with the title and a link to the task.
  const made = await makeItem(admin.api, site.id, { title: `Slack task ${RUN_ID}`, primary_user_id: person.id });
  expect(made.status(), await made.text()).toBe(200);
  const item = (await made.json()).item;

  messages = await slackMessages(admin.api.request, who);
  expect(messages).toHaveLength(2);
  expect(messages[1].body.text).toContain(`New work for you to do: Slack task ${RUN_ID}`);
  expect(JSON.stringify(messages[1].body.blocks)).toContain(`#item=${item.id}`);

  // The same seat saved again is nothing new.
  const current = await admin.api.get(`/work-items/${item.id}`);
  const again = await admin.api.patch(`/work-items/${item.id}`, { primary_user_id: person.id, record_version: current.item.record_version });
  expect(again.status(), await again.text()).toBe(200);
  expect(await slackMessages(admin.api.request, who)).toHaveLength(2);

  // A comment by somebody else: one message, quoted.
  const commented = await admin.api.post(`/work-items/${item.id}/comments`, { body: 'Looks fine to me', kind: 'comment', visibility: 'internal' });
  expect(commented.status(), await commented.text()).toBe(200);
  messages = await slackMessages(admin.api.request, who);
  expect(messages).toHaveLength(3);
  expect(messages[2].body.text).toContain('commented');
  expect(messages[2].body.text).toContain('Looks fine to me');

  // A preference switched off is honoured.
  await page.locator('#bwx-forge-slack-pref-comment').uncheck();
  await page.locator('#submit').click();
  await expect(page.locator('[data-bwx-slack="connected"]')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#bwx-forge-slack-pref-comment')).not.toBeChecked();
  const quiet = await admin.api.post(`/work-items/${item.id}/comments`, { body: 'Second thoughts', kind: 'comment', visibility: 'internal' });
  expect(quiet.status()).toBe(200);
  expect(await slackMessages(admin.api.request, who)).toHaveLength(3);

  // The morning message: due today, once, however many times it runs.
  const fresh = await admin.api.get(`/work-items/${item.id}`);
  const today = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const date = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
  const dated = await admin.api.patch(`/work-items/${item.id}`, { planned_start: date, planned_due: date, record_version: fresh.item.record_version });
  expect(dated.status(), await dated.text()).toBe(200);

  const first = await (await admin.api.post('/slack/morning', {})).json();
  expect(first.sent).toBeGreaterThanOrEqual(1);
  await admin.api.post('/slack/morning', {});

  messages = await slackMessages(admin.api.request, who);
  const mornings = messages.filter((one) => one.body.text.includes('due today'));
  expect(mornings).toHaveLength(1);
  expect(mornings[0].body.text).toContain('1 due today');
  expect(JSON.stringify(mornings[0].body.blocks)).toContain(`Slack task ${RUN_ID}`);

  // Disconnect forgets the webhook; nothing more arrives.
  await page.locator('#bwx-forge-slack-disconnect').check();
  await page.locator('#submit').click();
  await expect(page.locator('[data-bwx-slack="not-connected"]')).toBeVisible({ timeout: 30_000 });
  const after = await makeItem(admin.api, site.id, { title: `Unheard ${RUN_ID}`, primary_user_id: person.id });
  expect(after.status()).toBe(200);
  expect(await slackMessages(admin.api.request, who)).toHaveLength(messages.length);

  await admin.context.close();
});
