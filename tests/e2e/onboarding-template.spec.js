import { test, expect } from '@playwright/test';

// #159 walked as the studio walks it. The point being proved is ONB-E2: an
// issued version never changes again, and editing one means opening a copy.
//
// Nothing is ever deleted and the instance is kept between runs, so every name
// carries a run id or the spec passes once and fails for ever after.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

/** Starts a fresh draft and returns once its page is showing. */
async function startDraft(page, name) {
  await page.goto(TEMPLATE);

  // The "start a checklist" form only appears when nothing exists at all, so
  // after the first run this walks the copy route instead. Both end with a
  // draft on screen, which is what every test below needs.
  const start = page.locator('[data-bwx-start-draft="1"]');

  if (await start.count()) {
    await page.fill('#bwx-template-name', name);
    await start.locator('input[type="submit"]').click();
    await expect(page.locator('[data-bwx-result="draft-started"]')).toBeVisible();

    return;
  }

  await page.locator('[data-bwx-copy-template="1"] input[type="submit"]').first().click();
  await expect(page.locator('[data-bwx-result="copy-opened"]')).toBeVisible();
}

/** Adds one step to the draft currently on screen. */
async function addStep(page, title, { section = 'foundations', launchCritical = false } = {}) {
  await page.fill('#bwx-step-title', title);
  await page.selectOption('#bwx-step-section', section);

  if (launchCritical) {
    await page.check('#bwx-step-launch-critical');
  }

  await page.locator('[data-bwx-add-step="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="step-added"]')).toBeVisible();
}

test('a draft can be written, published, and then never changed again', async ({ page }) => {
  test.setTimeout(120_000);

  await signIn(page);
  await startDraft(page, `Checklist ${RUN_ID}`);

  const step = `Delegate the registrar ${RUN_ID}`;

  await addStep(page, step, { launchCritical: true });

  // While it is a draft, the step is there and can be taken away again.
  await expect(page.locator(`text=${step}`).first()).toBeVisible();
  await expect(page.locator('[data-bwx-remove-step="1"]').first()).toBeVisible();

  await page.locator('[data-bwx-publish-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="published"]')).toBeVisible();

  // ONB-E2: published, and now offering a copy rather than any way to edit.
  await expect(page.locator('[data-bwx-template-name="1"]')).toHaveAttribute(
    'data-bwx-state',
    'published'
  );
  await expect(page.locator('[data-bwx-add-step="1"]')).toHaveCount(0);
  await expect(page.locator('[data-bwx-remove-step="1"]')).toHaveCount(0);
  await expect(page.locator('[data-bwx-publish-template="1"]')).toHaveCount(0);
  await expect(page.locator('[data-bwx-copy-template="1"]')).toBeVisible();
});

test('editing an issued version opens a copy and leaves the original alone', async ({ page }) => {
  test.setTimeout(120_000);

  await signIn(page);
  await startDraft(page, `Original ${RUN_ID}`);
  await addStep(page, `Original step ${RUN_ID}`);
  await page.locator('[data-bwx-publish-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="published"]')).toBeVisible();

  const issuedRows = await page.locator('[data-bwx-versions="1"] tbody tr').count();

  await page.locator('[data-bwx-copy-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="copy-opened"]')).toBeVisible();

  // The copy is a draft, carrying the original's steps.
  await expect(page.locator('[data-bwx-template-name="1"]')).toHaveAttribute('data-bwx-state', 'draft');
  await expect(page.locator(`text=Original step ${RUN_ID}`).first()).toBeVisible();

  // And the original is still listed, still issued.
  await expect(page.locator('[data-bwx-versions="1"] tbody tr')).toHaveCount(issuedRows + 1);
  await expect(page.locator('[data-bwx-versions="1"] tr[data-bwx-state="published"]').first()).toBeVisible();
});

test('a change to a draft does not touch the version already issued', async ({ page }) => {
  test.setTimeout(120_000);

  await signIn(page);
  await startDraft(page, `Frozen ${RUN_ID}`);

  const original = `Only in version one ${RUN_ID}`;

  await addStep(page, original);
  await page.locator('[data-bwx-publish-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="published"]')).toBeVisible();

  const issuedUrl = page.url();

  await page.locator('[data-bwx-copy-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="copy-opened"]')).toBeVisible();

  const added = `Only in the draft ${RUN_ID}`;

  await addStep(page, added);

  // Back to the issued version: it has the original step and not the new one.
  await page.goto(issuedUrl);

  await expect(page.locator(`text=${original}`).first()).toBeVisible();
  await expect(page.locator(`text=${added}`)).toHaveCount(0);
});
