import { useState, useRef, Fragment } from 'react';
import { ChevronDown, ChevronRight, AlertCircle, Calendar, Star, Target, CornerDownRight, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import { format, startOfWeek, addWeeks, eachWeekOfInterval, startOfQuarter, parseISO } from 'date-fns';
import { Item, Release, Feature, Bug, Feedback, SubItem } from '../types';
import { AppData } from '../App';
import { useDragScroll } from '../hooks/useDragScroll';

// ── Design tokens (exact Figma values) ──────────────────────────────────────
const C = {
  bg:           '#fafbfc',
  white:        '#ffffff',
  headerBg:     '#f8fafc',   // sticky header row background — opaque
  border:       '#e2e8f0',
  muted:        '#f1f5f9',
  mutedFg:      '#64748b',
  fg:           '#1a1f36',
  primary:      '#2563eb',
  primaryLight: '#dbeafe',
  release:  { bar: '#10b981', bg: '#f0fdf4',  text: '#065f46',  border: '#a7f3d0' },
  feature:  { bar: '#3b82f6', bg: '#dbeafe',  text: '#1e3a8a',  border: '#93c5fd', hover: '#bfdbfe' },
  subitem:  { bar: '#06b6d4', bg: '#cffafe',  text: '#164e63',  border: '#67e8f9', hover: '#a5f3fc' },
  bug:      { bar: '#ef4444', bg: '#fee2e2',  text: '#7f1d1d',  border: '#fca5a5', hover: '#fecaca' },
  feedback: { bar: '#8b5cf6', bg: '#ede9fe',  text: '#4c1d95',  border: '#c4b5fd', hover: '#ddd6fe' },
  amber:    { bar: '#f59e0b', dot: '#d97706' },
};

// ── Timeline bar positioning helper ─────────────────────────────────────────
function pct( date: Date, viewStart: Date, totalDays: number ) {
  return ( Math.ceil( ( date.getTime() - viewStart.getTime() ) / 86400000 ) / totalDays ) * 100;
}

// ── Inline bar for release children (features, bugs, feedback) ──────────────
interface InlineBarProps {
  label: string;
  hours: number;
  color: typeof C.feature;
  leftPct: number;
  widthPct: number;
  onClick: () => void;
  isNested?: boolean;
}

function InlineBar( { label, hours, color, leftPct, widthPct, onClick, isNested = false }: InlineBarProps ) {
  return (
    <div style={{ position: 'absolute', top: 0, bottom: 0, left: `${ leftPct }%`, width: `${ widthPct }%`, display: 'flex', alignItems: 'center', pointerEvents: 'none' }}>
      <button
        onClick={ onClick }
        style={{
          width: '100%', height: 28, display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          gap: 6, padding: '0 10px',
          backgroundColor: color.bg, border: `1px solid ${ color.border }`, borderRadius: 6,
          color: color.text, fontSize: 12, fontWeight: 500, cursor: 'pointer',
          boxShadow: '0 1px 2px rgba(0,0,0,0.06)',
          pointerEvents: 'auto', textAlign: 'left',
        }}
        onMouseEnter={ e => { ( e.currentTarget as HTMLButtonElement ).style.backgroundColor = color.hover ?? color.bg; } }
        onMouseLeave={ e => { ( e.currentTarget as HTMLButtonElement ).style.backgroundColor = color.bg; } }
      >
        <span style={{ display: 'flex', alignItems: 'center', gap: 4, flex: 1, minWidth: 0 }}>
          { isNested && <CornerDownRight size={ 10 } style={{ flexShrink: 0, opacity: 0.55 }} /> }
          <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{ label }</span>
        </span>
        <span style={{ opacity: 0.6, whiteSpace: 'nowrap', fontSize: 10, fontWeight: 600, flexShrink: 0 }}>{ hours }h</span>
      </button>
    </div>
  );
}

// ── Release group ────────────────────────────────────────────────────────────
interface ReleaseGroupProps {
  release: Release;
  viewStart: Date; viewEnd: Date;
  totalDays: number;
  onItemClick: ( item: Item ) => void;
  isSidebarOpen: boolean;
  data: AppData;
}

function ReleaseGroup( { release, viewStart, viewEnd, totalDays, onItemClick, isSidebarOpen, data }: ReleaseGroupProps ) {
  const [isExpanded, setIsExpanded] = useState( true );
  const isOver = release.totalTimeEstimate > release.capacity;

  const linkedFeatures = release.linkedFeatureIds.map( id => data.features.find( f => f.id === id ) ).filter( Boolean ) as Feature[];
  const linkedBugs     = release.linkedBugIds.map( id => data.bugs.find( b => b.id === id ) ).filter( Boolean ) as Bug[];
  const linkedFeedback = release.linkedFeedbackIds.map( id => data.feedback.find( f => f.id === id ) ).filter( Boolean ) as Feedback[];
  const allLinked = [ ...linkedFeatures, ...linkedBugs, ...linkedFeedback ];

  let totalEffort = 0, completedEffort = 0;
  allLinked.forEach( item => {
    totalEffort += item.timeEstimate || 0;
    if ( ['active-features', 'staging-features'].includes( item.workflowStage ) ) completedEffort += item.timeEstimate || 0;
  } );
  const progress = totalEffort > 0 ? Math.round( ( completedEffort / totalEffort ) * 100 ) : 0;
  const remainingEffort = totalEffort - completedEffort;

  const rStart = new Date( release.startWeek );
  const rEnd   = new Date( release.endWeek );
  const leftPct  = Math.max( 0, pct( rStart, viewStart, totalDays ) );
  const rightPct = Math.min( 100, pct( rEnd, viewStart, totalDays ) );
  const widthPct = Math.max( 0, rightPct - leftPct );

  const sidebarW  = isSidebarOpen ? 320 : 48;
  const sidebarCls = isSidebarOpen ? 'border-r flex items-center gap-2 px-4 sticky left-0 z-10 transition-all overflow-hidden' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10';
  const sidebarStyle: React.CSSProperties = { width: sidebarW, minWidth: sidebarW, flexShrink: 0, borderColor: C.border, backgroundColor: C.white, paddingRight: isSidebarOpen ? 10 : undefined };
  const indentStyle: React.CSSProperties  = { width: sidebarW, minWidth: sidebarW, flexShrink: 0, borderColor: C.border, backgroundColor: C.white, paddingLeft: isSidebarOpen ? 56 : undefined, paddingRight: isSidebarOpen ? 10 : undefined };

  const rowStyle: React.CSSProperties = { display: 'flex', borderBottom: `1px solid ${ C.border }`, minHeight: 56 };
  const childRowStyle: React.CSSProperties = { display: 'flex', borderBottom: `1px solid ${ C.border }`, minHeight: 48 };

  return (
    <>
      {/* ── Release row ─────────────────────────────────────────── */}
      <div style={ rowStyle }>
        <div className={ sidebarCls } style={ sidebarStyle }>
          <button onClick={ () => setIsExpanded( !isExpanded ) } style={{ padding: 4, borderRadius: 4, cursor: 'pointer', display: 'flex', flexShrink: 0, background: 'none', border: 'none', color: C.mutedFg }}>
            { isExpanded ? <ChevronDown size={ 16 } /> : <ChevronRight size={ 16 } /> }
          </button>
          { isSidebarOpen && (
            <>
              <button onClick={ () => onItemClick( release ) } style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 8, textAlign: 'left', justifyContent: 'flex-start', minWidth: 0, cursor: 'pointer', background: 'none', border: 'none' }}>
                <span style={{ padding: '2px 8px', fontSize: 11, fontWeight: 700, borderRadius: 4, backgroundColor: C.release.bg, color: C.release.text, border: `1px solid ${ C.release.border }`, flexShrink: 0 }}>release</span>
                <span style={{ fontSize: 13, fontWeight: 600, color: C.fg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', textAlign: 'left' }}>{ release.name }</span>
              </button>
              { release.isBigWedgeCampaign && <Star size={ 14 } style={{ color: '#7c3aed', flexShrink: 0 }} title="Big Wedge Campaign" /> }
              { isOver && <AlertCircle size={ 14 } style={{ color: '#ea580c', flexShrink: 0 }} title="Over capacity" /> }
            </>
          ) }
        </div>
        <div style={{ flex: 1, minWidth: 0, position: 'relative' }}>
          {/* Release bar */}
          <div style={{ position: 'absolute', top: 0, bottom: 0, left: `${ leftPct }%`, width: `${ widthPct }%`, display: 'flex', alignItems: 'center', pointerEvents: 'none' }}>
            <button
              onClick={ () => onItemClick( release ) }
              style={{
                width: '100%', height: 28, position: 'relative', overflow: 'hidden',
                backgroundColor: C.release.bar, borderRadius: 6, border: '1px solid rgba(255,255,255,0.2)',
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '0 10px', cursor: 'pointer', boxShadow: '0 1px 3px rgba(0,0,0,0.1)',
                pointerEvents: 'auto',
              }}
            >
              { progress > 0 && <div style={{ position: 'absolute', inset: 0, background: 'linear-gradient(to right, rgba(255,255,255,0.2), transparent)', width: `${ progress }%`, pointerEvents: 'none' }} /> }
              <span style={{ fontSize: 11, fontWeight: 700, color: '#fff', textShadow: '0 1px 2px rgba(0,0,0,0.3)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '30%', position: 'relative' }}>
                { `${ format( rStart, 'MMM d' ) } - ${ format( rEnd, 'MMM d' ) }` }
              </span>
              <span style={{ fontSize: 11, fontWeight: 700, color: '#fff', textShadow: '0 1px 2px rgba(0,0,0,0.3)', position: 'absolute', left: '50%', transform: 'translateX(-50%)', whiteSpace: 'nowrap', maxWidth: '30%', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                { `${ progress }%` }
              </span>
              <span style={{ fontSize: 11, fontWeight: 700, color: '#fff', textShadow: '0 1px 2px rgba(0,0,0,0.3)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '30%', position: 'relative', textAlign: 'right' }}>
                { `${ remainingEffort }h remaining` }
              </span>
            </button>
          </div>
        </div>
      </div>

      { isExpanded && (
        <>
          { linkedFeatures.map( feature => (
            <Fragment key={ feature.id }>
              <div style={ childRowStyle }>
                <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                  style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 56 : undefined }}>
                  { isSidebarOpen ? (
                    <>
                      <button onClick={ () => onItemClick( feature ) } style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                        <span style={{ fontSize: 13, color: C.fg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ feature.name }</span>
                      </button>
                      <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ feature.timeEstimate }h</span>
                    </>
                  ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: C.feature.bar }} title={ feature.name } /> }
                </div>
                <div style={{ flex: 1, minWidth: 0, position: 'relative', backgroundColor: '#eff6ff1a' }}>
                  <InlineBar label={ feature.name } hours={ feature.timeEstimate } color={ C.feature } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( feature ) } />
                </div>
              </div>

              { ( feature.subItemIds || [] ).map( subItemId => {
                const si = data.subitems.find( s => s.id === subItemId ) as SubItem | undefined;
                if ( !si ) return null;
                return (
                  <div key={ si.id } style={ childRowStyle }>
                    <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden relative' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                      style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 80 : undefined }}>
                      { isSidebarOpen ? (
                        <>
                          <CornerDownRight size={ 12 } style={{ color: C.mutedFg, opacity: 0.5, flexShrink: 0 }} />
                          <button onClick={ () => onItemClick( si ) } style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                            <span style={{ fontSize: 13, color: C.mutedFg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ si.name }</span>
                          </button>
                          <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ si.timeEstimate }h</span>
                        </>
                      ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: C.subitem.bar }} title={ si.name } /> }
                    </div>
                    <div style={{ flex: 1, minWidth: 0, position: 'relative', backgroundColor: '#ecfeff1a' }}>
                      <InlineBar label={ si.name } hours={ si.timeEstimate } color={ C.subitem } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( si ) } isNested />
                    </div>
                  </div>
                );
              } ) }
            </Fragment>
          ) ) }

          { linkedBugs.map( bug => (
            <div key={ bug.id } style={ childRowStyle }>
              <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 56 : undefined }}>
                { isSidebarOpen ? (
                  <>
                    <button onClick={ () => onItemClick( bug ) } style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                      <span style={{ fontSize: 13, color: C.fg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ bug.title }</span>
                    </button>
                    <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ bug.timeEstimate }h</span>
                  </>
                ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: C.bug.bar }} title={ bug.title } /> }
              </div>
              <div style={{ flex: 1, minWidth: 0, position: 'relative', backgroundColor: '#fff1f21a' }}>
                <InlineBar label={ bug.title } hours={ bug.timeEstimate } color={ C.bug } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( bug ) } />
              </div>
            </div>
          ) ) }

          { linkedFeedback.map( fb => (
            <div key={ fb.id } style={ childRowStyle }>
              <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 56 : undefined }}>
                { isSidebarOpen ? (
                  <>
                    <button onClick={ () => onItemClick( fb ) } style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                      <span style={{ fontSize: 13, color: C.fg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ fb.title }</span>
                    </button>
                    <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ fb.timeEstimate }h</span>
                  </>
                ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: C.feedback.bar }} title={ fb.title } /> }
              </div>
              <div style={{ flex: 1, minWidth: 0, position: 'relative', backgroundColor: '#faf5ff1a' }}>
                <InlineBar label={ fb.title } hours={ fb.timeEstimate } color={ C.feedback } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( fb ) } />
              </div>
            </div>
          ) ) }
        </>
      ) }
    </>
  );
}

// ── Main component ───────────────────────────────────────────────────────────
interface GanttTimelineProps { data: AppData; onItemClick: ( item: Item ) => void; }

export function GanttTimeline( { data, onItemClick }: GanttTimelineProps ) {
  const [density, setDensity] = useState<'normal' | 'compact'>( 'normal' );
  const [isSidebarOpen, setIsSidebarOpen] = useState( true );
  const dragScrollRef = useDragScroll<HTMLDivElement>();
  const today = new Date( '2026-06-01' );

  const releases = data.releases;
  const minTime = releases.length > 0 ? Math.min( ...releases.map( r => new Date( r.startWeek ).getTime() ) ) : today.getTime();
  const maxTime = releases.length > 0 ? Math.max( ...releases.map( r => new Date( r.endWeek ).getTime() ) ) : today.getTime();

  const viewStart = startOfWeek( addWeeks( new Date( minTime ), -5 ) );
  const viewEnd   = startOfWeek( addWeeks( new Date( maxTime ), 6 ) );
  const weeks     = eachWeekOfInterval( { start: viewStart, end: viewEnd } );
  const totalDays = Math.ceil( ( viewEnd.getTime() - viewStart.getTime() ) / 86400000 );

  const quarterGroups: { quarter: string; weeks: Date[] }[] = [];
  weeks.forEach( week => {
    const qs    = startOfQuarter( week );
    const label = `Q${ Math.floor( qs.getMonth() / 3 ) + 1 } ${ qs.getFullYear() }`;
    const last  = quarterGroups[quarterGroups.length - 1];
    if ( last && last.quarter === label ) last.weeks.push( week );
    else quarterGroups.push( { quarter: label, weeks: [week] } );
  } );

  const todayPct  = pct( today, viewStart, totalDays );
  const sidebarW  = isSidebarOpen ? 320 : 48;
  const weekW     = density === 'compact' ? 64 : 128;

  const scrollToToday = () => {
    const container = dragScrollRef.current;
    if ( !container ) return;
    const tlWidth   = container.scrollWidth - sidebarW;
    const todayPx   = tlWidth * ( todayPct / 100 );
    const available = container.clientWidth - sidebarW;
    container.scrollTo( { left: Math.max( 0, todayPx - available / 2 ), behavior: 'smooth' } );
  };

  // Compact button style
  const pillBtn: React.CSSProperties = {
    display: 'inline-flex', alignItems: 'center', gap: 4,
    padding: '3px 8px', fontSize: 11, fontWeight: 600,
    backgroundColor: C.muted, color: C.fg,
    border: `1px solid ${ C.border }`, borderRadius: 6,
    cursor: 'pointer', whiteSpace: 'nowrap',
  };
  const todayBtn: React.CSSProperties = {
    ...pillBtn,
    backgroundColor: '#dbeafe', color: '#1d4ed8',
    border: '1px solid #bfdbfe',
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      {/* Sub-header */}
      <div style={{ padding: '12px 24px', borderBottom: `1px solid ${ C.border }`, backgroundColor: C.white }}>
        <h2 style={{ fontSize: 18, fontWeight: 600, color: C.fg, margin: 0 }}>Release Timeline</h2>
        <p style={{ fontSize: 13, color: C.mutedFg, margin: 0 }}>Track releases and linked items by week</p>
      </div>

      {/* Scrollable timeline */}
      <div ref={ dragScrollRef } className="scrollbar-hide" style={{ flex: 1, overflow: 'auto', backgroundColor: '#f8fafc', cursor: 'grab' }}>
        <div style={{ minWidth: 'max-content' }}>

          {/* ── Sticky column headers ─────────────────────────────── */}
          <div style={{ position: 'sticky', top: 0, zIndex: 20, display: 'flex', flexDirection: 'column', backgroundColor: C.headerBg }}>

            {/* Quarter row */}
            <div style={{ display: 'flex', borderBottom: `1px solid ${ C.border }` }}>
              <div style={{ width: sidebarW, minWidth: sidebarW, flexShrink: 0, borderRight: `1px solid ${ C.border }`, backgroundColor: C.headerBg, position: 'sticky', left: 0, zIndex: 30, transition: 'width 0.2s' }} />
              <div style={{ flex: 1, display: 'flex' }}>
                { quarterGroups.map( ( group, idx ) => (
                  <div key={ idx } style={{ width: `${ ( group.weeks.length / weeks.length ) * 100 }%`, borderRight: `1px solid ${ C.border }`, backgroundColor: C.headerBg }}>
                    <div style={{ padding: '6px 16px', fontSize: 13, fontWeight: 700, color: C.fg, textAlign: 'center' }}>{ group.quarter }</div>
                  </div>
                ) ) }
              </div>
            </div>

            {/* Week + sidebar controls row */}
            <div style={{ display: 'flex', borderBottom: `2px solid ${ C.border }` }}>
              <div style={{ width: sidebarW, minWidth: sidebarW, flexShrink: 0, borderRight: `1px solid ${ C.border }`, backgroundColor: C.headerBg, position: 'sticky', left: 0, zIndex: 30, transition: 'width 0.2s', overflow: 'hidden' }}>
                <div style={{ padding: '6px 8px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', height: '100%', gap: 4 }}>
                  { isSidebarOpen && <span style={{ fontSize: 11, fontWeight: 600, color: C.mutedFg }}>Item</span> }
                  <div style={{ display: 'flex', alignItems: 'center', gap: 4, flexWrap: 'nowrap' }}>
                    <button onClick={ () => setIsSidebarOpen( !isSidebarOpen ) } style={ pillBtn } title={ isSidebarOpen ? 'Collapse sidebar' : 'Expand sidebar' }>
                      { isSidebarOpen ? <PanelLeftClose size={ 13 } /> : <PanelLeftOpen size={ 13 } /> }
                    </button>
                    { isSidebarOpen && (
                      <>
                        <button onClick={ () => setDensity( d => d === 'normal' ? 'compact' : 'normal' ) } style={ pillBtn }>
                          { density === 'normal' ? 'Compact' : 'Normal' }
                        </button>
                        <button onClick={ scrollToToday } style={ todayBtn }>
                          <Target size={ 12 } />Today
                        </button>
                      </>
                    ) }
                  </div>
                </div>
              </div>
              <div style={{ flex: 1, display: 'flex' }}>
                { weeks.map( ( week, idx ) => (
                  <div key={ idx } style={{ width: weekW, flexShrink: 0, borderRight: `1px solid ${ C.border }`, padding: '6px 4px', backgroundColor: C.headerBg, display: 'flex', alignItems: 'center', justifyContent: 'center', transition: 'width 0.3s' }}>
                    <span style={{ fontSize: 11, fontWeight: 500, color: C.mutedFg, textAlign: 'center' }}>{ density === 'compact' ? format( week, 'd' ) : format( week, 'MMM d' ) }</span>
                  </div>
                ) ) }
              </div>
            </div>
          </div>

          {/* ── Timeline rows ─────────────────────────────────────── */}
          <div style={{ position: 'relative' }}>

            {/* Company date markers */}
            { data.companyDates.filter( cd => cd.tracked ).map( cd => {
              const dateOffset = Math.ceil( ( parseISO( cd.date ).getTime() - viewStart.getTime() ) / 86400000 );
              const datePct    = ( dateOffset / totalDays ) * 100;
              if ( datePct < 0 || datePct > 100 ) return null;
              return (
                <div key={ cd.id } style={{ position: 'absolute', top: 0, bottom: 0, left: `calc(${ sidebarW }px + (100% - ${ sidebarW }px) * ${ datePct / 100 })`, width: 2, backgroundColor: '#f59e0b', zIndex: 10, pointerEvents: 'none' }}>
                  <div style={{ position: 'absolute', top: -2, left: -9, width: 20, height: 20, backgroundColor: '#f59e0b', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 2px 4px rgba(0,0,0,0.2)', pointerEvents: 'auto', cursor: 'help' }} title={ cd.title }>
                    <Target size={ 11 } style={{ color: '#fff' }} />
                  </div>
                </div>
              );
            } ) }

            {/* Today marker */}
            <div style={{ position: 'absolute', top: 0, bottom: 0, left: `calc(${ sidebarW }px + (100% - ${ sidebarW }px) * ${ todayPct / 100 })`, width: 2, backgroundColor: '#2563eb', zIndex: 10, pointerEvents: 'none' }}>
              <div style={{ position: 'absolute', top: -2, left: -9, width: 20, height: 20, backgroundColor: '#2563eb', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 2px 4px rgba(0,0,0,0.2)' }}>
                <div style={{ width: 8, height: 8, backgroundColor: '#fff', borderRadius: '50%' }} />
              </div>
            </div>

            { releases.length === 0 ? (
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '96px 0' }}>
                <div style={{ textAlign: 'center' }}>
                  <Calendar size={ 64 } style={{ color: '#94a3b8', margin: '0 auto 16px' }} />
                  <h3 style={{ fontSize: 18, fontWeight: 500, color: C.fg, margin: '0 0 8px' }}>No releases scheduled</h3>
                  <p style={{ fontSize: 13, color: C.mutedFg, margin: 0 }}>Create your first release to start planning</p>
                </div>
              </div>
            ) : (
              releases.map( release => (
                <ReleaseGroup key={ release.id } release={ release } viewStart={ viewStart } viewEnd={ viewEnd } totalDays={ totalDays } onItemClick={ onItemClick } isSidebarOpen={ isSidebarOpen } data={ data } />
              ) )
            ) }
          </div>
        </div>
      </div>
    </div>
  );
}
