import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';

// #130, proven across two real WordPress sites: a client sees what happened to
// what they asked for, and sees nobody else's.
//
// The studio's reply now arrives through the studio's own triage route (#131),
// so the reply column here is proof of the whole loop rather than proof of a
// fixture. The id of the work a request became is still seeded, because nothing
// writes it until #132 lands.
//
// The work item a submission points at is made through the studio's own route
// rather than seeded, because which client site it belongs to is exactly what
// the tenancy check on this screen turns on.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const ASK = '/wp-admin/admin.php?page=blueworx-forge-client-ask';
const STATUS = '/wp-admin/admin.php?page=blueworx-forge-client-asked';

const RUN = `status${Date.now()}`;

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

async function send(page, title, { type = 'request', description = 'Because it would help.' } = {}) {
  await page.goto(ASK);
  await page.check(`input[name="type"][value="${type}"]`);
  await page.fill('#bwx-title', title);
  await page.fill('#bwx-description', description);
  await page.click('#submit');
  await expect(page.locator('[data-bwx-result="sent"]')).toHaveCount(1);
}

// The studio answers, through the route the studio's own queue uses (#131).
// This was seeded straight into the database until that route existed; it now
// runs for real, which is what makes this screen's reply column proof of
// something rather than proof of a fixture.
async function studioAnswers(studio, title, { state, response = '' }) {
  const queue = await (
    await studio.context.request.get('/wp-json/blueworx-forge/v1/submissions', {
      headers: { 'X-WP-Nonce': studio.nonce },
    })
  ).json();

  const submission = queue.submissions.find((one) => one.title === title);

  expect(submission, `no submission titled ${title} in the studio queue`).toBeTruthy();

  const answered = await studio.context.request.patch(
    `/wp-json/blueworx-forge/v1/submissions/${submission.id}`,
    {
      headers: { 'X-WP-Nonce': studio.nonce },
      data: { intake_state: state, response },
    }
  );

  expect(answered.status(), await answered.text()).toBe(200);
}

// Still stands in for #132, which does not exist: nothing yet writes the id of
// the work a request became. When it does, this goes the same way as the
// helper above.
function studioConverts(title, itemId) {
  execFileSync(
    'php',
    [
      '-r',
      `define("WP_USE_THEMES", false);
       require ".wp-test/wp/wp-load.php";
       global $wpdb;
       $updated = $wpdb->update(
         $wpdb->prefix . "bwx_forge_submissions",
         array( "converted_item_id" => $argv[2] ),
         array( "title" => $argv[1] )
       );
       if ( 1 !== $updated ) { fwrite( STDERR, "seeded {$updated} rows, expected 1" ); exit( 1 ); }`,
      title,
      itemId,
    ],
    { stdio: 'pipe' }
  );
}

test.describe('a client seeing what happened to what they asked for', () => {
  test('what you send appears here, received, in your own words', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Asked Once Co');

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    const title = `A booking form that takes deposits ${RUN}`;

    await send(page, title);
    await page.goto(STATUS);

    const entry = page.locator('[data-testid="bwx-asked-entry"]', { hasText: title });

    await expect(entry).toHaveCount(1);
    await expect(entry.locator('[data-testid="bwx-asked-status"]')).toHaveText('Received');
    await expect(entry).toContainText('Because it would help.');

    await page.close();
  });

  test('the studio reply and the work it became are shown', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Answered Co');

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    const title = `Deposits on the booking form ${RUN}`;

    await send(page, title);

    const work = await addWork(studio, mine.site.id, {
      title: `Booking deposits ${RUN}`,
      level: 'feature',
      work_type: 'feature',
    });

    await studioAnswers(studio, title, {
      state: 'converted',
      response: 'Yes — going into the October release.',
    });

    studioConverts(title, work.id);

    await page.goto(STATUS);

    const entry = page.locator('[data-testid="bwx-asked-entry"]', { hasText: title });

    await expect(entry.locator('[data-testid="bwx-asked-status"]')).toHaveText('Became work');
    await expect(entry.locator('[data-testid="bwx-asked-response"]')).toContainText(
      'going into the October release'
    );

    const link = entry.locator('[data-testid="bwx-asked-converted"]');

    await expect(link).toContainText(`Booking deposits ${RUN}`);
    await expect(link).toHaveAttribute('href', /page=blueworx-forge-client-board/);

    await page.close();
  });

  test('one client never sees what another client asked for', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);

    const theirs = await studioSite(studio, 'Not Yours Co');
    const mine = await studioSite(studio, 'Yours Co');

    // The same site connects as one client, asks, then reconnects as the other.
    await connect(client, theirs.issued);

    const page = await client.context.newPage();
    const hidden = `A secret nobody else may read ${RUN}`;

    await send(page, hidden);

    await connect(client, mine.issued);
    await page.goto(STATUS);

    await expect(page.locator('[data-testid="bwx-asked-entry"]', { hasText: hidden })).toHaveCount(
      0
    );

    await page.close();
  });

  test('a client who has asked for nothing is told so, not shown an error', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Asked Nothing Co');

    await connect(client, mine.issued);

    const page = await client.context.newPage();

    await page.goto(STATUS);

    await expect(page.locator('[data-testid="bwx-asked-empty"]')).toContainText(
      "haven't asked for anything yet"
    );
    await expect(page.locator('[data-testid="bwx-asked-entry"]')).toHaveCount(0);

    await page.close();
  });
});
