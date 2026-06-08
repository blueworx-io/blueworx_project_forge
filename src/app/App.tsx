import React, { useState, useEffect, lazy, Suspense } from 'react';
import { LayoutGrid, BarChart3, Calendar, Settings as SettingsIcon, LogIn, Loader2 } from 'lucide-react';
import { AppSettings, BrandConfig } from './types';
import { Feature, SubItem, Bug, Feedback, Release, CompanyDate } from './types';
import { isAdmin, getLoginUrl, getInitialSettings, fetchAllItems, fetchSettings } from './api/wordpress';
import { useDataStore } from './store/useDataStore';
import { useUIStore } from './store/useUIStore';
import { useIsMobile } from './hooks/useIsMobile';
import { MobileNav, BOTTOM_BAR_HEIGHT } from './components/MobileNav';

// Heavy view components — loaded only when first accessed
const KanbanBoard   = lazy( () => import('./components/KanbanBoard').then(   m => ( { default: m.KanbanBoard } ) ) );
const GanttTimeline = lazy( () => import('./components/GanttTimeline').then( m => ( { default: m.GanttTimeline } ) ) );
const CalendarView  = lazy( () => import('./components/CalendarView').then(  m => ( { default: m.CalendarView } ) ) );
const Settings      = lazy( () => import('./components/Settings').then(      m => ( { default: m.Settings } ) ) );
const DetailModal   = lazy( () => import('./components/DetailModal').then(   m => ( { default: m.DetailModal } ) ) );

type View = 'kanban' | 'gantt' | 'calendar' | 'settings';

class ErrorBoundary extends React.Component<
  { children: React.ReactNode },
  { error: Error | null }
> {
  state = { error: null };
  static getDerivedStateFromError( e: Error ) { return { error: e }; }
  render() {
    if ( this.state.error ) {
      return (
        <div style={{ padding: 32, color: '#dc2626' }}>
          <strong>Something went wrong loading this view.</strong>
          <pre style={{ marginTop: 8, fontSize: 12, whiteSpace: 'pre-wrap' }}>{ ( this.state.error as Error ).message }</pre>
        </div>
      );
    }
    return this.props.children;
  }
}

function ChunkLoader() {
  return (
    <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <Loader2 size={ 24 } style={{ color: '#2563eb', animation: 'spin 1s linear infinite' }} />
    </div>
  );
}

export default function App() {
  const [settings, setSettings]     = useState<AppSettings>( getInitialSettings() );
  const [visited, setVisited]       = useState<Set<string>>( () => new Set( ['gantt'] ) );
  const [drawerOpen, setDrawerOpen] = useState( false );
  // Block the UI with the full-screen loader only on the very first fetch.
  // Later refreshes (e.g. saving a release in Settings) load in the background
  // so the current view stays mounted and the user keeps their place. (#2)
  const [hasLoaded, setHasLoaded]   = useState( () => ! window.forgePMData );
  const isMobile = useIsMobile();

  // Store reads
  const isLoading    = useDataStore( s => s.isLoading );
  const refreshKey   = useDataStore( s => s.refreshKey );
  const setAllData   = useDataStore( s => s.setAllData );
  const setLoading   = useDataStore( s => s.setLoading );

  const currentView   = useUIStore( s => s.currentView );
  const openSettings  = useUIStore( s => s.openSettings );
  const closeSettings = useUIStore( s => s.closeSettings );
  const switchView    = useUIStore( s => s.switchView );

  const adminMode  = isAdmin();
  const isCalendar = currentView === 'calendar';

  // Track which views have been visited so we only mount them on first access
  useEffect( () => {
    setVisited( prev => {
      if ( prev.has( currentView ) ) return prev;
      const next = new Set( prev );
      next.add( currentView );
      return next;
    } );
  }, [currentView] );

  useEffect( () => {
    if ( ! window.forgePMData ) return;
    setLoading( true );
    Promise.all( [
      fetchAllItems().then( raw => {
        setAllData( {
          features:     raw.features     as Feature[],
          subitems:     raw.subitems     as SubItem[],
          bugs:         raw.bugs         as Bug[],
          feedback:     raw.feedback     as Feedback[],
          releases:     raw.releases     as Release[],
          companyDates: raw.companyDates as CompanyDate[],
        } );
      } ),
      fetchSettings().then( s => {
        if ( Array.isArray( s.brands ) ) {
          s.brands = ( s.brands as (string | BrandConfig)[] ).map( ( b ) => typeof b === 'string' ? { name: b, logo: '' } : b );
        }
        setSettings( s );
      } ),
    ] ).catch( ( err ) => console.error( '[Forge PM] fetch error:', err ) ).finally( () => { setLoading( false ); setHasLoaded( true ); } );
  }, [refreshKey] ); // eslint-disable-line react-hooks/exhaustive-deps

  const TAB_VIEWS: { view: Exclude<View, 'settings'>; label: string; Icon: React.ComponentType<{ size?: number }> }[] = [
    { view: 'gantt',    label: 'Timeline', Icon: BarChart3 },
    { view: 'kanban',   label: 'Kanban',   Icon: LayoutGrid },
    { view: 'calendar', label: 'Calendar', Icon: Calendar },
  ];

  return (
    <div
      style={{
        display: 'flex', flexDirection: 'column',
        height:     isCalendar ? undefined : '100dvh',
        minHeight:  isCalendar ? '100dvh'  : undefined,
        paddingBottom: isMobile ? `calc(${ BOTTOM_BAR_HEIGHT }px + env(safe-area-inset-bottom))` : undefined,
        backgroundColor: '#fafbfc', color: '#1a1f36',
      }}
    >
      {/* ── Top navigation ─────────────────────────────────────── */}
      <header style={{
        borderBottom: '1px solid #e2e8f0',
        backgroundColor: '#ffffff',
        position: 'sticky',
        top: 0,
        zIndex: 50,
        flexShrink: 0,
      }}>
        <div style={{ padding: '12px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ minWidth: 0, flex: '1 1 0' }}>
            <h1 style={{ fontSize: 'clamp(15px, 4vw, 20px)', fontWeight: 700, color: '#1a1f36', margin: 0, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{ settings.projectName || 'Forge Project Management' }</h1>
            <p style={{ fontSize: 13, color: '#64748b', margin: 0 }} className="hidden sm:block">Product planning &amp; release management</p>
          </div>
          { ! isMobile && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 4, padding: 4, backgroundColor: '#f1f5f9', borderRadius: 8 }}>
              { TAB_VIEWS.map( ( { view, label, Icon } ) => {
                const isActive = currentView === view;
                return (
                  <button key={ view } onClick={ () => switchView( view ) }
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 6, fontSize: 13, fontWeight: 500, cursor: 'pointer', border: 'none', backgroundColor: isActive ? '#ffffff' : 'transparent', color: isActive ? '#1a1f36' : '#64748b', boxShadow: isActive ? '0 1px 3px rgba(0,0,0,0.1)' : 'none', transition: 'all 0.15s' }}>
                    <Icon size={ 15 } />
                    <span className="hidden sm:inline">{ label }</span>
                  </button>
                );
              } ) }
            </div>
            { adminMode ? (
              <button onClick={ currentView === 'settings' ? closeSettings : openSettings }
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, fontSize: 13, fontWeight: 500, cursor: 'pointer', border: '1px solid #e2e8f0', backgroundColor: currentView === 'settings' ? '#2563eb' : '#ffffff', color: currentView === 'settings' ? '#ffffff' : '#1a1f36', boxShadow: '0 1px 2px rgba(0,0,0,0.05)', transition: 'all 0.15s' }}>
                <SettingsIcon size={ 15 } />
                <span className="hidden sm:inline">Settings</span>
              </button>
            ) : (
              <a href={ getLoginUrl() }
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, fontSize: 13, fontWeight: 500, cursor: 'pointer', border: '1px solid #e2e8f0', backgroundColor: '#ffffff', color: '#1a1f36', textDecoration: 'none', boxShadow: '0 1px 2px rgba(0,0,0,0.05)', transition: 'all 0.15s' }}>
                <LogIn size={ 15 } />
                <span className="hidden sm:inline">Login</span>
              </a>
            ) }
          </div>
          ) }
        </div>
      </header>

      {/* ── Main content ───────────────────────────────────────── */}
      <main style={{ flex: 1, overflow: isCalendar ? 'visible' : 'hidden', minHeight: 0 }}>
        { isLoading && ! hasLoaded ? (
          <div style={{ position: 'fixed', inset: 0, zIndex: 9999, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', backgroundColor: '#fafbfc', gap: 12, color: '#64748b' }}>
            <Loader2 size={ 36 } style={{ color: '#2563eb', animation: 'spin 1s linear infinite' }} />
            <p style={{ fontSize: 14, margin: 0 }}>Loading…</p>
            <style>{ `@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }` }</style>
          </div>
        ) : (
          <>
            { currentView === 'settings' && (
              <ErrorBoundary>
                <Suspense fallback={ <ChunkLoader /> }>
                  <Settings settings={ settings } onSettingsChange={ setSettings } onClose={ closeSettings } />
                </Suspense>
              </ErrorBoundary>
            ) }

            { currentView === 'calendar' && (
              <ErrorBoundary>
                <Suspense fallback={ <ChunkLoader /> }>
                  <CalendarView />
                </Suspense>
              </ErrorBoundary>
            ) }

            {/* Gantt and Kanban stay mounted after first visit — switching is instant, no remount cost */}
            { visited.has( 'gantt' ) && (
              <ErrorBoundary>
                <Suspense fallback={ <ChunkLoader /> }>
                  <div style={{ display: currentView === 'gantt' ? 'flex' : 'none', flexDirection: 'column', height: '100%' }}>
                    <GanttTimeline settings={ settings } />
                  </div>
                </Suspense>
              </ErrorBoundary>
            ) }

            { visited.has( 'kanban' ) && (
              <ErrorBoundary>
                <Suspense fallback={ <ChunkLoader /> }>
                  <div style={{ display: currentView === 'kanban' ? 'flex' : 'none', flexDirection: 'column', height: '100%' }}>
                    <KanbanBoard settings={ settings } />
                  </div>
                </Suspense>
              </ErrorBoundary>
            ) }
          </>
        ) }
      </main>

      {/* Mobile navigation — bottom bar + left drawer (below the sm breakpoint) */}
      { isMobile && (
        <MobileNav
          currentView={ currentView }
          switchView={ switchView }
          openSettings={ openSettings }
          adminMode={ adminMode }
          settings={ settings }
          drawerOpen={ drawerOpen }
          setDrawerOpen={ setDrawerOpen }
        />
      ) }

      {/* Detail modal — not shown when settings is open */}
      { currentView !== 'settings' && (
        <ErrorBoundary>
          <Suspense fallback={ null }>
            <DetailModal settings={ settings } />
          </Suspense>
        </ErrorBoundary>
      ) }
    </div>
  );
}
