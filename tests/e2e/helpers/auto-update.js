import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Release offers and the background updater inside a test WordPress (#713):
// update data for the plugins under test, and a route that runs the updater
// cron would run. See tests/support/auto-update-stub.php.

const ROOT = fileURLToPath(new URL('../../..', import.meta.url));
const STUB = 'bwx-forge-auto-update-stub.php';

export const STUDIO_PLUGIN = 'blueworx-forge/blueworx-forge.php';
export const CLIENT_PLUGIN = 'blueworx-forge-client/blueworx-forge-client.php';
export const AKISMET = 'akismet/akismet.php';
export const HELLO = 'hello.php';

function muDir(wpDir) {
  return join(wpDir, 'wp-content', 'mu-plugins');
}

/** Puts the stub in the WordPress at `wpDir` (the studio's by default). */
export function installUpdateStub(wpDir = process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp')) {
  if (!existsSync(wpDir)) {
    throw new Error(`No test WordPress at ${wpDir}; set BWX_WP_DIR or run npm run wp:up`);
  }

  mkdirSync(muDir(wpDir), { recursive: true });
  copyFileSync(join(ROOT, 'tests', 'support', 'auto-update-stub.php'), join(muDir(wpDir), STUB));
}

export function removeUpdateStub(wpDir = process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp')) {
  rmSync(join(muDir(wpDir), STUB), { force: true });
}

/**
 * Offers a release of each plugin in `offers` ({ file: version }), runs the
 * background updater, and says what it did. `build` names the plugins that
 * have a real zip behind their release, so they are installed; any other
 * offered plugin only has its package asked for.
 */
export async function runUpdater(request, offers, build = []) {
  const answer = await request.post('/wp-json/bwx-forge-test/v1/updates/run', { data: { offers, build } });

  if (!answer.ok()) {
    throw new Error(`The updater run failed: ${answer.status()} ${await answer.text()}`);
  }

  return answer.json();
}

/** The package address the stub gives a plugin's release. */
export function packageOf(file) {
  const slug = file.includes('/') ? file.split('/')[0] : file.replace(/\.php$/, '');

  return `https://example.test/updates/${slug}.zip`;
}

/** A version higher than `version`, bumping its last number. */
export function nextVersion(version) {
  const [major, minor, patch] = version.split('.').map(Number);

  return `${major}.${minor}.${patch + 1}`;
}
