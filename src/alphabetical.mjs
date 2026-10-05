/**
 * A copy of a list in A-to-Z order of what a person reads (2026-10-05).
 *
 * Plain JavaScript rather than TypeScript so the unit tests can load it, as
 * duration.mjs is. Case is ignored and digits compare as numbers, so
 * "Site 9" comes before "Site 10". Ties keep the order they arrived in.
 *
 * @template T
 * @param {T[]} items The list.
 * @param {keyof T | ((item: T) => string)} label The field, or how to read it.
 * @returns {T[]} A sorted copy.
 */
export function alphabetical(items, label) {
  const read = 'function' === typeof label ? label : (item) => String(item[label] ?? '');

  return [...items].sort((a, b) => read(a).localeCompare(read(b), undefined, { sensitivity: 'base', numeric: true }));
}
