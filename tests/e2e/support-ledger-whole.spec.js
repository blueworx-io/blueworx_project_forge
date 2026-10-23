import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #597. A client with more than 500 ledger entries still sees all of them,
// the newest included, and the lines still add up to "Left".

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';
const EXTRA = 500;

test('a busy client sees every ledger entry, newest included, adding up to Left', async ({ browser, baseURL }) => {
  test.slow();
  test.setTimeout(1_500_000);

  const admin = await Forge.signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await Forge.makeSite(admin.api, 'Busy Co', RUN_ID);
  await Forge.onSupport(admin, site.id, 20);

  const adjust = async (hours, reason) => {
    const made = await admin.api.post(`/client-sites/${site.id}/support/adjust`, { hours, reason });
    expect(made.status(), await made.text()).toBe(200);
  };

  // Many at once, to get past the old cap quickly...
  for (let done = 0; done < EXTRA; done += 25) {
    await Promise.all(Array.from({ length: Math.min(25, EXTRA - done) }, (_, i) => adjust(1, `Filler ${done + i}`)));
  }
  // ...then one on its own, so it is certainly the newest.
  await adjust(1, 'The newest entry');

  const ledger = await Forge.hourLedger(admin, site.id);
  expect(ledger.entries.length).toBeGreaterThan(500);

  const sum = Math.round(ledger.entries.reduce((total, [, hours]) => total + hours, 0) * 100) / 100;
  expect(sum).toBe(ledger.balance);

  const answer = await admin.api.get(`/client-sites/${site.id}/support`);
  expect(answer.ledger[answer.ledger.length - 1].reason).toBe('The newest entry');

  await admin.context.close();
});
