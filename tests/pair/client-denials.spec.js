import { test, expect } from '@playwright/test';
import { CLIENT_URL, connectedPair, disconnect, requireEnvironment, signedIn } from './helpers/pair.js';

// #134, on a real client site: when a client cannot do something, they are told
// why — and they are never shown a control that was going to be refused.
//
// The unit tests in tests/php/ClientDenialTest prove the vocabulary is complete;
// this proves the screens use it, which is a different claim and the one that
// actually reaches a person. A perfect sentence nothing prints is not messaging.
//
// Every screen in the workspace is visited in each of the two states a
// connected site can be put into from outside — cut off, and never connected —
// because "across the workspace" is the scope, and one screen that never
// learned about a state is exactly the failure this issue is about.

const RUN = `deny${Date.now()}`;

const SCREENS = [
  { name: 'the overview', page: 'blueworx-forge-client', shown: 'bwx-workspace-unavailable' },
  { name: 'the board', page: 'blueworx-forge-client-board', shown: 'bwx-work-unavailable' },
  { name: 'the timeline', page: 'blueworx-forge-client-timeline', shown: 'bwx-work-unavailable' },
  { name: 'the calendar', page: 'blueworx-forge-client-calendar', shown: 'bwx-work-unavailable' },
  { name: 'what you asked for', page: 'blueworx-forge-client-asked', shown: 'bwx-asked-unavailable' },
];

const ASK = '/wp-admin/admin.php?page=blueworx-forge-client-ask';

test.beforeAll(requireEnvironment);

/** Cuts a connected site off at the studio's end, the way revoking a key does. */
async function revoke(pair) {
  const revoked = await pair.studio.post(
    `/sites/${pair.issued.integration.registry_site_id}/revoke`,
    {}
  );

  expect(revoked.status(), await revoked.text()).toBe(200);
}

/** Asks a screen again rather than waiting out the staleness window. */
function fresh(page) {
  return page.locator('a', { hasText: 'Check again' }).first();
}

test.describe('what a client is told when something is not theirs', () => {
  test.beforeEach(() => {
    test.slow();
  });

  test('a refused site is told its connection is working, on every screen', async ({ browser }) => {
    const pair = await connectedPair(browser, 'Refused Co', RUN);
    const page = await pair.clientSite.context.newPage();

    // Read everything once while it works, so each screen has a copy to fall
    // back on. That is the case that matters: a screen with nothing cached
    // could say anything and be forgiven, and a screen holding a stale copy is
    // the one that used to show it and blame the network.
    for (const screen of SCREENS) {
      await page.goto(`/wp-admin/admin.php?page=${screen.page}`);
    }

    await revoke(pair);

    for (const screen of SCREENS) {
      await page.goto(`/wp-admin/admin.php?page=${screen.page}`);

      // Ask again on purpose, which is what the screen's own link does.
      if (0 < (await fresh(page).count())) {
        await fresh(page).click();
      }

      const denial = page.getByTestId(screen.shown);

      await expect(denial, `${screen.name} says nothing`).toBeVisible();
      await expect(denial, `${screen.name} does not say it was refused`).toHaveAttribute(
        'data-bwx-denial',
        'refused'
      );
      await expect(denial, `${screen.name} lets a refusal read as an outage`).toContainText(
        'connection is working'
      );

      // And no machine language anywhere on the page.
      await expect(page.locator('body')).not.toContainText('bwx_forge');
    }

    await page.close();
    await pair.close();
  });

  test('a refused site is not offered a control that cannot help', async ({ browser }) => {
    const pair = await connectedPair(browser, 'Dead Control Co', RUN);
    const page = await pair.clientSite.context.newPage();

    await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-board');
    await revoke(pair);
    await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-board');

    if (0 < (await fresh(page).count())) {
      await fresh(page).click();
    }

    // "Check again" gets the same answer every time, so it is not offered.
    await expect(page.locator('[data-bwx-sync-state="refused"]')).toBeVisible();
    await expect(fresh(page), 'a refused screen still offers "check again"').toHaveCount(0);

    await page.close();
    await pair.close();
  });

  test('an unconnected site says so, and offers the screen that fixes it', async ({ browser }) => {
    // A fresh browser rather than a connected pair: this is a site nobody has
    // introduced to a studio at all, which is a different state from one that
    // has been turned away.
    const clientSite = await signedIn(browser, CLIENT_URL);
    const page = await clientSite.context.newPage();

    // Whatever an earlier spec left behind. A client site keeps one connection,
    // so the way to have none is to forget it.
    await disconnect(clientSite);

    for (const screen of SCREENS) {
      await page.goto(`/wp-admin/admin.php?page=${screen.page}`);

      const denial = page.getByTestId(screen.shown);

      await expect(denial, `${screen.name} says nothing`).toBeVisible();
      await expect(denial).toHaveAttribute('data-bwx-denial', 'not_configured');

      // Something to do about it, on every screen that has nothing to show.
      await expect(
        denial.locator('a', { hasText: 'Connect this site' }),
        `${screen.name} says what is wrong but not where to fix it`
      ).toHaveCount(1);
    }

    await page.close();
    await clientSite.context.close();
  });

  test('an unconnected site is not shown a form it cannot send', async ({ browser }) => {
    const clientSite = await signedIn(browser, CLIENT_URL);
    const page = await clientSite.context.newPage();

    await disconnect(clientSite);

    await page.goto(ASK);

    // The form used to be drawn and refused on submit, which is the worst of
    // both: three paragraphs written into boxes that were never going to work.
    await expect(page.getByTestId('bwx-ask-form')).toHaveCount(0);
    await expect(page.getByTestId('bwx-ask-unavailable')).toBeVisible();
    await expect(page.getByTestId('bwx-ask-unavailable')).toContainText('not connected');

    await page.close();
    await clientSite.context.close();
  });

  test("work that is not this site's is refused in the same words as work that is not there", async ({
    browser,
  }) => {
    const pair = await connectedPair(browser, 'Comparing Co', RUN);

    const theirs = await (
      await pair.studio.post('/clients', {
        display_name: `Hidden Co ${RUN}`,
        timezone: 'Europe/London',
      })
    ).json();

    const theirSite = await (
      await pair.studio.post(`/clients/${theirs.client.id}/sites`, {
        name: `Hidden site ${RUN}`,
        url: 'https://elsewhere.test',
      })
    ).json();

    const theirWork = await (
      await pair.studio.post('/work-items', {
        client_site_id: theirSite.site.id,
        level: 'sub-feature',
        work_type: 'feature',
        title: `Hidden work ${RUN}`,
        problem: 'Theirs.',
      })
    ).json();

    const page = await pair.clientSite.context.newPage();
    const item = '/wp-admin/admin.php?page=blueworx-forge-client-board&item=';

    await page.goto(`${item}wrk_nothing_at_all`);
    const missing = await page.getByTestId('bwx-item-missing').textContent();

    await page.goto(item + theirWork.item.id);
    const hidden = await page.getByTestId('bwx-item-missing').textContent();

    // Word for word. Two sentences that differ at all are two answers, and a
    // client comparing them learns which ids are real elsewhere (D-1, D-2).
    expect(hidden).toBe(missing);
    await expect(page.locator('body')).not.toContainText(`Hidden work ${RUN}`);

    await page.close();
    await pair.close();
  });
});
