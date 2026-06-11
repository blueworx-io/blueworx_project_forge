import { useState, useMemo, useRef, useEffect, useCallback } from 'react';
import { ChevronLeft, ChevronRight, ChevronDown, Calendar as CalendarIcon, CalendarRange, CalendarDays, Target, Plus, X, List, LayoutGrid } from 'lucide-react';
import {
  format, addMonths, subMonths, addWeeks, subWeeks, addDays, subDays,
  startOfMonth, endOfMonth, eachDayOfInterval,
  isSameMonth, isSameDay, isToday,
  startOfWeek, endOfWeek,
  parseISO, isWithinInterval, startOfDay, endOfDay,
} from 'date-fns';
import { CompanyDate, Release } from '../types';
import { formatDate } from '../utils/dates';
import { createCompanyDate, updateCompanyDate, isAdmin } from '../api/wordpress';
import { useIsMobile } from '../hooks/useIsMobile';
import { BOTTOM_BAR_HEIGHT } from './MobileNav';
import { useDataStore } from '../store/useDataStore';
import { useUIStore } from '../store/useUIStore';
import { matchesFilters } from '../utils/filters';
import { FilterButton, ShareButton, SearchBox } from './ViewActions';

// Design tokens
const C = {
  bg: '#fafbfc', white: '#ffffff', border: '#e2e8f0',
  fg: '#1a1f36', mutedFg: '#64748b', muted: '#f1f5f9',
  primary: '#2563eb', primaryFg: '#ffffff',
};

const btnPrimary: React.CSSProperties    = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: C.primary, color: C.primaryFg, border: 'none', borderRadius: 6, cursor: 'pointer', boxShadow: '0 1px 3px rgba(37,99,235,0.35)' };
const btnSecondary: React.CSSProperties  = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: C.white, color: C.fg, border: `1px solid ${ C.border }`, borderRadius: 6, cursor: 'pointer', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' };
const btnIcon: React.CSSProperties       = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 7, backgroundColor: C.white, color: C.mutedFg, border: `1px solid ${ C.border }`, cursor: 'pointer' };

type CalViewMode = 'month' | 'week' | 'day' | 'list';

export function CalendarView() {
  const storeReleases = useDataStore( s => s.releases );
  const features      = useDataStore( s => s.features );
  const subitems      = useDataStore( s => s.subitems );
  const bugs          = useDataStore( s => s.bugs );
  const feedbackItems = useDataStore( s => s.feedback );
  const companyDates = useDataStore( s => s.companyDates );
  const triggerRefresh = useDataStore( s => s.triggerRefresh );
  const openModal    = useUIStore( s => s.openModal );
  const filters      = useUIStore( s => s.filters );

  // Global view filters (#35). Company dates always show; releases are limited
  // to the chosen release, and (when stage/category/brand are set) to releases
  // that contain at least one matching item.
  const releases = useMemo( () => {
    let rs = filters.release === 'all' ? storeReleases : storeReleases.filter( r => r.id === filters.release );
    const searching = filters.search.trim() !== '';
    const itemFilterActive = filters.stage !== 'all' || filters.category !== 'all' || filters.brand !== 'all' || searching;
    if ( itemFilterActive ) {
      const matchingReleaseIds = new Set<string>();
      [ ...features, ...subitems, ...bugs, ...feedbackItems ].forEach( it => {
        if ( matchesFilters( it, filters ) && 'releaseId' in it && it.releaseId ) matchingReleaseIds.add( it.releaseId );
      } );
      // A release shows if it contains a matching item, or (when searching) its own name matches.
      rs = rs.filter( r => matchingReleaseIds.has( r.id ) || ( searching && matchesFilters( r, filters ) ) );
    }
    return rs;
  }, [ storeReleases, features, subitems, bugs, feedbackItems, filters ] );
  const adminMode    = isAdmin();
  const isMobile = useIsMobile( 900 );
  const [currentDate, setCurrentDate] = useState( () => new Date() );
  const [viewMode,    setViewMode]    = useState<CalViewMode>( 'month' );
  const [isAddingEvent, setIsAddingEvent] = useState( false );
  const [isSaving,      setIsSaving]      = useState( false );
  const [newEvent,      setNewEvent]      = useState( { title: '', date: format( new Date(), 'yyyy-MM-dd' ), description: '', tracked: false } );
  const [editingDate,   setEditingDate]   = useState<CompanyDate | null>( null );
  const [isEditSaving,  setIsEditSaving]  = useState( false );

  // Measure header heights for sticky offsets
  const appHeaderH   = useHeaderHeight( 'header' );
  const calHeaderRef = useRef<HTMLDivElement>( null );
  const [calHeaderH, setCalHeaderH] = useState( 57 );
  useEffect( () => {
    if ( !calHeaderRef.current ) return;
    const obs = new ResizeObserver( () => {
      if ( calHeaderRef.current ) setCalHeaderH( calHeaderRef.current.offsetHeight );
    } );
    obs.observe( calHeaderRef.current );
    return () => obs.disconnect();
  }, [] );

  // Hide the window scrollbar while the Calendar view is mounted — the page still
  // scrolls, just without the visible bar.
  useEffect( () => {
    document.documentElement.classList.add( 'forge-pm-hide-page-scroll' );
    return () => document.documentElement.classList.remove( 'forge-pm-hide-page-scroll' );
  }, [] );

  // ── Navigation ───────────────────────────────────────────────────────────
  const navPrev = () => {
    if ( viewMode === 'month' ) setCurrentDate( d => subMonths( d, 1 ) );
    else if ( viewMode === 'week' )  setCurrentDate( d => subWeeks( d, 1 ) );
    else if ( viewMode === 'day' )   setCurrentDate( d => subDays( d, 1 ) );
  };
  const navNext = () => {
    if ( viewMode === 'month' ) setCurrentDate( d => addMonths( d, 1 ) );
    else if ( viewMode === 'week' )  setCurrentDate( d => addWeeks( d, 1 ) );
    else if ( viewMode === 'day' )   setCurrentDate( d => addDays( d, 1 ) );
  };
  const navToday = () => setCurrentDate( new Date() );

  // ── Title ────────────────────────────────────────────────────────────────
  const navTitle = useMemo( () => {
    if ( viewMode === 'month' ) return format( currentDate, isMobile ? 'MMM yyyy' : 'MMMM yyyy' );
    if ( viewMode === 'week' ) {
      const ws = startOfWeek( currentDate );
      const we = endOfWeek( currentDate );
      return isMobile
        ? `${ format( ws, 'd MMM' ) } – ${ format( we, 'd' ) }`
        : `${ format( ws, 'd MMM' ) } – ${ format( we, 'd MMM yyyy' ) }`;
    }
    if ( viewMode === 'day' ) return format( currentDate, isMobile ? 'EEE, d MMM' : 'EEEE, d MMMM yyyy' );
    return 'Schedule';
  }, [ viewMode, currentDate, isMobile ] );

  // ── Month view data ──────────────────────────────────────────────────────
  const monthDays = useMemo( () => {
    const ms = startOfMonth( currentDate );
    return eachDayOfInterval( { start: startOfWeek( ms ), end: endOfWeek( endOfMonth( ms ) ) } );
  }, [ currentDate ] );

  // ── Week view data ───────────────────────────────────────────────────────
  const weekDays = useMemo( () =>
    eachDayOfInterval( { start: startOfWeek( currentDate ), end: endOfWeek( currentDate ) } ),
  [ currentDate ] );

  // ── Day view data ────────────────────────────────────────────────────────
  const { dayReleases, dayCompanyDates } = useMemo( () => ( {
    dayReleases: releases.filter( r =>
      r.startWeek && r.endWeek &&
      isWithinInterval( currentDate, { start: startOfDay( parseISO( r.startWeek ) ), end: endOfDay( parseISO( r.endWeek ) ) } )
    ),
    dayCompanyDates: companyDates.filter( cd => cd.date && isSameDay( parseISO( cd.date ), currentDate ) ),
  } ), [ currentDate, releases, companyDates ] );

  // ── List view data ───────────────────────────────────────────────────────
  const listItems = useMemo( () => {
    const rows: { date: string; kind: 'release-start' | 'release-end' | 'date'; label: string; id: string; itemRef: Release | CompanyDate }[] = [];
    releases.forEach( r => {
      if ( r.startWeek ) rows.push( { date: r.startWeek, kind: 'release-start', label: r.name, id: r.id,          itemRef: r } );
      if ( r.endWeek )   rows.push( { date: r.endWeek,   kind: 'release-end',   label: r.name, id: `${ r.id }-end`, itemRef: r } );
    } );
    companyDates.forEach( cd => {
      rows.push( { date: cd.date, kind: 'date', label: cd.title, id: cd.id, itemRef: cd } );
    } );
    return rows.sort( ( a, b ) => a.date.localeCompare( b.date ) );
  }, [ releases, companyDates ] );

  // ── Add / edit event handlers ────────────────────────────────────────────
  const handleAddEvent = async ( e: React.FormEvent ) => {
    e.preventDefault();
    if ( !newEvent.title || !newEvent.date ) return;
    setIsSaving( true );
    try {
      await createCompanyDate( newEvent );
      triggerRefresh();
    } finally {
      setIsSaving( false );
      setIsAddingEvent( false );
      setNewEvent( { title: '', date: format( new Date(), 'yyyy-MM-dd' ), description: '', tracked: false } );
    }
  };

  const handleEditDate = async ( e: React.FormEvent ) => {
    e.preventDefault();
    if ( !editingDate ) return;
    setIsEditSaving( true );
    try {
      await updateCompanyDate( editingDate.id, { title: editingDate.title, date: editingDate.date, description: editingDate.description, tracked: !! editingDate.tracked } );
      triggerRefresh();
    } finally {
      setIsEditSaving( false );
      setEditingDate( null );
    }
  };

  // Helper: events for a single day (used by month + week views)
  const getEventsForDay = ( day: Date ) => {
    const dayRels = releases.filter( r =>
      r.startWeek && r.endWeek &&
      isWithinInterval( day, { start: startOfDay( parseISO( r.startWeek ) ), end: endOfDay( parseISO( r.endWeek ) ) } )
    );
    const dayCDs = companyDates.filter( cd => cd.date && isSameDay( parseISO( cd.date ), day ) );
    return { dayReleases: dayRels, dayCompanyDates: dayCDs };
  };

  const showNav = viewMode !== 'list';

  // ── View switcher items ──────────────────────────────────────────────────
  const VIEW_TABS: { mode: CalViewMode; label: string; shortLabel: string; Icon: React.ElementType }[] = [
    { mode: 'month', label: 'Month', shortLabel: 'Mo', Icon: LayoutGrid },
    { mode: 'week',  label: 'Week',  shortLabel: 'Wk', Icon: CalendarRange },
    { mode: 'day',   label: 'Day',   shortLabel: 'Dy', Icon: CalendarDays },
    { mode: 'list',  label: 'List',  shortLabel: 'Li', Icon: List },
  ];

  // ── Shared header building blocks (unified across views — see issue #16) ──
  const iconTitle = (
    <div style={{ display: 'flex', alignItems: 'center', gap: isMobile ? 8 : 12, minWidth: 0 }}>
      <div style={{ padding: 8, backgroundColor: '#dbeafe', borderRadius: 8, color: C.primary, display: 'flex', flexShrink: 0 }}>
        <CalendarIcon size={ isMobile ? 16 : 20 } />
      </div>
      <h2 style={{ fontSize: isMobile ? 15 : 20, fontWeight: 700, color: C.fg, margin: 0, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{ navTitle }</h2>
    </div>
  );

  const navArrows = showNav && (
    <div style={{ display: 'flex', border: `1px solid ${ C.border }`, borderRadius: 6, overflow: 'hidden', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }}>
      <button onClick={ navPrev } style={{ ...btnIcon, borderRight: `1px solid ${ C.border }` }}>
        <ChevronLeft size={ 16 } />
      </button>
      <button onClick={ navNext } style={ btnIcon }>
        <ChevronRight size={ 16 } />
      </button>
    </div>
  );

  const addButton = adminMode && (
    <button onClick={ () => setIsAddingEvent( true ) } style={{ ...btnPrimary, padding: isMobile ? '7px 10px' : '7px 14px' }}>
      <Plus size={ 16 } />
      { !isMobile && <span>Add Date</span> }
    </button>
  );

  const todayButton = showNav && (
    <button onClick={ navToday } style={ btnSecondary }>Today</button>
  );

  const [viewDropOpen, setViewDropOpen] = useState( false );
  const viewDropRef = useRef<HTMLDivElement>( null );
  const closeViewDrop = useCallback( ( e: MouseEvent ) => {
    if ( viewDropRef.current && !viewDropRef.current.contains( e.target as Node ) ) setViewDropOpen( false );
  }, [] );
  useEffect( () => {
    if ( viewDropOpen ) document.addEventListener( 'mousedown', closeViewDrop );
    return () => document.removeEventListener( 'mousedown', closeViewDrop );
  }, [ viewDropOpen, closeViewDrop ] );

  const activeTab = VIEW_TABS.find( t => t.mode === viewMode )!;
  const ActiveIcon = activeTab.Icon;

  const viewSwitcher = (
    <div ref={ viewDropRef } style={{ position: 'relative' }}>
      <button
        onClick={ () => setViewDropOpen( o => !o ) }
        style={{ ...btnSecondary, gap: 6, padding: isMobile ? '6px 10px' : '7px 12px', fontSize: 13 }}
      >
        <ActiveIcon size={ 14 } />
        { !isMobile && <span>{ activeTab.label }</span> }
        <ChevronDown size={ 13 } style={{ opacity: 0.6 }} />
      </button>
      { viewDropOpen && (
        <div style={{
          position: 'absolute', top: 'calc(100% + 4px)', right: 0, zIndex: 200,
          backgroundColor: C.white, border: `1px solid ${ C.border }`, borderRadius: 8,
          boxShadow: '0 4px 16px rgba(0,0,0,0.12)', minWidth: 130, overflow: 'hidden',
        }}>
          { VIEW_TABS.map( ( { mode, label, Icon } ) => (
            <button
              key={ mode }
              onClick={ () => { setViewMode( mode ); setViewDropOpen( false ); } }
              style={{
                display: 'flex', alignItems: 'center', gap: 8, width: '100%',
                padding: '9px 14px', fontSize: 13, fontWeight: viewMode === mode ? 600 : 400,
                backgroundColor: viewMode === mode ? '#eff6ff' : 'transparent',
                color: viewMode === mode ? C.primary : C.fg,
                border: 'none', cursor: 'pointer', textAlign: 'left',
              }}
            >
              <Icon size={ 14 } />
              { label }
            </button>
          ) ) }
        </div>
      ) }
    </div>
  );

  // Canonical sub-header row: 56px tall, white, title-left / actions-right
  const headerRow: React.CSSProperties = {
    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
    gap: 8, minHeight: 56, padding: isMobile ? '0 16px' : '0 24px',
    backgroundColor: C.white,
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', backgroundColor: C.bg, flex: 1, minHeight: `calc(100dvh - ${ appHeaderH }px)` }}>

      {/* ── Calendar header — sticky below app header ─────────── */}
      { isMobile ? (
        <div ref={ calHeaderRef } style={{ position: 'sticky', top: appHeaderH, zIndex: 40, backgroundColor: C.white, borderBottom: `1px solid ${ C.border }` }}>
          {/* Primary row — matches the shared header height of every view */}
          <div style={ headerRow }>
            { iconTitle }
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <FilterButton />
              <ShareButton />
              { addButton }
            </div>
          </div>
          {/* Extra layer — calendar-only second row for nav + view switcher */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, minHeight: 48, padding: '0 16px', borderTop: `1px solid ${ C.border }` }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              { navArrows }
              { todayButton }
            </div>
            { viewSwitcher }
          </div>
          {/* Search row — full width so it isn't cramped beside the action buttons */}
          <div style={{ display: 'flex', alignItems: 'center', minHeight: 48, padding: '0 16px', borderTop: `1px solid ${ C.border }` }}>
            <SearchBox fullWidth />
          </div>
        </div>
      ) : (
        <div ref={ calHeaderRef } style={{ ...headerRow, borderBottom: `1px solid ${ C.border }`, position: 'sticky', top: appHeaderH, zIndex: 40 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
            { iconTitle }
            { navArrows }
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'nowrap' }}>
            <SearchBox />
            <FilterButton />
            <ShareButton />
            { addButton }
            { todayButton }
            <div style={{ width: 1, height: 24, backgroundColor: C.border }} />
            { viewSwitcher }
          </div>
        </div>
      ) }

      {/* ═══════════════════════════════════════════════════════════
          MONTH VIEW
      ════════════════════════════════════════════════════════════ */}
      { viewMode === 'month' && (
        <div style={{ display: 'flex', flexDirection: 'column', backgroundColor: '#f1f5f9', flex: 1 }}>

          {/* Day-of-week header */}
          <div style={{
            display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
            borderBottom: `1px solid ${ C.border }`, backgroundColor: C.white,
            position: 'sticky', top: appHeaderH + calHeaderH, zIndex: 10,
          }}>
            { ( isMobile ? ['S','M','T','W','T','F','S'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] ).map( ( d, i ) => (
              <div key={ i } style={{ padding: isMobile ? '8px 0' : '10px 0', textAlign: 'center', fontSize: isMobile ? 11 : 13, fontWeight: 600, color: C.mutedFg }}>{ d }</div>
            ) ) }
          </div>

          {/* Days grid */}
          <div style={{ flex: 1, display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0, 1fr))', gridAutoRows: `minmax(${ isMobile ? 72 : 120 }px, 1fr)` }}>
            { monthDays.map( ( day, idx ) => {
              const isCurrentMonth = isSameMonth( day, currentDate );
              const isDayToday     = isToday( day );
              const { dayReleases: rels, dayCompanyDates: cds } = getEventsForDay( day );
              return (
                <div key={ day.toString() } style={{
                  minHeight: isMobile ? 72 : 120, paddingBottom: isMobile ? 4 : 8,
                  borderBottom: `1px solid ${ C.border }`,
                  borderRight: `1px solid ${ C.border }`,
                  borderLeft: idx % 7 === 0 ? `1px solid ${ C.border }` : 'none',
                  display: 'flex', flexDirection: 'column',
                  overflow: 'hidden', minWidth: 0,
                  backgroundColor: isCurrentMonth ? C.white : '#f8fafc',
                }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: isMobile ? '4px 4px 2px' : '6px 8px 4px' }}>
                    <span style={{
                      fontSize: isMobile ? 11 : 13, fontWeight: 500,
                      width: isMobile ? 22 : 28, height: isMobile ? 22 : 28,
                      display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: '50%',
                      backgroundColor: isDayToday ? C.primary : 'transparent',
                      color: isDayToday ? '#fff' : isCurrentMonth ? C.fg : C.mutedFg,
                    }}>
                      { format( day, 'd' ) }
                    </span>
                  </div>
                  <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: isMobile ? 2 : 3, overflow: 'hidden' }}>
                    { rels.map( release => {
                      const isStart = isSameDay( day, parseISO( release.startWeek ) );
                      const isEnd   = isSameDay( day, parseISO( release.endWeek ) );
                      return (
                        <div key={ `rel-${ release.id }` } onClick={ () => openModal(release ) } style={{
                          cursor: 'pointer', padding: isMobile ? '2px 4px' : '3px 8px',
                          fontSize: isMobile ? 9 : 11, fontWeight: 500,
                          backgroundColor: '#f0fdf4', color: '#065f46',
                          borderTop: '1px solid #a7f3d0', borderBottom: '1px solid #a7f3d0',
                          borderLeft: isStart ? '1px solid #a7f3d0' : 'none',
                          borderRight: isEnd ? '1px solid #a7f3d0' : 'none',
                          borderRadius: isStart && isEnd ? 4 : isStart ? '4px 0 0 4px' : isEnd ? '0 4px 4px 0' : 0,
                          marginLeft: isStart ? ( isMobile ? 4 : 8 ) : 0,
                          marginRight: isEnd ? ( isMobile ? 4 : 8 ) : 0,
                          display: 'flex', alignItems: 'center', gap: 3, minHeight: isMobile ? 16 : 20,
                        }}>
                          { isStart && <div style={{ width: isMobile ? 5 : 6, height: isMobile ? 5 : 6, borderRadius: '50%', backgroundColor: '#10b981', flexShrink: 0 }} /> }
                          { !isMobile && (
                            <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', opacity: ( !isStart && day.getDay() !== 0 ) ? 0.5 : 1 }}>
                              { release.name }
                            </span>
                          ) }
                        </div>
                      );
                    } ) }
                    { cds.map( cd => (
                      <div key={ `cd-${ cd.id }` }
                        onClick={ adminMode ? () => setEditingDate( { ...cd } ) : undefined }
                        style={{
                          margin: `0 ${ isMobile ? 4 : 8 }px`,
                          padding: isMobile ? '3px 4px' : '4px 8px',
                          fontSize: isMobile ? 9 : 11, fontWeight: 600,
                          backgroundColor: '#fef3c7', color: '#92400e',
                          border: '1px solid #fcd34d', borderRadius: 4,
                          boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                          cursor: adminMode ? 'pointer' : 'default',
                        }}
                        title={ adminMode ? 'Click to edit' : cd.description }
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: 3, marginBottom: 1 }}>
                          <Target size={ isMobile ? 8 : 10 } />
                          <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ cd.title }</span>
                        </div>
                        { cd.description && !isMobile && <div style={{ fontSize: 10, fontWeight: 400, opacity: 0.75, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ cd.description }</div> }
                      </div>
                    ) ) }
                  </div>
                </div>
              );
            } ) }
          </div>
        </div>
      ) }

      {/* ═══════════════════════════════════════════════════════════
          WEEK VIEW
      ════════════════════════════════════════════════════════════ */}
      { viewMode === 'week' && (
        <div style={{ display: 'flex', flexDirection: 'column', backgroundColor: '#f1f5f9', flex: 1 }}>

          {/* Day-of-week header */}
          <div style={{
            display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
            borderBottom: `1px solid ${ C.border }`, backgroundColor: C.white,
            position: 'sticky', top: appHeaderH + calHeaderH, zIndex: 10,
          }}>
            { weekDays.map( ( day, i ) => {
              const isDayToday = isToday( day );
              return (
                <div key={ i } style={{ padding: isMobile ? '8px 4px' : '10px 8px', textAlign: 'center', borderRight: i < 6 ? `1px solid ${ C.border }` : 'none' }}>
                  <div style={{ fontSize: isMobile ? 10 : 12, fontWeight: 600, color: isDayToday ? C.primary : C.mutedFg, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                    { format( day, isMobile ? 'EEE' : 'EEEE' ) }
                  </div>
                  <div style={{
                    fontSize: isMobile ? 14 : 20, fontWeight: 700,
                    width: isMobile ? 28 : 36, height: isMobile ? 28 : 36,
                    display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                    borderRadius: '50%',
                    backgroundColor: isDayToday ? C.primary : 'transparent',
                    color: isDayToday ? '#fff' : C.fg,
                    marginTop: 2,
                  }}>
                    { format( day, 'd' ) }
                  </div>
                </div>
              );
            } ) }
          </div>

          {/* Week cells */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0, 1fr))', gridAutoRows: `minmax(${ isMobile ? 120 : 200 }px, 1fr)`, flex: 1 }}>
            { weekDays.map( ( day, idx ) => {
              const isDayToday = isToday( day );
              const { dayReleases: rels, dayCompanyDates: cds } = getEventsForDay( day );
              return (
                <div key={ day.toString() } style={{
                  minHeight: isMobile ? 120 : 200,
                  padding: isMobile ? '6px 4px' : '8px 6px',
                  borderRight: idx < 6 ? `1px solid ${ C.border }` : 'none',
                  borderBottom: `1px solid ${ C.border }`,
                  backgroundColor: isDayToday ? '#eff6ff' : C.white,
                  display: 'flex', flexDirection: 'column', gap: 4,
                }}>
                  { rels.map( release => {
                    const isStart = isSameDay( day, parseISO( release.startWeek ) );
                    const isEnd   = isSameDay( day, parseISO( release.endWeek ) );
                    return (
                      <div key={ `rel-${ release.id }` } onClick={ () => openModal(release ) } style={{
                        cursor: 'pointer', padding: isMobile ? '4px 6px' : '5px 8px',
                        fontSize: isMobile ? 10 : 12, fontWeight: 500,
                        backgroundColor: '#f0fdf4', color: '#065f46',
                        borderTop: '1px solid #a7f3d0', borderBottom: '1px solid #a7f3d0',
                        borderLeft: isStart ? '3px solid #10b981' : 'none',
                        borderRight: isEnd ? '1px solid #a7f3d0' : 'none',
                        borderRadius: isStart ? '4px 0 0 4px' : isEnd ? '0 4px 4px 0' : 0,
                        display: 'flex', alignItems: 'center', gap: 4,
                      }}>
                        <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: '#10b981', flexShrink: 0 }} />
                        <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ release.name }</span>
                      </div>
                    );
                  } ) }
                  { cds.map( cd => (
                    <div key={ `cd-${ cd.id }` }
                      onClick={ adminMode ? () => setEditingDate( { ...cd } ) : undefined }
                      style={{
                        padding: isMobile ? '4px 6px' : '5px 8px',
                        fontSize: isMobile ? 10 : 12, fontWeight: 600,
                        backgroundColor: '#fef3c7', color: '#92400e',
                        border: '1px solid #fcd34d', borderRadius: 4,
                        cursor: adminMode ? 'pointer' : 'default',
                        display: 'flex', alignItems: 'flex-start', gap: 4,
                      }}
                    >
                      <Target size={ 10 } style={{ flexShrink: 0, marginTop: 1 }} />
                      <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ cd.title }</span>
                        { cd.description && !isMobile && <span style={{ fontSize: 10, fontWeight: 400, opacity: 0.75 }}>{ cd.description }</span> }
                      </span>
                    </div>
                  ) ) }
                  { rels.length === 0 && cds.length === 0 && (
                    <div style={{ fontSize: 11, color: '#cbd5e1', textAlign: 'center', paddingTop: 8 }}>—</div>
                  ) }
                </div>
              );
            } ) }
          </div>
        </div>
      ) }

      {/* ═══════════════════════════════════════════════════════════
          DAY VIEW
      ════════════════════════════════════════════════════════════ */}
      { viewMode === 'day' && (
        <div style={{ padding: isMobile ? '16px 14px 40px' : '24px 24px 40px', maxWidth: 640, flex: 1 }}>
          { dayReleases.length === 0 && dayCompanyDates.length === 0 ? (
            <div style={{ padding: '48px 0', textAlign: 'center', color: C.mutedFg, fontSize: 14 }}>
              Nothing scheduled on { format( currentDate, 'd MMMM yyyy' ) }.
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
              { dayReleases.length > 0 && (
                <div>
                  <p style={{ fontSize: 11, fontWeight: 700, color: C.mutedFg, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 10px' }}>Active Releases</p>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                    { dayReleases.map( r => {
                      const isStart = isSameDay( currentDate, parseISO( r.startWeek ) );
                      const isEnd   = isSameDay( currentDate, parseISO( r.endWeek ) );
                      return (
                        <div key={ r.id } onClick={ () => openModal(r ) } style={{
                          display: 'flex', alignItems: 'center', gap: 12,
                          padding: '12px 16px', borderRadius: 8,
                          backgroundColor: '#f0fdf4', border: '1px solid #a7f3d0',
                          cursor: 'pointer',
                        }}>
                          <div style={{ width: 10, height: 10, borderRadius: '50%', backgroundColor: '#10b981', flexShrink: 0 }} />
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ fontSize: 14, fontWeight: 600, color: C.fg }}>{ r.name }</div>
                            <div style={{ fontSize: 12, color: C.mutedFg, marginTop: 2 }}>{ formatDate( r.startWeek ) } – { formatDate( r.endWeek ) }</div>
                          </div>
                          <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                            { isStart && <span style={{ padding: '2px 8px', borderRadius: 99, fontSize: 10, fontWeight: 700, backgroundColor: '#dcfce7', color: '#15803d' }}>Starts today</span> }
                            { isEnd   && <span style={{ padding: '2px 8px', borderRadius: 99, fontSize: 10, fontWeight: 700, backgroundColor: '#fef3c7', color: '#92400e' }}>Ends today</span> }
                            { !isStart && !isEnd && <span style={{ padding: '2px 8px', borderRadius: 99, fontSize: 10, fontWeight: 600, backgroundColor: '#f0fdf4', color: '#16a34a' }}>In progress</span> }
                          </div>
                        </div>
                      );
                    } ) }
                  </div>
                </div>
              ) }

              { dayCompanyDates.length > 0 && (
                <div>
                  <p style={{ fontSize: 11, fontWeight: 700, color: C.mutedFg, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 10px' }}>Events</p>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                    { dayCompanyDates.map( cd => (
                      <div key={ cd.id }
                        onClick={ adminMode ? () => setEditingDate( { ...cd } ) : undefined }
                        style={{
                          display: 'flex', alignItems: 'flex-start', gap: 12,
                          padding: '12px 16px', borderRadius: 8,
                          backgroundColor: '#fef3c7', border: '1px solid #fcd34d',
                          cursor: adminMode ? 'pointer' : 'default',
                        }}
                      >
                        <Target size={ 16 } style={{ color: '#d97706', flexShrink: 0, marginTop: 1 }} />
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ fontSize: 14, fontWeight: 600, color: C.fg }}>{ cd.title }</div>
                          { cd.description && <div style={{ fontSize: 12, color: C.mutedFg, marginTop: 4, lineHeight: 1.5 }}>{ cd.description }</div> }
                        </div>
                        { adminMode && <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>Edit</span> }
                      </div>
                    ) ) }
                  </div>
                </div>
              ) }
            </div>
          ) }
        </div>
      ) }

      {/* ═══════════════════════════════════════════════════════════
          LIST VIEW
      ════════════════════════════════════════════════════════════ */}
      { viewMode === 'list' && (
        <div style={{ padding: isMobile ? '0 14px 40px' : '0 24px 40px', flex: 1 }}>
          { listItems.length === 0 ? (
            <div style={{ padding: '64px 0', textAlign: 'center', color: C.mutedFg, fontSize: 14 }}>No scheduled items</div>
          ) : (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: isMobile ? 13 : 14 }}>
              <thead>
                <tr style={{ borderBottom: `2px solid ${ C.border }` }}>
                  { !isMobile && <th style={{ padding: '10px 12px', textAlign: 'left', fontSize: 11, fontWeight: 700, color: C.mutedFg, textTransform: 'uppercase', letterSpacing: '0.06em' }}>Date</th> }
                  <th style={{ padding: '10px 8px', textAlign: 'left', fontSize: 11, fontWeight: 700, color: C.mutedFg, textTransform: 'uppercase', letterSpacing: '0.06em' }}>Type</th>
                  <th style={{ padding: '10px 8px', textAlign: 'left', fontSize: 11, fontWeight: 700, color: C.mutedFg, textTransform: 'uppercase', letterSpacing: '0.06em' }}>Name</th>
                </tr>
              </thead>
              <tbody>
                { listItems.map( row => (
                  <tr
                    key={ row.id }
                    onClick={ () => {
                      if ( row.kind === 'date' ) { if ( adminMode ) setEditingDate( { ...row.itemRef } ); }
                      else openModal(row.itemRef );
                    } }
                    style={{ borderBottom: `1px solid ${ C.border }`, cursor: 'pointer', transition: 'background 0.1s' }}
                    onMouseEnter={ e => { ( e.currentTarget as HTMLTableRowElement ).style.background = C.muted; } }
                    onMouseLeave={ e => { ( e.currentTarget as HTMLTableRowElement ).style.background = 'transparent'; } }
                  >
                    { !isMobile && <td style={{ padding: '10px 12px', color: C.fg, whiteSpace: 'nowrap' }}>{ row.date }</td> }
                    <td style={{ padding: '10px 8px' }}>
                      { row.kind === 'release-start' && <span style={{ padding: '2px 7px', borderRadius: 99, fontSize: 11, fontWeight: 600, background: '#f0fdf4', color: '#16a34a', border: '1px solid #a7f3d0', whiteSpace: 'nowrap' }}>{ isMobile ? 'Start' : 'Release start' }</span> }
                      { row.kind === 'release-end'   && <span style={{ padding: '2px 7px', borderRadius: 99, fontSize: 11, fontWeight: 600, background: '#f0fdf4', color: '#16a34a', border: '1px solid #a7f3d0', whiteSpace: 'nowrap' }}>{ isMobile ? 'End' : 'Release end' }</span> }
                      { row.kind === 'date'           && <span style={{ padding: '2px 7px', borderRadius: 99, fontSize: 11, fontWeight: 600, background: '#fef3c7', color: '#92400e', border: '1px solid #fcd34d', whiteSpace: 'nowrap' }}>{ isMobile ? 'Event' : 'Company date' }</span> }
                    </td>
                    <td style={{ padding: '10px 8px', color: C.fg }}>
                      <div style={{ fontWeight: 500 }}>{ row.label }</div>
                      { isMobile && <div style={{ fontSize: 11, color: C.mutedFg, marginTop: 2 }}>{ row.date }</div> }
                    </td>
                  </tr>
                ) ) }
              </tbody>
            </table>
          ) }
        </div>
      ) }

      {/* ── Edit Date modal ─────────────────────────────────────── */}
      { editingDate && (
        <div style={{ position: 'fixed', inset: 0, zIndex: 55, backgroundColor: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: isMobile ? 'flex-end' : 'center', justifyContent: 'center', padding: isMobile ? 0 : 16, bottom: isMobile ? `calc(${ BOTTOM_BAR_HEIGHT }px + env(safe-area-inset-bottom))` : 0 }}>
          <div style={{ backgroundColor: C.white, borderRadius: isMobile ? '16px 16px 0 0' : 12, boxShadow: '0 20px 60px rgba(0,0,0,0.2)', width: '100%', maxWidth: isMobile ? '100%' : 440, maxHeight: '90vh', overflowY: 'auto', border: `1px solid ${ C.border }` }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: `1px solid ${ C.border }` }}>
              <h3 style={{ fontSize: 16, fontWeight: 600, color: C.fg, margin: 0 }}>Edit Company Date</h3>
              <button onClick={ () => setEditingDate( null ) } style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.mutedFg, display: 'flex' }}><X size={ 20 } /></button>
            </div>
            <form onSubmit={ handleEditDate } style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Title</label>
                <input autoFocus type="text" required value={ editingDate.title }
                  onChange={ e => setEditingDate( { ...editingDate, title: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', backgroundColor: C.white, boxSizing: 'border-box' }} />
              </div>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Date</label>
                <input type="date" required value={ editingDate.date }
                  onChange={ e => setEditingDate( { ...editingDate, date: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', backgroundColor: C.white, boxSizing: 'border-box' }} />
              </div>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Description (optional)</label>
                <textarea value={ editingDate.description ?? '' }
                  onChange={ e => setEditingDate( { ...editingDate, description: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', minHeight: 80, resize: 'vertical', backgroundColor: C.white, boxSizing: 'border-box' }} />
              </div>
              <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
                <input type="checkbox" checked={ !! editingDate.tracked }
                  onChange={ e => setEditingDate( { ...editingDate, tracked: e.target.checked } ) }
                  style={{ width: 16, height: 16, accentColor: C.primary }} />
                <span style={{ fontSize: 13, fontWeight: 500, color: C.fg }}>Track on Timeline</span>
              </label>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10 }}>
                <button type="button" onClick={ () => setEditingDate( null ) } style={ btnSecondary }>Cancel</button>
                <button type="submit" disabled={ isEditSaving } style={{ ...btnPrimary, opacity: isEditSaving ? 0.6 : 1 }}>
                  { isEditSaving ? 'Saving...' : 'Save Changes' }
                </button>
              </div>
            </form>
          </div>
        </div>
      ) }

      {/* ── Add Date modal ───────────────────────────────────────── */}
      { isAddingEvent && (
        <div style={{ position: 'fixed', inset: 0, zIndex: 55, backgroundColor: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: isMobile ? 'flex-end' : 'center', justifyContent: 'center', padding: isMobile ? 0 : 16, bottom: isMobile ? `calc(${ BOTTOM_BAR_HEIGHT }px + env(safe-area-inset-bottom))` : 0 }}>
          <div style={{ backgroundColor: C.white, borderRadius: isMobile ? '16px 16px 0 0' : 12, boxShadow: '0 20px 60px rgba(0,0,0,0.2)', width: '100%', maxWidth: isMobile ? '100%' : 440, maxHeight: '90vh', overflowY: 'auto', border: `1px solid ${ C.border }` }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: `1px solid ${ C.border }` }}>
              <h3 style={{ fontSize: 16, fontWeight: 600, color: C.fg, margin: 0 }}>Add Company Date</h3>
              <button onClick={ () => setIsAddingEvent( false ) } style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.mutedFg, display: 'flex' }}><X size={ 20 } /></button>
            </div>
            <form onSubmit={ handleAddEvent } style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Title</label>
                <input autoFocus type="text" required value={ newEvent.title } onChange={ e => setNewEvent( { ...newEvent, title: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', backgroundColor: C.white, boxSizing: 'border-box' }}
                  placeholder="e.g. Q3 Planning Summit" />
              </div>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Date</label>
                <input type="date" required value={ newEvent.date } onChange={ e => setNewEvent( { ...newEvent, date: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', backgroundColor: C.white, boxSizing: 'border-box' }} />
              </div>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 6 }}>Description (optional)</label>
                <textarea value={ newEvent.description } onChange={ e => setNewEvent( { ...newEvent, description: e.target.value } ) }
                  style={{ width: '100%', fontSize: 13, color: C.fg, padding: '8px 12px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', minHeight: 80, resize: 'vertical', backgroundColor: C.white, boxSizing: 'border-box' }}
                  placeholder="Add details about this event..." />
              </div>
              <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
                <input type="checkbox" checked={ newEvent.tracked } onChange={ e => setNewEvent( { ...newEvent, tracked: e.target.checked } ) } style={{ width: 16, height: 16, accentColor: C.primary }} />
                <span style={{ fontSize: 13, fontWeight: 500, color: C.fg }}>Track on Timeline</span>
              </label>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10 }}>
                <button type="button" onClick={ () => setIsAddingEvent( false ) } style={ btnSecondary }>Cancel</button>
                <button type="submit" disabled={ isSaving } style={{ ...btnPrimary, opacity: isSaving ? 0.6 : 1 }}>
                  { isSaving ? 'Saving...' : 'Save Date' }
                </button>
              </div>
            </form>
          </div>
        </div>
      ) }
    </div>
  );
}

function useHeaderHeight( selector: string ) {
  const [height, setHeight] = useState( () => {
    if ( typeof document === 'undefined' ) return 57;
    return document.querySelector( selector )?.clientHeight ?? 57;
  } );
  useEffect( () => {
    const el = document.querySelector( selector );
    if ( !el ) return;
    const obs = new ResizeObserver( () => setHeight( ( el as HTMLElement ).offsetHeight ) );
    obs.observe( el );
    return () => obs.disconnect();
  }, [selector] );
  return height;
}
