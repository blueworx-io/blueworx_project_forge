import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, makeItem, PASSWORD } from './helpers/forge.js';

// #406. Only administrators and Forge: Managers get into Forge. A Manager is
// one of our people: they see and edit their own clients' work, and none of
// the five administrator screens.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN = `mgr${Date.now()}`;
const BASE = '/wp-json/blueworx-forge/v1';

test.describe.configure({ mode: 'serial' });

let admin;
let mine;
let theirs;
let manager;
let asManager;
let myItem;
let theirItem;

/** The WordPress roles behind a login, as the administrator reads them. */
async function rolesOf(login) {
  const found = await admin.api.request.get(`/wp-json/wp/v2/users?search=${login}&context=edit`, { headers: admin.api.headers });
  const [ account ] = await found.json();

  return account.roles;
}

/** A WordPress account with one role and no Forge person behind it. */
async function account(role) {
  const login = `${role}${RUN}`;
  const made = await admin.api.request.post('/wp-json/wp/v2/users', {
    headers: admin.api.headers,
    data: { username: login, email: `${login}@example.test`, password: PASSWORD, roles: [ role ] },
  });
  expect(made.status(), await made.text()).toBe(201);

  return login;
}

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  mine = await makeSite(admin.api, 'Manager mine', RUN);
  theirs = await makeSite(admin.api, 'Manager theirs', RUN);

  manager = await makePerson(admin.api, mine.client.id, 'staff', `manager${RUN}`);

  myItem = (await (await makeItem(admin.api, mine.site.id, { title: `Mine ${RUN}` })).json()).item;
  theirItem = (await (await makeItem(admin.api, theirs.site.id, { title: `Theirs ${RUN}` })).json()).item;

  asManager = await signedIn(browser, baseURL, manager.login, PASSWORD);
});

test.afterAll(async () => {
  await asManager?.context.close();
  await admin?.context.close();
});

test('a new member of staff is given the Manager role, and keeps their own', async () => {
  const roles = await rolesOf(manager.login);

  expect(roles).toContain('forge_manager');
  expect(roles).toContain('subscriber');
});

test('a Manager opens Forge, without the five administrator screens', async () => {
  const page = await asManager.context.newPage();
  const response = await page.goto('/blueworx-forge/');
  expect(response?.status()).toBe(200);

  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByTestId('bwx-screen-work')).toBeVisible();
  await expect(page.getByTestId('bwx-screen-clients')).toBeVisible();

  for (const hidden of [ 'packages', 'subscriptions', 'reports', 'availability', 'people' ]) {
    await expect(page.getByTestId(`bwx-screen-${hidden}`), `${hidden} is on a Manager's rail`).toHaveCount(0);
  }
  await expect(page.getByTestId('bwx-link-sync')).toHaveCount(0);

  await page.close();
});

test('the five screens are refused on the server too', async () => {
  const other = await makePerson(admin.api, mine.client.id, 'staff', `colleague${RUN}`);

  for (const path of [ '/packages', '/subscriptions', '/reports', '/users', `/users/${other.id}/availability` ]) {
    const answer = await asManager.api.request.get(`${BASE}${path}`, { headers: asManager.api.headers });
    expect(answer.status(), `${path} answered a Manager`).toBe(403);
  }

  // Changing somebody else's hours is still the administrator's.
  const wrote = await asManager.api.post(`/users/${other.id}/leave`, { starts_on: '2020-03-02', ends_on: '2020-03-02', kind: 'leave' });
  expect(wrote.status()).toBe(403);
});

test('a Manager edits a task on their client, and cannot reach another client\'s', async () => {
  const edited = await asManager.api.patch(`/work-items/${myItem.id}`, {
    title: `Mine, edited ${RUN}`,
    record_version: myItem.record_version,
  });
  expect(edited.status(), await edited.text()).toBe(200);
  expect((await edited.json()).item.title).toBe(`Mine, edited ${RUN}`);

  const read = await asManager.api.request.get(`${BASE}/work-items/${theirItem.id}`, { headers: asManager.api.headers });
  expect(read.status()).toBe(404);

  const wrote = await asManager.api.patch(`/work-items/${theirItem.id}`, {
    title: 'Not mine',
    record_version: theirItem.record_version,
  });
  expect(wrote.status()).toBe(404);
});

test('a Manager\'s lists and picker hold their own clients only', async () => {
  const sites = await asManager.api.get('/client-sites');
  const ids = sites.sites.map((site) => site.id);
  expect(ids).toContain(mine.site.id);
  expect(ids).not.toContain(theirs.site.id);

  const clients = await asManager.api.get('/clients?status=all');
  const names = clients.clients.map((client) => client.id);
  expect(names).toContain(mine.client.id);
  expect(names).not.toContain(theirs.client.id);

  // The Clients screen reads their client's sites, and not another's.
  const own = await asManager.api.request.get(`${BASE}/clients/${mine.client.id}/sites`, { headers: asManager.api.headers });
  expect(own.status()).toBe(200);
  const other = await asManager.api.request.get(`${BASE}/clients/${theirs.client.id}/sites`, { headers: asManager.api.headers });
  expect(other.status()).toBe(404);

  // Client settings stay the administrator's.
  const renamed = await asManager.api.patch(`/clients/${mine.client.id}`, { display_name: 'Renamed', record_version: 1 });
  expect(renamed.status()).toBe(403);

  // Meetings and support: read on their site, not on another's, write nowhere.
  for (const path of [ 'meetings', 'support' ]) {
    const ours = await asManager.api.request.get(`${BASE}/client-sites/${mine.site.id}/${path}`, { headers: asManager.api.headers });
    expect(ours.status(), `${path} on their own site`).toBe(200);

    const not = await asManager.api.request.get(`${BASE}/client-sites/${theirs.site.id}/${path}`, { headers: asManager.api.headers });
    expect(not.status(), `${path} on another client's site`).toBe(404);
  }

  const added = await asManager.api.post(`/client-sites/${mine.site.id}/meetings/series`, { title: 'No' });
  expect(added.status()).toBe(403);
});

test('a Manager\'s capacity is their own row', async () => {
  const today = new Date().toISOString().slice(0, 10);
  const answer = await asManager.api.get(`/capacity?from=${today}&to=${today}&by=days`);

  expect(answer.only_me).toBe(true);
  expect(answer.people.map((person) => person.user_id)).toEqual([ manager.id ]);

  const colleague = await admin.api.get(`/capacity?from=${today}&to=${today}&by=days`);
  const someoneElse = colleague.people.find((person) => person.user_id !== manager.id);
  const drill = await asManager.api.request.get(`${BASE}/capacity/person/${someoneElse.user_id}?from=${today}&to=${today}`, { headers: asManager.api.headers });
  expect(drill.status()).toBe(404);
});

test('a Manager sets their own hours and time off', async () => {
  const hours = await asManager.api.post(`/users/${manager.id}/availability/hours`, {
    effective_from: '2020-01-01',
    hours_sun: 0, hours_mon: 8, hours_tue: 8, hours_wed: 8, hours_thu: 8, hours_fri: 8, hours_sat: 0,
  });
  expect(hours.status(), await hours.text()).toBe(200);

  const leave = await asManager.api.post(`/users/${manager.id}/leave`, { starts_on: '2020-02-03', ends_on: '2020-02-03', kind: 'leave' });
  expect(leave.status(), await leave.text()).toBe(200);

  const mineRead = await asManager.api.request.get(`${BASE}/users/${manager.id}/availability`, { headers: asManager.api.headers });
  expect(mineRead.status()).toBe(200);
});

test('a Subscriber, an Editor and a client\'s person are kept out', async ({ browser, baseURL }) => {
  const client = await makePerson(admin.api, mine.client.id, 'client_admin', `clientside${RUN}`);
  const logins = [ await account('subscriber'), await account('editor'), client.login ];

  for (const login of logins) {
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();
    await signIn(page, login, PASSWORD);

    const response = await page.goto('/blueworx-forge/');
    expect(response?.status(), `${login} opened Forge`).toBe(403);
    await expect(page.getByTestId('bwx-forge-ready')).toHaveCount(0);

    const nonce = await (await context.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text();
    for (const path of [ '/work-items-all', '/client-sites', '/me' ]) {
      const answer = await context.request.get(`${BASE}${path}`, { headers: { 'X-WP-Nonce': nonce } });
      expect(answer.status(), `${login} read ${path}`).toBe(403);
    }

    await context.close();
  }
});

test('offboarding takes the role away, and with it the way in', async ({ browser, baseURL }) => {
  const leaving = await makePerson(admin.api, mine.client.id, 'staff', `leaving${RUN}`);
  expect(await rolesOf(leaving.login)).toContain('forge_manager');

  const offboarded = await admin.api.patch(`/users/${leaving.id}`, { status: 'inactive', record_version: leaving.user.record_version });
  expect(offboarded.status(), await offboarded.text()).toBe(200);

  const roles = await rolesOf(leaving.login);
  expect(roles).not.toContain('forge_manager');
  expect(roles).toContain('subscriber');

  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  await signIn(page, leaving.login, PASSWORD);
  expect((await page.goto('/blueworx-forge/'))?.status()).toBe(403);
  await context.close();
});
