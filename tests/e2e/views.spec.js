import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// #123 and #124. One filter model behind every view, saved views that cannot
// bend the rules, and a check that the views agree with each other.
//
// The reconciliation test is the point of the other two. Four views showing
// different totals for the same filters is the failure that survives review,
// because each view looks correct on its own and nothing compares them.

const RUN = `vw${Date.now()}`;

test.describe('views', () => {
  test.describe.configure({ mode: 'serial' });

  let world;

  test.beforeAll(async ({ browser, baseURL }) => {
    const asAdmin = await signedIn(
      browser,
      baseURL,
      process.env.WP_ADMIN_USER ?? 'admin',
      process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw'
    );

    const { client, site } = await makeSite(asAdmin.api, `${RUN} views`, RUN);

    // A spread worth filtering: two types, two priorities, one searchable.
    const made = [];

    for (const spec of [
      { title: `${RUN} checkout is slow`, work_type: 'bug', problem: 'The checkout takes nine seconds.' },
      { title: `${RUN} add a report`, work_type: 'feature', problem: 'They want a monthly report.' },
      { title: `${RUN} tidy the footer`, work_type: 'task', problem: 'It has an old year in it.' },
    ]) {
      const item = await makeItem(asAdmin.api, site.id, spec);
      expect(item.status(), await item.text()).toBe(200);
      made.push((await item.json()).item);
    }

    world = { api: asAdmin.api, context: asAdmin.context, client, site, made };
  });

  test.afterAll(async () => {
    await world?.context?.close();
  });

  /** The work on the site under test, with whatever filters. */
  function listing(query = '') {
    return world.api.get(`/work-items?client_site_id=${world.site.id}${query}`);
  }

  // -------------------------------------------------------------------------
  // Filtering.
  // -------------------------------------------------------------------------

  test('with no filters, everything on the site is shown', async () => {
    const all = await listing();

    expect(all.items.length).toBeGreaterThanOrEqual(3);
    expect(all.total).toBe(all.items.length);
  });

  test('filtering by work type narrows to that type', async () => {
    const bugs = await listing('&work_type[]=bug');

    expect(bugs.items.length).toBeGreaterThan(0);
    expect(bugs.items.every((item) => 'bug' === item.work_type)).toBe(true);
  });

  test('two values in one filter match either of them', async () => {
    const either = await listing('&work_type[]=bug&work_type[]=task');

    expect(either.items.every((item) => ['bug', 'task'].includes(item.work_type))).toBe(true);
    expect(either.items.length).toBeGreaterThanOrEqual(2);
  });

  test('two different filters both have to match', async () => {
    const both = await listing('&work_type[]=bug&stage[]=in-review');

    expect(both.items).toHaveLength(0);
  });

  test('searching looks at the title and the problem', async () => {
    const found = await listing('&search=checkout');

    expect(found.items).toHaveLength(1);
    expect(found.items[0].title).toContain('checkout is slow');
  });

  test('a filter nobody defined is ignored rather than obeyed', async () => {
    const all = await listing();
    const injected = await listing('&created_by[]=1&client_id[]=cli_someone_elses');

    // Dropped, not applied: a view does not get to name the column it filters
    // on, and a filter is a query.
    expect(injected.items.length).toBe(all.items.length);
  });

  test('the response says which filters it actually applied', async () => {
    const answered = await listing('&work_type[]=bug&created_by[]=1');

    expect(answered.filters).toEqual({ work_type: ['bug'] });
  });

  // -------------------------------------------------------------------------
  // #124. Four views, one truth.
  // -------------------------------------------------------------------------

  test('the total and the items agree, whatever the filters', async () => {
    for (const query of ['', '&work_type[]=bug', '&search=checkout', '&work_type[]=bug&work_type[]=task']) {
      const answered = await listing(query);

      expect(answered.total, `for "${query}"`).toBe(answered.items.length);
    }
  });

  test('grouping rearranges the same items and loses none', async () => {
    const flat = await listing();
    const grouped = await listing('&group_by=work_type');

    const inGroups = Object.values(grouped.groups).reduce((sum, group) => sum + group.length, 0);

    expect(inGroups).toBe(flat.total);
  });

  test('every view is built from one answer, so two views cannot disagree', async ({ page }) => {
    // The board and the list are two renderings of one response. This walks
    // both in the browser for the same filters and compares what they show —
    // the check the issue asks for, and the one that fails the day somebody
    // gives a view its own filtering.
    await page.goto('/wp-login.php');
    await page.fill('#user_login', process.env.WP_ADMIN_USER ?? 'admin');
    await page.fill('#user_pass', process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw');
    await page.click('#wp-submit');
    await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

    await page.goto('/blueworx-forge/');
    await page.selectOption('[data-testid="bwx-site"]', world.site.id);

    await expect(page.locator('[data-testid="bwx-board"]')).toBeVisible();
    const onBoard = await page.locator('[data-testid="bwx-card"]').count();

    await page.locator('[data-testid="bwx-view-list"]').click();
    await expect(page.locator('[data-testid="bwx-list"]')).toBeVisible();
    const inList = await page.locator('[data-testid="bwx-row"]').count();

    expect(inList).toBe(onBoard);

    // The schedule shows the same work in two places rather than one: on the
    // chart if it has dates, in the tray if it has none. The two together are
    // the whole answer, and that sum is what has to match the other views —
    // counting only the bars is exactly how a Gantt quietly loses the
    // unscheduled work (#120).
    await page.locator('[data-testid="bwx-view-gantt"]').click();
    await expect(page.locator('[data-testid="bwx-gantt"]')).toBeVisible();
    const drawn = await page.locator('[data-testid="bwx-gantt-row"]').count();
    const untimed = await page.locator('[data-testid="bwx-gantt-tray-item"]').count();

    expect(drawn + untimed).toBe(onBoard);

    // And again with a filter applied, because agreeing on everything is
    // easier than agreeing on a subset.
    await page.locator('[data-testid="bwx-view-list"]').click();
    await page.fill('[data-testid="bwx-search"]', 'checkout');
    await expect(page.locator('[data-testid="bwx-row"]')).toHaveCount(1);

    await page.locator('[data-testid="bwx-view-board"]').click();
    await expect(page.locator('[data-testid="bwx-card"]')).toHaveCount(1);

    await page.locator('[data-testid="bwx-view-gantt"]').click();
    const drawnNow = await page.locator('[data-testid="bwx-gantt-row"]').count();
    const untimedNow = await page.locator('[data-testid="bwx-gantt-tray-item"]').count();

    expect(drawnNow + untimedNow).toBe(1);
  });

  // -------------------------------------------------------------------------
  // Saved views.
  // -------------------------------------------------------------------------

  test('a saved view is kept and comes back', async () => {
    const saved = await world.api.post('/saved-views', {
      name: `${RUN} bugs only`,
      filters: { work_type: ['bug'] },
      grouping: 'stage',
    });
    expect(saved.status(), await saved.text()).toBe(200);

    const mine = await world.api.get('/saved-views');
    const found = mine.views.find((view) => view.name === `${RUN} bugs only`);

    expect(found).toBeTruthy();
    expect(found.filters).toEqual({ work_type: ['bug'] });
  });

  test('a saved view cannot carry anything that changes what is allowed', async () => {
    const saved = await (
      await world.api.post('/saved-views', {
        name: `${RUN} sneaky`,
        filters: { stage: ['triage'] },
        capability: 'override',
        role: 'primary_admin',
        client_id: 'cli_someone_elses',
      })
    ).json();

    expect(Object.keys(saved.view).sort()).toEqual(['filters', 'grouping', 'id', 'name']);
  });

  test('a saved view can be removed', async () => {
    const saved = await (
      await world.api.post('/saved-views', { name: `${RUN} temporary` })
    ).json();

    const removed = await world.api.request.delete(
      `/wp-json/blueworx-forge/v1/saved-views/${saved.view.id}`,
      { headers: world.api.headers }
    );
    expect(removed.status(), await removed.text()).toBe(200);

    const mine = await world.api.get('/saved-views');

    expect(mine.views.some((view) => view.id === saved.view.id)).toBe(false);
  });

  test('one person cannot read or remove another person\'s saved views', async ({ browser, baseURL }) => {
    const saved = await (
      await world.api.post('/saved-views', { name: `${RUN} private` })
    ).json();

    const login = `viewer${Date.now()}`;
    const created = await world.api.request.post('/wp-json/wp/v2/users', {
      headers: world.api.headers,
      data: {
        username: login,
        email: `${login}@example.test`,
        password: 'forge-test-pw-4471',
        roles: ['subscriber'],
      },
    });
    expect(created.status(), await created.text()).toBe(201);

    const them = await signedIn(browser, baseURL, login, 'forge-test-pw-4471');

    const theirs = await them.api.get('/saved-views');
    expect(theirs.views.some((view) => view.id === saved.view.id)).toBe(false);

    const removed = await them.api.request.delete(
      `/wp-json/blueworx-forge/v1/saved-views/${saved.view.id}`,
      { headers: them.api.headers }
    );
    expect(removed.status()).toBe(404);

    await them.context.close();

    // Still there, for the person whose it is.
    const mine = await world.api.get('/saved-views');
    expect(mine.views.some((view) => view.id === saved.view.id)).toBe(true);
  });
});
