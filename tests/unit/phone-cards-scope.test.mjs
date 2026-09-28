import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// #429: on a phone the studio's lists become cards. The kit is shared with the
// client app, which was left out of this work, so every card rule is scoped to
// the studio's own page and the client app's lists stay as they were.
test('the phone card rules for kit tables apply to the studio page only', () => {
  const css = readFileSync(new URL('../../src/kit/kit.css', import.meta.url), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  const selectors = [...css.matchAll(/([^{}]*data-stack[^{}]*)\{/g)]
    .flatMap((match) => match[1].split(','))
    .map((selector) => selector.trim())
    .filter(Boolean);

  assert.ok(selectors.length > 0, 'there are phone card rules to check');
  for (const selector of selectors) {
    assert.match(selector, /^\.bwx-forge-page\s/, `${selector} is scoped to the studio page`);
  }
});
