import { useState } from 'react';
import { SlidersHorizontal, Share2, Check } from 'lucide-react';
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
