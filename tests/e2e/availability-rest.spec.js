import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson } from './helpers/forge.js';

// PR 1 of the move out of WordPress admin: a person's working week and time
// off over REST. Nothing here is deleted and the instance is reused, so every
// name carries a run id.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');
const BASE = '/wp-json/blueworx-forge/v1';

const WEEK = { hours_sun: 0, hours_mon: 8, hours_tue: 8, hours_wed: 8, hours_thu: 8, hours_fri: 4, hours_sat: 0 };

test.describe.configure({ mode: 'serial' });

let api;
let context;
let person;

test.beforeAll(async ({ browser, baseURL }) => {
  ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
  const where = await makeSite(api, 'Availability REST', RUN_ID);
  person = await makePerson(api, where.client.id, 'staff', `avail${STAMP}`);
});

test.afterAll(async () => {
  await context?.close();
});

test('a person nobody has set up reads as unrecorded, not as no hours', async () => {
  const answer = await api.get(`/users/${person.id}/availability`);

  expect(answer.ok).toBe(true);
  expect(answer.person.id).toBe(person.id);
  expect(answer.recorded).toBe(false);
  expect(answer.current).toBeNull();
  expect(answer.history).toEqual([]);
  expect(answer.leave).toEqual([]);
  expect(answer.week.days).toHaveLength(7);
});

test('an unknown person is a 404, not an empty answer', async () => {
  const response = await api.request.get(`${BASE}/users/usr_nobody${STAMP}/availability`, { headers: api.headers });

  expect(response.status()).toBe(404);
  expect((await response.json()).code).toBe('bwx_forge_unknown_user');
});
