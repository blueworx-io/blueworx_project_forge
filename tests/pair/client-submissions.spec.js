import { test, expect } from '@playwright/test';

// #129, proven across two real WordPress sites: a client can ask for something,
// whether or not they pay for support.
//
// No package is bought anywhere in this file, and that is the point rather than
// an omission. Packages are M8; asking is not gated on them, and the way to
// prove that is to never create one and still get a receipt.
//
// Immutability is asserted in tests/php/ClientSubmissionRouteTest as an absence:
// no route anywhere edits or deletes a submission. That is a stronger statement
// than anything a browser can check, because a route that does not exist cannot
// be called.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const ASK = '/wp-admin/admin.php?page=blueworx-forge-client-ask';

const RUN = `ask${Date.now()}`;

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

async function fillAndSend(page, { type = 'idea', title, description = 'Because it would help.' }) {
  await page.goto(ASK);
  await page.check(`input[name="type"][value="${type}"]`);
  await page.fill('#bwx-title', title);
  await page.fill('#bwx-description', description);
  await page.click('#submit');
}

test.describe('a client asking for something', () => {
  test('a client with no support package can send one and gets a receipt', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'No Package Co');

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await fillAndSend(page, { title: `A booking form would help ${RUN}` });

    await expect(page.locator('[data-bwx-result="sent"]')).toContainText('The studio has it');

    await page.close();
  });

  test('the form says what happens to what you send', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Told Plainly Co');

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(ASK);

    // The promise that makes an intake record worth keeping, said to the person
    // making it rather than only written in the code.
    await expect(page.locator('[data-testid="bwx-ask-immutable"]')).toContainText(
      'cannot be edited'
    );
    await expect(page.locator('[data-testid="bwx-ask-form"]')).toContainText(
      'whether or not you have a support package'
    );

    await page.close();
  });

  test('all four kinds can be sent', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Four Kinds Co');

    await connect(client, mine.issued);

    const page = await client.context.newPage();

    for (const type of ['bug', 'request', 'idea', 'suggestion']) {
      await fillAndSend(page, { type, title: `A ${type} ${RUN}` });
      await expect(page.locator('[data-bwx-result="sent"]')).toHaveCount(1);
    }

    await page.close();
  });

  test('a form with nothing said is refused and keeps what was typed', async ({ browser }) => {
    test.slow();

    const studio = await signedIn(browser, STUDIO_URL);
    const client = await signedIn(browser, CLIENT_URL);
    const mine = await studioSite(studio, 'Half Filled Co');

    await connect(client, mine.issued);

    const page = await client.context.newPage();
    await page.goto(ASK);
    await page.check('input[name="type"][value="request"]');
    await page.fill('#bwx-title', `Title but no detail ${RUN}`);
    await page.click('#submit');

    await expect(page.locator('[data-bwx-result="invalid"]')).toHaveCount(1);

    // The words come back. Nobody should retype a form because the studio
    // wanted one more box filled in.
    await expect(page.locator('#bwx-title')).toHaveValue(`Title but no detail ${RUN}`);

    await page.close();
  });

  test('an unreachable studio sends nothing and says so, keeping the words', async ({ browser }) => {
    test.slow();

    const client = await signedIn(browser, CLIENT_URL);

    await client.context.request.post('/wp-json/blueworx-forge-client/v1/connection', {
      headers: { 'X-WP-Nonce': client.nonce },
      data: { studio_url: 'http://127.0.0.1:9', site_id: 'site_nowhere', key: 'not-a-key' },
    });

    const page = await client.context.newPage();
    await fillAndSend(page, { title: `Into the void ${RUN}` });

    await expect(page.locator('[data-bwx-result="unreachable"]')).toContainText('nothing was sent');
    await expect(page.locator('#bwx-title')).toHaveValue(`Into the void ${RUN}`);

    await page.close();
  });
});
