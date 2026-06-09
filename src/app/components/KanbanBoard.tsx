import { useState, useMemo, useRef, useEffect } from 'react';
import { useShallow } from 'zustand/react/shallow';
import { DndProvider, useDrag, useDrop } from 'react-dnd';
import { HTML5Backend } from 'react-dnd-html5-backend';
import { Save, Check, AlertCircle, X, Plus, GripVertical } from 'lucide-react';
import { ItemCard } from './ItemCard';
import { WorkflowStage, Item, AppSettings, Release } from '../types';
import { useDragScroll } from '../hooks/useDragScroll';
import { useIsMobile } from '../hooks/useIsMobile';
import { updateStage, canEdit } from '../api/wordpress';
import { AddItemModal } from './AddItemModal';
import { useDataStore, selectAllItems } from '../store/useDataStore';
import { useUIStore } from '../store/useUIStore';
import { buildNestedGroups } from '../utils/nesting';
import { sortItemsByName } from '../utils/sortItems';
import { matchesFilters } from '../utils/filters';
import { FilterButton, ShareButton } from './ViewActions';

interface KanbanBoardProps {
  settings: AppSettings;
}

// ── Drag item shapes ──────────────────────────────────────────────────────────
type ItemDragPayload = { kind: 'ITEM'; id: string; type: string; workflowStage?: WorkflowStage };
type GroupDragPayload = { kind: 'RELEASE_GROUP'; releaseId: string; stage: WorkflowStage; items: { id: string; type: string }[] };
type AnyDragPayload = ItemDragPayload | GroupDragPayload;

// ── DraggableCard ─────────────────────────────────────────────────────────────
function DraggableCard( { item, isEditMode, onClick }: { item: Item; isEditMode: boolean; onClick: () => void } ) {
  const [{ isDragging }, drag] = useDrag<ItemDragPayload, unknown, { isDragging: boolean }>( {
    type: 'ITEM',
    item: () => {
      const workflowStage = 'workflowStage' in item ? item.workflowStage : undefined;
      return { kind: 'ITEM', id: item.id, type: item.type, workflowStage };
    },
    canDrag: isEditMode,
    collect: ( monitor ) => ( { isDragging: monitor.isDragging() } ),
  } );

  return (
    <div ref={ drag } style={ { opacity: isDragging ? 0.5 : 1 } } className={ isEditMode ? 'cursor-grab active:cursor-grabbing' : '' }>
      <ItemCard item={ item } onClick={ onClick } showDragHandle={ isEditMode } />
    </div>
  );
}

// ── DraggableReleaseGroup ─────────────────────────────────────────────────────
interface ReleaseGroupProps {
  rId: string;
  releaseName: string;
  groupItems: Item[];
  nestedChildren: Record<string, Item[]>;
  isEditMode: boolean;
  onItemClick: ( item: Item ) => void;
  currentStage: WorkflowStage;
}

function DraggableReleaseGroup( { rId, releaseName, groupItems, nestedChildren, isEditMode, onItemClick, currentStage }: ReleaseGroupProps ) {
  const canDragGroup = isEditMode && rId !== 'unassigned';

  const [{ isDragging }, drag] = useDrag<GroupDragPayload, unknown, { isDragging: boolean }>( {
    type: 'RELEASE_GROUP',
    item: () => ( { kind: 'RELEASE_GROUP', releaseId: rId, stage: currentStage, items: groupItems.map( i => ( { id: i.id, type: i.type } ) ) } ),
    canDrag: canDragGroup,
    collect: ( monitor ) => ( { isDragging: monitor.isDragging() } ),
  } );

  return (
    <div
      ref={ drag }
      style={ { opacity: isDragging ? 0.45 : 1 } }
      className="flex flex-col rounded-xl border border-border shadow-sm bg-background/50 overflow-hidden"
    >
      <div
        className="flex items-center gap-2 px-3 py-2 border-b border-border bg-muted/30"
        style={ { cursor: canDragGroup ? 'grab' : 'default' } }
        title={ canDragGroup ? 'Drag to move entire release group to another column' : undefined }
      >
        { canDragGroup && <GripVertical size={ 13 } className="text-muted-foreground/50 flex-shrink-0" /> }
        <span className="text-[11px] font-bold text-muted-foreground uppercase tracking-wider flex-1">{ releaseName }</span>
        <span className="text-[10px] text-muted-foreground/60">{ groupItems.length } item{ groupItems.length !== 1 ? 's' : '' }</span>
      </div>
      <div className="flex flex-col gap-3 p-2 bg-muted/10">
        { groupItems.map( ( item ) => (
          <div key={ item.id } className="relative">
            <DraggableCard item={ item } isEditMode={ isEditMode } onClick={ () => onItemClick( item ) } />
            { nestedChildren[item.id] && nestedChildren[item.id].length > 0 && (
              <div className="mt-2 ml-4 pl-3 border-l-2 border-primary/20 flex flex-col gap-2">
                { nestedChildren[item.id].map( ( child ) => (
                  <DraggableCard key={ child.id } item={ child } isEditMode={ isEditMode } onClick={ () => onItemClick( child ) } />
                ) ) }
              </div>
            ) }
          </div>
        ) ) }
      </div>
    </div>
  );
}

// ── KanbanColumn ──────────────────────────────────────────────────────────────
interface ColumnProps {
  stage: WorkflowStage;
  label: string;
  items: Item[];
  isEditMode: boolean;
  onItemClick: ( item: Item ) => void;
  onDrop: ( itemId: string, itemType: string, newStage: WorkflowStage ) => void;
  onGroupDrop: ( items: { id: string; type: string }[], newStage: WorkflowStage ) => void;
  releases: Release[];
  isMobile?: boolean;
}

function KanbanColumn( { stage, label, items, isEditMode, onItemClick, onDrop, onGroupDrop, releases, isMobile }: ColumnProps ) {
  const isBugTrack = stage === 'bug-tracking';

  const [{ isOver }, drop] = useDrop<AnyDragPayload, unknown, { isOver: boolean }>( {
    accept: ['ITEM', 'RELEASE_GROUP'],
    drop: ( dragged ) => {
      if ( dragged.kind === 'RELEASE_GROUP' ) {
        if ( dragged.stage !== stage ) onGroupDrop( dragged.items, stage );
      } else {
        if ( dragged.workflowStage !== stage ) onDrop( dragged.id, dragged.type, stage );
      }
    },
    collect: ( monitor ) => ( { isOver: monitor.isOver() } ),
  } );

  // Build nested structure
  const { nestedChildren, topLevelItems } = buildNestedGroups( items );

  // Group by release — treat items whose release no longer exists as unassigned
  const itemsByRelease: Record<string, Item[]> = {};
  topLevelItems.forEach( ( item ) => {
    const rawId = 'releaseId' in item ? item.releaseId : null;
    const exists = rawId && releases.find( ( r ) => r.id === rawId );
    const rId = exists ? rawId! : 'unassigned';
    if ( ! itemsByRelease[rId] ) itemsByRelease[rId] = [];
    itemsByRelease[rId].push( item );
  } );
  // Order items alphabetically within each release group (#28)
  Object.keys( itemsByRelease ).forEach( id => { itemsByRelease[id] = sortItemsByName( itemsByRelease[id] ); } );

  const releaseGroups = Object.keys( itemsByRelease ).sort( ( a, b ) => {
    if ( a === 'unassigned' ) return 1;
    if ( b === 'unassigned' ) return -1;
    return a.localeCompare( b );
  } );

  return (
    <div style={ {
      display: 'flex', flexDirection: 'column',
      width: isMobile ? '100%' : 340, minWidth: isMobile ? 0 : 340, flexShrink: 0,
      backgroundColor: '#ffffff',
      borderRadius: 8,
      boxShadow: isBugTrack ? '0 1px 3px rgba(239,68,68,0.15)' : '0 1px 3px rgba(0,0,0,0.07)',
      border: isBugTrack ? '2px solid #fca5a5' : '1px solid #e2e8f0',
    } }>
      {/* Column header */}
      <div style={ {
        display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        padding: '12px 16px',
        borderBottom: isBugTrack ? '2px solid #ef4444' : '2px solid #e2e8f0',
        background: isBugTrack ? 'linear-gradient(to bottom, #fff1f2, #ffe4e6)' : 'linear-gradient(to bottom, #f8fafc, #f1f5f9)',
        borderRadius: '8px 8px 0 0',
        flexShrink: 0,
        position: 'sticky', top: 0, zIndex: 2,
      } }>
        <div style={ { display: 'flex', alignItems: 'center', gap: 10 } }>
          <h3 style={ { fontSize: 13, fontWeight: 700, color: '#1a1f36', margin: 0 } }>{ label }</h3>
          <span style={ { padding: '2px 10px', fontSize: 11, fontWeight: 700, borderRadius: 99, backgroundColor: '#2563eb', color: '#ffffff', boxShadow: '0 1px 2px rgba(0,0,0,0.1)' } }>{ items.length }</span>
        </div>
      </div>

      {/* Column body — no per-column scroll; board scrolls as unit */}
      <div
        ref={ drop }
        style={ {
          flex: 1,
          minHeight: isMobile ? 'auto' : 'calc(200vh - 160px)',
          padding: 12,
          transition: 'background-color 0.15s',
          backgroundColor: isOver && isEditMode ? '#eff6ff' : '#f8fafc',
          boxShadow: isOver && isEditMode ? 'inset 0 0 0 2px rgba(37,99,235,0.2)' : 'none',
        } }
      >
        { items.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-full text-center py-16">
            <div className="w-20 h-20 rounded-2xl bg-muted/70 flex items-center justify-center mb-4">
              <div className="w-10 h-10 rounded-xl bg-muted/70" />
            </div>
            <p className="text-sm font-medium text-muted-foreground">No items</p>
            <p className="text-xs text-muted-foreground mt-1.5 max-w-[180px]">
              { isEditMode ? 'Drag items here to add them to this stage' : 'Items will appear here as they move through the workflow' }
            </p>
          </div>
        ) : (
          <div className="flex flex-col gap-5">
            { releaseGroups.map( ( rId ) => {
              const groupItems = itemsByRelease[rId];
              const releaseName = rId === 'unassigned' ? 'Unassigned' : releases.find( ( r ) => r.id === rId )?.name ?? 'Unassigned';
              return (
                <DraggableReleaseGroup
                  key={ rId }
                  rId={ rId }
                  releaseName={ releaseName }
                  groupItems={ groupItems }
                  nestedChildren={ nestedChildren }
                  isEditMode={ isEditMode }
                  onItemClick={ onItemClick }
                  currentStage={ stage }
                />
              );
            } ) }
          </div>
        ) }
      </div>
    </div>
  );
}

// ── MobileKanbanColumn ────────────────────────────────────────────────────────
// Renders one column without any DnD hooks — safe outside a DndProvider.
function MobileKanbanColumn( { items, onItemClick, releases }: {
  items: Item[];
  onItemClick: ( item: Item ) => void;
  releases: Release[];
} ) {
  const { nestedChildren, topLevelItems } = buildNestedGroups( items );

  const itemsByRelease: Record<string, Item[]> = {};
  topLevelItems.forEach( ( item ) => {
    const rawId = 'releaseId' in item ? item.releaseId : null;
    const exists = rawId && releases.find( ( r ) => r.id === rawId );
    const rId = exists ? rawId! : 'unassigned';
    if ( ! itemsByRelease[rId] ) itemsByRelease[rId] = [];
    itemsByRelease[rId].push( item );
  } );
  // Order items alphabetically within each release group (#28)
  Object.keys( itemsByRelease ).forEach( id => { itemsByRelease[id] = sortItemsByName( itemsByRelease[id] ); } );

  const releaseGroups = Object.keys( itemsByRelease ).sort( ( a, b ) => {
    if ( a === 'unassigned' ) return 1;
    if ( b === 'unassigned' ) return -1;
    return a.localeCompare( b );
  } );

  if ( items.length === 0 ) {
    return (
      <div style={ { flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '48px 16px', textAlign: 'center' } }>
        <p style={ { fontSize: 14, fontWeight: 500, color: '#64748b', margin: 0 } }>No items</p>
        <p style={ { fontSize: 12, color: '#94a3b8', marginTop: 6, maxWidth: 200 } }>Items will appear here as they move through the workflow</p>
      </div>
    );
  }

  return (
    <div style={ { flex: 1, overflow: 'auto', padding: 12 } }>
      <div className="flex flex-col gap-5">
        { releaseGroups.map( ( rId ) => {
          const groupItems = itemsByRelease[rId];
          const releaseName = rId === 'unassigned' ? 'Unassigned' : releases.find( ( r ) => r.id === rId )?.name ?? 'Unassigned';
          return (
            <div key={ rId } className="flex flex-col rounded-xl border border-border shadow-sm bg-background/50 overflow-hidden">
              <div className="flex items-center gap-2 px-3 py-2 border-b border-border bg-muted/30">
                <span className="text-[11px] font-bold text-muted-foreground uppercase tracking-wider flex-1">{ releaseName }</span>
                <span className="text-[10px] text-muted-foreground/60">{ groupItems.length } item{ groupItems.length !== 1 ? 's' : '' }</span>
              </div>
              <div className="flex flex-col gap-3 p-2 bg-muted/10">
                { groupItems.map( ( item ) => (
                  <div key={ item.id } className="relative">
                    <ItemCard item={ item } onClick={ () => onItemClick( item ) } />
                    { nestedChildren[item.id] && nestedChildren[item.id].length > 0 && (
                      <div className="mt-2 ml-4 pl-3 border-l-2 border-primary/20 flex flex-col gap-2">
                        { nestedChildren[item.id].map( ( child ) => (
                          <ItemCard key={ child.id } item={ child } onClick={ () => onItemClick( child ) } />
                        ) ) }
                      </div>
                    ) }
                  </div>
                ) ) }
              </div>
            </div>
          );
        } ) }
      </div>
    </div>
  );
}

// ── KanbanBoard ───────────────────────────────────────────────────────────────
export function KanbanBoard( { settings }: KanbanBoardProps ) {
  const storeAllItems  = useDataStore( useShallow( selectAllItems ) );
  const releases       = useDataStore( s => s.releases );
  const triggerRefresh = useDataStore( s => s.triggerRefresh );
  const openModal      = useUIStore( s => s.openModal );
  const filters        = useUIStore( s => s.filters );
  const adminMode      = canEdit();
  const isMobile = useIsMobile( 768 );
  const scrollRef = useDragScroll<HTMLDivElement>( { axis: 'x' } );
  const [isEditMode, setIsEditMode] = useState( false );
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'success' | 'error'>( 'idle' );
  const [pendingStages, setPendingStages] = useState<{ id: string; type: string; stage: WorkflowStage }[]>( [] );
  const [addItemOpen, setAddItemOpen] = useState( false );
  const [activeColIdx, setActiveColIdx] = useState( 0 );
  // Mobile: swipeable column carousel kept in sync with the tab strip (#15).
  // useDragScroll adds click-and-drag (mouse) on top of native touch/snap.
  const mobileScrollRef = useDragScroll<HTMLDivElement>( { axis: 'x' } );
  const tabStripRef     = useRef<HTMLDivElement>( null );

  // Swiping the columns updates the active tab…
  const handleMobileScroll = () => {
    const el = mobileScrollRef.current;
    if ( ! el || el.clientWidth === 0 ) return;
    const idx = Math.round( el.scrollLeft / el.clientWidth );
    setActiveColIdx( prev => prev === idx ? prev : idx );
  };

  // …and tapping a tab scrolls the columns to match.
  const goToColumn = ( idx: number ) => {
    setActiveColIdx( idx );
    const el = mobileScrollRef.current;
    if ( el ) el.scrollTo( { left: idx * el.clientWidth, behavior: 'smooth' } );
  };

  // Keep the active tab visible in the strip as the carousel moves.
  useEffect( () => {
    const btn = tabStripRef.current?.children[ activeColIdx ] as HTMLElement | undefined;
    btn?.scrollIntoView( { inline: 'center', block: 'nearest', behavior: 'smooth' } );
  }, [ activeColIdx ] );

  const localItems = useMemo( () => {
    if ( pendingStages.length === 0 ) return storeAllItems;
    return storeAllItems.map( item => {
      const pending = pendingStages.find( p => p.id === item.id );
      if ( pending && 'workflowStage' in item ) return { ...item, workflowStage: pending.stage };
      return item;
    } );
  }, [storeAllItems, pendingStages] );

  const handleDrop = ( itemId: string, itemType: string, newStage: WorkflowStage ) => {
    setPendingStages( ( prev ) => {
      const filtered = prev.filter( ( p ) => p.id !== itemId );
      return [...filtered, { id: itemId, type: itemType, stage: newStage }];
    } );
  };

  const handleGroupDrop = ( draggedItems: { id: string; type: string }[], newStage: WorkflowStage ) => {
    const ids = new Set( draggedItems.map( i => i.id ) );
    setPendingStages( ( prev ) => {
      const filtered = prev.filter( ( p ) => !ids.has( p.id ) );
      const added = draggedItems.map( ( { id, type } ) => ( { id, type, stage: newStage } ) );
      return [...filtered, ...added];
    } );
  };

  const handleSave = async () => {
    setSaveState( 'saving' );
    try {
      await Promise.all(
        pendingStages.map( ( { id, type, stage } ) => updateStage( type, id, stage ) )
      );
      setSaveState( 'success' );
      setPendingStages( [] );
      setTimeout( () => {
        setSaveState( 'idle' );
        setIsEditMode( false );
        triggerRefresh();
      }, 1500 );
    } catch {
      setSaveState( 'error' );
    }
  };

  const handleCancel = () => {
    setIsEditMode( false );
    setSaveState( 'idle' );
    setPendingStages( [] );
  };

  const itemsWithWorkflow = localItems.filter( ( item ) => 'workflowStage' in item && matchesFilters( item, filters ) );
  const columns = settings.statuses.length > 0 ? settings.statuses : [
    { id: 'bug-tracking', label: 'Bug Tracking' }, { id: 'future-idea', label: 'Future Idea' },
    { id: 'up-next', label: 'Up Next' }, { id: 'in-development', label: 'In Development' },
  ];

  // ── Mobile: single-column tab view, no DnD ──────────────────────────────────
  if ( isMobile ) {
    const safeIdx = Math.min( activeColIdx, columns.length - 1 );

    return (
      <>
        <div style={ { display: 'flex', flexDirection: 'column', height: '100%' } }>
          {/* Top bar — shared header spec (issue #16) */}
          <div className="flex items-center justify-between gap-2 px-4 border-b border-border flex-shrink-0" style={ { minHeight: 56, backgroundColor: '#ffffff' } }>
            <div className="flex items-center gap-2 min-w-0">
              <FilterButton />
              <ShareButton />
            </div>
            { adminMode && (
              <button
                onClick={ () => setAddItemOpen( true ) }
                style={ { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#f0fdf4', color: '#16a34a', border: '1px solid #bbf7d0', borderRadius: 8, cursor: 'pointer', touchAction: 'manipulation' } }
              >
                <Plus size={ 15 } />Add Item
              </button>
            ) }
          </div>

          {/* Scrollable column tabs — synced with the column carousel below */}
          <div
            ref={ tabStripRef }
            className="scrollbar-hide flex-shrink-0"
            style={ { display: 'flex', alignItems: 'stretch', overflowX: 'auto', borderBottom: '1px solid #e2e8f0', backgroundColor: '#fff' } }
          >
            { columns.map( ( c, idx ) => {
              const count = itemsWithWorkflow.filter( i => 'workflowStage' in i && i.workflowStage === c.id ).length;
              const isActive = idx === safeIdx;
              return (
                <button
                  key={ c.id }
                  onClick={ () => goToColumn( idx ) }
                  style={ {
                    flex: '0 0 auto', display: 'inline-flex', alignItems: 'center', gap: 6,
                    padding: '10px 14px', fontSize: 12, fontWeight: isActive ? 700 : 500,
                    color: isActive ? '#2563eb' : '#64748b', background: 'none',
                    borderTop: 'none', borderLeft: 'none', borderRight: 'none',
                    borderBottom: isActive ? '2px solid #2563eb' : '2px solid transparent',
                    cursor: 'pointer', whiteSpace: 'nowrap', touchAction: 'manipulation',
                  } }
                >
                  { c.label }
                  <span style={ {
                    padding: '1px 6px', fontSize: 10, fontWeight: 700, borderRadius: 99,
                    backgroundColor: isActive ? '#dbeafe' : '#f1f5f9',
                    color: isActive ? '#2563eb' : '#94a3b8',
                  } }>{ count }</span>
                </button>
              );
            } ) }
          </div>

          {/* Swipeable column carousel — one stage per page, snaps left/right */}
          <div
            ref={ mobileScrollRef }
            onScroll={ handleMobileScroll }
            className="scrollbar-hide"
            style={ { flex: 1, display: 'flex', overflowX: 'auto', overflowY: 'hidden', scrollSnapType: 'x mandatory', WebkitOverflowScrolling: 'touch', cursor: 'grab' } }
          >
            { columns.map( ( c ) => {
              const colItems = itemsWithWorkflow.filter( i => 'workflowStage' in i && i.workflowStage === c.id );
              return (
                <div key={ c.id } style={ { flex: '0 0 100%', width: '100%', scrollSnapAlign: 'start', display: 'flex', flexDirection: 'column', overflow: 'hidden' } }>
                  <MobileKanbanColumn items={ colItems } onItemClick={ openModal } releases={ releases } />
                </div>
              );
            } ) }
          </div>
        </div>

        <AddItemModal
          isOpen={ addItemOpen }
          onClose={ () => setAddItemOpen( false ) }
          onSuccess={ () => { setAddItemOpen( false ); triggerRefresh(); } }
          settings={ settings }

        />
      </>
    );
  }

  // ── Desktop: full DnD board ──────────────────────────────────────────────────
  return (
    <DndProvider backend={ HTML5Backend }>
      <div className="flex flex-col h-full">
        {/* Top bar — shared header spec (issue #16) */}
        <div className="flex items-center justify-between gap-3 px-4 sm:px-6 border-b border-border flex-shrink-0" style={ { minHeight: 56, backgroundColor: '#ffffff' } }>
          <div className="flex items-center gap-2 min-w-0">
            <FilterButton />
            <ShareButton />
          </div>
          <div className="flex items-center gap-2 flex-wrap sm:flex-nowrap">
            { saveState === 'saving' && (
              <div className="flex items-center gap-2 px-3 py-2 bg-blue-50 text-blue-700 text-sm font-medium rounded-lg border border-blue-200">
                <div className="w-3.5 h-3.5 border-2 border-blue-700 border-t-transparent rounded-full animate-spin" />
                Saving changes...
              </div>
            ) }
            { saveState === 'success' && (
              <div className="flex items-center gap-2 px-3 py-2 bg-green-50 text-green-700 text-sm font-medium rounded-lg border border-green-200">
                <Check className="w-4 h-4" />Saved successfully
              </div>
            ) }
            { saveState === 'error' && (
              <div className="flex items-center gap-2 px-3 py-2 bg-red-50 text-red-700 text-sm font-medium rounded-lg border border-red-200">
                <AlertCircle className="w-4 h-4" />Failed to save
              </div>
            ) }
            { adminMode && (
              <>
                <button
                  onClick={ () => setAddItemOpen( true ) }
                  style={ { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#f0fdf4', color: '#16a34a', border: '1px solid #bbf7d0', borderRadius: 8, cursor: 'pointer' } }
                >
                  <Plus size={ 15 } /><span className="hidden sm:inline">Add Item</span>
                </button>
                { isEditMode ? (
                  <>
                    <button onClick={ handleCancel } style={ { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#f1f5f9', color: '#1a1f36', border: '1px solid #e2e8f0', borderRadius: 8, cursor: 'pointer' } }>
                      <X className="w-4 h-4" /><span>Cancel</span>
                    </button>
                    <button onClick={ handleSave } style={ { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#2563eb', color: '#ffffff', border: 'none', borderRadius: 8, cursor: 'pointer', boxShadow: '0 1px 3px rgba(37,99,235,0.4)' } }>
                      <Save className="w-4 h-4" />Save Changes
                    </button>
                  </>
                ) : (
                  <button onClick={ () => setIsEditMode( true ) } style={ { padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#2563eb', color: '#ffffff', border: 'none', borderRadius: 8, cursor: 'pointer', boxShadow: '0 1px 3px rgba(37,99,235,0.4)' } }>
                    Edit Board
                  </button>
                ) }
              </>
            ) }
          </div>
        </div>

        {/* Columns — horizontal scroll on desktop; stacked vertically on mobile */}
        <div
          ref={ isMobile ? undefined : scrollRef }
          className={ `flex-1 bg-muted/30 scrollbar-hide ${ isMobile ? '' : 'cursor-grab' }` }
          style={ { overflow: 'auto' } }
        >
          <div style={ isMobile
            ? { display: 'flex', flexDirection: 'column', gap: 16, padding: 16 }
            : { display: 'flex', alignItems: 'stretch', gap: 16, padding: 16, minWidth: 'max-content' }
          }>
            { columns.map( ( col ) => (
              <KanbanColumn
                key={ col.id }
                stage={ col.id }
                label={ col.label }
                items={ itemsWithWorkflow.filter( ( item ) => 'workflowStage' in item && item.workflowStage === col.id ) }
                isEditMode={ isEditMode }
                onItemClick={ openModal }
                onDrop={ handleDrop }
                onGroupDrop={ handleGroupDrop }
                releases={ releases }
                isMobile={ isMobile }
              />
            ) ) }
          </div>
        </div>
      </div>

      <AddItemModal
        isOpen={ addItemOpen }
        onClose={ () => setAddItemOpen( false ) }
        onSuccess={ () => { setAddItemOpen( false ); triggerRefresh(); } }
        settings={ settings }
      />
    </DndProvider>
  );
}
