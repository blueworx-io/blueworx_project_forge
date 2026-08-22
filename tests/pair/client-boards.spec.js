import { test, expect } from '@playwright/test';

// #128, proven across two real WordPress sites: the client sees the same work
// the studio sees, and has no authority over any of it.
//
// The issue's acceptance is deliberately stronger than "the buttons are
// hidden" — the client build contains no transition control to hide — and that
// half is asserted against the shipped files in tests/unit/client-read-only.
// What is proved here is the other half: the work arrives, it is the right
// client's, it carries only what a client may see, and the page a person
// actually gets has nothing on it to move anything with.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const BOARD = '/wp-admin/admin.php?page=blueworx-forge-client-board';
const TIMELINE = '/wp-admin/admin.php?page=blueworx-forge-client-timeline';
const CALENDAR = '/wp-admin/admin.php?page=blueworx-forge-client-calendar';

const RUN = `boards${Date.now()}`;

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

/** A client, a site beneath it, and a key issued through the integration route. */
async function studioSite(studio, label, url = CLIENT_URL) {
  const client = await (
    await studio.context.request.post('/wp-json/blueworx-forge/v1/clients', {
      headers: { 'X-WP-Nonce': studio.nonce },
      data: { display_name: `${label} ${RUN}`, timezone: 'Europe/London' },
    })
  ).json();

  const site = await (
    await studio.context.request.post(
      `/wp-json/blueworx-forge/v1/clients/${client.client.id}/sites`,
      { headers: { 'X-WP-Nonce': studio.nonce }, data: { name: `${label} site ${RUN}`, url } }
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

test.describe('the client read-only views', () => {
  test('work appears on the board, in the column its stage names', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Board Co');

    await addWork(studio, mine.site.id, {
      title: `Rebuild the booking form ${RUN}`,
      level: 'feature',
      work_type: 'feature',
    });

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(BOARD);

    const column = page.locator('[data-bwx-stage="future-idea"]');
    await expect(column).toContainText(`Rebuild the booking form ${RUN}`);

    // Every stage is drawn, empty or not: a board that changes shape as work
    // moves is one nobody can read at a glance.
    await expect(page.locator('[data-testid="bwx-column"]')).toHaveCount(12);

    await page.close();
  });

  test('another client work never reaches this board', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);

    const mine = await studioSite(studio, 'Ours Co');
    const theirs = await studioSite(studio, 'Somebody Else Co', 'https://not-this-site.test');

    await addWork(studio, mine.site.id, {
      title: `Ours to see ${RUN}`,
      level: 'feature',
      work_type: 'feature',
    });
    await addWork(studio, theirs.site.id, {
      title: `Never theirs to see ${RUN}`,
      level: 'feature',
      work_type: 'feature',
    });

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(BOARD);

    await expect(page.locator('body')).toContainText(`Ours to see ${RUN}`);
    await expect(page.locator('body')).not.toContainText(`Never theirs to see ${RUN}`);

    await page.close();
  });

  test('the board carries nothing anybody could move work with', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Locked Co');

    await addWork(studio, mine.site.id, {
      title: `Not yours to move ${RUN}`,
      level: 'feature',
      work_type: 'feature',
    });

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(BOARD);

    const work = page.locator('.bwx-work');
    await expect(work).toContainText(`Not yours to move ${RUN}`);

    // Not "disabled" — absent. Anything a person could act through would be a
    // way to change work from a site that has no authority over it.
    await expect(work.locator('button')).toHaveCount(0);
    await expect(work.locator('select')).toHaveCount(0);
    await expect(work.locator('input')).toHaveCount(0);
    await expect(work.locator('form')).toHaveCount(0);
    await expect(work.locator('[draggable="true"]')).toHaveCount(0);

    await page.close();
  });

  test('a card names who is on it and nothing commercial', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Discreet Co');

    await addWork(studio, mine.site.id, {
      title: `Quietly chargeable ${RUN}`,
      level: 'feature',
      work_type: 'feature',
      priority: 'urgent',
    });

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(BOARD);

    const work = page.locator('.bwx-work');
    await expect(work).toContainText(`Quietly chargeable ${RUN}`);

    // Ruled out in the projection, so ruled out on the page. A client seeing
    // their work marked "urgent" is a conversation to have, not one to spring.
    await expect(work).not.toContainText('urgent');
    await expect(work).not.toContainText('unclassified');

    await page.close();
  });

  test('undated work stays visible on the timeline rather than vanishing', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Undated Co');

    await addWork(studio, mine.site.id, {
      title: `Nobody has dated this ${RUN}`,
      level: 'feature',
      work_type: 'feature',
    });

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(TIMELINE);

    await expect(page.locator('[data-testid="bwx-undated"]')).toContainText(
      `Nobody has dated this ${RUN}`
    );

    await page.close();
  });

  test('a dated item lands on its day in the calendar', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Dated Co');

    const item = await addWork(studio, mine.site.id, {
      title: `Due on a known day ${RUN}`,
      level: 'feature',
      work_type: 'feature',
      planned_due: '2026-09-10',
    });

    expect(item.planned_due).toBe('2026-09-10');

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(`${CALENDAR}&bwx-month=2026-09`);

    await expect(page.locator('[data-bwx-day="2026-09-10"]')).toContainText(
      `Due on a known day ${RUN}`
    );

    await page.close();
  });

  test('an unreachable studio says so rather than drawing an empty board', async ({ browser }) => {
    test.slow();

    const client = await signedIn(browser, CLIENT_URL);

    // Connected to somewhere that is not a studio, so the read fails and there
    // is nothing cached to fall back on.
    await client.context.request.post('/wp-json/blueworx-forge-client/v1/connection', {
      headers: { 'X-WP-Nonce': client.nonce },
      data: { studio_url: 'http://127.0.0.1:9', site_id: 'site_nowhere', key: 'not-a-key' },
    });

    const page = await client.context.newPage();
    await page.goto(BOARD);

    await expect(page.locator('[data-bwx-empty="1"]')).toContainText('cannot be shown');
    await expect(page.locator('[data-testid="bwx-column"]')).toHaveCount(0);

    await page.close();
  });
});
