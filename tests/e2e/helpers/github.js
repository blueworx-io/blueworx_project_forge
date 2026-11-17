import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// A stand-in GitHub inside the test WordPress (#680): the token check and the
// organisation's repository list, so a spec can connect GitHub without an
// account or the network.

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

/** Sets what the stand-in answers: `repos`, `down`, `denied`, and `reset` to clear the call log. */
export async function githubSays(request, state) {
  const answer = await request.post(STUB, { data: state });
  if (!answer.ok()) throw new Error(await answer.text());
}

/** Every call Forge has made to the stand-in: `{ method, path, body }`. */
export async function githubCalls(request) {
  return (await (await request.get(STUB)).json()).calls;
}
