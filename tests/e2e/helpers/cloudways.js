import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// A stand-in Cloudways inside the test WordPress (#621): the token call and
// the server list, so a spec can connect Cloudways and stop a server without
// an account or the network.

const ROOT = fileURLToPath(new URL('../../..', import.meta.url));
const WP = process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp');
const MU = join(WP, 'wp-content', 'mu-plugins');
const STUB = '/wp-json/bwx-forge-test/v1/cloudways';

export function installCloudwaysStub() {
  if (!existsSync(WP)) {
    throw new Error(`No test WordPress at ${WP}; set BWX_WP_DIR or run npm run wp:up`);
  }

  mkdirSync(MU, { recursive: true });
  copyFileSync(join(ROOT, 'tests', 'support', 'cloudways-stub.php'), join(MU, 'bwx-forge-cloudways-stub.php'));
}

export function removeCloudwaysStub() {
  rmSync(join(MU, 'bwx-forge-cloudways-stub.php'), { force: true });
}

/** Sets what the stand-in answers: `servers`, `down`, and `reset` to zero the read count. */
export async function cloudwaysSays(request, state) {
  const answer = await request.post(STUB, { data: state });
  if (!answer.ok()) throw new Error(await answer.text());
}

/** How many times Forge has read the server list. */
export async function serverReads(request) {
  return (await (await request.get(STUB)).json()).reads;
}

/** A server, as Cloudways lists it. */
export function server(id, label, status, apps = []) {
  return { id, label, status, apps };
}
