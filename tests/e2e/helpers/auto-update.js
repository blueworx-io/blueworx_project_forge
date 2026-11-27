import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, lstatSync, mkdirSync, readlinkSync, renameSync, rmSync, unlinkSync } from 'node:fs';
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
    /** Sets which of Akismet and Hello Dolly update themselves; returns what it was. */
    others: async (enabled) => (await answer(await request.post(`${API}/others`, { headers, data: { enabled } }))).enabled,
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
 * Swaps a plugin the harness installed into the site for a copy of what ships,
 * so a real update can replace it. Installing over a link would empty the
 * folder it points at — this repository, for the studio plugin.
 *
 * The original is kept, not rebuilt: a link is only unlinked, and a real
 * folder is moved aside and moved back, so the site comes back exactly as it
 * was. It comes back if setting the copy up fails too.
 *
 * Returns what puts the original back. Only ever unlinks or moves: nothing of
 * the original is removed through it.
 *
 * @param {'studio' | 'client'} artifact Which plugin in bin/artifacts.json.
 */
export function useDisposableCopy(artifact, slug, wpDir = DEFAULT_WP()) {
  const plugin = join(wpDir, 'wp-content', 'plugins', slug);
  // Beside plugins/, not in it, so WordPress never lists it as a second plugin.
  const aside = join(wpDir, `bwx-original-${slug}`);

  // A run that died before it could restore leaves the original here. It is
  // still the original; whatever is in the plugin's place is the leftover copy.
  const leftover = existsSync(aside);
  const exists = leftover || existsSync(plugin) || isLink(plugin);

  if (!exists) {
    throw new Error(`${slug} is not installed in ${wpDir}; bring the site up with --slug ${slug}`);
  }

  const target = !leftover && isLink(plugin) ? readlinkSync(plugin) : null;

  if (leftover) {
    rmSync(plugin, { recursive: true, force: true });
  } else if (target) {
    unlinkSync(plugin);
  } else {
    renameSync(plugin, aside);
  }

  const restore = () => {
    // An update replaces the folder with a real one, and a failed run can leave
    // it half there; either way it is a copy, and the copy goes.
    rmSync(plugin, { recursive: true, force: true });

    if (target) {
      link(target, plugin);
    } else {
      renameSync(aside, plugin);
    }
  };

  try {
    stageArtifact(artifact, plugin);
  } catch (error) {
    restore();
    throw error;
  }

  return restore;
}

function isLink(path) {
  try {
    return lstatSync(path).isSymbolicLink();
  } catch {
    return false;
  }
}
