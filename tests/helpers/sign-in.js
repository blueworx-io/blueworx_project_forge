// The one way a spec signs in to WordPress.
//
// WordPress's login page focuses and selects the username box 200ms after it
// starts loading. Playwright fills a box in two steps — focus it, then type —
// and when the test server is slow that timer lands between the two, so the
// password is typed into the username box and the login never happens (#294).
// Waiting for the focus to arrive before touching the form removes the race.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

/**
 * Signs `page` in, as the site's admin unless told otherwise. `site` is for a
 * page whose baseURL is not the WordPress being signed in to.
 */
export async function signIn(page, user = ADMIN_USER, pass = ADMIN_PASS, site = '') {
  await page.goto(`${site}/wp-login.php?loggedout=true`);
  // A site can turn the autofocus off (`enable_login_autofocus`), so a page
  // where it never arrives is filled after a short wait rather than failed.
  await page
    .waitForFunction(() => document.activeElement?.id === 'user_login', null, { timeout: 5000 })
    .catch(() => {});
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}
