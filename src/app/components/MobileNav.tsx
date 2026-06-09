import { useEffect, useMemo } from 'react';
import { BarChart3, LayoutGrid, Calendar, Menu, Settings as SettingsIcon, X, LogIn, LogOut, AlertTriangle, CalendarClock, ChevronRight, ExternalLink } from 'lucide-react';
import { parseISO, differenceInCalendarDays } from 'date-fns';
import { AppSettings, Item } from '../types';
import { useDataStore } from '../store/useDataStore';
import { useUIStore } from '../store/useUIStore';
import { isLoggedIn, getLoginUrl, getLogoutUrl, getAdminUrl } from '../api/wordpress';

type View = 'kanban' | 'gantt' | 'calendar' | 'settings';

const C = {
  white:   '#ffffff',
  border:  '#e2e8f0',
  fg:      '#1a1f36',
  mutedFg: '#64748b',
  primary: '#2563eb',
  danger:  '#dc2626',
  scrim:   'rgba(0,0,0,0.45)',
};

export const BOTTOM_BAR_HEIGHT = 60;
const UPCOMING_WINDOW_DAYS = 7; // how far ahead "upcoming" dates reach
const MAX_ROWS = 4;             // cap each Overview section

const TABS: { view: Exclude<View, 'settings'>; label: string; Icon: React.ComponentType<{ size?: number }> }[] = [
  { view: 'gantt',    label: 'Timeline', Icon: BarChart3 },
  { view: 'kanban',   label: 'Kanban',   Icon: LayoutGrid },
  { view: 'calendar', label: 'Calendar', Icon: Calendar },
];

interface MobileNavProps {
  currentView: View;
  switchView: ( view: Exclude<View, 'settings'> ) => void;
  openSettings: () => void;
  adminMode: boolean;
  isWpAdmin?: boolean;
  settings: AppSettings;
  drawerOpen: boolean;
  setDrawerOpen: ( open: boolean ) => void;
}

function relativeDay( diff: number ): string {
  if ( diff <= 0 ) return 'Today';
  if ( diff === 1 ) return 'Tomorrow';
  return `in ${ diff } days`;
}

export function MobileNav( {
  currentView, switchView, openSettings, adminMode, isWpAdmin = false, settings, drawerOpen, setDrawerOpen,
}: MobileNavProps ) {
  const bugs         = useDataStore( s => s.bugs );
  const feedback     = useDataStore( s => s.feedback );
  const companyDates = useDataStore( s => s.companyDates );
  const openModal    = useUIStore( s => s.openModal );

  // Active indicator position: tabs 0–2, Menu tab = 3 (active while drawer open)
  const activeIndex = drawerOpen
    ? 3
    : TABS.findIndex( t => t.view === currentView ); // -1 on settings view → indicator hidden

  // ── Overview: high-priority open items ──────────────────────────
  const priorities = useMemo<Item[]>( () => {
    const hotBugs     = bugs.filter( b => b.priority === 'high' && b.bugStatus !== 'resolved' );
    const hotFeedback = feedback.filter( f => f.priority === 'high' && f.status !== 'resolved' );
    return [ ...hotBugs, ...hotFeedback ];
  }, [ bugs, feedback ] );

  // ── Overview: today's / upcoming company dates ──────────────────
  const upcoming = useMemo( () => {
    const today = new Date();
    return companyDates
      .filter( cd => cd.date )
      .map( cd => ( { cd, diff: differenceInCalendarDays( parseISO( cd.date ), today ) } ) )
      .filter( ( { diff } ) => diff >= 0 && diff <= UPCOMING_WINDOW_DAYS )
      .sort( ( a, b ) => a.diff - b.diff );
  }, [ companyDates ] );

  const hasOverview = priorities.length > 0 || upcoming.length > 0;

  // Escape to close + lock body scroll while the drawer is open
  useEffect( () => {
    if ( ! drawerOpen ) return;
    const onKey = ( e: KeyboardEvent ) => { if ( e.key === 'Escape' ) setDrawerOpen( false ); };
    window.addEventListener( 'keydown', onKey );
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener( 'keydown', onKey );
      document.body.style.overflow = prevOverflow;
    };
  }, [drawerOpen, setDrawerOpen] );

  const handleTab = ( view: Exclude<View, 'settings'> ) => {
    if ( drawerOpen ) setDrawerOpen( false );
    switchView( view );
  };

  const handleSettings = () => {
    setDrawerOpen( false );
    openSettings();
  };

  const openItem = ( item: Item ) => {
    setDrawerOpen( false );
    openModal( item );
  };

  // Staggered-entrance style for drawer sections
  const stagger = ( i: number ): React.CSSProperties => ( {
    opacity: drawerOpen ? 1 : 0,
    transform: drawerOpen ? 'translateX(0)' : 'translateX(-12px)',
    transition: 'opacity 0.25s ease, transform 0.25s ease',
    transitionDelay: drawerOpen ? `${ 80 + i * 50 }ms` : '0ms',
  } );

  const loggedIn = isLoggedIn();

  const authLinkStyle: React.CSSProperties = {
    width: '100%', boxSizing: 'border-box', display: 'flex', alignItems: 'center', gap: 10,
    padding: '12px 12px', borderRadius: 10, border: 'none', cursor: 'pointer',
    fontSize: 15, fontWeight: 600, textAlign: 'left',
    backgroundColor: '#f1f5f9', color: C.fg, textDecoration: 'none',
  };

  return (
    <>
      {/* ── Slide-in drawer (always mounted; transitions handle open/close) ── */}
      <div
        aria-hidden={ ! drawerOpen }
        onClick={ () => setDrawerOpen( false ) }
        style={{
          position: 'fixed', inset: 0, zIndex: 70,
          backgroundColor: C.scrim,
          opacity: drawerOpen ? 1 : 0,
          visibility: drawerOpen ? 'visible' : 'hidden',
          pointerEvents: drawerOpen ? 'auto' : 'none',
          transition: 'opacity 0.25s ease, visibility 0.25s ease',
        }}
      >
        <nav
          role="dialog"
          aria-modal="true"
          aria-label="Menu"
          onClick={ e => e.stopPropagation() }
          style={{
            position: 'absolute', top: 0, left: 0, bottom: 0,
            width: '82%', maxWidth: 340,
            backgroundColor: C.white,
            boxShadow: '6px 0 24px rgba(0,0,0,0.18)',
            display: 'flex', flexDirection: 'column',
            transform: drawerOpen ? 'translateX(0)' : 'translateX(-100%)',
            transition: 'transform 0.25s ease-out',
          }}
        >
          {/* Drawer header — project info + close */}
          <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, padding: '18px 16px', borderBottom: `1px solid ${ C.border }`, flexShrink: 0 }}>
            <div style={{ minWidth: 0 }}>
              <h2 style={{ fontSize: 17, fontWeight: 700, color: C.fg, margin: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>{ settings.projectName || 'Forge Project Management' }</h2>
              <p style={{ fontSize: 13, color: C.mutedFg, margin: '2px 0 0' }}>Product planning &amp; release management</p>
            </div>
            <button onClick={ () => setDrawerOpen( false ) } aria-label="Close menu"
              style={{ flexShrink: 0, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 32, height: 32, borderRadius: 8, border: `1px solid ${ C.border }`, backgroundColor: C.white, color: C.mutedFg, cursor: 'pointer' }}>
              <X size={ 16 } />
            </button>
          </div>

          {/* Overview — scrollable middle region */}
          <div style={{ flex: 1, overflowY: 'auto', padding: '16px' }}>
            <div style={{ ...stagger( 0 ) }}>
              <p style={{ fontSize: 11, fontWeight: 700, letterSpacing: '0.06em', textTransform: 'uppercase', color: C.mutedFg, margin: '0 0 10px' }}>Today&apos;s Overview</p>
            </div>

            { ! hasOverview && (
              <div style={{ ...stagger( 1 ), padding: '20px 12px', textAlign: 'center', border: `1px dashed ${ C.border }`, borderRadius: 12, color: C.mutedFg }}>
                <p style={{ fontSize: 13, margin: 0 }}>You&apos;re all caught up — no high-priority items or upcoming dates.</p>
              </div>
            ) }

            { priorities.length > 0 && (
              <div style={{ ...stagger( 1 ), marginBottom: upcoming.length > 0 ? 18 : 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 8, color: C.danger }}>
                  <AlertTriangle size={ 14 } />
                  <span style={{ fontSize: 12, fontWeight: 700 }}>High priority ({ priorities.length })</span>
                </div>
                { priorities.slice( 0, MAX_ROWS ).map( item => {
                  const title = 'title' in item ? item.title : item.name;
                  return (
                    <button key={ item.id } onClick={ () => openItem( item ) }
                      style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 10, padding: '10px 10px', marginBottom: 4, borderRadius: 10, border: `1px solid ${ C.border }`, backgroundColor: C.white, cursor: 'pointer', textAlign: 'left' }}>
                      <span style={{ flexShrink: 0, width: 8, height: 8, borderRadius: '50%', backgroundColor: C.danger }} />
                      <span style={{ flex: 1, minWidth: 0, fontSize: 13, fontWeight: 600, color: C.fg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ title }</span>
                      <span style={{ flexShrink: 0, fontSize: 11, fontWeight: 600, color: C.mutedFg, textTransform: 'capitalize' }}>{ item.type }</span>
                      <ChevronRight size={ 14 } style={{ flexShrink: 0, color: C.mutedFg }} />
                    </button>
                  );
                } ) }
                { priorities.length > MAX_ROWS && (
                  <p style={{ fontSize: 12, color: C.mutedFg, margin: '6px 2px 0' }}>+{ priorities.length - MAX_ROWS } more</p>
                ) }
              </div>
            ) }

            { upcoming.length > 0 && (
              <div style={{ ...stagger( 2 ) }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 8, color: C.primary }}>
                  <CalendarClock size={ 14 } />
                  <span style={{ fontSize: 12, fontWeight: 700 }}>Upcoming</span>
                </div>
                { upcoming.slice( 0, MAX_ROWS ).map( ( { cd, diff } ) => (
                  <div key={ cd.id }
                    style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 10px', marginBottom: 4, borderRadius: 10, border: `1px solid ${ C.border }`, backgroundColor: C.white }}>
                    <span style={{ flex: 1, minWidth: 0, fontSize: 13, fontWeight: 600, color: C.fg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ cd.title }</span>
                    <span style={{ flexShrink: 0, fontSize: 11, fontWeight: 700, color: diff === 0 ? C.primary : C.mutedFg }}>{ relativeDay( diff ) }</span>
                  </div>
                ) ) }
                { upcoming.length > MAX_ROWS && (
                  <p style={{ fontSize: 12, color: C.mutedFg, margin: '6px 2px 0' }}>+{ upcoming.length - MAX_ROWS } more</p>
                ) }
              </div>
            ) }
          </div>

          {/* Footer — Settings (admin/manager) + WP Admin (admin only) + Log in / Log out */}
          <div style={{ flexShrink: 0, padding: 12, borderTop: `1px solid ${ C.border }`, display: 'flex', flexDirection: 'column', gap: 8, ...stagger( 3 ) }}>
            { adminMode && (
              <button onClick={ handleSettings }
                style={{ ...authLinkStyle, cursor: 'pointer', backgroundColor: currentView === 'settings' ? C.primary : '#f1f5f9', color: currentView === 'settings' ? C.white : C.fg }}>
                <SettingsIcon size={ 18 } />
                Settings
              </button>
            ) }
            { isWpAdmin && (
              <a href={ getAdminUrl() } target="_blank" rel="noreferrer" style={ authLinkStyle }>
                <ExternalLink size={ 18 } />
                WordPress Admin
              </a>
            ) }
            { loggedIn ? (
              <a href={ getLogoutUrl() } style={ authLinkStyle }>
                <LogOut size={ 18 } />
                Log out
              </a>
            ) : (
              <a href={ getLoginUrl() } style={ authLinkStyle }>
                <LogIn size={ 18 } />
                Log in
              </a>
            ) }
          </div>
        </nav>
      </div>

      {/* ── Bottom tab bar ───────────────────────────────────────── */}
      <nav
        aria-label="Primary"
        style={{
          position: 'fixed', left: 0, right: 0, bottom: 0, zIndex: 60,
          height: BOTTOM_BAR_HEIGHT,
          paddingBottom: 'env(safe-area-inset-bottom)',
          backgroundColor: C.white,
          borderTop: `1px solid ${ C.border }`,
          display: 'flex',
          boxShadow: '0 -1px 3px rgba(0,0,0,0.04)',
        }}
      >
        {/* Sliding active indicator */}
        <div
          aria-hidden
          style={{
            position: 'absolute', top: 0, left: 0,
            width: '25%', height: 3,
            backgroundColor: C.primary,
            borderRadius: '0 0 3px 3px',
            transform: `translateX(${ activeIndex * 100 }%)`,
            opacity: activeIndex < 0 ? 0 : 1,
            transition: 'transform 0.22s ease, opacity 0.18s ease',
          }}
        />

        { TABS.map( ( { view, label, Icon } ) => {
          const isActive = ! drawerOpen && currentView === view;
          return (
            <button key={ view } onClick={ () => handleTab( view ) } aria-current={ isActive ? 'page' : undefined }
              style={{
                flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 3,
                border: 'none', backgroundColor: 'transparent', cursor: 'pointer',
                fontSize: 11, fontWeight: 600,
                color: isActive ? C.primary : C.mutedFg,
                transition: 'color 0.15s',
              }}>
              <Icon size={ 20 } />
              { label }
            </button>
          );
        } ) }

        {/* Menu tab → opens drawer */}
        <button onClick={ () => setDrawerOpen( ! drawerOpen ) } aria-expanded={ drawerOpen } aria-label="Menu"
          style={{
            flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 3,
            border: 'none', backgroundColor: 'transparent', cursor: 'pointer',
            fontSize: 11, fontWeight: 600,
            color: drawerOpen ? C.primary : C.mutedFg,
            transition: 'color 0.15s',
          }}>
          <Menu size={ 20 } />
          Menu
        </button>
      </nav>
    </>
  );
}
