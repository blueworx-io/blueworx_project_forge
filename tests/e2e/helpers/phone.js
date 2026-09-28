import { expect } from '@playwright/test';
import { signedIn } from './forge.js';

/** An ordinary modern phone, portrait (2026-09-27, #428). */
export const PHONE = { width: 390, height: 844 };

/**
 * The app, signed in, at phone size. The viewport is set before the page
 * loads, so the first paint is the phone layout — the calendar's default is
 * read once, on first draw.
 */
export async function onPhone(browser, baseURL, user = process.env.WP_ADMIN_USER ?? 'admin', pass = process.env.WP_ADMIN_PASS ?? 'admin', hash = '') {
  const admin = await signedIn(browser, baseURL, user, pass);
  const page = await admin.context.newPage();
  await page.setViewportSize(PHONE);
  await page.goto(`/blueworx-forge/${hash}`);
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible();
  return { page, admin };
}

/**
 * Whether the page, or the one scrolling area, is wider than the screen.
 * Scrollers inside a screen (Kanban, Gantt) are allowed: they scroll
 * sideways on their own without taking the page with them.
 */
export async function spillsSideways(page) {
  return page.evaluate(() => {
    const main = document.querySelector('.fs-main');
    return document.documentElement.scrollWidth > window.innerWidth + 1
      || (null !== main && main.scrollWidth > main.clientWidth + 1);
  });
}
