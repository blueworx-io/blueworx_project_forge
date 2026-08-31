import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #175. The in-product signals that do not warrant an email.
//
// "Each event notifies exactly the users entitled to see the underlying record"
// is the criterion, and *exactly* points both ways. The obvious half is that
// somebody who cannot read a client must not be told what happened on it. The
// half that is easier to get wrong is the other one: a person who can read it
// must actually be told, and a scoping bug that hides everything passes a test
// that only checks for leaks.
//
// So the first test proves a signal arrives, the second proves it does not
// arrive anywhere it should not, and the third proves the count means what it
// says. Nothing here is stored per person — the list is worked out each time
// from records the reader may see now — so all three are really the same
// question asked from different seats.
//
// The instance is kept between runs and other specs leave work behind, so every
// assertion here is scoped to this run's own items.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** A client, a site, and a colleague who can move work on it. */
async function withColleague(browser, baseURL, label) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, `${label} ${RUN_ID}`, `${RUN_ID}${label}`);
  const crew = await Forge.team(admin.api, browser, baseURL, client.id);

  return { admin, client, site, crew, colleague: crew.as.completed };
}

/** One piece of work, moved on by whoever is given. */
async function movedBy(api, siteId, title) {
  const made = await Forge.makeItem(api, siteId, { title });

  expect(made.status(), await made.text()).toBe(200);

  return Forge.walkTo(api, (await made.json()).item, [ 'triage' ]);
}

/** What one person is told has happened lately. */
async function lately(api) {
  return api.get('/signals');
}

test('something a colleague did turns up, and your own doing does not', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, crew, colleague } = await withColleague(browser, baseURL, 'Signals Co');

  // Two identical pieces of work, moved on by two different people.
  const theirs = await movedBy(colleague, site.id, `Their thing ${RUN_ID}`);
  const mine = await movedBy(admin.api, site.id, `My thing ${RUN_ID}`);

  const answer = await lately(admin.api);

  expect(answer.denied).toBe(false);

  const about = (id) => (answer.signals ?? []).filter((one) => one.subject_id === id);

  // Somebody else moved this on. That is news.
  expect(about(theirs.id).length, 'a colleague’s move is a signal').toBeGreaterThan(0);
  expect(about(theirs.id)[0].action).toBe('moved');

  /*
   * And this one I moved myself. Left out rather than dimmed: a list where most
   * rows are things you did an hour ago is a list you stop scanning, and the one
   * row that was somebody else goes past with the rest.
   */
  expect(about(mine.id), 'my own move is not news to me').toHaveLength(0);

  await crew.close();
  await admin.context.close();
});

test('a client request turns up as soon as it arrives', async ({ browser, baseURL, request }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Signals Ask Co ${RUN_ID}`, `${RUN_ID}ask`);
  const asSite = await Forge.asClientSite(admin.api, site.id, request);

  const submission = await Forge.makeSubmission(asSite, {
    title: `A booking form ${RUN_ID}`,
    submitted_by: 'Someone at the client',
  });

  const answer = await lately(admin.api);
  const signal = (answer.signals ?? []).find((one) => one.subject_id === submission.id);

  expect(signal, 'the request is a signal').toBeTruthy();
  expect(signal.kind).toBe('submission');
  expect(signal.action).toBe('requested');

  // Nobody in the studio did it, which is why it can never be filtered out as
  // somebody's own doing.
  expect(signal.actor).toBe(0);

  await admin.context.close();
});

test('somebody who cannot read the client is never told what happened on it', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const { admin, site, crew, colleague } = await withColleague(browser, baseURL, 'Signals Theirs Co');
  const theirs = await movedBy(colleague, site.id, `Not for them ${RUN_ID}`);

  /*
   * A real person on a real client, with a real membership — just not this one.
   * Proving the scoping with somebody who reaches nothing would prove only that
   * an empty list is empty.
   */
  const elsewhere = await Forge.makeSite(admin.api, `Signals Other Co ${RUN_ID}`, `${RUN_ID}other`);
  const outsider = await Forge.makePerson(admin.api, elsewhere.client.id, 'staff', 'outsider');
  const asOutsider = await Forge.signedIn(browser, baseURL, outsider.login, Forge.PASSWORD);

  const answer = await lately(asOutsider.api);

  expect(answer.denied, 'they can read their own client').toBe(false);
  expect(
    (answer.signals ?? []).filter((one) => one.subject_id === theirs.id),
    'a client they are not on'
  ).toHaveLength(0);

  // And the same event is on the list of somebody who is on that client, so the
  // absence above is scoping rather than nothing having happened.
  const insider = await lately(admin.api);

  expect((insider.signals ?? []).filter((one) => one.subject_id === theirs.id).length).toBeGreaterThan(0);

  await asOutsider.context.close();
  await crew.close();
  await admin.context.close();
});

test('opening the list clears the count, and the rows stay', async ({ browser, baseURL }) => {
  test.slow();

  const { admin, site, crew, colleague } = await withColleague(browser, baseURL, 'Signals Read Co');
  const theirs = await movedBy(colleague, site.id, `Read me ${RUN_ID}`);

  const page = await admin.context.newPage();

  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 30_000 });

  const count = page.getByTestId('bwx-signals-count');

  await expect(count).toBeVisible({ timeout: 30_000 });

  await page.getByTestId('bwx-signals-open').click();

  const row = page.locator(`[data-testid="bwx-signal"][data-subject="${theirs.id}"]`);

  await expect(row).toBeVisible();
  await expect(row).toContainText(`Read me ${RUN_ID}`);

  // The count goes because it has been read, not because the rows have gone.
  // Removing them would make the panel useless the second time somebody opens
  // it in a morning.
  await expect(count).toHaveCount(0);
  await expect(row).toHaveAttribute('data-unread', 'false');

  // Still read after a reload, which is the only part of this that is stored —
  // one number saying how far this person got.
  await page.reload();
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-signals-count')).toHaveCount(0);

  await page.close();
  await crew.close();
  await admin.context.close();
});
