import { test, expect } from '@playwright/test';

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
const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';
const CLIENTS = '/wp-admin/admin.php?page=blueworx-forge-clients';

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

// Publishes a checklist whose newest version carries the steps named here.
//
// Built through the studio's own screens rather than seeded, for two reasons:
// the seeded version 1 is deliberately not ready to publish until its remaining
// categories are written (#159), and these two sites are reused between runs,
// so the state this starts from is whatever the last run left. Hence the three
// ways in below — start a first draft, open a copy of what is published, or
// pick up a draft somebody already left open.
async function publishChecklist(studio, titles) {
  const page = await studio.context.newPage();

  await page.goto(TEMPLATE);

  if (!(await page.locator('[data-bwx-add-step="1"]').count())) {
    const start = page.locator('[data-bwx-start-draft="1"]');
    const copy = page.locator('[data-bwx-copy-template="1"]');

    if (await start.count()) {
      await start.locator('input[name="name"]').fill(`Launch checklist ${RUN}`);
      await start.locator('input[type="submit"]').click();
    } else {
      await copy.first().locator('input[type="submit"]').click();
    }

    await page.waitForLoadState();
  }

  const addStep = page.locator('[data-bwx-add-step="1"]');
  await expect(addStep).toHaveCount(1);

  // Empty the draft first. These two sites are reused between runs, so a copy
  // of what is published carries whatever earlier runs left on it — and a step
  // from last week sorting above this one is how "what is next for you" ends up
  // naming something this test never created.
  for (let guard = 0; guard < 50; guard += 1) {
    const remove = page.locator('[data-bwx-remove-step="1"]');

    if (!(await remove.count())) {
      break;
    }

    await remove.first().locator('input[type="submit"]').click();
    await page.waitForLoadState();
  }

  for (const [index, title] of titles.entries()) {
    await addStep.locator('#bwx-step-title').fill(title);
    await addStep.locator('#bwx-step-section').selectOption('foundations');
    await addStep.locator('#bwx-step-owner').selectOption('client');
    await addStep.locator('#bwx-step-position').fill(String((index + 1) * 10));
    await addStep.locator('input[type="submit"]').click();
    await page.waitForLoadState();
  }

  const publish = page.locator('[data-bwx-publish-template="1"]');
  await expect(publish).toHaveCount(1);
  await publish.locator('input[type="submit"]').click();
  await page.waitForLoadState();

  await page.close();
}

// The site row is an <li> keyed by site id, and the control is a <button> with
// a confirm on it. Both matter: keying on the id rather than the name means a
// second client called something similar cannot be clicked by mistake.
async function giveChecklistTo(studio, siteId) {
  const page = await studio.context.newPage();

  page.on('dialog', (dialog) => dialog.accept());

  await page.goto(CLIENTS);

  const row = page.locator(`[data-bwx-site="${siteId}"]`);
  await expect(row).toHaveCount(1);

  await row.locator('[data-bwx-action="bwx_forge_assign_onboarding"]').click();
  await page.waitForLoadState();

  await expect(page.locator(`[data-bwx-onboarding="${siteId}"]`)).toHaveCount(1);

  await page.close();
}

async function readyClient(browser, label, titles) {
  const studio = await signedIn(browser, STUDIO_URL);
  const client = await signedIn(browser, CLIENT_URL);
  const mine = await studioSite(studio, label);

  await connect(client, mine.issued);
  await publishChecklist(studio, titles);
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
