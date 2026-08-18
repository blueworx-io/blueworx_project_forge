#!/usr/bin/env node
// Fails when a Playwright run executed nothing.
//
//   node bin/check-tests-ran.mjs playwright-pair-report.json
//
// `playwright test` exits 0 when every spec skips itself, which is how a suite
// once reported green for months having asserted nothing. The shared foundation
// job makes this check for the single-instance run; the pair job is ours, so it
// makes it here.

import process from 'node:process';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const report = resolve(process.argv[2] ?? 'playwright-pair-report.json');

if (!existsSync(report)) {
  console.error(`No Playwright report at ${report} — the run produced nothing, so nothing was proven.`);
  process.exit(1);
}

const results = JSON.parse(readFileSync(report, 'utf8'));
const counts = { expected: 0, unexpected: 0, skipped: 0, flaky: 0, ...(results.stats ?? {}) };
const ran = counts.expected + counts.unexpected + counts.flaky;

if (ran === 0) {
  console.error(
    `The pair suite executed ${ran} tests (${counts.skipped} skipped). ` +
      'A suite that runs nothing is not a suite that passed.'
  );
  process.exit(1);
}

console.log(`Pair suite executed ${ran} tests (${counts.skipped} skipped).`);
