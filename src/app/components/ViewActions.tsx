import { useEffect, useRef, useState } from 'react';
import { SlidersHorizontal, Share2, Check, Search, X } from 'lucide-react';
import { useUIStore } from '../store/useUIStore';
import { ViewFilters } from '../utils/filters';
import { buildShareUrl } from '../utils/urlState';

// Shared toolbar button styling — matches the existing secondary buttons in each view.
const toolbarBtn: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: 6,
  padding: '7px 12px', fontSize: 13, fontWeight: 500,
  backgroundColor: '#ffffff', color: '#1a1f36',
  border: '1px solid #e2e8f0', borderRadius: 8, cursor: 'pointer',
  flexShrink: 0,
};

function activeCount( f: ViewFilters ): number {
  return [ f.release, f.stage, f.category, f.brand ].filter( v => v !== 'all' ).length;
}

/**
 * Toolbar free-text search. Writes to the shared `filters.search` so it narrows
 * every view (Kanban / Gantt / Calendar) and is captured in shareable links.
 * Keeps a local input value and pushes to the store on a short debounce so typing
 * stays smooth.
 */
export function SearchBox( { fullWidth = false }: { fullWidth?: boolean } = {} ) {
  const query     = useUIStore( s => s.filters.search );
  const setFilter = useUIStore( s => s.setFilter );

  const [value, setValue] = useState( query );

  // Reflect external changes (deep link, Clear all filters) into the input.
  useEffect( () => { setValue( query ); }, [ query ] );

  // Debounce store writes so each keystroke doesn't re-filter the whole list.
  const timer = useRef<ReturnType<typeof setTimeout>>();
  useEffect( () => {
    if ( value === query ) return;
    timer.current = setTimeout( () => setFilter( 'search', value ), 150 );
    return () => clearTimeout( timer.current );
  }, [ value, query, setFilter ] );

  const active = value.trim() !== '';

  return (
    <div style={{ position: 'relative', flexShrink: fullWidth ? 1 : 0, flex: fullWidth ? '1 1 auto' : undefined, display: 'flex', alignItems: 'center' }}>
      <Search
        size={ 15 }
        style={{ position: 'absolute', left: 10, color: active ? '#2563eb' : '#94a3b8', pointerEvents: 'none' }}
      />
      <input
        type="text"
        value={ value }
        onChange={ e => setValue( e.target.value ) }
        placeholder="Search…"
        aria-label="Search items"
        style={{
          width: fullWidth ? '100%' : 180,
          maxWidth: fullWidth ? undefined : '40vw',
          padding: '7px 28px 7px 32px',
          fontSize: 13,
          borderRadius: 8,
          border: `1px solid ${ active ? '#bfdbfe' : '#e2e8f0' }`,
          backgroundColor: active ? '#eff6ff' : '#ffffff',
          color: '#1a1f36',
          outline: 'none',
        }}
      />
      { active && (
        <button
          onClick={ () => { setValue( '' ); setFilter( 'search', '' ); } }
          title="Clear search"
          aria-label="Clear search"
          style={{
            position: 'absolute', right: 6,
            display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
            width: 18, height: 18, padding: 0, borderRadius: 99,
            border: 'none', background: 'none', color: '#64748b', cursor: 'pointer',
          }}
        >
          <X size={ 14 } />
        </button>
      ) }
    </div>
  );
}

/** Toggles the filter side panel; shows a badge with the number of active filters. */
export function FilterButton() {
  const filters = useUIStore( s => s.filters );
  const toggle  = useUIStore( s => s.toggleFilterPanel );
  const isOpen  = useUIStore( s => s.filterPanelOpen );
  const count   = activeCount( filters );
  const highlight = isOpen || count > 0;

  return (
    <button
      onClick={ toggle }
      title="Filters"
      aria-pressed={ isOpen }
      style={{
        ...toolbarBtn,
        backgroundColor: highlight ? '#eff6ff' : '#ffffff',
        borderColor:     highlight ? '#bfdbfe' : '#e2e8f0',
        color:           highlight ? '#2563eb' : '#1a1f36',
      }}
    >
      <SlidersHorizontal size={ 15 } />
      <span className="hidden sm:inline">Filters</span>
      { count > 0 && (
        <span style={{
          minWidth: 16, height: 16, padding: '0 4px', borderRadius: 99,
          backgroundColor: '#2563eb', color: '#fff', fontSize: 10, fontWeight: 700,
          display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
        }}>{ count }</span>
      ) }
    </button>
  );
}

/** Copies a shareable link to the current view + active filters. */
export function ShareButton() {
  const currentView = useUIStore( s => s.currentView );
  const filters     = useUIStore( s => s.filters );
  const [copied, setCopied] = useState( false );

  const handleShare = async () => {
    const url = buildShareUrl( { view: currentView, filters } );
    try {
      await navigator.clipboard.writeText( url );
      setCopied( true );
      setTimeout( () => setCopied( false ), 2000 );
    } catch {
      window.prompt( 'Copy this link:', url );
    }
  };

  return (
    <button
      onClick={ handleShare }
      title="Copy a link to this view and its filters"
      style={{
        ...toolbarBtn,
        backgroundColor: copied ? '#ecfdf5' : '#ffffff',
        borderColor:     copied ? '#a7f3d0' : '#e2e8f0',
        color:           copied ? '#059669' : '#1a1f36',
      }}
    >
      { copied ? <Check size={ 15 } /> : <Share2 size={ 15 } /> }
      <span className="hidden sm:inline">{ copied ? 'Copied' : 'Share' }</span>
    </button>
  );
}
