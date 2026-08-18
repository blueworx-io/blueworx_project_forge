import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

// #85. Both interfaces draw their design tokens from one file. What is under
// test is not that the file exists — it is that there is only one of it, and
// that both builds actually reach it. Two token files that agree today is
// exactly the state a drift starts from.

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const TOKENS = join(ROOT, 'tokens');

// A value that appears in the token layer and nowhere it could be coincidence:
// the brand colour, in the form a CSS custom property declares it.
const DECLARATION = /--brand-600\s*:\s*(#[0-9a-fA-F]{3,8})/;

function brandValue(css) {
  const found = css.match(DECLARATION);
  return found ? found[1].toLowerCase() : null;
}

// Everything that either ships or is compiled into something that ships.
// design/ is intake and never ships; the test directories describe rather than
// build; node_modules and the disposable WordPress installs are not ours.
const SKIP = new Set(['node_modules', 'design', 'tests', '.git', '.wp-test', '.wp-test-client', '.wp-test-client-plugin', 'vendor', 'test-results', 'playwright-report']);

function* cssFiles(dir) {
  for (const entry of readdirSync(dir)) {
    if (SKIP.has(entry)) continue;

    const path = join(dir, entry);

    if (statSync(path).isDirectory()) {
      yield* cssFiles(path);
    } else if (entry.endsWith('.css')) {
      yield path;
    }
  }
}

test('the brand colour is declared in exactly one place', () => {
  const declaring = [...cssFiles(ROOT)].filter((path) => DECLARATION.test(readFileSync(path, 'utf8')));

  assert.deepEqual(
    declaring.map((path) => relative(ROOT, path).split(sep).join('/')),
    ['tokens/colors.css'],
    'a second file declares the brand colour — that is where drift between the two interfaces starts'
  );
});

test('the studio build compiles the tokens in', () => {
  const expected = brandValue(readFileSync(join(TOKENS, 'colors.css'), 'utf8'));
  assert.ok(expected, 'the token layer no longer declares --brand-600');

  // The app bundle carries its own stylesheet, injected at runtime, so the
  // token values end up inside the built JavaScript rather than beside it.
  const bundle = readFileSync(join(ROOT, 'assets/js/blueworx-forge.js'), 'utf8');

  assert.ok(
    bundle.includes(`--brand-600:${expected}`),
    'the built studio bundle does not carry the token values — run npm run build'
  );
});

test('the client artifact ships the token layer, and takes it from the one copy', () => {
  const config = JSON.parse(readFileSync(join(ROOT, 'bin/artifacts.json'), 'utf8'));
  const client = config.artifacts.client;

  assert.ok(
    client.shared.includes('tokens'),
    'the client plugin no longer ships the tokens, so its screens have none'
  );

  // Shared, not copied into client/. A path inside client/ would be a second
  // token layer, which is the thing this issue exists to prevent.
  assert.ok(
    !client.include.some((path) => path.startsWith('tokens')),
    'the client artifact has its own token path — there must be only one copy'
  );
});
