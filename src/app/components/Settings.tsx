import { useState, useEffect, useCallback } from 'react';
import { Briefcase, Tag, Archive as ArchiveIcon, Plus, X, RotateCcw, Check, AlertCircle, Loader2 } from 'lucide-react';
import { AppSettings, ArchivedItem } from '../types';
import { saveSettings, fetchArchived, restoreItem } from '../api/wordpress';

interface SettingsProps {
  settings: AppSettings;
  onSettingsChange: ( s: AppSettings ) => void;
  onClose: () => void;
}

type Section = 'brands' | 'categories' | 'archive';

const SECTION_NAV: { id: Section; label: string; Icon: any }[] = [
  { id: 'brands',     label: 'Brands',     Icon: Briefcase   },
  { id: 'categories', label: 'Categories', Icon: Tag         },
  { id: 'archive',    label: 'Archive',    Icon: ArchiveIcon },
];

const TYPE_BADGE: Record<string, { label: string; bg: string; color: string }> = {
  feature:      { label: 'Feature',      bg: '#eff6ff', color: '#2563eb' },
  subitem:      { label: 'Sub-item',     bg: '#f5f3ff', color: '#7c3aed' },
  bug:          { label: 'Bug',          bg: '#fff1f2', color: '#e11d48' },
  feedback:     { label: 'Feedback',     bg: '#fffbeb', color: '#d97706' },
  release:      { label: 'Release',      bg: '#f0fdf4', color: '#16a34a' },
  company_date: { label: 'Company Date', bg: '#f8fafc', color: '#64748b' },
};

function useSaveApi( onSettingsChange: ( s: AppSettings ) => void ) {
  return useCallback( async ( next: AppSettings ): Promise<AppSettings> => {
    if ( ! window.forgePMData ) {
      onSettingsChange( next );
      return next;
    }
    const saved = await saveSettings( next );
    onSettingsChange( saved );
    return saved;
  }, [ onSettingsChange ] );
}

function useFeedback() {
  const [ state, setState ] = useState<'idle' | 'saving' | 'saved' | 'error'>( 'idle' );
  return {
    state,
    start: () => setState( 'saving' ),
    ok:    () => { setState( 'saved' ); setTimeout( () => setState( 'idle' ), 2000 ); },
    fail:  () => { setState( 'error' ); setTimeout( () => setState( 'idle' ), 3000 ); },
  };
}

// ── Shared feedback badge ────────────────────────────────────────────────────
function SaveFeedback( { state }: { state: ReturnType<typeof useFeedback>['state'] } ) {
  if ( state === 'saved' ) return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, color: '#16a34a' }}>
      <Check size={ 12 } /> Saved
    </span>
  );
  if ( state === 'error' ) return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, color: '#ef4444' }}>
      <AlertCircle size={ 12 } /> Failed
    </span>
  );
  return null;
}

// ── Card wrapper ─────────────────────────────────────────────────────────────
function Card( { children, style }: { children: React.ReactNode; style?: React.CSSProperties } ) {
  return (
    <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: 20, ...style }}>
      { children }
    </div>
  );
}

// ── Text input + Add button row ──────────────────────────────────────────────
function AddRow( { placeholder, value, onChange, onAdd, busy }: {
  placeholder: string; value: string;
  onChange: ( v: string ) => void;
  onAdd: () => void;
  busy: boolean;
} ) {
  return (
    <div style={{ display: 'flex', gap: 8 }}>
      <input
        type="text"
        placeholder={ placeholder }
        value={ value }
        onChange={ e => onChange( e.target.value ) }
        onKeyDown={ e => e.key === 'Enter' && onAdd() }
        style={{
          flex: 1, padding: '7px 10px', borderRadius: 6,
          border: '1px solid #e2e8f0', fontSize: 14, color: '#1a1f36',
          outline: 'none', background: '#fff',
        }}
      />
      <button
        onClick={ onAdd }
        disabled={ busy || ! value.trim() }
        style={{
          display: 'inline-flex', alignItems: 'center', gap: 5,
          padding: '7px 14px', borderRadius: 6, fontSize: 13, fontWeight: 500,
          border: 'none', cursor: busy || ! value.trim() ? 'not-allowed' : 'pointer',
          background: '#2563eb', color: '#fff',
          opacity: busy || ! value.trim() ? 0.5 : 1,
          transition: 'opacity 0.15s',
        }}
      >
        { busy ? <Loader2 size={ 13 } style={{ animation: 'spin 1s linear infinite' }} /> : <Plus size={ 13 } /> }
        Add
      </button>
    </div>
  );
}

// ── Delete row item ──────────────────────────────────────────────────────────
function ListRow( { label, onDelete, badge }: { label: string; onDelete?: () => void; badge?: React.ReactNode } ) {
  return (
    <div style={{
      display: 'flex', alignItems: 'center', justifyContent: 'space-between',
      padding: '8px 12px', borderRadius: 6,
      background: onDelete ? '#fafbfc' : '#f8fafc',
      border: '1px solid #e2e8f0',
    }}>
      <span style={{ fontSize: 14, color: '#1a1f36' }}>{ label }</span>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        { badge }
        { onDelete && (
          <button
            onClick={ onDelete }
            style={{
              display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
              width: 26, height: 26, borderRadius: 5, border: 'none',
              background: 'transparent', color: '#94a3b8', cursor: 'pointer',
              transition: 'all 0.1s',
            }}
            onMouseEnter={ e => { const b = e.currentTarget; b.style.background = '#fff1f2'; b.style.color = '#ef4444'; } }
            onMouseLeave={ e => { const b = e.currentTarget; b.style.background = 'transparent'; b.style.color = '#94a3b8'; } }
          >
            <X size={ 13 } />
          </button>
        ) }
      </div>
    </div>
  );
}

// ── Brand Configuration card ─────────────────────────────────────────────────
function BrandConfig( { settings, persist }: { settings: AppSettings; persist: ( s: AppSettings ) => Promise<AppSettings> } ) {
  const [ hours,       setHours       ] = useState( settings.teamMonthlyHours );
  const [ parentBrand, setParentBrand ] = useState( settings.parentBrand );
  const fb = useFeedback();

  // Keep local state in sync when settings prop changes externally
  useEffect( () => { setHours( settings.teamMonthlyHours ); }, [ settings.teamMonthlyHours ] );
  useEffect( () => { setParentBrand( settings.parentBrand ); }, [ settings.parentBrand ] );

  async function handleSave() {
    fb.start();
    try {
      await persist( { ...settings, teamMonthlyHours: hours, parentBrand } );
      fb.ok();
    } catch { fb.fail(); }
  }

  return (
    <Card style={{ marginBottom: 16 }}>
      <h3 style={{ fontSize: 14, fontWeight: 600, color: '#1a1f36', margin: '0 0 16px' }}>Brand Configuration</h3>
      <div style={{ display: 'grid', gap: 14, marginBottom: 18 }}>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
          <span style={{ fontSize: 13, fontWeight: 500, color: '#374151' }}>Total team hours per month</span>
          <input
            type="number" min={ 1 } value={ hours }
            onChange={ e => setHours( Number( e.target.value ) ) }
            style={{
              width: 160, padding: '7px 10px', borderRadius: 6,
              border: '1px solid #e2e8f0', fontSize: 14, color: '#1a1f36',
              outline: 'none', background: '#fff',
            }}
          />
        </label>

        <label style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
          <span style={{ fontSize: 13, fontWeight: 500, color: '#374151' }}>Parent brand</span>
          <span style={{ fontSize: 12, color: '#64748b' }}>The baseline brand all others are compared against.</span>
          <select
            value={ parentBrand }
            onChange={ e => setParentBrand( e.target.value ) }
            style={{
              width: 220, padding: '7px 10px', borderRadius: 6,
              border: '1px solid #e2e8f0', fontSize: 14, color: '#1a1f36',
              background: '#fff', outline: 'none',
            }}
          >
            <option value="">— None —</option>
            { settings.brands.map( b => <option key={ b } value={ b }>{ b }</option> ) }
          </select>
        </label>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        <button
          onClick={ handleSave }
          disabled={ fb.state === 'saving' }
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 6,
            padding: '7px 16px', borderRadius: 6, fontSize: 13, fontWeight: 500,
            border: 'none', cursor: fb.state === 'saving' ? 'not-allowed' : 'pointer',
            background: '#2563eb', color: '#fff',
            opacity: fb.state === 'saving' ? 0.7 : 1,
            transition: 'opacity 0.15s',
          }}
        >
          { fb.state === 'saving' && <Loader2 size={ 13 } style={{ animation: 'spin 1s linear infinite' }} /> }
          Save configuration
        </button>
        <SaveFeedback state={ fb.state } />
      </div>
    </Card>
  );
}

// ── Manage Brands card ───────────────────────────────────────────────────────
function ManageBrands( { settings, persist }: { settings: AppSettings; persist: ( s: AppSettings ) => Promise<AppSettings> } ) {
  const [ newBrand, setNewBrand ] = useState( '' );
  const [ adding,   setAdding   ] = useState( false );
  const fb = useFeedback();

  async function handleAdd() {
    const t = newBrand.trim();
    if ( ! t || settings.brands.includes( t ) ) return;
    setAdding( true ); fb.start();
    try {
      await persist( { ...settings, brands: [ ...settings.brands, t ] } );
      setNewBrand( '' ); fb.ok();
    } catch { fb.fail(); }
    setAdding( false );
  }

  async function handleDelete( brand: string ) {
    fb.start();
    try {
      await persist( {
        ...settings,
        brands: settings.brands.filter( b => b !== brand ),
        parentBrand: settings.parentBrand === brand ? '' : settings.parentBrand,
      } );
      fb.ok();
    } catch { fb.fail(); }
  }

  return (
    <Card>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
        <h3 style={{ fontSize: 14, fontWeight: 600, color: '#1a1f36', margin: 0 }}>Manage Brands</h3>
        <SaveFeedback state={ fb.state } />
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 16 }}>
        { settings.brands.map( brand => {
          const isParent = brand === settings.parentBrand;
          const parentBadge = isParent ? (
            <span style={{
              fontSize: 11, fontWeight: 600, padding: '2px 7px', borderRadius: 99,
              background: '#eff6ff', color: '#2563eb', letterSpacing: '0.02em',
            }}>
              Parent
            </span>
          ) : undefined;
          return (
            <ListRow
              key={ brand }
              label={ brand }
              badge={ parentBadge }
              onDelete={ isParent ? undefined : () => handleDelete( brand ) }
            />
          );
        } ) }
      </div>

      <AddRow
        placeholder="New brand name…"
        value={ newBrand }
        onChange={ setNewBrand }
        onAdd={ handleAdd }
        busy={ adding }
      />
    </Card>
  );
}

// ── Categories section ───────────────────────────────────────────────────────
function CategoriesSection( { settings, persist }: { settings: AppSettings; persist: ( s: AppSettings ) => Promise<AppSettings> } ) {
  const [ newCat, setNewCat ] = useState( '' );
  const [ adding, setAdding ] = useState( false );
  const fb = useFeedback();

  async function handleAdd() {
    const t = newCat.trim();
    if ( ! t || settings.categories.includes( t ) ) return;
    setAdding( true ); fb.start();
    try {
      await persist( { ...settings, categories: [ ...settings.categories, t ] } );
      setNewCat( '' ); fb.ok();
    } catch { fb.fail(); }
    setAdding( false );
  }

  async function handleDelete( cat: string ) {
    if ( ! window.confirm( `Remove category "${ cat }"?` ) ) return;
    fb.start();
    try {
      await persist( { ...settings, categories: settings.categories.filter( c => c !== cat ) } );
      fb.ok();
    } catch { fb.fail(); }
  }

  return (
    <Card>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
        <h3 style={{ fontSize: 14, fontWeight: 600, color: '#1a1f36', margin: 0 }}>Manage Categories</h3>
        <SaveFeedback state={ fb.state } />
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 16 }}>
        { settings.categories.map( cat => (
          <ListRow key={ cat } label={ cat } onDelete={ () => handleDelete( cat ) } />
        ) ) }
      </div>

      <AddRow
        placeholder="New category name…"
        value={ newCat }
        onChange={ setNewCat }
        onAdd={ handleAdd }
        busy={ adding }
      />
    </Card>
  );
}

// ── Archive section ──────────────────────────────────────────────────────────
function ArchiveSection() {
  const [ items,     setItems     ] = useState<ArchivedItem[]>( [] );
  const [ loading,   setLoading   ] = useState( true );
  const [ restoring, setRestoring ] = useState<string | null>( null );
  const [ error,     setError     ] = useState<string | null>( null );

  const load = useCallback( async () => {
    if ( ! window.forgePMData ) { setLoading( false ); return; }
    try {
      setItems( await fetchArchived() );
    } catch ( e: any ) {
      setError( e.message ?? 'Failed to load archive.' );
    } finally {
      setLoading( false );
    }
  }, [] );

  useEffect( () => { load(); }, [ load ] );

  async function handleRestore( item: ArchivedItem ) {
    setRestoring( item.id );
    try {
      await restoreItem( item.itemType, item.id );
      setItems( prev => prev.filter( i => i.id !== item.id ) );
    } catch { /* keep item in list */ }
    setRestoring( null );
  }

  if ( loading ) return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: 24, color: '#64748b', fontSize: 14 }}>
      <Loader2 size={ 16 } style={{ animation: 'spin 1s linear infinite' }} />
      Loading archived items…
    </div>
  );

  if ( error ) return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 8,
      padding: 20, borderRadius: 8, background: '#fff1f2',
      border: '1px solid #fecdd3', color: '#e11d48', fontSize: 14,
    }}>
      <AlertCircle size={ 15 } />{ error }
    </div>
  );

  if ( items.length === 0 ) return (
    <Card style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '48px 24px', gap: 10 }}>
      <ArchiveIcon size={ 32 } strokeWidth={ 1.5 } style={{ color: '#cbd5e1' }} />
      <p style={{ fontSize: 14, color: '#64748b', margin: 0 }}>No archived items</p>
      <p style={{ fontSize: 13, color: '#94a3b8', margin: 0 }}>Items you delete will appear here for recovery.</p>
    </Card>
  );

  return (
    <Card style={{ padding: 0, overflow: 'hidden' }}>
      <div style={{ padding: '14px 20px', borderBottom: '1px solid #e2e8f0' }}>
        <h3 style={{ fontSize: 14, fontWeight: 600, color: '#1a1f36', margin: 0 }}>
          Archived Items
          <span style={{
            marginLeft: 8, fontSize: 12, fontWeight: 500,
            padding: '1px 7px', borderRadius: 99,
            background: '#f1f5f9', color: '#64748b',
          }}>{ items.length }</span>
        </h3>
      </div>
      { items.map( ( item, idx ) => {
        const badge  = TYPE_BADGE[ item.itemType ] ?? TYPE_BADGE.company_date;
        const isSelf = restoring === item.id;
        return (
          <div
            key={ item.id }
            style={{
              display: 'flex', alignItems: 'center', gap: 12,
              padding: '12px 20px',
              borderBottom: idx < items.length - 1 ? '1px solid #f1f5f9' : 'none',
            }}
          >
            <span style={{
              flexShrink: 0, fontSize: 11, fontWeight: 600, padding: '2px 7px',
              borderRadius: 99, background: badge.bg, color: badge.color,
              letterSpacing: '0.02em',
            }}>{ badge.label }</span>

            <span style={{
              flex: 1, fontSize: 14, color: '#1a1f36',
              minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
            }}>{ item.name }</span>

            <span style={{ flexShrink: 0, fontSize: 12, color: '#94a3b8' }}>{ item.archivedAt }</span>

            <button
              onClick={ () => handleRestore( item ) }
              disabled={ !! restoring }
              style={{
                flexShrink: 0,
                display: 'inline-flex', alignItems: 'center', gap: 5,
                padding: '5px 11px', borderRadius: 6, fontSize: 12, fontWeight: 500,
                border: '1px solid #e2e8f0', cursor: restoring ? 'not-allowed' : 'pointer',
                background: '#ffffff', color: '#374151',
                opacity: restoring && ! isSelf ? 0.5 : 1,
                transition: 'all 0.15s',
              }}
            >
              { isSelf
                ? <Loader2 size={ 12 } style={{ animation: 'spin 1s linear infinite' }} />
                : <RotateCcw size={ 12 } /> }
              Restore
            </button>
          </div>
        );
      } ) }
    </Card>
  );
}

// ── Main Settings component ──────────────────────────────────────────────────
export function Settings( { settings, onSettingsChange }: SettingsProps ) {
  const [ activeSection, setActiveSection ] = useState<Section>( 'brands' );
  const persist = useSaveApi( onSettingsChange );

  return (
    <>
      <style>{ `@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }` }</style>

      {/* Outer: column so mobile tab strip sits above the row layout */}
      <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>

        {/* Mobile-only tab strip */}
        <div
          className="flex sm:hidden"
          style={{
            borderBottom: '1px solid #e2e8f0',
            background: '#ffffff',
            overflowX: 'auto',
            flexShrink: 0,
          }}
        >
          { SECTION_NAV.map( ( { id, label, Icon } ) => {
            const active = activeSection === id;
            return (
              <button
                key={ id }
                onClick={ () => setActiveSection( id ) }
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: 6,
                  padding: '10px 16px', fontSize: 13, fontWeight: 500,
                  border: 'none',
                  borderBottom: active ? '2px solid #2563eb' : '2px solid transparent',
                  marginBottom: -1,
                  cursor: 'pointer', whiteSpace: 'nowrap', flexShrink: 0,
                  background: 'transparent',
                  color: active ? '#2563eb' : '#64748b',
                }}
              >
                <Icon size={ 13 } />{ label }
              </button>
            );
          } ) }
        </div>

        {/* Row: sidebar + content */}
        <div style={{ display: 'flex', flex: 1, minHeight: 0 }}>

          {/* Desktop sidebar */}
          <nav
            className="hidden sm:flex"
            style={{
              flexShrink: 0, width: 200,
              borderRight: '1px solid #e2e8f0',
              background: '#ffffff',
              padding: '20px 12px',
              flexDirection: 'column', gap: 2,
              overflowY: 'auto',
            }}
          >
            <p style={{
              fontSize: 11, fontWeight: 600, color: '#94a3b8',
              letterSpacing: '0.07em', textTransform: 'uppercase',
              margin: '0 4px 10px',
            }}>
              Settings
            </p>
            { SECTION_NAV.map( ( { id, label, Icon } ) => {
              const active = activeSection === id;
              return (
                <button
                  key={ id }
                  onClick={ () => setActiveSection( id ) }
                  style={{
                    display: 'flex', alignItems: 'center', gap: 8,
                    padding: '8px 10px', borderRadius: 6,
                    fontSize: 13, fontWeight: 500,
                    border: 'none', cursor: 'pointer',
                    textAlign: 'left', width: '100%',
                    background: active ? '#eff6ff' : 'transparent',
                    color:      active ? '#2563eb' : '#374151',
                    transition: 'all 0.15s',
                  }}
                >
                  <Icon size={ 14 } />{ label }
                </button>
              );
            } ) }
          </nav>

          {/* Content pane */}
          <div style={{ flex: 1, overflowY: 'auto', padding: 24 }}>

            { activeSection === 'brands' && (
              <div style={{ maxWidth: 640 }}>
                <h2 style={{ fontSize: 18, fontWeight: 700, color: '#1a1f36', margin: '0 0 4px' }}>Brands</h2>
                <p style={{ fontSize: 13, color: '#64748b', margin: '0 0 20px' }}>
                  Configure your brand roster and set the parent brand for comparisons.
                </p>
                <BrandConfig  settings={ settings } persist={ persist } />
                <ManageBrands settings={ settings } persist={ persist } />
              </div>
            ) }

            { activeSection === 'categories' && (
              <div style={{ maxWidth: 640 }}>
                <h2 style={{ fontSize: 18, fontWeight: 700, color: '#1a1f36', margin: '0 0 4px' }}>Categories</h2>
                <p style={{ fontSize: 13, color: '#64748b', margin: '0 0 20px' }}>
                  Manage the feature categories used across your project items.
                </p>
                <CategoriesSection settings={ settings } persist={ persist } />
              </div>
            ) }

            { activeSection === 'archive' && (
              <div style={{ maxWidth: 720 }}>
                <h2 style={{ fontSize: 18, fontWeight: 700, color: '#1a1f36', margin: '0 0 4px' }}>Archive</h2>
                <p style={{ fontSize: 13, color: '#64748b', margin: '0 0 20px' }}>
                  Deleted items are stored here. Restore any item to bring it back.
                </p>
                <ArchiveSection />
              </div>
            ) }

          </div>
        </div>
      </div>
    </>
  );
}
