import { test, expect } from '@playwright/test';
import { CLIENT_URL, requireEnvironment, signedIn } from './helpers/pair.js';

// #200 and #340 on the half that needs it most. A client site is somebody
// else's WordPress: nobody at the studio can edit its wp-config.php, and with
// hundreds of client sites nobody can enter a token on each. Releases come
// from a public repository now, so the site needs nothing — and what it says
// still matters: a client site that quietly never updates is one nobody finds
// out about until something else breaks.

test.beforeAll(() => {
  requireEnvironment();
});

const CONNECTION = '/wp-admin/admin.php?page=blueworx-forge-client-connection';

test.describe('a client site fetching its own updates', () => {
  test('needs no token, and says whether it can see a release', async ({ browser }) => {
    const { context } = await signedIn(browser, CLIENT_URL);
    const page = await context.newPage();

    await page.goto(CONNECTION);

    const status = page.locator('[data-bwx-updates]');

    await expect(status).toHaveAttribute('data-bwx-updates', /^(ok|missing|limited|unreachable)$/);
    await expect(status).not.toContainText('token');

    await expect(page.locator('#bwx-update-token')).toHaveCount(0);
    await expect(page.locator('form[data-bwx-update-token]')).toHaveCount(0);
    await expect(page.locator('[data-bwx-action="bwx_forge_client_forget_update_token"]')).toHaveCount(0);

    await expect(page.locator('[data-bwx-installed]')).toHaveAttribute('data-bwx-installed', /^\d+\.\d+\.\d+$/);

    await context.close();
  });
});
