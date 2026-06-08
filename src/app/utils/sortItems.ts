import { Item } from '../types';

/** Human-readable label for an item — features/sub-items/releases use `name`, bugs/feedback use `title`. */
export function itemLabel( item: Item ): string {
  return ( 'name' in item ? item.name : ( item as { title?: string } ).title ) || '';
}

/** Return a new array sorted alphabetically (case-insensitive) by item label (#28). */
export function sortItemsByName<T extends Item>( items: T[] ): T[] {
  return [ ...items ].sort( ( a, b ) =>
    itemLabel( a ).localeCompare( itemLabel( b ), undefined, { sensitivity: 'base' } )
  );
}
