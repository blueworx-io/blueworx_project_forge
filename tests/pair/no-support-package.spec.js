import { test, expect } from '@playwright/test';
import { asClientSite, connectedPair, makeItem, requireEnvironment } from './helpers/pair.js';
import * as Forge from '../e2e/helpers/forge.js';

// #151, COMM-2. A client with no package is restricted by the service, not by a
// hidden menu — proved with a real client site talking to a real studio,
// because that is the only place both halves of it are visible at once.
//
// The two halves are easy to get right separately and usually are not both
// right together:
//
// - the service refuses chargeable work, whatever any screen chooses to draw,
// - and reporting a bug, asking for something and reaching a person all keep
//   working, because a client with no package is a sales conversation rather
//   than a locked door.
//
// The second half is the one that quietly breaks. A rule written as "active
// clients can do things" is right about the money and wrong about everything
// else, and it takes a lapsed client's ability to tell us something is broken.

const RUN = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const HOME = '/wp-admin/admin.php?page=blueworx-forge-client';

test.beforeAll(requireEnvironment);

test('a client with no package is refused chargeable work at the API', async ({ browser }) => {
  test.setTimeout(420_000);

  const pair = await connectedPair(browser, 'No package', RUN);

  const primary = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `nopkgprimary${RUN}`);
  const reviewer = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `nopkgreviewer${RUN}`);
  const deliverer = await Forge.makePerson(pair.studio, pair.client.id, 'staff', `nopkgdeliverer${RUN}`);

  const item = await makeItem(pair.studio, pair.site.id, { title: `Chargeable ${RUN}` });

  const ready = await Forge.walkTo(
    pair.studio,
    item,
    ['triage', 'documentation-period', 'technical-audit', 'design-process'],
    {}
  );

  // Everything the gate into Up Next wants, and a real plan on it. The only
  // thing this site has not got is a package.
  const planned = await Forge.satisfy(pair.studio, ready, 'up-next', {
    primary_user_id: primary.id,
    reviewer_id: reviewer.id,
    deliverer_id: deliverer.id,
    planned_start: '2026-11-02',
    planned_due: '2026-11-06',
    hours_primary: 10,
  });

  const refused = await pair.studio.post(`/work-items/${planned.id}/transition`, {
    to: 'up-next',
    record_version: planned.record_version,
  });

  expect(refused.status(), await refused.text()).toBe(409);

  // And it did not half happen. Work everybody can see is scheduled, against a
  // client with no package to pay for it, is the state this must never leave.
  const unmoved = await pair.studio.get(`/work-items/${planned.id}`);

  expect(unmoved.item.stage).toBe('design-process');

  await pair.close();
});

test('and can still report a bug, ask for something, and reach their contact', async ({
  browser,
  request,
}) => {
  test.setTimeout(300_000);

  const pair = await connectedPair(browser, 'Still open', RUN);
  const signed = asClientSite(request, pair.issued);

  // The client site's own signature, which is how a client site proves who it
  // is (ARCH-6). No package anywhere in this test.
  const bug = await signed.post('/client/submissions', {
    type: 'bug',
    title: `The booking form has stopped sending ${RUN}`,
    description: 'Nothing arrives when somebody presses send.',
    submitted_by: 'Someone at the client',
  });

  expect(bug.status(), await bug.text()).toBe(200);

  const ask = await signed.post('/client/submissions', {
    type: 'request',
    title: `A page for the new opening hours ${RUN}`,
    description: 'We would like somewhere to put them.',
    submitted_by: 'Someone at the client',
  });

  expect(ask.status(), await ask.text()).toBe(200);

  const workspace = await (await signed.get('/client/workspace')).json();

  // The position, and both lists, sent by the studio rather than worked out at
  // this end. A client artifact deciding for itself which of its own buttons to
  // draw would be a second copy of a commercial rule.
  expect(workspace.support.state).toBe('none');
  expect(workspace.support.refused).toEqual(['chargeable-work']);
  expect(workspace.support.allowed).toContain('bug-intake');
  expect(workspace.support.allowed).toContain('request-intake');
  expect(workspace.support.allowed).toContain('sales');
  expect(workspace.support.allowed).toContain('point-of-contact');

  // Reaching a person is part of what stays open, so the route that names one
  // still answers.
  expect(workspace).toHaveProperty('contact');

  await pair.close();
});

test('the client is told, on their own screen, and told what they can still do', async ({
  browser,
}) => {
  test.setTimeout(300_000);

  const pair = await connectedPair(browser, 'Told plainly', RUN);
  const page = await pair.clientSite.context.newPage();

  await page.goto(HOME);

  /*
   * Said rather than hidden. A section quietly missing reads as a page that
   * failed to load, and a client who thinks the page is broken does not ring up
   * to buy a package — which makes hiding it the one behaviour that costs us
   * the sale this state exists to start.
   */
  await expect(page.locator('[data-bwx-support-state="none"]')).toBeVisible();

  const refused = page.locator('[data-testid="bwx-support-refused"]');

  await expect(refused).toBeVisible();
  await expect(refused).toContainText('report anything that is broken');
  await expect(refused).toContainText('talk to your contact');

  await page.close();
  await pair.close();
});
