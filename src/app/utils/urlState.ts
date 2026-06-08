import { ViewFilters, EMPTY_FILTERS } from './filters';

export type View = 'kanban' | 'gantt' | 'calendar' | 'settings';

const VIEWS: View[] = [ 'kanban', 'gantt', 'calendar', 'settings' ];

export interface UrlState {
  view?:   View;
  filters: ViewFilters;
  itemId?: string;
}

/**
 * Shareable deep-link state encoded in the URL hash (#25). Hash is used (not the
 * path) so links work on any WordPress page without server-side routing.
 * Example: `#view=kanban&item=feature-1&r=rel-2&s=in-development&c=Scoring&b=SwingU`
 */
export function parseUrlState(): UrlState {
  const raw = ( typeof window !== 'undefined' ? window.location.hash : '' ).replace( /^#/, '' );
  const p = new URLSearchParams( raw );
  const view = p.get( 'view' );
  return {
    view: VIEWS.includes( view as View ) ? ( view as View ) : undefined,
    itemId: p.get( 'item' ) || undefined,
    filters: {
      release:  p.get( 'r' ) || 'all',
      stage:    p.get( 's' ) || 'all',
      category: p.get( 'c' ) || 'all',
      brand:    p.get( 'b' ) || 'all',
    },
  };
}

function buildParams( state: UrlState ): URLSearchParams {
  const p = new URLSearchParams();
  if ( state.view )   p.set( 'view', state.view );
  if ( state.itemId ) p.set( 'item', state.itemId );
  const f = state.filters;
  if ( f.release  !== 'all' ) p.set( 'r', f.release );
  if ( f.stage    !== 'all' ) p.set( 's', f.stage );
  if ( f.category !== 'all' ) p.set( 'c', f.category );
  if ( f.brand    !== 'all' ) p.set( 'b', f.brand );
  return p;
}

/** Push the current state into the address bar without adding history entries. */
export function writeUrlState( state: UrlState ): void {
  if ( typeof window === 'undefined' ) return;
  const params = buildParams( state ).toString();
  const { origin, pathname, search } = window.location;
  const url = origin + pathname + search + ( params ? `#${ params }` : '' );
  window.history.replaceState( null, '', url );
}

/** Absolute URL that reproduces the given state when opened — for "copy link". */
export function buildShareUrl( state: UrlState ): string {
  if ( typeof window === 'undefined' ) return '';
  const params = buildParams( state ).toString();
  const { origin, pathname, search } = window.location;
  return origin + pathname + search + ( params ? `#${ params }` : '' );
}

export { EMPTY_FILTERS };
