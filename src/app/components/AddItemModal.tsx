import { useState } from 'react';
import { X, Loader2, ImageIcon } from 'lucide-react';
import { useIsMobile } from '../hooks/useIsMobile';
import { AppSettings, WorkflowStatus, ItemLink } from '../types';
import { createItem } from '../api/wordpress';
import { useDataStore } from '../store/useDataStore';
import { LinksEditor } from './LinksField';
import { encodeParentValue, decodeParentValue } from '../utils/linkParent';

interface WPMediaFrame {
  on: ( event: string, callback: () => void ) => void;
  state: () => { get: ( key: string ) => { toArray: () => Array<{ toJSON: () => { url: string } }> } };
  open: () => void;
}

declare global {
  interface Window {
    wp?: { media: ( args: Record<string, unknown> ) => WPMediaFrame };
  }
}

interface AddItemModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  settings: AppSettings;
}

const C = {
  white: '#ffffff', border: '#e2e8f0',
  fg: '#1a1f36', mutedFg: '#64748b',
  primary: '#2563eb',
};

const TYPE_OPTIONS = [
  { value: 'feature',  label: 'Feature'  },
  { value: 'subitem',  label: 'Sub-Item' },
  { value: 'bug',      label: 'Bug'      },
  { value: 'feedback', label: 'Feedback' },
];

const PRICE_OPTIONS = [
  { value: 'scoping', label: 'Scoping' },
  { value: 'free',    label: 'Free'    },
  { value: 'teaser',  label: 'Teaser'  },
  { value: 'premium', label: 'Premium' },
];

const PRIORITY_OPTIONS = [ 'low', 'medium', 'high' ];

function getDefaultStage( type: string, statuses: WorkflowStatus[] ): string {
  const targetId = type === 'bug' ? 'bug-tracking' : 'triage';
  return statuses.find( s => s.id === targetId )?.id ?? statuses[0]?.id ?? '';
}

function openMediaPicker( onSelect: ( urls: string[] ) => void ) {
  if ( ! window.wp?.media ) return;
  const frame = window.wp.media( {
    title: 'Select Images',
    multiple: true,
    library: { type: 'image' },
    button: { text: 'Use these images' },
  } );
  frame.on( 'select', () => {
    const attachments = frame.state().get( 'selection' ).toArray();
    const urls: string[] = attachments.map( ( a: { toJSON: () => { url: string } } ) => a.toJSON().url );
    onSelect( urls );
  } );
  frame.open();
}

export function AddItemModal( { isOpen, onClose, onSuccess, settings }: AddItemModalProps ) {
  const features = useDataStore( s => s.features );
  const releases = useDataStore( s => s.releases );
  const subitems = useDataStore( s => s.subitems );
  const isMobile = useIsMobile( 640 );
  const [itemType,         setItemType]        = useState( 'feature' );
  const [name,             setName]            = useState( '' );
  const [description,      setDescription]     = useState( '' );
  const [stage,            setStage]           = useState( () => getDefaultStage( 'feature', settings.statuses ) );
  const [priority,         setPriority]        = useState( 'medium' );
  const [featurePrice,     setFeaturePrice]    = useState( 'scoping' );
  const [timeEst,          setTimeEst]         = useState( 0 );
  const [releaseId,        setReleaseId]       = useState( '' );
  const [category,         setCategory]        = useState( '' );
  const [linkedFeatureId,  setLinkedFeatureId] = useState( '' );
  const [linkedSubItemId,  setLinkedSubItemId] = useState( '' );
  const [links,            setLinks]           = useState<ItemLink[]>( [] );
  const [imageUrls,        setImageUrls]       = useState<string[]>( [] );
  const [saving,           setSaving]          = useState( false );
  const [error,            setError]           = useState( '' );

  if ( ! isOpen ) return null;

  const isFeature  = itemType === 'feature';
  const isSubitem  = itemType === 'subitem';
  const isBug      = itemType === 'bug';
  const isFeedback = itemType === 'feedback';
  const hasPrice   = isFeature || isSubitem;
  const hasImages  = isFeature || isSubitem || isBug || isFeedback;
  const hasLinkedFeature = isSubitem || isBug || isFeedback;

  const hasWPMedia    = !! window.wp?.media;
  const needsCategory = hasPrice && settings.categories.length > 0;

  function handleTypeChange( type: string ) {
    setItemType( type );
    setStage( getDefaultStage( type, settings.statuses ) );
    // Reset category and linked feature when switching types
    setCategory( '' );
    setLinkedFeatureId( '' );
    setLinkedSubItemId( '' );
  }

  function handleParentFeatureChange( featureId: string ) {
    setLinkedFeatureId( featureId );
    if ( isSubitem && featureId ) {
      const parent = features.find( f => f.id === featureId );
      if ( parent?.category ) setCategory( parent.category );
    }
  }

  function handleAddImages() {
    openMediaPicker( urls => {
      setImageUrls( prev => {
        const combined = [ ...prev, ...urls ];
        return combined.filter( ( u, i ) => combined.indexOf( u ) === i );
      } );
    } );
  }

  async function handleSubmit( e: React.FormEvent ) {
    e.preventDefault();
    if ( ! name.trim() ) return;
    if ( ! description.trim() ) return;
    if ( isSubitem && ! linkedFeatureId ) return;
    if ( needsCategory && ! category && ! ( isSubitem && linkedFeatureId ) ) return;
    setSaving( true );
    setError( '' );

    const payload: Record<string, unknown> = {
      workflowStage: stage,
      timeEstimate:  timeEst,
      description:   description.trim(),
    };

    if ( isFeature ) {
      payload.name             = name.trim();
      payload.category         = category;
      payload.featurePrice     = featurePrice;
      payload.isEnabled        = false;
      payload.isTrackedAsStat  = false;
      if ( releaseId )          payload.releaseId = releaseId;
      if ( imageUrls.length )   payload.images    = imageUrls;
    } else if ( isSubitem ) {
      payload.name          = name.trim();
      payload.category      = category;
      payload.featurePrice  = featurePrice;
      payload.parentFeatureId = linkedFeatureId;
      if ( imageUrls.length ) payload.images = imageUrls;
    } else if ( isBug ) {
      payload.title        = name.trim();
      payload.priority     = priority;
      payload.bugStatus    = 'open';
      payload.reportedDate = new Date().toISOString().slice( 0, 10 );
      if ( linkedFeatureId ) payload.linkedFeatureId = linkedFeatureId;
      if ( linkedSubItemId ) payload.linkedSubItemId = linkedSubItemId;
      if ( imageUrls.length ) payload.images = imageUrls;
    } else if ( isFeedback ) {
      payload.title        = name.trim();
      payload.priority     = priority;
      payload.status       = 'open';
      payload.reportedDate = new Date().toISOString().slice( 0, 10 );
      if ( linkedFeatureId ) payload.linkedFeatureId = linkedFeatureId;
      if ( linkedSubItemId ) payload.linkedSubItemId = linkedSubItemId;
      if ( imageUrls.length ) payload.images = imageUrls;
    }

    if ( links.length ) payload.links = links.filter( l => l.url.trim() );

    try {
      if ( window.forgePMData ) await createItem( itemType, payload );
      onSuccess();
      handleClose();
    } catch ( err: unknown ) {
      setError( ( err instanceof Error ? err.message : String( err ) ) ?? 'Failed to create item.' );
    } finally {
      setSaving( false );
    }
  }

  function handleClose() {
    setName( '' ); setDescription( '' );
    setStage( getDefaultStage( 'feature', settings.statuses ) );
    setPriority( 'medium' ); setFeaturePrice( 'scoping' );
    setTimeEst( 0 ); setReleaseId( '' ); setCategory( '' );
    setLinkedFeatureId( '' ); setLinkedSubItemId( '' ); setLinks( [] ); setImageUrls( [] );
    setError( '' ); setItemType( 'feature' );
    onClose();
  }

  const inputStyle: React.CSSProperties = {
    width: '100%', padding: '8px 10px', borderRadius: 6,
    border: `1px solid ${ C.border }`, fontSize: 14, color: C.fg,
    outline: 'none', background: C.white, boxSizing: 'border-box',
  };
  const labelStyle: React.CSSProperties = { display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 5 };

  const canSubmit = !! name.trim() && !! description.trim() && ! saving
    && ( ! isSubitem || !! linkedFeatureId )
    && ( ! needsCategory || !! category );

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 60, backgroundColor: 'rgba(0,0,0,0.45)', display: 'flex', alignItems: isMobile ? 'flex-end' : 'center', justifyContent: 'center', padding: isMobile ? 0 : 16 }}>
      <div style={{ backgroundColor: C.white, borderRadius: isMobile ? '16px 16px 0 0' : 12, boxShadow: '0 20px 60px rgba(0,0,0,0.2)', width: '100%', maxWidth: isMobile ? '100%' : 520, maxHeight: '90vh', display: 'flex', flexDirection: 'column', border: `1px solid ${ C.border }` }}>

        {/* Header */}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: `1px solid ${ C.border }`, flexShrink: 0 }}>
          <h3 style={{ fontSize: 16, fontWeight: 600, color: C.fg, margin: 0 }}>Add New Item</h3>
          <button onClick={ handleClose } style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.mutedFg, display: 'flex' }}>
            <X size={ 20 } />
          </button>
        </div>

        <form onSubmit={ handleSubmit } style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 14, overflowY: 'auto', flex: 1 }}>

          {/* Type selector */}
          <div>
            <label style={ labelStyle }>Item type</label>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 6 }}>
              { TYPE_OPTIONS.map( opt => (
                <button key={ opt.value } type="button" onClick={ () => handleTypeChange( opt.value ) }
                  style={{ padding: '7px 4px', borderRadius: 6, fontSize: isMobile ? 12 : 13, fontWeight: 500, cursor: 'pointer',
                    border: `1px solid ${ itemType === opt.value ? C.primary : C.border }`,
                    background: itemType === opt.value ? '#eff6ff' : C.white,
                    color: itemType === opt.value ? C.primary : C.fg, transition: 'all 0.12s',
                    textAlign: 'center' }}>
                  { opt.label }
                </button>
              ) ) }
            </div>
          </div>

          {/* Name */}
          <div>
            <label style={ labelStyle }>{ isFeature || isSubitem ? 'Name' : 'Title' }</label>
            <input autoFocus type="text" required value={ name } onChange={ e => setName( e.target.value ) }
              placeholder={ isFeature ? 'e.g. Improved onboarding flow' : isSubitem ? 'e.g. Dark mode support' : 'e.g. Login button not responding' }
              style={ inputStyle } />
          </div>

          {/* Description */}
          <div>
            <label style={ labelStyle }>Description <span style={{ fontSize: 12, fontWeight: 400, color: '#ef4444' }}>*</span></label>
            <textarea required value={ description } onChange={ e => setDescription( e.target.value ) }
              placeholder="Add a short description…"
              style={{ ...inputStyle, minHeight: 72, resize: 'vertical' }} />
          </div>

          {/* Stage */}
          <div>
            <label style={ labelStyle }>Workflow stage</label>
            <select value={ stage } onChange={ e => setStage( e.target.value ) } style={ inputStyle }>
              { settings.statuses.map( s => <option key={ s.id } value={ s.id }>{ s.label }</option> ) }
            </select>
          </div>

          {/* Price (features + subitems) */}
          { hasPrice && (
            <div>
              <label style={ labelStyle }>Feature price</label>
              <div style={{ display: 'flex', gap: 6 }}>
                { PRICE_OPTIONS.map( opt => (
                  <button key={ opt.value } type="button" onClick={ () => setFeaturePrice( opt.value ) }
                    style={{ flex: 1, padding: '7px 0', borderRadius: 6, fontSize: 12, fontWeight: 500, cursor: 'pointer',
                      border: `1px solid ${ featurePrice === opt.value ? C.primary : C.border }`,
                      background: featurePrice === opt.value ? '#eff6ff' : C.white,
                      color: featurePrice === opt.value ? C.primary : C.fg, transition: 'all 0.12s' }}>
                    { opt.label }
                  </button>
                ) ) }
              </div>
            </div>
          ) }

          {/* Priority (bugs + feedback) */}
          { ( isBug || isFeedback ) && (
            <div>
              <label style={ labelStyle }>Priority</label>
              <div style={{ display: 'flex', gap: 6 }}>
                { PRIORITY_OPTIONS.map( p => (
                  <button key={ p } type="button" onClick={ () => setPriority( p ) }
                    style={{ flex: 1, padding: '7px 0', borderRadius: 6, fontSize: 13, fontWeight: 500, cursor: 'pointer',
                      border: `1px solid ${ priority === p ? C.primary : C.border }`,
                      background: priority === p ? '#eff6ff' : C.white,
                      color: priority === p ? C.primary : C.fg,
                      textTransform: 'capitalize', transition: 'all 0.12s' }}>
                    { p }
                  </button>
                ) ) }
              </div>
            </div>
          ) }

          {/* Parent feature — required for sub-items, optional for bugs/feedback */}
          { hasLinkedFeature && ( features.length > 0 || subitems.length > 0 ) && (
            <div>
              <label style={ labelStyle }>
                { isSubitem ? 'Parent feature' : 'Links up to' }
                { ! isSubitem && <span style={{ fontSize: 12, fontWeight: 400, color: C.mutedFg }}> (optional)</span> }
                { isSubitem && <span style={{ fontSize: 12, fontWeight: 400, color: '#ef4444' }}> *</span> }
              </label>
              { isSubitem ? (
                <select
                  value={ linkedFeatureId }
                  onChange={ e => handleParentFeatureChange( e.target.value ) }
                  required
                  style={{ ...inputStyle, borderColor: ! linkedFeatureId ? '#fca5a5' : C.border }}>
                  <option value="">— Select a parent feature —</option>
                  { [ ...features ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( f => (
                    <option key={ f.id } value={ f.id }>{ f.name }</option>
                  ) ) }
                </select>
              ) : (
                <select
                  value={ encodeParentValue( linkedFeatureId, linkedSubItemId ) }
                  onChange={ e => {
                    const { linkedFeatureId: f, linkedSubItemId: s } = decodeParentValue( e.target.value );
                    setLinkedFeatureId( f );
                    setLinkedSubItemId( s );
                  } }
                  style={ inputStyle }>
                  <option value="">— None —</option>
                  <optgroup label="Features">
                    { [ ...features ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( f => (
                      <option key={ f.id } value={ `f:${ f.id }` }>{ f.name }</option>
                    ) ) }
                  </optgroup>
                  { subitems.length > 0 && (
                    <optgroup label="Sub-Items">
                      { [ ...subitems ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( s => (
                        <option key={ s.id } value={ `s:${ s.id }` }>{ s.name }</option>
                      ) ) }
                    </optgroup>
                  ) }
                </select>
              ) }
              { isSubitem && ! linkedFeatureId && (
                <p style={{ margin: '4px 0 0', fontSize: 12, color: '#ef4444' }}>A parent feature is required for sub-items.</p>
              ) }
            </div>
          ) }

          {/* Category — auto-inherited for sub-items, manual for features */}
          { hasPrice && settings.categories.length > 0 && (
            <div>
              <label style={ labelStyle }>
                Category
                { isSubitem && linkedFeatureId
                  ? <span style={{ fontSize: 12, fontWeight: 400, color: C.mutedFg }}> (inherited from parent)</span>
                  : <span style={{ fontSize: 12, fontWeight: 400, color: '#ef4444' }}> *</span>
                }
              </label>
              <select
                required={ ! ( isSubitem && !! linkedFeatureId ) }
                value={ category }
                onChange={ e => setCategory( e.target.value ) }
                disabled={ isSubitem && !! linkedFeatureId }
                style={{ ...inputStyle, opacity: isSubitem && linkedFeatureId ? 0.7 : 1,
                  borderColor: needsCategory && ! category && ! ( isSubitem && linkedFeatureId ) ? '#fca5a5' : C.border }}>
                <option value="">— Select a category —</option>
                { [ ...settings.categories ].sort().map( c => <option key={ c } value={ c }>{ c }</option> ) }
              </select>
            </div>
          ) }

          {/* Release (features only) */}
          { isFeature && releases.length > 0 && (
            <div>
              <label style={ labelStyle }>Release <span style={{ fontSize: 12, fontWeight: 400, color: C.mutedFg }}>(optional)</span></label>
              <select value={ releaseId } onChange={ e => setReleaseId( e.target.value ) } style={ inputStyle }>
                <option value="">— Unassigned —</option>
                { releases.map( r => <option key={ r.id } value={ r.id }>{ r.name }</option> ) }
              </select>
            </div>
          ) }

          {/* Time estimate */}
          <div>
            <label style={ labelStyle }>Time estimate (hours)</label>
            <input type="number" min={ 0 } value={ timeEst } onChange={ e => setTimeEst( Number( e.target.value ) ) }
              style={{ ...inputStyle, width: 120 }} />
          </div>

          {/* Links — all item types */}
          <div>
            <label style={ labelStyle }>Links <span style={{ fontSize: 12, fontWeight: 400, color: C.mutedFg }}>(optional)</span></label>
            <LinksEditor links={ links } onChange={ setLinks } />
          </div>

          {/* Images — all item types, WP media picker */}
          { hasImages && (
            <div>
              <label style={ labelStyle }>
                Images <span style={{ fontSize: 12, fontWeight: 400, color: C.mutedFg }}>(optional)</span>
              </label>
              { hasWPMedia ? (
                <>
                  <button type="button" onClick={ handleAddImages }
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 6, fontSize: 13, fontWeight: 500,
                      border: `1px solid ${ C.border }`, background: C.white, color: C.fg, cursor: 'pointer' }}>
                    <ImageIcon size={ 14 } /> Add from Media Library
                  </button>
                  { imageUrls.length > 0 && (
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 6, marginTop: 8 }}>
                      { imageUrls.map( url => (
                        <div key={ url } style={{ position: 'relative', aspectRatio: '1', borderRadius: 6, overflow: 'hidden', border: `1px solid ${ C.border }` }}>
                          <img src={ url } alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                          <button type="button" onClick={ () => setImageUrls( prev => prev.filter( u => u !== url ) ) }
                            style={{ position: 'absolute', top: 3, right: 3, background: 'rgba(0,0,0,0.6)', border: 'none', borderRadius: 4, cursor: 'pointer', color: '#fff', display: 'flex', padding: 2 }}>
                            <X size={ 10 } />
                          </button>
                        </div>
                      ) ) }
                    </div>
                  ) }
                </>
              ) : (
                <p style={{ margin: 0, fontSize: 12, color: C.mutedFg }}>Image uploads are available when logged in as an admin.</p>
              ) }
            </div>
          ) }

          { error && <p style={{ margin: 0, fontSize: 13, color: '#ef4444' }}>{ error }</p> }

          {/* Actions */}
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, paddingTop: 4, flexShrink: 0 }}>
            <button type="button" onClick={ handleClose }
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 16px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: `1px solid ${ C.border }`, background: C.white, color: C.fg, cursor: 'pointer' }}>
              Cancel
            </button>
            <button type="submit" disabled={ ! canSubmit }
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 16px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: 'none', background: C.primary, color: '#fff', cursor: canSubmit ? 'pointer' : 'not-allowed', opacity: canSubmit ? 1 : 0.6 }}>
              { saving && <Loader2 size={ 13 } style={{ animation: 'spin 1s linear infinite' }} /> }
              Create { TYPE_OPTIONS.find( t => t.value === itemType )?.label }
            </button>
          </div>
        </form>
      </div>
      <style>{ `@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }` }</style>
    </div>
  );
}
