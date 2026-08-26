import { test, expect } from '@playwright/test';

// #131, proven across two real WordPress sites: a client asks for something on
// their own site, it arrives in the studio's queue, the studio answers it, and
// the answer appears back on the client's site.
//
// That whole loop is the reason this is a pair test rather than a studio one.
// Each half is provable on its own — the queue reads a table, the client screen
// reads a route — and neither half proves the thing that matters, which is that
// what the studio types is what the client reads.
//
// The isolation test here is the one worth the runtime. The queue is the first
// studio screen that spans clients on purpose, so "shows the right rows" and
// "shows only permitted rows" are two different claims and the second is the
// one with consequences.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const ASK = '/wp-admin/admin.php?page=blueworx-forge-client-ask';
const STATUS = '/wp-admin/admin.php?page=blueworx-forge-client-asked';

const RUN = `queue${Date.now()}`;

test.beforeAll(() => {
  if (!CLIENT_URL || !ADMIN_USER || !ADMIN_PASS) {
    throw new Error('BWX_CLIENT_BASE_URL, WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

async function signedIn(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  const nonce = await page.evaluate(() => window.wpApiSettings?.nonce);
  await page.close();

  expect(nonce, `no REST nonce available at ${baseURL}`).toBeTruthy();
  return { context, nonce };
}

async function studioSite(studio, label) {
  const client = await (
    await studio.context.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { 'X-WP-Nonce': studio.nonce },
      data: { display_name: `${label} ${RUN}`, timezone: 'Europe/London' },
    })
  ).json();

  const site = await (
    await studio.context.request.post(
      `/wp-json/blueworx-forge/v1/clients/${client.client.id}/sites`,
      {
        headers: { 'X-WP-Nonce': studio.nonce },
        data: { name: `${label} site ${RUN}`, url: CLIENT_URL },
      }
    )
  ).json();

  const issued = await (
    await studio.context.request.post(
      `/wp-json/blueworx-forge/v1/client-sites/${site.site.id}/integration/key`,
      { headers: { 'X-WP-Nonce': studio.nonce } }
    )
  ).json();

  return { client: client.client, site: site.site, issued };
}

async function connect(client, issued) {
  const response = await client.context.request.post(
    '/wp-json/blueworx-forge-client/v1/connection',
    {
      headers: { 'X-WP-Nonce': client.nonce },
      data: {
        studio_url: STUDIO_URL,
        site_id: issued.integration.registry_site_id,
        key: issued.key,
      },
    }
  );

  expect(response.status(), await response.text()).toBe(200);
}

async function send(page, title, { type = 'request', description = 'Because it would help.' } = {}) {
  await page.goto(ASK);
  await page.check(`input[name="type"][value="${type}"]`);
  await page.fill('#bwx-title', title);
  await page.fill('#bwx-description', description);
  await page.click('#submit');
  await expect(page.locator('[data-bwx-result="sent"]')).toHaveCount(1);
}

/**
 * Opens the studio app and switches to the request queue.
 *
 * It waits for the work screen to settle before switching, which is what a
 * person does anyway. It also matters here: the harness runs WordPress on PHP's
 * single-threaded built-in server, so a read issued while the board's three are
 * still in flight waits behind them rather than alongside them, and the wait
 * looks exactly like a screen that never loads.
 */
async function queue(studio) {
  const page = await studio.context.newPage();

  await page.goto('/blueworx-forge/');
  await page.waitForLoadState('networkidle');
  await page.getByTestId('bwx-screen-requests').click();
  await expect(page.getByTestId('bwx-queue')).toBeVisible({ timeout: 30000 });

  return page;
}

test.describe('the studio working the request queue', () => {
  test('what a client sends arrives in the queue, in their words', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Queued Co');

    await connect(client, mine.issued);

    const clientPage = await client.context.newPage();
    const title = `A booking form that takes deposits ${RUN}`;

    await send(clientPage, title, { description: 'People ring up to pay.' });

    const page = await queue(studio);
    const row = page.locator('[data-testid="bwx-queue-row"]', { hasText: title });

    await expect(row).toHaveCount(1);
    await expect(row).toContainText(`Queued Co ${RUN}`);
    await expect(row).toContainText('Received');

    // The client's own words, on the studio's screen, unchanged.
    await row.locator('.bwx-row-open').click();
    await expect(page.getByTestId('bwx-request-description')).toHaveText('People ring up to pay.');

    await clientPage.close();
    await page.close();
  });

  test('the answer the studio types is the answer the client reads', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Answered Live Co');

    await connect(client, mine.issued);

    const clientPage = await client.context.newPage();
    const title = `Deposits on the booking form ${RUN}`;

    await send(clientPage, title);

    const page = await queue(studio);
    const row = page.locator('[data-testid="bwx-queue-row"]', { hasText: title });

    await row.locator('.bwx-row-open').click();
    await page.getByTestId('bwx-request-state').selectOption('accepted');
    await page.getByTestId('bwx-request-response').fill('Yes — going into the October release.');
    await page.getByTestId('bwx-request-save').click();

    await expect(page.getByTestId('bwx-request-panel')).toHaveCount(0);
    await expect(row).toContainText('Accepted');

    // The whole point of the loop: it reaches the client's own site.
    await clientPage.goto(STATUS);

    const entry = clientPage.locator('[data-testid="bwx-asked-entry"]', { hasText: title });

    await expect(entry.locator('[data-testid="bwx-asked-status"]')).toHaveText('Accepted');
    await expect(entry.locator('[data-testid="bwx-asked-response"]')).toContainText(
      'going into the October release'
    );

    await clientPage.close();
    await page.close();
  });

  test('answering a request never rewrites what the client asked for', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Immutable Co');

    await connect(client, mine.issued);

    const clientPage = await client.context.newPage();
    const title = `Words that must survive ${RUN}`;

    await send(clientPage, title, { description: 'Exactly what I typed.' });

    const page = await queue(studio);
    const row = page.locator('[data-testid="bwx-queue-row"]', { hasText: title });

    await row.locator('.bwx-row-open').click();

    // Nothing on this panel offers to edit the client's words — the record is
    // read-only by construction, not by a disabled attribute somebody can flip.
    await expect(page.getByTestId('bwx-request-said').locator('input, textarea')).toHaveCount(0);

    await page.getByTestId('bwx-request-response').fill('Noted.');
    await page.getByTestId('bwx-request-save').click();
    await expect(page.getByTestId('bwx-request-panel')).toHaveCount(0);

    // And the write left them exactly as they were, on the client's own screen.
    await clientPage.goto(STATUS);

    const entry = clientPage.locator('[data-testid="bwx-asked-entry"]', { hasText: title });

    await expect(entry).toContainText('Exactly what I typed.');

    await clientPage.close();
    await page.close();
  });

  test('the queue shows every client, and says which is which', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);

    const first = await studioSite(studio, 'Alpha Co');
    const second = await studioSite(studio, 'Beta Co');

    const clientPage = await client.context.newPage();
    const fromFirst = `Something Alpha asked ${RUN}`;
    const fromSecond = `Something Beta asked ${RUN}`;

    // One site, connecting as each client in turn — the same trick #130's
    // isolation test uses, and the only way to get two clients out of one
    // client install.
    await connect(client, first.issued);
    await send(clientPage, fromFirst);

    await connect(client, second.issued);
    await send(clientPage, fromSecond);

    const page = await queue(studio);

    // Cross-client is the point of this screen: both, at once, on one list.
    await expect(
      page.locator('[data-testid="bwx-queue-row"]', { hasText: fromFirst })
    ).toContainText(`Alpha Co ${RUN}`);
    await expect(
      page.locator('[data-testid="bwx-queue-row"]', { hasText: fromSecond })
    ).toContainText(`Beta Co ${RUN}`);

    // And filtering to one client hides the other rather than merely dimming it.
    await page.getByTestId('bwx-queue-client').selectOption({ label: `Alpha Co ${RUN}` });

    await expect(page.locator('[data-testid="bwx-queue-row"]', { hasText: fromFirst })).toHaveCount(
      1
    );
    await expect(
      page.locator('[data-testid="bwx-queue-row"]', { hasText: fromSecond })
    ).toHaveCount(0);

    await clientPage.close();
    await page.close();
  });

  test('filtering to nothing says so, rather than looking like an empty queue', async ({
    browser,
  }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Filtered Co');

    await connect(client, mine.issued);

    const clientPage = await client.context.newPage();

    await send(clientPage, `Something to hide behind a filter ${RUN}`);

    const page = await queue(studio);

    await page.getByTestId('bwx-queue-search').fill('nothing matches this at all');

    await expect(page.getByTestId('bwx-queue-row')).toHaveCount(0);
    await expect(page.getByText('Nothing matches those filters')).toBeVisible();

    await clientPage.close();
    await page.close();
  });
});
