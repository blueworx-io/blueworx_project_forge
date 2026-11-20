/**
 * How each person looking after the agent's work is offered (#695): by name,
 * "Somebody" when the name is not known, and numbered in the order given when
 * two share a name, so the Agents screen's filter has one choice a person.
 *
 * Plain JavaScript with no DOM, so it can be tested with node alone.
 *
 * @param {string[]} ids                 Person ids, in the order to number them.
 * @param {Record<string, string>} names Person id to display name.
 * @returns {Record<string, string>} Person id to the label offered.
 */
export function keeperLabels(ids, names) {
  const unique = [...new Set(ids)];
  const nameOf = (id) => names[id] ?? 'Somebody';
  const shared = {};

  for (const id of unique) {
    shared[nameOf(id)] = (shared[nameOf(id)] ?? 0) + 1;
  }

  const seen = {};
  const labels = {};

  for (const id of unique) {
    const name = nameOf(id);
    seen[name] = (seen[name] ?? 0) + 1;
    labels[id] = 1 < shared[name] ? `${name} (${seen[name]})` : name;
  }

  return labels;
}
