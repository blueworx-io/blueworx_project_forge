import { Item } from '../types';

/**
 * Global "view" filters shared across Kanban / Gantt / Calendar (#35).
 * Each dimension is either 'all' (no filtering) or a specific value.
 */
export interface ViewFilters {
  release:     string; // 'all' or a release id
  stage:       string; // 'all' or a workflow-stage id
  category:    string; // 'all' or a category name
  brand:       string; // 'all' or a brand name
  statTracking: string; // 'all' | 'tracked' | 'not-tracked'
  search:      string; // free-text query ('' = no search)
}

export const EMPTY_FILTERS: ViewFilters = {
  release:     'all',
  stage:       'all',
  category:    'all',
  brand:       'all',
  statTracking: 'all',
  search:      '',
};

export type FilterKey = keyof ViewFilters;

/** True when any filter is narrowing the result set. */
export function hasActiveFilters( f: ViewFilters ): boolean {
  return f.release !== 'all' || f.stage !== 'all' || f.category !== 'all' || f.brand !== 'all' || f.statTracking !== 'all' || f.search.trim() !== '';
}

/**
 * Whether an item passes the active filters. Items that lack a filtered
 * dimension (e.g. bugs have no brand) are excluded when that filter is set —
 * the intuitive "whittle down" behaviour.
 */
export function matchesFilters( item: Item, f: ViewFilters ): boolean {
  if ( f.release !== 'all' ) {
    const rid = 'releaseId' in item ? item.releaseId : undefined;
    if ( rid !== f.release ) return false;
  }
  if ( f.stage !== 'all' ) {
    if ( ! ( 'workflowStage' in item ) || item.workflowStage !== f.stage ) return false;
  }
  if ( f.category !== 'all' ) {
    if ( ! ( 'category' in item ) || item.category !== f.category ) return false;
  }
  if ( f.brand !== 'all' ) {
    const brands = 'brands' in item ? item.brands : undefined;
    if ( ! brands || ! brands.includes( f.brand ) ) return false;
  }
  if ( f.statTracking !== 'all' ) {
    if ( ! ( 'isTrackedAsStat' in item ) ) return false;
    const tracked = ( item as { isTrackedAsStat: boolean } ).isTrackedAsStat;
    if ( f.statTracking === 'tracked'     && ! tracked ) return false;
    if ( f.statTracking === 'not-tracked' &&   tracked ) return false;
  }
  const q = f.search.trim().toLowerCase();
  if ( q !== '' ) {
    // Items carry their text as either `name` or `title`, plus an optional description.
    const title = ( 'name' in item ? item.name : 'title' in item ? item.title : '' ) ?? '';
    const desc  = 'description' in item ? ( item.description ?? '' ) : '';
    if ( ! ( title + ' ' + desc ).toLowerCase().includes( q ) ) return false;
  }
  return true;
}
