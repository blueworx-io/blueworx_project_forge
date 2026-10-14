import { test, expect as baseExpect } from '@playwright/test';

// This instance answers a write in seconds, so every check gets longer than the default.
const expect = baseExpect.configure({ timeout: 30_000 });
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makePerson } from './helpers/forge.js';

// #492. Leads: a business we are talking to, kept under Clients, moving
// Lead → Opportunity → Client or into the archive, and never showing up
// anywhere a client does.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

test.describe.configure({ mode: 'serial' });

let admin;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
});

test.afterAll(async () => {
  await admin?.context.close();
});

const leadRow = (page, name) => page.getByTestId('bwx-leads-list').locator('tr', { hasText: name });

async function openLeads(page) {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-leads').click();
  await expect(page.getByTestId('bwx-leads')).toBeVisible({ timeout: 30_000 });
}

test('a lead is added from the form, listed as a lead, and is no client', async ({ page }) => {
  await openLeads(page);
  await expect(page.locator('h1')).toHaveText('Leads');

  const name = `Prospect ${RUN_ID}`;
  await page.getByTestId('bwx-leads-add').click();
  const form = page.getByTestId('bwx-leads-form');
  await expect(form).toBeVisible();
  await form.getByTestId('bwx-leads-form-name').fill(name);
  await form.getByTestId('bwx-leads-form-save').click();
  await expect(form).toBeHidden({ timeout: 30_000 });

  await expect(leadRow(page, name)).toBeVisible();
  await expect(leadRow(page, name)).toContainText('Lead');

  // Not a client, anywhere.
  const clients = await admin.api.get('/clients?status=all');
  expect(clients.clients.some((client) => client.display_name === name)).toBe(false);
  const sites = await admin.api.get('/client-sites');
  expect(JSON.stringify(sites)).not.toContain(name);
});

test('a lead with no name is refused, with the reason', async ({ page }) => {
  await openLeads(page);
  await page.getByTestId('bwx-leads-add').click();
  const form = page.getByTestId('bwx-leads-form');
  await form.getByTestId('bwx-leads-form-save').click();
  await expect(form.getByTestId('bwx-leads-form-notice')).toContainText('needs a name');
  await form.getByTestId('bwx-leads-form-cancel').click();
  await expect(form).toBeHidden({ timeout: 30_000 });
});

test('a lead can be given a contact, becomes an opportunity, then a client with everything kept', async ({ page }) => {
  const name = `Signing ${RUN_ID}`;
  const made = await admin.api.post('/leads', { display_name: name, timezone: 'Europe/Paris', legal_name: `Signing Ltd ${RUN_ID}` });
  expect(made.status(), await made.text()).toBe(200);
  const lead = (await made.json()).lead;
  expect(lead.status).toBe('lead');

  // A contact, before they are a client.
  const sites = await admin.api.get('/client-sites');
  const studio = sites.sites.find((one) => one.studio);
  const person = await makePerson(admin.api, studio.client_id, 'staff', `leadcontact${RUN_ID.replace('-', '')}`);
  const named = await admin.api.put(`/clients/${lead.id}/contact`, { user_id: person.id });
  expect(named.status(), await named.text()).toBe(200);

  await openLeads(page);
  await leadRow(page, name).click();
  const selected = page.getByTestId('bwx-leads-selected');
  await expect(selected).toHaveAttribute('data-lead', lead.id);
  await expect(selected.getByTestId('bwx-leads-selected-contact')).toContainText(`leadcontact`);

  // Only an opportunity can be made a client.
  await expect(selected.getByTestId('bwx-leads-convert')).toHaveCount(0);
  const early = await admin.api.post(`/leads/${lead.id}/convert`, { record_version: lead.record_version });
  expect(early.status()).toBe(409);

  await selected.getByTestId('bwx-leads-forward').click();
  await expect(leadRow(page, name)).toContainText('Opportunity', { timeout: 30_000 });
  await selected.getByTestId('bwx-leads-convert').click();
  await expect(page.getByTestId('bwx-leads-notice')).toContainText('now a client', { timeout: 30_000 });
  await expect(leadRow(page, name)).toHaveCount(0, { timeout: 30_000 });

  // On Clients, with the same details, and all staff on it.
  const clients = await admin.api.get('/clients?status=all');
  const client = clients.clients.find((one) => one.display_name === name);
  expect(client).toBeTruthy();
  expect(client.status).toBe('active');
  expect(client.timezone).toBe('Europe/Paris');
  expect(client.legal_name).toBe(`Signing Ltd ${RUN_ID}`);
  expect(client.staff_all).toBe(true);

  // And the lead routes no longer know it, so a client cannot be moved from here.
  const refused = await admin.api.patch(`/leads/${lead.id}`, { status: 'lead-archived', record_version: client.record_version });
  expect(refused.status()).toBe(404);
});

test('a lead that does not sign is archived, and can be brought back', async ({ page }) => {
  const name = `Passing ${RUN_ID}`;
  const made = await admin.api.post('/leads', { display_name: name, timezone: 'Europe/London' });
  expect(made.status(), await made.text()).toBe(200);

  await openLeads(page);
  await leadRow(page, name).click();
  await page.getByTestId('bwx-leads-selected').getByTestId('bwx-leads-archive').click();
  await expect(leadRow(page, name)).toHaveCount(0, { timeout: 30_000 });

  await page.getByTestId('bwx-leads-show-archive').click();
  await expect(leadRow(page, name)).toBeVisible();
  await expect(leadRow(page, name)).toContainText('Archived');

  // Still not a client while archived.
  const clients = await admin.api.get('/clients?status=all');
  expect(clients.clients.some((client) => client.display_name === name)).toBe(false);

  await leadRow(page, name).click();
  await page.getByTestId('bwx-leads-selected').getByTestId('bwx-leads-restore').click();
  await expect(leadRow(page, name)).toHaveCount(0, { timeout: 30_000 });
  await page.getByTestId('bwx-leads-show-archive').click();
  await expect(leadRow(page, name)).toContainText('Lead');
});

test('a client\'s id is refused by the lead routes', async () => {
  const made = await admin.api.post('/clients', { display_name: `Real client ${RUN_ID}`, timezone: 'Europe/London' });
  expect(made.status(), await made.text()).toBe(200);
  const client = (await made.json()).client;

  const moved = await admin.api.patch(`/leads/${client.id}`, { status: 'lead', record_version: client.record_version });
  expect(moved.status()).toBe(404);
  const converted = await admin.api.post(`/leads/${client.id}/convert`, { record_version: client.record_version });
  expect(converted.status()).toBe(404);
});
