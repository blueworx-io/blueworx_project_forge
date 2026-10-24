import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// A stand-in Google inside the test WordPress (#606): the consent screen,
// the token endpoint and the Calendar API, so a spec can connect people and
// put events on their calendars without a Google account or the network.

const ROOT = fileURLToPath(new URL('../../..', import.meta.url));
const WP = process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp');
const MU = join(WP, 'wp-content', 'mu-plugins');
const STUB = '/wp-json/bwx-forge-test/v1/google';

export function installGoogleStub() {
  if (!existsSync(WP)) {
    throw new Error(`No test WordPress at ${WP}; set BWX_WP_DIR or run npm run wp:up`);
  }

  mkdirSync(MU, { recursive: true });
  copyFileSync(join(ROOT, 'tests', 'support', 'google-stub.php'), join(MU, 'bwx-forge-google-stub.php'));
}

export function removeGoogleStub() {
  rmSync(join(MU, 'bwx-forge-google-stub.php'), { force: true });
}

/** Puts events on somebody's stand-in calendar, or changes them. */
export async function putEvents(request, who, events) {
  const answer = await request.post(`${STUB}/events`, { data: { who, events } });
  if (!answer.ok()) throw new Error(await answer.text());
}

/** The reads a calendar has had: 'full' or 'incremental', in order. */
export async function readsOf(request, who) {
  return (await request.get(`${STUB}/reads/${who}`)).json();
}

/** The next incremental read for somebody answers 410. */
export async function expireSyncToken(request, who) {
  await request.post(`${STUB}/gone`, { data: { who } });
}

/**
 * Connects a person's calendar the way they would: ask Forge for Google's
 * address, follow it, and land back on their page.
 */
export async function connectGoogle(page, api, who) {
  await page.request.post(`${STUB}/next`, { data: { who } });

  const started = await api.post('/me/google/connect', {});
  if (200 !== started.status()) throw new Error(await started.text());

  // The app clears its hash as it opens, so where Forge sent the browser is
  // read off the callback's redirect rather than the address bar.
  const back = page.waitForResponse((response) => response.url().includes('admin-post.php?action=bwx_forge_google'), { timeout: 120000 });
  await page.goto((await started.json()).url);

  return (await (await back).headerValue('location')) ?? '';
}

/** A timed Google event, as the Calendar API sends it. */
export function timedEvent(id, uid, start, minutes, overrides = {}) {
  const from = new Date(start);
  const to = new Date(from.getTime() + minutes * 60000);

  return {
    id,
    iCalUID: uid,
    status: 'confirmed',
    summary: `Meeting ${id}`,
    start: { dateTime: from.toISOString() },
    end: { dateTime: to.toISOString() },
    ...overrides,
  };
}
