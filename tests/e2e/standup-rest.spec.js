import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #169. The day's list, worked out from what is true rather than stored.
//
// The unit tests state the fourteen rules and argue with them. What only a real
// WordPress can show is the property the whole board rests on: a card appears
// because a condition became true, and leaves when that condition resolves —
// with nothing anywhere to mark it seen. So the spec makes a condition true,
// finds the card, resolves the condition, and watches it go.
//
// Nothing is ever deleted and the instance is kept between runs, so every
// assertion is scoped to this run's own records.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** The cards on the list about one record. */
async function cardsAbout(api, subjectId) {
  const list = await api.get('/standup');

  // An error body has no cards: a denied or error reply must not read as an empty list.
  if (list.denied === true || !Array.isArray(list.cards)) {
    throw new Error(`/standup gave no usable list: ${JSON.stringify(list)}`);
  }

  return list.cards.filter((card) => card.subject_id === subjectId);
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

test('cancelled work is off the list', async ({ browser, baseURL }) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, `Standup Cancel Co ${RUN_ID}`, RUN_ID);

  const made = await Forge.makeItem(admin.api, site.id, {
    title: `Cancelled thing ${RUN_ID}`,
    planned_start: '2020-01-01',
    planned_due: '2020-01-02',
  });

  expect(made.status(), await made.text()).toBe(200);

  const item = (await made.json()).item;

  await expect
    .poll(async () => rulesOf(await cardsAbout(admin.api, item.id)))
    .toContain('overdue');

  const ended = await admin.api.post(`/work-items/${item.id}/outcome`, {
    outcome: 'cancelled',
    reason: 'Not needed.',
    record_version: item.record_version,
  });

  expect(ended.status(), await ended.text()).toBe(200);

  await expect.poll(async () => rulesOf(await cardsAbout(admin.api, item.id))).toEqual([]);

  await admin.context.close();
});

test('a reminder everyone has ticked is off the list, one with someone still to tick is on it', async ({
  browser,
  baseURL,
}) => {
  test.slow();

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { client, site } = await Forge.makeSite(admin.api, `Standup Remind Co ${RUN_ID}`, RUN_ID);
  const one = await Forge.makePerson(admin.api, client.id, 'staff', 'standuprema');
  const two = await Forge.makePerson(admin.api, client.id, 'staff', 'standupremb');
  const title = `Ring Callum ${RUN_ID}`;

  // Dated far in the past, so it is late whatever day the suite runs on.
  const made = await admin.api.post('/reminders', {
    client_site_id: site.id,
    title,
    assignees: [one.id, two.id],
    starts_on: '2020-01-01',
    ends_on: '2020-01-02',
  });

  expect(made.status(), await made.text()).toBe(200);

  const work = await admin.api.get(`/work-items?client_site_id=${site.id}`);
  const tasks = work.items.filter((item) => item.title === title);

  expect(tasks).toHaveLength(2);

  const mine = tasks.find((task) => task.assignees[0] === one.id);
  const theirs = tasks.find((task) => task.assignees[0] === two.id);

  // Both copies are late and waiting on their person.
  await expect.poll(async () => rulesOf(await cardsAbout(admin.api, mine.id))).toContain('overdue');
  await expect.poll(async () => rulesOf(await cardsAbout(admin.api, theirs.id))).toContain('overdue');

  // An administrator ticks one copy for its person. Ticking sends it to Released,
  // so reopen it into Development: only the tick is left to say it is done, which is
  // how the reminders that stayed on the list looked.
  const ticked = await admin.api.post(`/work-items/${mine.id}/tick`, { done: true, user_id: one.id });

  expect(ticked.status(), await ticked.text()).toBe(200);

  const released = (await admin.api.get(`/work-items/${mine.id}`)).item;
  const moved = await admin.api.post(`/work-items/${mine.id}/reopen`, {
    to: 'in-development',
    reason: 'Put back so only the tick says it is done.',
    record_version: released.record_version,
  });

  expect(moved.status(), await moved.text()).toBe(200);

  const reopened = (await admin.api.get(`/work-items/${mine.id}`)).item;

  expect(reopened.stage).toBe('in-development');
  expect(Object.keys(reopened.ticks)).toEqual([one.id]);

  // Everyone on it has ticked, so it is off the list.
  await expect.poll(async () => rulesOf(await cardsAbout(admin.api, mine.id))).toEqual([]);

  // The other copy still has someone to tick, so it stays.
  expect(rulesOf(await cardsAbout(admin.api, theirs.id))).toContain('overdue');

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
  expect(list.rules).toHaveLength(14);
  expect(list.rules).toContain('untriaged');

  // Every card names a rule from that list and the record it is about. A card
  // that named neither would be one nobody could act on.
  for (const card of list.cards ?? []) {
    expect(list.rules).toContain(card.rule);
    expect(card.subject_id).not.toBe('');
    expect(card.subject_type).not.toBe('');
  }

  await admin.context.close();
});
