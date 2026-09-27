import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import * as Forge from './helpers/forge.js';

// #405: each client's Edit screen sets which staff work on it — All staff, or
// chosen people. Chosen staff are the same memberships the People page edits,
// so a change on either screen shows on the other. The seat pickers offer only
// that client's staff. The Cross-client grant is gone.
//
// The instance is reused between runs, so every name carries a run id, and
// every client made here is closed at the end: an All staff client left
// behind would open itself to every later staff test.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

test.describe.configure({ mode: 'serial' });

let admin;
let mine;
let other;
let insider;
let outsider;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  mine = await Forge.makeSite(admin.api, `Staffed Co ${RUN_ID}`, RUN_ID);
  other = await Forge.makeSite(admin.api, `Elsewhere Co ${RUN_ID}`, RUN_ID);
  insider = await Forge.makePerson(admin.api, mine.client.id, 'staff', `Insider-${RUN_ID}`);
  outsider = await Forge.makePerson(admin.api, other.client.id, 'staff', `Outsider-${RUN_ID}`);
});

test.afterAll(async () => {
  for (const one of [mine, other]) {
    if (!one) {
      continue;
    }

    const client = (await admin.api.get(`/clients/${one.client.id}`)).client;
    await admin.api.patch(`/clients/${one.client.id}`, { status: 'inactive', staff_all: false, record_version: client.record_version });
  }

  await admin?.context.close();
});

test.beforeEach(() => {
  test.slow();
});

/** Who the seat pickers offer on the staffed client's site. */
async function offered() {
  return (await admin.api.get(`/people?client_site_id=${mine.site.id}`)).people.map((one) => one.id);
}

/** A person's active membership on the staffed client, or undefined. */
async function heldOnMine(userId) {
  return (await admin.api.get(`/users/${userId}`)).memberships.find(
    (one) => one.client_id === mine.client.id && 'active' === one.status
  );
}

/** A screen, from the rail. */
async function openScreen(page, screen) {
  await page.goto('/blueworx-forge/');
  await page.getByTestId(`bwx-screen-${screen}`).click();
  await expect(page.getByTestId(`bwx-${screen}`)).toBeVisible({ timeout: 30_000 });
}

/** Opens the staffed client's Edit form on the Clients screen. */
async function editMine(page) {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'clients');
  await page.getByTestId('bwx-clients-list').locator('tbody tr', { hasText: mine.client.display_name }).click();
  await expect(page.getByTestId('bwx-clients-selected')).toHaveAttribute('data-client', mine.client.id);
  await page.getByTestId('bwx-clients-edit').click();

  const form = page.getByTestId('bwx-clients-form');
  await expect(form).toBeVisible();

  return form;
}

test('with chosen staff, the pickers offer only them and a save with anybody else is refused', async () => {
  const staff = await admin.api.get(`/clients/${mine.client.id}/staff`);
  expect(staff.staff_all).toBe(false);
  expect(staff.chosen).toEqual([insider.id]);

  const list = await offered();
  expect(list).toContain(insider.id);
  expect(list).not.toContain(outsider.id);

  const refused = await Forge.makeItem(admin.api, mine.site.id, { title: `Refused ${RUN_ID}`, primary_user_id: outsider.id });
  expect(refused.status(), await refused.text()).toBe(400);
  expect((await refused.json()).data.fields.primary_user_id).toBe(`Outsider-${RUN_ID} doesn't have access to this client.`);
});

test('choosing staff on the Edit screen shows on People', async ({ page }) => {
  const form = await editMine(page);

  await expect(form.getByTestId('bwx-clients-form-staff-chosen')).toBeChecked();
  const people = form.getByTestId('bwx-clients-form-staff-people');
  await expect(people.getByTestId(`bwx-clients-form-staff-person-${insider.id}`)).toBeChecked();
  await people.getByTestId(`bwx-clients-form-staff-person-${outsider.id}`).check();
  await form.getByTestId('bwx-clients-form-save').click();
  await expect(form).toBeHidden();

  expect((await heldOnMine(outsider.id))?.role).toBe('staff');
  expect(await offered()).toContain(outsider.id);

  // And People shows it on their card.
  await openScreen(page, 'people');
  const card = page.locator(`[data-testid="bwx-people-card"][data-person="${outsider.id}"]`);
  await expect(card).toContainText(mine.client.display_name);
});

test('ending access on People shows on the Edit screen', async ({ page }) => {
  page.on('dialog', (dialog) => dialog.accept());

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'people');
  const row = page
    .locator(`[data-testid="bwx-people-card"][data-person="${outsider.id}"]`)
    .locator('tbody tr', { hasText: mine.client.display_name });
  await row.getByTestId('bwx-people-membership-end').click();
  await expect.poll(async () => heldOnMine(outsider.id)).toBeUndefined();

  const form = await editMine(page);
  const people = form.getByTestId('bwx-clients-form-staff-people');
  await expect(people.getByTestId(`bwx-clients-form-staff-person-${outsider.id}`)).not.toBeChecked();
  await expect(people.getByTestId(`bwx-clients-form-staff-person-${insider.id}`)).toBeChecked();
});

test('All staff offers every staff person, including one added afterwards, and People shows it', async ({ page }) => {
  const form = await editMine(page);

  await form.getByTestId('bwx-clients-form-staff-all').check();
  await expect(form.getByTestId('bwx-clients-form-staff-people')).toHaveCount(0);
  await form.getByTestId('bwx-clients-form-save').click();
  await expect(form).toBeHidden();
  await expect(page.getByTestId('bwx-clients-selected-staff')).toHaveText('All staff');

  expect(await offered()).toContain(outsider.id);

  // Somebody added to the team after the client was set: staff somewhere else.
  const latecomer = await Forge.makePerson(admin.api, other.client.id, 'staff', `Latecomer-${RUN_ID}`);

  expect(await offered()).toContain(latecomer.id);

  // Somebody with no access yet is not staff, and reaches nothing.
  const added = await admin.api.post('/users', { email: `nobody${RUN_ID.replace('-', '')}@example.test`, display_name: `Nobody-${RUN_ID}` });
  expect(added.status(), await added.text()).toBe(200);
  const nobody = (await added.json()).user;
  expect(await offered()).not.toContain(nobody.id);
  expect((await admin.api.get(`/users/${nobody.id}`)).memberships).toEqual([]);

  const seated = await Forge.makeItem(admin.api, mine.site.id, { title: `All staff ${RUN_ID}`, primary_user_id: latecomer.id });
  expect(seated.status(), await seated.text()).toBe(200);

  // A client's own person is still not offered.
  const clientSide = await Forge.makePerson(admin.api, other.client.id, 'client_admin', `ClientSide-${RUN_ID}`);
  expect(await offered()).not.toContain(clientSide.id);

  // People shows the client on them as All staff, with nothing to end.
  const row = (await admin.api.get(`/users/${latecomer.id}`)).memberships.find((one) => one.client_id === mine.client.id);
  expect(row.all_staff).toBe(true);

  await openScreen(page, 'people');
  const shown = page
    .locator(`[data-testid="bwx-people-card"][data-person="${latecomer.id}"]`)
    .locator('tbody tr', { hasText: mine.client.display_name });
  await expect(shown).toContainText('All staff');
  await expect(shown.getByTestId('bwx-people-membership-end')).toHaveCount(0);
});

test('only an administrator can change who works on a client', async ({ browser, baseURL }) => {
  const staff = await Forge.signedIn(browser, baseURL, insider.login, Forge.PASSWORD);
  const client = (await admin.api.get(`/clients/${mine.client.id}`)).client;

  const chosen = await staff.api.put(`/clients/${mine.client.id}/staff`, { user_ids: [insider.id] });
  expect(chosen.status(), await chosen.text()).toBe(403);

  const flipped = await staff.api.patch(`/clients/${mine.client.id}`, { staff_all: false, record_version: client.record_version });
  expect(flipped.status(), await flipped.text()).toBe(403);

  await staff.context.close();

  // And only our own people can be chosen: not a client's person, nor one
  // whose client access has ended.
  const clientSide = await Forge.makePerson(admin.api, mine.client.id, 'client_admin', `Picked-${RUN_ID}`);
  const refused = await admin.api.put(`/clients/${mine.client.id}/staff`, { user_ids: [clientSide.id] });
  expect(refused.status(), await refused.text()).toBe(400);

  const former = await Forge.makePerson(admin.api, other.client.id, 'client_viewer', `Former-${RUN_ID}`);
  const held = (await admin.api.get(`/users/${former.id}`)).memberships[0];
  const ended = await admin.api.patch(`/memberships/${held.id}`, { status: 'inactive', record_version: held.record_version });
  expect(ended.status(), await ended.text()).toBe(200);

  const pickable = (await admin.api.get(`/clients/${mine.client.id}/staff`)).people.map((one) => one.id);
  expect(pickable).not.toContain(former.id);
  expect(pickable).not.toContain(clientSide.id);
  const formerRefused = await admin.api.put(`/clients/${mine.client.id}/staff`, { user_ids: [former.id] });
  expect(formerRefused.status(), await formerRefused.text()).toBe(400);
});

test('the Cross-client grant is gone from People', async ({ page }) => {
  const grants = await admin.api.get('/grants');
  expect(grants.on_user).toEqual([]);

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await openScreen(page, 'people');
  await page.locator(`[data-testid="bwx-people-card"][data-person="${insider.id}"]`).getByTestId('bwx-people-edit').click();
  const form = page.getByTestId('bwx-people-edit-form');
  await expect(form).toBeVisible();
  await expect(form.getByTestId('bwx-people-edit-grant-cross_client')).toHaveCount(0);
  await expect(form).not.toContainText('Cross-client');
});
