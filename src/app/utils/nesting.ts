import { Item } from '../types';
import { sortItemsByName } from './sortItems';

/**
 * The id of the item this item should nest under, or null if top-level.
 * Bug/feedback prefer a linked sub-item, then a linked feature.
 * Sub-items nest under their parent feature.
 */
export function getNestingParentId( item: Item ): string | null {
  if ( item.type === 'subitem' ) return item.parentFeatureId || null;
  if ( item.type === 'bug' || item.type === 'feedback' ) {
    return item.linkedSubItemId || item.linkedFeatureId || null;
  }
  return null;
}

function releaseIdOf( item: Item ): string | undefined {
  return 'releaseId' in item ? item.releaseId : undefined;
}

/**
 * Split a flat item list into nested children (keyed by parent id) and
 * top-level items. A child only nests when its nesting parent is present in
 * the same list AND shares its release — items with no release share the
 * "unassigned" bucket and nest together, matching how the board groups them.
 */
export function buildNestedGroups( items: Item[] ): {
  nestedChildren: Record<string, Item[]>;
  topLevelItems: Item[];
} {
  const nestedChildren: Record<string, Item[]> = {};
  const topLevelItems: Item[] = [];

  items.forEach( ( item ) => {
    const parentId = getNestingParentId( item );
    const parent = parentId ? items.find( ( i ) => i.id === parentId ) : undefined;
    if ( parent && releaseIdOf( item ) === releaseIdOf( parent ) ) {
      ( nestedChildren[parentId!] ||= [] ).push( item );
    } else {
      topLevelItems.push( item );
    }
  } );

  // Order nested children alphabetically (#28)
  Object.keys( nestedChildren ).forEach( id => {
    nestedChildren[id] = sortItemsByName( nestedChildren[id] );
  } );

  return { nestedChildren, topLevelItems };
}
