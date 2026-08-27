import { test, expect } from '@playwright/test';
import { CLIENT_URL, requireEnvironment, signedIn } from './helpers/pair.js';

// #200 on the half that needs it most. A client site is somebody else's
// WordPress: nobody at the studio can edit its wp-config.php, so before this
// the only way it could ever be offered an update was to ask its administrator
// to add a line to a file on their server. What it says when it has no token
// matters just as much — a client site that quietly never updates is one nobody
// finds out about until something else breaks.

test.beforeAll(() => {
  requireEnvironment();
});

test.describe.configure({ mode: 'serial' });

const CONNECTION = '/wp-admin/admin.php?page=blueworx-forge-client-connection';
const TOKEN = 'github_pat_not_a_real_token';

async function clientAdmin(browser) {
  const { context } = await signedIn(browser, CLIENT_URL);

  return context.newPage();
}

// The token is site-wide state on a site that is kept between runs, so a run
// that left one behind would have the first assertion below pass or fail for
// reasons that have nothing to do with it.
async function withoutAToken(page) {
  await page.goto(CONNECTION);

  const remove = page.locator('[data-bwx-action="bwx_forge_client_forget_update_token"]');

  if (await remove.count()) {
    await remove.click();
    await expect(page.locator('[data-bwx-result="token_forgotten"]')).toBeVisible({ timeout: 30_000 });
  }
}

test.describe('a client site fetching its own updates', () => {
  test('says its updates are invisible rather than saying nothing', async ({ browser }) => {
    const page = await clientAdmin(browser);
    await withoutAToken(page);

    const status = page.locator('[data-bwx-updates]');

    await expect(status).toHaveAttribute('data-bwx-updates', 'none');
    await expect(status).toContainText('Updates cannot be fetched');

    await page.context().close();
  });

  test('takes a token in the browser, and never prints it back', async ({ browser }) => {
    const page = await clientAdmin(browser);
    await withoutAToken(page);

    await page.fill('#bwx-update-token', TOKEN);
    await page.click('form[data-bwx-update-token] input[type="submit"]');

    await expect(page.locator('[data-bwx-result="token_saved"]')).toBeVisible({ timeout: 30_000 });

    // Saying the connection was saved would be the wrong answer: this is a
    // different credential pointing somewhere else.
    await expect(page.locator('[data-bwx-result="connected"]')).toHaveCount(0);

    // "none" would mean the site never asked GitHub. Anything else means the
    // token it was given is the one it is now using.
    await expect(page.locator('[data-bwx-updates]')).not.toHaveAttribute('data-bwx-updates', 'none', {
      timeout: 30_000,
    });

    await expect(page.locator('#bwx-update-token')).toHaveValue('');
    await expect(page.content()).resolves.not.toContain(TOKEN);

    await page.context().close();
  });

  test('and can be told to forget it again', async ({ browser }) => {
    const page = await clientAdmin(browser);
    await page.goto(CONNECTION);

    await page.fill('#bwx-update-token', TOKEN);
    await page.click('form[data-bwx-update-token] input[type="submit"]');
    await expect(page.locator('[data-bwx-result="token_saved"]')).toBeVisible({ timeout: 30_000 });

    await page.click('[data-bwx-action="bwx_forge_client_forget_update_token"]');

    await expect(page.locator('[data-bwx-result="token_forgotten"]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('[data-bwx-updates]')).toHaveAttribute('data-bwx-updates', 'none');

    await page.context().close();
  });
});
