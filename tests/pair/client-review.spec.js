import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, requireEnvironment } from './helpers/pair.js';
import { checkAccessibility } from '../helpers/accessibility.js';

// #391. The client as reviewer, across the two sites: work the studio asks the
// client to review shows on their Forge page under "Needs your review", where
// they approve it or send it back with a note. That is the one decision a
// client site can make about work (D-14a), and only for work waiting on them.

const RUN = `review${Date.now()}`;
const PAGE = '/forge/#board';

test.beforeAll(requireEnvironment);

test.beforeEach(() => {
  test.setTimeout(360_000);
});

/** A task on the pair's site, in review with the client reviewing it. */
async function inClientReview(pair, title) {
  const made = await makeItem(pair.studio, pair.site.id, { title });
  const move = async (item, to) => {
    const moved = await pair.studio.post(`/work-items/${item.id}/override`, {
      to,
      reason: 'Set up for the test.',
      record_version: item.record_version,
    });
    expect(moved.status(), await moved.text()).toBe(200);

    return (await moved.json()).item;
  };

  const planned = await move(made, 'up-next');
  const edited = await pair.studio.patch(`/work-items/${planned.id}`, {
    reviewer_id: 'client',
    record_version: planned.record_version,
  });
  expect(edited.status(), await edited.text()).toBe(200);

  return move((await edited.json()).item, 'in-review');
}

/** The client's board, read afresh, with the review list in view. */
async function board(pair) {
  const page = await pair.clientSite.context.newPage();
  await page.goto(PAGE);
  await expect(page.getByTestId('bwx-board-screen')).toBeVisible();
  await expect(page.getByTestId('bwx-board')).toBeVisible({ timeout: 60_000 });

  return page;
}

test('the client approves on their own site, and the studio task is completed', async ({ browser }) => {
  const pair = await connectedPair(browser, 'Approving Co', RUN);
  const title = `Check the new menu ${RUN}`;
  const item = await inClientReview(pair, title);

  const page = await board(pair);
  const list = page.getByTestId('bwx-needs-review');
  await expect(list).toContainText('Needs your review');

  const row = page.getByTestId('bwx-review-item').filter({ hasText: title });
  await expect(row).toBeVisible();
  await checkAccessibility(page, 'Client review list', 'app');
  await row.getByRole('button', { name: 'Approve' }).click();

  await expect
    .poll(async () => (await pair.studio.get(`/work-items/${item.id}`)).item.stage, { timeout: 60_000 })
    .toBe('completed');

  // Once decided, it leaves the list.
  await expect(page.getByTestId('bwx-review-item').filter({ hasText: title })).toHaveCount(0, { timeout: 60_000 });

  const detail = await pair.studio.get(`/work-items/${item.id}`);
  const entry = detail.history.find((one) => 'moved' === one.action && 'completed' === one.to_stage);
  expect(entry.via).toBe('client');
  expect(entry.reason).toMatch(/^Approved by the client/);

  await page.close();
  await pair.close();
});

test('the client sends one back with a note, and it is a new review attempt', async ({ browser }) => {
  const pair = await connectedPair(browser, 'Returning Co', RUN);
  const title = `Check the contact page ${RUN}`;
  const item = await inClientReview(pair, title);

  const page = await board(pair);
  const row = page.getByTestId('bwx-review-item').filter({ hasText: title });
  await row.getByRole('button', { name: 'Send back' }).click();

  // The note is required: the button waits for it.
  const send = row.getByRole('button', { name: 'Send back to the studio' });
  await expect(send).toBeDisabled();
  await row.getByLabel('What needs to change').fill(`The phone number is wrong ${RUN}`);
  await send.click();

  await expect
    .poll(async () => (await pair.studio.get(`/work-items/${item.id}`)).item.stage, { timeout: 60_000 })
    .toBe('in-development');
  await expect(page.getByTestId('bwx-review-item').filter({ hasText: title })).toHaveCount(0, { timeout: 60_000 });

  const detail = await pair.studio.get(`/work-items/${item.id}`);
  expect(detail.item.review_attempt).toBe(item.review_attempt + 1);

  const entry = detail.history.find((one) => 'returned' === one.action);
  expect(entry.via).toBe('client');
  expect(entry.detail).toBe(`The phone number is wrong ${RUN}`);

  await page.close();
  await pair.close();
});

test('work not waiting on the client cannot be approved from their site', async ({ browser, request }) => {
  const pair = await connectedPair(browser, 'Not Waiting Co', RUN);
  const site = asClientSite(request, pair.issued);
  const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;

  const refused = await site.post(`/client/work-items/${pair.work.id}/review`, { decision: 'approve', author_name: 'Someone' });
  expect(refused.status()).toBe(409);

  const after = (await pair.studio.get(`/work-items/${pair.work.id}`)).item;
  expect(after.stage).toBe(before.stage);
  expect(after.record_version).toBe(before.record_version);

  // And nothing about it shows as waiting on their review.
  const page = await board(pair);
  await expect(page.getByTestId('bwx-review-item').filter({ hasText: before.title })).toHaveCount(0);

  await page.close();
  await pair.close();
});
