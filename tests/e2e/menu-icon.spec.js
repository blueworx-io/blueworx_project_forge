import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

// The Forge menu icon is drawn by WordPress like every other plugin's, so it
// sits where the neighbouring icons sit and takes the menu's own colours.

test('the Forge menu icon is placed by WordPress, in line with its neighbours', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/index.php');

  const forge = page.locator('#toplevel_page_blueworx-forge-sites');
  await expect(forge).toBeVisible();

  // WordPress paints an SVG icon as the entry's background; nothing of ours
  // draws beside it.
  await expect(forge.locator('.wp-menu-image.svg')).toHaveCount(1);
  await expect(page.locator('#bwx-forge-menu-icon')).toHaveCount(0);

  // Same box, same place within the row, as a core entry's icon.
  const measure = async (id) => {
    const li = page.locator(id);
    const row = await li.boundingBox();
    const icon = await li.locator('.wp-menu-image').boundingBox();
    return { w: icon.width, h: icon.height, top: icon.y - row.y, left: icon.x - row.x };
  };
  const ours = await measure('#toplevel_page_blueworx-forge-sites');
  const theirs = await measure('#menu-comments');
  expect(ours).toEqual(theirs);
});
