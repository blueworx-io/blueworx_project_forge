import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import * as Forge from './helpers/forge.js';

// The board screen (#117, #118, #119, #122). These drive the real app against a
// real WordPress: the columns come from the stage registry, a drag is a
// transition, and a refused move puts the card back rather than leaving the
// board showing something that never happened.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function seed(page, label) {
  return page.evaluate(async ({ label, runId }) => {
    const nonce = window.bwxForgeData.nonce;
    const base = window.bwxForgeData.restUrl.replace(/\/$/, '');

    const post = async (path, body) => {
      const response = await fetch(`${base}${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        credentials: 'same-origin',
        body: JSON.stringify(body),
      });
      return response.json();
    };

    const client = await post('/clients', {
      display_name: `${label} ${runId}`,
      timezone: 'Europe/London',
    });
    const site = await post(`/clients/${client.client.id}/sites`, {
      name: `${label} site ${runId}`,
      url: 'https://example.test',
    });
    const item = await post('/work-items', {
      client_site_id: site.site.id,
      title: `Board item ${runId}`,
      problem: 'Something needs doing.',
      level: 'feature',
      work_type: 'feature',
    });

    return { siteId: site.site.id, siteName: site.site.name, itemId: item.item.id, clientId: client.client.id };
  }, { label, runId: RUN_ID });
}

/*
 * Since #105 a card does not move because somebody dragged it — it moves
 * because the stage's gate is satisfied. G-FUTURE-IDEA wants the problem
 * (which seed() writes) and three recorded completions, so a test about
 * dragging has to do them first or it is only testing the refusal.
 */
async function readyForTriage(page, itemId, clientId) {
  // The two people Triage wants named (2026-09-19).
  const nonce = await page.evaluate(() => window.bwxForgeData.nonce);
  const api = Forge.forge(page.context().request, nonce);
  const seats = await Forge.seatsFor(api, { client_id: clientId });
  const current = (await api.get(`/work-items/${itemId}`)).item;
  const seated = await api.patch(`/work-items/${itemId}`, {
    primary_user_id: seats.primary_user_id,
    reviewer_id: seats.reviewer_id,
    record_version: current.record_version,
  });
  expect(seated.status(), await seated.text()).toBe(200);
  await Forge.confirmClient(api, { id: itemId });

  await page.evaluate(async (id) => {
    const nonce = window.bwxForgeData.nonce;
    const base = window.bwxForgeData.restUrl.replace(/\/$/, '');

    for (const requirement of ['G-FUTURE-IDEA-2', 'G-FUTURE-IDEA-3', 'G-FUTURE-IDEA-4']) {
      await fetch(`${base}/work-items/${id}/gate`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        credentials: 'same-origin',
        body: JSON.stringify({ requirement, value: 'Confirmed.' }),
      });
    }
  }, itemId);
}

async function openBoardOn(page, siteId) {
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-client-choice"]', siteId);
  await expect(page.locator('[data-testid="bwx-card"]')).toHaveCount(1);
}

test.describe('the board', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page);
    await page.goto('/blueworx-forge/');
  });

  test('draws a column for every stage on the linear path', async ({ page }) => {
    await page.waitForSelector('[data-testid="bwx-board"]');

    const columns = page.locator('[data-testid="bwx-column"]');
    await expect(columns).toHaveCount(9);

    // The first column is where new work lands, and the last is the end of the
    // road. Both are fixed by the state machine, not by this screen.
    await expect(columns.first()).toHaveAttribute('data-stage', 'future-idea');
    await expect(columns.last()).toHaveAttribute('data-stage', 'released');

    // #511. Up Next, then Design Process, then In Development: work is planned
    // and booked before design starts.
    const order = await columns.evaluateAll((all) => all.map((one) => one.getAttribute('data-stage')));
    expect(order.indexOf('design-process')).toBe(order.indexOf('up-next') + 1);
    expect(order.indexOf('in-development')).toBe(order.indexOf('design-process') + 1);

    // Blocked and Bug Tracking are stages but not columns: one is somewhere an
    // item goes from wherever it is, the other only exists for bugs.
    await expect(page.locator('[data-stage="blocked"][data-testid="bwx-column"]')).toHaveCount(0);
    await expect(page.locator('[data-stage="bug-tracking"][data-testid="bwx-column"]')).toHaveCount(0);
  });

  test("Design Process sits under In delivery, with that group's colour", async ({ page }) => {
    await page.waitForSelector('[data-testid="bwx-board"]');

    const head = (stage) => page.locator(`[data-testid="bwx-column"][data-stage="${ stage }"] .bwx-column-head`);
    await expect(head('design-process')).toHaveAttribute('data-phase', 'pipeline');

    // Design Process, Up Next, In Development and In Review make one band.
    await expect(page.locator('.bwx-phase', { hasText: 'In delivery' })).toHaveCount(1);
  });

  test('new work appears in the first column',async ({ page }) => {
    const { siteId } = await seed(page, 'Board');
    await openBoardOn(page, siteId);

    const card = page.locator('[data-testid="bwx-card"]');
    await expect(card).toHaveAttribute('data-stage', 'future-idea');
    await expect(page.locator('[data-stage="future-idea"] [data-testid="bwx-column-count"]')).toHaveText('1');
  });

  test('dragging a card to the next column moves the work', async ({ page }) => {
    const { siteId, itemId, clientId } = await seed(page, 'Drag');
    await readyForTriage(page, itemId, clientId);
    await openBoardOn(page, siteId);

    await page
      .locator('[data-testid="bwx-card"]')
      .dragTo(page.locator('[data-stage="triage"][data-testid="bwx-column"]'));

    await expect(page.locator('[data-testid="bwx-card"]')).toHaveAttribute('data-stage', 'triage');

    // And it is a real move, not a repositioned card: it survives a reload.
    await page.reload();
    await page.waitForSelector('[data-testid="bwx-board"]');
    await page.selectOption('[data-testid="bwx-client-choice"]', siteId);
    await expect(page.locator('[data-testid="bwx-card"]')).toHaveAttribute('data-stage', 'triage');
  });

  test('two quick moves both stay, and a read from before the second cannot undo it (#613)', async ({ page }) => {
    test.slow();

    const { siteId, itemId: first, clientId } = await seed(page, 'Quick');
    const nonce = await page.evaluate(() => window.bwxForgeData.nonce);
    const api = Forge.forge(page.context().request, nonce);
    const second = (await (await Forge.makeItem(api, siteId, { title: `Second card ${RUN_ID}`, level: 'feature' })).json()).item.id;
    await readyForTriage(page, first, clientId);
    await readyForTriage(page, second, clientId);

    await page.goto('/blueworx-forge/');
    await page.waitForSelector('[data-testid="bwx-board"]');
    await page.selectOption('[data-testid="bwx-client-choice"]', siteId);
    const card = (id) => page.locator(`[data-testid="bwx-card"][data-item="${id}"]`);
    await expect(card(second)).toBeVisible({ timeout: 60_000 });

    // From here, each read of the board is taken as the server answers it, then held.
    const held = [];
    await page.route((url) => url.pathname.endsWith('/work-items') && url.search.includes('client_site_id='), async (route) => {
      if ('GET' !== route.request().method()) return route.fallback();
      held.push({ route, answer: await route.fetch() });
    });

    // The second card's move waits until the first move's read has been taken.
    let letSecondGo;
    const secondMay = new Promise((resolve) => { letSecondGo = resolve; });
    await page.route(`**/work-items/${second}/transition`, async (route) => {
      await secondMay;
      await route.continue();
    });

    const triage = page.locator('[data-stage="triage"][data-testid="bwx-column"]');
    await card(first).dragTo(triage);
    // The card settles without waiting for the board to be read again.
    await expect(card(first)).toHaveAttribute('data-stage', 'triage');
    await expect.poll(() => held.length, { timeout: 60_000 }).toBeGreaterThan(0);

    const moved = page.waitForResponse((response) => response.url().includes(`/work-items/${second}/transition`));
    await card(second).dragTo(triage);
    letSecondGo();
    expect((await moved).status()).toBe(200);

    // The read from before the second move arrives: it must not put that card back.
    await held[0].route.fulfill({ response: held[0].answer });
    await page.waitForTimeout(500);
    await expect(card(second)).toHaveAttribute('data-stage', 'triage');
    await expect(card(first)).toHaveAttribute('data-stage', 'triage');

    await page.unrouteAll({ behavior: 'ignoreErrors' });
    for (const one of held.slice(1)) await one.route.fulfill({ response: one.answer }).catch(() => undefined);

    await page.reload();
    await page.waitForSelector('[data-testid="bwx-board"]');
    await page.selectOption('[data-testid="bwx-client-choice"]', siteId);
    await expect(card(first)).toHaveAttribute('data-stage', 'triage');
    await expect(card(second)).toHaveAttribute('data-stage', 'triage');
  });

  test('Released shows the last two weeks, and a long column the first thirty, with the rest a click away (#616)', async ({ page }) => {
    test.slow();

    const { siteId } = await seed(page, 'Long');
    const now = Math.floor(Date.now() / 1000);

    // The site's one card, as forty: three released this week, five long ago, and thirty-two at Triage.
    await page.route((url) => url.pathname.endsWith('/work-items') && url.search.includes(`client_site_id=${siteId}`), async (route) => {
      if ('GET' !== route.request().method()) return route.fallback();
      const response = await route.fetch();
      const body = await response.json();
      const [ one ] = body.items;
      const copy = (n, stage, releasedAt) => ({ ...one, id: `${one.id}x${n}`, title: `Copy ${n}`, stage, released_at: releasedAt, next_step: null });
      body.items = [
        ...[0, 1, 2].map((n) => copy(n, 'released', now - n * 86400)),
        ...[3, 4, 5, 6, 7].map((n) => copy(n, 'released', now - 40 * 86400)),
        ...Array.from({ length: 32 }, (_, n) => copy(100 + n, 'triage', 0)),
      ];
      await route.fulfill({ response, json: body });
    });

    await page.goto('/blueworx-forge/');
    await page.waitForSelector('[data-testid="bwx-board"]');
    await page.selectOption('[data-testid="bwx-client-choice"]', siteId);

    const column = (stage) => page.locator(`[data-testid="bwx-column"][data-stage="${stage}"]`);
    await expect(column('released').getByTestId('bwx-card')).toHaveCount(3, { timeout: 60_000 });
    await expect(column('released').getByTestId('bwx-column-count')).toHaveText('8');
    // Newest first.
    await expect(column('released').getByTestId('bwx-card').first()).toContainText('Copy 0');

    const older = column('released').getByTestId('bwx-column-more');
    await expect(older).toHaveText('Show older (5)');
    await older.click();
    await expect(column('released').getByTestId('bwx-card')).toHaveCount(8);
    await expect(older).toHaveText('Show fewer');

    await expect(column('triage').getByTestId('bwx-card')).toHaveCount(30);
    await column('triage').getByTestId('bwx-column-more').click();
    await expect(column('triage').getByTestId('bwx-card')).toHaveCount(32);
  });

  test('a move the workflow refuses puts the card back and says why', async ({ page }) => {
    const { siteId } = await seed(page, 'Refused');
    await openBoardOn(page, siteId);

    // Future Idea straight to Documentation Period skips Triage, which is where
    // the first gate is.
    await page
      .locator('[data-testid="bwx-card"]')
      .dragTo(page.locator('[data-stage="documentation-period"][data-testid="bwx-column"]'));

    await expect(page.locator('[data-testid="bwx-notice"]')).toBeVisible();
    await expect(page.locator('[data-testid="bwx-card"]')).toHaveAttribute('data-stage', 'future-idea');
  });

  test('the panel opens on a card, moves it, and shows the history', async ({ page }) => {
    const { siteId, itemId, clientId } = await seed(page, 'Panel');
    await readyForTriage(page, itemId, clientId);
    await openBoardOn(page, siteId);

    await page.locator('[data-testid="bwx-card"]').click();
    await expect(page.locator('[data-testid="bwx-panel"]')).toBeVisible();
    await expect(page.locator('[data-testid="bwx-panel-stage"]')).toHaveText('Future idea');

    // Three entries to begin with: the item being created, the two people
    // named for it (2026-09-19), and its client confirmed (#390).
    await expect(page.locator('[data-testid="bwx-history"] li')).toHaveCount(3);

    await page.locator('[data-testid="bwx-move"]').first().click();

    await expect(page.locator('[data-testid="bwx-panel-stage"]')).not.toHaveText('Future idea', { timeout: 30_000 });
    await expect(page.locator('[data-testid="bwx-history"] li')).toHaveCount(4);
  });

  test('the panel saves an edit', async ({ page }) => {
    const { siteId } = await seed(page, 'Edit');
    await openBoardOn(page, siteId);

    await page.locator('[data-testid="bwx-card"]').click();
    await page.fill('#bwx-title', `Renamed ${RUN_ID}`);
    await page.locator('[data-testid="bwx-save"]').click();

    await expect(page.locator('[data-testid="bwx-panel-notice"]')).toHaveText('Saved.', { timeout: 30_000 });
    await expect(page.locator('[data-testid="bwx-card"]')).toContainText(`Renamed ${RUN_ID}`);
  });

  test('a site with no work says so, and still shows what happens to work', async ({ page }) => {
    // #125. An empty board is drawn — the nine columns are the answer to "what
    // happens to work here" — with the emptiness said out loud above it rather
    // than left to be inferred.
    const { siteId, itemId } = await seed(page, 'Empty');

    // Cancel the only item and archive it, so the site genuinely has none.
    await page.evaluate(async (id) => {
      const nonce = window.bwxForgeData.nonce;
      const base = window.bwxForgeData.restUrl.replace(/\/$/, '');
      const post = (path, body) =>
        fetch(`${base}${path}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
          credentials: 'same-origin',
          body: JSON.stringify(body),
        }).then((r) => r.json());

      const read = await fetch(`${base}/work-items/${id}`, {
        headers: { 'X-WP-Nonce': nonce },
        credentials: 'same-origin',
      }).then((r) => r.json());

      const ended = await post(`/work-items/${id}/outcome`, {
        outcome: 'cancelled',
        reason: 'Not needed after all.',
        record_version: read.item.record_version,
      });

      await post(`/work-items/${id}/archive`, { record_version: ended.item.record_version });
    }, itemId);

    await page.goto('/blueworx-forge/');
    await page.waitForSelector('[data-testid="bwx-board"]');
    await page.selectOption('[data-testid="bwx-client-choice"]', siteId);

    const state = page.locator('[data-testid="bwx-state"]');
    await expect(state).toHaveAttribute('data-state', 'empty');
    await expect(state).toContainText(/Nothing for Empty .+ here\./);

    // Empty, not denied. The two are told apart deliberately.
    await expect(state).not.toHaveAttribute('data-state', 'denied');
    await expect(page.locator('[data-testid="bwx-column"]')).toHaveCount(9);
  });

  test('somebody without access is told that, not shown an empty board', async ({ page }) => {
    // #125's other half. "Nothing here" and "not yours to see" look identical
    // as a blank screen and mean completely different things.
    const login = `visitor${Date.now()}`;

    await page.goto('/blueworx-forge/');
    const created = await page.evaluate(async (user) => {
      const response = await fetch('/wp-json/wp/v2/users', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.bwxForgeData.nonce },
        credentials: 'same-origin',
        body: JSON.stringify({
          username: user,
          email: `${user}@example.test`,
          password: 'visitor-pw-9931',
          roles: ['subscriber'],
        }),
      });

      return { status: response.status, body: await response.json() };
    }, login);

    expect(created.status, JSON.stringify(created.body)).toBe(201);

    await page.context().clearCookies();

    await signIn(page, login, 'visitor-pw-9931');

    // Since #406 an account that is neither an administrator nor a Manager is
    // turned away at the door, before any board is drawn.
    const response = await page.goto('/blueworx-forge/');
    expect(response?.status()).toBe(403);
    await expect(page.locator('[data-testid="bwx-board"]')).toHaveCount(0);
  });

  test('work can be added from the board', async ({ page }) => {
    const { siteId } = await seed(page, 'Add');
    await openBoardOn(page, siteId);

    await page.locator('[data-testid="bwx-add"]').click();
    await page.fill('#bwx-new-title', `Added ${RUN_ID}`);
    await page.fill('#bwx-new-problem', 'It came from the board.');
    await page.locator('[data-testid="bwx-create"]').click();

    await expect(page.locator('[data-testid="bwx-new-work"]')).toHaveCount(0);
    await expect(page.locator('[data-testid="bwx-card"]')).toHaveCount(2);
  });
});
