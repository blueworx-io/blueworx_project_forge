import test from 'node:test';
import assert from 'node:assert/strict';

import { checkArtifacts } from '../../bin/check-artifacts.mjs';

// The guarantee under test is ARCH-1's: a client's WordPress cannot physically
// contain command-centre code. These cases are the ways somebody would break it
// by accident — nearly always by adding one plausible-looking path to the client
// allowlist.

const STUDIO = {
  slug: 'blueworx-forge',
  main: 'blueworx-forge.php',
  root: '.',
  include: ['blueworx-forge.php', 'includes'],
  shared: [],
};

const CLIENT = {
  slug: 'blueworx-forge-client',
  main: 'blueworx-forge-client.php',
  root: 'client',
  include: ['blueworx-forge-client.php', 'includes'],
  shared: ['plugin-update-checker'],
};

const config = (client) => ({ artifacts: { studio: STUDIO, client: { ...CLIENT, ...client } } });

// Nothing here touches the disk: existence is the zip script's problem, and a
// checker that needed a real tree could not test the cases that matter.
const options = { checkExistence: false };

test('the allowlists as they actually ship are accepted', async () => {
  const { readFile } = await import('node:fs/promises');
  const { fileURLToPath } = await import('node:url');
  const path = fileURLToPath(new URL('../../bin/artifacts.json', import.meta.url));
  const real = JSON.parse(await readFile(path, 'utf8'));

  assert.deepEqual(checkArtifacts(real, options), []);
});

test('a studio directory in the client allowlist is refused', () => {
  const problems = checkArtifacts(config({ include: ['blueworx-forge-client.php', '../includes'] }), options);

  assert.equal(problems.length, 1);
  assert.match(problems[0], /outside client\//);
});

test('an absolute path in the client allowlist is refused', () => {
  const problems = checkArtifacts(config({ include: ['blueworx-forge-client.php', '/includes'] }), options);

  assert.equal(problems.length, 1);
  assert.match(problems[0], /outside client\//);
});

test('a path that climbs out and back in is still refused', () => {
  // Reads as though it stays inside. It does not, which is why the check
  // resolves the path rather than matching on the string.
  const problems = checkArtifacts(
    config({ include: ['blueworx-forge-client.php', '../client/../includes'] }),
    options
  );

  assert.equal(problems.length, 1);
  assert.match(problems[0], /outside client\//);
});

test('the client cannot quietly widen what it shares with the studio', () => {
  // "shared" is the one door out of client/, so it is a closed list rather than
  // a rule — adding includes/ here would be the easiest possible mistake.
  const problems = checkArtifacts(config({ shared: ['plugin-update-checker', 'includes'] }), options);

  assert.equal(problems.length, 1);
  assert.match(problems[0], /not a shareable path/);
});

test('the studio artifact may ship studio code', () => {
  assert.deepEqual(checkArtifacts(config({}), options), []);
});

test('an artifact with no main file is refused', () => {
  const problems = checkArtifacts(config({ include: ['includes'] }), options);

  assert.equal(problems.length, 1);
  assert.match(problems[0], /main file/);
});

test('the two artifacts cannot share a slug', () => {
  const problems = checkArtifacts(config({ slug: 'blueworx-forge' }), options);

  assert.ok(problems.some((p) => /same slug/.test(p)));
});
