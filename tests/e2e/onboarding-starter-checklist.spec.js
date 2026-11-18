import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

// ONB-1 (#159). A site gets the agreed launch checklist as version 1 without
// anybody building it: seeded on activation, or on the first request after the
// update that finished it.
//
// Version 1 is whatever was published first, so this needs a site whose first
// version came from the seed. CI's WordPress is fresh on every run. A local
// instance that already had a checklist of its own before this change keeps
// it, which is the point of seeding only once: run `wp-test-env.mjs down` and
// `up` again to see the seed.

const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';

test('version 1 is the agreed launch checklist, ready to give to a client', async ({ page }) => {
  await signIn(page);
  await page.goto(TEMPLATE);

  const first = page
    .locator('[data-bwx-versions="1"] tbody tr')
    .filter({ has: page.locator('td.bw-table__primary', { hasText: /^1$/ }) });

  await expect(first).toHaveCount(1);
  await expect(first).toHaveAttribute('data-bwx-state', 'published');
  await expect(first.locator('td').nth(1)).toHaveText('Launch checklist');
  await expect(first.locator('td.bw-table__num')).toHaveText('27');

  const id = await first.getAttribute('data-bwx-version');
  await page.goto(`${TEMPLATE}&template=${id}`);

  // One step from each end, and the ones the 18 November standards added.
  for (const title of [
    'Delegate the domain and DNS',
    'Confirm your brand colours and fonts',
    "Create the client's Figma file",
    'Approve the page designs',
    'Search set-up',
    'Final review and sign-off to go live',
  ]) {
    await expect(page.locator('[data-bwx-step]', { hasText: title })).toHaveCount(1);
  }
});
