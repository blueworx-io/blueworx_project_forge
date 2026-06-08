import { Item } from '../types';

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
