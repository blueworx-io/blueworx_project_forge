#!/usr/bin/env node
// Stages one artifact's shipped tree into a directory, from the allowlist in
// bin/artifacts.json.
//
//   node bin/stage-artifact.mjs client .wp-test-client-plugin/blueworx-forge-client
//
// One definition, used by everything that needs a runnable copy of an artifact:
// the pair harness points a WordPress at it, and the single-instance test setup
// installs the client plugin from it. Hand-picking a file list in each of those
// would give three descriptions of what ships and no way to notice them drifting
// apart — and the one that matters, bin/build-zip.sh, is the one nobody would be
// testing.

import process from 'node:process';
import { cpSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));

/**
 * Stages an artifact's shipped files into `target`, replacing whatever is there.
 *
 * @param {string} name   Artifact key in bin/artifacts.json — "studio" or "client".
 * @param {string} target Directory to stage into. Created if missing.
 * @returns {string[]} The paths staged, relative to the artifact.
 */
export function stageArtifact(name, target) {
  const config = JSON.parse(readFileSync(join(ROOT, 'bin', 'artifacts.json'), 'utf8'));
  const artifact = config.artifacts?.[name];

  if (!artifact) {
    throw new Error(`No such artifact "${name}" in bin/artifacts.json.`);
  }

  rmSync(target, { recursive: true, force: true });
  mkdirSync(target, { recursive: true });

  const staged = [];

  for (const entry of artifact.include) {
    cpSync(join(ROOT, artifact.root, entry), join(target, entry), { recursive: true });
    staged.push(entry);
  }
  for (const entry of artifact.shared) {
    cpSync(join(ROOT, entry), join(target, entry), { recursive: true });
    staged.push(entry);
  }

  return staged;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const [name, target] = process.argv.slice(2);

  if (!name || !target) {
    console.error('Usage: node bin/stage-artifact.mjs <studio|client> <target-dir>');
    process.exit(1);
  }

  mkdirSync(dirname(resolve(target)), { recursive: true });
  const staged = stageArtifact(name, resolve(target));
  console.log(`Staged ${name} into ${target}: ${staged.join(', ')}`);
}
