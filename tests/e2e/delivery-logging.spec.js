import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #174. A failed email is visible rather than lost.
//
// "A permanently failing send appears in Standup rather than disappearing" is
// the criterion, and the word doing the work is *permanently*. A send that
// failed a minute ago is going to be tried again, and putting it on somebody's
// daily list would fill that list with problems that fix themselves. So the
// spec walks the whole ladder — four failures reported the way a client site
// reports them — and checks the board stays quiet until the last one.
//
// The client site is not involved here. This is the studio's half: what it does
// with the outcomes it is told about. Whether mail actually leaves a client's
// WordPress is proved in the two-instance suite.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** A site, a signed caller for it, and one event waiting to be sent. */
async function withSomethingToSend(browser, baseURL, request) {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, `Delivery Co ${RUN_ID}`, RUN_ID);

  /*
   * Somebody on the client to write to. Without one the studio settles the
   * event as suppressed rather than handing it over — correctly, since a client
   * site cannot fix a client with nobody set up — and there would be nothing
   * here to fail at.
   */
  await Forge.makePerson(admin.api, client.id, 'client_admin', 'recipient');

  // A client asking for something raises the acknowledgement — the cheapest way
  // to get a real event onto the queue.
  const asSite = await Forge.asClientSite(admin.api, site.id, request);
  const submission = await Forge.makeSubmission(asSite, {
    title: `Delivery thing ${RUN_ID}`,
    description: 'Because it would help.',
  });

  return { admin, asSite, site, submission };
}

/** What the studio says this site should send now. */
async function outbox(asSite) {
  return (await asSite.get('/client/notifications')).json();
}

/** Reports one attempt back, the way the client plugin does. */
async function report(asSite, eventId, sent, detail = '') {
  const said = await asSite.post('/client/notifications', {
    outcomes: [ { event_id: eventId, sent, detail } ],
  });

  expect(said.status(), await said.text()).toBe(200);

  return (await said.json()).recorded;
}

/** The notification cards on the day's list about one record. */
async function onStandup(api, about) {
  const list = await api.get('/standup');

  return (list.cards ?? []).filter(
    (card) => 'needs-intervention' === card.rule && card.detail?.about === about
  );
}

test('a failing send is tried again, and only the last failure reaches Standup', async ({
  browser,
  baseURL,
  request,
}) => {
  test.slow();

  const { admin, asSite, submission } = await withSomethingToSend(browser, baseURL, request);

  const waiting = await outbox(asSite);
  const envelope = (waiting.send ?? []).find((one) => one.subject.includes(`Delivery thing ${RUN_ID}`));

  expect(envelope, 'there is an email to send').toBeTruthy();

  // Nothing on the daily list yet: nothing has gone wrong.
  expect(await onStandup(admin.api, submission.id)).toHaveLength(0);

  /*
   * Three failures, each of which leaves road ahead. The board must stay quiet
   * through all of them — an email due to be tried again in five minutes is not
   * something to put in front of a person.
   */
  for (const attempt of [ 1, 2, 3 ]) {
    expect(await report(asSite, envelope.event_id, false, `SMTP refused, attempt ${attempt}`)).toBe(1);
    expect(
      await onStandup(admin.api, submission.id),
      `attempt ${attempt} should not need a person yet`
    ).toHaveLength(0);
  }

  // The fourth is the end of the ladder, and now it is somebody's.
  expect(await report(asSite, envelope.event_id, false, 'SMTP refused, and that is that')).toBe(1);

  const cards = await onStandup(admin.api, submission.id);

  expect(cards).toHaveLength(1);

  // Carrying what went wrong. "An email failed" is not something anybody can
  // act on; the mailer's own complaint is.
  expect(cards[0].detail.detail).toContain('SMTP refused');
  expect(cards[0].detail.attempts).toBe(4);

  await admin.context.close();
});

test('a send that is going to be tried again is not offered until it is due', async ({
  browser,
  baseURL,
  request,
}) => {
  test.slow();

  const { admin, asSite } = await withSomethingToSend(browser, baseURL, request);

  const waiting = await outbox(asSite);
  const envelope = (waiting.send ?? []).find((one) => one.subject.includes(`Delivery thing ${RUN_ID}`));

  expect(envelope).toBeTruthy();

  await report(asSite, envelope.event_id, false, 'Not this time');

  // Asked again straight away, the site is told there is nothing to send. This
  // is what makes the ladder a ladder rather than a loop.
  const again = await outbox(asSite);

  expect((again.send ?? []).map((one) => one.event_id)).not.toContain(envelope.event_id);

  await admin.context.close();
});

test('a send that works is finished with, and never offered again', async ({
  browser,
  baseURL,
  request,
}) => {
  test.slow();

  const { admin, asSite, submission } = await withSomethingToSend(browser, baseURL, request);

  const waiting = await outbox(asSite);
  const envelope = (waiting.send ?? []).find((one) => one.subject.includes(`Delivery thing ${RUN_ID}`));

  expect(envelope).toBeTruthy();
  expect(await report(asSite, envelope.event_id, true)).toBe(1);

  const again = await outbox(asSite);

  expect((again.send ?? []).map((one) => one.event_id)).not.toContain(envelope.event_id);
  expect(await onStandup(admin.api, submission.id)).toHaveLength(0);

  await admin.context.close();
});
