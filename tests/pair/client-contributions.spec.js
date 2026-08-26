import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';

// #133, proven across two real WordPress sites: a client can comment, attach
// evidence and answer something we asked, and none of it moves the work.
//
// That last clause is why this is a pair test rather than a studio one. Every
// half of it is provable on its own — the rules refuse a stage, the routes have
// none — and neither half proves the thing that matters, which is that a person
// typing into a form on their own WordPress cannot end up moving a card on
// ours. The only way to know is to have both sites, and to look at the stage
// before and after.

const RUN = `say${Date.now()}`;

const ITEM = '/wp-admin/admin.php?page=blueworx-forge-client-board&item=';

test.beforeAll(requireEnvironment);

test.describe('what a client may add to their own work', () => {
  // Two cold WordPress installs, a client, a site, a key and a connection
  // before the first assertion — on PHP's single-threaded built-in server.
  test.beforeEach(() => {
    test.slow();
  });

  test('a comment reaches the studio and the work does not move', async ({ browser }) => {
    const pair = await connectedPair(browser, 'Talking Co', RUN, {
      title: `A thing being done ${RUN}`,
    });

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item.stage;

    const page = await pair.clientSite.context.newPage();

    await page.goto(ITEM + pair.work.id);
    await expect(page.getByTestId('bwx-say-form')).toBeVisible();

    await page.fill('[data-testid="bwx-say-form"] textarea', `Any news on this? ${RUN}`);
    await page.click('[data-testid="bwx-say-form"] #submit');

    await expect(page.locator('[data-bwx-result="added"]')).toHaveCount(1);

    // The studio can read it.
    const detail = await pair.studio.get(`/work-items/${pair.work.id}`);
    const said = detail.comments.map((one) => one.body);

    expect(said).toContain(`Any news on this? ${RUN}`);

    // And the work is exactly where it was. This is the assertion the issue is
    // closed on.
    expect(detail.item.stage).toBe(before);

    await pair.close();
  });

  test('evidence carries a link, and still nothing moves', async ({ browser }) => {
    const pair = await connectedPair(browser, 'Showing Co', RUN);

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item.stage;

    const page = await pair.clientSite.context.newPage();

    await page.goto(ITEM + pair.work.id);
    await page.fill('[data-testid="bwx-say-form"] textarea', 'This is the page it happens on.');
    await page.fill('[data-testid="bwx-say-form"] input[type="url"]', 'https://example.test/broken');
    await page.click('[data-testid="bwx-say-form"] #submit');

    await expect(page.locator('[data-bwx-result="added"]')).toHaveCount(1);

    const detail = await pair.studio.get(`/work-items/${pair.work.id}`);
    const evidence = detail.comments.find((one) => 'evidence' === one.kind);

    expect(evidence, 'no evidence reached the studio').toBeTruthy();
    expect(evidence.url).toBe('https://example.test/broken');
    expect(evidence.from_client).toBe(true);
    expect(detail.item.stage).toBe(before);

    await pair.close();
  });

  test('the studio asks, the client answers, and the question stops asking', async ({ browser }) => {
    const pair = await connectedPair(browser, 'Asking Co', RUN);

    const before = (await pair.studio.get(`/work-items/${pair.work.id}`)).item.stage;

    const asked = await pair.studio.post(`/work-items/${pair.work.id}/comments`, {
      kind: 'question',
      body: `Which page is this happening on? ${RUN}`,
    });

    expect(asked.status(), await asked.text()).toBe(200);

    const page = await pair.clientSite.context.newPage();

    await page.goto(ITEM + pair.work.id);

    // The client is told, at the top, rather than having to find it in a thread.
    await expect(page.getByTestId('bwx-questions')).toBeVisible();
    await expect(page.getByTestId('bwx-question')).toContainText(
      `Which page is this happening on? ${RUN}`
    );

    await page.fill('[data-testid="bwx-answer-form"] textarea', 'The bookings page.');
    await page.click('[data-testid="bwx-answer-form"] #submit');

    await expect(page.locator('[data-bwx-result="added"]')).toHaveCount(1);

    // Answered questions stop being asked, so the screen does not keep
    // demanding something already given.
    await expect(page.getByTestId('bwx-questions')).toHaveCount(0);
    await expect(page.getByTestId('bwx-thread')).toContainText('The bookings page.');

    const detail = await pair.studio.get(`/work-items/${pair.work.id}`);
    const answer = detail.comments.find((one) => 'The bookings page.' === one.body);

    expect(answer, 'no answer reached the studio').toBeTruthy();
    expect(answer.answers, 'the answer is not linked to the question').not.toBe('');
    expect(detail.item.stage).toBe(before);

    await pair.close();
  });

  test('a client never sees an internal note, before or after contributing', async ({ browser }) => {
    const pair = await connectedPair(browser, 'Private Co', RUN);

    const noted = await pair.studio.post(`/work-items/${pair.work.id}/comments`, {
      body: `Do not show the client this ${RUN}`,
      visibility: 'internal',
    });

    expect(noted.status(), await noted.text()).toBe(200);

    const page = await pair.clientSite.context.newPage();

    await page.goto(ITEM + pair.work.id);

    await expect(page.locator('body')).not.toContainText(`Do not show the client this ${RUN}`);

    await page.fill('[data-testid="bwx-say-form"] textarea', 'Anything I can help with?');
    await page.click('[data-testid="bwx-say-form"] #submit');

    await expect(page.locator('[data-bwx-result="added"]')).toHaveCount(1);
    await expect(page.locator('body')).not.toContainText(`Do not show the client this ${RUN}`);

    await pair.close();
  });

  test("another client's work is not reachable from this site", async ({ browser }) => {
    const pair = await connectedPair(browser, 'Ours Co', RUN);

    // A second client on the studio, with work of its own, and no connection
    // to the site under test.
    const theirs = await (
      await pair.studio.post('/clients', {
        display_name: `Theirs Co ${RUN}`,
        timezone: 'Europe/London',
      })
    ).json();

    const theirSite = await (
      await pair.studio.post(`/clients/${theirs.client.id}/sites`, {
        name: `Theirs site ${RUN}`,
        url: 'https://elsewhere.test',
      })
    ).json();

    const theirWork = await (
      await pair.studio.post('/work-items', {
        client_site_id: theirSite.site.id,
        level: 'sub-feature',
        work_type: 'feature',
        title: `Their private work ${RUN}`,
        problem: 'Theirs.',
      })
    ).json();

    const page = await pair.clientSite.context.newPage();

    await page.goto(ITEM + theirWork.item.id);

    // The same sentence an id nobody has ever used would get, and no form to
    // write into (D-1, D-2).
    await expect(page.getByTestId('bwx-item-missing')).toBeVisible();
    await expect(page.getByTestId('bwx-say-form')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText(`Their private work ${RUN}`);

    await pair.close();
  });
});
