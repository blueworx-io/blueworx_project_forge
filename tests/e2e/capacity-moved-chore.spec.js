import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #650. A chore ticked off after its due date was moved used to count on one
// day in the grid and a different one in the panel behind it. Today's cell
// and its panel now show the same time, and time that is all done says so.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

// Today as the browser sees it, which is where the grid starts.
function localToday() {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function dayAfter(date) {
  const at = new Date(`${date}T12:00:00Z`);

  at.setUTCDate(at.getUTCDate() + 1);

  return at.toISOString().slice(0, 10);
}

test('a finished chore whose date was moved reads the same in its cell and its panel', async ({ browser, baseURL }) => {
  test.setTimeout(300_000);

  const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const STAMP = RUN_ID.replace('-', '');
  const today = localToday();
  const { context, api } = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const where = await Forge.makeSite(api, 'Capacity moved chore', RUN_ID);
  const person = await Forge.makePerson(api, where.client.id, 'staff', `moved${STAMP}`);

  await Forge.setHours(api, person.id, 8);

  // Two chores for today only, so nothing is left running on a reused site.
  const chore = async (title, hours) => {
    const made = await api.post('/recurring', {
      title,
      description: '<p>For the capacity spec.</p>',
      rule: { every: 'day' },
      starts_on: today,
      ends_on: today,
      assignees: [person.id],
      hours_each: hours,
      client_site_id: where.site.id,
    });
    expect(made.status(), await made.text()).toBe(200);
  };

  const movedTitle = `Moved chore ${RUN_ID}`;
  const todayTitle = `Today's chore ${RUN_ID}`;

  await chore(movedTitle, '0.25');
  await chore(todayTitle, '0.17');
  await api.post('/recurring/run', {});

  const items = (await api.get(`/work-items?client_site_id=${where.site.id}`)).items;
  const moved = items.find((item) => item.title.startsWith(movedTitle));
  const done = items.find((item) => item.title.startsWith(todayTitle));

  expect(moved, 'the moved chore was made').toBeTruthy();
  expect(done, 'today\'s chore was made').toBeTruthy();

  // Due tomorrow instead, still starting today, then both ticked off.
  const dated = await api.patch(`/work-items/${moved.id}`, { planned_due: dayAfter(today), record_version: moved.record_version });
  expect(dated.status(), await dated.text()).toBe(200);

  for (const item of [moved, done]) {
    const ticked = await api.post(`/work-items/${item.id}/tick`, { user_id: person.id, done: true });
    expect(ticked.status(), await ticked.text()).toBe(200);
  }

  const page = await context.newPage();

  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-capacity').click();
  await expect(page.getByTestId('bwx-capacity-grid')).toBeVisible({ timeout: 30_000 });

  // The cell: today's chore only, and it says the time is done.
  const cell = page.getByTestId(`bwx-capacity-cell-${person.id}-${today}`);

  await expect(cell.locator('.bwx-capacity-committed')).toHaveText('10 min');
  await expect(cell.getByTestId('bwx-capacity-all-done')).toBeVisible();
  await expect(cell).toHaveAttribute('aria-label', /all done$/);

  // The panel behind it: the same 10 minutes, and the moved chore not listed.
  await cell.click();

  const panel = page.getByTestId('bwx-capacity-drilldown');
  const doneList = panel.getByTestId('bwx-capacity-done');

  await expect(panel).toBeVisible();
  await expect(panel.locator('.bwx-capacity-summary')).toContainText('0h still to do and 10 min done');
  await expect(panel.getByTestId('bwx-capacity-done-head')).toContainText('10 min');
  await expect(doneList).toContainText(todayTitle);
  await expect(doneList).not.toContainText(movedTitle);

  await context.close();
});
