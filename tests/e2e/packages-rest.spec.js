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

let made;

test('adding a package writes version 1 and puts it at the end of the catalogue', async () => {
  const wrote = await api.post('/packages', TERMS);
  expect(wrote.status(), await wrote.text()).toBe(200);

  const answer = await wrote.json();
  made = answer.package;

  expect(made.id).toMatch(/^pkg_/);
  expect(made.status).toBe('active');
  expect(made.current.version).toBe(1);
  expect(made.current.hours).toBe(12);
  expect(made.current.price).toBe(1200);
  expect(made.versions).toHaveLength(1);

  const last = answer.packages[answer.packages.length - 1];
  expect(last.id).toBe(made.id);
});

test('a package with no name, or no hours, is refused with the reason', async () => {
  const noName = await api.post('/packages', { ...TERMS, name: '' });
  expect(noName.status()).toBe(400);
  const noNameBody = await noName.json();
  expect(noNameBody.code).toBe('bwx_forge_invalid_package');
  expect(noNameBody.message).toContain('needs a name');

  const noHours = await api.post('/packages', { ...TERMS, name: `Empty ${RUN_ID}`, hours: 0 });
  expect(noHours.status()).toBe(400);
  expect((await noHours.json()).message).toContain('some hours');
});

test('a replayed add under one retry key makes one package, not two', async () => {
  const key = `package-${RUN_ID}`;
  const body = { ...TERMS, name: `Replayed ${RUN_ID}` };
  const headers = { ...api.headers, 'Idempotency-Key': key };

  const first = await api.request.post(`${BASE}/packages`, { headers, data: body });
  const again = await api.request.post(`${BASE}/packages`, { headers, data: body });

  expect(first.status()).toBe(200);
  expect(again.status()).toBe(200);
  expect((await again.json()).package.id).toBe((await first.json()).package.id);

  const answer = await api.get('/packages');
  expect(answer.packages.filter((one) => one.name === body.name)).toHaveLength(1);
});

test('somebody who is not an administrator cannot add a package', async ({ browser, baseURL }) => {
  const other = await signedIn(browser, baseURL, person.login, PASSWORD);

  const wrote = await other.api.post('/packages', { ...TERMS, name: `Denied ${RUN_ID}` });
  expect(wrote.status()).toBe(403);

  await other.context.close();
});
