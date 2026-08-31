import { test, expect } from '@playwright/test';

// #165. Every client's launch readiness in one view.
//
// The acceptance criterion is reconciliation — "the board reconciles to the
// same step records the client sees" — so the spec proves that directly rather
// than by inspection: it reads the board and the site's own checklist through
// the API and compares the step ids and statuses one for one. A screen that
// merely looked right would pass an inspection and fail this.
//
// The other half is the rule the screen would be worthless without: filtering
// narrows what is listed and never what is counted. Filter to something this
// site has none of and the row goes; clear it and the same figures come back.
//
// Nothing is ever deleted and the instance is kept between runs, so every name
// carries a run id, and every assertion is scoped to this run's own site.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';
const CLIENTS = '/wp-admin/admin.php?page=blueworx-forge-clients';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

// Nothing signs in twice. Logging in again rotates WordPress's cookies, which
// silently invalidates the REST nonce the page is holding — and every later
// call then fails somewhere nowhere near the login.
async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

/** Publishes a checklist carrying one launch-critical step. */
async function publishAChecklist(page) {
  await page.goto(TEMPLATE);

  const start = page.locator('[data-bwx-start-draft="1"]');

  if (await start.count()) {
    await page.fill('#bwx-template-name', `Board ${RUN_ID}`);
    await start.locator('input[type="submit"]').click();
  } else {
    await page.locator('[data-bwx-copy-template="1"] input[type="submit"]').first().click();
  }

  await page.fill('#bwx-step-title', `Delegate the registrar ${RUN_ID}`);
  await page.check('#bwx-step-launch-critical');
  await page.locator('[data-bwx-add-step="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="step-added"]')).toBeVisible();

  await page.locator('[data-bwx-publish-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="published"]')).toBeVisible();
}

/** Adds a client with one site, gives the site the checklist, returns its id. */
async function onboardingSite(page) {
  const clientName = `Board client ${RUN_ID}`;
  const siteName = `Board site ${RUN_ID}`;

  await page.goto(CLIENTS);
  await page.fill('#bwx-client-name', clientName);
  await page.locator('form[data-bwx-add-client] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-notice="added"]')).toBeVisible();

  const client = page
    .locator(`li[data-bwx-client]:has([data-bwx-client-name]:text-is("${clientName}"))`)
    .first();

  await client.locator('form[data-bwx-add-site] input[name="name"]').fill(siteName);
  await client.locator('form[data-bwx-add-site] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-notice="added"]')).toBeVisible();

  const site = page
    .locator(`li[data-bwx-site]:has([data-bwx-site-name]:text-is("${siteName}"))`)
    .first();

  const siteId = await site.getAttribute('data-bwx-site');

  page.once('dialog', (dialog) => dialog.accept());
  await page.locator(`li[data-bwx-site="${siteId}"] [data-bwx-assign-onboarding="1"] button`).click();
  await expect(page.locator('[data-bwx-notice="onboarding-started"]')).toBeVisible();

  return siteId;
}

/** Opens the board and waits for it to arrive. */
async function openBoard(page) {
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-onboarding').click();

  const table = page.getByTestId('bwx-onboarding-table');

  // Waited for here rather than in each test: the first request after a page
  // load pays this instance's cold start, which is longer than the default
  // expect timeout and has nothing to do with what is being proved.
  await expect(table).toBeVisible({ timeout: 30_000 });

  return table;
}

/** One call to the API, made from inside the app page so it carries the nonce. */
function ask(page, path, options = {}) {
  return page.evaluate(
    async ({ where, how }) => {
      const data = window.bwxForgeData;
      const response = await fetch(`${data.restUrl.replace(/\/$/, '')}${where}`, {
        method: how.method ?? 'GET',
        headers: { 'X-WP-Nonce': data.nonce, 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: undefined === how.body ? undefined : JSON.stringify(how.body),
      });

      return response.json();
    },
    { where: path, how: options }
  );
}

test('the board reconciles to the same steps the client sees', async ({ page }) => {
  test.setTimeout(180_000);

  await signIn(page);
  await publishAChecklist(page);

  const siteId = await onboardingSite(page);

  await openBoard(page);

  const board = await ask(page, '/onboarding/board');
  const row = board.sites.find((one) => one.client_site_id === siteId);

  expect(row, 'the site this run created is on the board').toBeTruthy();

  // The same records, read the other way: this is the route the client's own
  // checklist page reads, and the acceptance criterion is that the two agree.
  const own = await ask(page, `/onboarding/sites/${siteId}/steps`);

  const onTheBoard = row.steps.map((step) => `${step.id}:${step.status}`).sort();
  const onTheSite = own.steps.map((step) => `${step.id}:${step.status}`).sort();

  expect(onTheBoard).toEqual(onTheSite);

  // And the figures are worked out from those same records rather than stored.
  expect(row.required).toBe(own.steps.filter((step) => !step.optional).length);
  expect(row.approved).toBe(0);
  expect(row.completion).toBe(0);
  expect(row.launch_ready).toBe(false);
});

test('a brand new checklist reads as nothing done rather than as finished', async ({ page }) => {
  test.setTimeout(180_000);

  await signIn(page);
  await publishAChecklist(page);

  const siteId = await onboardingSite(page);

  await openBoard(page);

  const row = page.locator(`[data-testid="bwx-onboarding-row"][data-site="${siteId}"]`);

  await expect(row).toBeVisible();

  // Nought of nought is nought. A checklist nobody has started must never read
  // as complete, which is the one wrong answer that gets a site launched.
  await expect(row.getByTestId('bwx-onboarding-completion')).toContainText('0%');
  await expect(row).toHaveAttribute('data-launch', 'not-ready');
});

test('a filter narrows what is listed and never what is counted', async ({ page }) => {
  test.setTimeout(180_000);

  await signIn(page);
  await publishAChecklist(page);

  const siteId = await onboardingSite(page);

  await openBoard(page);

  const row = page.locator(`[data-testid="bwx-onboarding-row"][data-site="${siteId}"]`);

  await expect(row).toBeVisible();

  const before = await row.getByTestId('bwx-onboarding-completion').textContent();

  // Nothing on a checklist assigned a moment ago is late, so asking for the
  // overdue ones must take this site off the board entirely — not leave it
  // sitting there with an empty list under it.
  await page.getByTestId('bwx-onboarding-overdue').check();
  await expect(row).toHaveCount(0);

  await page.getByTestId('bwx-onboarding-overdue').uncheck();
  await expect(row).toBeVisible();

  // Back, and saying exactly what it said before. A figure that moved when a
  // filter was cleared would mean the board was counting what it was showing.
  await expect(row.getByTestId('bwx-onboarding-completion')).toHaveText(before);
});

test('a row opens to the steps behind its figures', async ({ page }) => {
  test.setTimeout(180_000);

  await signIn(page);
  await publishAChecklist(page);

  const siteId = await onboardingSite(page);

  await openBoard(page);

  const row = page.locator(`[data-testid="bwx-onboarding-row"][data-site="${siteId}"]`);

  await expect(row).toBeVisible();
  await row.locator('.bwx-row-open').click();

  const panel = page.getByTestId('bwx-onboarding-panel');

  await expect(panel).toBeVisible();

  // The launch-critical step this run published is named as standing in the
  // way, and appears on the checklist under it.
  await expect(panel.getByTestId('bwx-onboarding-blocking')).toContainText(
    `Delegate the registrar ${RUN_ID}`
  );
  await expect(panel.getByTestId('bwx-onboarding-steps')).toContainText(
    `Delegate the registrar ${RUN_ID}`
  );

  // Nothing has been sent to us, so there is nothing to approve. A board
  // offering "Approve" on a step nobody has answered would be offering a
  // button the server refuses.
  await expect(panel.getByTestId('bwx-onboarding-approve')).toHaveCount(0);

  await panel.getByRole('button', { name: 'Close' }).click();
  await expect(panel).toBeHidden();
});

test('a step waiting on us is approved from the board, and the figures move', async ({ page }) => {
  test.setTimeout(180_000);

  // The reason this issue exists. The approve and send-back decisions shipped
  // with #163 and worked from the first day; until this board there was no
  // screen in the studio that could reach them. So the test is the whole loop,
  // not the button: something is handed over, it shows up as ours to answer, it
  // is answered here, and the client's launch readiness changes because of it.
  await signIn(page);
  await publishAChecklist(page);

  const siteId = await onboardingSite(page);

  await openBoard(page);

  const own = await ask(page, `/onboarding/sites/${siteId}/steps`);
  const step = own.steps[0];

  expect(step, 'the checklist has a step on it').toBeTruthy();

  // How many steps this site actually has is not fixed: the checklist template
  // on this instance gains a step every time a spec publishes one, so nothing
  // below may assume a total. What is asserted is the movement — none approved,
  // then one — which is true whatever the checklist happens to be that day.

  // Handed over, the way a client hands one over.
  const answered = await ask(page, `/onboarding/steps/${step.id}`, {
    method: 'PATCH',
    body: { status: 'submitted', response: 'Invited the named account on Monday.' },
  });

  expect(answered.step.status).toBe('submitted');

  await page.reload();
  await page.getByTestId('bwx-screen-onboarding').click();
  await expect(page.getByTestId('bwx-onboarding-table')).toBeVisible({ timeout: 30_000 });

  const row = page.locator(`[data-testid="bwx-onboarding-row"][data-site="${siteId}"]`);

  await expect(row).toBeVisible();
  await expect(row.getByTestId('bwx-onboarding-completion')).toContainText('0 /');
  await expect(row.getByTestId('bwx-onboarding-awaiting')).toContainText('1 waiting on us');

  await row.locator('.bwx-row-open').click();

  const panel = page.getByTestId('bwx-onboarding-panel');

  await expect(panel).toBeVisible();

  // The one step that has been handed over is the only one offering a decision.
  // Every other step on the checklist is untouched, and the server refuses an
  // approval of work nobody has sent.
  const approve = panel.getByTestId('bwx-onboarding-approve');

  await expect(approve).toHaveCount(1);
  await approve.click();

  // Approved, and the row moves with it rather than being patched in place —
  // one approved, and nothing left waiting on us.
  await expect(row.getByTestId('bwx-onboarding-completion')).toContainText('1 /');
  await expect(row.getByTestId('bwx-onboarding-awaiting')).toHaveCount(0);

  // And the client's own record says the same, which is the whole point: the
  // decision was made on the board and it is the step that changed.
  const after = await ask(page, `/onboarding/sites/${siteId}/steps`);

  expect(after.steps.find((one) => one.id === step.id).status).toBe('approved');
});
