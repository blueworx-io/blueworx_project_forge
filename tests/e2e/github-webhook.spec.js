import { test, expect, request as pwRequest } from '@playwright/test';
import { signedIn } from './helpers/forge.js';
import { signIn } from '../helpers/sign-in.js';
import { installGitHubStub, removeGitHubStub, githubSays, sendHook } from './helpers/github.js';

// #680. GitHub's organisation hook is public, so it must refuse anything that is
// not signed with the shared secret, and say nothing about why.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const SECRET = 'whsec-hook-test';

test.describe.configure({ mode: 'serial' });

let admin;
let bare;

async function lastChecked() {
  const answer = await admin.api.get('/connections');
  return (answer.services ?? answer.connections ?? []).find((service) => service.id === 'github').last_checked;
}

test.beforeAll(async ({ browser, baseURL }) => {
  test.setTimeout(600000);
  installGitHubStub();

  // Signed out, as GitHub is.
  bare = await pwRequest.newContext({ baseURL });
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  await admin.api.del('/connections/github');
  await githubSays(admin.api.request, { down: false, denied: false, reset: true, repos: ['blueworx-io/site-a'] });

  const connected = await admin.api.post('/connections/github', { token: 'good-token-1234', org: 'blueworx-io', secret: SECRET });
  expect(connected.ok()).toBe(true);
});

test.afterAll(async () => {
  await admin?.api.del('/connections/github');
  await admin?.context.close();
  await bare?.dispose();
  removeGitHubStub();
});

test('an unsigned message is refused', async () => {
  const answer = await sendHook(bare, 'ping', { zen: 'x' }, { secret: null });

  expect(answer.status()).toBe(401);
  expect(await lastChecked()).toBe(0);
});

test('a message signed with the wrong secret is refused', async () => {
  const answer = await sendHook(bare, 'ping', { zen: 'x' }, { secret: 'not-the-secret' });

  expect(answer.status()).toBe(401);
  expect(await lastChecked()).toBe(0);
});

test('a ping is answered', async ({ page }) => {
  const answer = await sendHook(bare, 'ping', { zen: 'x' }, { secret: SECRET });

  expect(answer.status()).toBe(200);
  expect(await answer.json()).toEqual({ ok: true, outcome: 'pong' });

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('about:blank');
  await page.goto('/blueworx-forge/#screen=settings');

  const checked = page.getByTestId('bwx-connection-github-checked');
  await expect(checked).toBeVisible({ timeout: 60000 });
  await expect(checked).not.toContainText('Never');
});

test('the same delivery sent again is not handled twice', async () => {
  const delivery = `dup-${Date.now()}`;
  const first = await sendHook(bare, 'ping', { zen: 'x' }, { secret: SECRET, delivery });
  const again = await sendHook(bare, 'ping', { zen: 'x' }, { secret: SECRET, delivery });

  expect((await first.json()).outcome).toBe('pong');
  expect(again.status()).toBe(200);
  expect((await again.json()).outcome).toBe('duplicate');
});

test('an event Forge does not use is ignored', async () => {
  const answer = await sendHook(bare, 'star', { action: 'created' }, { secret: SECRET });

  expect(answer.status()).toBe(200);
  expect((await answer.json()).outcome).toBe('ignored');
});
