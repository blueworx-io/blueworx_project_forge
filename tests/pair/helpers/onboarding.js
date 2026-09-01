import { expect } from '@playwright/test';

// Getting a site to the point where a client has a checklist to work through:
// publish a template, then give it to the site.
//
// It lived in client-checklist.spec.js until #181 wanted the same preamble for
// the acceptance specs. Copying it would have been the shorter change and the
// wrong one — this walks two admin screens and carries hard-won knowledge about
// what a reused instance leaves behind, and a second copy would drift from this
// one silently.
//
// Both callers pass the same shape: a `studio` with a `context` signed in to the
// studio's WordPress. Not matched by testMatch, so it is a module rather than a
// suite that asserts nothing.

const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';
const CLIENTS = '/wp-admin/admin.php?page=blueworx-forge-clients';

/**
 * A published checklist with exactly these steps on it, all owned by the client.
 *
 * Templates are versioned and there is no route that writes one — #159 put the
 * whole of it behind the screen — so this walks the screen. The three ways in
 * are because these instances are reused between runs and the state this starts
 * from is whatever the last run left: no draft at all, a published version to
 * copy, or a draft somebody already had open.
 */
export async function publishChecklist(studio, titles, label) {
  const page = await studio.context.newPage();

  await page.goto(TEMPLATE);

  if (!(await page.locator('[data-bwx-add-step="1"]').count())) {
    const start = page.locator('[data-bwx-start-draft="1"]');
    const copy = page.locator('[data-bwx-copy-template="1"]');

    if (await start.count()) {
      await start.locator('input[name="name"]').fill(`Launch checklist ${label}`);
      await start.locator('input[type="submit"]').click();
    } else {
      await copy.first().locator('input[type="submit"]').click();
    }

    await page.waitForLoadState();
  }

  const addStep = page.locator('[data-bwx-add-step="1"]');
  await expect(addStep).toHaveCount(1);

  // Empty the draft first. A copy of what is published carries whatever earlier
  // runs left on it, and a step from last week sorting above this one is how
  // "what is next for you" ends up naming something the spec never created.
  for (let guard = 0; guard < 50; guard += 1) {
    const remove = page.locator('[data-bwx-remove-step="1"]');

    if (!(await remove.count())) {
      break;
    }

    await remove.first().locator('input[type="submit"]').click();
    await page.waitForLoadState();
  }

  for (const [index, step] of titles.entries()) {
    const { title, launchCritical = false } = 'string' === typeof step ? { title: step } : step;

    await addStep.locator('#bwx-step-title').fill(title);
    await addStep.locator('#bwx-step-section').selectOption('foundations');
    await addStep.locator('#bwx-step-owner').selectOption('client');
    await addStep.locator('#bwx-step-position').fill(String((index + 1) * 10));

    // Off unless asked for: a checklist where everything blocks go-live proves
    // nothing about the gate, because there is nothing it lets through.
    const critical = addStep.locator('#bwx-step-launch-critical');

    if (await critical.count()) {
      await critical.setChecked(launchCritical);
    }

    await addStep.locator('input[type="submit"]').click();
    await page.waitForLoadState();
  }

  const publish = page.locator('[data-bwx-publish-template="1"]');
  await expect(publish).toHaveCount(1);
  await publish.locator('input[type="submit"]').click();
  await page.waitForLoadState();

  await page.close();
}

/**
 * Gives the published checklist to one site.
 *
 * The site row is an <li> keyed by site id and the control is a button with a
 * confirm on it. Both matter: keying on the id rather than the name means a
 * second client called something similar cannot be clicked by mistake.
 */
export async function giveChecklistTo(studio, siteId) {
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
