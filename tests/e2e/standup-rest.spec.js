import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #169. The day's list, worked out from what is true rather than stored.
//
// The unit tests state the twelve rules and argue with them. What only a real
// WordPress can show is the property the whole board rests on: a card appears
// because a condition became true, and leaves when that condition resolves —
// with nothing anywhere to mark it seen. So the spec makes a condition true,
// finds the card, resolves the condition, and watches it go.
//
// Nothing is ever deleted and the instance is kept between runs, so every
// assertion is scoped to this run's own records.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** The cards on the list about one record. */
async function cardsAbout(api, subjectId) {
  const list = await api.get('/standup');

  return (list.cards ?? []).filter((card) => card.subject_id === subjectId);
}

/** Which rules those cards name. */
function rulesOf(cards) {
  return cards.map((card) => card.rule).sort();
}

test('work past its date is on the list, and leaves when it is done', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Standup Co ${RUN_ID}`, RUN_ID);

  const made = await Forge.makeItem(admin.api, site.id, {
    title: `Late thing ${RUN_ID}`,
    planned_start: '2020-01-01',
    planned_due: '2020-01-02',
  });

  expect(made.status(), await made.text()).toBe(200);

  const item = (await made.json()).item;

  // A date long past, so this is late whatever day the suite runs on.
  await expect
    .poll(async () => rulesOf(await cardsAbout(admin.api, item.id)))
    .toContain('overdue');

  // Resolved by moving the date, not by dismissing anything — there is nothing
  // to dismiss, which is the whole design.
  const current = await admin.api.get(`/work-items/${item.id}`);
  const moved = await admin.api.patch(`/work-items/${item.id}`, {
    planned_start: '2099-01-01',
    planned_due: '2099-12-31',
    record_version: current.item.record_version,
  });

  expect(moved.status(), await moved.text()).toBe(200);

  await expect
    .poll(async () => rulesOf(await cardsAbout(admin.api, item.id)))
    .not.toContain('overdue');

  await admin.context.close();
});

test('a client request sits on the list until somebody answers it', async ({
  browser,
  baseURL,
  request,
}) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Standup Ask Co ${RUN_ID}`, RUN_ID);

  const asSite = await Forge.asClientSite(admin.api, site.id, request);
  const submission = await Forge.makeSubmission(asSite, {
    type: 'request',
    title: `Something asked ${RUN_ID}`,
    description: 'Because it would help.',
  });

  expect(rulesOf(await cardsAbout(admin.api, submission.id))).toContain('request-waiting');

  // Answered. Not marked as read, not dismissed — answered.
  const answered = await admin.api.patch(`/submissions/${submission.id}`, {
    intake_state: 'declined',
    response: 'Not this quarter.',
  });

  expect(answered.status(), await answered.text()).toBe(200);

  expect(rulesOf(await cardsAbout(admin.api, submission.id))).not.toContain('request-waiting');

  await admin.context.close();
});

test('the list says which day it was worked out for', async ({ browser, baseURL }) => {
  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const list = await admin.api.get('/standup');

  // A board left open overnight is a board showing yesterday's "due today", and
  // the only way a screen can know that is to be told which day it was given.
  expect(list.today).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  expect(list.denied).toBe(false);
  expect(list.rules).toHaveLength(12);

  // Every card names a rule from that list and the record it is about. A card
  // that named neither would be one nobody could act on.
  for (const card of list.cards ?? []) {
    expect(list.rules).toContain(card.rule);
    expect(card.subject_id).not.toBe('');
    expect(card.subject_type).not.toBe('');
  }

  await admin.context.close();
});
