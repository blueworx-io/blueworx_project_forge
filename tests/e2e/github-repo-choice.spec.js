import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';

// #681. Work names the repo it belongs in (or "No repo needed") before it
// leaves Documentation Period. A project or a release is never asked.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

let admin;
let site;

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  ({ site } = await Forge.makeSite(admin.api, 'Repo Co', RUN_ID));
});

test.afterAll(async () => {
  await admin?.context.close();
});

test('a feature cannot reach Up Next without a repo answer', async () => {
  test.slow();

  const made = (await (await Forge.makeItem(admin.api, site.id, { title: `Repo ${RUN_ID}`, level: 'feature' })).json()).item;
  const item = await Forge.walkTo(admin.api, made, ['triage', 'documentation-period']);

  const approved = await admin.api.post(`/work-items/${item.id}/gate`, { requirement: 'G-DOCUMENTATION-9', value: 'approved', evidence: '' });
  expect(approved.status(), await approved.text()).toBe(200);

  const before = (await admin.api.get(`/work-items/${item.id}`)).item;
  const refused = await admin.api.post(`/work-items/${item.id}/transition`, { to: 'up-next', record_version: before.record_version });

  expect(refused.status(), await refused.text()).toBe(409);
  const unmet = (await refused.json()).unmet;
  expect(unmet.map((row) => row.id)).toEqual(['G-DOCUMENTATION-10']);
  expect(unmet[0].label).toBe('Repo');

  const filed = await admin.api.patch(`/work-items/${item.id}`, { github_repo: 'none', record_version: before.record_version });
  expect(filed.status(), await filed.text()).toBe(200);

  const after = (await admin.api.get(`/work-items/${item.id}`)).item;
  const moved = await admin.api.post(`/work-items/${item.id}/transition`, { to: 'up-next', record_version: after.record_version });

  expect(moved.status(), await moved.text()).toBe(200);
  expect((await moved.json()).item.stage).toBe('up-next');
});

test('a release is never asked', async () => {
  const project = (await (await Forge.makeItem(admin.api, site.id, { title: `Project ${RUN_ID}`, level: 'project' })).json()).item;
  const release = (await (await Forge.makeItem(admin.api, site.id, { title: `Release ${RUN_ID}`, level: 'milestone', parent_id: project.id })).json()).item;

  const detail = await admin.api.get(`/work-items/${release.id}`);
  const rows = Object.values(detail.readiness).flatMap((gate) => gate.all.map((row) => row.id));

  expect(rows).not.toContain('G-DOCUMENTATION-10');
});

test('a client cannot add work with a repo chosen', async ({ browser, baseURL }) => {
  const { client, site: theirSite } = await Forge.makeSite(admin.api, 'Client Repo Co', RUN_ID);
  await Forge.onSupport(admin, theirSite.id, 200);

  const person = await Forge.makePerson(admin.api, client.id, 'client_admin', 'repoclient');
  const theirs = await browser.newContext({ baseURL });
  const page = await theirs.newPage();

  await signIn(page, person.login, Forge.PASSWORD);
  const nonce = await (await theirs.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text();
  await page.close();

  const made = await theirs.request.post('/wp-json/blueworx-forge/v1/work-items', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      client_site_id: theirSite.id,
      title: `Client repo ${RUN_ID}`,
      problem: 'Something needs doing.',
      level: 'feature',
      work_type: 'feature',
      github_repo: 'none',
    },
  });

  // Nothing sets the active-package condition yet, so a client is refused at the door. If that
  // changes, the repo they sent must still not be stored.
  if (200 !== made.status()) {
    expect(made.status(), await made.text()).toBe(403);
    await theirs.close();
    return;
  }


  const stored = (await admin.api.get(`/work-items/${(await made.json()).item.id}`)).item;
  expect(stored.github_repo).toBe('');

  await theirs.close();
});
