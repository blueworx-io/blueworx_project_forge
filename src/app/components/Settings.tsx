import React, { useState, useEffect, useCallback } from 'react';
import {
  Briefcase, Tag, Archive as ArchiveIcon, Plus, X, RotateCcw,
  Check, AlertCircle, Loader2, ListOrdered, PackageOpen, Download,
  Pencil, ChevronUp, ChevronDown, Settings as SettingsIcon, Image as ImageIcon,
} from 'lucide-react';
import { AppSettings, ArchivedItem, WorkflowStatus, BrandConfig, Release } from '../types';
import { useDataStore } from '../store/useDataStore';
import { useIsMobile } from '../hooks/useIsMobile';
import { useDragScroll } from '../hooks/useDragScroll';
import { BOTTOM_BAR_HEIGHT, TOP_BAR_HEIGHT } from './MobileNav';
import {
  dateToIsoWeek, isoWeekToReleaseDate, autoQuarter, autoCapacity,
  composeReleaseName, formatDate,
} from '../utils/dates';
import {
  saveSettings, fetchArchived, restoreItem,
  createItem, archiveItem, updateItem, isAdmin,
} from '../api/wordpress';

interface SettingsProps {
  settings: AppSettings;
  onSettingsChange: ( s: AppSettings ) => void;
  onClose: () => void;
}

type Section = 'config' | 'statuses' | 'brands' | 'categories' | 'releases' | 'archive' | 'export';

// Config first · alphabetical middle · Archive/Export always last
const SECTION_NAV: { id: Section; label: string; Icon: React.ComponentType<{ size?: number }> }[] = [
  { id: 'config',      label: 'Config',      Icon: SettingsIcon },
  { id: 'brands',      label: 'Brands',      Icon: Briefcase    },
  { id: 'categories',  label: 'Categories',  Icon: Tag          },
  { id: 'releases',    label: 'Releases',    Icon: PackageOpen  },
  { id: 'statuses',    label: 'Statuses',    Icon: ListOrdered  },
  { id: 'archive',     label: 'Archive',     Icon: ArchiveIcon  },
  { id: 'export',      label: 'Export',      Icon: Download     },
];

const TYPE_BADGE: Record<string, { label: string; bg: string; color: string }> = {
  feature:      { label: 'Feature',      bg: '#eff6ff', color: '#2563eb' },
  subitem:      { label: 'Sub-item',     bg: '#f5f3ff', color: '#7c3aed' },
  bug:          { label: 'Bug',          bg: '#fff1f2', color: '#e11d48' },
  feedback:     { label: 'Feedback',     bg: '#fffbeb', color: '#d97706' },
  release:      { label: 'Release',      bg: '#f0fdf4', color: '#16a34a' },
  company_date: { label: 'Company Date', bg: '#f8fafc', color: '#64748b' },
};

function slugify( label: string ): string {
  return label.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' );
}

// ── Save API helper ──────────────────────────────────────────────────────────
function useSaveApi( onSettingsChange: ( s: AppSettings ) => void ) {
  return useCallback( async ( next: AppSettings ): Promise<AppSettings> => {
    if ( ! window.forgePMData ) { onSettingsChange( next ); return next; }
    const saved = await saveSettings( next );
    onSettingsChange( saved );
    return saved;
  }, [ onSettingsChange ] );
}

// ── Inline feedback ──────────────────────────────────────────────────────────
function useFeedback() {
  const [ state, setState ] = useState<'idle' | 'saving' | 'saved' | 'error'>( 'idle' );
  return {
    state,
    start: () => setState( 'saving' ),
    ok:    () => { setState( 'saved' ); setTimeout( () => setState( 'idle' ), 2000 ); },
    fail:  () => { setState( 'error' ); setTimeout( () => setState( 'idle' ), 3000 ); },
  };
}

function SaveFeedback( { state }: { state: ReturnType<typeof useFeedback>['state'] } ) {
  if ( state === 'saved' ) return <span style={{ display:'inline-flex',alignItems:'center',gap:4,fontSize:12,color:'#16a34a' }}><Check size={12} /> Saved</span>;
  if ( state === 'error' ) return <span style={{ display:'inline-flex',alignItems:'center',gap:4,fontSize:12,color:'#ef4444' }}><AlertCircle size={12} /> Failed</span>;
  return null;
}

function Card( { children, style }: { children: React.ReactNode; style?: React.CSSProperties } ) {
  return <div style={{ background:'#fff',border:'1px solid #e2e8f0',borderRadius:8,padding:20,...style }}>{ children }</div>;
}

const inputStyle: React.CSSProperties = {
  width:'100%', padding:'7px 10px', borderRadius:6,
  border:'1px solid #e2e8f0', fontSize:14, color:'#1a1f36',
  outline:'none', background:'#fff', boxSizing:'border-box',
};

// ── Config section ───────────────────────────────────────────────────────────
function ConfigSection( { settings, persist }: { settings: AppSettings; persist: ( s: AppSettings ) => Promise<AppSettings> } ) {
  const [ projectName, setProjectName ] = useState( settings.projectName ?? '' );
  const [ hours,       setHours       ] = useState( settings.teamMonthlyHours );
  const [ releaseDay,  setReleaseDay  ] = useState( settings.releaseDay ?? 1 );
  const isMobile = useIsMobile();
  const fb = useFeedback();
  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- deliberate: these are editable fields seeded from saved settings, so they re-seed when a save lands or another admin's change arrives.
    setProjectName( settings.projectName ?? '' );
    setHours( settings.teamMonthlyHours );
    setReleaseDay( settings.releaseDay ?? 1 );
  }, [ settings.projectName, settings.teamMonthlyHours, settings.releaseDay ] );

  async function handleSave() {
    fb.start();
    try { await persist( { ...settings, projectName, teamMonthlyHours: hours, releaseDay } ); fb.ok(); }
    catch { fb.fail(); }
  }

  return (
    <Card>
      <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:'0 0 16px' }}>Configuration</h3>

      <label style={{ display:'flex', flexDirection:'column', gap:5, marginBottom:18 }}>
        <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Project name</span>
        <span style={{ fontSize:12, color:'#64748b' }}>Shown in the app header. Leave blank to use the default.</span>
        <input
          type="text" value={projectName} placeholder="Forge Project Management"
          onChange={ e => setProjectName( e.target.value ) }
          style={{ ...inputStyle }}
        />
      </label>

      <label style={{ display:'flex', flexDirection:'column', gap:5, marginBottom:18 }}>
        <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Total team hours per month</span>
        <span style={{ fontSize:12, color:'#64748b' }}>Used to auto-calculate release capacity from date range.</span>
        <input
          type="number" min={1} value={hours}
          onChange={ e => setHours( Number(e.target.value) ) }
          style={{ ...inputStyle, width: isMobile ? '100%' : 160 }}
        />
      </label>

      <label style={{ display:'flex', flexDirection:'column', gap:5, marginBottom:18 }}>
        <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Release day</span>
        <span style={{ fontSize:12, color:'#64748b' }}>The day each release week starts and ends on. Week selections run from this day to this day (e.g. Tuesday → Tuesday).</span>
        <select value={releaseDay} onChange={ e => setReleaseDay( Number(e.target.value) ) }
          style={{ ...inputStyle, width: isMobile ? '100%' : 200 }}>
          { DAY_NAMES.map( ( label, idx ) => <option key={idx} value={idx}>{ label }</option> ) }
        </select>
      </label>

      <div style={{ display:'flex', alignItems:'center', gap:10 }}>
        <button onClick={handleSave} disabled={fb.state==='saving'}
          style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'7px 16px', borderRadius:6, fontSize:13, fontWeight:500, border:'none', cursor: fb.state==='saving'?'not-allowed':'pointer', background:'#2563eb', color:'#fff', opacity: fb.state==='saving'?0.7:1 }}>
          { fb.state==='saving' && <Loader2 size={13} style={{ animation:'spin 1s linear infinite' }} /> }
          Save
        </button>
        <SaveFeedback state={fb.state} />
      </div>

      <div style={{ marginTop:20, paddingTop:18, borderTop:'1px solid #e2e8f0' }}>
        <span style={{ fontSize:13, fontWeight:500, color:'#374151', display:'block', marginBottom:4 }}>Refresh app</span>
        <span style={{ fontSize:12, color:'#64748b', display:'block', marginBottom:10 }}>Reload to pull the latest version of the plugin.</span>
        <button onClick={ () => window.location.reload() }
          style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'7px 16px', borderRadius:6, fontSize:13, fontWeight:500, border:'1px solid #cbd5e1', cursor:'pointer', background:'#fff', color:'#374151' }}>
          <RotateCcw size={13} />
          Refresh App
        </button>
      </div>
    </Card>
  );
}

// ── Statuses section ─────────────────────────────────────────────────────────
function StatusesSection( { settings, persist }: { settings: AppSettings; persist: ( s: AppSettings ) => Promise<AppSettings> } ) {
  const [ statuses, setStatuses ] = useState<WorkflowStatus[]>( settings.statuses );
  const [ newLabel, setNewLabel ] = useState( '' );
  const [ editingId, setEditingId ] = useState<string | null>( null );
  const [ editLabel, setEditLabel ] = useState( '' );
  const [ adding, setAdding ] = useState( false );
  const fb = useFeedback();

  // eslint-disable-next-line react-hooks/set-state-in-effect -- deliberate: editable list re-seeded from saved settings, as above.
  useEffect( () => { setStatuses( settings.statuses ); }, [ settings.statuses ] );

  async function save( next: WorkflowStatus[] ) {
    fb.start();
    try {
      await persist( { ...settings, statuses: next } );
      setStatuses( next );
      fb.ok();
    } catch { fb.fail(); }
  }

  async function handleAdd() {
    const label = newLabel.trim();
    if ( ! label ) return;
    const id = slugify( label );
    if ( statuses.find( s => s.id === id ) ) return;
    setAdding( true );
    await save( [ ...statuses, { id, label } ] );
    setNewLabel( '' );
    setAdding( false );
  }

  async function handleDelete( id: string ) {
    if ( ! window.confirm( `Remove status "${ statuses.find( s => s.id === id )?.label }"?` ) ) return;
    await save( statuses.filter( s => s.id !== id ) );
  }

  async function handleEditSave( id: string ) {
    const label = editLabel.trim();
    if ( ! label ) { setEditingId( null ); return; }
    await save( statuses.map( s => s.id === id ? { ...s, label } : s ) );
    setEditingId( null );
  }

  function move( idx: number, dir: -1 | 1 ) {
    const next = [ ...statuses ];
    const swap = idx + dir;
    if ( swap < 0 || swap >= next.length ) return;
    [ next[idx], next[swap] ] = [ next[swap], next[idx] ];
    save( next );
  }

  return (
    <Card>
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:14 }}>
        <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:0 }}>Workflow Statuses</h3>
        <SaveFeedback state={ fb.state } />
      </div>

      <div style={{ display:'flex', flexDirection:'column', gap:6, marginBottom:16 }}>
        { statuses.map( ( s, idx ) => (
          <div key={ s.id } style={{ display:'flex', alignItems:'center', gap:8, padding:'8px 10px', borderRadius:6, background:'#fafbfc', border:'1px solid #e2e8f0' }}>
            {/* Order arrows */}
            <div style={{ display:'flex', flexDirection:'column', gap:1 }}>
              <button onClick={ () => move( idx, -1 ) } disabled={ idx === 0 }
                style={{ background:'none', border:'none', cursor: idx===0?'default':'pointer', color:'#94a3b8', padding:1, display:'flex', opacity: idx===0?0.3:1 }}>
                <ChevronUp size={11} />
              </button>
              <button onClick={ () => move( idx, 1 ) } disabled={ idx === statuses.length - 1 }
                style={{ background:'none', border:'none', cursor: idx===statuses.length-1?'default':'pointer', color:'#94a3b8', padding:1, display:'flex', opacity: idx===statuses.length-1?0.3:1 }}>
                <ChevronDown size={11} />
              </button>
            </div>

            {/* Label */}
            { editingId === s.id ? (
              <input
                autoFocus
                value={ editLabel }
                onChange={ e => setEditLabel( e.target.value ) }
                onBlur={ () => handleEditSave( s.id ) }
                onKeyDown={ e => { if ( e.key === 'Enter' ) handleEditSave( s.id ); if ( e.key === 'Escape' ) setEditingId( null ); } }
                style={{ ...inputStyle, flex:1, padding:'4px 8px', fontSize:13 }}
              />
            ) : (
              <span style={{ flex:1, fontSize:14, color:'#1a1f36' }}>{ s.label }</span>
            ) }

            <span style={{ fontSize:10, color:'#94a3b8', fontFamily:'monospace', flexShrink:0 }}>{ s.id }</span>

            <button onClick={ () => { setEditingId( s.id ); setEditLabel( s.label ); } }
              style={{ background:'none', border:'none', cursor:'pointer', color:'#94a3b8', display:'flex', padding:2 }}>
              <Pencil size={12} />
            </button>
            <button onClick={ () => handleDelete( s.id ) }
              style={{ background:'none', border:'none', cursor:'pointer', color:'#94a3b8', display:'flex', padding:2 }}
              onMouseEnter={ e => (e.currentTarget as HTMLButtonElement).style.color='#ef4444' }
              onMouseLeave={ e => (e.currentTarget as HTMLButtonElement).style.color='#94a3b8' }>
              <X size={13} />
            </button>
          </div>
        ) ) }
      </div>

      <div style={{ display:'flex', gap:8 }}>
        <input
          type="text" placeholder="New status label…"
          value={ newLabel } onChange={ e => setNewLabel( e.target.value ) }
          onKeyDown={ e => e.key === 'Enter' && handleAdd() }
          style={{ flex:1, ...inputStyle }}
        />
        <button
          onClick={ handleAdd } disabled={ adding || ! newLabel.trim() }
          style={{ display:'inline-flex', alignItems:'center', gap:5, padding:'7px 14px', borderRadius:6, fontSize:13, fontWeight:500, border:'none', cursor: adding||!newLabel.trim()?'not-allowed':'pointer', background:'#2563eb', color:'#fff', opacity: adding||!newLabel.trim()?0.5:1 }}>
          { adding ? <Loader2 size={13} style={{ animation:'spin 1s linear infinite' }} /> : <Plus size={13} /> }
          Add
        </button>
      </div>
    </Card>
  );
}

// ── Parent Brand selector card ───────────────────────────────────────────────
function BrandParentConfig( { settings, persist }: { settings: AppSettings; persist: ( s: AppSettings ) => Promise<AppSettings> } ) {
  const [ parentBrand, setParentBrand ] = useState( settings.parentBrand );
  const isMobile = useIsMobile();
  const fb = useFeedback();
  // eslint-disable-next-line react-hooks/set-state-in-effect -- deliberate: editable field re-seeded from saved settings, as above.
  useEffect( () => { setParentBrand( settings.parentBrand ); }, [ settings.parentBrand ] );

  async function handleSave() {
    fb.start();
    try { await persist( { ...settings, parentBrand } ); fb.ok(); }
    catch { fb.fail(); }
  }

  return (
    <Card style={{ marginBottom:16 }}>
      <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:'0 0 14px' }}>Parent Brand</h3>
      <label style={{ display:'flex', flexDirection:'column', gap:5, marginBottom:18 }}>
        <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Comparison baseline</span>
        <span style={{ fontSize:12, color:'#64748b' }}>The brand all others are measured against.</span>
        <select value={parentBrand} onChange={ e => setParentBrand(e.target.value) }
          style={{ ...inputStyle, width: isMobile ? '100%' : 220 }}>
          <option value="">— None —</option>
          { settings.brands.map( b => <option key={b.name} value={b.name}>{b.name}</option> ) }
        </select>
      </label>
      <div style={{ display:'flex', alignItems:'center', gap:10 }}>
        <button onClick={handleSave} disabled={fb.state==='saving'}
          style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'7px 16px', borderRadius:6, fontSize:13, fontWeight:500, border:'none', cursor: fb.state==='saving'?'not-allowed':'pointer', background:'#2563eb', color:'#fff', opacity: fb.state==='saving'?0.7:1 }}>
          { fb.state==='saving' && <Loader2 size={13} style={{ animation:'spin 1s linear infinite' }} /> }
          Save
        </button>
        <SaveFeedback state={fb.state} />
      </div>
    </Card>
  );
}

// ── Manage Brands card (with logo) ───────────────────────────────────────────
function ManageBrands( { settings, persist }: { settings: AppSettings; persist: ( s: AppSettings ) => Promise<AppSettings> } ) {
  const [ newName,    setNewName   ] = useState( '' );
  const [ newLogo,    setNewLogo   ] = useState( '' );
  const [ adding,     setAdding    ] = useState( false );
  const [ editingIdx, setEditingIdx ] = useState<number | null>( null );
  const [ editName,   setEditName  ] = useState( '' );
  const [ editLogo,   setEditLogo  ] = useState( '' );
  const fb = useFeedback();

  async function saveBrands( next: BrandConfig[], newParent?: string ) {
    fb.start();
    try {
      await persist( { ...settings, brands: next, parentBrand: newParent ?? settings.parentBrand } );
      fb.ok();
    } catch { fb.fail(); }
  }

  async function handleAdd() {
    const t = newName.trim();
    if ( !t || settings.brands.find( b => b.name === t ) ) return;
    setAdding( true );
    await saveBrands( [ ...settings.brands, { name: t, logo: newLogo.trim() } ] );
    setNewName( '' ); setNewLogo( '' ); setAdding( false );
  }

  async function handleDelete( idx: number ) {
    const brand = settings.brands[idx];
    const next = settings.brands.filter( ( _, i ) => i !== idx );
    await saveBrands( next, settings.parentBrand === brand.name ? '' : settings.parentBrand );
  }

  async function handleEditSave( idx: number ) {
    const next = settings.brands.map( ( b, i ) =>
      i === idx ? { name: editName.trim() || b.name, logo: editLogo.trim() } : b
    );
    await saveBrands( next );
    setEditingIdx( null );
  }

  return (
    <Card>
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:14 }}>
        <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:0 }}>Manage Brands</h3>
        <SaveFeedback state={fb.state} />
      </div>

      <div style={{ display:'flex', flexDirection:'column', gap:8, marginBottom:16 }}>
        { settings.brands.map( ( brand, idx ) => {
          const isParent  = brand.name === settings.parentBrand;
          const isEditing = editingIdx === idx;
          return (
            <div key={idx} style={{ borderRadius:8, border:`1px solid ${ isParent ? '#bfdbfe' : '#e2e8f0' }`, overflow:'hidden' }}>
              { !isEditing && (
                <div style={{ display:'flex', alignItems:'center', gap:10, padding:'10px 12px', background: isParent?'#f0f7ff':'#fafbfc' }}>
                  <div style={{ width:32, height:32, borderRadius:6, border:'1px solid #e2e8f0', background:'#fff', overflow:'hidden', flexShrink:0, display:'flex', alignItems:'center', justifyContent:'center' }}>
                    { brand.logo
                      ? <img src={brand.logo} alt={brand.name} style={{ width:'100%', height:'100%', objectFit:'contain' }} onError={ e => { (e.currentTarget as HTMLImageElement).style.display='none'; } } />
                      : <ImageIcon size={14} style={{ color:'#cbd5e1' }} /> }
                  </div>
                  <span style={{ flex:1, fontSize:14, fontWeight:500, color:'#1a1f36' }}>{ brand.name }</span>
                  { isParent && <span style={{ fontSize:11, fontWeight:600, padding:'2px 7px', borderRadius:99, background:'#eff6ff', color:'#2563eb' }}>Parent</span> }
                  <button onClick={ () => { setEditingIdx(idx); setEditName(brand.name); setEditLogo(brand.logo||''); } }
                    style={{ background:'none', border:'none', cursor:'pointer', color:'#94a3b8', display:'flex', padding:3 }}>
                    <Pencil size={13} />
                  </button>
                  <button onClick={ () => handleDelete(idx) }
                    style={{ background:'none', border:'none', cursor:'pointer', color:'#94a3b8', display:'flex', padding:3 }}
                    onMouseEnter={ e => (e.currentTarget as HTMLButtonElement).style.color='#ef4444' }
                    onMouseLeave={ e => (e.currentTarget as HTMLButtonElement).style.color='#94a3b8' }>
                    <X size={13} />
                  </button>
                </div>
              ) }
              { isEditing && (
                <div style={{ padding:'12px', background:'#fffbeb', display:'flex', flexDirection:'column', gap:8 }}>
                  <div style={{ display:'flex', gap:8 }}>
                    <div style={{ flex:1 }}>
                      <div style={{ fontSize:11, fontWeight:500, color:'#64748b', marginBottom:3 }}>Brand Name</div>
                      <input value={editName} onChange={e=>setEditName(e.target.value)} style={{ ...inputStyle, fontSize:13 }} />
                    </div>
                    <div style={{ flex:2 }}>
                      <div style={{ fontSize:11, fontWeight:500, color:'#64748b', marginBottom:3 }}>Logo URL</div>
                      <input value={editLogo} onChange={e=>setEditLogo(e.target.value)} placeholder="https://..." style={{ ...inputStyle, fontSize:13 }} />
                    </div>
                  </div>
                  { editLogo && (
                    <div style={{ display:'flex', alignItems:'center', gap:6 }}>
                      <div style={{ width:36, height:36, borderRadius:6, border:'1px solid #e2e8f0', background:'#fff', overflow:'hidden', display:'flex', alignItems:'center', justifyContent:'center' }}>
                        <img src={editLogo} alt="preview" style={{ width:'100%', height:'100%', objectFit:'contain' }} onError={ e => { (e.currentTarget as HTMLImageElement).style.display='none'; } } />
                      </div>
                      <span style={{ fontSize:12, color:'#64748b' }}>Preview</span>
                    </div>
                  ) }
                  <div style={{ display:'flex', gap:8 }}>
                    <button onClick={ () => handleEditSave(idx) }
                      style={{ display:'inline-flex', alignItems:'center', gap:5, padding:'6px 12px', borderRadius:6, fontSize:12, fontWeight:500, border:'none', background:'#2563eb', color:'#fff', cursor:'pointer' }}>
                      <Check size={12} /> Save
                    </button>
                    <button onClick={ () => setEditingIdx(null) }
                      style={{ padding:'6px 12px', borderRadius:6, fontSize:12, fontWeight:500, border:'1px solid #e2e8f0', background:'#fff', color:'#64748b', cursor:'pointer' }}>
                      Cancel
                    </button>
                  </div>
                </div>
              ) }
            </div>
          );
        } ) }
      </div>

      <div style={{ padding:12, borderRadius:8, background:'#f8fafc', border:'1px dashed #e2e8f0' }}>
        <p style={{ fontSize:12, fontWeight:500, color:'#64748b', margin:'0 0 8px' }}>Add new brand</p>
        <div style={{ display:'flex', gap:8 }}>
          <input type="text" placeholder="Brand name *" value={newName} onChange={e=>setNewName(e.target.value)} onKeyDown={e=>e.key==='Enter'&&handleAdd()} style={{ flex:1, ...inputStyle, fontSize:13 }} />
          <input type="text" placeholder="Logo URL (optional)" value={newLogo} onChange={e=>setNewLogo(e.target.value)} style={{ flex:2, ...inputStyle, fontSize:13 }} />
          <button onClick={handleAdd} disabled={adding||!newName.trim()}
            style={{ display:'inline-flex', alignItems:'center', gap:5, padding:'7px 14px', borderRadius:6, fontSize:13, fontWeight:500, border:'none', cursor:adding||!newName.trim()?'not-allowed':'pointer', background:'#2563eb', color:'#fff', opacity:adding||!newName.trim()?0.5:1, flexShrink:0 }}>
            { adding ? <Loader2 size={13} style={{ animation:'spin 1s linear infinite' }} /> : <Plus size={13} /> } Add
          </button>
        </div>
      </div>
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
    if ( !t || settings.categories.includes(t) ) return;
    setAdding(true); fb.start();
    try { await persist( { ...settings, categories: [...settings.categories, t] } ); setNewCat(''); fb.ok(); }
    catch { fb.fail(); }
    setAdding(false);
  }

  async function handleDelete( cat: string ) {
    if ( !window.confirm(`Remove category "${cat}"?`) ) return;
    fb.start();
    try { await persist( { ...settings, categories: settings.categories.filter(c=>c!==cat) } ); fb.ok(); }
    catch { fb.fail(); }
  }

  const sorted = [ ...settings.categories ].sort( ( a, b ) => a.localeCompare(b) );

  return (
    <Card>
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:14 }}>
        <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:0 }}>Manage Categories</h3>
        <SaveFeedback state={fb.state} />
      </div>
      <div style={{ display:'flex', flexDirection:'column', gap:6, marginBottom:16 }}>
        { sorted.map( cat => (
          <div key={cat} style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'8px 12px', borderRadius:6, background:'#fafbfc', border:'1px solid #e2e8f0' }}>
            <span style={{ fontSize:14, color:'#1a1f36' }}>{ cat }</span>
            <button onClick={ () => handleDelete(cat) }
              style={{ display:'inline-flex', width:26, height:26, alignItems:'center', justifyContent:'center', borderRadius:5, border:'none', background:'transparent', color:'#94a3b8', cursor:'pointer' }}
              onMouseEnter={ e => { const b=e.currentTarget as HTMLButtonElement; b.style.background='#fff1f2'; b.style.color='#ef4444'; } }
              onMouseLeave={ e => { const b=e.currentTarget as HTMLButtonElement; b.style.background='transparent'; b.style.color='#94a3b8'; } }>
              <X size={13} />
            </button>
          </div>
        ) ) }
      </div>
      <div style={{ display:'flex', gap:8 }}>
        <input type="text" placeholder="New category name…" value={newCat} onChange={e=>setNewCat(e.target.value)} onKeyDown={e=>e.key==='Enter'&&handleAdd()} style={{ flex:1, ...inputStyle }} />
        <button onClick={handleAdd} disabled={adding||!newCat.trim()}
          style={{ display:'inline-flex', alignItems:'center', gap:5, padding:'7px 14px', borderRadius:6, fontSize:13, fontWeight:500, border:'none', cursor:adding||!newCat.trim()?'not-allowed':'pointer', background:'#2563eb', color:'#fff', opacity:adding||!newCat.trim()?0.5:1 }}>
          { adding ? <Loader2 size={13} style={{ animation:'spin 1s linear infinite' }} /> : <Plus size={13} /> } Add
        </button>
      </div>
    </Card>
  );
}

const DAY_NAMES = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];

// ── Releases section ─────────────────────────────────────────────────────────
const VERSION_TYPES = [
  'Major Release', 'Minor Update', 'Patch', 'Bug Fix',
  'Beta', 'Alpha', 'Release Candidate', 'Hotfix',
];

// Each of major / minor / patch can be any value 0–99 (#49).
const VERSION_PARTS = Array.from( { length: 100 }, ( _, i ) => i );

interface ReleaseForm {
  name: string; releaseName: string; versionNumber: string; versionType: string;
  quarter: string; startWeek: string; endWeek: string;
  status: string; capacity: number; isBigWedgeCampaign: boolean;
}

const emptyReleaseForm: ReleaseForm = {
  name: '', releaseName: '', versionNumber: '1.0.0', versionType: 'Major Release',
  quarter: '', startWeek: '', endWeek: '',
  status: 'planned', capacity: 0, isBigWedgeCampaign: false,
};

function ReleaseModal( { release, teamMonthlyHours, releaseDay, onSave, onClose, isSaving }: {
  release: ReleaseForm; teamMonthlyHours: number; releaseDay: number;
  onSave: ( f: ReleaseForm ) => void; onClose: () => void; isSaving: boolean;
} ) {
  const [ form, setForm ] = useState<ReleaseForm>( release );
  const navVisible = useIsMobile(); // bottom mobile menu is shown below 900px

  function apply( patch: Partial<ReleaseForm> ) {
    setForm( f => {
      const next = { ...f, ...patch };
      return { ...next, name: composeReleaseName( next ) };
    } );
  }

  function updateVersion( num: string, type: string ) {
    apply( { versionNumber: num, versionType: type } );
  }

  // Major / minor / patch are edited independently (#49); recombine on change.
  const vParts = ( form.versionNumber || '0.0.0' ).split( '.' );
  const vMajor = vParts[0] ?? '0', vMinor = vParts[1] ?? '0', vPatch = vParts[2] ?? '0';
  function setVersionPart( idx: number, value: string ) {
    const parts = [ vMajor, vMinor, vPatch ];
    parts[idx] = value;
    updateVersion( parts.join( '.' ), form.versionType );
  }

  function updateReleaseName( rn: string ) {
    apply( { releaseName: rn } );
  }

  // Convert only the selector that changed; keep the other date verbatim so the
  // two week pickers never disturb each other (#48).
  function updateStartWeek( startIsoWeek: string ) {
    const startDate = isoWeekToReleaseDate( startIsoWeek, releaseDay );
    apply( {
      startWeek: startDate,
      quarter:   autoQuarter( startDate ) || form.quarter,
      capacity:  autoCapacity( startDate, form.endWeek, teamMonthlyHours ),
    } );
  }
  function updateEndWeek( endIsoWeek: string ) {
    const endDate = isoWeekToReleaseDate( endIsoWeek, releaseDay );
    apply( {
      endWeek:  endDate,
      capacity: autoCapacity( form.startWeek, endDate, teamMonthlyHours ),
    } );
  }

  return (
    <div style={{ position:'fixed', inset:0, zIndex:55, backgroundColor:'rgba(0,0,0,0.45)', display:'flex', alignItems:'center', justifyContent:'center', padding:16, top: navVisible ? TOP_BAR_HEIGHT : 0, bottom: navVisible ? `calc(${ BOTTOM_BAR_HEIGHT }px + env(safe-area-inset-bottom))` : 0 }}>
      <div style={{ background:'#fff', borderRadius:12, boxShadow:'0 20px 60px rgba(0,0,0,0.2)', width:'100%', maxWidth:520, maxHeight: navVisible ? '100%' : '90vh', overflowY:'auto', border:'1px solid #e2e8f0' }}>
        <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'16px 20px', borderBottom:'1px solid #e2e8f0' }}>
          <h3 style={{ fontSize:16, fontWeight:600, color:'#1a1f36', margin:0 }}>{ form.name || 'New Release' }</h3>
          <button onClick={onClose} style={{ background:'none', border:'none', cursor:'pointer', color:'#64748b', display:'flex' }}><X size={20} /></button>
        </div>
        <div style={{ padding:20, display:'flex', flexDirection:'column', gap:14 }}>

          {/* Version row */}
          <div style={{ display:'grid', gridTemplateColumns: navVisible ? '1fr' : '1fr 1fr', gap:12 }}>
            <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
              <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Version</span>
              <div style={{ display:'flex', alignItems:'center', gap:6 }}>
                <select value={vMajor} onChange={ e => setVersionPart( 0, e.target.value ) } style={{ ...inputStyle, flex:1, minWidth:0, padding:'7px 6px' }} aria-label="Major version">
                  { VERSION_PARTS.map( v => <option key={v} value={v}>{ v }</option> ) }
                </select>
                <span style={{ color:'#94a3b8', fontWeight:600 }}>.</span>
                <select value={vMinor} onChange={ e => setVersionPart( 1, e.target.value ) } style={{ ...inputStyle, flex:1, minWidth:0, padding:'7px 6px' }} aria-label="Minor version">
                  { VERSION_PARTS.map( v => <option key={v} value={v}>{ v }</option> ) }
                </select>
                <span style={{ color:'#94a3b8', fontWeight:600 }}>.</span>
                <select value={vPatch} onChange={ e => setVersionPart( 2, e.target.value ) } style={{ ...inputStyle, flex:1, minWidth:0, padding:'7px 6px' }} aria-label="Patch version">
                  { VERSION_PARTS.map( v => <option key={v} value={v}>{ v }</option> ) }
                </select>
              </div>
            </label>
            <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
              <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Version type</span>
              <select value={form.versionType} onChange={ e => updateVersion( form.versionNumber, e.target.value ) } style={inputStyle}>
                { VERSION_TYPES.map( t => <option key={t} value={t}>{t}</option> ) }
              </select>
            </label>
          </div>

          {/* Descriptive release name (feeds the auto-composed display name) */}
          <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
            <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Release name</span>
            <span style={{ fontSize:12, color:'#64748b' }}>A short descriptive name for this release.</span>
            <input type="text" value={form.releaseName}
              onChange={ e => updateReleaseName( e.target.value ) }
              style={inputStyle} placeholder="e.g. User Management System" />
          </label>

          {/* Release title — auto-composed from all fields, read-only */}
          <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
            <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Release title <span style={{ fontSize:11, color:'#94a3b8' }}>auto</span></span>
            <span style={{ fontSize:12, color:'#64748b' }}>Built from version, quarter, name, and weeks.</span>
            <input type="text" readOnly value={form.name}
              style={{ ...inputStyle, background:'#f8fafc', color:'#64748b' }} placeholder="Set version and dates to generate" />
          </label>

          {/* Week selectors */}
          <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:12 }}>
            <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
              <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Start week</span>
              <input type="week" value={ dateToIsoWeek( form.startWeek ) }
                onChange={ e => updateStartWeek( e.target.value ) } style={inputStyle} />
            </label>
            <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
              <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>End week</span>
              <input type="week" value={ dateToIsoWeek( form.endWeek ) }
                onChange={ e => updateEndWeek( e.target.value ) } style={inputStyle} />
            </label>
          </div>

          {/* Quarter + Capacity (auto, read-only display) */}
          <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:12 }}>
            <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
              <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Quarter <span style={{ fontSize:11, color:'#94a3b8' }}>auto</span></span>
              <input type="text" value={form.quarter} readOnly
                style={{ ...inputStyle, background:'#f8fafc', color:'#64748b' }} placeholder="Set dates to auto-fill" />
            </label>
            <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
              <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Capacity (hrs) <span style={{ fontSize:11, color:'#94a3b8' }}>auto</span></span>
              <input type="number" value={form.capacity} readOnly
                style={{ ...inputStyle, background:'#f8fafc', color:'#64748b' }} />
            </label>
          </div>

          {/* Status */}
          <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
            <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Status</span>
            <select value={form.status} onChange={ e => setForm(f=>({...f,status:e.target.value})) } style={{ ...inputStyle, width: navVisible ? '100%' : 200 }}>
              { ['planned','active','complete'].map( s => <option key={s} value={s}>{ s }</option> ) }
            </select>
          </label>

          <label style={{ display:'flex', alignItems:'center', gap:10, cursor:'pointer' }}>
            <input type="checkbox" checked={form.isBigWedgeCampaign}
              onChange={ e => setForm(f=>({...f,isBigWedgeCampaign:e.target.checked})) }
              style={{ width:16, height:16, accentColor:'#2563eb' }} />
            <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Big Wedge Campaign</span>
          </label>
        </div>
        <div style={{ display:'flex', justifyContent:'flex-end', gap:10, padding:'0 20px 20px' }}>
          <button onClick={onClose}
            style={{ padding:'7px 16px', borderRadius:6, fontSize:13, fontWeight:500, border:'1px solid #e2e8f0', background:'#fff', color:'#1a1f36', cursor:'pointer' }}>
            Cancel
          </button>
          <button onClick={ () => form.name.trim() && onSave(form) } disabled={isSaving||!form.name.trim()}
            style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'7px 16px', borderRadius:6, fontSize:13, fontWeight:500, border:'none', background:'#2563eb', color:'#fff', cursor: isSaving||!form.name.trim()?'not-allowed':'pointer', opacity: isSaving||!form.name.trim()?0.6:1 }}>
            { isSaving && <Loader2 size={13} style={{ animation:'spin 1s linear infinite' }} /> }
            Save Release
          </button>
        </div>
      </div>
    </div>
  );
}

function ReleasesSection( { settings }: { settings: AppSettings } ) {
  const data_releases = useDataStore( s => s.releases );
  const triggerRefresh = useDataStore( s => s.triggerRefresh );
  // Order releases soonest → latest by start date; undated releases sink to the bottom
  const sortedReleases = [ ...data_releases ].sort( ( a, b ) =>
    ( a.startWeek || '￿' ).localeCompare( b.startWeek || '￿' )
  );
  const [ modal, setModal ]     = useState<{ mode: 'add' | 'edit'; release: ReleaseForm; id?: string } | null>( null );
  const [ saving, setSaving ]   = useState( false );
  const [ deleting, setDeleting ] = useState<string | null>( null );
  const fb = useFeedback();

  async function handleSave( form: ReleaseForm ) {
    setSaving( true ); fb.start();
    try {
      if ( modal?.mode === 'edit' && modal.id ) {
        await updateItem( 'release', modal.id, form as unknown as Partial<Release> );
      } else {
        await createItem( 'release', { ...form, linkedFeatureIds:[], linkedBugIds:[], linkedFeedbackIds:[], totalTimeEstimate:0 } );
      }
      triggerRefresh(); setModal( null ); fb.ok();
    } catch { fb.fail(); }
    setSaving( false );
  }

  async function handleDelete( id: string, name: string ) {
    if ( ! window.confirm(`Archive release "${name}"? It can be restored from the Archive tab.`) ) return;
    setDeleting( id ); fb.start();
    try {
      await archiveItem( 'release', id );
      triggerRefresh(); fb.ok();
    } catch { fb.fail(); }
    setDeleting( null );
  }

  return (
    <>
      <Card>
        <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:14 }}>
          <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:0 }}>
            Manage Releases
            <span style={{ marginLeft:8, fontSize:12, fontWeight:500, padding:'1px 7px', borderRadius:99, background:'#f1f5f9', color:'#64748b' }}>{ data_releases.length }</span>
          </h3>
          <div style={{ display:'flex', alignItems:'center', gap:10 }}>
            <SaveFeedback state={fb.state} />
            <button
              onClick={ () => setModal({ mode:'add', release:{ ...emptyReleaseForm } }) }
              style={{ display:'inline-flex', alignItems:'center', gap:5, padding:'6px 12px', borderRadius:6, fontSize:13, fontWeight:500, border:'none', background:'#2563eb', color:'#fff', cursor:'pointer' }}>
              <Plus size={13} /> Add Release
            </button>
          </div>
        </div>

        { data_releases.length === 0 ? (
          <p style={{ fontSize:14, color:'#64748b', margin:0 }}>No releases yet.</p>
        ) : (
          <div style={{ display:'flex', flexDirection:'column', gap:6 }}>
            { sortedReleases.map( r => (
              <div key={r.id} style={{ display:'flex', alignItems:'center', gap:12, padding:'10px 12px', borderRadius:6, background:'#fafbfc', border:'1px solid #e2e8f0' }}>
                <div style={{ flex:1, minWidth:0 }}>
                  <div style={{ fontSize:14, fontWeight:500, color:'#1a1f36' }}>{ r.name }</div>
                  <div style={{ fontSize:12, color:'#64748b' }}>
                    { r.quarter && `${ r.quarter } · ` }
                    { r.startWeek && r.endWeek && `${ formatDate( r.startWeek ) } → ${ formatDate( r.endWeek ) } · ` }
                    { r.capacity }h capacity · <span style={{ textTransform:'capitalize' }}>{ r.status }</span>
                  </div>
                </div>
                <button
                  onClick={ () => setModal({ mode:'edit', id:r.id, release:{ name:r.name, releaseName:r.releaseName||'', versionNumber:r.versionNumber||'', versionType:r.versionType||'Major Release', quarter:r.quarter, startWeek:r.startWeek, endWeek:r.endWeek, status:r.status, capacity:r.capacity, isBigWedgeCampaign:r.isBigWedgeCampaign } }) }
                  style={{ display:'inline-flex', alignItems:'center', gap:4, padding:'5px 10px', borderRadius:6, fontSize:12, fontWeight:500, border:'1px solid #e2e8f0', background:'#fff', color:'#374151', cursor:'pointer' }}>
                  <Pencil size={11} /> Edit
                </button>
                <button
                  onClick={ () => handleDelete(r.id, r.name) }
                  disabled={ deleting === r.id }
                  style={{ display:'inline-flex', width:28, height:28, alignItems:'center', justifyContent:'center', borderRadius:6, border:'none', background:'transparent', color:'#94a3b8', cursor:'pointer' }}
                  onMouseEnter={ e => { const b=e.currentTarget as HTMLButtonElement; b.style.background='#fff1f2'; b.style.color='#ef4444'; } }
                  onMouseLeave={ e => { const b=e.currentTarget as HTMLButtonElement; b.style.background='transparent'; b.style.color='#94a3b8'; } }>
                  { deleting===r.id ? <Loader2 size={13} style={{ animation:'spin 1s linear infinite' }} /> : <X size={13} /> }
                </button>
              </div>
            ) ) }
          </div>
        ) }
      </Card>

      { modal && (
        <ReleaseModal
          release={ modal.release }
          teamMonthlyHours={ settings.teamMonthlyHours }
          releaseDay={ settings.releaseDay ?? 1 }
          onSave={ handleSave }
          onClose={ () => setModal(null) }
          isSaving={ saving }
        />
      ) }
    </>
  );
}

// ── Archive section ──────────────────────────────────────────────────────────
const ARCHIVE_PAGE_SIZE = 25;

function ArchiveSection() {
  const [ items, setItems ] = useState<ArchivedItem[]>( [] );
  const [ loading, setLoading ] = useState( true );
  const [ restoring, setRestoring ] = useState<string | null>( null );
  const [ error, setError ] = useState<string | null>( null );
  const [ page, setPage ] = useState( 0 );

  const load = useCallback( async () => {
    try { setItems( await fetchArchived() ); }
    catch ( e: unknown ) { setError( ( e instanceof Error ? e.message : String( e ) ) ?? 'Failed to load archive.' ); }
    finally { setLoading(false); }
  }, [] );

  // eslint-disable-next-line react-hooks/set-state-in-effect -- deliberate: fetches the archive on mount; the state it sets is the response, which no render can produce.
  useEffect( () => { load(); }, [ load ] );

  async function handleRestore( item: ArchivedItem ) {
    setRestoring( item.id );
    try {
      await restoreItem( item.itemType, item.id );
      setItems( prev => {
        const next = prev.filter( i => i.id !== item.id );
        const maxPage = Math.max( 0, Math.ceil( next.length / ARCHIVE_PAGE_SIZE ) - 1 );
        setPage( p => Math.min( p, maxPage ) );
        return next;
      } );
    }
    catch { /* keep in list */ }
    setRestoring( null );
  }

  if ( loading ) return <div style={{ display:'flex', alignItems:'center', gap:8, padding:24, color:'#64748b', fontSize:14 }}><Loader2 size={16} style={{ animation:'spin 1s linear infinite' }} />Loading…</div>;
  if ( error )   return <div style={{ display:'flex', alignItems:'center', gap:8, padding:20, borderRadius:8, background:'#fff1f2', border:'1px solid #fecdd3', color:'#e11d48', fontSize:14 }}><AlertCircle size={15} />{ error }</div>;
  if ( items.length === 0 ) return (
    <Card style={{ display:'flex', flexDirection:'column', alignItems:'center', justifyContent:'center', padding:'48px 24px', gap:10 }}>
      <ArchiveIcon size={32} strokeWidth={1.5} style={{ color:'#cbd5e1' }} />
      <p style={{ fontSize:14, color:'#64748b', margin:0 }}>No archived items</p>
      <p style={{ fontSize:13, color:'#94a3b8', margin:0 }}>Items you delete will appear here for recovery.</p>
    </Card>
  );

  const totalPages  = Math.ceil( items.length / ARCHIVE_PAGE_SIZE );
  const pageItems   = items.slice( page * ARCHIVE_PAGE_SIZE, ( page + 1 ) * ARCHIVE_PAGE_SIZE );

  return (
    <Card style={{ padding:0, overflow:'hidden' }}>
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'14px 20px', borderBottom:'1px solid #e2e8f0' }}>
        <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:0 }}>
          Archived Items
          <span style={{ marginLeft:8, fontSize:12, fontWeight:500, padding:'1px 7px', borderRadius:99, background:'#f1f5f9', color:'#64748b' }}>{ items.length }</span>
        </h3>
        { totalPages > 1 && (
          <div style={{ display:'flex', alignItems:'center', gap:8 }}>
            <button onClick={ () => setPage( p => p - 1 ) } disabled={ page === 0 }
              style={{ padding:'4px 10px', borderRadius:6, fontSize:12, fontWeight:500, border:'1px solid #e2e8f0', background:'#fff', color:'#374151', cursor: page===0?'not-allowed':'pointer', opacity: page===0?0.4:1 }}>
              ← Prev
            </button>
            <span style={{ fontSize:12, color:'#64748b' }}>{ page + 1 } / { totalPages }</span>
            <button onClick={ () => setPage( p => p + 1 ) } disabled={ page >= totalPages - 1 }
              style={{ padding:'4px 10px', borderRadius:6, fontSize:12, fontWeight:500, border:'1px solid #e2e8f0', background:'#fff', color:'#374151', cursor: page>=totalPages-1?'not-allowed':'pointer', opacity: page>=totalPages-1?0.4:1 }}>
              Next →
            </button>
          </div>
        ) }
      </div>
      { pageItems.map( ( item, idx ) => {
        const badge = TYPE_BADGE[item.itemType] ?? TYPE_BADGE.company_date;
        const isSelf = restoring === item.id;
        return (
          <div key={item.id} style={{ display:'flex', alignItems:'center', gap:12, padding:'12px 20px', borderBottom: idx<pageItems.length-1?'1px solid #f1f5f9':'none' }}>
            <span style={{ flexShrink:0, fontSize:11, fontWeight:600, padding:'2px 7px', borderRadius:99, background:badge.bg, color:badge.color }}>{ badge.label }</span>
            <span style={{ flex:1, fontSize:14, color:'#1a1f36', minWidth:0, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>{ item.name }</span>
            <span style={{ flexShrink:0, fontSize:12, color:'#94a3b8' }}>{ item.archivedAt }</span>
            <button onClick={ () => handleRestore(item) } disabled={!!restoring}
              style={{ flexShrink:0, display:'inline-flex', alignItems:'center', gap:5, padding:'5px 11px', borderRadius:6, fontSize:12, fontWeight:500, border:'1px solid #e2e8f0', cursor:restoring?'not-allowed':'pointer', background:'#fff', color:'#374151', opacity: restoring&&!isSelf?0.5:1 }}>
              { isSelf ? <Loader2 size={12} style={{ animation:'spin 1s linear infinite' }} /> : <RotateCcw size={12} /> }
              Restore
            </button>
          </div>
        );
      } ) }
    </Card>
  );
}

// ── Export section ───────────────────────────────────────────────────────────
function ExportSection( { settings }: { settings: AppSettings } ) {
  const data_features  = useDataStore( s => s.features );
  const data_subitems  = useDataStore( s => s.subitems );
  const data_bugs      = useDataStore( s => s.bugs );
  const data_feedback  = useDataStore( s => s.feedback );
  const data_releases  = useDataStore( s => s.releases );
  const [ types, setTypes ]     = useState( { feature:true, subitem:true, bug:true, feedback:true, release:true } );
  const [ stage, setStage ]     = useState( 'all' );
  const [ releaseId, setRelId ] = useState( 'all' );
  const [ dateFrom, setFrom ]   = useState( '' );
  const [ dateTo,   setTo   ]   = useState( '' );

  function toggleType( t: keyof typeof types ) {
    setTypes( prev => ({ ...prev, [t]: !prev[t] }) );
  }

  function getFilteredData() {
    const results: Record<string, unknown>[] = [];

    const matchStage = ( s: string ) => stage === 'all' || s === stage;
    const matchRelease = ( rid?: string ) => releaseId === 'all' || rid === releaseId;
    const matchDate = ( d?: string ) => {
      if ( ! d ) return true;
      if ( dateFrom && d < dateFrom ) return false;
      if ( dateTo   && d > dateTo   ) return false;
      return true;
    };

    if ( types.feature ) {
      data_features.filter( f => matchStage(f.workflowStage) && matchRelease(f.releaseId) && matchDate(f.createdDate) ).forEach( f => {
        results.push({ id:f.id, type:'feature', name:f.name, workflowStage:f.workflowStage, category:f.category, featurePrice:f.featurePrice, timeEstimate:f.timeEstimate, releaseId:f.releaseId??'', isEnabled:f.isEnabled, createdDate:f.createdDate });
      });
    }
    if ( types.subitem ) {
      data_subitems.filter( s => matchStage(s.workflowStage) && matchRelease(s.releaseId) ).forEach( s => {
        results.push({ id:s.id, type:'subitem', name:s.name, workflowStage:s.workflowStage, category:s.category, timeEstimate:s.timeEstimate, releaseId:s.releaseId??'', parentFeatureId:s.parentFeatureId });
      });
    }
    if ( types.bug ) {
      data_bugs.filter( b => matchStage(b.workflowStage) && matchRelease(b.releaseId) && matchDate(b.reportedDate) ).forEach( b => {
        results.push({ id:b.id, type:'bug', name:b.title, workflowStage:b.workflowStage, priority:b.priority, bugStatus:b.bugStatus, timeEstimate:b.timeEstimate, releaseId:b.releaseId??'', reportedDate:b.reportedDate });
      });
    }
    if ( types.feedback ) {
      data_feedback.filter( f => matchStage(f.workflowStage) && matchRelease(f.releaseId) && matchDate(f.reportedDate) ).forEach( f => {
        results.push({ id:f.id, type:'feedback', name:f.title, workflowStage:f.workflowStage, priority:f.priority, status:f.status, timeEstimate:f.timeEstimate, releaseId:f.releaseId??'', reportedDate:f.reportedDate });
      });
    }
    if ( types.release ) {
      data_releases.filter( r => releaseId==='all' || r.id===releaseId ).forEach( r => {
        results.push({ id:r.id, type:'release', name:r.name, quarter:r.quarter, startWeek:r.startWeek, endWeek:r.endWeek, status:r.status, capacity:r.capacity, totalTimeEstimate:r.totalTimeEstimate });
      });
    }

    return results;
  }

  function downloadFile( content: string, filename: string, mime: string ) {
    const blob = new Blob( [content], { type: mime } );
    const url  = URL.createObjectURL( blob );
    const a    = document.createElement( 'a' );
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL( url );
  }

  function handleExportJSON() {
    const filtered = getFilteredData();
    downloadFile( JSON.stringify( filtered, null, 2 ), 'forge-export.json', 'application/json' );
  }

  function handleExportCSV() {
    const rows = getFilteredData();
    if ( rows.length === 0 ) return;
    const keys = Array.from( new Set( rows.flatMap( r => Object.keys(r) ) ) );
    const escape = ( v: unknown ) => `"${ String(v ?? '').replace(/"/g,'""') }"`;
    const csv = [ keys.join(','), ...rows.map( r => keys.map( k => escape( r[k] ) ).join(',') ) ].join('\n');
    downloadFile( csv, 'forge-export.csv', 'text/csv' );
  }

  const count = getFilteredData().length;

  const checkStyle = ( active: boolean ): React.CSSProperties => ({
    display:'inline-flex', alignItems:'center', gap:6, padding:'6px 12px', borderRadius:6, fontSize:13, fontWeight:500,
    cursor:'pointer', border:`1px solid ${ active?'#2563eb':'#e2e8f0' }`, background: active?'#eff6ff':'#fff',
    color: active?'#2563eb':'#64748b', transition:'all 0.12s',
  });

  const typeKeys = Object.keys( types ) as ( keyof typeof types )[];

  return (
    <div style={{ display:'flex', flexDirection:'column', gap:16 }}>
      <Card>
        <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:'0 0 14px' }}>Item Types</h3>
        <div style={{ display:'flex', flexWrap:'wrap', gap:8 }}>
          { typeKeys.map( t => (
            <button key={t} onClick={ () => toggleType(t) } style={ checkStyle(types[t]) }>
              { types[t] && <Check size={12} /> }
              { t.charAt(0).toUpperCase() + t.slice(1) }
            </button>
          ) ) }
        </div>
      </Card>

      <Card>
        <h3 style={{ fontSize:14, fontWeight:600, color:'#1a1f36', margin:'0 0 14px' }}>Filters</h3>
        <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fit,minmax(200px,1fr))', gap:14 }}>
          <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
            <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Workflow stage</span>
            <select value={stage} onChange={e=>setStage(e.target.value)} style={inputStyle}>
              <option value="all">All stages</option>
              { settings.statuses.map( s => <option key={s.id} value={s.id}>{ s.label }</option> ) }
            </select>
          </label>
          <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
            <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Release</span>
            <select value={releaseId} onChange={e=>setRelId(e.target.value)} style={inputStyle}>
              <option value="all">All releases</option>
              { data_releases.map( r => <option key={r.id} value={r.id}>{ r.name }</option> ) }
            </select>
          </label>
          <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
            <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Date from</span>
            <input type="date" value={dateFrom} onChange={e=>setFrom(e.target.value)} style={inputStyle} />
          </label>
          <label style={{ display:'flex', flexDirection:'column', gap:5 }}>
            <span style={{ fontSize:13, fontWeight:500, color:'#374151' }}>Date to</span>
            <input type="date" value={dateTo} onChange={e=>setTo(e.target.value)} style={inputStyle} />
          </label>
        </div>
      </Card>

      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'14px 20px', borderRadius:8, background:'#fff', border:'1px solid #e2e8f0' }}>
        <span style={{ fontSize:14, color:'#64748b' }}>
          <strong style={{ color:'#1a1f36' }}>{ count }</strong> item{ count!==1?'s':'' } match your filters
        </span>
        <div style={{ display:'flex', gap:10 }}>
          <button onClick={handleExportJSON} disabled={count===0}
            style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'7px 16px', borderRadius:6, fontSize:13, fontWeight:500, border:'1px solid #e2e8f0', background:'#fff', color:'#1a1f36', cursor:count===0?'not-allowed':'pointer', opacity:count===0?0.5:1 }}>
            <Download size={13} /> JSON
          </button>
          <button onClick={handleExportCSV} disabled={count===0}
            style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'7px 16px', borderRadius:6, fontSize:13, fontWeight:500, border:'none', background:'#2563eb', color:'#fff', cursor:count===0?'not-allowed':'pointer', opacity:count===0?0.5:1 }}>
            <Download size={13} /> CSV
          </button>
        </div>
      </div>
    </div>
  );
}

const MANAGER_SECTIONS: Section[] = [ 'brands', 'categories', 'releases' ];

// ── Main Settings component ──────────────────────────────────────────────────
// Header + body for a single settings section — shared by the sidebar layout and
// the Kanban-style horizontal carousel so the two never drift apart.
function SectionHeader( { title, blurb }: { title: string; blurb: string } ) {
  return (
    <>
      <h2 style={{ fontSize:18, fontWeight:700, color:'#1a1f36', margin:'0 0 4px' }}>{ title }</h2>
      <p style={{ fontSize:13, color:'#64748b', margin:'0 0 20px' }}>{ blurb }</p>
    </>
  );
}

export function Settings( { settings, onSettingsChange }: SettingsProps ) {
  const wpAdmin = isAdmin();
  const isMobile = useIsMobile();
  // Mobile keeps the #50 Kanban-style strip + swipe carousel; desktop uses the left nav.
  const tabStripRef = useDragScroll<HTMLDivElement>( { axis: 'x', allowButtons: true } );
  const carouselRef = useDragScroll<HTMLDivElement>( { axis: 'x', allowButtons: true, gain: 2 } );
  const visibleSections = wpAdmin ? SECTION_NAV : SECTION_NAV.filter( s => MANAGER_SECTIONS.includes( s.id ) );
  const [ activeIdx, setActiveIdx ] = useState( 0 );
  const persist = useSaveApi( onSettingsChange );

  const safeIdx = Math.min( activeIdx, Math.max( 0, visibleSections.length - 1 ) );

  // Swiping the pages updates the active tab… (mobile only)
  const handleCarouselScroll = () => {
    const el = carouselRef.current;
    if ( ! el || el.clientWidth === 0 ) return;
    const idx = Math.round( el.scrollLeft / el.clientWidth );
    setActiveIdx( prev => prev === idx ? prev : idx );
  };

  // …and tapping a tab scrolls the pages to match.
  const goToPage = ( idx: number ) => {
    setActiveIdx( idx );
    const el = carouselRef.current;
    if ( el ) el.scrollTo( { left: idx * el.clientWidth, behavior: 'smooth' } );
  };

  // Keep the active tab visible in the strip as the carousel moves.
  useEffect( () => {
    if ( ! isMobile ) return;
    const btn = tabStripRef.current?.children[ safeIdx ] as HTMLElement | undefined;
    btn?.scrollIntoView( { inline: 'center', block: 'nearest', behavior: 'smooth' } );
  }, [ safeIdx, isMobile, tabStripRef ] );

  // Inner content for one section.
  const renderSection = ( id: Section ) => {
    switch ( id ) {
      case 'config':
        return ( <div><SectionHeader title="Config" blurb="Project name, team capacity, and global settings." /><ConfigSection settings={settings} persist={persist} /></div> );
      case 'brands':
        return ( <div><SectionHeader title="Brands" blurb="Manage brands, logos, and set the parent brand for comparisons." /><BrandParentConfig settings={settings} persist={persist} /><ManageBrands settings={settings} persist={persist} /></div> );
      case 'categories':
        return ( <div><SectionHeader title="Categories" blurb="Manage feature categories. Listed alphabetically." /><CategoriesSection settings={settings} persist={persist} /></div> );
      case 'releases':
        return ( <div><SectionHeader title="Releases" blurb="Add, edit, and remove release milestones." /><ReleasesSection settings={settings} /></div> );
      case 'statuses':
        return ( <div><SectionHeader title="Statuses" blurb="Define the workflow stages items move through. Order controls the Kanban column sequence." /><StatusesSection settings={settings} persist={persist} /></div> );
      case 'archive':
        return ( <div><SectionHeader title="Archive" blurb="Archived items are stored here. Restore any item to bring it back." /><ArchiveSection /></div> );
      case 'export':
        return ( <div><SectionHeader title="Export Data" blurb="Download your project data as CSV or JSON with optional filters." /><ExportSection settings={settings} /></div> );
    }
  };

  const navCss = (
    <style>{ `
      @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
      .settings-nav-scroll { scrollbar-width: none; -ms-overflow-style: none; }
      .settings-nav-scroll::-webkit-scrollbar { display: none; }
    ` }</style>
  );

  // ── Mobile: #50 top tab strip + swipe carousel · Desktop: left nav + panel ──
  if ( isMobile ) {
    return (
      <>
        { navCss }
        <div style={{ display:'flex', flexDirection:'column', height:'100%' }}>
          {/* Tab strip — synced with the page carousel */}
          <div ref={ tabStripRef } className="settings-nav-scroll" style={{ display:'flex', borderBottom:'1px solid #e2e8f0', background:'#fff', overflowX:'auto', overflowY:'hidden', flexShrink:0, cursor:'grab' }}>
            { visibleSections.map( ({ id, label, Icon }, idx) => {
              const active = idx === safeIdx;
              return (
                <button key={id} onClick={ () => goToPage(idx) }
                  style={{ display:'inline-flex', alignItems:'center', gap:6, padding:'10px 16px', fontSize:13, fontWeight: active?700:500, border:'none', borderBottom: active?'2px solid #2563eb':'2px solid transparent', marginBottom:-1, cursor:'pointer', whiteSpace:'nowrap', flexShrink:0, background:'transparent', color: active?'#2563eb':'#64748b' }}>
                  <Icon size={13} />{ label }
                </button>
              );
            } ) }
          </div>

          {/* Swipeable page carousel — one settings page per panel, snaps left/right */}
          <div
            ref={ carouselRef }
            onScroll={ handleCarouselScroll }
            className="settings-nav-scroll"
            style={{ flex:1, display:'flex', overflowX:'auto', overflowY:'hidden', scrollSnapType:'x mandatory', WebkitOverflowScrolling:'touch', cursor:'grab' }}
          >
            { visibleSections.map( ({ id }) => (
              <div key={id} style={{ flex:'0 0 100%', width:'100%', scrollSnapAlign:'start', overflowY:'auto', padding:24 }}>
                { renderSection( id ) }
              </div>
            ) ) }
          </div>
        </div>
      </>
    );
  }

  return (
    <>
      { navCss }
      <div style={{ display:'flex', height:'100%' }}>
        {/* Left-hand section nav (desktop) */}
        <nav className="settings-nav-scroll" style={{ flexShrink:0, width:208, borderRight:'1px solid #e2e8f0', background:'#fff', overflowY:'auto', display:'flex', flexDirection:'column', gap:2, padding:'12px 8px' }}>
          { visibleSections.map( ({ id, label, Icon }, idx) => {
            const active = idx === safeIdx;
            return (
              <button key={id} onClick={ () => setActiveIdx(idx) } aria-current={ active ? 'page' : undefined }
                style={{ display:'flex', alignItems:'center', gap:10, padding:'10px 12px', fontSize:13, fontWeight: active?700:500, border:'none', borderRadius:8, cursor:'pointer', textAlign:'left', whiteSpace:'nowrap', background: active?'#eff6ff':'transparent', color: active?'#2563eb':'#64748b' }}>
                <Icon size={16} />{ label }
              </button>
            );
          } ) }
        </nav>

        {/* Active section content */}
        <div className="settings-nav-scroll" style={{ flex:1, minWidth:0, overflowY:'auto', padding:24 }}>
          { renderSection( visibleSections[ safeIdx ].id ) }
        </div>
      </div>
    </>
  );
}
