import { test, expect } from '@playwright/test';

// #193. Forge's interface has to look the same on every site, whatever theme is
// active and whatever else is installed — and it must not change how the rest of
// the site looks either.
//
// The failure this catches is not subtle in production and nearly invisible in
// review: the app page renders its own bare template, so it *looks* isolated,
// while wp_head() quietly prints the theme's styles into it.
//
// Note what is asserted and why. WordPress and block themes deliver almost all
// of this as INLINE <style> blocks, not <link> tags — the first version of this
// spec only looked at links and passed against a page that was loading the whole
// of Twenty Twenty-Five.

async function headStyles(page) {
  return page.evaluate(() => ({
    // Every inline style block that WordPress or a theme printed carries an id
    // or a class; ours is injected by the bundle and carries neither.
    identified: [...document.querySelectorAll('style[id], style[class]')].map(
      (el) => el.id || el.className
    ),
    links: [...document.querySelectorAll('link[rel="stylesheet"]')].map((el) => el.href),
    html: document.head.innerHTML,
  }));
}

test('the app page loads no WordPress or theme styling', async ({ page }) => {
  await page.goto('/blueworx-forge/');

  const { identified, links } = await headStyles(page);

  expect(identified, `WordPress or the theme is styling the app: ${identified.join(', ')}`).toEqual([]);

  const strangers = links.filter((href) => !href.includes('/plugins/blueworx-forge/'));
  expect(strangers, `something else is styling the app: ${strangers.join(', ')}`).toEqual([]);
});

test('the app page references nothing from the active theme', async ({ page }) => {
  // Fonts, in particular: a block theme prints @font-face rules for its own
  // fonts, which is how an app ends up rendering in the theme's typeface.
  await page.goto('/blueworx-forge/');

  const { html } = await headStyles(page);

  expect(html).not.toContain('/wp-content/themes/');
});

test('the app page carries no admin bar', async ({ page, context }) => {
  // The admin bar is not just a bar: it brings its own stylesheet and pushes the
  // document down by 32px, which an app owning the viewport notices.
  await page.goto('/wp-login.php');
  await page.fill('#user_login', process.env.WP_ADMIN_USER ?? 'admin');
  await page.fill('#user_pass', process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw');
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto('/blueworx-forge/');

  await expect(page.locator('#wpadminbar')).toHaveCount(0);
  await expect(page.locator('body')).not.toHaveClass(/admin-bar/);

  await context.clearCookies();
});

test('the app still mounts once everything else is stripped out', async ({ page }) => {
  // Isolation that breaks the app is not isolation. This is the other half of
  // the requirement, and the half a dequeue-everything approach breaks.
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await page.goto('/blueworx-forge/');

  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible();
  expect(errors).toEqual([]);
});

test('Forge changes nothing on the rest of the site', async ({ page }) => {
  await page.goto('/');

  const assets = await page.evaluate(() =>
    [
      ...[...document.querySelectorAll('link[rel="stylesheet"]')].map((el) => el.href),
      ...[...document.querySelectorAll('script[src]')].map((el) => el.src),
    ].filter((url) => url.includes('blueworx-forge'))
  );

  expect(assets, `Forge is loading assets on a page that is not its own: ${assets.join(', ')}`).toEqual([]);
});
