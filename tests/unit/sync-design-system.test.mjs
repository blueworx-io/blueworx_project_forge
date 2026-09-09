// House style, matching tests/unit/check-artifacts.test.mjs: test is the
// default import, assert is the strict build.
import test from 'node:test';
import assert from 'node:assert/strict';
import { plan } from '../../bin/sync-design-system.mjs';

// The paths check-design-system-sync.mjs compares when the workflow passes it
// no overrides — which ci-wordpress.yml does not. If this list and that script
// disagree, the guardrail silently compares nothing.
const REQUIRED_DESTINATIONS = [
  'assets/blueworx-admin-design.css',
  'assets/blueworx-admin-design.php',
  'assets/fonts',
  'assets/blueworx-admin-icons.js',
  'assets/blueworx-page-editor.js',
  'blueworx-page-editor',
];

test('every path the drift check compares is produced by the sync step', () => {
  const produced = plan().map((entry) => entry.to);
  for (const required of REQUIRED_DESTINATIONS) {
    assert.ok(
      produced.includes(required),
      `${required} is compared by check-design-system-sync.mjs but nothing produces it`
    );
  }
});

test('every source sits inside the vendored design system', () => {
  for (const { from } of plan()) {
    assert.ok(
      from.startsWith('.claude/skills/blueworx-admin-design/'),
      `${from} is not in the vendored system — the vendored copy is the only source`
    );
  }
});

test('each entry says whether it is a file or a directory', () => {
  for (const entry of plan()) {
    assert.ok(['file', 'dir'].includes(entry.kind), `${entry.to} has no valid kind`);
  }
});
