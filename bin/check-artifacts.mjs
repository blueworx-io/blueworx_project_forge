#!/usr/bin/env node
// Checks the two build allowlists in bin/artifacts.json.
//
//   node bin/check-artifacts.mjs
//
// ARCH-1 puts a Blueworx Forge plugin on the client's own WordPress. Two
// artifacts rather than one plugin with a switch, so a client's site cannot
// physically contain command-centre code — not merely be configured not to run
// it. A configuration flag is one bad merge away from being wrong; a file that
// was never in the archive cannot be.
//
// The guarantee is enforced by geography. Everything the client ships lives
// under client/; studio code lives everywhere else. A path is either inside
// client/ or it cannot be in the client allowlist, and "shared" — the one door
// out — is a closed list rather than a rule, because widening it is the easiest
// mistake to make and the hardest to notice.

// Imported rather than used as a global: bin/ has no node globals in the ESLint
// config, and adding them there would also silence the pre-existing findings in
// check-decisions.mjs, which are Luke's call and not this change's business.
import process from 'node:process';
import { existsSync, readFileSync } from 'node:fs';
import { posix, resolve, relative, isAbsolute, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));

// The only paths an artifact may take from outside its own root. Deliberately
// short, deliberately not a pattern: plugin-update-checker is here because a
// client site without it could never receive a fix, and tokens because the two
// interfaces have to be able to drift apart before they can look different, and
// a second copy of the token layer is how that starts (#85). Neither is studio
// code: the boundary exists to keep command-centre code off a client site, not
// to keep colours off it. Adding a third entry is a decision, not a build change.
const SHAREABLE = new Set(['plugin-update-checker', 'CHANGELOG.md', 'tokens']);

/**
 * Checks every artifact definition, returning a list of problems.
 *
 * @param {object} config             Parsed bin/artifacts.json.
 * @param {object} [options]
 * @param {boolean} [options.checkExistence] Whether listed paths must exist on disk.
 * @returns {string[]} One line per problem; empty when everything is in order.
 */
export function checkArtifacts(config, options = {}) {
  const { checkExistence = true } = options;
  const problems = [];
  const artifacts = config.artifacts ?? {};
  const seenSlugs = new Map();

  for (const [name, artifact] of Object.entries(artifacts)) {
    const { slug, main, root, include = [], shared = [] } = artifact;

    if (seenSlugs.has(slug)) {
      problems.push(
        `"${name}" and "${seenSlugs.get(slug)}" have the same slug "${slug}" — WordPress would install one over the other.`
      );
    }
    seenSlugs.set(slug, name);

    if (!include.includes(main)) {
      problems.push(`"${name}" does not ship its own main file "${main}" — the plugin would not activate.`);
    }

    for (const entry of include) {
      const contained = isContainedIn(root, entry);

      if (!contained) {
        problems.push(
          `"${name}" allowlists "${entry}", which resolves outside ${root}/. ` +
            `Everything an artifact ships lives under its own root; use "shared" for the few paths both artifacts take.`
        );
        continue;
      }

      if (checkExistence && !existsSync(resolve(ROOT, root, entry))) {
        problems.push(`"${name}" allowlists "${entry}", which does not exist. Did the build run?`);
      }
    }

    for (const entry of shared) {
      if (!SHAREABLE.has(entry)) {
        problems.push(
          `"${name}" wants to share "${entry}", which is not a shareable path. ` +
            `Only ${[...SHAREABLE].join(', ')} may cross between artifacts, and widening that list is a decision, not a build change.`
        );
        continue;
      }

      if (checkExistence && !existsSync(resolve(ROOT, entry))) {
        problems.push(`"${name}" shares "${entry}", which does not exist.`);
      }
    }
  }

  return problems;
}

/**
 * Whether a listed path stays inside its artifact's root.
 *
 * Resolved rather than string-matched: "../client/../includes" reads as though
 * it stays inside and does not.
 *
 * @param {string} root  Artifact root, relative to the repo.
 * @param {string} entry Allowlisted path.
 * @returns {boolean}
 */
function isContainedIn(root, entry) {
  if (isAbsolute(entry) || entry.startsWith(posix.sep)) return false;

  const base = resolve(ROOT, root);
  const target = resolve(base, entry);
  const rel = relative(base, target);

  return rel !== '' && !rel.startsWith('..') && !isAbsolute(rel) && !rel.split(sep).includes('..');
}

/**
 * Checks that every artifact's plugin header carries the same version.
 *
 * One repo, one tag, one release. The release tag is checked against the studio
 * header only, so a client header left behind would publish a client artifact
 * whose version disagrees with the release it is attached to — and client sites
 * would either be offered nothing or be offered the same version forever.
 *
 * @param {object} config Parsed bin/artifacts.json.
 * @returns {string[]} One line per problem.
 */
export function checkVersions(config) {
  const seen = new Map();

  for (const [name, artifact] of Object.entries(config.artifacts ?? {})) {
    const file = resolve(ROOT, artifact.root, artifact.main);
    if (!existsSync(file)) continue;

    const match = readFileSync(file, 'utf8').match(/^\s*\*?\s*Version:\s*(\S+)/im);
    if (!match) {
      seen.set(name, null);
      continue;
    }
    seen.set(name, match[1]);
  }

  const versions = [...new Set(seen.values())];

  if (versions.length <= 1) return [];

  return [
    `The artifacts disagree about the version: ${[...seen]
      .map(([name, version]) => `${name} ${version ?? '(no Version: header)'}`)
      .join(', ')}. One repo means one tag, and the tag is checked against the studio header only.`,
  ];
}

// Run as a script rather than imported by the tests.
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const config = JSON.parse(readFileSync(resolve(ROOT, 'bin/artifacts.json'), 'utf8'));
  const problems = [...checkArtifacts(config), ...checkVersions(config)];

  if (problems.length > 0) {
    console.error('The build allowlists are not shippable:\n');
    for (const problem of problems) console.error(`  - ${problem}`);
    console.error('');
    process.exit(1);
  }

  const names = Object.keys(config.artifacts ?? {});
  console.log(`Artifact allowlists are in order (${names.join(', ')}).`);
}
