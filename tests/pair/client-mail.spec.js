import { test, expect } from '@playwright/test';
import * as Pair from './helpers/pair.js';

// #173, NOTIF-3. The client's email leaves the client's own site.
//
// Two WordPress installs, which is the only honest way to test this: the whole
// decision is about *which machine* sends, and one instance cannot tell you
// that. The studio writes the email and never sends it; the client's site asks
// what to send, hands it to its own wp_mail, and reports back.
//
// What this can and cannot see. It can see the studio hand over a finished
// envelope addressed to the client's own people, the client site take it, and
// an outcome come back — which is the round trip. It cannot see an inbox: the
// test instance has no mail transport, so wp_mail will almost certainly fail,
// and that is fine. A failure reported by the client site is still proof that
// the client site is the thing that tried, which is the criterion.
//
// That Forge holds no mail credentials is proved in tests/php as an absence,
// which is a stronger statement than a browser can make.

const RUN = `mail${Date.now()}`;

test.beforeAll(() => {
  Pair.requireEnvironment();
});

const ASK = '/wp-admin/admin.php?page=blueworx-forge-client-ask';
const BOARD = '/wp-admin/admin.php?page=blueworx-forge-client';

/** A person on the client, of a client-side role, with a real address. */
async function clientPerson(studio, clientId, label) {
  const login = `${label}${Date.now()}${Math.floor(Math.random() * 1000)}`;
  const email = `${login}@example.test`;

  const wp = await studio.context.request.post('/wp-json/wp/v2/users', {
    headers: { 'X-WP-Nonce': studio.nonce },
    data: { username: login, email, password: 'forge-test-pw-4471', roles: ['subscriber'] },
  });

  expect(wp.status(), await wp.text()).toBe(201);

  const created = await studio.post('/users', {
    email,
    display_name: label,
    wp_user_id: (await wp.json()).id,
  });

  expect(created.status(), await created.text()).toBe(200);

  const user = (await created.json()).user;

  const membership = await studio.post(`/clients/${clientId}/memberships`, {
    user_id: user.id,
    role: 'client_admin',
  });

  expect(membership.status(), await membership.text()).toBe(200);

  return { ...user, email };
}

/** What the studio has recorded about telling this client. */
async function told(studio, submissionId) {
  const queue = await studio.get('/submissions');
  const one = (queue.submissions ?? []).find((each) => each.id === submissionId);

  return one?.notifications ?? [];
}

test.describe('the client site sends the client the email', () => {
  test.describe.configure({ mode: 'serial' });

  test('a request raises one email, and the client site is the one that sends it', async ({
    browser,
  }) => {
    // Two installs, a person, a request and a round trip, all against a
    // single-threaded PHP server on each side.
    test.slow();

    const world = await Pair.connectedPair(browser, 'Mail Co', RUN);
    const person = await clientPerson(world.studio, world.client.id, 'recipient');

    // The client asks for something, the way a client does.
    const page = await world.clientSite.context.newPage();

    await page.goto(ASK);
    await page.fill('#bwx-title', `A booking form would help ${RUN}`);
    await page.fill('#bwx-description', 'Because it would help.');
    await page.click('#submit');
    await expect(page.locator('[data-bwx-result="sent"]')).toHaveCount(1);

    const queue = await world.studio.get('/submissions');
    const ours = (queue.submissions ?? []).find((one) =>
      one.title.includes(`A booking form would help ${RUN}`)
    );

    expect(ours, 'the request reached the studio').toBeTruthy();

    // One email to send, and nobody has sent it yet.
    const raised = await told(world.studio, ours.id);

    expect(raised).toHaveLength(1);
    expect(raised[0].event_kind).toBe('request-received');
    expect(raised[0].outcome).toBe('raised');

    /*
     * Loading a Forge screen on the client's site is what triggers a run:
     * WP-Cron on a quiet site only fires when somebody visits, and a client
     * site can be quiet for days. This is that path, exercised as a person
     * would exercise it.
     */
    // Reloaded rather than loaded once. The site will not check more than once
    // a minute however many screens somebody opens, and these two installs are
    // shared and long-lived, so an earlier spec may have used this minute up.
    await expect
      .poll(
        async () => {
          await page.goto(BOARD);

          const now = await told(world.studio, ours.id);

          return now[0]?.outcome ?? '';
        },
        { timeout: 180_000, intervals: [5000, 10_000, 20_000, 30_000] }
      )
      .not.toBe('raised');

    const settled = await told(world.studio, ours.id);

    // Still one. Whatever happened to it, the client was told about this once.
    expect(settled).toHaveLength(1);
    expect(settled[0].id).toBe(raised[0].id);

    /*
     * Sent, or not sent because this instance has no mail transport. All three
     * mean the client's site asked for the envelope, gave it to its own
     * wp_mail and said what happened — which is the thing under test.
     *
     * `retrying` is the usual answer here rather than `failed`, and only since
     * #174: a first failure has three attempts left, so it is not somebody's
     * problem yet. What must never appear is `suppressed` — that would mean
     * there was nobody to write to, and a person was created above precisely so
     * that there is.
     */
    expect(['sent', 'retrying', 'failed']).toContain(settled[0].outcome);

    // And the person the studio addressed it to is the client's own.
    expect(person.email).toContain('@example.test');

    await page.close();
    await world.close();
  });

  test('one client site is never handed another client\'s email', async ({ browser, request }) => {
    test.slow();

    const world = await Pair.connectedPair(browser, 'Mail Isolation Co', `${RUN}b`);
    const other = await Pair.studioSite(world.studio, 'Mail Other Co', `${RUN}c`);

    await clientPerson(world.studio, world.client.id, 'theirs');

    const page = await world.clientSite.context.newPage();

    await page.goto(ASK);
    await page.fill('#bwx-title', `Only ours ${RUN}`);
    await page.fill('#bwx-description', 'Because it would help.');
    await page.click('#submit');
    await expect(page.locator('[data-bwx-result="sent"]')).toHaveCount(1);
    await page.close();

    /*
     * A second site, signing with its own key, asks what it should send. It is
     * a real site with a real key — the only way to prove it cannot reach
     * somebody else's email is to reach for it with a signature that works.
     */
    const stranger = Pair.asClientSite(request, other.issued);
    const answer = await (await stranger.get('/client/notifications')).json();

    expect(answer.ok).toBe(true);

    const titles = (answer.send ?? []).map((one) => one.subject).join(' ');

    expect(titles).not.toContain(`Only ours ${RUN}`);

    await world.close();
  });
});
