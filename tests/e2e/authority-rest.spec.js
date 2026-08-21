import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #112, #113, #114 and #115 against a real WordPress. The unit tests prove the
// rules; these prove the routes ask them — and the one that matters most is the
// client lock, which is only a lock if every route has it.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const BASE = '/wp-json/blueworx-forge/v1';
const { PASSWORD } = Forge;


test('no client role moves work, by any route, and the item is untouched', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { context, api } = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(api, 'Locked Co', RUN_ID);

  const person = await Forge.makePerson(api, client.id, 'client_admin', 'clientadmin');

  const item = (
    await (
      await api.post('/work-items', {
        client_site_id: site.id,
        title: `Theirs to watch ${RUN_ID}`,
        problem: 'Something needs doing.',
        level: 'feature',
        work_type: 'feature',
      })
    ).json()
  ).item;

  const before = await api.get(`/work-items/${item.id}`);

  // Now as the client administrator, who may sign in and read their own work
  // and may not move any of it.
  const theirs = await browser.newContext({ baseURL });
  const theirPage = await theirs.newPage();
  await Forge.signInPage(theirPage, person.login, PASSWORD);
  await theirPage.goto('/blueworx-forge/');
  const theirNonce = await theirPage.evaluate(() => window.bwxForgeData?.nonce);
  await theirPage.close();

  const attempts = [
    ['/transition', { to: 'triage' }],
    ['/return', { to: 'future-idea', reason: 'Because.' }],
    ['/block', {
      reason: 'Because.',
      owner: 'Them',
      dependency: 'Us',
      target_date: '2026-09-01',
      next_action: 'Wait.',
    }],
    ['/unblock', { resolution: 'Done.' }],
    ['/outcome', { outcome: 'cancelled', reason: 'Because.' }],
    ['/archive', {}],
    ['/reopen', { to: 'in-development', reason: 'Because.' }],
    ['/override', { to: 'released', reason: 'Because.' }],
    ['/gate', { requirement: 'G-FUTURE-IDEA-2', value: 'Confirmed.' }],
  ];

  for (const [path, body] of attempts) {
    const refused = await theirs.request.post(`${BASE}/work-items/${item.id}${path}`, {
      headers: { 'X-WP-Nonce': theirNonce },
      data: { ...body, record_version: item.record_version },
    });

    expect(refused.status(), `${path} should be refused`).toBe(403);

    const body_ = await refused.json();
    expect(body_.data.denied_by, `${path} should name the lock`).toBe('client_transition_lock');
  }

  // Nothing moved, and nothing was recorded as having moved.
  const after = await api.get(`/work-items/${item.id}`);
  expect(after.item.stage).toBe(before.item.stage);
  expect(after.item.record_version).toBe(before.item.record_version);
  expect(after.history).toHaveLength(before.history.length);

  await theirs.close();
  await context.close();
});

test('only the assigned Reviewer approves, and a substitute is recorded as one', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { context, api } = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(api, 'Reviewed Co', RUN_ID);

  const reviewer = await Forge.makePerson(api, client.id, 'staff', 'reviewer');
  const stranger = await Forge.makePerson(api, client.id, 'staff', 'stranger');

  let item = (
    await (
      await api.post('/work-items', {
        client_site_id: site.id,
        title: `Needs its reviewer ${RUN_ID}`,
        problem: 'Something needs doing.',
        level: 'sub-feature',
        work_type: 'feature',
      })
    ).json()
  ).item;

  item = await Forge.walkTo(
    api,
    item,
    ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next', 'in-development', 'in-review'],
    { seats: {
      primary_user_id: stranger.user.id,
      reviewer_id: reviewer.user.id,
      deliverer_id: stranger.user.id,
    } },
  );

  await Forge.satisfy(api, item, 'completed');
  item = (await api.get(`/work-items/${item.id}`)).item;

  // The administrator is not the reviewer. Rank does not stand in for
  // assignment — the way past that is the override, which leaves a mark.
  const byAdmin = await api.post(`/work-items/${item.id}/transition`, {
    to: 'completed',
    record_version: item.record_version,
  });
  expect(byAdmin.status()).toBe(403);
  expect((await byAdmin.json()).data.capability).toBe('approve_review');

  // The person the item names may.
  const theirs = await browser.newContext({ baseURL });
  const theirPage = await theirs.newPage();
  await Forge.signInPage(theirPage, reviewer.login, PASSWORD);
  await theirPage.goto('/blueworx-forge/');
  const theirNonce = await theirPage.evaluate(() => window.bwxForgeData?.nonce);
  await theirPage.close();

  const approved = await theirs.request.post(`${BASE}/work-items/${item.id}/transition`, {
    headers: { 'X-WP-Nonce': theirNonce },
    data: { to: 'completed', record_version: item.record_version },
  });
  expect(approved.status(), await approved.text()).toBe(200);
  expect((await approved.json()).item.stage).toBe('completed');

  await theirs.close();
  await context.close();
});

test('a substitute may stand in, and the changelog says it was a substitute', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { context, api } = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(api, 'Substitute Co', RUN_ID);

  const away = await Forge.makePerson(api, client.id, 'staff', 'away');
  const standin = await Forge.makePerson(api, client.id, 'staff', 'standin');

  let item = (
    await (
      await api.post('/work-items', {
        client_site_id: site.id,
        title: `Reviewer is away ${RUN_ID}`,
        problem: 'Something needs doing.',
        level: 'sub-feature',
        work_type: 'feature',
      })
    ).json()
  ).item;

  item = await Forge.walkTo(
    api,
    item,
    ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next', 'in-development', 'in-review'],
    { seats: {
      primary_user_id: standin.user.id,
      reviewer_id: away.user.id,
      deliverer_id: standin.user.id,
    } },
  );

  await Forge.satisfy(api, item, 'completed');
  item = (await api.get(`/work-items/${item.id}`)).item;

  // Assigning the substitute is the Primary administrator's, and nobody else's.
  const assigned = await api.patch(`/work-items/${item.id}`, {
    reviewer_substitute_id: standin.user.id,
    record_version: item.record_version,
  });
  expect(assigned.status(), await assigned.text()).toBe(200);
  item = (await assigned.json()).item;

  const theirs = await browser.newContext({ baseURL });
  const theirPage = await theirs.newPage();
  await Forge.signInPage(theirPage, standin.login, PASSWORD);
  await theirPage.goto('/blueworx-forge/');
  const theirNonce = await theirPage.evaluate(() => window.bwxForgeData?.nonce);
  await theirPage.close();

  const approved = await theirs.request.post(`${BASE}/work-items/${item.id}/transition`, {
    headers: { 'X-WP-Nonce': theirNonce },
    data: { to: 'completed', record_version: item.record_version },
  });
  expect(approved.status(), await approved.text()).toBe(200);

  // Audited: the entry says it was done by a stand-in, so "who actually
  // approved this" survives the person being away.
  const after = await api.get(`/work-items/${item.id}`);
  const entry = after.history[after.history.length - 1];
  expect(entry.to_stage).toBe('completed');
  expect(entry.via).toBe('substitute');

  await theirs.close();
  await context.close();
});

test('reopening starts a new cycle and keeps the completion it came from', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { context, api } = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(api, 'Reopen Co', RUN_ID);
  const crew = await Forge.team(api, browser, baseURL, client.id);

  let item = (
    await (
      await api.post('/work-items', {
        client_site_id: site.id,
        title: `Comes back ${RUN_ID}`,
        problem: 'Something needs doing.',
        level: 'sub-feature',
        work_type: 'feature',
      })
    ).json()
  ).item;

  item = await Forge.walkTo(
    api,
    item,
    ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next', 'in-development'],
    { seats: crew.seats },
  );

  // The administrator overrides into Completed rather than walking the review,
  // which is the point of having an override — and it leaves a mark.
  const overridden = await api.post(`/work-items/${item.id}/override`, {
    to: 'completed',
    reason: 'Approved out of band during the migration.',
    record_version: item.record_version,
  });
  expect(overridden.status(), await overridden.text()).toBe(200);

  item = (await overridden.json()).item;
  expect(item.stage).toBe('completed');
  expect(item.override_used).toBe(true);
  expect(item.override_reason).toContain('out of band');

  const historyBefore = (await api.get(`/work-items/${item.id}`)).history.length;

  // No reason, no reopen.
  const silent = await api.post(`/work-items/${item.id}/reopen`, {
    to: 'in-development',
    record_version: item.record_version,
  });
  expect(silent.status()).toBe(400);

  const reopened = await api.post(`/work-items/${item.id}/reopen`, {
    to: 'in-development',
    reason: 'The client found a case we missed.',
    record_version: item.record_version,
  });
  expect(reopened.status(), await reopened.text()).toBe(200);

  const back = (await reopened.json()).item;
  expect(back.stage).toBe('in-development');
  expect(back.cycle).toBe(2);

  // Nothing was erased: the completion it came from is still in the changelog,
  // and so is the reopening.
  const after = await api.get(`/work-items/${item.id}`);
  expect(after.history.length).toBe(historyBefore + 1);
  expect(after.history.some((each) => 'completed' === each.to_stage)).toBe(true);
  expect(after.history.some((each) => 'reopened' === each.action)).toBe(true);

  // And the new cycle reviews from scratch rather than inheriting the old
  // cycle's records.
  expect(Object.keys(after.records)).toHaveLength(0);

  await crew.close();
  await context.close();
});

test('the override refuses what even an override must not do', async ({ browser, baseURL }) => {
  const { context, api } = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(api, 'Override Co', RUN_ID);

  const feature = (
    await (
      await api.post('/work-items', {
        client_site_id: site.id,
        title: `Not a bug ${RUN_ID}`,
        problem: 'Something needs doing.',
        level: 'sub-feature',
        work_type: 'feature',
      })
    ).json()
  ).item;

  // No reason, no override.
  const silent = await api.post(`/work-items/${feature.id}/override`, {
    to: 'released',
    record_version: feature.record_version,
  });
  expect(silent.status()).toBe(400);

  // Bug Tracking exists only for bugs, and that is a property of the stage
  // rather than a rule about who may move work (#110).
  const wrongStage = await api.post(`/work-items/${feature.id}/override`, {
    to: 'bug-tracking',
    reason: 'I would like it there.',
    record_version: feature.record_version,
  });
  expect(wrongStage.status()).toBe(409);

  // Everything else it may do, gates and all.
  const jumped = await api.post(`/work-items/${feature.id}/override`, {
    to: 'released',
    reason: 'Delivered before Forge existed; recording it now.',
    record_version: feature.record_version,
  });
  expect(jumped.status(), await jumped.text()).toBe(200);

  const after = await api.get(`/work-items/${feature.id}`);
  expect(after.item.stage).toBe('released');
  expect(after.item.override_used).toBe(true);

  const entry = after.history[after.history.length - 1];
  expect(entry.action).toBe('overridden');
  expect(entry.via).toBe('override');

  await context.close();
});
