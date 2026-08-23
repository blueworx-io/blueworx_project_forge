import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { checkReadOnlyViews, READ_ONLY_VIEWS } from '../../bin/check-artifacts.mjs';

// #128 asks for something stronger than hidden buttons: "the client build
// contains no transition control to hide". A control that is merely never
// rendered is one a future change can start rendering, and nobody would notice
// until a client moved a card. So the assertion is about the files that ship,
// not about a page somebody remembered to look at.

const file = (path, contents) => [{ path, contents }];

test('the files that draw work, as they actually ship, contain no controls', () => {
  const real = READ_ONLY_VIEWS.map((path) => ({
    path,
    contents: readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8'),
  }));

  assert.deepEqual(checkReadOnlyViews(real), []);
});

test('a button smuggled into a work view is refused', () => {
  const problems = checkReadOnlyViews(
    file('client/includes/Admin/BoardScreen.php', "echo '<button>Move on</button>';")
  );

  assert.equal(problems.length, 1);
  assert.match(problems[0], /button/);
});

test('a select is refused — a stage picker is a transition control wearing a dropdown', () => {
  const problems = checkReadOnlyViews(
    file('client/includes/Admin/Card.php', "echo '<select name=\"stage\">';")
  );

  assert.equal(problems.length, 1);
});

test('a form is refused, however innocent it looks', () => {
  const problems = checkReadOnlyViews(
    file('client/includes/Admin/WorkScreen.php', "echo '<form method=\"post\">';")
  );

  assert.equal(problems.length, 1);
});

test('drag is refused, which is how a board would move a card without a button', () => {
  const problems = checkReadOnlyViews(
    file('client/includes/Admin/BoardScreen.php', "printf('<article draggable=\"true\">');")
  );

  assert.equal(problems.length, 1);
  assert.match(problems[0], /draggable/);
});

test('a link is allowed — going somewhere is not changing something', () => {
  const problems = checkReadOnlyViews(
    file('client/includes/Admin/CalendarScreen.php', "printf('<a href=\"%s\">Next</a>', $url);")
  );

  assert.deepEqual(problems, []);
});

test('the word transition in a comment is not a control', () => {
  const problems = checkReadOnlyViews(
    file(
      'client/includes/Admin/BoardScreen.php',
      '/**\n * Every transition is refused server-side.\n */\necho \'<p>Hello</p>\';'
    )
  );

  assert.deepEqual(problems, []);
});
