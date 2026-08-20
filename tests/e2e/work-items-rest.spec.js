import { test, expect } from '@playwright/test';

// #96, #97, #104 and #106 against a real WordPress. The unit tests prove the
// rules in isolation; these prove the table exists, the routes are registered,
// and — the one that matters — that a refused move leaves nothing behind.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

// Nothing is ever deleted and the instance is kept between runs, so everything
// this run writes carries the run with it.
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function signedInContext(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto('/blueworx-forge/');
  const nonce = await page.evaluate(() => window.bwxForgeData?.nonce);
  expect(nonce, 'no REST nonce was localised for the signed-in user').toBeTruthy();

  await page.close();
  return { context, nonce };
}

async function makeSite(request, nonce, label) {
  const client = await (
    await request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { 'X-WP-Nonce': nonce },
      data: { display_name: `${label} ${RUN_ID}`, timezone: 'Europe/London' },
    })
  ).json();

  const site = await (
    await request.post(`/wp-json/blueworx-forge/v1/clients/${client.client.id}/sites`, {
      headers: { 'X-WP-Nonce': nonce },
      data: { name: `${label} site ${RUN_ID}`, url: 'https://example.test' },
    })
  ).json();

  return site.site;
}

function makeItem(request, nonce, siteId, data) {
  return request.post('/wp-json/blueworx-forge/v1/work-items', {
    headers: { 'X-WP-Nonce': nonce },
    data: { client_site_id: siteId, ...data },
  });
}

function move(request, nonce, item, to) {
  return request.post(`/wp-json/blueworx-forge/v1/work-items/${item.id}/transition`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { to, record_version: item.record_version },
  });
}

function show(request, nonce, itemId) {
  return request
    .get(`/wp-json/blueworx-forge/v1/work-items/${itemId}`, { headers: { 'X-WP-Nonce': nonce } })
    .then((response) => response.json());
}

// A plausible answer for each field a gate asks for. Anything not named here is
// prose, and prose is prose.
function answerFor(field) {
  switch (field) {
    case 'planned_start':
      return '2026-09-01';
    case 'planned_due':
      return '2026-09-30';
    case 'priority':
      return 'normal';
    case 'commercial_class':
      return 'chargeable';
    case 'release_method':
      return 'software';
    case 'remaining_estimate':
      return 2;
    default:
      return 'Written down.';
  }
}

// Satisfies whatever stands between an item and a stage, then hands back the
// item as it now is. Since #105 the gates are real, so a test that wants to
// exercise a move past one has to go through it like a person would.
async function satisfy(request, nonce, item, to) {
  const detail = await show(request, nonce, item.id);
  const unmet = detail.readiness[to]?.unmet ?? [];
  const patch = {};

  for (const requirement of unmet) {
    if ('field' === requirement.by) {
      for (const field of requirement.fields) {
        patch[field] = answerFor(field);
      }
      continue;
    }

    if ('record' !== requirement.by) {
      continue;
    }

    const recorded = await request.post(
      `/wp-json/blueworx-forge/v1/work-items/${item.id}/gate`,
      {
        headers: { 'X-WP-Nonce': nonce },
        data: {
          requirement: requirement.id,
          value: 'Done.',
          evidence: requirement.evidence ? 'https://example.test/evidence' : '',
        },
      },
    );
    expect(recorded.status(), `recording ${requirement.id}`).toBe(200);
  }

  if (0 === Object.keys(patch).length) {
    return (await show(request, nonce, item.id)).item;
  }

  const edited = await request.patch(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { ...patch, record_version: (await show(request, nonce, item.id)).item.record_version },
  });
  expect(edited.status(), `filling in ${Object.keys(patch).join(', ')}`).toBe(200);

  return (await edited.json()).item;
}

// Move, having first done what the stage asks for.
async function advance(request, nonce, item, to) {
  const ready = await satisfy(request, nonce, item, to);

  return move(request, nonce, ready, to);
}

test('the stages are twelve, fixed, and readable without signing in', async ({ request }) => {
  const response = await request.get('/wp-json/blueworx-forge/v1/stages');

  expect(response.status()).toBe(200);
  const body = await response.json();

  expect(body.stages).toHaveLength(12);
  expect(body.stages[0].id).toBe('future-idea');
  expect(body.columns).toHaveLength(10);
  expect(body.columns).not.toContain('blocked');
});

test('a stranger cannot read or create work', async ({ request }) => {
  expect([401, 403]).toContain(
    (await request.get('/wp-json/blueworx-forge/v1/work-items?client_site_id=cst_x')).status(),
  );

  const created = await request.post('/wp-json/blueworx-forge/v1/work-items', {
    data: { client_site_id: 'cst_x', title: 'Trespass', level: 'project', work_type: 'task' },
  });
  expect([401, 403]).toContain(created.status());
});

test('work is created at Future Idea and nowhere else', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'Board Co');

  const response = await makeItem(context.request, nonce, site.id, {
    title: `Rebuild the checkout ${RUN_ID}`,
    level: 'project',
    work_type: 'feature',
    // Offered, and ignored — the stage is not a field a caller may set.
    stage: 'in-development',
  });

  // Refused rather than quietly dropped: dropping it would let the caller
  // believe they had created work already in development.
  expect(response.status()).toBe(400);

  const clean = await makeItem(context.request, nonce, site.id, {
    title: `Rebuild the checkout ${RUN_ID}`,
    level: 'project',
    work_type: 'feature',
  });

  const { item } = await clean.json();
  expect(item.stage).toBe('future-idea');
  expect(item.cycle).toBe(1);

  await context.close();
});

test('creating an item records that it was created', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'History Co');

  const { item } = await (
    await makeItem(context.request, nonce, site.id, {
      title: `Tracked from the start ${RUN_ID}`,
      level: 'feature',
      work_type: 'feature',
    })
  ).json();

  const shown = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  expect(shown.history).toHaveLength(1);
  expect(shown.history[0].action).toBe('created');
  expect(shown.history[0].gate).toBe('G-CREATE');

  await context.close();
});

test('an item moves one stage at a time, and the move is recorded', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'Moving Co');

  let { item } = await (
    await makeItem(context.request, nonce, site.id, {
      title: `Walks the path ${RUN_ID}`,
      level: 'sub-feature',
      work_type: 'feature',
    })
  ).json();

  const moved = await advance(context.request, nonce, item, 'triage');
  expect(moved.status()).toBe(200);

  const body = await moved.json();
  item = body.item;

  expect(item.stage).toBe('triage');
  // Satisfying the gate wrote to the item too, so the version has moved more
  // than once. What matters is that the move itself moved it on.
  expect(item.record_version).toBeGreaterThan(1);

  // A feature leaves Triage for Documentation Period; Bug Tracking is not on
  // offer, because it is not a bug.
  expect(body.available).toEqual(['documentation-period']);

  const shown = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  expect(shown.history).toHaveLength(2);
  expect(shown.history[1].from_stage).toBe('future-idea');
  expect(shown.history[1].to_stage).toBe('triage');
  expect(shown.history[1].gate).toBe('G-FUTURE-IDEA');

  await context.close();
});

test('a jump that skips stages is refused, and changes nothing', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'No Shortcuts Co');

  const { item } = await (
    await makeItem(context.request, nonce, site.id, {
      title: `Tries to skip ${RUN_ID}`,
      level: 'sub-feature',
      work_type: 'feature',
    })
  ).json();

  const refused = await move(context.request, nonce, item, 'in-development');
  expect(refused.status()).toBe(409);

  const body = await refused.json();
  expect(body.data.available).toEqual(['triage']);

  // The point of #106: a refused move leaves the item and its history exactly
  // as they were. One entry, the creation, and still at the first stage.
  const shown = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  expect(shown.item.stage).toBe('future-idea');
  expect(shown.item.record_version).toBe(1);
  expect(shown.history).toHaveLength(1);

  await context.close();
});

test('only a bug goes through Bug Tracking', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'Bug Co');

  const bug = (
    await (
      await makeItem(context.request, nonce, site.id, {
        title: `A real bug ${RUN_ID}`,
        level: 'sub-feature',
        work_type: 'bug',
      })
    ).json()
  ).item;

  const feature = (
    await (
      await makeItem(context.request, nonce, site.id, {
        title: `Not a bug ${RUN_ID}`,
        level: 'sub-feature',
        work_type: 'feature',
      })
    ).json()
  ).item;

  const triagedBug = (await (await advance(context.request, nonce, bug, 'triage')).json()).item;
  const triagedFeature = (await (await advance(context.request, nonce, feature, 'triage')).json())
    .item;

  expect((await advance(context.request, nonce, triagedBug, 'bug-tracking')).status()).toBe(200);

  // #110. Refused for what it is, not for what it is missing: satisfying the
  // gate first proves the refusal is about the work type and nothing else.
  const refused = await advance(context.request, nonce, triagedFeature, 'bug-tracking');
  expect(refused.status()).toBe(409);
  expect((await refused.json()).code).toBe('bwx_forge_stage_not_for_type');

  await context.close();
});

test('a parent has to be a higher level than the work beneath it', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'Hierarchy Co');

  const project = (
    await (
      await makeItem(context.request, nonce, site.id, {
        title: `The project ${RUN_ID}`,
        level: 'project',
        work_type: 'feature',
      })
    ).json()
  ).item;

  // A level may be skipped: a feature straight under a project is ordinary.
  const skipped = await makeItem(context.request, nonce, site.id, {
    title: `Skips milestone ${RUN_ID}`,
    level: 'feature',
    work_type: 'feature',
    parent_id: project.id,
  });
  expect(skipped.status()).toBe(200);

  // Equal levels cannot parent each other — that is the case that makes a cycle.
  const sameLevel = await makeItem(context.request, nonce, site.id, {
    title: `Another project ${RUN_ID}`,
    level: 'project',
    work_type: 'feature',
    parent_id: project.id,
  });
  expect(sameLevel.status()).toBe(400);

  await context.close();
});

test("work cannot take a parent from another client's site", async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const mine = await makeSite(context.request, nonce, 'Mine');
  const theirs = await makeSite(context.request, nonce, 'Theirs');

  const theirProject = (
    await (
      await makeItem(context.request, nonce, theirs.id, {
        title: `Their project ${RUN_ID}`,
        level: 'project',
        work_type: 'feature',
      })
    ).json()
  ).item;

  const refused = await makeItem(context.request, nonce, mine.id, {
    title: `Reaches across ${RUN_ID}`,
    level: 'feature',
    work_type: 'feature',
    parent_id: theirProject.id,
  });

  // An item whose parent sits on another site is reachable from two tenants at
  // once. Answered as "no such parent", so the refusal confirms nothing about
  // what exists elsewhere.
  expect(refused.status()).toBe(404);

  await context.close();
});

test('a bug can stand alone, under nothing at all', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'Loose Bug Co');

  const response = await makeItem(context.request, nonce, site.id, {
    title: `Nobody's bug ${RUN_ID}`,
    level: 'sub-feature',
    work_type: 'bug',
  });

  expect(response.status()).toBe(200);
  expect((await response.json()).item.parent_id).toBe('');

  await context.close();
});

test('the board reads one site and never another', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const one = await makeSite(context.request, nonce, 'Site One');
  const two = await makeSite(context.request, nonce, 'Site Two');

  await makeItem(context.request, nonce, one.id, {
    title: `Belongs to one ${RUN_ID}`,
    level: 'feature',
    work_type: 'task',
  });
  await makeItem(context.request, nonce, two.id, {
    title: `Belongs to two ${RUN_ID}`,
    level: 'feature',
    work_type: 'task',
  });

  const listed = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/work-items?client_site_id=${one.id}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  expect(listed.items).toHaveLength(1);
  expect(listed.items[0].client_site_id).toBe(one.id);

  await context.close();
});

test('an edit changes the fields and never the stage', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'Editing Co');

  const { item } = await (
    await makeItem(context.request, nonce, site.id, {
      title: `Before ${RUN_ID}`,
      level: 'feature',
      work_type: 'feature',
    })
  ).json();

  const edited = await context.request.patch(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      title: `After ${RUN_ID}`,
      problem: 'Checkout drops the basket on payment failure.',
      priority: 'high',
      record_version: item.record_version,
    },
  });

  expect(edited.status()).toBe(200);
  const body = await edited.json();
  expect(body.item.title).toBe(`After ${RUN_ID}`);
  expect(body.item.priority).toBe('high');
  expect(body.item.stage).toBe('future-idea');

  const sneaky = await context.request.patch(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { stage: 'completed', record_version: body.item.record_version },
  });
  expect(sneaky.status()).toBe(400);

  await context.close();
});

test('an edit made against a version that has moved is refused', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'Racing Co');

  const { item } = await (
    await makeItem(context.request, nonce, site.id, {
      title: `Raced ${RUN_ID}`,
      level: 'feature',
      work_type: 'feature',
    })
  ).json();

  const first = await context.request.patch(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { title: `First ${RUN_ID}`, record_version: item.record_version },
  });
  expect(first.status()).toBe(200);

  const stale = await context.request.patch(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { title: `Second ${RUN_ID}`, record_version: item.record_version },
  });
  expect(stale.status()).toBe(409);

  // And the same rule on a move: two people dragging one card, one of them wins.
  const staleMove = await move(context.request, nonce, item, 'triage');
  expect(staleMove.status()).toBe(409);

  await context.close();
});

test('a due date before its start is refused', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'Dates Co');

  const refused = await makeItem(context.request, nonce, site.id, {
    title: `Backwards dates ${RUN_ID}`,
    level: 'feature',
    work_type: 'task',
    planned_start: '2026-09-10',
    planned_due: '2026-09-01',
  });

  expect(refused.status()).toBe(400);

  const impossible = await makeItem(context.request, nonce, site.id, {
    title: `Thirtieth of February ${RUN_ID}`,
    level: 'feature',
    work_type: 'task',
    planned_due: '2026-02-30',
  });

  expect(impossible.status()).toBe(400);

  await context.close();
});

test('an item walks the whole path to Released', async ({ browser, baseURL }) => {
  // Nine gates, each with its own requirements to satisfy first.
  test.slow();

  const { context, nonce } = await signedInContext(browser, baseURL);
  const site = await makeSite(context.request, nonce, 'End To End Co');

  let item = (
    await (
      await makeItem(context.request, nonce, site.id, {
        title: `All the way ${RUN_ID}`,
        level: 'sub-feature',
        work_type: 'feature',
      })
    ).json()
  ).item;

  const path = [
    'triage',
    'documentation-period',
    'technical-audit',
    'design-process',
    'up-next',
    'in-development',
    'in-review',
    'completed',
    'released',
  ];

  for (const stage of path) {
    const response = await advance(context.request, nonce, item, stage);
    expect(response.status(), `moving to ${stage}`).toBe(200);
    item = (await response.json()).item;
    expect(item.stage).toBe(stage);
  }

  // Released is the end of the forward path.
  const shown = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  expect(shown.available).toEqual([]);
  expect(shown.history).toHaveLength(path.length + 1);

  await context.close();
});
