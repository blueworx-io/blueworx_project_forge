import { test, expect } from '@playwright/test';

// The workflow proper (#105, #107, #108, #109, #110, #111) and the discussion
// attached to it (#100), against a real WordPress. The unit tests prove the
// rules; these prove the routes exist, the tables hold what the rules write,
// and — the ones that matter — that a refusal lists everything, that a returned
// item keeps its earlier review attempt, and that blocked work comes back to
// exactly where it left.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const BASE = '/wp-json/blueworx-forge/v1';

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

/** One caller, so every helper below reads the same. */
function forge(request, nonce) {
  const headers = { 'X-WP-Nonce': nonce };

  return {
    get: (path) => request.get(`${BASE}${path}`, { headers }).then((r) => r.json()),
    post: (path, data) => request.post(`${BASE}${path}`, { headers, data }),
    patch: (path, data) => request.patch(`${BASE}${path}`, { headers, data }),
  };
}

async function makeSite(api, label) {
  const client = await (
    await api.post('/clients', { display_name: `${label} ${RUN_ID}`, timezone: 'Europe/London' })
  ).json();
  const site = await (
    await api.post(`/clients/${client.client.id}/sites`, {
      name: `${label} site ${RUN_ID}`,
      url: 'https://example.test',
    })
  ).json();

  return site.site;
}

async function makeItem(api, siteId, data) {
  const created = await api.post('/work-items', {
    client_site_id: siteId,
    level: 'sub-feature',
    work_type: 'feature',
    ...data,
  });

  return (await created.json()).item;
}

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

/** Does whatever the gate asks for, the way a person would. */
async function satisfy(api, item, to) {
  const detail = await api.get(`/work-items/${item.id}`);
  const patch = {};

  for (const requirement of detail.readiness[to]?.unmet ?? []) {
    if ('field' === requirement.by) {
      for (const field of requirement.fields) {
        patch[field] = answerFor(field);
      }
      continue;
    }

    if ('record' === requirement.by) {
      await api.post(`/work-items/${item.id}/gate`, {
        requirement: requirement.id,
        value: 'Done.',
        evidence: requirement.evidence ? 'https://example.test/evidence' : '',
      });
    }
  }

  if (0 < Object.keys(patch).length) {
    const current = await api.get(`/work-items/${item.id}`);
    await api.patch(`/work-items/${item.id}`, {
      ...patch,
      record_version: current.item.record_version,
    });
  }

  return (await api.get(`/work-items/${item.id}`)).item;
}

/** Walks an item up the path, satisfying each gate on the way. */
async function walkTo(api, item, stages) {
  let current = item;

  for (const stage of stages) {
    current = await satisfy(api, current, stage);

    const moved = await api.post(`/work-items/${current.id}/transition`, {
      to: stage,
      record_version: current.record_version,
    });

    expect(moved.status(), `moving to ${stage}`).toBe(200);
    current = (await moved.json()).item;
  }

  return current;
}

test('a refused move names every unmet requirement and moves nothing', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Gate Co');

  // Nothing filled in but the title, so the first gate has plenty to say.
  const item = await makeItem(api, site.id, { title: `Bare idea ${RUN_ID}` });

  const refused = await api.post(`/work-items/${item.id}/transition`, {
    to: 'triage',
    record_version: item.record_version,
  });

  expect(refused.status()).toBe(409);

  const body = await refused.json();

  // The documented gate-failure body, not an error envelope.
  expect(body.ok).toBe(false);
  expect(body.stage).toBe('future-idea');
  expect(body.attempted).toBe('triage');

  // Every one of them, not the first. Four requirements, four entries.
  expect(body.unmet.length).toBeGreaterThanOrEqual(3);
  expect(body.unmet.map((each) => each.id)).toContain('G-FUTURE-IDEA-1');
  expect(body.unmet[0].satisfied_by).toBeTruthy();

  const after = await api.get(`/work-items/${item.id}`);
  expect(after.item.stage).toBe('future-idea');
  expect(after.item.record_version).toBe(item.record_version);
  expect(after.history).toHaveLength(1);

  await context.close();
});

test('a completion is refused without a signed-in person and without its evidence', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Record Co');
  const item = await makeItem(api, site.id, { title: `Recorded ${RUN_ID}`, work_type: 'bug' });

  // Nobody signed in at all: the record has no actor to carry.
  const anonymous = await context.request.post(`${BASE}/work-items/${item.id}/gate`, {
    data: { requirement: 'G-FUTURE-IDEA-2', value: 'Confirmed.' },
  });
  expect([401, 403]).toContain(anonymous.status());

  // Signed in, but a requirement whose specification says "Evidence: Yes"
  // is not satisfied by somebody ticking it.
  const bare = await api.post(`/work-items/${item.id}/gate`, {
    requirement: 'G-BUG-TRACKING-5',
    value: 'Saw it happen.',
  });
  expect(bare.status()).toBe(400);

  const withEvidence = await api.post(`/work-items/${item.id}/gate`, {
    requirement: 'G-BUG-TRACKING-5',
    value: 'Saw it happen.',
    evidence: 'https://example.test/screenshot.png',
  });
  expect(withEvidence.status()).toBe(200);

  const record = (await withEvidence.json()).record;
  expect(record.actor).toBeGreaterThan(0);
  expect(record.completed_at).toBeGreaterThan(0);

  await context.close();
});

test('work goes back only to a stage it has been in, and only with a reason', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Return Co');

  let item = await makeItem(api, site.id, { title: `Goes back ${RUN_ID}` });
  item = await walkTo(api, item, ['triage', 'documentation-period', 'technical-audit']);

  const detail = await api.get(`/work-items/${item.id}`);
  expect(detail.returns).toEqual(['future-idea', 'triage', 'documentation-period']);

  // No reason, no return. WF-3, without exception.
  const silent = await api.post(`/work-items/${item.id}/return`, {
    to: 'triage',
    record_version: item.record_version,
  });
  expect(silent.status()).toBe(400);

  // A stage it has never occupied is a correction, not a return.
  const invented = await api.post(`/work-items/${item.id}/return`, {
    to: 'design-process',
    reason: 'Because.',
    record_version: item.record_version,
  });
  expect(invented.status()).toBe(409);

  const sent = await api.post(`/work-items/${item.id}/return`, {
    to: 'triage',
    reason: 'The scope was wrong.',
    record_version: item.record_version,
  });
  expect(sent.status()).toBe(200);
  expect((await sent.json()).item.stage).toBe('triage');

  const after = await api.get(`/work-items/${item.id}`);
  const returned = after.history[after.history.length - 1];
  expect(returned.action).toBe('returned');
  expect(returned.reason).toBe('The scope was wrong.');

  await context.close();
});

test('a failed review keeps the review attempt that failed', async ({ browser, baseURL }) => {
  // Seven gates' worth of requirements before the review even starts, one
  // request each against a single-threaded PHP server.
  test.slow();

  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Review Co');

  let item = await makeItem(api, site.id, { title: `Reviewed twice ${RUN_ID}` });
  item = await walkTo(api, item, [
    'triage',
    'documentation-period',
    'technical-audit',
    'design-process',
    'up-next',
    'in-development',
    'in-review',
  ]);

  // The reviewer gets part way through, then sends it back.
  await api.post(`/work-items/${item.id}/gate`, {
    requirement: 'G-IN-REVIEW-1',
    value: 'Checked the first half.',
  });

  const bounced = await api.post(`/work-items/${item.id}/return`, {
    to: 'in-development',
    reason: 'The empty state is missing.',
    feedback: 'Two of the acceptance criteria are not met, and there is no empty state.',
    record_version: item.record_version,
  });
  expect(bounced.status()).toBe(200);

  const after = await api.get(`/work-items/${item.id}`);
  expect(after.item.stage).toBe('in-development');
  expect(after.item.review_attempt).toBe(2);

  // The earlier attempt is still on the item, and no longer counts towards the
  // next review — which is the whole of #108's second acceptance.
  expect(after.records['G-IN-REVIEW-1']).toBeUndefined();

  const feedback = after.history[after.history.length - 1];
  expect(feedback.detail).toContain('no empty state');

  await context.close();
});

test('a review that sends work back has to say what was wrong', async ({ browser, baseURL }) => {
  test.slow();

  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Feedback Co');

  let item = await makeItem(api, site.id, { title: `Needs feedback ${RUN_ID}` });
  item = await walkTo(api, item, [
    'triage',
    'documentation-period',
    'technical-audit',
    'design-process',
    'up-next',
    'in-development',
    'in-review',
  ]);

  const silent = await api.post(`/work-items/${item.id}/return`, {
    to: 'in-development',
    reason: 'Not good enough.',
    record_version: item.record_version,
  });

  expect(silent.status()).toBe(400);
  expect((await silent.json()).code).toBe('bwx_forge_feedback_required');

  await context.close();
});

test('blocked work keeps its place and comes back to exactly it', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Blocked Co');

  let item = await makeItem(api, site.id, { title: `Waiting on somebody ${RUN_ID}` });
  item = await walkTo(api, item, ['triage', 'documentation-period']);

  // Every one of the five answers, or it is not blocked, it is just late.
  const half = await api.post(`/work-items/${item.id}/block`, {
    reason: 'Waiting on the client.',
    record_version: item.record_version,
  });
  expect(half.status()).toBe(409);
  expect((await half.json()).unmet.map((each) => each.id)).toContain('G-BLOCKED-ENTRY-2');

  const blocked = await api.post(`/work-items/${item.id}/block`, {
    reason: 'Waiting on the client.',
    owner: 'Jo',
    dependency: 'Their sign-off on the copy.',
    target_date: '2026-09-15',
    next_action: 'Chase on Monday.',
    record_version: item.record_version,
  });
  expect(blocked.status()).toBe(200);

  const paused = (await blocked.json()).item;
  expect(paused.stage).toBe('blocked');
  expect(paused.prior_stage).toBe('documentation-period');

  // A blocked item is not sent forward, and not sent back either.
  const forward = await api.post(`/work-items/${item.id}/transition`, {
    to: 'technical-audit',
    record_version: paused.record_version,
  });
  expect(forward.status()).toBe(409);

  // No resolution note, no way out.
  const unresolved = await api.post(`/work-items/${item.id}/unblock`, {
    record_version: paused.record_version,
  });
  expect(unresolved.status()).toBe(409);

  const resumed = await api.post(`/work-items/${item.id}/unblock`, {
    resolution: 'They signed it off.',
    record_version: paused.record_version,
  });
  expect(resumed.status()).toBe(200);

  const back = (await resumed.json()).item;
  expect(back.stage).toBe('documentation-period');
  expect(back.prior_stage).toBe('');

  await context.close();
});

test('a non-bug cannot reach Bug Tracking by any route', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Conditional Co');

  let feature = await makeItem(api, site.id, { title: `Not a bug ${RUN_ID}` });
  feature = await walkTo(api, feature, ['triage', 'documentation-period']);

  // Forward.
  const forward = await api.post(`/work-items/${feature.id}/transition`, {
    to: 'bug-tracking',
    record_version: feature.record_version,
  });
  expect(forward.status()).toBe(409);

  // Backwards — the route that would not have known to check.
  const backwards = await api.post(`/work-items/${feature.id}/return`, {
    to: 'bug-tracking',
    reason: 'Sneaking in.',
    record_version: feature.record_version,
  });
  expect(backwards.status()).toBe(409);

  // And it is never offered.
  const detail = await api.get(`/work-items/${feature.id}`);
  expect(detail.available).not.toContain('bug-tracking');
  expect(detail.returns).not.toContain('bug-tracking');

  await context.close();
});

test('each terminal outcome is reachable only from where it is permitted', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Outcome Co');

  // Rejection belongs to Triage. An idea nobody has triaged cannot be rejected.
  const idea = await makeItem(api, site.id, { title: `Too early to reject ${RUN_ID}` });
  const early = await api.post(`/work-items/${idea.id}/outcome`, {
    outcome: 'rejected',
    reason: 'No.',
    record_version: idea.record_version,
  });
  expect(early.status()).toBe(409);

  const triaged = await walkTo(api, idea, ['triage']);

  // A rejection with no reason is not a decision, it is a shrug.
  const silent = await api.post(`/work-items/${triaged.id}/outcome`, {
    outcome: 'rejected',
    record_version: triaged.record_version,
  });
  expect(silent.status()).toBe(400);

  const rejected = await api.post(`/work-items/${triaged.id}/outcome`, {
    outcome: 'rejected',
    reason: 'Out of scope for this client.',
    record_version: triaged.record_version,
  });
  expect(rejected.status()).toBe(200);

  const ended = (await rejected.json()).item;
  expect(ended.terminal_outcome).toBe('rejected');

  // Ended work stops looking active: it does not move again.
  const moved = await api.post(`/work-items/${ended.id}/transition`, {
    to: 'documentation-period',
    record_version: ended.record_version,
  });
  expect(moved.status()).toBe(409);
  expect((await moved.json()).code).toBe('bwx_forge_work_ended');

  await context.close();
});

test('deferring puts work back to Future Idea and leaves it open', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Deferred Co');

  let item = await makeItem(api, site.id, { title: `Not this quarter ${RUN_ID}` });
  item = await walkTo(api, item, ['triage']);

  const deferred = await api.post(`/work-items/${item.id}/outcome`, {
    outcome: 'deferred',
    reason: 'Revisit after the migration.',
    record_version: item.record_version,
  });
  expect(deferred.status()).toBe(200);

  const back = (await deferred.json()).item;
  expect(back.stage).toBe('future-idea');
  expect(back.terminal_outcome).toBe('deferred');

  // Still open: it can be picked up again without anybody reopening anything.
  const detail = await api.get(`/work-items/${back.id}`);
  expect(detail.available).toEqual(['triage']);

  await context.close();
});

test('archived work leaves the default view and stays in the reports', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Archive Co');

  let item = await makeItem(api, site.id, { title: `Filed away ${RUN_ID}` });
  item = await walkTo(api, item, ['triage']);

  // Only ended work can be archived.
  const tooSoon = await api.post(`/work-items/${item.id}/archive`, {
    record_version: item.record_version,
  });
  expect(tooSoon.status()).toBe(409);

  const cancelled = (
    await (
      await api.post(`/work-items/${item.id}/outcome`, {
        outcome: 'cancelled',
        reason: 'The client withdrew it.',
        record_version: item.record_version,
      })
    ).json()
  ).item;

  const archived = await api.post(`/work-items/${item.id}/archive`, {
    record_version: cancelled.record_version,
  });
  expect(archived.status()).toBe(200);

  const board = await api.get(`/work-items?client_site_id=${site.id}`);
  expect(board.items.map((each) => each.id)).not.toContain(item.id);

  const everything = await api.get(`/work-items?client_site_id=${site.id}&include_archived=1`);
  expect(everything.items.map((each) => each.id)).toContain(item.id);

  await context.close();
});

test('an internal note is not reachable by a client, and a client comment is', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const api = forge(context.request, nonce);
  const site = await makeSite(api, 'Comment Co');
  const item = await makeItem(api, site.id, { title: `Talked about ${RUN_ID}` });

  const internal = await api.post(`/work-items/${item.id}/comments`, {
    body: 'This one is going to be a nightmare.',
    visibility: 'internal',
  });
  expect(internal.status()).toBe(200);
  expect((await internal.json()).comment.visibility).toBe('internal');

  const shared = await api.post(`/work-items/${item.id}/comments`, {
    body: 'We have started on this.',
    visibility: 'client',
  });
  expect(shared.status()).toBe(200);

  // Evidence with nothing to look at is a comment claiming to be evidence.
  const empty = await api.post(`/work-items/${item.id}/comments`, {
    kind: 'evidence',
    body: 'Trust me.',
  });
  expect(empty.status()).toBe(400);

  // Staff see both.
  const asStaff = await api.get(`/work-items/${item.id}/comments`);
  expect(asStaff.scope).toBe('staff');
  expect(asStaff.comments).toHaveLength(2);

  // And a stranger sees neither, by either route.
  expect([401, 403]).toContain(
    (await context.request.get(`${BASE}/work-items/${item.id}/comments`)).status(),
  );

  await context.close();
});
