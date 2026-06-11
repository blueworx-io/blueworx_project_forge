import { Item } from '../types';

/**
 * Whether an item counts as "done" for dependency purposes. Mirrors how the rest
 * of the app reads completion per type (the Gantt treats 'deployed' as done, etc.).
 */
export function isItemComplete( item: Item ): boolean {
  switch ( item.type ) {
    case 'feature':
    case 'subitem':  return item.workflowStage === 'deployed';
    case 'bug':      return item.bugStatus === 'resolved';
    case 'feedback': return item.status === 'resolved';
    case 'release':  return item.status === 'complete';
    default:         return false;
  }
}

/** The IDs this item is blocked by (safe against a missing field). */
export function getDependencyIds( item: Item ): string[] {
  return ( item as { dependsOn?: string[] } ).dependsOn ?? [];
}

/** Resolve this item's "blocked by" IDs to items, dropping stale/deleted references. */
export function getDependencies( item: Item, allItems: Item[] ): Item[] {
  const ids = new Set( getDependencyIds( item ) );
  return allItems.filter( i => ids.has( i.id ) );
}

/** This item's blockers that are not yet complete. */
export function getBlockingDependencies( item: Item, allItems: Item[] ): Item[] {
  return getDependencies( item, allItems ).filter( i => ! isItemComplete( i ) );
}

/** Items that list this item in their dependsOn — i.e. the items this one blocks. */
export function getDependents( item: Item, allItems: Item[] ): Item[] {
  return allItems.filter( i => getDependencyIds( i ).includes( item.id ) );
}

/** True when at least one blocker is unresolved. */
export function hasUnresolvedBlockers( item: Item, allItems: Item[] ): boolean {
  return getBlockingDependencies( item, allItems ).length > 0;
}

/** Display label for an item (features/sub-items/releases use name; bugs/feedback use title). */
export function itemDisplayName( item: Item ): string {
  return 'name' in item ? item.name : ( item as { title: string } ).title;
}
