import { useState, useEffect } from 'react';
import { DndProvider, useDrag, useDrop } from 'react-dnd';
import { HTML5Backend } from 'react-dnd-html5-backend';
import { Save, Check, AlertCircle, X } from 'lucide-react';
import { ItemCard } from './ItemCard';
import { WorkflowStage, Item } from '../types';
import { useDragScroll } from '../hooks/useDragScroll';
import { updateStage } from '../api/wordpress';
import { AppData } from '../App';

interface KanbanBoardProps {
  data: AppData;
  onItemClick: ( item: Item ) => void;
  isAdmin: boolean;
  onDataChange: () => void;
}

const WORKFLOW_COLUMNS: { stage: WorkflowStage; label: string }[] = [
  { stage: 'bug-tracking',     label: 'Bug Tracking' },
  { stage: 'scoping',          label: 'Scoping' },
  { stage: 'future-idea',      label: 'Future Idea' },
  { stage: 'up-next',          label: 'Up Next' },
  { stage: 'in-development',   label: 'In Development' },
  { stage: 'staging-features', label: 'Staging Features' },
  { stage: 'active-features',  label: 'Active Features' },
];

function DraggableCard( { item, isEditMode, onClick, data }: { item: Item; isEditMode: boolean; onClick: () => void; data: AppData } ) {
  const [{ isDragging }, drag] = useDrag( {
    type: 'ITEM',
    item: () => {
      const workflowStage = 'workflowStage' in item ? item.workflowStage : undefined;
      return { id: item.id, type: item.type, workflowStage };
    },
    canDrag: isEditMode,
    collect: ( monitor ) => ( { isDragging: monitor.isDragging() } ),
  } );

  return (
    <div ref={ drag } style={ { opacity: isDragging ? 0.5 : 1 } } className={ isEditMode ? 'cursor-grab active:cursor-grabbing' : '' }>
      <ItemCard item={ item } onClick={ onClick } showDragHandle={ isEditMode } data={ data } />
    </div>
  );
}

interface ColumnProps {
  stage: WorkflowStage;
  label: string;
  items: Item[];
  isEditMode: boolean;
  onItemClick: ( item: Item ) => void;
  onDrop: ( itemId: string, itemType: string, newStage: WorkflowStage ) => void;
  data: AppData;
}

function KanbanColumn( { stage, label, items, isEditMode, onItemClick, onDrop, data }: ColumnProps ) {
  const [{ isOver }, drop] = useDrop( {
    accept: 'ITEM',
    drop: ( dragged: { id: string; type: string; workflowStage?: WorkflowStage } ) => {
      if ( dragged.workflowStage !== stage ) onDrop( dragged.id, dragged.type, stage );
    },
    collect: ( monitor ) => ( { isOver: monitor.isOver() } ),
  } );

  // Build nested structure
  const nestedChildren: Record<string, Item[]> = {};
  const topLevelItems: Item[] = [];

  items.forEach( ( item ) => {
    let parentId: string | null = null;
    if ( item.type === 'subitem' ) parentId = item.parentFeatureId;
    else if ( item.type === 'bug' && item.linkedFeatureId ) parentId = item.linkedFeatureId;
    else if ( item.type === 'feedback' && item.linkedFeatureId ) parentId = item.linkedFeatureId;

    if ( parentId && items.find( ( i ) => i.id === parentId ) ) {
      if ( ! nestedChildren[parentId] ) nestedChildren[parentId] = [];
      nestedChildren[parentId].push( item );
    } else {
      topLevelItems.push( item );
    }
  } );

  const itemsByRelease: Record<string, Item[]> = {};
  topLevelItems.forEach( ( item ) => {
    const rId = ( 'releaseId' in item && item.releaseId ) ? item.releaseId : 'unassigned';
    if ( ! itemsByRelease[rId] ) itemsByRelease[rId] = [];
    itemsByRelease[rId].push( item );
  } );

  const releaseGroups = Object.keys( itemsByRelease ).sort( ( a, b ) => {
    if ( a === 'unassigned' ) return 1;
    if ( b === 'unassigned' ) return -1;
    return a.localeCompare( b );
  } );

  return (
    <div style={{ display: 'flex', flexDirection: 'column', width: 340, minWidth: 340, flexShrink: 0, backgroundColor: '#ffffff', borderRadius: 8, boxShadow: '0 1px 3px rgba(0,0,0,0.07)', border: '1px solid #e2e8f0' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderBottom: '2px solid #e2e8f0', background: 'linear-gradient(to bottom, #f8fafc, #f1f5f9)', borderRadius: '8px 8px 0 0' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <h3 style={{ fontSize: 13, fontWeight: 700, color: '#1a1f36', margin: 0 }}>{ label }</h3>
          <span style={{ padding: '2px 10px', fontSize: 11, fontWeight: 700, borderRadius: 99, backgroundColor: '#2563eb', color: '#ffffff', boxShadow: '0 1px 2px rgba(0,0,0,0.1)' }}>{ items.length }</span>
        </div>
      </div>
      <div ref={ drop } style={{ flex: 1, padding: 12, transition: 'background-color 0.15s', backgroundColor: isOver && isEditMode ? '#eff6ff' : '#f8fafc', boxShadow: isOver && isEditMode ? 'inset 0 0 0 2px rgba(37,99,235,0.2)' : 'none' }}>
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
              const releaseName = rId === 'unassigned' ? 'Unassigned' : data.releases.find( ( r ) => r.id === rId )?.name || 'Unknown Release';
              return (
                <div key={ rId } className="flex flex-col rounded-xl border border-border shadow-sm bg-background/50 overflow-hidden">
                  <div className="flex items-center gap-2 px-3 py-2 border-b border-border bg-muted/30">
                    <span className="text-[11px] font-bold text-muted-foreground uppercase tracking-wider">{ releaseName }</span>
                  </div>
                  <div className="flex flex-col gap-3 p-2 bg-muted/10">
                    { groupItems.map( ( item ) => (
                      <div key={ item.id } className="relative">
                        <DraggableCard item={ item } isEditMode={ isEditMode } onClick={ () => onItemClick( item ) } data={ data } />
                        { nestedChildren[item.id] && nestedChildren[item.id].length > 0 && (
                          <div className="mt-2 ml-4 pl-3 border-l-2 border-primary/20 flex flex-col gap-2">
                            { nestedChildren[item.id].map( ( child ) => (
                              <DraggableCard key={ child.id } item={ child } isEditMode={ isEditMode } onClick={ () => onItemClick( child ) } data={ data } />
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
        ) }
      </div>
    </div>
  );
}

export function KanbanBoard( { data, onItemClick, isAdmin, onDataChange }: KanbanBoardProps ) {
  const scrollRef = useDragScroll<HTMLDivElement>();
  const [localItems, setLocalItems] = useState<Item[]>( data.allItems );
  const [isEditMode, setIsEditMode] = useState( false );
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'success' | 'error'>( 'idle' );
  const [pendingStages, setPendingStages] = useState<{ id: string; type: string; stage: WorkflowStage }[]>( [] );

  // Sync when data refreshes from parent
  useEffect( () => { setLocalItems( data.allItems ); }, [data.allItems] );

  const handleDrop = ( itemId: string, itemType: string, newStage: WorkflowStage ) => {
    setLocalItems( ( prev ) =>
      prev.map( ( item ) =>
        item.id === itemId && 'workflowStage' in item ? { ...item, workflowStage: newStage } : item
      )
    );
    setPendingStages( ( prev ) => {
      const filtered = prev.filter( ( p ) => p.id !== itemId );
      return [...filtered, { id: itemId, type: itemType, stage: newStage }];
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
        onDataChange();
      }, 1500 );
    } catch {
      setSaveState( 'error' );
    }
  };

  const handleCancel = () => {
    setIsEditMode( false );
    setSaveState( 'idle' );
    setPendingStages( [] );
    setLocalItems( data.allItems );
  };

  const itemsWithWorkflow = localItems.filter( ( item ) => 'workflowStage' in item );

  // Build a merged data object so columns can resolve release names
  const mergedData: AppData = { ...data, allItems: localItems };

  return (
    <DndProvider backend={ HTML5Backend }>
      <div className="flex flex-col h-full">
        <div className="flex items-center justify-between px-4 sm:px-6 py-4 border-b border-border bg-background">
          <div>
            <h2 className="text-base sm:text-lg font-semibold">Kanban Board</h2>
            <p className="text-xs sm:text-sm text-muted-foreground hidden sm:block">Manage your workflow across all stages</p>
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
            { isAdmin && (
              isEditMode ? (
                <>
                  <button onClick={ handleCancel } style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#f1f5f9', color: '#1a1f36', border: '1px solid #e2e8f0', borderRadius: 8, cursor: 'pointer' }}>
                    <X className="w-4 h-4" /><span>Cancel</span>
                  </button>
                  <button onClick={ handleSave } style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#2563eb', color: '#ffffff', border: 'none', borderRadius: 8, cursor: 'pointer', boxShadow: '0 1px 3px rgba(37,99,235,0.4)' }}>
                    <Save className="w-4 h-4" />Save Changes
                  </button>
                </>
              ) : (
                <button onClick={ () => setIsEditMode( true ) } style={{ padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#2563eb', color: '#ffffff', border: 'none', borderRadius: 8, cursor: 'pointer', boxShadow: '0 1px 3px rgba(37,99,235,0.4)' }}>
                  Edit Board
                </button>
              )
            ) }
          </div>
        </div>

        <div ref={ scrollRef } className="flex-1 overflow-auto bg-muted/30 cursor-grab scrollbar-hide">
          <div className="flex min-h-full gap-4 p-4 min-w-max">
            { WORKFLOW_COLUMNS.map( ( column ) => (
              <KanbanColumn
                key={ column.stage }
                stage={ column.stage }
                label={ column.label }
                items={ itemsWithWorkflow.filter( ( item ) => 'workflowStage' in item && item.workflowStage === column.stage ) }
                isEditMode={ isEditMode }
                onItemClick={ onItemClick }
                onDrop={ handleDrop }
                data={ mergedData }
              />
            ) ) }
          </div>
        </div>
      </div>
    </DndProvider>
  );
}
