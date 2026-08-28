import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, requireEnvironment, studioSite } from './helpers/pair.js';

// #140. A client can find out whether there is room without finding out
// anything about anybody else.
//
// The acceptance is negative and that is the point of putting it here rather
// than in a studio spec: the answer is asked for with a real client site's real
// signature, from the artifact a client actually runs, and then checked for
// everything it must not contain. A leak test run against a mocked caller
// proves the projection, not the route.

const RUN = `avail${Date.now()}`;
const MARKER = `Confidential ${RUN}`;

test.beforeAll(requireEnvironment);

test('a client is told whether there is room, and nothing else', async ({ browser, request }) => {
  const pair = await connectedPair(browser, 'Availability', RUN);

  // Somebody else's work, titled with something nothing may repeat back.
  const other = await studioSite(pair.studio, `Other ${MARKER}`, RUN);

  await makeItem(pair.studio, other.site.id, {
    title: MARKER,
    planned_start: '2026-09-07',
    planned_due: '2026-09-11',
    hours_primary: 6,
  });

  const signed = asClientSite(request, pair.issued);
  const response = await signed.get('/client/availability?from=2026-09-07&to=2026-10-04');

  expect(response.status(), await response.text()).toBe(200);

  const answer = await response.json();

  expect(['room', 'tight', 'none']).toContain(answer.availability);
  expect(Object.keys(answer).sort()).toEqual(['availability', 'earliest', 'from', 'to']);

  const body = JSON.stringify(answer);

  expect(body, 'no other client name').not.toContain(MARKER);
  expect(body, 'no work title').not.toContain('Confidential');
  expect(body, 'no id of any kind').not.toMatch(/(cli|cst|wrk|usr|avp)_/);
  expect(body, 'no hours figure').not.toMatch(/"(hours|available|committed|remaining)"/);

  await pair.close();
});

test('an unsigned caller is refused', async ({ request }) => {
  const response = await request.get('/wp-json/blueworx-forge/v1/client/availability');

  expect(response.status(), 'the answer is for a registered site, not for anyone who asks').toBe(401);
});

test('the client site shows the answer where somebody is about to ask', async ({ browser }) => {
  const pair = await connectedPair(browser, 'AvailabilityScreen', RUN);

  const page = await pair.clientSite.context.newPage();

  await page.goto('/wp-admin/admin.php?page=blueworx-forge-client-ask');

  const notice = page.locator('[data-bwx-availability]');

  await expect(notice).toBeVisible();
  await expect(notice).toContainText(/room|tight|no room/i);

  await page.close();
  await pair.close();
});
