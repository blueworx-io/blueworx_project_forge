import { test, expect } from '@playwright/test';

// #127, proven across two real WordPress sites.
//
// The acceptance is unusual and worth stating plainly: a brand-new client with
// no work must see a dashboard that reads as new rather than broken. So the
// first test here is the one that matters — a client with nothing gets five
// sections that each say what kind of nothing it is, and not one blank box.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const HOME = '/wp-admin/admin.php?page=blueworx-forge-client';

const RUN = `dash${Date.now()}`;

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

async function addWork(studio, siteId, values) {
  const response = await studio.context.request.post('/wp-json/blueworx-forge/v1/work-items', {
    headers: { 'X-WP-Nonce': studio.nonce },
    data: { client_site_id: siteId, problem: 'Something needs doing.', ...values },
  });

  expect(response.status(), await response.text()).toBe(200);
  return (await response.json()).item;
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

const day = (offset) => {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + offset);
  return date.toISOString().slice(0, 10);
};

test.describe('the client dashboard', () => {
  test('a brand-new client sees a dashboard that reads as new, not broken', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Brand New Co');

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(HOME);

    // Five sections, and every one that can be empty says which kind of empty.
    await expect(page.locator('[data-testid="bwx-panel"]')).toHaveCount(5);

    await expect(page.locator('[data-bwx-panel="contact"]')).toContainText('Nobody is assigned');
    await expect(page.locator('[data-bwx-panel="attention"]')).toContainText(
      'Nothing is blocked or overdue'
    );
    await expect(page.locator('[data-bwx-panel="upcoming"]')).toContainText(
      'Nothing has a date on it yet'
    );
    await expect(page.locator('[data-bwx-panel="support"]')).toContainText('not set up yet');

    // And nothing anywhere that reads as a failure.
    await expect(page.locator('.notice-error')).toHaveCount(0);

    await page.close();
  });

  test('scheduled work appears under coming up, soonest first', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Scheduled Co');

    await addWork(studio, mine.site.id, {
      title: `Later job ${RUN}`,
      level: 'feature',
      work_type: 'feature',
      planned_due: day(30),
    });
    await addWork(studio, mine.site.id, {
      title: `Sooner job ${RUN}`,
      level: 'feature',
      work_type: 'feature',
      planned_due: day(3),
    });

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(HOME);

    const rows = page.locator('[data-testid="bwx-upcoming-list"] li');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText(`Sooner job ${RUN}`);
    await expect(rows.last()).toContainText(`Later job ${RUN}`);

    await page.close();
  });

  test('work past its date is reported as needing attention', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Running Late Co');

    await addWork(studio, mine.site.id, {
      title: `Should have shipped ${RUN}`,
      level: 'feature',
      work_type: 'feature',
      planned_due: day(-7),
    });

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(HOME);

    const attention = page.locator('[data-bwx-panel="attention"]');
    await expect(attention).toContainText(`Should have shipped ${RUN}`);
    await expect(attention.locator('[data-bwx-reason="overdue"]')).toHaveCount(1);

    // Late work is not also advertised as coming up.
    await expect(page.locator('[data-bwx-panel="upcoming"]')).not.toContainText(
      `Should have shipped ${RUN}`
    );

    await page.close();
  });

  test('the dashboard offers nothing to act on', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Look Only Co');

    await addWork(studio, mine.site.id, {
      title: `Not yours to change ${RUN}`,
      level: 'feature',
      work_type: 'feature',
      planned_due: day(5),
    });

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(HOME);

    const panels = page.locator('[data-testid="bwx-panel"]');
    await expect(panels.locator('button')).toHaveCount(0);
    await expect(panels.locator('select')).toHaveCount(0);
    await expect(panels.locator('form')).toHaveCount(0);

    await page.close();
  });

  test('an unreachable studio leaves the work sections honest', async ({ browser }) => {
    test.slow();

    const client = await signedIn(browser, CLIENT_URL);

    await client.context.request.post('/wp-json/blueworx-forge-client/v1/connection', {
      headers: { 'X-WP-Nonce': client.nonce },
      data: { studio_url: 'http://127.0.0.1:9', site_id: 'site_nowhere', key: 'not-a-key' },
    });

    const page = await client.context.newPage();
    await page.goto(HOME);

    // No record means the page says so once, rather than drawing four sections
    // full of things it cannot see — and says which kind of nothing it is,
    // rather than a sentence that would fit an outage and a refusal equally
    // badly (#134).
    const denial = page.getByTestId('bwx-workspace-unavailable');

    await expect(denial).toContainText('cannot be read from the studio');
    await expect(denial).toContainText('Nothing has been lost');
    await expect(denial).toHaveAttribute('data-bwx-denial', 'unreachable');

    await page.close();
  });
});
