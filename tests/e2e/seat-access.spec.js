import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #393: only staff with access to a client can be put on its tasks.
//
// Before this any of our people could be put in any seat, on any client's
// work, including a client they could not open. The rule is the reminders'
// (a person reaches the site through a staff membership, or is the studio's
// administrator), put to each seat as it is saved and to the pickers that
// offer the names.
//
// The instance is kept between runs, so every name carries a run id.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** A Forge person behind a WordPress administrator, holding no membership. */
async function makeAdministrator(api, label) {
  const login = `${label}${Date.now()}${Math.floor(Math.random() * 1000)}`;
  const wp = await api.request.post('/wp-json/wp/v2/users', {
    headers: api.headers,
    data: { username: login, email: `${login}@example.test`, password: Forge.PASSWORD, roles: ['administrator'] },
  });
  expect(wp.status(), await wp.text()).toBe(201);

  const created = await api.post('/users', {
    email: `${login}@example.test`,
    display_name: label,
    wp_user_id: (await wp.json()).id,
  });
  expect(created.status(), await created.text()).toBe(200);

  return (await created.json()).user;
}

/** Two clients, and one of our people on each, plus one of the client's own. */
async function twoClients(api) {
  const mine = await Forge.makeSite(api, `Seat Co ${RUN_ID}`, RUN_ID);
  const theirs = await Forge.makeSite(api, `Other Seat Co ${RUN_ID}`, RUN_ID);

  const insider = await Forge.makePerson(api, mine.client.id, 'staff', `Insider-${RUN_ID}`);
  const outsider = await Forge.makePerson(api, theirs.client.id, 'staff', `Outsider-${RUN_ID}`);
  const clientSide = await Forge.makePerson(api, mine.client.id, 'client_admin', `ClientSide-${RUN_ID}`);

  return { mine, theirs, insider, outsider, clientSide };
}

test.describe('seats go only to people who reach the client', () => {
  test.beforeEach(() => {
    test.slow();
  });

  test('saving a seat for somebody without access is refused, with their name', async ({ browser, baseURL }) => {
    const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const { mine, insider, outsider, clientSide } = await twoClients(admin.api);

    const refused = await Forge.makeItem(admin.api, mine.site.id, {
      title: `Refused ${RUN_ID}`,
      primary_user_id: outsider.id,
    });
    expect(refused.status(), await refused.text()).toBe(400);
    expect((await refused.json()).data.fields.primary_user_id).toBe(`Outsider-${RUN_ID} doesn't have access to this client.`);

    // One of the client's own people reaches the site, but cannot do the work.
    const clientRefused = await Forge.makeItem(admin.api, mine.site.id, {
      title: `Client refused ${RUN_ID}`,
      deliverer_id: clientSide.id,
    });
    expect(clientRefused.status(), await clientRefused.text()).toBe(400);
    expect((await clientRefused.json()).data.fields.deliverer_id).toBe(`ClientSide-${RUN_ID} doesn't have access to this client.`);

    const made = await Forge.makeItem(admin.api, mine.site.id, {
      title: `Allowed ${RUN_ID}`,
      primary_user_id: insider.id,
    });
    expect(made.status(), await made.text()).toBe(200);
    const item = (await made.json()).item;
    expect(item.primary_user_id).toBe(insider.id);

    // An edit, and the substitute seats, are asked the same question.
    for (const field of ['reviewer_id', 'reviewer_substitute_id', 'deliverer_substitute_id']) {
      const edited = await admin.api.patch(`/work-items/${item.id}`, {
        [field]: outsider.id,
        record_version: item.record_version,
      });
      expect(edited.status(), await edited.text()).toBe(400);
      expect((await edited.json()).data.fields[field]).toBe(`Outsider-${RUN_ID} doesn't have access to this client.`);
    }

    // The studio's administrator reaches every client, membership or not.
    const boss = await makeAdministrator(admin.api, `Boss-${RUN_ID}`);
    const seated = await admin.api.patch(`/work-items/${item.id}`, {
      reviewer_id: boss.id,
      record_version: item.record_version,
    });
    expect(seated.status(), await seated.text()).toBe(200);

    await admin.context.close();
  });

  test('a seat whose person has lost access blocks only saves that send it', async ({ browser, baseURL }) => {
    const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const { mine, insider } = await twoClients(admin.api);

    const made = await Forge.makeItem(admin.api, mine.site.id, {
      title: `Stale ${RUN_ID}`,
      primary_user_id: insider.id,
    });
    expect(made.status(), await made.text()).toBe(200);
    let item = (await made.json()).item;

    // The insider leaves the client.
    const held = (await admin.api.get(`/clients/${mine.client.id}/memberships`)).memberships.find(
      (membership) => membership.user_id === insider.id
    );
    const ended = await admin.api.patch(`/memberships/${held.id}`, {
      status: 'inactive',
      record_version: held.record_version,
    });
    expect(ended.status(), await ended.text()).toBe(200);

    const unrelated = await admin.api.patch(`/work-items/${item.id}`, {
      title: `Stale renamed ${RUN_ID}`,
      record_version: item.record_version,
    });
    expect(unrelated.status(), await unrelated.text()).toBe(200);
    item = (await unrelated.json()).item;

    const resent = await admin.api.patch(`/work-items/${item.id}`, {
      title: `Stale again ${RUN_ID}`,
      primary_user_id: insider.id,
      record_version: item.record_version,
    });
    expect(resent.status(), await resent.text()).toBe(400);
    expect((await resent.json()).data.fields.primary_user_id).toBe(`Insider-${RUN_ID} doesn't have access to this client.`);

    await admin.context.close();
  });

  test('the people list for a site offers only our people who reach it', async ({ browser, baseURL }) => {
    const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const { mine, insider, outsider, clientSide } = await twoClients(admin.api);

    const everyone = (await admin.api.get('/people')).people.map((person) => person.id);
    expect(everyone).toContain(outsider.id);

    const onSite = (await admin.api.get(`/people?client_site_id=${mine.site.id}`)).people.map((person) => person.id);
    expect(onSite).toContain(insider.id);
    expect(onSite).not.toContain(outsider.id);
    expect(onSite).not.toContain(clientSide.id);

    await admin.context.close();
  });

  test("the panel's seat pickers list only people who reach the task's client", async ({ browser, baseURL }) => {
    const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const { mine, insider, outsider } = await twoClients(admin.api);

    // Somebody on the work who then leaves the client is still shown, flagged.
    const leaver = await Forge.makePerson(admin.api, mine.client.id, 'staff', `Leaver-${RUN_ID}`);
    const made = await Forge.makeItem(admin.api, mine.site.id, { title: `Picker ${RUN_ID}`, deliverer_id: leaver.id });
    expect(made.status(), await made.text()).toBe(200);

    const held = (await admin.api.get(`/clients/${mine.client.id}/memberships`)).memberships.find(
      (membership) => membership.user_id === leaver.id
    );
    const ended = await admin.api.patch(`/memberships/${held.id}`, { status: 'inactive', record_version: held.record_version });
    expect(ended.status(), await ended.text()).toBe(200);

    const page = await admin.context.newPage();
    await page.goto('/blueworx-forge/');
    await page.waitForSelector('[data-testid="bwx-board"]');
    await page.selectOption('[data-testid="bwx-site"]', mine.site.id);
    await page.locator('[data-testid="bwx-card"]').click();
    await expect(page.locator('[data-testid="bwx-panel"]')).toBeVisible();

    for (const field of ['primary_user_id', 'reviewer_id', 'deliverer_id']) {
      const picker = page.locator(`#bwx-${field}`);
      await picker.scrollIntoViewIfNeeded().catch(() => {});
      await expect(picker.locator(`option[value="${insider.id}"]`)).toHaveCount(1);
      await expect(picker.locator(`option[value="${outsider.id}"]`)).toHaveCount(0);
    }

    await expect(page.locator('#bwx-deliverer_id')).toHaveValue(leaver.id);
    await expect(page.locator(`#bwx-deliverer_id option[value="${leaver.id}"]`)).toHaveText(
      `Leaver-${RUN_ID} (no access to this client)`
    );
    await expect(page.locator(`#bwx-primary_user_id option[value="${leaver.id}"]`)).toHaveCount(0);

    await admin.context.close();
  });

  test('a request converted at Triage cannot name somebody without access', async ({ browser, baseURL, request }) => {
    const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const { mine, insider, outsider } = await twoClients(admin.api);
    const second = await Forge.makePerson(admin.api, mine.client.id, 'staff', `Second-${RUN_ID}`);

    const as = await Forge.asClientSite(admin.api, mine.site.id, request);
    const submission = await Forge.makeSubmission(as, {});

    const refused = await admin.api.post(`/submissions/${submission.id}/conversion`, {
      entry_stage: 'triage',
      primary_user_id: insider.id,
      reviewer_id: outsider.id,
    });
    expect(refused.status(), await refused.text()).toBe(400);
    expect((await refused.json()).data.fields.reviewer_id).toBe(`Outsider-${RUN_ID} doesn't have access to this client.`);

    const converted = await admin.api.post(`/submissions/${submission.id}/conversion`, {
      entry_stage: 'triage',
      primary_user_id: insider.id,
      reviewer_id: second.id,
    });
    expect(converted.status(), await converted.text()).toBe(200);

    await admin.context.close();
  });
});
