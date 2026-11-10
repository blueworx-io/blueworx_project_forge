import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #669. The hours statement's one read route: the site's ledger over a range,
// holds left out, each task named with the milestone and project above it.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const BASE = '/wp-json/blueworx-forge/v1';
const TODAY = new Date().toISOString().slice(0, 10);

const round = (n) => Math.round(n * 100) / 100;

/** A feature with its hours, walked to In Development so it uses them. */
async function startedTask(admin, crew, siteId, title, hours, parentId) {
  const data = { title };
  if (parentId) {
    data.parent_id = parentId;
  }
  const made = (await (await Forge.makeItem(admin.api, siteId, data)).json()).item;
  const planned = await admin.api.patch(`/work-items/${made.id}`, {
    commercial_class: 'chargeable',
    hours_primary: hours,
    hours_review: 0,
    hours_delivery: 0,
    record_version: made.record_version,
  });
  expect(planned.status(), await planned.text()).toBe(200);

  return Forge.walkTo(
    admin.api,
    (await planned.json()).item,
    ['triage', 'documentation-period', 'up-next', 'in-development'],
    { seats: crew.seats }
  );
}

test('the export reads a range, names each task, scopes to a release and refuses bad input', async ({ browser, baseURL }) => {
  test.setTimeout(300_000);

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'Export Co', RUN_ID);
  await Forge.onSupport(admin, site.id, 20);
  const crew = await Forge.team(admin.api, browser, baseURL, client.id);

  const project = (await (await Forge.makeItem(admin.api, site.id, { title: `Project ${RUN_ID}`, level: 'project' })).json()).item;
  const milestone = (
    await (await Forge.makeItem(admin.api, site.id, { title: `Release ${RUN_ID}`, level: 'milestone', parent_id: project.id })).json()
  ).item;
  const inside = await startedTask(admin, crew, site.id, `Inside ${RUN_ID}`, 2, milestone.id);
  const loose = await startedTask(admin, crew, site.id, `Loose ${RUN_ID}`, 1, '');

  const get = async (query) => admin.api.request.get(`${BASE}/client-sites/${site.id}/support/export?${query}`, { headers: admin.api.headers });
  const range = `from=2020-01-01&to=${TODAY}`;

  const whole = await get(range);
  expect(whole.status(), await whole.text()).toBe(200);
  const body = await whole.json();
  const ledger = await Forge.hourLedger(admin, site.id);

  expect(body.site.id).toBe(site.id);
  expect(body.client.id).toBe(client.id);
  expect(body.opening).toBe(0);
  expect(body.scope).toBeNull();
  expect(round(body.closing + body.held)).toBe(ledger.balance);
  expect(body.closing).toBeLessThanOrEqual(17);
  expect(body.entries.length).toBeGreaterThan(0);
  expect(body.entries.filter((e) => /-(reservation|release)$/.test(e.event_type))).toHaveLength(0);

  const forInside = body.entries.filter((e) => e.source_id === inside.id);
  expect(forInside.length).toBeGreaterThan(0);
  for (const entry of forInside) {
    expect(entry.task.milestone.id).toBe(milestone.id);
    expect(entry.task.project.id).toBe(project.id);
  }
  const forLoose = body.entries.filter((e) => e.source_id === loose.id);
  expect(forLoose.length).toBeGreaterThan(0);
  expect(forLoose[0].task.milestone).toBeNull();
  expect(forLoose[0].task.project).toBeNull();

  expect(body.items.map((i) => i.id).sort()).toEqual([project.id, milestone.id].sort());

  const scoped = await get(`${range}&under=${milestone.id}`);
  expect(scoped.status(), await scoped.text()).toBe(200);
  const narrow = await scoped.json();
  expect(narrow.scope.level).toBe('milestone');
  expect(narrow.scope.id).toBe(milestone.id);
  expect(narrow.entries.length).toBeGreaterThan(0);
  expect(new Set(narrow.entries.map((e) => e.source_id))).toEqual(new Set([inside.id]));
  expect(narrow.closing).toBe(body.closing);

  expect((await get(`${range}&under=${loose.id}`)).status()).toBe(400);

  const other = await Forge.makeSite(admin.api, 'Export Other', RUN_ID);
  const foreign = (await (await Forge.makeItem(admin.api, other.site.id, { title: `Foreign ${RUN_ID}`, level: 'project' })).json()).item;
  expect((await get(`${range}&under=${foreign.id}`)).status()).toBe(400);

  expect((await get(`from=${TODAY}&to=2020-01-01`)).status()).toBe(400);
  expect((await get('from=2000-01-01&to=2026-01-01')).status()).toBe(200);

  await crew.close();
  await admin.context.close();
});
