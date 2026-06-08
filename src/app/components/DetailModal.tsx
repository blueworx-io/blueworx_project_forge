import { X, Calendar, Clock, Link2, TrendingUp, Star, BarChart3, AlertCircle, Image as ImageIcon, ExternalLink, CheckCircle, Circle, Tag, History, ScrollText, Trash2, Plus } from 'lucide-react';
import { useState, useEffect } from 'react';
import { Feature, SubItem, Bug, Feedback, Release, AppSettings, BrandConfig, ItemLink } from '../types';
import { ImageLightbox } from './ImageLightbox';
import { LinksEditor, LinksDisplay } from './LinksField';
import { updateItem, archiveItem, isAdmin } from '../api/wordpress';
import { useDataStore } from '../store/useDataStore';
import { useUIStore } from '../store/useUIStore';

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

interface EditFormState {
  id?: string; type?: string; name?: string; title?: string;
  description?: string; notes?: string; releaseId?: string;
  startWeek?: string; endWeek?: string; quarter?: string; status?: string;
  isBigWedgeCampaign?: boolean; capacity?: number;
  brands?: string[]; images?: string[]; urls?: string[];
  timeEstimate?: number; workflowStage?: string;
  linkedFeatureIds?: string[]; linkedBugIds?: string[]; linkedFeedbackIds?: string[];
  links?: ItemLink[];
  linkedSubItemId?: string;
  linkedFeatureId?: string;
  [key: string]: unknown;
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

interface DetailModalProps {
  settings?: AppSettings;
}

const TYPE_STYLES = {
  feature:  { bg: 'bg-blue-50',    text: 'text-blue-700',    border: 'border-blue-200' },
  subitem:  { bg: 'bg-cyan-50',    text: 'text-cyan-700',    border: 'border-cyan-200' },
  bug:      { bg: 'bg-red-50',     text: 'text-red-700',     border: 'border-red-200' },
  feedback: { bg: 'bg-purple-50',  text: 'text-purple-700',  border: 'border-purple-200' },
  release:  { bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200' },
};

export function DetailModal( { settings }: DetailModalProps ) {
  const item      = useUIStore( s => s.selectedItem );
  const isOpen    = useUIStore( s => s.isModalOpen );
  const closeModal = useUIStore( s => s.closeModal );
  const features  = useDataStore( s => s.features );
  const subitems  = useDataStore( s => s.subitems );
  const bugs_     = useDataStore( s => s.bugs );
  const feedback_ = useDataStore( s => s.feedback );
  const releases  = useDataStore( s => s.releases );
  const patchItem      = useDataStore( s => s.patchItem );
  const triggerRefresh = useDataStore( s => s.triggerRefresh );
  const adminMode = isAdmin();
  const [lightboxOpen,    setLightboxOpen]    = useState( false );
  const [lightboxIndex,   setLightboxIndex]   = useState( 0 );
  const [isEditing,       setIsEditing]       = useState( false );
  const [isSaving,        setIsSaving]        = useState( false );
  const [editForm,        setEditForm]        = useState<EditFormState>( {} );
  const [confirmDelete,   setConfirmDelete]   = useState( false );
  const [isDeleting,      setIsDeleting]      = useState( false );
  const [changeLogPage,   setChangeLogPage]   = useState( 0 );

  // Release ↔ item linking state
  const [editLinkedFeatureIds,  setEditLinkedFeatureIds]  = useState<string[]>( [] );
  const [editLinkedBugIds,      setEditLinkedBugIds]      = useState<string[]>( [] );
  const [editLinkedFeedbackIds, setEditLinkedFeedbackIds] = useState<string[]>( [] );
  const [itemSearch,    setItemSearch]    = useState( '' );
  const [releaseSearch, setReleaseSearch] = useState( '' );

  useEffect( () => {
    if ( item ) {
      setEditForm( { ...item } );
      setIsEditing( false );
      setChangeLogPage( 0 );
      setItemSearch( '' );
      setReleaseSearch( '' );
      if ( item.type === 'release' ) {
        const r = item as Release;
        const fIds  = new Set( [ ...r.linkedFeatureIds,  ...features.filter(  f => f.releaseId === r.id ).map( f => f.id ) ] );
        const bIds  = new Set( [ ...r.linkedBugIds,      ...bugs_.filter(      b => b.releaseId === r.id ).map( b => b.id ) ] );
        const fbIds = new Set( [ ...r.linkedFeedbackIds, ...feedback_.filter(  f => f.releaseId === r.id ).map( f => f.id ) ] );
        setEditLinkedFeatureIds( [ ...fIds ] );
        setEditLinkedBugIds( [ ...bIds ] );
        setEditLinkedFeedbackIds( [ ...fbIds ] );
      } else {
        setEditLinkedFeatureIds( [] );
        setEditLinkedBugIds( [] );
        setEditLinkedFeedbackIds( [] );
      }
    }
  }, [item] ); // eslint-disable-line react-hooks/exhaustive-deps

  if ( ! isOpen || ! item ) return null;

  const typeStyle = TYPE_STYLES[item.type];

  const allItems = [ ...features, ...subitems, ...bugs_, ...feedback_, ...releases ];
  const getItemById  = ( id: string ) => allItems.find( ( i ) => i.id === id );
  const handleSave = async () => {
    setIsSaving( true );
    try {
      let savedItem: Item | undefined;
      if ( item.type === 'release' ) {
        // Update release with new linked ID arrays
        const releaseResult = await updateItem( item.type, item.id, {
          ...editForm,
          linkedFeatureIds:  editLinkedFeatureIds,
          linkedBugIds:      editLinkedBugIds,
          linkedFeedbackIds: editLinkedFeedbackIds,
        } as Partial<Release> );
        savedItem = releaseResult.item;

        // Sync releaseId on each item that was added or removed
        if ( window.forgePMData ) {
          const origRelease   = item as Release;
          const origFIds  = new Set( [ ...origRelease.linkedFeatureIds,  ...features.filter(  f => f.releaseId === origRelease.id ).map( f => f.id ) ] );
          const origBIds  = new Set( [ ...origRelease.linkedBugIds,      ...bugs_.filter(      b => b.releaseId === origRelease.id ).map( b => b.id ) ] );
          const origFbIds = new Set( [ ...origRelease.linkedFeedbackIds, ...feedback_.filter(  f => f.releaseId === origRelease.id ).map( f => f.id ) ] );

          await Promise.all( [
            ...editLinkedFeatureIds.filter(  id => ! origFIds.has( id )  ).map( id => updateItem( 'feature',  id, { releaseId: item.id } ) ),
            ...[ ...origFIds  ].filter( id => ! editLinkedFeatureIds.includes( id )  ).map( id => updateItem( 'feature',  id, { releaseId: '' } ) ),
            ...editLinkedBugIds.filter(      id => ! origBIds.has( id )  ).map( id => updateItem( 'bug',      id, { releaseId: item.id } ) ),
            ...[ ...origBIds  ].filter( id => ! editLinkedBugIds.includes( id )      ).map( id => updateItem( 'bug',      id, { releaseId: '' } ) ),
            ...editLinkedFeedbackIds.filter( id => ! origFbIds.has( id ) ).map( id => updateItem( 'feedback', id, { releaseId: item.id } ) ),
            ...[ ...origFbIds ].filter( id => ! editLinkedFeedbackIds.includes( id ) ).map( id => updateItem( 'feedback', id, { releaseId: '' } ) ),
          ] );
        }
      } else {
        // For non-release items, also update the old/new release's linked ID arrays when releaseId changes
        const oldReleaseId = ( item as Feature | SubItem | Bug | Feedback ).releaseId ?? '';
        const newReleaseId = ( editForm.releaseId as string | undefined ) ?? '';

        const mainResult = await updateItem( item.type, item.id, editForm );
        savedItem = mainResult.item;

        if ( window.forgePMData && oldReleaseId !== newReleaseId ) {
          const linkedKey =
            item.type === 'feature'  ? 'linkedFeatureIds'  :
            item.type === 'bug'      ? 'linkedBugIds'      :
            item.type === 'feedback' ? 'linkedFeedbackIds' : null;

          if ( linkedKey ) {
            if ( oldReleaseId ) {
              const oldRelease = releases.find( r => r.id === oldReleaseId );
              if ( oldRelease ) {
                const oldIds = ( oldRelease[linkedKey as keyof Release] as string[] ).filter( id => id !== item.id );
                await updateItem( 'release', oldReleaseId, { [linkedKey]: oldIds } as Partial<Release> );
              }
            }
            if ( newReleaseId ) {
              const newRelease = releases.find( r => r.id === newReleaseId );
              if ( newRelease ) {
                const newIds = [ ...new Set( [ ...( newRelease[linkedKey as keyof Release] as string[] ), item.id ] ) ];
                await updateItem( 'release', newReleaseId, { [linkedKey]: newIds } as Partial<Release> );
              }
            }
          }
        }
      }

      // When release linking changes affect multiple items, fall back to full refetch
      // so all linked items' releaseId fields update in state at once.
      const needsFullRefetch = item.type === 'release' ||
        ( item as Feature | SubItem | Bug | Feedback ).releaseId !== editForm.releaseId;
      if ( needsFullRefetch ) {
        triggerRefresh();
      } else if ( savedItem ) {
        patchItem( String( savedItem.id ), savedItem );
      }
      setIsEditing( false );
    } catch {
      // keep editing open on error
    } finally {
      setIsSaving( false );
    }
  };

  const handleDelete = async () => {
    setIsDeleting( true );
    try {
      await archiveItem( item.type, item.id );
      triggerRefresh();
      closeModal();
    } catch {
      setConfirmDelete( false );
    } finally {
      setIsDeleting( false );
    }
  };

  const timeField = ( key: string ) => (
    isEditing ? (
      <div className="flex items-center gap-1">
        <input type="number" value={ editForm[key] || 0 } onChange={ ( e ) => setEditForm( { ...editForm, [key]: parseInt( e.target.value ) || 0 } ) }
          className="w-20 text-sm font-semibold text-foreground bg-background border border-input rounded px-2 py-0.5 outline-none focus:border-primary focus:ring-1 focus:ring-primary shadow-sm" />
        <span className="text-sm font-medium text-muted-foreground">hours</span>
      </div>
    ) : <div className="text-sm font-semibold text-foreground">{ (item as Record<string, unknown>)[key] } hours</div>
  );

  const descField = ( key: string ) => (
    isEditing ? (
      <textarea value={ editForm[key] || '' } onChange={ ( e ) => setEditForm( { ...editForm, [key]: e.target.value } ) }
        className="w-full text-sm text-foreground p-3 bg-background border border-input rounded-md min-h-[120px] outline-none focus:border-primary focus:ring-1 focus:ring-primary shadow-sm resize-y" placeholder="Add a description..." />
    ) : <p className="text-sm text-foreground/90 leading-relaxed">{ (item as Record<string, unknown>)[key] }</p>
  );

  const imageGrid = ( images: string[] ) => (
    <>
      <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
        { images.map( ( url, index ) => (
          <button key={ index } onClick={ () => { setLightboxIndex( index ); setLightboxOpen( true ); } }
            className="group relative aspect-square rounded-lg overflow-hidden bg-muted border border-border hover:border-primary/40 transition-all hover:shadow-md">
            <img src={ url } alt={ `Attachment ${ index + 1 }` } className="w-full h-full object-cover transition-transform group-hover:scale-105" />
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
              <ImageIcon className="w-6 h-6 text-white opacity-0 group-hover:opacity-100 transition-opacity drop-shadow" />
            </div>
          </button>
        ) ) }
      </div>
      <ImageLightbox images={ images } isOpen={ lightboxOpen } currentIndex={ lightboxIndex } onClose={ () => setLightboxOpen( false ) } onNavigate={ setLightboxIndex } />
    </>
  );

  const renderImagesSection = ( imagesKey: string, label: string ) => {
    const currentImages: string[] = ( editForm[imagesKey] as string[] | undefined ) || ( ( item as Record<string, unknown> )[imagesKey] as string[] | undefined ) || [];
    const hasWPMedia = !! window.wp?.media;

    if ( isEditing ) {
      return (
        <Section title={ <><ImageIcon className="w-4 h-4" /> { label }</> }>
          { editForm[imagesKey]?.length > 0 && (
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mb-3">
              { ( editForm[imagesKey] as string[] ).map( ( url: string, index: number ) => (
                <div key={ index } className="relative aspect-square rounded-lg overflow-hidden bg-muted border border-border group">
                  <img src={ url } alt={ `Attachment ${ index + 1 }` } className="w-full h-full object-cover" />
                  <button onClick={ () => setEditForm( { ...editForm, [imagesKey]: editForm[imagesKey].filter( ( _: string, i: number ) => i !== index ) } ) }
                    className="absolute top-1 right-1 bg-black/60 rounded p-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    <X className="w-3 h-3 text-white" />
                  </button>
                </div>
              ) ) }
            </div>
          ) }
          { hasWPMedia ? (
            <button onClick={ () => openMediaPicker( urls => {
              const existing: string[] = editForm[imagesKey] || [];
              const combined = [ ...existing, ...urls ].filter( ( u, i, arr ) => arr.indexOf( u ) === i );
              setEditForm( { ...editForm, [imagesKey]: combined } );
            } ) }
              className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-foreground bg-background border border-input rounded-lg hover:bg-accent transition-colors">
              <ImageIcon className="w-4 h-4" /> Add from Media Library
            </button>
          ) : (
            <p className="text-sm text-muted-foreground">Image uploads require admin access.</p>
          ) }
        </Section>
      );
    }

    if ( currentImages.length === 0 ) return null;
    return (
      <Section title={ <><ImageIcon className="w-4 h-4" /> { label } ({ currentImages.length })</> }>
        { imageGrid( currentImages ) }
      </Section>
    );
  };

  const renderLinksSection = () => {
    const current: ItemLink[] = ( editForm.links as ItemLink[] | undefined ) ?? ( ( item as { links?: ItemLink[] } ).links ) ?? [];
    if ( isEditing ) {
      return (
        <Section title={ <><ExternalLink className="w-4 h-4" /> Links</> }>
          <LinksEditor links={ current } onChange={ next => setEditForm( { ...editForm, links: next } ) } />
        </Section>
      );
    }
    if ( current.length === 0 ) return null;
    return (
      <Section title={ <><ExternalLink className="w-4 h-4" /> Links ({ current.length })</> }>
        <LinksDisplay links={ current } />
      </Section>
    );
  };

  const linkedBadge = ( colorBase: string, typeLabel: string, text: string ) => (
    <div className={ `flex items-center gap-3 p-3 bg-${ colorBase }-50/50 rounded-lg border border-${ colorBase }-200/50` }>
      <span className={ `px-2 py-0.5 text-xs font-semibold rounded border bg-${ colorBase }-50 text-${ colorBase }-700 border-${ colorBase }-200` }>{ typeLabel }</span>
      <span className="text-sm font-medium text-foreground flex-1">{ text }</span>
    </div>
  );

  const renderBrandsField = ( currentBrands: string[] ) => {
    const available: BrandConfig[] = settings?.brands ?? [];
    if ( isEditing && available.length > 0 ) {
      return (
        <div className="flex flex-wrap gap-2">
          { available.map( brandCfg => {
            const checked = ( editForm.brands || [] ).includes( brandCfg.name );
            return (
              <label key={ brandCfg.name } className="flex items-center gap-1.5 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={ checked }
                  onChange={ e => {
                    const cur = editForm.brands || [];
                    setEditForm( { ...editForm, brands: e.target.checked ? [ ...cur, brandCfg.name ] : cur.filter( ( b: string ) => b !== brandCfg.name ) } );
                  } }
                  className="w-3.5 h-3.5 accent-blue-600"
                />
                { brandCfg.logo && (
                  <img src={ brandCfg.logo } alt={ brandCfg.name } style={{ width: 16, height: 16, objectFit: 'contain', borderRadius: 2 }} onError={ e => { (e.currentTarget as HTMLImageElement).style.display = 'none'; } } />
                ) }
                <span className={ `text-xs font-medium px-2 py-0.5 rounded border ${ checked ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-muted text-muted-foreground border-border' }` }>
                  { brandCfg.name }
                </span>
              </label>
            );
          } ) }
        </div>
      );
    }
    if ( currentBrands.length === 0 ) return <span className="text-sm text-muted-foreground">No brands linked</span>;
    return (
      <div className="flex flex-wrap gap-1.5">
        { currentBrands.map( name => {
          const cfg = available.find( b => b.name === name );
          return (
            <span key={ name } className="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded border bg-blue-50 text-blue-700 border-blue-200">
              { cfg?.logo && <img src={ cfg.logo } alt={ name } style={{ width: 14, height: 14, objectFit: 'contain', borderRadius: 2 }} onError={ e => { (e.currentTarget as HTMLImageElement).style.display = 'none'; } } /> }
              { name }
            </span>
          );
        } ) }
      </div>
    );
  };

  // ── Release picker (for feature / subitem / bug / feedback edit) ─────────────
  const renderReleasePicker = () => {
    const currentRelease = releases.find( r => r.id === editForm.releaseId );
    const filtered = releases.filter( r =>
      ! releaseSearch || r.name.toLowerCase().includes( releaseSearch.toLowerCase() )
    );
    return (
      <Section title={ <><TrendingUp className="w-4 h-4" /> Release</> }>
        { currentRelease ? (
          <div className="flex items-center gap-2 p-2.5 bg-emerald-50 border border-emerald-200 rounded-lg mb-2">
            <span className="text-sm font-medium text-emerald-800 flex-1">{ currentRelease.name }</span>
            <button onClick={ () => { setEditForm( { ...editForm, releaseId: '' } ); setReleaseSearch( '' ); } }
              className="text-emerald-600 hover:text-red-600 transition-colors flex-shrink-0" title="Remove release">
              <X className="w-4 h-4" />
            </button>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground mb-2">No release assigned</p>
        ) }
        <input
          type="text"
          placeholder="Search releases…"
          value={ releaseSearch }
          onChange={ e => setReleaseSearch( e.target.value ) }
          className="w-full text-sm px-3 py-2 border border-input rounded-lg outline-none focus:border-primary focus:ring-1 focus:ring-primary"
        />
        { releaseSearch && (
          <div className="mt-1 border border-input rounded-lg overflow-hidden shadow-md max-h-48 overflow-y-auto">
            { filtered.length === 0 ? (
              <div className="px-3 py-2 text-sm text-muted-foreground">No releases found</div>
            ) : filtered.map( r => (
              <button key={ r.id }
                onClick={ () => { setEditForm( { ...editForm, releaseId: r.id } ); setReleaseSearch( '' ); } }
                className={ `w-full text-left px-3 py-2.5 text-sm hover:bg-accent transition-colors border-b border-border last:border-0 ${ editForm.releaseId === r.id ? 'bg-emerald-50 text-emerald-700 font-medium' : '' }` }>
                { r.name }
              </button>
            ) ) }
          </div>
        ) }
      </Section>
    );
  };

  // ── Item picker (for release edit) ───────────────────────────────────────────
  const renderReleaseItemPicker = () => {
    const allLinkable = [
      ...features.map(  f => ( { id: f.id, type: 'feature'  as const, label: f.name,  hours: f.timeEstimate } ) ),
      ...bugs_.map(      b => ( { id: b.id, type: 'bug'       as const, label: b.title, hours: b.timeEstimate } ) ),
      ...feedback_.map(  f => ( { id: f.id, type: 'feedback'  as const, label: f.title, hours: f.timeEstimate } ) ),
    ];
    const linked = new Set( [ ...editLinkedFeatureIds, ...editLinkedBugIds, ...editLinkedFeedbackIds ] );
    const filtered = itemSearch
      ? allLinkable.filter( i => i.label.toLowerCase().includes( itemSearch.toLowerCase() ) )
      : allLinkable;
    const sorted = [ ...filtered ].sort( ( a, b ) => {
      const aL = linked.has( a.id ), bL = linked.has( b.id );
      if ( aL !== bL ) return aL ? -1 : 1;
      return a.label.localeCompare( b.label );
    } );

    const toggle = ( id: string, type: 'feature' | 'bug' | 'feedback' ) => {
      if ( type === 'feature' )  setEditLinkedFeatureIds(  ids => ids.includes( id ) ? ids.filter( x => x !== id ) : [ ...ids, id ] );
      else if ( type === 'bug' ) setEditLinkedBugIds(      ids => ids.includes( id ) ? ids.filter( x => x !== id ) : [ ...ids, id ] );
      else                       setEditLinkedFeedbackIds( ids => ids.includes( id ) ? ids.filter( x => x !== id ) : [ ...ids, id ] );
    };

    const typeColors: Record<string, string> = {
      feature:  'bg-blue-50 text-blue-700 border-blue-200',
      bug:      'bg-red-50 text-red-700 border-red-200',
      feedback: 'bg-purple-50 text-purple-700 border-purple-200',
    };
    const total = editLinkedFeatureIds.length + editLinkedBugIds.length + editLinkedFeedbackIds.length;

    return (
      <Section title={ <><Link2 className="w-4 h-4" /> Linked Items ({ total })</> }>
        <input
          type="text"
          placeholder="Search all items…"
          value={ itemSearch }
          onChange={ e => setItemSearch( e.target.value ) }
          className="w-full text-sm px-3 py-2 border border-input rounded-lg outline-none focus:border-primary focus:ring-1 focus:ring-primary mb-2"
        />
        <div className="border border-border rounded-lg divide-y divide-border" style={{ maxHeight: 320, overflowY: 'auto' }}>
          { sorted.length === 0 ? (
            <div className="px-3 py-4 text-sm text-muted-foreground text-center">No items found</div>
          ) : sorted.map( it => {
            const isLinked = linked.has( it.id );
            return (
              <div key={ it.id } className={ `flex items-center gap-3 px-3 py-2 transition-colors ${ isLinked ? 'bg-emerald-50/50' : 'hover:bg-accent/50' }` }>
                <span className={ `px-1.5 py-0.5 text-[10px] font-semibold rounded border flex-shrink-0 ${ typeColors[it.type] }` }>{ it.type }</span>
                <span className={ `flex-1 text-sm truncate ${ isLinked ? 'font-medium text-foreground' : 'text-muted-foreground' }` }>{ it.label }</span>
                <span className="text-xs text-muted-foreground flex-shrink-0">{ it.hours }h</span>
                <button onClick={ () => toggle( it.id, it.type ) }
                  className={ `flex-shrink-0 w-6 h-6 flex items-center justify-center rounded transition-colors ${ isLinked ? 'text-red-500 hover:bg-red-50' : 'text-emerald-600 hover:bg-emerald-50' }` }>
                  { isLinked ? <X className="w-3.5 h-3.5" /> : <Plus className="w-3.5 h-3.5" /> }
                </button>
              </div>
            );
          } ) }
        </div>
      </Section>
    );
  };

  const renderFeatureDetails = ( feature: Feature ) => {
    const release  = feature.releaseId ? getItemById( feature.releaseId ) as Release | undefined : undefined;
    const subItems = ( feature.subItemIds || [] ).map( ( id ) => getItemById( id ) ).filter( ( si ): si is SubItem => si?.type === 'subitem' );
    const sortedStageDates = feature.stageDates
      ? Object.entries( feature.stageDates ).sort( ( [, a], [, b] ) => a.localeCompare( b ) )
      : [];
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <MetaCard icon={ <div className="w-2 h-2 rounded-full bg-blue-600" /> } bg="bg-blue-100" label="Category" value={ feature.category } />
          <MetaCard icon={ <Star className="w-4 h-4 text-amber-600" /> } bg="bg-amber-100" label="Feature Price" value={ <span className="capitalize">{ feature.featurePrice }</span> } />
          <MetaCard icon={ <Clock className="w-4 h-4 text-slate-600" /> } bg="bg-slate-100" label="Time Estimate" value={ timeField( 'timeEstimate' ) } />
          <MetaCard icon={ feature.isEnabled ? <CheckCircle className="w-4 h-4 text-green-600" /> : <Circle className="w-4 h-4 text-gray-400" /> } bg={ feature.isEnabled ? 'bg-green-100' : 'bg-gray-100' } label="Status" value={ feature.isEnabled ? 'Enabled' : 'Disabled' } />
          <MetaCard icon={ <BarChart3 className="w-4 h-4 text-indigo-600" /> } bg="bg-indigo-100" label="Stat Tracking" value={ feature.isTrackedAsStat ? 'Yes' : 'No' } />
          { ! isEditing && release && <MetaCard icon={ <TrendingUp className="w-4 h-4 text-green-600" /> } bg="bg-green-100" label="Release" value={ release.name } /> }
        </div>

        { isEditing && renderReleasePicker() }

        <Section title={ <><Tag className="w-4 h-4" /> Brands Offering Feature</> }>
          { renderBrandsField( feature.brands || [] ) }
        </Section>

        <Section title="Description">{ descField( 'description' ) }</Section>

        { renderImagesSection( 'images', 'Attachments' ) }
        { renderLinksSection() }
        { subItems.length > 0 && (
          <Section title={ `Sub-Items (${ subItems.length })` }>
            <div className="space-y-2">
              { subItems.map( ( si ) => (
                <div key={ si.id } className="flex items-center gap-3 p-3 bg-cyan-50/50 rounded-lg border border-cyan-200/50">
                  <span className="px-2 py-0.5 text-xs font-semibold rounded border bg-cyan-50 text-cyan-700 border-cyan-200">sub-item</span>
                  <span className="text-sm font-medium text-foreground flex-1">{ si.name }</span>
                  <span className="text-xs text-muted-foreground">{ si.timeEstimate }h</span>
                </div>
              ) ) }
            </div>
          </Section>
        ) }

        { sortedStageDates.length > 0 && (
          <Section title={ <><History className="w-4 h-4" /> Stage History</> }>
            <div className="space-y-1.5">
              { sortedStageDates.map( ( [ stageId, date ] ) => (
                <div key={ stageId } className="flex items-center gap-3 text-sm">
                  <span className="text-muted-foreground w-28 flex-shrink-0">{ date }</span>
                  <span className="px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700 capitalize">
                    { stageId.split( '-' ).map( w => w.charAt( 0 ).toUpperCase() + w.slice( 1 ) ).join( ' ' ) }
                  </span>
                </div>
              ) ) }
            </div>
          </Section>
        ) }

        { feature.changeLog && ( () => {
          const PAGE_SIZE = 25;
          const entries   = feature.changeLog.split( ' | ' ).filter( Boolean );
          const totalPages = Math.ceil( entries.length / PAGE_SIZE );
          const pageEntries = entries.slice( changeLogPage * PAGE_SIZE, ( changeLogPage + 1 ) * PAGE_SIZE );
          return (
            <Section title={ <><ScrollText className="w-4 h-4" /> Change Log <span className="text-xs font-normal text-muted-foreground">({ entries.length })</span></> }>
              <div className="rounded-lg border border-border overflow-hidden">
                { pageEntries.map( ( entry, i ) => {
                  const dashIdx = entry.indexOf( ' — ' );
                  const body    = dashIdx !== -1 ? entry.slice( 0, dashIdx ) : entry;
                  const user    = dashIdx !== -1 ? entry.slice( dashIdx + 3 ) : null;
                  return (
                    <div key={ i } className={ `flex items-start justify-between gap-4 px-3 py-2 text-xs ${ i % 2 === 0 ? 'bg-muted/40' : 'bg-background' }` }>
                      <span className="text-foreground font-mono leading-relaxed flex-1">{ body }</span>
                      { user && <span className="text-muted-foreground shrink-0 font-medium">{ user }</span> }
                    </div>
                  );
                } ) }
              </div>
              { totalPages > 1 && (
                <div className="flex items-center justify-between pt-2">
                  <button disabled={ changeLogPage === 0 } onClick={ () => setChangeLogPage( p => p - 1 ) }
                    className="px-3 py-1 text-xs font-medium rounded border border-input bg-background hover:bg-accent disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                    ← Prev
                  </button>
                  <span className="text-xs text-muted-foreground">Page { changeLogPage + 1 } of { totalPages }</span>
                  <button disabled={ changeLogPage >= totalPages - 1 } onClick={ () => setChangeLogPage( p => p + 1 ) }
                    className="px-3 py-1 text-xs font-medium rounded border border-input bg-background hover:bg-accent disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                    Next →
                  </button>
                </div>
              ) }
            </Section>
          );
        } )() }
      </>
    );
  };

  const renderSubItemDetails = ( subItem: SubItem ) => {
    const parent  = subItem.parentFeatureId ? getItemById( subItem.parentFeatureId ) as Feature | undefined : undefined;
    const release = subItem.releaseId ? getItemById( subItem.releaseId ) as Release | undefined : undefined;
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          { parent && <div className="col-span-full"><MetaCard icon={ <Link2 className="w-4 h-4 text-blue-600" /> } bg="bg-blue-100" label="Parent Feature" value={ parent.name } /></div> }
          <MetaCard icon={ <div className="w-2 h-2 rounded-full bg-cyan-600" /> } bg="bg-cyan-100" label="Category" value={ subItem.category } />
          <MetaCard icon={ <Star className="w-4 h-4 text-amber-600" /> } bg="bg-amber-100" label="Feature Price" value={ <span className="capitalize">{ subItem.featurePrice }</span> } />
          <MetaCard icon={ <Clock className="w-4 h-4 text-slate-600" /> } bg="bg-slate-100" label="Time Estimate" value={ timeField( 'timeEstimate' ) } />
          { ! isEditing && release && <MetaCard icon={ <TrendingUp className="w-4 h-4 text-green-600" /> } bg="bg-green-100" label="Release" value={ release.name } /> }
        </div>
        { isEditing && renderReleasePicker() }
        { ( subItem.brands || [] ).length > 0 && (
          <Section title={ <><Tag className="w-4 h-4" /> Brands Offering Feature</> }>
            { renderBrandsField( subItem.brands || [] ) }
          </Section>
        ) }
        <Section title="Description">{ descField( 'description' ) }</Section>
        { renderImagesSection( 'images', 'Attachments' ) }
        { renderLinksSection() }
      </>
    );
  };

  const renderBugDetails = ( bug: Bug ) => {
    const linkedFeature  = bug.linkedFeatureId  ? getItemById( bug.linkedFeatureId )  as Feature | undefined  : undefined;
    const linkedSubItem  = bug.linkedSubItemId  ? getItemById( bug.linkedSubItemId )  as SubItem | undefined  : undefined;
    const release = bug.releaseId ? getItemById( bug.releaseId ) as Release | undefined : undefined;
    const priBg = bug.priority === 'high' ? 'bg-orange-100' : bug.priority === 'medium' ? 'bg-yellow-100' : 'bg-slate-100';
    const priColor = bug.priority === 'high' ? 'bg-orange-600' : bug.priority === 'medium' ? 'bg-yellow-600' : 'bg-slate-600';
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <MetaCard icon={ <AlertCircle className="w-4 h-4 text-red-600" /> } bg="bg-red-100" label="Bug Status" value={ <span className="capitalize">{ bug.bugStatus }</span> } />
          <MetaCard icon={ <div className={ `w-2 h-2 rounded-full ${ priColor }` } /> } bg={ priBg } label="Priority" value={ <span className="capitalize">{ bug.priority }</span> } />
          <MetaCard icon={ <Clock className="w-4 h-4 text-slate-600" /> } bg="bg-slate-100" label="Time Estimate" value={ timeField( 'timeEstimate' ) } />
          <MetaCard icon={ <Calendar className="w-4 h-4 text-blue-600" /> } bg="bg-blue-100" label="Reported Date" value={ new Date( bug.reportedDate ).toLocaleDateString( 'en-US', { year: 'numeric', month: 'long', day: 'numeric' } ) } />
        </div>
        <Section title="Description">{ descField( 'description' ) }</Section>
        { bug.notes && <Section title="Notes"><p className="text-sm text-muted-foreground leading-relaxed">{ bug.notes }</p></Section> }
        { renderImagesSection( 'images', 'Screenshots' ) }
        { renderLinksSection() }
        { isEditing && (
          <Section title={ <><Link2 className="w-4 h-4" /> Links up to</> }>
            <select
              value={ editForm.linkedSubItemId ? `s:${ editForm.linkedSubItemId }` : editForm.linkedFeatureId ? `f:${ editForm.linkedFeatureId }` : '' }
              onChange={ e => {
                const v = e.target.value;
                if ( v.startsWith( 's:' ) ) setEditForm( { ...editForm, linkedSubItemId: v.slice( 2 ), linkedFeatureId: '' } );
                else if ( v.startsWith( 'f:' ) ) setEditForm( { ...editForm, linkedFeatureId: v.slice( 2 ), linkedSubItemId: '' } );
                else setEditForm( { ...editForm, linkedFeatureId: '', linkedSubItemId: '' } );
              } }
              className="w-full px-3 py-2 text-sm border border-input rounded-lg bg-background">
              <option value="">— None —</option>
              <optgroup label="Features">
                { [ ...features ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( f => <option key={ f.id } value={ `f:${ f.id }` }>{ f.name }</option> ) }
              </optgroup>
              { subitems.length > 0 && (
                <optgroup label="Sub-Items">
                  { [ ...subitems ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( s => <option key={ s.id } value={ `s:${ s.id }` }>{ s.name }</option> ) }
                </optgroup>
              ) }
            </select>
          </Section>
        ) }
        { isEditing && renderReleasePicker() }
        { ! isEditing && ( linkedFeature || linkedSubItem || release ) && (
          <Section title={ <><Link2 className="w-4 h-4" /> Linked Items</> }>
            <div className="space-y-2">
              { linkedFeature  && linkedBadge( 'blue',  'feature',  linkedFeature.name ) }
              { linkedSubItem  && linkedBadge( 'cyan',  'sub-item', linkedSubItem.name ) }
              { release        && linkedBadge( 'green', 'release',  release.name ) }
            </div>
          </Section>
        ) }
      </>
    );
  };

  const renderFeedbackDetails = ( feedback: Feedback ) => {
    const linkedFeature = feedback.linkedFeatureId ? getItemById( feedback.linkedFeatureId ) as Feature | undefined : undefined;
    const linkedSubItem = feedback.linkedSubItemId ? getItemById( feedback.linkedSubItemId ) as SubItem | undefined : undefined;
    const linkedBug     = feedback.linkedBugId     ? getItemById( feedback.linkedBugId )     as Bug     | undefined : undefined;
    const release       = feedback.releaseId       ? getItemById( feedback.releaseId )       as Release | undefined : undefined;
    const priBg = feedback.priority === 'high' ? 'bg-orange-100' : feedback.priority === 'medium' ? 'bg-yellow-100' : 'bg-slate-100';
    const priColor = feedback.priority === 'high' ? 'bg-orange-600' : feedback.priority === 'medium' ? 'bg-yellow-600' : 'bg-slate-600';
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <MetaCard icon={ <div className="w-2 h-2 rounded-full bg-purple-600" /> } bg="bg-purple-100" label="Status" value={ <span className="capitalize">{ feedback.status }</span> } />
          <MetaCard icon={ <div className={ `w-2 h-2 rounded-full ${ priColor }` } /> } bg={ priBg } label="Priority" value={ <span className="capitalize">{ feedback.priority }</span> } />
          <MetaCard icon={ <Clock className="w-4 h-4 text-slate-600" /> } bg="bg-slate-100" label="Time Estimate" value={ timeField( 'timeEstimate' ) } />
          <MetaCard icon={ <Calendar className="w-4 h-4 text-blue-600" /> } bg="bg-blue-100" label="Reported Date" value={ new Date( feedback.reportedDate ).toLocaleDateString( 'en-US', { year: 'numeric', month: 'long', day: 'numeric' } ) } />
        </div>
        <Section title="Description">{ descField( 'description' ) }</Section>
        { feedback.notes && <Section title="Notes"><p className="text-sm text-muted-foreground leading-relaxed">{ feedback.notes }</p></Section> }
        { renderImagesSection( 'images', 'Attachments' ) }
        { renderLinksSection() }
        { isEditing && (
          <Section title={ <><Link2 className="w-4 h-4" /> Links up to</> }>
            <select
              value={ editForm.linkedSubItemId ? `s:${ editForm.linkedSubItemId }` : editForm.linkedFeatureId ? `f:${ editForm.linkedFeatureId }` : '' }
              onChange={ e => {
                const v = e.target.value;
                if ( v.startsWith( 's:' ) ) setEditForm( { ...editForm, linkedSubItemId: v.slice( 2 ), linkedFeatureId: '' } );
                else if ( v.startsWith( 'f:' ) ) setEditForm( { ...editForm, linkedFeatureId: v.slice( 2 ), linkedSubItemId: '' } );
                else setEditForm( { ...editForm, linkedFeatureId: '', linkedSubItemId: '' } );
              } }
              className="w-full px-3 py-2 text-sm border border-input rounded-lg bg-background">
              <option value="">— None —</option>
              <optgroup label="Features">
                { [ ...features ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( f => <option key={ f.id } value={ `f:${ f.id }` }>{ f.name }</option> ) }
              </optgroup>
              { subitems.length > 0 && (
                <optgroup label="Sub-Items">
                  { [ ...subitems ].sort( ( a, b ) => a.name.localeCompare( b.name ) ).map( s => <option key={ s.id } value={ `s:${ s.id }` }>{ s.name }</option> ) }
                </optgroup>
              ) }
            </select>
          </Section>
        ) }
        { isEditing && renderReleasePicker() }
        { ! isEditing && ( linkedFeature || linkedSubItem || linkedBug || release ) && (
          <Section title={ <><Link2 className="w-4 h-4" /> Linked Items</> }>
            <div className="space-y-2">
              { linkedFeature  && linkedBadge( 'blue',  'feature',  linkedFeature.name ) }
              { linkedSubItem  && linkedBadge( 'cyan',  'sub-item', linkedSubItem.name ) }
              { linkedBug      && linkedBadge( 'red',   'bug',      linkedBug.title ) }
              { release        && linkedBadge( 'green', 'release',  release.name ) }
            </div>
          </Section>
        ) }
      </>
    );
  };

  const renderReleaseDetails = ( release: Release ) => {
    const isOverCapacity = release.totalTimeEstimate > release.capacity;

    // Union both arrays and items' own releaseId for display
    const featureIds  = new Set( [ ...release.linkedFeatureIds,  ...features.filter(  f => f.releaseId === release.id ).map( f => f.id ) ] );
    const bugIds      = new Set( [ ...release.linkedBugIds,      ...bugs_.filter(      b => b.releaseId === release.id ).map( b => b.id ) ] );
    const feedbackIds = new Set( [ ...release.linkedFeedbackIds, ...feedback_.filter(  f => f.releaseId === release.id ).map( f => f.id ) ] );
    const linkedFeatures = Array.from( featureIds  ).map( id => getItemById( id ) as Feature  ).filter( Boolean );
    const linkedBugs     = Array.from( bugIds      ).map( id => getItemById( id ) as Bug      ).filter( Boolean );
    const linkedFeedback = Array.from( feedbackIds ).map( id => getItemById( id ) as Feedback ).filter( Boolean );

    return (
      <>
        {/* Time & Capacity — always at top */}
        <div className="flex items-start gap-3 p-4 rounded-xl bg-slate-50 border border-slate-200">
          <div className="w-8 h-8 p-2 rounded-lg bg-slate-100 flex items-center justify-center flex-shrink-0"><Clock className="w-4 h-4 text-slate-600" /></div>
          <div className="flex-1">
            <div className="text-xs font-medium text-muted-foreground mb-0.5">Time &amp; Capacity</div>
            <div className="text-sm font-semibold text-foreground mb-2">
              { release.totalTimeEstimate } / { release.capacity } hours
              { isOverCapacity && <span className="ml-2 text-xs font-medium text-orange-600">({ release.totalTimeEstimate - release.capacity }h over)</span> }
            </div>
            <div className="w-full h-2 bg-muted rounded-full overflow-hidden">
              <div className={ `h-full transition-all ${ isOverCapacity ? 'bg-orange-500' : 'bg-green-500' }` } style={ { width: `${ Math.min( ( release.totalTimeEstimate / ( release.capacity || 1 ) ) * 100, 100 ) }%` } } />
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <MetaCard icon={ <Calendar className="w-4 h-4 text-green-600" /> } bg="bg-green-100" label="Quarter" value={ release.quarter } />
          <MetaCard icon={ <TrendingUp className="w-4 h-4 text-blue-600" /> } bg="bg-blue-100" label="Status" value={ <span className="capitalize">{ release.status }</span> } />
          { release.isBigWedgeCampaign && <MetaCard icon={ <Star className="w-4 h-4 text-violet-600" /> } bg="bg-violet-100" label="Campaign Type" value={ <span className="text-violet-700">Big Wedge Campaign</span> } colSpan /> }
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 p-2 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0"><Calendar className="w-4 h-4 text-emerald-600" /></div>
            <div>
              <div className="text-xs font-medium text-muted-foreground mb-0.5">Start Week</div>
              { isEditing ? (
                <input type="date" value={ editForm.startWeek || '' } onChange={ ( e ) => setEditForm( { ...editForm, startWeek: e.target.value } ) }
                  className="text-sm font-semibold text-foreground bg-background border border-input rounded px-2 py-0.5 outline-none focus:border-primary focus:ring-1 focus:ring-primary shadow-sm" />
              ) : (
                <div className="text-sm font-semibold text-foreground">{ new Date( release.startWeek ).toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } ) }</div>
              ) }
            </div>
          </div>
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 p-2 rounded-lg bg-rose-100 flex items-center justify-center flex-shrink-0"><Calendar className="w-4 h-4 text-rose-600" /></div>
            <div>
              <div className="text-xs font-medium text-muted-foreground mb-0.5">End Week</div>
              { isEditing ? (
                <input type="date" value={ editForm.endWeek || '' } onChange={ ( e ) => setEditForm( { ...editForm, endWeek: e.target.value } ) }
                  className="text-sm font-semibold text-foreground bg-background border border-input rounded px-2 py-0.5 outline-none focus:border-primary focus:ring-1 focus:ring-primary shadow-sm" />
              ) : (
                <div className="text-sm font-semibold text-foreground">{ new Date( release.endWeek ).toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } ) }</div>
              ) }
            </div>
          </div>
        </div>

        { isEditing ? renderReleaseItemPicker() : (
          <>
            { linkedFeatures.length > 0 && (
              <Section title={ `Linked Features (${ linkedFeatures.length })` }>
                <div className="space-y-2">{ linkedFeatures.map( ( f ) => <div key={ f.id } className="flex items-center gap-3 p-3 bg-blue-50/50 rounded-lg border border-blue-200/50"><span className="px-2 py-0.5 text-xs font-semibold rounded border bg-blue-50 text-blue-700 border-blue-200">feature</span><span className="text-sm font-medium text-foreground flex-1">{ f.name }</span><span className="text-xs text-muted-foreground">{ f.timeEstimate }h</span></div> ) }</div>
              </Section>
            ) }
            { linkedBugs.length > 0 && (
              <Section title={ `Linked Bugs (${ linkedBugs.length })` }>
                <div className="space-y-2">{ linkedBugs.map( ( b ) => <div key={ b.id } className="flex items-center gap-3 p-3 bg-red-50/50 rounded-lg border border-red-200/50"><span className="px-2 py-0.5 text-xs font-semibold rounded border bg-red-50 text-red-700 border-red-200">bug</span><span className="text-sm font-medium text-foreground flex-1">{ b.title }</span><span className="text-xs text-muted-foreground">{ b.timeEstimate }h</span></div> ) }</div>
              </Section>
            ) }
            { linkedFeedback.length > 0 && (
              <Section title={ `Linked Feedback (${ linkedFeedback.length })` }>
                <div className="space-y-2">{ linkedFeedback.map( ( f ) => <div key={ f.id } className="flex items-center gap-3 p-3 bg-purple-50/50 rounded-lg border border-purple-200/50"><span className="px-2 py-0.5 text-xs font-semibold rounded border bg-purple-50 text-purple-700 border-purple-200">feedback</span><span className="text-sm font-medium text-foreground flex-1">{ f.title }</span><span className="text-xs text-muted-foreground">{ f.timeEstimate }h</span></div> ) }</div>
              </Section>
            ) }
          </>
        ) }
        { renderLinksSection() }
      </>
    );
  };

  const itemTitle = 'name' in item ? item.name : ( item as Bug | Feedback ).title;

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 md:p-6 lg:p-8">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={ closeModal } />
      <div className="relative bg-background rounded-t-xl sm:rounded-xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col border border-border">
        {/* Header */}
        <div className="flex items-start justify-between p-5 sm:p-6 border-b border-border bg-gradient-to-b from-muted/30 to-background">
          <div className="flex-1 min-w-0 pr-4">
            <div className="flex items-center gap-2 mb-3 flex-wrap">
              <span className={ `px-2.5 py-1 text-xs font-semibold rounded border ${ typeStyle.bg } ${ typeStyle.text } ${ typeStyle.border }` }>{ item.type }</span>
              { 'workflowStage' in item && (
                <span className="px-2.5 py-1 text-xs font-medium rounded border bg-slate-100 text-slate-700 border-slate-300">
                  { item.workflowStage.split( '-' ).map( ( w ) => w.charAt( 0 ).toUpperCase() + w.slice( 1 ) ).join( ' ' ) }
                </span>
              ) }
            </div>
            { isEditing ? (
              <input autoFocus value={ editForm.name || editForm.title || '' }
                onChange={ ( e ) => {
                  const key = 'name' in editForm ? 'name' : 'title';
                  setEditForm( { ...editForm, [key]: e.target.value } );
                } }
                className="text-lg sm:text-xl font-bold text-foreground bg-background border border-primary/50 outline-none focus:ring-1 focus:ring-primary px-2 py-1 w-full rounded-md shadow-sm" />
            ) : (
              <h2 className="text-lg sm:text-xl font-bold text-foreground">{ itemTitle }</h2>
            ) }
          </div>
          <button onClick={ closeModal } className="p-2 hover:bg-accent rounded-lg transition-colors flex-shrink-0">
            <X className="w-5 h-5 text-muted-foreground" />
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-5 sm:p-6 space-y-6">
          { item.type === 'feature'  && renderFeatureDetails( item as Feature ) }
          { item.type === 'subitem'  && renderSubItemDetails( item as SubItem ) }
          { item.type === 'bug'      && renderBugDetails( item as Bug ) }
          { item.type === 'feedback' && renderFeedbackDetails( item as Feedback ) }
          { item.type === 'release'  && renderReleaseDetails( item as Release ) }
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between gap-3 p-5 sm:p-6 border-t border-border bg-muted/20">
          { isEditing ? (
            <>
              <div />
              <div className="flex items-center gap-3">
                <button onClick={ () => { setIsEditing( false ); setEditForm( { ...item } ); } } className="px-4 py-2 text-sm font-medium text-foreground hover:bg-accent rounded-lg transition-colors">Cancel</button>
                <button onClick={ handleSave } disabled={ isSaving } className="px-4 py-2 text-sm font-medium bg-green-600 text-white hover:bg-green-700 rounded-lg transition-colors flex items-center gap-2 shadow-sm disabled:opacity-60">
                  <CheckCircle className="w-4 h-4" />{ isSaving ? 'Saving...' : 'Save Changes' }
                </button>
              </div>
            </>
          ) : (
            <>
              { adminMode ? (
                <button onClick={ () => setConfirmDelete( true ) }
                  className="px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 border border-red-200 rounded-lg transition-colors flex items-center gap-1.5">
                  <Trash2 className="w-4 h-4" />Archive
                </button>
              ) : <div /> }
              <div className="flex items-center gap-3">
                <button onClick={ closeModal } className="px-4 py-2 text-sm font-medium text-foreground hover:bg-accent rounded-lg transition-colors">Close</button>
                { adminMode && (
                  <button onClick={ () => setIsEditing( true ) } className="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm rounded-lg transition-colors">
                    Edit { item.type === 'feature' ? 'Feature' : item.type === 'subitem' ? 'Sub-Item' : item.type === 'bug' ? 'Bug' : item.type === 'feedback' ? 'Feedback' : 'Release' }
                  </button>
                ) }
              </div>
            </>
          ) }
        </div>
      </div>

      {/* Delete confirmation dialog */}
      { confirmDelete && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={ () => setConfirmDelete( false ) } />
          <div className="relative bg-background rounded-xl shadow-2xl w-full max-w-sm border border-border p-6 flex flex-col gap-4">
            <div className="flex items-start gap-3">
              <div className="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                <Trash2 className="w-5 h-5 text-red-600" />
              </div>
              <div>
                <h3 className="text-base font-semibold text-foreground">Move to Archive?</h3>
                <p className="text-sm text-muted-foreground mt-1">
                  <span className="font-medium text-foreground">{ itemTitle }</span> will be moved to the archive. You can restore it from there later.
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-3">
              <button onClick={ () => setConfirmDelete( false ) } disabled={ isDeleting }
                className="px-4 py-2 text-sm font-medium text-foreground hover:bg-accent rounded-lg transition-colors disabled:opacity-50">
                Cancel
              </button>
              <button onClick={ handleDelete } disabled={ isDeleting }
                className="px-4 py-2 text-sm font-medium bg-red-600 text-white hover:bg-red-700 rounded-lg transition-colors flex items-center gap-2 shadow-sm disabled:opacity-60">
                <Trash2 className="w-4 h-4" />{ isDeleting ? 'Archiving...' : 'Yes, Archive' }
              </button>
            </div>
          </div>
        </div>
      ) }
    </div>
  );
}

function MetaCard( { icon, bg, label, value, colSpan }: { icon: React.ReactNode; bg: string; label: string; value: React.ReactNode; colSpan?: boolean } ) {
  return (
    <div className={ `flex items-start gap-3 ${ colSpan ? 'col-span-full' : '' }` }>
      <div className={ `w-8 h-8 p-2 rounded-lg ${ bg } flex items-center justify-center flex-shrink-0` }>{ icon }</div>
      <div><div className="text-xs font-medium text-muted-foreground mb-0.5">{ label }</div><div className="text-sm font-semibold text-foreground">{ value }</div></div>
    </div>
  );
}

function Section( { title, children }: { title: React.ReactNode; children: React.ReactNode } ) {
  return (
    <div>
      <h3 className="text-sm font-semibold text-foreground mb-2.5 flex items-center gap-2">{ title }</h3>
      { children }
    </div>
  );
}
