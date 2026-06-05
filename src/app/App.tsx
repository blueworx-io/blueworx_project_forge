import { useState, useEffect } from 'react';
import { LayoutGrid, BarChart3, Calendar, Settings as SettingsIcon } from 'lucide-react';
import { KanbanBoard } from './components/KanbanBoard';
import { GanttTimeline } from './components/GanttTimeline';
import { CalendarView } from './components/CalendarView';
import { DetailModal } from './components/DetailModal';
import { Settings } from './components/Settings';
import { Item, Feature, SubItem, Bug, Feedback, Release, CompanyDate, AppSettings } from './types';
import { fetchAllItems, isAdmin, getInitialSettings } from './api/wordpress';
import {
  sampleFeatures, sampleSubItems, sampleBugs,
  sampleFeedback, sampleReleases, sampleCompanyDates,
} from './data/sampleData';

type View = 'kanban' | 'gantt' | 'calendar' | 'settings';

export interface AppData {
  features: Feature[];
  subitems: SubItem[];
  bugs: Bug[];
  feedback: Feedback[];
  releases: Release[];
  companyDates: CompanyDate[];
  allItems: Item[];
}

function buildAllItems( data: Omit<AppData, 'allItems'> ): Item[] {
  return [ ...data.features, ...data.subitems, ...data.bugs, ...data.feedback, ...data.releases ];
}

const FALLBACK: AppData = {
  features:     sampleFeatures,
  subitems:     sampleSubItems,
  bugs:         sampleBugs,
  feedback:     sampleFeedback,
  releases:     sampleReleases,
  companyDates: sampleCompanyDates,
  allItems:     [ ...sampleFeatures, ...sampleSubItems, ...sampleBugs, ...sampleFeedback, ...sampleReleases ],
};

export default function App() {
  const [currentView,  setCurrentView]  = useState<View>( 'gantt' );
  const [prevView,     setPrevView]     = useState<View>( 'gantt' );
  const [selectedItem, setSelectedItem] = useState<Item | null>( null );
  const [isModalOpen,  setIsModalOpen]  = useState( false );
  const [data,         setData]         = useState<AppData>( FALLBACK );
  const [settings,     setSettings]     = useState<AppSettings>( getInitialSettings() );
  const [refreshKey,   setRefreshKey]   = useState( 0 );

  const adminMode = isAdmin();
  const isCalendar = currentView === 'calendar';

  useEffect( () => {
    if ( ! window.forgePMData ) return;
    fetchAllItems()
      .then( raw => {
        const next = {
          features:     raw.features     as Feature[],
          subitems:     raw.subitems     as SubItem[],
          bugs:         raw.bugs         as Bug[],
          feedback:     raw.feedback     as Feedback[],
          releases:     raw.releases     as Release[],
          companyDates: raw.companyDates as CompanyDate[],
        };
        setData( { ...next, allItems: buildAllItems( next ) } );
      } )
      .catch( () => {} );
  }, [refreshKey] );

  const handleItemClick = ( item: Item ) => { setSelectedItem( item ); setIsModalOpen( true ); };
  const handleUpdateItem = () => setRefreshKey( k => k + 1 );

  const openSettings = () => {
    if ( currentView !== 'settings' ) setPrevView( currentView );
    setCurrentView( 'settings' );
  };

  const closeSettings = () => setCurrentView( prevView );

  const TAB_VIEWS: { view: Exclude<View, 'settings'>; label: string; Icon: any }[] = [
    { view: 'gantt',    label: 'Timeline', Icon: BarChart3 },
    { view: 'kanban',   label: 'Kanban',   Icon: LayoutGrid },
    { view: 'calendar', label: 'Calendar', Icon: Calendar },
  ];

  return (
    <div
      key={ refreshKey }
      style={{
        display: 'flex', flexDirection: 'column',
        height:     isCalendar ? undefined : '100vh',
        minHeight:  isCalendar ? '100vh'   : undefined,
        backgroundColor: '#fafbfc', color: '#1a1f36',
      }}
    >
      {/* ── Top navigation ─────────────────────────────────────── */}
      <header style={{
        borderBottom: '1px solid #e2e8f0',
        backgroundColor: '#ffffff',
        position: isCalendar ? 'sticky' : 'static',
        top: 0, zIndex: isCalendar ? 50 : 'auto' as any,
      }}>
        <div style={{ padding: '12px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>

          {/* Title */}
          <div>
            <h1 style={{ fontSize: 20, fontWeight: 700, color: '#1a1f36', margin: 0 }}>Forge Project Management</h1>
            <p style={{ fontSize: 13, color: '#64748b', margin: 0 }} className="hidden sm:block">Product planning &amp; release management</p>
          </div>

          {/* Right side: tabs + settings (separate containers) */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>

            {/* Tab group */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 4, padding: 4, backgroundColor: '#f1f5f9', borderRadius: 8 }}>
              { TAB_VIEWS.map( ( { view, label, Icon } ) => {
                const isActive = currentView === view;
                return (
                  <button
                    key={ view }
                    onClick={ () => setCurrentView( view ) }
                    style={{
                      display: 'inline-flex', alignItems: 'center', gap: 6,
                      padding: '6px 12px', borderRadius: 6,
                      fontSize: 13, fontWeight: 500, cursor: 'pointer',
                      border: 'none',
                      backgroundColor: isActive ? '#ffffff' : 'transparent',
                      color:           isActive ? '#1a1f36' : '#64748b',
                      boxShadow:       isActive ? '0 1px 3px rgba(0,0,0,0.1)' : 'none',
                      transition: 'all 0.15s',
                    }}
                  >
                    <Icon size={ 15 } />
                    <span className="hidden sm:inline">{ label }</span>
                  </button>
                );
              } ) }
            </div>

            {/* Settings — separate container, admin only */}
            { adminMode && (
              <button
                onClick={ currentView === 'settings' ? closeSettings : openSettings }
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: 6,
                  padding: '6px 12px', borderRadius: 8,
                  fontSize: 13, fontWeight: 500, cursor: 'pointer',
                  border: '1px solid #e2e8f0',
                  backgroundColor: currentView === 'settings' ? '#2563eb' : '#ffffff',
                  color:           currentView === 'settings' ? '#ffffff'  : '#1a1f36',
                  boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                  transition: 'all 0.15s',
                }}
              >
                <SettingsIcon size={ 15 } />
                <span className="hidden sm:inline">Settings</span>
              </button>
            ) }
          </div>
        </div>
      </header>

      {/* ── Main content ───────────────────────────────────────── */}
      <main style={{ flex: 1, overflow: isCalendar || currentView === 'settings' ? 'visible' : 'hidden' }}>
        { currentView === 'settings' ? (
          <Settings settings={ settings } onSettingsChange={ setSettings } onClose={ closeSettings } />
        ) : currentView === 'kanban' ? (
          <KanbanBoard data={ data } onItemClick={ handleItemClick } isAdmin={ adminMode } onDataChange={ handleUpdateItem } />
        ) : currentView === 'gantt' ? (
          <GanttTimeline data={ data } onItemClick={ handleItemClick } />
        ) : (
          <CalendarView data={ data } onItemClick={ handleItemClick } isAdmin={ adminMode } onDataChange={ handleUpdateItem } />
        ) }
      </main>

      {/* Detail modal — not shown when settings is open */}
      { currentView !== 'settings' && (
        <DetailModal
          item={ selectedItem }
          data={ data }
          isOpen={ isModalOpen }
          onClose={ () => setIsModalOpen( false ) }
          onUpdate={ handleUpdateItem }
          isAdmin={ adminMode }
        />
      ) }
    </div>
  );
}
