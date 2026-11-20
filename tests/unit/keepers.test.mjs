import test from 'node:test';
import assert from 'node:assert/strict';

// #695. Who looks after the agent's work, as the Agents screen's filter
// offers them: one choice a person, even when two share a name.

import { keeperLabels } from '../../src/keepers.mjs';

test('each person is offered by name', () => {
  assert.deepEqual(keeperLabels(['p1', 'p2'], { p1: 'Sam', p2: 'Alex' }), { p1: 'Sam', p2: 'Alex' });
});

test('two people with one name are told apart, in the order given', () => {
  assert.deepEqual(keeperLabels(['p1', 'p2'], { p1: 'Sam', p2: 'Sam' }), { p1: 'Sam (1)', p2: 'Sam (2)' });
});

test('somebody with no known name is Somebody', () => {
  assert.deepEqual(keeperLabels(['p9'], {}), { p9: 'Somebody' });
});

test('a person listed twice is one choice', () => {
  assert.deepEqual(keeperLabels(['p1', 'p1'], { p1: 'Sam' }), { p1: 'Sam' });
});
