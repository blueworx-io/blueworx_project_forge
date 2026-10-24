import { test, expect } from '@playwright/test';
import { createHash, createHmac, randomBytes } from 'node:crypto';
import { CLIENT_URL, requireEnvironment } from './helpers/pair.js';

// #621, Part B. The client plugin's create-site routes, on a real client
// site: they refuse anything not signed by the studio with the network's key.
// The pair's client site is a single site with no network key, so every call
// is refused — and refused the same way, so a stranger learns nothing about
// which check failed or whether this is a network at all. Making sites is
// proved by the unit tests (tests/php/NetworkSitesTest.php), because the test
// harness cannot run a multisite network.

const CLIENT_API = '/wp-json/blueworx-forge-client/v1';
const ROUTE = '/blueworx-forge-client/v1';

/** Headers as the studio would sign them, with whatever key a caller holds. */
function signed(key, method, path, body = '', at = Math.floor(Date.now() / 1000)) {
  const timestamp = String(at);
  const nonce = randomBytes(16).toString('hex');
  const canonical = ['network', method, `${ROUTE}${path}`, timestamp, nonce, createHash('sha256').update(body).digest('hex')].join('\n');

  return {
    'Content-Type': 'application/json',
    'X-BWX-Site': 'network',
    'X-BWX-Timestamp': timestamp,
    'X-BWX-Nonce': nonce,
    'X-BWX-Signature': createHmac('sha256', key).update(canonical).digest('hex'),
  };
}

test.beforeAll(requireEnvironment);

test('the create-site routes refuse an unsigned call, a guessed key and a stale one alike', async ({ playwright }) => {
  test.setTimeout(180_000);
  const request = await playwright.request.newContext({ baseURL: CLIENT_URL });
  const body = JSON.stringify({ slug: 'acme', title: 'Acme', site_id: 'cst_x' });

  const attempts = {
    unsigned: await request.post(`${CLIENT_API}/network/sites`, { data: body, headers: { 'Content-Type': 'application/json' } }),
    'guessed key': await request.post(`${CLIENT_API}/network/sites`, { data: body, headers: signed('a-guessed-key', 'POST', '/network/sites', body) }),
    'empty key': await request.post(`${CLIENT_API}/network/sites`, { data: body, headers: signed('', 'POST', '/network/sites', body) }),
    stale: await request.post(`${CLIENT_API}/network/sites`, { data: body, headers: signed('a-guessed-key', 'POST', '/network/sites', body, Math.floor(Date.now() / 1000) - 3600) }),
    hello: await request.get(`${CLIENT_API}/network/hello`, { headers: signed('a-guessed-key', 'GET', '/network/hello') }),
    link: await request.post(`${CLIENT_API}/network/sites/1/link`, { data: '{}', headers: signed('a-guessed-key', 'POST', '/network/sites/1/link', '{}') }),
    people: await request.post(`${CLIENT_API}/network/sites/1/people`, { data: '{}', headers: signed('a-guessed-key', 'POST', '/network/sites/1/people', '{}') }),
  };

  for (const [name, response] of Object.entries(attempts)) {
    expect(response.status(), `${name} was not refused`).toBe(401);
    expect((await response.json()).code, `${name} said more than no`).toBe('bwx_forge_network_refused');
  }

  await request.dispose();
});
