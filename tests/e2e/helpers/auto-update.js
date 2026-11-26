import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, lstatSync, mkdirSync, readlinkSync, rmSync, unlinkSync } from 'node:fs';
import { platform } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { stageArtifact } from '../../../bin/stage-artifact.mjs';

// Release offers and the background updater inside a test WordPress (#713):
// update data for the plugins under test, and a route that runs the updater
// cron would run. See tests/support/auto-update-stub.php.

const ROOT = fileURLToPath(new URL('../../..', import.meta.url));
const STUB = 'bwx-forge-auto-update-stub.php';
const API = '/wp-json/bwx-forge-test/v1/updates';

export const STUDIO_PLUGIN = 'blueworx-forge/blueworx-forge.php';
export const CLIENT_PLUGIN = 'blueworx-forge-client/blueworx-forge-client.php';
export const AKISMET = 'akismet/akismet.php';
export const HELLO = 'hello.php';

const DEFAULT_WP = () => process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp');

function muDir(wpDir) {
  return join(wpDir, 'wp-content', 'mu-plugins');
}

/** Puts the stub in the WordPress at `wpDir` (the studio's by default). */
export function installUpdateStub(wpDir = DEFAULT_WP()) {
  if (!existsSync(wpDir)) {
    throw new Error(`No test WordPress at ${wpDir}; set BWX_WP_DIR or run npm run wp:up`);
  }

  mkdirSync(muDir(wpDir), { recursive: true });
  copyFileSync(join(ROOT, 'tests', 'support', 'auto-update-stub.php'), join(muDir(wpDir), STUB));
}

export function removeUpdateStub(wpDir = DEFAULT_WP()) {
  rmSync(join(muDir(wpDir), STUB), { force: true });
}

/**
 * The stub's routes, as the signed-in administrator `request` belongs to.
 * They refuse anybody else: they install code on the site.
 *
 * `run` offers a release of each plugin in `offers` ({ file: version }), runs
 * the background updater, and says what it did. `build` names the plugins that
 * have a real zip behind their release, so they are installed; any other
 * offered plugin only has its package asked for.
 */
export function updaterApi(request, nonce) {
  const headers = { 'X-WP-Nonce': nonce };

  async function answer(response) {
    if (!response.ok()) {
      throw new Error(`The updater route failed: ${response.status()} ${await response.text()}`);
    }

    return response.json();
  }

  return {
    versions: async () => answer(await request.get(`${API}/versions`, { headers })),
    run: async (offers, build = []) => answer(await request.post(`${API}/run`, { headers, data: { offers, build } })),
  };
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

function link(target, dest) {
  if (platform() === 'win32') {
    execFileSync('cmd', ['/c', 'mklink', '/J', dest, target], { stdio: 'ignore' });
  } else {
    execFileSync('ln', ['-s', target, dest], { stdio: 'ignore' });
  }
}

/**
 * Swaps a plugin the harness linked into the site for a copy of what ships,
 * so a real update can replace it. Installing over a link would empty the
 * folder it points at — this repository, for the studio plugin.
 *
 * Returns what puts the link back. Only ever unlinks: nothing is removed
 * through it.
 *
 * @param {'studio' | 'client'} artifact Which plugin in bin/artifacts.json.
 */
export function useDisposableCopy(artifact, slug, wpDir = DEFAULT_WP()) {
  const plugin = join(wpDir, 'wp-content', 'plugins', slug);
  const target = lstatSync(plugin).isSymbolicLink() ? readlinkSync(plugin) : null;

  if (target) {
    unlinkSync(plugin);
  } else {
    rmSync(plugin, { recursive: true, force: true });
  }

  stageArtifact(artifact, plugin);

  return () => {
    // An update replaces the folder with a real one, and a failed run can leave
    // it half there; either way it is a copy, and the copy goes.
    rmSync(plugin, { recursive: true, force: true });

    if (target) {
      link(target, plugin);
    } else {
      stageArtifact(artifact, plugin);
    }
  };
}
