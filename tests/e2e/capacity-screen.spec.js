import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import * as Forge from './helpers/forge.js';

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

// Today as the browser sees it. The config sets no timezone, so the browser
// runs on this machine's, and so does this. UTC would be a day out just after
// midnight.
function localToday() {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

// #139's acceptance: the view reconciles to the allocations behind it, and
// drill-down explains every number. So the spec reads a cell, opens it, and
// checks the panel is talking about the same period.

async function openCapacity(page) {
  await signIn(page);

  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-capacity').click();

  // The grid is waited for here rather than in each test. The first request
  // after a page load pays this instance's cold start, which is longer than the
  // default expect timeout and has nothing to do with what is being proved.
  const grid = page.getByTestId('bwx-capacity-grid');

  await expect(grid).toBeVisible({ timeout: 30_000 });

  return grid;
}

test('the studio can see who has room', async ({ page }) => {
  const grid = await openCapacity(page);

  // A week per column, and a person per row. Both come from the server, so a
  // grid with no columns means the range never reached it.
  await expect(grid.locator('thead th')).not.toHaveCount(0);
  await expect(grid.locator('tbody tr')).not.toHaveCount(0);
});

test('it opens on the next fourteen days, today first, and can be read by week instead', async ({ page }) => {
  const grid = await openCapacity(page);
  const today = localToday();

  // A day per column, starting today: the question is "who has room now",
  // not "who had room on Monday".
  await expect(page.getByTestId('bwx-capacity-by-days')).toHaveAttribute('aria-pressed', 'true');
  await expect(grid.locator('thead th[data-from]')).toHaveCount(14);
  await expect(grid.locator('thead th[data-from]').first()).toHaveAttribute('data-from', today);

  await page.getByTestId('bwx-capacity-by-weeks').click();

  await expect(page.getByTestId('bwx-capacity-by-weeks')).toHaveAttribute('aria-pressed', 'true');
  await expect(grid.locator('thead th[data-from]')).toHaveCount(8);
});

test('every figure opens to the work behind it', async ({ page }) => {
  const grid = await openCapacity(page);

  const cell = grid.locator('.bwx-capacity-cell').first();

  await expect(cell).toBeVisible();
  await cell.click();

  const panel = page.getByTestId('bwx-capacity-drilldown');

  await expect(panel).toBeVisible();

  // The panel names the period it is explaining, so a number and its
  // explanation cannot be about two different weeks.
  await expect(panel.locator('.bwx-eyebrow')).toContainText(/\d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2}/);

  await panel.getByRole('button', { name: 'Close' }).first().click();
  await expect(panel).toBeHidden();
});

test('a person nobody has set up says so rather than showing no time', async ({ page }) => {
  const grid = await openCapacity(page);

  const unset = grid.locator('[data-band="unrecorded"]').first();

  // Whether anybody on this instance is unrecorded depends on what other specs
  // have created, so this asserts the wording only where the state exists. The
  // distinction itself is proved without a browser in CapacityPositionTest.
  if (0 < (await unset.count())) {
    await expect(unset).toContainText(/hours not set/i);
  }
});

// #384, #385, #387 together, because they are one picture: a day with one
// piece of work still to do and one finished. The bar shows both, the panel
// splits them with their hours, each task opens, and ten minutes reads as
// "10 min". The run id keeps a reused instance from answering for this run.
test('a day shows what is done beside what is still to do, and every task opens', async ({ browser, baseURL }) => {
  test.setTimeout(300_000);

  const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const STAMP = RUN_ID.replace('-', '');
  const today = localToday();
  const { context, api } = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await Forge.makeSite(api, 'Capacity day', RUN_ID);

  await Forge.onSupport({ context, api }, where.site.id, 400);

  const person = await Forge.makePerson(api, where.client.id, 'staff', `day${STAMP}`);
  const reviewer = await Forge.makePerson(api, where.client.id, 'staff', `dayreviewer${STAMP}`);
  const deliverer = await Forge.makePerson(api, where.client.id, 'staff', `daydeliverer${STAMP}`);

  await Forge.setHours(api, person.id, 8);

  const plan = async (title, hours) => {
    const created = await Forge.makeItem(api, where.site.id, { title });
    expect(created.status(), await created.text()).toBe(200);

    return Forge.walkTo(
      api,
      (await created.json()).item,
      ['triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next', 'in-development'],
      {
        seats: {
          primary_user_id: person.id,
          reviewer_id: reviewer.id,
          deliverer_id: deliverer.id,
          planned_start: today,
          planned_due: today,
          hours_primary: hours,
        },
      }
    );
  };

  await plan(`Still to do ${RUN_ID}`, 0.17);
  const finished = await plan(`Already done ${RUN_ID}`, 1.5);

  const done = await api.post(`/work-items/${finished.id}/override`, {
    to: 'completed',
    reason: 'Finished for the capacity screen spec.',
    record_version: finished.record_version,
  });
  expect(done.status(), await done.text()).toBe(200);

  const page = await context.newPage();

  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-capacity').click();
  await expect(page.getByTestId('bwx-capacity-grid')).toBeVisible({ timeout: 30_000 });

  // The legend says which colour is which, so the bar does not rely on hue.
  await expect(page.getByTestId('bwx-capacity-legend')).toContainText('Done');

  const cell = page.getByTestId(`bwx-capacity-cell-${person.id}-${today}`);

  await expect(cell.getByTestId('bwx-capacity-bar-done')).toBeVisible();
  await expect(cell.getByTestId('bwx-capacity-bar-todo')).toBeVisible();

  // No decimal hours anywhere on the screen.
  await expect(page.getByTestId('bwx-capacity-grid')).not.toContainText(/\d\.\d/);

  await cell.click();

  const panel = page.getByTestId('bwx-capacity-drilldown');
  const todo = panel.getByTestId('bwx-capacity-todo');
  const doneList = panel.getByTestId('bwx-capacity-done');

  await expect(todo).toContainText('Still to do');
  await expect(todo).toContainText('10 min');
  await expect(todo).toContainText(`Still to do ${RUN_ID}`);
  await expect(doneList).toContainText('Done');
  await expect(doneList).toContainText('1h 30m');
  await expect(doneList).toContainText(`Already done ${RUN_ID}`);
  await expect(panel).not.toContainText(/\d\.\d/);

  await doneList.getByRole('button', { name: new RegExp(`Already done ${RUN_ID}`) }).click();

  const itemPanel = page.getByTestId('bwx-panel');

  await expect(itemPanel).toBeVisible();
  await expect(itemPanel).toContainText(`Already done ${RUN_ID}`);

  await context.close();
});
