import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// The Forge menu's way onto the board.
//
// The board is a front-end page, so the admin has to link out to it. Three
// things have to hold for that link to be worth having, and each is one
// assertion below: it is there, it points at the app page rather than at an
// admin screen, and it opens in a new tab so the screen somebody was
// configuring is still there when they come back.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

test('the Forge menu links out to the board, in a new tab', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await admin.context.newPage();

  await page.goto('/wp-admin/admin.php?page=blueworx-forge-sites');

  const link = page.locator('#adminmenu a', { has: page.locator('.bwx-board-link') });

  await expect(link).toHaveCount(1);
  await expect(link).toContainText('Board');

  // Out of the admin, onto the app page — not another admin screen wearing the
  // name, which is the failure this would otherwise ship with.
  const href = await link.getAttribute('href');

  expect(href).toContain('/blueworx-forge');
  expect(href, 'the front end, not an admin page').not.toContain('/wp-admin/');

  // And it says so before it is clicked, for people reading and for people
  // listening.
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link.locator('.dashicons-external')).toHaveCount(1);
  await expect(link).toContainText('opens in a new tab');

  // The page it points at is really the app, rather than a 404 that happens to
  // have the right address.
  const board = await admin.context.newPage();

  await board.goto(href);
  await expect(board.locator('#bwx-forge-app')).toHaveCount(1);

  await board.close();
  await page.close();
  await admin.context.close();
});
