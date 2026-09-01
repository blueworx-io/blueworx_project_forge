import { test, expect } from '@playwright/test';
import { publishChecklist, giveChecklistTo } from './helpers/onboarding.js';

// #162, #167, proven across two real WordPress sites.
//
// A client works through their own checklist and can do nothing else with it.
// The prohibitions are proven twice over, and the stronger half is not here:
// that a client cannot create, delete, reorder or approve a step is proven as
// an absence in tests/php/ClientOnboardingRouteTest, because a route that does
// not exist cannot be called, and no browser can make that claim. What a
// browser is good for is the rest — that somebody can actually do their part,
// and that a refusal reaches them in words they can act on.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const CHECKLIST = '/wp-admin/admin.php?page=blueworx-forge-client-checklist';

const RUN = `chk${Date.now()}`;

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

async function readyClient(browser, label, titles) {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);
  const mine = await studioSite(studio, label);

  await connect(client, mine.issued);
  await publishChecklist(studio, titles, RUN);
  await giveChecklistTo(studio, mine.site.id);

  return { studio, client, mine };
}

test.describe('a client working through their checklist', () => {
  test('they can answer a step and hand it back to us', async ({ browser }) => {
    test.slow();

    const title = `Delegate the domain ${RUN}`;
    const { client } = await readyClient(browser, 'Checklist Co', [title]);

    const page = await client.context.newPage();
    await page.goto(CHECKLIST);

    // The question somebody opening the page actually has, answered first.
    await expect(page.locator('[data-testid="bwx-checklist-next"]')).toContainText(title);

    // Nothing on the page offers to approve anything (ONB-2). The absence is
    // proven properly in the PHP route test; this is the screen agreeing.
    await expect(page.getByRole('button', { name: /approve/i })).toHaveCount(0);

    // Said before somebody types, not only after they are refused.
    await expect(page.locator('[data-testid="bwx-checklist-form"]').first()).toContainText(
      'do not put passwords'
    );

    await page
      .locator('[data-testid="bwx-checklist-response"]')
      .first()
      .fill('Invited your account as an administrator.');
    await page.locator('[data-testid="bwx-checklist-submit"]').first().click();

    await expect(page.locator('[data-testid="bwx-checklist-outcome"]')).toContainText('Saved');

    // Handed over: with us now, and no longer something they can act on.
    await expect(page.locator('[data-testid="bwx-checklist-status"]').first()).toContainText(
      'With us to check'
    );
    await expect(page.locator('[data-testid="bwx-checklist-response"]')).toHaveCount(0);

    await page.close();
  });

  test('a password typed into a step is refused, and says what to do instead', async ({
    browser,
  }) => {
    test.slow();

    const { client } = await readyClient(browser, 'No Passwords Co', [
      `Give us access to the hosting ${RUN}`,
    ]);

    const page = await client.context.newPage();
    await page.goto(CHECKLIST);

    await page.locator('[data-testid="bwx-checklist-response"]').first().fill('password: hunter2');
    await page.locator('[data-testid="bwx-checklist-save"]').first().click();

    // ONB-3, and the half of it that matters most: the refusal names the way
    // out. A refusal that stops at "no" gets the password emailed to us.
    await expect(page.locator('[data-testid="bwx-checklist-outcome"]')).toContainText('invite');

    await page.close();
  });

  test('a step we send back shows the client what we asked for', async ({ browser }) => {
    test.slow();

    // #163, and the whole reason returning is a decision of its own: the
    // feedback has to arrive on the client's own screen. Without it, "needs
    // another look" is an instruction with no content.
    const title = `Give us access to the hosting ${RUN}`;
    const { studio, client, mine } = await readyClient(browser, 'Sent Back Co', [title]);

    const page = await client.context.newPage();
    await page.goto(CHECKLIST);

    await page
      .locator('[data-testid="bwx-checklist-response"]')
      .first()
      .fill('I think that is done.');
    await page.locator('[data-testid="bwx-checklist-submit"]').first().click();
    await expect(page.locator('[data-testid="bwx-checklist-status"]').first()).toContainText(
      'With us to check'
    );

    const listed = await (
      await studio.context.request.get(
        `/wp-json/blueworx-forge/v1/onboarding/sites/${mine.site.id}/steps`,
        { headers: { 'X-WP-Nonce': studio.nonce } }
      )
    ).json();

    const step = listed.steps.find((each) => each.title === title);
    expect(step, 'the studio should see the step it issued').toBeTruthy();

    // Sending it back with nothing to say is refused, not silently accepted.
    const empty = await studio.context.request.post(
      `/wp-json/blueworx-forge/v1/onboarding/steps/${step.id}/review`,
      {
        headers: { 'X-WP-Nonce': studio.nonce },
        data: { decision: 'return', reason: '' },
      }
    );

    expect(empty.status()).toBe(400);

    const returned = await studio.context.request.post(
      `/wp-json/blueworx-forge/v1/onboarding/steps/${step.id}/review`,
      {
        headers: { 'X-WP-Nonce': studio.nonce },
        data: { decision: 'return', reason: 'The invitation has not arrived — please resend it.' },
      }
    );

    expect(returned.status(), await returned.text()).toBe(200);

    // The client site holds its copy for a minute (Cache::MAX_AGE), and the
    // studio's decision happened outside it, so this waits the window out
    // rather than forcing a refresh. Slower, but it is the real path a client
    // takes — nobody clicks anything to be told their step came back.
    //
    // count() rather than textContent(): a locator that matches nothing makes
    // textContent() wait for it, so the predicate never returns and the poll
    // never gets a second go. count() answers immediately with zero.
    await expect
      .poll(
        async () => {
          await page.goto(CHECKLIST);

          return page.locator('[data-testid="bwx-checklist-feedback"]').count();
        },
        { timeout: 120000, intervals: [5000] }
      )
      .toBeGreaterThan(0);

    await expect(page.locator('[data-testid="bwx-checklist-feedback"]').first()).toContainText(
      'The invitation has not arrived'
    );

    // And it is theirs to do again, with what they wrote still there.
    await expect(page.locator('[data-testid="bwx-checklist-status"]').first()).toContainText(
      'Needs another look'
    );
    await expect(page.locator('[data-testid="bwx-checklist-response"]').first()).toHaveValue(
      /I think that is done/
    );

    await page.close();
  });

  test('a file we will not hold is turned away, and a real one is kept', async ({ browser }) => {
    test.slow();

    const { client } = await readyClient(browser, 'Evidence Co', [
      `Approve the legal pages ${RUN}`,
    ]);

    const page = await client.context.newPage();
    await page.goto(CHECKLIST);

    // #168. Not "we scanned it and it was bad" — we do not accept the file at
    // all, which is the promise a plugin can actually keep on any hosting.
    await page.locator('[data-testid="bwx-checklist-file"]').first().setInputFiles({
      name: 'shell.php',
      mimeType: 'application/x-php',
      buffer: Buffer.from('<?php echo "hello"; ?>'),
    });
    await page.locator('[data-testid="bwx-checklist-save"]').first().click();

    await expect(page.locator('[data-testid="bwx-checklist-outcome"]')).toContainText(
      /not accepted|not the kind of file/i
    );
    await expect(page.locator('[data-testid="bwx-checklist-evidence"]')).toHaveCount(0);

    // And the ordinary case still works, so the rule above is a rule rather
    // than an upload that never worked.
    await page.locator('[data-testid="bwx-checklist-file"]').first().setInputFiles({
      name: 'dns-records.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('A record points at the new host.'),
    });
    await page.locator('[data-testid="bwx-checklist-save"]').first().click();

    await expect(page.locator('[data-testid="bwx-checklist-evidence"]').first()).toContainText(
      'dns-records.txt'
    );

    await page.close();
  });
});
