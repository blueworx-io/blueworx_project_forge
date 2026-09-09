#!/usr/bin/env node
// Lays the shipped copies of the shared design system down from the vendored
// one. The vendored folder at .claude/skills/blueworx-admin-design is the only
// source, and it is itself byte-compared against the foundation on every pull
// request — so nothing here is ever hand-edited, and a difference is always
// fixed by re-pulling the foundation rather than by editing assets/.
//
// This runs after Vite, not before: vite.config.js builds into assets/ with
// emptyOutDir, so anything laid down first is deleted.

import process from 'node:process';
import { cpSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const SKILL = '.claude/skills/blueworx-admin-design';

/**
 * What gets copied where. Pure, so the unit test can check it against the
 * paths check-design-system-sync.mjs compares without touching the disk.
 *
 * The destinations are not ours to choose: ci-wordpress.yml passes that script
 * no path overrides, so its defaults are binding.
 *
 * @returns {Array<{from: string, to: string, kind: 'file'|'dir'}>}
 */
export function plan() {
  return [
    { from: `${SKILL}/styles.css`, to: 'assets/blueworx-admin-design.css', kind: 'file' },
    // The registrar has to be here, not merely committed: vite builds into
    // assets/ with emptyOutDir, so anything this plan does not rewrite is
    // deleted by every build and the plugin then fatals on a missing require.
    { from: `${SKILL}/design-system.php`, to: 'assets/blueworx-admin-design.php', kind: 'file' },
    { from: `${SKILL}/fonts`, to: 'assets/fonts', kind: 'dir' },
    { from: `${SKILL}/assets/icons/lucide-icons.js`, to: 'assets/blueworx-admin-icons.js', kind: 'file' },
    { from: `${SKILL}/editor/blueworx-page-editor.js`, to: 'assets/blueworx-page-editor.js', kind: 'file' },
    { from: `${SKILL}/editor/php`, to: 'blueworx-page-editor', kind: 'dir' },
  ];
}

/** Writes every entry in the plan. */
export function sync() {
  for (const { from, to, kind } of plan()) {
    const source = resolve(ROOT, from);
    const target = resolve(ROOT, to);
    mkdirSync(dirname(target), { recursive: true });
    cpSync(source, target, { recursive: kind === 'dir' });
  }
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(ROOT, 'bin/sync-design-system.mjs')) {
  sync();
  console.log(`Design system: wrote ${plan().length} path(s) from ${SKILL}.`);
}
