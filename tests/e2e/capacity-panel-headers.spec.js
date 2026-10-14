import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #520. The day panel's section headers ("Still to do", "Done") stand out from
// the entries beneath them: each is its own header element with the total
// beside it, styled as a header rather than as another line in the list.

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

function localToday() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');

  return `${now.getFullYear()}-${month}-${day}`;
}

test('each section of the day panel has a header that stands apart from its entries', async ({ browser, baseURL }) => {
  test.setTimeout(300_000);

  const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const today = localToday();
  const { context, api } = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await Forge.makeSite(api, 'Headers Co', RUN_ID);
  await Forge.onSupport({ context, api }, where.site.id, 400);

  const person = await Forge.makePerson(api, where.client.id, 'staff', `head${RUN_ID.replace('-', '')}`);
  await Forge.setHours(api, person.id, 8);

  const made = (await (await Forge.makeItem(api, where.site.id, { title: `Headed ${RUN_ID}` })).json()).item;
  const seats = { ...(await Forge.seatsFor(api, made)), primary_user_id: person.id, planned_start: today, planned_due: today, hours_primary: 1 };
  await Forge.walkTo(api, made, ['triage', 'documentation-period', 'up-next', 'design-process', 'in-development'], { seats });

  const page = await context.newPage();
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-capacity').click();
  await expect(page.getByTestId('bwx-capacity-grid')).toBeVisible({ timeout: 30_000 });
  await page.getByTestId(`bwx-capacity-cell-${person.id}-${today}`).click();

  const panel = page.getByTestId('bwx-capacity-drilldown');

  for (const [section, name] of [['bwx-capacity-todo', 'Still to do'], ['bwx-capacity-done', 'Done']]) {
    const head = panel.getByTestId(`${section}-head`);

    await expect(head).toBeVisible();
    await expect(head).toContainText(name);
    // A header element, with the total beside it.
    expect(await head.evaluate((node) => node.tagName)).toBe('H3');
    await expect(head.locator('.bwx-capacity-of')).toBeVisible();
    // Set apart from the entries: upper-case, on a rule.
    expect(await head.evaluate((node) => getComputedStyle(node).textTransform)).toBe('uppercase');
    expect(await head.evaluate((node) => getComputedStyle(node).borderBottomWidth)).not.toBe('0px');
  }

  // The entry beneath is not dressed as a header.
  const entry = panel.getByTestId('bwx-capacity-todo').locator('li', { hasText: `Headed ${RUN_ID}` });
  await expect(entry).toHaveCount(1);
  expect(await entry.locator('strong').evaluate((node) => getComputedStyle(node).textTransform)).toBe('none');

  await page.close();
  await context.close();
});
