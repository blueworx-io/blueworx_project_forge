import fs from 'node:fs';
import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #669. The Support screen's Export CSV: the whole ledger as a statement, and
// the same statement narrowed to one release.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const BOM = String.fromCharCode(0xfeff);
const HEADER = 'Date,What,Task,Milestone,Project,Note,Hours,Balance after';

const round = (n) => Math.round(n * 100) / 100;
const slug = (...parts) => parts.join(' ').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

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

const plannedHours = (item) => round(Number(item.hours_primary) + Number(item.hours_review) + Number(item.hours_delivery));

/** Opens the dialog, optionally picks a scope, and returns the downloaded file. */
async function downloadStatement(page, scope) {
  await page.getByTestId('bwx-support-export').click();
  await expect(page.getByTestId('bwx-export-download')).toBeEnabled({ timeout: 30_000 });
  if (scope) {
    await page.getByTestId('bwx-export-scope').selectOption(scope);
  }
  const from = await page.getByTestId('bwx-export-from').inputValue();
  const to = await page.getByTestId('bwx-export-to').inputValue();

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('bwx-export-download').click()]);
  const text = fs.readFileSync(await download.path(), 'utf8');

  return { text, name: download.suggestedFilename(), from, to, lines: text.slice(1).split('\r\n').filter((l) => '' !== l).map((l) => l.split(',')) };
}

test('the hours export downloads the whole ledger and a single release', async ({ browser, baseURL }) => {
  test.setTimeout(300_000);

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, 'Export Co', RUN_ID);
  await Forge.onSupport(admin, site.id, 20);
  const crew = await Forge.team(admin.api, browser, baseURL, client.id);

  const project = (await (await Forge.makeItem(admin.api, site.id, { title: `Export Project ${RUN_ID}`, level: 'project' })).json()).item;
  const milestone = (
    await (await Forge.makeItem(admin.api, site.id, { title: `Export Release ${RUN_ID}`, level: 'milestone', parent_id: project.id })).json()
  ).item;
  const insideTitle = `Export Feature ${RUN_ID}`;
  const looseTitle = `Export Loose ${RUN_ID}`;
  const inside = await startedTask(admin, crew, site.id, insideTitle, 2, milestone.id);
  const loose = await startedTask(admin, crew, site.id, looseTitle, 1, '');
  const insideHours = plannedHours(inside);
  const looseHours = plannedHours(loose);
  const ledger = await Forge.hourLedger(admin, site.id);

  const page = await admin.context.newPage();
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-support').click();
  await expect(page.getByTestId('bwx-support')).toBeVisible({ timeout: 30_000 });
  await page.getByTestId('bwx-client-choice').selectOption(site.id);
  await expect(page.getByTestId('bwx-support-state')).toBeVisible({ timeout: 30_000 });

  // Everything: balance brought forward, each task, balance carried forward.
  const all = await downloadStatement(page, '');
  expect(all.text.startsWith(BOM)).toBe(true);
  expect(all.lines[0].join(',')).toBe(HEADER);
  expect(all.name).toBe(`${slug(client.display_name, site.name, 'hours', all.from, 'to', all.to)}.csv`);

  const done = (title) => all.lines.filter((l) => 'Work done' === l[1] && title === l[2]);
  expect(done(insideTitle)).toHaveLength(1);
  expect(done(looseTitle)).toHaveLength(1);
  expect(done(insideTitle)[0][6]).toBe((-insideHours).toFixed(2));
  expect(done(looseTitle)[0][6]).toBe((-looseHours).toFixed(2));
  expect(done(insideTitle)[0][3]).toBe(milestone.title);
  expect(done(insideTitle)[0][4]).toBe(project.title);

  const used = round(insideHours + looseHours);
  const row = (label) => all.lines.find((l) => label === l[1]);
  expect(row('Balance brought forward')).toBeTruthy();
  const carried = row('Balance carried forward');
  expect(carried[7]).toBe(round(20 - used).toFixed(2));
  const held = row('Held for work in progress');
  const heldHours = held ? Number(held[6]) : 0;
  expect(round(Number(carried[7]) + heldHours)).toBe(ledger.balance);

  // One release: the loose task is gone and the last line totals what the release used.
  const scoped = await downloadStatement(page, milestone.id);
  expect(scoped.lines[0].join(',')).toBe(HEADER);
  expect(scoped.text).toContain(insideTitle);
  expect(scoped.text).not.toContain(looseTitle);
  const last = scoped.lines[scoped.lines.length - 1];
  expect(last[1]).toBe('Total');
  expect(last[6]).toBe(insideHours.toFixed(2));
  expect(scoped.name).toBe(`${slug(client.display_name, site.name, 'hours', scoped.from, 'to', scoped.to, milestone.title)}.csv`);

  await page.close();
  await crew.close();
  await admin.context.close();
});
