import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, makeItem, PASSWORD } from './helpers/forge.js';

// Deleting work. Only a WordPress administrator can, it takes everything
// under the item with it, and the panel only offers it to someone who may.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

async function openBoardOn(page, siteId) {
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', siteId);
  await expect(page.getByTestId('bwx-card')).toHaveCount(1);
}

test.describe('deleting work', () => {
  test('an administrator deletes an item and its children; staff cannot', async ({ browser, baseURL }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const { client, site } = await makeSite(admin.api, 'Delete Co', RUN_ID);

    const parent = (await (await makeItem(admin.api, site.id, { title: `Parent ${RUN_ID}`, level: 'feature' })).json()).item;
    const child = (
      await (
        await makeItem(admin.api, site.id, { title: `Child ${RUN_ID}`, level: 'sub-feature', parent_id: parent.id })
      ).json()
    ).item;
    const other = (await (await makeItem(admin.api, site.id, { title: `Other ${RUN_ID}` })).json()).item;

    // Staff on the client: may see and move the work, may not delete it.
    const staff = await makePerson(admin.api, client.id, 'staff', 'deleter');
    const asStaff = await signedIn(browser, baseURL, staff.login, PASSWORD);

    const refused = await asStaff.api.request.delete(`/wp-json/blueworx-forge/v1/work-items/${other.id}`, {
      headers: asStaff.api.headers,
    });
    expect(refused.status()).toBe(403);
    expect((await asStaff.api.get(`/work-items/${other.id}`)).item.id).toBe(other.id);

    const deleted = await admin.api.request.delete(`/wp-json/blueworx-forge/v1/work-items/${parent.id}`, {
      headers: admin.api.headers,
    });
    expect(deleted.status(), await deleted.text()).toBe(200);
    expect((await deleted.json()).deleted).toBe(2);

    const gone = await admin.api.request.get(`/wp-json/blueworx-forge/v1/work-items/${parent.id}`, {
      headers: admin.api.headers,
    });
    expect(gone.status()).toBe(404);
    const childGone = await admin.api.request.get(`/wp-json/blueworx-forge/v1/work-items/${child.id}`, {
      headers: admin.api.headers,
    });
    expect(childGone.status()).toBe(404);

    await asStaff.context.close();
    await admin.context.close();
  });

  test('the panel offers Delete to an administrator and not to staff', async ({ browser, baseURL, page }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const { client, site } = await makeSite(admin.api, 'Delete Panel Co', RUN_ID);
    const item = (await (await makeItem(admin.api, site.id, { title: `Panel item ${RUN_ID}` })).json()).item;

    await signIn(page, ADMIN_USER, ADMIN_PASS);
    await openBoardOn(page, site.id);
    await page.getByTestId('bwx-card').click();

    const panel = page.getByTestId('bwx-panel');
    await expect(panel).toBeVisible();
    const remove = page.getByTestId('bwx-item-delete');
    await expect(remove).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await remove.click();
    await expect(panel).toBeHidden();

    const gone = await admin.api.request.get(`/wp-json/blueworx-forge/v1/work-items/${item.id}`, {
      headers: admin.api.headers,
    });
    expect(gone.status()).toBe(404);

    await makeItem(admin.api, site.id, { title: `Kept item ${RUN_ID}` });
    const staff = await makePerson(admin.api, client.id, 'staff', 'watcher');
    const staffPage = await (await browser.newContext({ baseURL })).newPage();
    await signIn(staffPage, staff.login, PASSWORD);
    await openBoardOn(staffPage, site.id);
    await staffPage.getByTestId('bwx-card').click();
    await expect(staffPage.getByTestId('bwx-panel')).toBeVisible();
    await expect(staffPage.getByTestId('bwx-item-delete')).toHaveCount(0);

    await staffPage.context().close();
    await admin.context.close();
  });
});
