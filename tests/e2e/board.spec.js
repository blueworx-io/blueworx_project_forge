import { test, expect } from '@playwright/test';

// The board screen (#117, #118, #119, #122). These drive the real app against a
// real WordPress: the columns come from the stage registry, a drag is a
// transition, and a refused move puts the card back rather than leaving the
// board showing something that never happened.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

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

    return { siteId: site.site.id, siteName: site.site.name, itemId: item.item.id };
  }, { label, runId: RUN_ID });
}

/*
 * Since #105 a card does not move because somebody dragged it — it moves
 * because the stage's gate is satisfied. G-FUTURE-IDEA wants the problem
 * (which seed() writes) and three recorded completions, so a test about
 * dragging has to do them first or it is only testing the refusal.
 */
async function readyForTriage(page, itemId) {
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
  await page.selectOption('[data-testid="bwx-site"]', siteId);
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
    await expect(columns).toHaveCount(10);

    // The first column is where new work lands, and the last is the end of the
    // road. Both are fixed by the state machine, not by this screen.
    await expect(columns.first()).toHaveAttribute('data-stage', 'future-idea');
    await expect(columns.last()).toHaveAttribute('data-stage', 'released');

    // Blocked and Bug Tracking are stages but not columns: one is somewhere an
    // item goes from wherever it is, the other only exists for bugs.
    await expect(page.locator('[data-stage="blocked"][data-testid="bwx-column"]')).toHaveCount(0);
    await expect(page.locator('[data-stage="bug-tracking"][data-testid="bwx-column"]')).toHaveCount(0);
  });

  test('new work appears in the first column', async ({ page }) => {
    const { siteId } = await seed(page, 'Board');
    await openBoardOn(page, siteId);

    const card = page.locator('[data-testid="bwx-card"]');
    await expect(card).toHaveAttribute('data-stage', 'future-idea');
    await expect(page.locator('[data-stage="future-idea"] [data-testid="bwx-column-count"]')).toHaveText('1');
  });

  test('dragging a card to the next column moves the work', async ({ page }) => {
    const { siteId, itemId } = await seed(page, 'Drag');
    await readyForTriage(page, itemId);
    await openBoardOn(page, siteId);

    await page
      .locator('[data-testid="bwx-card"]')
      .dragTo(page.locator('[data-stage="triage"][data-testid="bwx-column"]'));

    await expect(page.locator('[data-testid="bwx-card"]')).toHaveAttribute('data-stage', 'triage');

    // And it is a real move, not a repositioned card: it survives a reload.
    await page.reload();
    await page.waitForSelector('[data-testid="bwx-board"]');
    await page.selectOption('[data-testid="bwx-site"]', siteId);
    await expect(page.locator('[data-testid="bwx-card"]')).toHaveAttribute('data-stage', 'triage');
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
    const { siteId, itemId } = await seed(page, 'Panel');
    await readyForTriage(page, itemId);
    await openBoardOn(page, siteId);

    await page.locator('[data-testid="bwx-card"]').click();
    await expect(page.locator('[data-testid="bwx-panel"]')).toBeVisible();
    await expect(page.locator('[data-testid="bwx-panel-stage"]')).toHaveText('Future idea');

    // One entry to begin with: the item being created.
    await expect(page.locator('[data-testid="bwx-history"] li')).toHaveCount(1);

    await page.locator('[data-testid="bwx-move"]').first().click();

    await expect(page.locator('[data-testid="bwx-panel-stage"]')).not.toHaveText('Future idea');
    await expect(page.locator('[data-testid="bwx-history"] li')).toHaveCount(2);
  });

  test('the panel saves an edit', async ({ page }) => {
    const { siteId } = await seed(page, 'Edit');
    await openBoardOn(page, siteId);

    await page.locator('[data-testid="bwx-card"]').click();
    await page.fill('#bwx-title', `Renamed ${RUN_ID}`);
    await page.locator('[data-testid="bwx-save"]').click();

    await expect(page.locator('[data-testid="bwx-panel-notice"]')).toHaveText('Saved.');
    await expect(page.locator('[data-testid="bwx-card"]')).toContainText(`Renamed ${RUN_ID}`);
  });

  test('a site with no work says so, and still shows what happens to work', async ({ page }) => {
    // #125. An empty board is drawn — the ten columns are the answer to "what
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
    await page.selectOption('[data-testid="bwx-site"]', siteId);

    const state = page.locator('[data-testid="bwx-state"]');
    await expect(state).toHaveAttribute('data-state', 'empty');
    await expect(state).toContainText('No work on this site yet');

    // Empty, not denied. The two are told apart deliberately.
    await expect(state).not.toHaveAttribute('data-state', 'denied');
    await expect(page.locator('[data-testid="bwx-column"]')).toHaveCount(10);
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

    await page.goto('/wp-login.php');
    await page.fill('#user_login', login);
    await page.fill('#user_pass', 'visitor-pw-9931');
    await page.click('#wp-submit');
    await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

    await page.goto('/blueworx-forge/');

    const state = page.locator('[data-testid="bwx-state"]');
    await expect(state).toHaveAttribute('data-state', 'denied');
    await expect(state).toContainText('Not yours to see');
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
