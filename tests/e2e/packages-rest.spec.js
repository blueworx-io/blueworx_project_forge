import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, PASSWORD } from './helpers/forge.js';

// PR 2 of the move out of WordPress admin: the package catalogue over REST.
// Nothing here is deleted and the instance is reused, so every name carries
// a run id and every assertion finds its own package by id.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const BASE = '/wp-json/blueworx-forge/v1';

const TERMS = { name: `Standard ${RUN_ID}`, hours: 12, price: 1200, currency: 'GBP', validity_months: 12, terms: 'Twelve hours a month.' };

test.describe.configure({ mode: 'serial' });

let api;
let context;
let person;

test.beforeAll(async ({ browser, baseURL }) => {
  ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
  const where = await makeSite(api, 'Packages REST', RUN_ID);
  person = await makePerson(api, where.client.id, 'staff', `pkg${STAMP}`);
});

test.afterAll(async () => {
  await context?.close();
});

test('the catalogue reads in its own order, each package with its current version and history', async () => {
  const answer = await api.get('/packages');

  expect(answer.ok).toBe(true);
  expect(Array.isArray(answer.packages)).toBe(true);

  const positions = answer.packages.map((one) => one.position);
  expect(positions).toEqual([...positions].sort((a, b) => a - b));

  for (const one of answer.packages) {
    expect(one.current === null || one.current.package_id === one.id).toBe(true);
    expect(Array.isArray(one.versions)).toBe(true);
  }
});

test('somebody who is not an administrator cannot read the catalogue', async ({ browser, baseURL }) => {
  const other = await signedIn(browser, baseURL, person.login, PASSWORD);

  const read = await other.api.request.get(`${BASE}/packages`, { headers: other.api.headers });
  expect(read.status()).toBe(403);

  await other.context.close();
});
