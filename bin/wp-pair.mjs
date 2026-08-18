#!/usr/bin/env node
// Provisions the studio and client WordPress sites as a pair (#86).
//
//   node bin/wp-pair.mjs up
//   node bin/wp-pair.mjs down
//
// Two disposable installs, each with its own directory, port and database, both
// from the shared foundation harness — this drives that script twice rather than
// reimplementing it, so the pair cannot drift from the single instance every
// other project tests against.
//
// The two halves are installed differently on purpose. The studio site links
// the repository itself, so edits are live and the loop stays fast. The client
// site is pointed at a *staged* copy built from the client allowlist, for two
// reasons: the client plugin's files live under client/ but it also needs
// plugin-update-checker from the repo root, so there is no single directory to
// link; and staging means the client site runs exactly what ships to a client,
// rather than a convenient approximation of it.
//
// Credentials are the harness's own and identical on both sites — admin /
// wptest-admin-pw. They are throwaway logins on throwaway sites.

import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { stageArtifact } from './stage-artifact.mjs';

const ROOT = fileURLToPath(new URL('..', import.meta.url));

// Where the shared harness might be. A developer has the foundation as a
// sibling directory; CI cannot check a repo out above its own workspace, so it
// puts it in .foundation, the same name the foundation's own workflows use.
const HARNESS_CANDIDATES = [
  process.env.BWX_FOUNDATION_DIR && resolve(process.env.BWX_FOUNDATION_DIR, 'scripts', 'wp-test-env.mjs'),
  resolve(ROOT, '..', 'bluegroup_core_foundation', 'scripts', 'wp-test-env.mjs'),
  resolve(ROOT, '.foundation', 'scripts', 'wp-test-env.mjs'),
].filter(Boolean);

const HARNESS = HARNESS_CANDIDATES.find((candidate) => existsSync(candidate));

export const STUDIO = { name: 'studio', slug: 'blueworx-forge', dir: '.wp-test', port: 8892 };
export const CLIENT = { name: 'client', slug: 'blueworx-forge-client', dir: '.wp-test-client', port: 8893 };

// Where the client artifact is staged for the client site to link. Outside
// .wp-test-client so that tearing the site down does not take the plugin with
// it, and git-ignored either way.
const CLIENT_PLUGIN_DIR = '.wp-test-client-plugin';

const command = process.argv[2] ?? 'up';

if (!HARNESS) {
  console.error('ERROR: the shared harness was not found. It lives in the bluegroup_core_foundation repo.');
  console.error('Looked in:');
  for (const candidate of HARNESS_CANDIDATES) console.error(`  ${candidate}`);
  console.error('Set BWX_FOUNDATION_DIR if it is somewhere else.');
  process.exit(1);
}

if (command === 'up') {
  up();
} else if (command === 'down') {
  down();
} else {
  fail(`Unknown command "${command}". Use "up" or "down".`);
}

function up() {
  harness('up', ['--plugin', ROOT, '--slug', STUDIO.slug, '--dir', STUDIO.dir, '--port', String(STUDIO.port)]);

  const staged = resolve(ROOT, CLIENT_PLUGIN_DIR, CLIENT.slug);
  mkdirSync(resolve(ROOT, CLIENT_PLUGIN_DIR), { recursive: true });
  stageArtifact('client', staged);

  harness('up', ['--plugin', staged, '--slug', CLIENT.slug, '--dir', CLIENT.dir, '--port', String(CLIENT.port)]);

  console.log('');
  console.log('  Studio   http://127.0.0.1:' + STUDIO.port + '   (this repo, linked — edits are live)');
  console.log('  Client   http://127.0.0.1:' + CLIENT.port + '   (staged from the client allowlist)');
  console.log('  Login    admin / wptest-admin-pw, on both');
  console.log('');
  console.log('  Run the pair suite against them:');
  console.log('    npm run test:pair');
  console.log('');
  console.log('  Stop them with: npm run wp:pair:down');
}

function down() {
  // Both, even if one is already stopped: a half-torn-down pair is worse than
  // either state, because the next `up` reuses whatever it finds.
  for (const site of [STUDIO, CLIENT]) {
    harness('down', ['--dir', site.dir], { allowFailure: true });
  }

  rmSync(resolve(ROOT, CLIENT_PLUGIN_DIR), { recursive: true, force: true });
}

function harness(verb, args, { allowFailure = false } = {}) {
  const result = spawnSync(process.execPath, [HARNESS, verb, ...args], {
    cwd: ROOT,
    stdio: 'inherit',
  });

  if (result.status !== 0 && !allowFailure) {
    fail(`The harness failed: ${verb} ${args.join(' ')}`);
  }
}

function fail(message) {
  console.error(`ERROR: ${message}`);
  process.exit(1);
}

