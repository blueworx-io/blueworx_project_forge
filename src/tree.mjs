/**
 * One tree of projects, releases and features, shared by the client profile,
 * the project page and the release page (#561).
 *
 * Plain JavaScript rather than TypeScript so the unit tests can load it.
 */

/** Work that hangs off a feature and is never a parent itself. */
export const LEAF_TYPES = ['bug', 'task', 'feedback'];

/** The levels, highest first: Levels::ALL. */
export const LEVEL_ORDER = ['project', 'milestone', 'feature', 'sub-feature'];

/**
 * #562. Whether one item may sit under another, as the server decides it: a
 * bug, task or feedback item holds nothing and may sit under any level;
 * everything else sits under a higher level only.
 *
 * @param {{ level: string, work_type: string }} parent The proposed parent.
 * @param {{ level: string, work_type: string }} child The item to place.
 * @returns {boolean}
 */
export function mayHold(parent, child) {
  if (LEAF_TYPES.includes(parent.work_type)) return false;
  if (LEAF_TYPES.includes(child.work_type)) return true;

  const above = LEVEL_ORDER.indexOf(parent.level);
  const below = LEVEL_ORDER.indexOf(child.level);

  return -1 !== above && -1 !== below && above < below;
}

/**
 * The ids of everything beneath an item, however deep: none of them may
 * become its parent.
 *
 * @param {object[]} items Every item on the site.
 * @param {string} rootId The item.
 * @returns {Set<string>}
 */
export function beneath(items, rootId) {
  const found = new Set();
  const queue = [rootId];

  while (queue.length) {
    const id = queue.shift();

    for (const one of items) {
      if (one.parent_id === id && !found.has(one.id) && one.id !== rootId) {
        found.add(one.id);
        queue.push(one.id);
      }
    }
  }

  return found;
}

const byStartThenTitle = (a, b) => {
  const start = String(a.planned_start || '').localeCompare(String(b.planned_start || ''));

  return 0 !== start ? start : String(a.title || '').localeCompare(String(b.title || ''), undefined, { sensitivity: 'base', numeric: true });
};

/**
 * One item with what hangs under it.
 *
 * @param {object[]} items Every item the screen holds.
 * @param {string} rootId The item to start from.
 * @returns {{ item: object, parts: object[], attached: object[] }} The tree.
 */
export function buildTree(items, rootId) {
  const item = items.find((one) => one.id === rootId);
  const children = items.filter((one) => one.parent_id === rootId && one.id !== rootId);

  return {
    item,
    parts: children
      .filter((one) => !LEAF_TYPES.includes(one.work_type))
      .sort(byStartThenTitle)
      .map((one) => buildTree(items, one.id)),
    attached: children.filter((one) => LEAF_TYPES.includes(one.work_type)).sort((a, b) => Number(a.created_at || 0) - Number(b.created_at || 0)),
  };
}

/**
 * Items of one level, earliest start first.
 *
 * @param {object[]} items Every item.
 * @param {string} level 'project', 'milestone' and so on.
 * @returns {object[]} A sorted copy.
 */
export function topLevel(items, level) {
  return items.filter((one) => one.level === level).sort(byStartThenTitle);
}

/** Matches Derived::counted(): cancelled and archived work is not part of the count. */
const counted = (one) => !one.terminal_outcome && !one.archived;

/**
 * How far along a node is, and over what dates.
 *
 * @param {{ item: object, parts: object[], attached: object[] }} node A tree node.
 * @returns {{ total: number, finished: number, start: string, due: string, ongoing: boolean }} Progress.
 */
export function summary(node) {
  const item = node.item || {};
  const kids = [...node.parts.map((part) => part.item), ...node.attached].filter(counted);
  const total = Number.isFinite(item.children_total) ? item.children_total : kids.length;
  const finished = Number.isFinite(item.children_finished) ? item.children_finished : kids.filter((one) => ['completed', 'released'].includes(one.stage)).length;
  const due = item.planned_due || item.derived_due || '';

  return { total, finished, start: item.planned_start || item.derived_start || '', due, ongoing: !due };
}
