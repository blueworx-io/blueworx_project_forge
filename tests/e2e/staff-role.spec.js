import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import * as Forge from './helpers/forge.js';

// #474: each person has a Role. The administrator sets it on the People
// screen, the person reads their own on their profile, and the hours are a
// note that changes nothing else.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

test.describe.configure({ mode: 'serial' });

let admin;
let person;
let other;
let asPerson;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client } = await Forge.makeSite(admin.api, 'Role Co', RUN_ID);
  person = await Forge.makePerson(admin.api, client.id, 'staff', 'Roleplayer');
  other = await Forge.makePerson(admin.api, client.id, 'staff', 'Bystander');
  asPerson = await Forge.signedIn(browser, baseURL, person.login, Forge.PASSWORD);
});

test.afterAll(async () => {
  await asPerson?.context.close();
  await admin?.context.close();
});

test('an administrator sets a role, and it is there when the person is reopened', async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-people').click();
  await expect(page.getByTestId('bwx-people-count')).toBeVisible({ timeout: 30_000 });

  const card = page.locator(`[data-testid="bwx-people-card"][data-person="${person.id}"]`);
  await card.getByTestId('bwx-people-role').click();

  await page.getByTestId('bwx-people-role-title').fill('Lead developer');
  await page.getByTestId('bwx-people-role-hours').fill('30');
  await page.getByTestId('bwx-people-role-starts').fill('2026-01-05');
  await page.getByTestId('bwx-people-role-description').fill('Builds and ships client sites.');
  await page.getByTestId('bwx-people-role-duty-add').click();
  await page.getByTestId('bwx-people-role-duty').nth(0).fill('Weekly release');
  await page.getByTestId('bwx-people-role-duty-add').click();
  await page.getByTestId('bwx-people-role-duty').nth(1).fill('Code review');
  await page.getByTestId('bwx-people-role-duty-remove').nth(1).click();
  await page.getByTestId('bwx-people-role-save').click();
  await expect(page.getByTestId('bwx-people-role-form')).toBeHidden();

  await card.getByTestId('bwx-people-role').click();
  await expect(page.getByTestId('bwx-people-role-title')).toHaveValue('Lead developer');
  await expect(page.getByTestId('bwx-people-role-hours')).toHaveValue('30');
  await expect(page.getByTestId('bwx-people-role-starts')).toHaveValue('2026-01-05');
  await expect(page.getByTestId('bwx-people-role-ends')).toHaveValue('');
  await expect(page.getByTestId('bwx-people-role-duty')).toHaveCount(1);
  await expect(page.getByTestId('bwx-people-role-duty')).toHaveValue('Weekly release');
});

test('an end before the start, and negative hours, are refused', async () => {
  const bad = await admin.api.request.put(`/wp-json/blueworx-forge/v1/users/${person.id}/role`, {
    headers: admin.api.headers,
    data: { starts_on: '2026-03-01', ends_on: '2026-02-01' },
  });
  expect(bad.status()).toBe(400);
  expect((await bad.json()).data.fields.ends_on).toBeTruthy();

  const negative = await admin.api.request.put(`/wp-json/blueworx-forge/v1/users/${person.id}/role`, {
    headers: admin.api.headers,
    data: { weekly_hours: '-4' },
  });
  expect(negative.status()).toBe(400);
});

test('the person reads their own role, and cannot change it or read anyone else\'s', async () => {
  const page = await asPerson.context.newPage();

  await page.goto('/blueworx-forge/#screen=profile');
  await expect(page.getByTestId('bwx-profile-screen')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-role-title')).toHaveText('Lead developer');
  await expect(page.getByTestId('bwx-role-duties')).toContainText('Weekly release');

  const write = await asPerson.api.request.put(`/wp-json/blueworx-forge/v1/users/${person.id}/role`, {
    headers: asPerson.api.headers,
    data: { title: 'Boss' },
  });
  expect(write.ok()).toBe(false);

  const peek = await asPerson.api.request.get(`/wp-json/blueworx-forge/v1/users/${other.id}/role`, { headers: asPerson.api.headers });
  expect(peek.ok()).toBe(false);

  await page.close();
});
