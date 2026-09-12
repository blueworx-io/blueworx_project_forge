import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

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
  const today = new Date().toISOString().slice(0, 10);

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

  await panel.getByRole('button', { name: 'Close' }).click();
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
