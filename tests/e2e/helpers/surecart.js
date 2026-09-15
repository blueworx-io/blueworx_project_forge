import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Puts a stand-in SureCart inside the test WordPress, so a spec can connect
// a store, refresh it and see what Forge makes of the answer — without a
// real store, a real token, or the network.
//
// The stub is an mu-plugin, copied in before the spec and removed after.
// Only the disposable instance ever has it; it lives in tests/ and nothing
// under tests/ is on any build allowlist.

const ROOT = fileURLToPath(new URL('../../..', import.meta.url));
const WP = process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp');
const MU = join(WP, 'wp-content', 'mu-plugins');

/** The token the stub accepts. */
export const STUB_TOKEN = 'test-token';

export function installSureCartStub() {
  if (!existsSync(WP)) {
    throw new Error(`No test WordPress at ${WP}; set BWX_WP_DIR or run npm run wp:up`);
  }

  mkdirSync(MU, { recursive: true });
  copyFileSync(join(ROOT, 'tests', 'support', 'surecart-stub.php'), join(MU, 'bwx-forge-surecart-stub.php'));
  copyFileSync(join(ROOT, 'tests', 'php', 'fixtures', 'surecart-subscriptions.json'), join(MU, 'surecart-subscriptions.json'));
}

export function removeSureCartStub() {
  rmSync(join(MU, 'bwx-forge-surecart-stub.php'), { force: true });
  rmSync(join(MU, 'surecart-subscriptions.json'), { force: true });
}
