import { createHmac } from 'node:crypto';
import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// A stand-in GitHub inside the test WordPress (#680): the token check, the
// account's repository list and each repo's webhooks, so a spec can connect
// GitHub without an account or the network.

const ROOT = fileURLToPath(new URL('../../..', import.meta.url));
const WP = process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp');
const MU = join(WP, 'wp-content', 'mu-plugins');
const STUB = '/wp-json/bwx-forge-test/v1/github';

export function installGitHubStub() {
  if (!existsSync(WP)) {
    throw new Error(`No test WordPress at ${WP}; set BWX_WP_DIR or run npm run wp:up`);
  }

  mkdirSync(MU, { recursive: true });
  copyFileSync(join(ROOT, 'tests', 'support', 'github-stub.php'), join(MU, 'bwx-forge-github-stub.php'));
}

export function removeGitHubStub() {
  rmSync(join(MU, 'bwx-forge-github-stub.php'), { force: true });
}

/**
 * Sets what the stand-in answers: `repos`, `hooks`, `down`, `denied`,
 * `hooks_denied`, and `reset` to clear the call log.
 */
export async function githubSays(request, state) {
  const answer = await request.post(STUB, { data: state });
  if (!answer.ok()) throw new Error(await answer.text());
}

/** Everything the stand-in holds: `calls` ({ method, path, body }), `hooks` by repo, and the rest. */
export async function githubState(request) {
  return (await request.get(STUB)).json();
}

/** Every call Forge has made to the stand-in: `{ method, path, body }`. */
export async function githubCalls(request) {
  return (await githubState(request)).calls;
}

/**
 * The secret Forge made when it connected. Only the test stand-in gives it
 * out: no real route ever does.
 */
export async function githubSecret(request) {
  return (await (await request.get(`${STUB}/secret`)).json()).secret;
}

const HOOK = '/wp-json/blueworx-forge/v1/github/webhook';

/**
 * Sends a repo webhook message as GitHub would: the body signed with the
 * secret, no login. `secret: null` leaves the signature off altogether, and
 * `form: true` sends it the way a hook set to the form content type does.
 */
export async function sendHook(request, event, payload, { secret, delivery = `d-${Date.now()}-${Math.random()}`, form = false } = {}) {
  const body = form ? `payload=${encodeURIComponent(JSON.stringify(payload))}` : JSON.stringify(payload);
  const headers = {
    'Content-Type': form ? 'application/x-www-form-urlencoded' : 'application/json',
    'X-GitHub-Event': event,
    'X-GitHub-Delivery': delivery,
  };

  if (secret !== null && secret !== undefined) {
    headers['X-Hub-Signature-256'] = `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;
  }

  return request.post(HOOK, { headers, data: body });
}
