import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// The two workflows describe what must not ship, and they have to describe it
// identically: ci.yml checks a pull request against its list, release.yml builds
// the artifact from its own. If they drift, a pull request passes a check that
// the release then ignores — which is precisely how v2.8.0 shipped the pair
// suite's Playwright config to live sites.

const read = (name) =>
  readFileSync(fileURLToPath(new URL(`../../.github/workflows/${name}`, import.meta.url)), 'utf8');

// The exclude_paths block: every indented line after it until a line that is
// not a path. Comments are guidance for humans, not part of the list.
function excludePaths(yaml) {
  const lines = yaml.split(/\r?\n/);
  const start = lines.findIndex((line) => line.trim().startsWith('exclude_paths:'));

  assert.notEqual(start, -1, 'a workflow no longer declares exclude_paths');

  const paths = [];

  for (const line of lines.slice(start + 1)) {
    const value = line.trim();

    if (value.startsWith('#')) continue;
    if (!value.startsWith('/')) break;

    paths.push(value);
  }

  return paths;
}

test('both workflows exclude exactly the same paths', () => {
  assert.deepEqual(
    excludePaths(read('ci.yml')),
    excludePaths(read('release.yml')),
    'ci.yml and release.yml disagree about what must not ship'
  );
});

test('the test configuration is excluded', () => {
  // Named rather than left to the general rule above, because this one already
  // reached a published release once.
  const paths = excludePaths(read('release.yml'));

  assert.ok(paths.includes('/playwright.pair.config.js'), 'the pair suite config would ship');
  assert.ok(paths.includes('/src'), 'the front-end source would ship');
  assert.ok(paths.includes('/client'), 'the client plugin would ship inside the studio one');
});
