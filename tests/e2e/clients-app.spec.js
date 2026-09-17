import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makePerson } from './helpers/forge.js';

// The Clients screen in the app (PR 4 of spec 2026-09-16): who we work for,
// their sites, who looks after them, where each site is with its onboarding
// and whether it is connected. Add a client, add a site, edit it, name a
// contact, issue and revoke a key, start onboarding, deactivate, rename the
// studio. Names carry a run id because the instance is reused between runs.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';

test.describe.configure({ mode: 'serial' });
// The key panel copies to the clipboard; the test reads the button, not the
// clipboard, but the write itself needs the permission or it rejects.
test.use({ permissions: ['clipboard-read', 'clipboard-write'] });

let admin;
let seeded;
let person;
let siteName;

/**
 * Makes sure there is a published checklist with at least one step in it —
 * the same walk onboarding-assign.spec.js takes, because publishing still
 * lives on the template admin page.
 */
async function publishAChecklist(page) {
  await page.goto(TEMPLATE);

  const start = page.locator('[data-bwx-start-draft="1"]');

  if (await start.count()) {
    await page.fill('#bwx-template-name', `Clients app ${RUN_ID}`);
    await start.locator('input[type="submit"]').click();
  } else {
    await page.locator('[data-bwx-copy-template="1"] input[type="submit"]').first().click();
  }

  await page.fill('#bwx-step-title', `Delegate the registrar ${RUN_ID}`);
  await page.check('#bwx-step-launch-critical');
  await page.locator('[data-bwx-add-step="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="step-added"]')).toBeVisible();

  await page.locator('[data-bwx-publish-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="published"]')).toBeVisible();
}

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const made = await admin.api.post('/clients', { display_name: `Seeded client ${RUN_ID}`, timezone: 'Europe/London' });
  expect(made.status(), await made.text()).toBe(200);
  seeded = (await made.json()).client;

  person = await makePerson(admin.api, seeded.id, 'staff', `contact${STAMP}`);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  // Landing by hash rather than clicking the rail each time. Coming from the
  // sign-in page this is a fresh load, which is when the app reads the hash.
  await page.goto('/blueworx-forge/#screen=clients');
  await expect(page.getByTestId('bwx-clients')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-clients-count')).toBeVisible({ timeout: 30_000 });
});

/** The clients row for one client, by name. */
function clientRow(page, name) {
  return page.getByTestId('bwx-clients-list').locator('tbody tr', { hasText: name });
}

/** The sites row for one site, by name. */
function siteRow(page, name) {
  return page.getByTestId('bwx-clients-sites').locator('tbody tr', { hasText: name });
}

/** Opens the seeded client's panel. */
async function selectSeeded(page) {
  await clientRow(page, seeded.display_name).click();
  await expect(page.getByTestId('bwx-clients-selected')).toHaveAttribute('data-client', seeded.id);
}

test('the rail offers Clients, and the list shows who we work for', async ({ page }) => {
  await expect(page.locator('h1')).toHaveText('Clients');
  await expect(page.getByTestId('bwx-screen-clients')).toHaveAttribute('aria-current', 'page');

  const row = clientRow(page, seeded.display_name);
  await expect(row).toBeVisible();
  await expect(row).toContainText('Active');
});

test('a client is added from a form and appears in the list', async ({ page }) => {
  const name = `Added client ${RUN_ID}`;

  await page.getByTestId('bwx-clients-add').click();
  const form = page.getByTestId('bwx-clients-form');
  await expect(form).toBeVisible();
  await expect(form.getByTestId('bwx-clients-form-timezone')).toHaveValue('Europe/London');

  await form.getByTestId('bwx-clients-form-name').fill(name);
  await form.getByTestId('bwx-clients-form-domains').fill('example.test, example.co.uk');
  await form.getByTestId('bwx-clients-form-save').click();

  await expect(form).toBeHidden();
  await expect(clientRow(page, name)).toBeVisible();
  await expect(clientRow(page, name)).toContainText('Active');
});

test('a client with no name is refused in the form, with the reason', async ({ page }) => {
  await page.getByTestId('bwx-clients-add').click();
  const form = page.getByTestId('bwx-clients-form');

  await form.getByTestId('bwx-clients-form-save').click();

  await expect(form.getByTestId('bwx-clients-form-notice')).toContainText('needs a name');
  await form.getByTestId('bwx-clients-form-cancel').click();
  await expect(form).toBeHidden();
});

test('picking a client shows its sites; a site is added and appears', async ({ page }) => {
  siteName = `Site ${RUN_ID}`;

  await selectSeeded(page);
  await expect(page.getByTestId('bwx-clients-sites')).toBeVisible();

  await page.getByTestId('bwx-clients-add-site').click();
  const form = page.getByTestId('bwx-clients-site-form');
  await expect(form).toBeVisible();

  await form.getByTestId('bwx-clients-site-name').fill(siteName);
  await form.getByTestId('bwx-clients-site-url').fill('https://example.test');
  await form.getByTestId('bwx-clients-site-save').click();

  await expect(form).toBeHidden();
  const row = siteRow(page, siteName);
  await expect(row).toBeVisible();
  await expect(row).toContainText('https://example.test');
  await expect(row).toContainText('Active');
  await expect(row).toContainText('Not connected');
});

test('a site is renamed from its row', async ({ page }) => {
  await selectSeeded(page);

  await siteRow(page, siteName).getByTestId('bwx-clients-site-edit').click();
  const form = page.getByTestId('bwx-clients-site-form');
  await expect(form.getByTestId('bwx-clients-site-name')).toHaveValue(siteName);

  siteName = `${siteName} renamed`;
  await form.getByTestId('bwx-clients-site-name').fill(siteName);
  await form.getByTestId('bwx-clients-site-save').click();

  await expect(form).toBeHidden();
  await expect(siteRow(page, siteName)).toBeVisible();
});

test('our contact for a client is named, and the list says who', async ({ page }) => {
  await expect(clientRow(page, seeded.display_name)).toContainText('Nobody');

  await selectSeeded(page);
  await page.getByTestId('bwx-clients-contact').click();

  const form = page.getByTestId('bwx-clients-contact-form');
  await expect(form).toBeVisible();
  await form.getByTestId('bwx-clients-contact-pick').selectOption(person.id);
  await form.getByTestId('bwx-clients-contact-save').click();

  await expect(form).toBeHidden();
  await expect(clientRow(page, seeded.display_name)).toContainText(person.user.display_name);
  await expect(page.getByTestId('bwx-clients-selected')).toContainText(person.user.display_name);
});

test('a key is issued once, copied, and never shown again', async ({ page }) => {
  await selectSeeded(page);

  await siteRow(page, siteName).getByTestId('bwx-clients-site-key').click();
  const panel = page.getByTestId('bwx-clients-key-form');
  await expect(panel).toBeVisible();
  await expect(panel.getByTestId('bwx-clients-key-value')).toHaveCount(0);

  page.once('dialog', (dialog) => dialog.accept());
  await panel.getByTestId('bwx-clients-key-issue').click();

  const value = panel.getByTestId('bwx-clients-key-value');
  await expect(value).toBeVisible();
  await expect(value).not.toHaveValue('');
  await expect(value).toHaveAttribute('readonly', '');

  await panel.getByTestId('bwx-clients-key-copy').click();
  await expect(panel.getByTestId('bwx-clients-key-copy')).toHaveText('Copied');

  await panel.getByTestId('bwx-clients-key-close').click();
  await expect(panel).toBeHidden();
  await expect(siteRow(page, siteName)).toContainText('Key issued, not yet connected');

  // Reopening shows the state, not the key: it was in one answer and nowhere else.
  await siteRow(page, siteName).getByTestId('bwx-clients-site-key').click();
  await expect(panel).toBeVisible();
  await expect(panel.getByTestId('bwx-clients-key-value')).toHaveCount(0);
  await expect(panel.getByTestId('bwx-clients-key-revoke')).toBeVisible();
});

test('revoking a key cuts the site off', async ({ page }) => {
  await selectSeeded(page);

  await siteRow(page, siteName).getByTestId('bwx-clients-site-key').click();
  const panel = page.getByTestId('bwx-clients-key-form');

  page.once('dialog', (dialog) => {
    expect(dialog.message()).toContain('Cut this site off');
    dialog.accept();
  });
  await panel.getByTestId('bwx-clients-key-revoke').click();

  await expect(panel.getByTestId('bwx-clients-key-revoke')).toHaveCount(0);
  await panel.getByTestId('bwx-clients-key-close').click();
  await expect(siteRow(page, siteName)).toContainText('Cut off');
});

test('onboarding is started from the site row, and once only', async ({ page }) => {
  await publishAChecklist(page);

  await page.goto('/blueworx-forge/#screen=clients');
  await expect(page.getByTestId('bwx-clients-count')).toBeVisible({ timeout: 30_000 });
  await selectSeeded(page);

  const row = siteRow(page, siteName);
  await expect(row).toContainText('Not started');

  page.once('dialog', (dialog) => {
    expect(dialog.message()).toContain('Start onboarding');
    dialog.accept();
  });
  await row.getByTestId('bwx-clients-site-onboard').click();

  await expect(row).toContainText('In progress');
  await expect(row.getByTestId('bwx-clients-site-onboard')).toBeDisabled();
});

test('a deactivated site stays in the list when everyone is shown', async ({ page }) => {
  await selectSeeded(page);

  page.once('dialog', (dialog) => dialog.accept());
  await siteRow(page, siteName).getByTestId('bwx-clients-site-deactivate').click();

  await expect(siteRow(page, siteName)).toHaveCount(0);

  await page.getByTestId('bwx-clients-show-all').click();
  await expect(siteRow(page, siteName)).toContainText('Deactivated');
});

test('the studio is marked, cannot be deactivated, and is renamed by name only', async ({ page }) => {
  const list = page.getByTestId('bwx-clients-list');
  const studio = list.locator('tbody tr', { has: page.getByText('Studio', { exact: true }) }).first();
  await expect(studio).toBeVisible();

  await studio.click();
  const selected = page.getByTestId('bwx-clients-selected');
  await expect(selected.getByTestId('bwx-clients-deactivate')).toHaveCount(0);

  await selected.getByTestId('bwx-clients-edit').click();
  const form = page.getByTestId('bwx-clients-form');
  await expect(form.getByTestId('bwx-clients-form-status')).toHaveCount(0);
  await expect(form.getByTestId('bwx-clients-form-timezone')).toHaveCount(0);

  const before = await form.getByTestId('bwx-clients-form-name').inputValue();
  const renamed = `${before} ${RUN_ID}`;
  await form.getByTestId('bwx-clients-form-name').fill(renamed);
  await form.getByTestId('bwx-clients-form-save').click();

  await expect(form).toBeHidden();
  await expect(clientRow(page, renamed)).toBeVisible();

  // And back, so the instance reads as it did for every spec after this one.
  await selected.getByTestId('bwx-clients-edit').click();
  await form.getByTestId('bwx-clients-form-name').fill(before);
  await form.getByTestId('bwx-clients-form-save').click();
  await expect(form).toBeHidden();
  await expect(clientRow(page, renamed)).toHaveCount(0);
});
