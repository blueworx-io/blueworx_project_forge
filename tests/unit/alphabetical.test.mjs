import test from 'node:test';
import assert from 'node:assert/strict';

// 2026-10-05. Every dropdown that lists named things — clients, sites,
// people, items — reads A to Z, whatever order the server handed them in.

import { alphabetical } from '../../src/alphabetical.mjs';

test('sorts by the label, ignoring case', () => {
  const sites = [{ id: 'c', name: 'zebra' }, { id: 'a', name: 'Apple' }, { id: 'b', name: 'mango' }];

  assert.deepEqual(alphabetical(sites, (site) => site.name).map((site) => site.id), ['a', 'b', 'c']);
});

test('leaves the list it was given alone', () => {
  const people = [{ name: 'Zed' }, { name: 'Amy' }];
  alphabetical(people, (person) => person.name);

  assert.equal(people[0].name, 'Zed');
});

test('numbers in names sort as numbers, and ties keep their order', () => {
  const items = [{ id: 'x', title: 'Site 10' }, { id: 'y', title: 'Site 9' }, { id: 'z', title: 'Site 9' }];

  assert.deepEqual(alphabetical(items, (item) => item.title).map((item) => item.id), ['y', 'z', 'x']);
});

test('reads a plain label field when told its name', () => {
  assert.deepEqual(alphabetical([{ value: '2', label: 'b' }, { value: '1', label: 'a' }], 'label').map((option) => option.value), ['1', '2']);
});
