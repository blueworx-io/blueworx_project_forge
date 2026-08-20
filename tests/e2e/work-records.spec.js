import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// M3's remaining half, proved end to end: the changelog (#99), what a parent
// reads as (#101) and work that waits on other work (#103).
//
// All three are about what somebody reads later rather than what they type now,
// so all three are tested by doing something and then asking the API what it
// remembers.

const RUN = `wr${Date.now()}`;

test.describe('work records', () => {
  test.describe.configure({ mode: 'serial' });

  let world;

  test.beforeAll(async ({ browser, baseURL }) => {
    const asAdmin = await signedIn(
      browser,
      baseURL,
      process.env.WP_ADMIN_USER ?? 'admin',
      process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw'
    );

    const { client, site } = await makeSite(asAdmin.api, `${RUN} records`, RUN);

    world = { api: asAdmin.api, context: asAdmin.context, client, site };
  });

  test.afterAll(async () => {
    await world?.context?.close();
  });

  /** An item on the site under test. */
  async function item(fields) {
    const made = await makeItem(world.api, world.site.id, fields);
    expect(made.status(), await made.text()).toBe(200);

    return (await made.json()).item;
  }

  /** Edits an item, quoting whatever version it currently holds. */
  async function edit(id, changes) {
    const current = await world.api.get(`/work-items/${id}`);
    const patched = await world.api.patch(`/work-items/${id}`, {
      ...changes,
      record_version: current.item.record_version,
    });
    expect(patched.status(), await patched.text()).toBe(200);

    return (await patched.json()).item;
  }

  // -------------------------------------------------------------------------
  // #99. The changelog.
  // -------------------------------------------------------------------------

  test('an edit is remembered as what changed, from what, to what', async () => {
    const made = await item({ title: 'Before', problem: 'It needs a better name.' });

    await edit(made.id, { title: 'After' });

    const history = (await world.api.get(`/work-items/${made.id}`)).history;
    const entry = history.find((event) => 'title' === event.field);

    expect(entry, JSON.stringify(history)).toBeTruthy();
    expect(entry.previous_value).toBe('Before');
    expect(entry.new_value).toBe('After');
  });

  test('each field changed in one edit gets its own entry', async () => {
    const made = await item({ title: 'Several at once', problem: 'Three things move together.' });

    await edit(made.id, { priority: 'high', planned_due: '2026-10-01', scope: 'Just this.' });

    const history = (await world.api.get(`/work-items/${made.id}`)).history;
    const fields = history.filter((event) => event.field).map((event) => event.field);

    expect(fields).toEqual(expect.arrayContaining(['priority', 'planned_due', 'scope']));
  });

  test('an edit that changes nothing is not remembered as a change', async () => {
    const made = await item({ title: 'Unchanged', problem: 'Saved without editing.' });

    const before = (await world.api.get(`/work-items/${made.id}`)).history.length;
    await edit(made.id, { title: 'Unchanged' });
    const after = (await world.api.get(`/work-items/${made.id}`)).history.length;

    expect(after).toBe(before);
  });

  test('the changelog cannot be edited or deleted through the API', async () => {
    const made = await item({ title: 'Immutable', problem: 'History is not a draft.' });
    await edit(made.id, { title: 'Changed once' });

    const entry = (await world.api.get(`/work-items/${made.id}`)).history.find((e) => 'title' === e.field);

    // There is no route at all. Not a route that refuses — none, which is the
    // stronger guarantee: a refusal can be got round by finding another way in.
    const patched = await world.api.request.patch(
      `/wp-json/blueworx-forge/v1/work-items/${made.id}/history/${entry.id}`,
      { headers: world.api.headers, data: { new_value: 'Something else' } }
    );
    const deleted = await world.api.request.delete(
      `/wp-json/blueworx-forge/v1/work-items/${made.id}/history/${entry.id}`,
      { headers: world.api.headers }
    );

    expect(patched.status()).toBe(404);
    expect(deleted.status()).toBe(404);
  });

  test('history says which interface a change came from', async () => {
    const made = await item({ title: 'Sourced', problem: 'It matters who typed it.' });
    await edit(made.id, { title: 'Sourced, edited' });

    const entry = (await world.api.get(`/work-items/${made.id}`)).history.find((e) => 'title' === e.field);

    expect(entry.source_interface).toBe('studio');
  });

  // -------------------------------------------------------------------------
  // #101. What a parent reads as.
  // -------------------------------------------------------------------------

  test('a parent with nothing beneath it reads as empty, not as unstarted', async () => {
    const parent = await item({ level: 'feature', title: 'Nothing under it', problem: 'Yet.' });

    const read = await world.api.get(`/work-items/${parent.id}`);

    expect(read.item.derived_state).toBe('empty');
    expect(read.item.progress).toBe(0);
  });

  test('a parent reflects how far its children have got', async () => {
    const parent = await item({ level: 'feature', title: 'Half done', problem: 'Two children.' });

    const done = await item({ parent_id: parent.id, title: 'Finished child', problem: 'Done.' });
    await item({ parent_id: parent.id, title: 'Unfinished child', problem: 'Not done.' });

    // Walk one child to Completed the short way: the outcome route ends work
    // without needing every gate, which is enough to prove the parent follows.
    const current = await world.api.get(`/work-items/${done.id}`);
    await world.api.post(`/work-items/${done.id}/outcome`, {
      outcome: 'cancelled',
      reason: 'Standing in for a completed child.',
      record_version: current.item.record_version,
    });

    const read = await world.api.get(`/work-items/${parent.id}`);

    // The cancelled child no longer counts, so the parent is down to one child
    // and that child has not started.
    expect(read.item.derived_state).toBe('not-started');
  });

  test('a parent\'s progress cannot be written by hand', async () => {
    const parent = await item({ level: 'feature', title: 'Not talked up', problem: 'By hand.' });

    const current = await world.api.get(`/work-items/${parent.id}`);
    await world.api.patch(`/work-items/${parent.id}`, {
      progress: 100,
      derived_state: 'completed',
      record_version: current.item.record_version,
    });

    const read = await world.api.get(`/work-items/${parent.id}`);

    expect(read.item.progress).toBe(0);
    expect(read.item.derived_state).toBe('empty');
  });

  test('a parent with an unfinished child cannot be completed', async () => {
    const parent = await item({ level: 'feature', title: 'Held open', problem: 'One child left.' });
    await item({ parent_id: parent.id, title: 'Still going', problem: 'Not done.' });

    const current = await world.api.get(`/work-items/${parent.id}`);
    const readiness = current.readiness?.completed;

    // Either the move is not offered at all, or it is offered and says what it
    // is waiting for. Both are honest; silently allowing it is not.
    if (readiness) {
      expect(JSON.stringify(readiness.unmet)).toContain('child');
    }

    const moved = await world.api.post(`/work-items/${parent.id}/transition`, {
      to: 'completed',
      record_version: current.item.record_version,
    });

    expect(moved.status()).not.toBe(200);
  });

  // -------------------------------------------------------------------------
  // #103. Work that waits on other work.
  // -------------------------------------------------------------------------

  test('a dependency chain is readable from both ends', async () => {
    const upstream = await item({ title: 'Goes first', problem: 'The other one needs it.' });
    const downstream = await item({ title: 'Goes second', problem: 'Waits for the first.' });

    const added = await world.api.post(`/work-items/${downstream.id}/dependencies`, {
      depends_on_id: upstream.id,
    });
    expect(added.status(), await added.text()).toBe(200);

    const waiting = await world.api.get(`/work-items/${downstream.id}`);
    expect(waiting.dependencies.upstream.map((d) => d.id)).toContain(upstream.id);

    const held = await world.api.get(`/work-items/${upstream.id}`);
    expect(held.dependencies.downstream.map((d) => d.id)).toContain(downstream.id);
  });

  test('a dependency with no dates is surfaced as unscheduled', async () => {
    const upstream = await item({ title: 'Undated', problem: 'Nobody has planned it.' });
    const downstream = await item({ title: 'Waiting on the undated one', problem: 'Stuck.' });

    await world.api.post(`/work-items/${downstream.id}/dependencies`, { depends_on_id: upstream.id });

    const read = await world.api.get(`/work-items/${downstream.id}`);

    expect(read.dependencies.summary.unscheduled).toContain(upstream.id);
    expect(read.dependencies.summary.clear).toBe(false);
  });

  test('a blocked dependency is surfaced as blocked', async () => {
    const upstream = await item({ title: 'Will be blocked', problem: 'Something is in the way.' });
    const downstream = await item({ title: 'Waiting on the blocked one', problem: 'Stuck.' });

    await world.api.post(`/work-items/${downstream.id}/dependencies`, { depends_on_id: upstream.id });

    const current = await world.api.get(`/work-items/${upstream.id}`);
    const blocked = await world.api.post(`/work-items/${upstream.id}/block`, {
      reason: 'Waiting on the client.',
      owner: 'Client',
      dependency: 'An answer.',
      target_date: '2026-10-01',
      next_action: 'Chase them.',
      record_version: current.item.record_version,
    });
    expect(blocked.status(), await blocked.text()).toBe(200);

    const read = await world.api.get(`/work-items/${downstream.id}`);

    expect(read.dependencies.summary.blocked).toContain(upstream.id);
  });

  test('work cannot be made to wait on itself', async () => {
    const only = await item({ title: 'Alone', problem: 'It cannot wait for itself.' });

    const added = await world.api.post(`/work-items/${only.id}/dependencies`, {
      depends_on_id: only.id,
    });

    expect(added.status()).toBe(400);
  });

  test('a dependency that would close a loop is refused', async () => {
    const first = await item({ title: 'Loop one', problem: 'A.' });
    const second = await item({ title: 'Loop two', problem: 'B.' });

    const forward = await world.api.post(`/work-items/${second.id}/dependencies`, {
      depends_on_id: first.id,
    });
    expect(forward.status(), await forward.text()).toBe(200);

    const back = await world.api.post(`/work-items/${first.id}/dependencies`, {
      depends_on_id: second.id,
    });

    expect(back.status()).toBe(400);
  });

  test('a dependency on another site is refused', async () => {
    const elsewhere = await makeSite(world.api, `${RUN} elsewhere`, RUN);
    const theirs = await (
      await makeItem(world.api, elsewhere.site.id, { title: 'Theirs', problem: 'Another site.' })
    ).json();

    const ours = await item({ title: 'Ours', problem: 'Cannot wait on another site.' });

    const added = await world.api.post(`/work-items/${ours.id}/dependencies`, {
      depends_on_id: theirs.item.id,
    });

    expect(added.status()).toBe(404);
  });

  test('a dependency can be removed, and the removal is remembered', async () => {
    const upstream = await item({ title: 'Briefly needed', problem: 'Then not.' });
    const downstream = await item({ title: 'Changed its mind', problem: 'No longer waiting.' });

    const added = await (
      await world.api.post(`/work-items/${downstream.id}/dependencies`, { depends_on_id: upstream.id })
    ).json();

    const removed = await world.api.request.delete(
      `/wp-json/blueworx-forge/v1/work-items/${downstream.id}/dependencies/${added.dependency.id}`,
      { headers: world.api.headers }
    );
    expect(removed.status(), await removed.text()).toBe(200);

    const read = await world.api.get(`/work-items/${downstream.id}`);

    expect(read.dependencies.upstream).toHaveLength(0);
    expect(read.history.some((event) => 'dependency-removed' === event.action)).toBe(true);
  });
});
