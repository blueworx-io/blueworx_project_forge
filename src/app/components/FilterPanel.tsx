import { SlidersHorizontal, X } from 'lucide-react';
import { AppSettings } from '../types';
import { useDataStore } from '../store/useDataStore';
import { useUIStore } from '../store/useUIStore';
import { hasActiveFilters } from '../utils/filters';

interface FilterPanelProps {
  settings: AppSettings;
}

const labelStyle: React.CSSProperties = {
  display: 'block', fontSize: 12, fontWeight: 600, color: '#64748b', marginBottom: 6,
};

const selectStyle: React.CSSProperties = {
  appearance: 'none',
  width: '100%',
  padding: '9px 30px 9px 12px',
  borderRadius: 8,
  fontSize: 13,
  fontWeight: 500,
  border: '1px solid #e2e8f0',
  backgroundColor: '#ffffff',
  color: '#1a1f36',
  cursor: 'pointer',
  backgroundImage: 'url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%2364748b\' stroke-width=\'2\'><polyline points=\'6 9 12 15 18 9\'/></svg>")',
  backgroundRepeat: 'no-repeat',
  backgroundPosition: 'right 10px center',
};

function Field( { label, value, onChange, options }: {
  label: string;
  value: string;
  onChange: ( v: string ) => void;
  options: { value: string; label: string }[];
} ) {
  const active = value !== 'all';
  return (
    <div>
      <label style={ labelStyle }>{ label }</label>
      <select
        aria-label={ label }
        value={ value }
        onChange={ e => onChange( e.target.value ) }
        style={{
          ...selectStyle,
          borderColor: active ? '#2563eb' : '#e2e8f0',
          color:       active ? '#2563eb' : '#1a1f36',
          backgroundColor: active ? '#eff6ff' : '#ffffff',
        }}
      >
        <option value="all">All</option>
        { options.map( o => <option key={ o.value } value={ o.value }>{ o.label }</option> ) }
      </select>
    </div>
  );
}

export function FilterPanel( { settings }: FilterPanelProps ) {
  const releases     = useDataStore( s => s.releases );
  const filters      = useUIStore( s => s.filters );
  const setFilter    = useUIStore( s => s.setFilter );
  const resetFilters = useUIStore( s => s.resetFilters );
  const isOpen       = useUIStore( s => s.filterPanelOpen );
  const setOpen      = useUIStore( s => s.setFilterPanelOpen );

  const active = hasActiveFilters( filters );

  const releaseOptions  = [ ...releases ]
    .sort( ( a, b ) => a.name.localeCompare( b.name ) )
    .map( r => ( { value: r.id, label: r.name } ) );
  const stageOptions    = settings.statuses.map( s => ( { value: s.id, label: s.label } ) );
  const categoryOptions = [ ...settings.categories ]
    .sort( ( a, b ) => a.localeCompare( b ) )
    .map( c => ( { value: c, label: c } ) );
  const brandOptions    = settings.brands.map( b => ( { value: b.name, label: b.name } ) );

  return (
    <>
      {/* Dimmed overlay — click to dismiss */}
      <div
        onClick={ () => setOpen( false ) }
        aria-hidden={ ! isOpen }
        style={{
          position: 'fixed', inset: 0, zIndex: 60,
          backgroundColor: 'rgba(15,23,42,0.4)',
          opacity: isOpen ? 1 : 0,
          pointerEvents: isOpen ? 'auto' : 'none',
          transition: 'opacity 0.25s ease',
        }}
      />

      {/* Right-side panel — slides in on desktop and mobile */}
      <aside
        role="dialog"
        aria-label="Filters"
        style={{
          position: 'fixed', top: 0, right: 0, bottom: 0, zIndex: 61,
          width: 'min(360px, 100%)',
          backgroundColor: '#ffffff',
          boxShadow: '-8px 0 24px rgba(15,23,42,0.12)',
          transform: isOpen ? 'translateX(0)' : 'translateX(100%)',
          transition: 'transform 0.25s ease',
          display: 'flex', flexDirection: 'column',
          paddingBottom: 'env(safe-area-inset-bottom)',
        }}
      >
        <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: '1px solid #e2e8f0' }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, fontSize: 15, fontWeight: 700, color: '#1a1f36' }}>
            <SlidersHorizontal size={ 17 } /> Filters
          </span>
          <button onClick={ () => setOpen( false ) } title="Close" style={{ display: 'inline-flex', padding: 6, borderRadius: 8, border: 'none', background: 'none', color: '#64748b', cursor: 'pointer' }}>
            <X size={ 18 } />
          </button>
        </header>

        <div style={{ flex: 1, overflowY: 'auto', padding: 20, display: 'flex', flexDirection: 'column', gap: 16 }}>
          <Field label="Release"      value={ filters.release }      onChange={ v => setFilter( 'release', v ) }      options={ releaseOptions } />
          <Field label="Status"       value={ filters.stage }        onChange={ v => setFilter( 'stage', v ) }        options={ stageOptions } />
          <Field label="Category"     value={ filters.category }     onChange={ v => setFilter( 'category', v ) }     options={ categoryOptions } />
          <Field label="Brand"        value={ filters.brand }        onChange={ v => setFilter( 'brand', v ) }        options={ brandOptions } />
          <Field label="Stat Tracking" value={ filters.statTracking } onChange={ v => setFilter( 'statTracking', v ) } options={ [
            { value: 'tracked',     label: 'Tracked' },
            { value: 'not-tracked', label: 'Not Tracked' },
          ] } />
        </div>

        <footer style={{ padding: '14px 20px', borderTop: '1px solid #e2e8f0' }}>
          <button
            onClick={ resetFilters }
            disabled={ ! active }
            style={{
              width: '100%', padding: '9px 14px', borderRadius: 8, fontSize: 13, fontWeight: 600,
              border: '1px solid #e2e8f0',
              backgroundColor: active ? '#ffffff' : '#f8fafc',
              color: active ? '#1a1f36' : '#94a3b8',
              cursor: active ? 'pointer' : 'not-allowed',
            }}
          >
            Clear all filters
          </button>
        </footer>
      </aside>
    </>
  );
}
