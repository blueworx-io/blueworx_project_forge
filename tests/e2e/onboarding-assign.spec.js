import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { forge, makeSite, startOnboarding } from './helpers/forge.js';

// #160 walked as the studio walks it: publish a checklist, give it to a site
// over REST, and watch it become that site's own — fixed at the version they
// were given — in the sites list the Clients screen in the app draws from.
//
// Nothing is ever deleted and the instance is kept between runs, so every name
// carries a run id or the spec passes once and fails for ever after.

const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** The REST caller for the signed-in page: the app page localises the nonce. */
async function callerFor(page) {
  await page.goto('/blueworx-forge/');

  const nonce = await page.evaluate(() => window.bwxForgeData?.nonce);
  expect(nonce, 'no REST nonce was localised for the signed-in user').toBeTruthy();

  return forge(page.request, nonce);
}

/** Makes sure there is a published checklist with at least one step in it. */
async function publishAChecklist(page) {
  await page.goto(TEMPLATE);

  const start = page.locator('[data-bwx-start-draft="1"]');

  if (await start.count()) {
    await page.fill('#bwx-template-name', `Assignable ${RUN_ID}`);
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

/** Where one site is with its onboarding, as the sites list says. */
async function onboardingOf(api, site) {
  const listed = await api.get(`/clients/${site.client_id}/sites?status=all`);
  expect(listed.ok, JSON.stringify(listed)).toBe(true);

  const row = listed.sites.find((one) => one.id === site.id);
  expect(row, 'the site is in the list').toBeTruthy();

  return row.onboarding;
}

test('a site is given the checklist once, and it is theirs from then on', async ({ page }) => {
  test.setTimeout(180_000);

  await signIn(page);
  const api = await callerFor(page);
  await publishAChecklist(page);

  const { site } = await makeSite(api, `Onboarding ${RUN_ID}a`, RUN_ID);

  expect(site.id, 'the site was created').toBeTruthy();

  // Before: offered the current version.
  const offered = await onboardingOf(api, site);

  expect(offered.started).toBe(false);
  expect(offered.template_version).toBeGreaterThan(0);

  const started = await startOnboarding(api, site.id);

  // After: it says where they are, at the version they were given, and offers
  // no way to give them another.
  const theirs = await onboardingOf(api, site);

  expect(theirs.started).toBe(true);
  expect(theirs.template_version).toBe(started.template_version);
  expect(theirs.completion).toBeGreaterThanOrEqual(0);

  const again = await api.post(`/client-sites/${site.id}/onboarding`, {});
  expect(again.status()).toBe(409);
  expect((await again.json()).code).toBe('bwx_forge_already_onboarding');
});

test('a brand new checklist is nought per cent done and not ready to launch', async ({ page }) => {
  test.setTimeout(180_000);

  await signIn(page);
  const api = await callerFor(page);
  await publishAChecklist(page);

  const { site } = await makeSite(api, `Onboarding ${RUN_ID}b`, RUN_ID);

  await startOnboarding(api, site.id);

  const state = await onboardingOf(api, site);

  // Nought of nought is nought, not a hundred — a checklist nobody has started
  // must not read as finished.
  expect(state.completion).toBe(0);
  expect(state.ready).toBe(false);

  // And the launch-critical step is counted as standing in the way.
  expect(state.blocking).toBeGreaterThan(0);
});
