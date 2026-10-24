import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// A stand-in multisite network inside the test WordPress (#621), so a spec
// can connect a network and create a client's site on it without one. It
// checks the studio's signatures against the key below.

const ROOT = fileURLToPath(new URL('../../..', import.meta.url));
const WP = process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp');
const MU = join(WP, 'wp-content', 'mu-plugins');
const STUB = '/wp-json/bwx-forge-test/v1/network';

export const NETWORK_URL = 'https://network.forge.test';
export const NETWORK_KEY = 'stubnetworkkey0123456789abcdef0123456789abcdef0123456789abcdef01';

export function installNetworkStub() {
  if (!existsSync(WP)) {
    throw new Error(`No test WordPress at ${WP}; set BWX_WP_DIR or run npm run wp:up`);
  }

  mkdirSync(MU, { recursive: true });
  copyFileSync(join(ROOT, 'tests', 'support', 'network-stub.php'), join(MU, 'bwx-forge-network-stub.php'));
}

export function removeNetworkStub() {
  rmSync(join(MU, 'bwx-forge-network-stub.php'), { force: true });
}

/** What the stand-in network was asked for. */
export async function networkState(request) {
  return (await request.get(STUB)).json();
}

/** The next logins call fails once, so a retry can be tried. */
export async function failPeopleOnce(request) {
  const answer = await request.post(STUB, { data: { fail_people_once: true } });
  if (!answer.ok()) throw new Error(await answer.text());
}
