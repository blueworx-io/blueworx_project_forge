import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// #102. A retried write cannot duplicate a record, and a stale write cannot
// silently overwrite a newer one.
//
// Both rules are implemented per route, so both are proved per route rather
// than in one place: the failure mode is exactly that one endpoint forgets.
// Each test here names the endpoint it is about.

const RUN = `wc${Date.now()}`;

test.describe('write conventions', () => {
  test.describe.configure({ mode: 'serial' });

  let world;

  test.beforeAll(async ({ browser, baseURL }) => {
    const asAdmin = await signedIn(
      browser,
      baseURL,
      process.env.WP_ADMIN_USER ?? 'admin',
      process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw'
    );

    const { client, site } = await makeSite(asAdmin.api, `${RUN} conventions`, RUN);

    world = { api: asAdmin.api, context: asAdmin.context, client, site };
  });

  test.afterAll(async () => {
    await world?.context?.close();
  });

  // -------------------------------------------------------------------------
  // A replayed create makes one record.
  // -------------------------------------------------------------------------

  test('replaying a work item create makes one record, not two', async () => {
    const key = `${RUN}-item`;
    const body = {
      client_site_id: world.site.id,
      level: 'sub-feature',
      work_type: 'feature',
      title: 'Retried once',
      problem: 'The connection dropped and the client tried again.',
    };

    const first = await world.api.request.post('/wp-json/blueworx-forge/v1/work-items', {
      headers: { ...world.api.headers, 'Idempotency-Key': key },
      data: body,
    });
    const second = await world.api.request.post('/wp-json/blueworx-forge/v1/work-items', {
      headers: { ...world.api.headers, 'Idempotency-Key': key },
      data: body,
    });

    expect(first.status(), await first.text()).toBe(200);
    expect(second.status(), await second.text()).toBe(200);

    // The same record, not a second one that happens to look the same.
    expect((await second.json()).item.id).toBe((await first.json()).item.id);

    const listed = await world.api.get(`/work-items?client_site_id=${world.site.id}`);
    const matching = listed.items.filter((item) => 'Retried once' === item.title);

    expect(matching).toHaveLength(1);
  });

  test('replaying a client create makes one record, not two', async () => {
    const key = `${RUN}-client`;
    const body = { display_name: `${RUN} retried client`, timezone: 'Europe/London' };

    const first = await world.api.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { ...world.api.headers, 'Idempotency-Key': key },
      data: body,
    });
    const second = await world.api.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { ...world.api.headers, 'Idempotency-Key': key },
      data: body,
    });

    expect((await second.json()).client.id).toBe((await first.json()).client.id);
  });

  test('a retry key used for one kind of write does not answer another', async () => {
    const key = `${RUN}-crossed`;

    const client = await world.api.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { ...world.api.headers, 'Idempotency-Key': key },
      data: { display_name: `${RUN} crossed`, timezone: 'Europe/London' },
    });
    expect(client.status(), await client.text()).toBe(200);

    const item = await world.api.request.post('/wp-json/blueworx-forge/v1/work-items', {
      headers: { ...world.api.headers, 'Idempotency-Key': key },
      data: {
        client_site_id: world.site.id,
        level: 'sub-feature',
        work_type: 'feature',
        title: 'Not a client',
        problem: 'Same key, different kind of write.',
      },
    });

    // A work item, not the client's response played back at it.
    expect(item.status(), await item.text()).toBe(200);
    expect((await item.json()).item).toBeTruthy();
  });

  // -------------------------------------------------------------------------
  // A stale write is refused rather than merged.
  // -------------------------------------------------------------------------

  test('editing a work item against a stale version is refused with a conflict', async () => {
    const made = await makeItem(world.api, world.site.id, {
      title: 'Two people at once',
      problem: 'Both had it open.',
    });
    const item = (await made.json()).item;

    const first = await world.api.patch(`/work-items/${item.id}`, {
      title: 'Edited by the first person',
      record_version: item.record_version,
    });
    expect(first.status(), await first.text()).toBe(200);

    // The second person still holds the version they loaded.
    const second = await world.api.patch(`/work-items/${item.id}`, {
      title: 'Edited by the second person',
      record_version: item.record_version,
    });

    expect(second.status()).toBe(409);

    // And is handed the current state, so they can see what they would have
    // overwritten rather than being told only that they lost.
    const body = await second.json();
    expect(body.data.current.title).toBe('Edited by the first person');
  });

  test('editing a client against a stale version is refused with a conflict', async () => {
    const created = await (
      await world.api.post('/clients', { display_name: `${RUN} contended`, timezone: 'Europe/London' })
    ).json();

    const first = await world.api.patch(`/clients/${created.client.id}`, {
      display_name: `${RUN} contended, edited`,
      record_version: created.client.record_version,
    });
    expect(first.status(), await first.text()).toBe(200);

    const second = await world.api.patch(`/clients/${created.client.id}`, {
      display_name: `${RUN} contended, edited again`,
      record_version: created.client.record_version,
    });

    expect(second.status()).toBe(409);
  });

  test('a write quoting no version at all is refused', async () => {
    const made = await makeItem(world.api, world.site.id, {
      title: 'No version quoted',
      problem: 'The caller forgot.',
    });
    const item = (await made.json()).item;

    const edited = await world.api.patch(`/work-items/${item.id}`, { title: 'Changed anyway' });

    expect(edited.status()).toBe(400);
  });

  // -------------------------------------------------------------------------
  // Identity.
  // -------------------------------------------------------------------------

  test('every record carries an id that says what it is', async () => {
    const made = await makeItem(world.api, world.site.id, {
      title: 'Identifiable',
      problem: 'Ids are read by people as well as machines.',
    });
    const item = (await made.json()).item;

    // Prefixed, so an id pasted into a message says which kind of record it is
    // and cannot be mistaken for another kind's.
    expect(item.id).toMatch(/^wrk_[0-9a-f]{26}$/);
    expect(world.client.id).toMatch(/^cli_[0-9a-f]{26}$/);
    expect(world.site.id).toMatch(/^cst_[0-9a-f]{26}$/);
  });

  test('two records never share an id', async () => {
    const ids = new Set();

    for (let n = 0; n < 5; n += 1) {
      const made = await makeItem(world.api, world.site.id, {
        title: `Unique ${n}`,
        problem: 'One of several made in a row.',
      });
      ids.add((await made.json()).item.id);
    }

    expect(ids.size).toBe(5);
  });
});
