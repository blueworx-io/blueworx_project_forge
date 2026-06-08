import { useState, useRef, Fragment, useEffect, useCallback, useMemo, memo } from 'react';
import { useIsMobile } from '../hooks/useIsMobile';
import { ChevronDown, ChevronRight, AlertCircle, Calendar, Star, Target, CornerDownRight, PanelLeftClose, PanelLeftOpen, Plus, X, Loader2, BarChart3 } from 'lucide-react';
import { format, startOfWeek, addWeeks, eachWeekOfInterval, startOfQuarter, parseISO, addDays, getISOWeek } from 'date-fns';
import { Item, Release, Feature, Bug, Feedback, SubItem, AppSettings, CompanyDate } from '../types';
import { AddItemModal } from './AddItemModal';
import { updateCompanyDate, isAdmin } from '../api/wordpress';
import { useDragScroll } from '../hooks/useDragScroll';
import { useDataStore } from '../store/useDataStore';
import { useUIStore } from '../store/useUIStore';
import { getNestingParentId } from '../utils/nesting';
import { BOTTOM_BAR_HEIGHT } from './MobileNav';

// ── Design tokens ────────────────────────────────────────────────────────────
const C = {
  bg:           '#fafbfc',
  white:        '#ffffff',
  headerBg:     '#f8fafc',
  sidebarBg:    '#fcfdff',
  border:       '#e2e8f0',
  muted:        '#f1f5f9',
  mutedFg:      '#64748b',
  fg:           '#1a1f36',
  primary:      '#2563eb',
  primaryLight: '#dbeafe',
  release:  { bar: '#10b981', barElapsed: '#059669', bg: '#f0fdf4', text: '#065f46', border: '#a7f3d0' },
  feature:  { bar: '#3b82f6', bg: '#dbeafe',  text: '#1e3a8a', border: '#93c5fd', hover: '#bfdbfe' },
  subitem:  { bar: '#06b6d4', bg: '#cffafe',  text: '#164e63', border: '#67e8f9', hover: '#a5f3fc' },
  bug:      { bar: '#ef4444', bg: '#fee2e2',  text: '#7f1d1d', border: '#fca5a5', hover: '#fecaca' },
  feedback: { bar: '#8b5cf6', bg: '#ede9fe',  text: '#4c1d95', border: '#c4b5fd', hover: '#ddd6fe' },
  amber:    { bar: '#f59e0b', dot: '#d97706' },
};

function pct( date: Date, viewStart: Date, totalDays: number ) {
  return ( Math.ceil( ( date.getTime() - viewStart.getTime() ) / 86400000 ) / totalDays ) * 100;
}

function resolveReleaseDate( value: string, fallback: Date ): Date {
  if ( !value ) return fallback;
  const d = new Date( value );
  if ( !isNaN( d.getTime() ) ) return d;
  const weekNum = parseInt( value, 10 );
  if ( isNaN( weekNum ) ) return fallback;
  const year  = new Date().getFullYear();
  const jan4  = new Date( year, 0, 4 );
  const dow   = jan4.getDay();
  const week1Mon = addDays( jan4, -( dow === 0 ? 6 : dow - 1 ) );
  return addDays( week1Mon, ( weekNum - 1 ) * 7 );
}

// ── InlineBar ────────────────────────────────────────────────────────────────
interface InlineBarProps {
  label: string; hours: number; color: typeof C.feature;
  leftPct: number; widthPct: number; onClick: () => void; isNested?: boolean;
}

const InlineBar = memo( function InlineBar( { label, hours, color, leftPct, widthPct, onClick, isNested = false }: InlineBarProps ) {
  return (
    <div style={{ position: 'absolute', top: 0, bottom: 0, left: `${ leftPct }%`, width: `${ widthPct }%`, display: 'flex', alignItems: 'center', pointerEvents: 'none' }}>
      <button onClick={ onClick }
        style={{ width: '100%', height: 28, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 6, padding: '0 10px', backgroundColor: color.bg, border: `1px solid ${ color.border }`, borderRadius: 6, color: color.text, fontSize: 12, fontWeight: 500, cursor: 'pointer', boxShadow: '0 1px 2px rgba(0,0,0,0.06)', pointerEvents: 'auto', textAlign: 'left' }}
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
} );

// ── ReleaseGroup ─────────────────────────────────────────────────────────────
interface ReleaseGroupProps {
  release: Release;
  viewStart: Date;
  totalDays: number;
  timelineWidth: number;
  onItemClick: ( item: Item ) => void;
  onNavigate: ( leftPct: number ) => void;
  isSidebarOpen: boolean;
  features: Feature[];
  subitems: SubItem[];
  bugs: Bug[];
  feedback: Feedback[];
  today: Date;
}

const ReleaseGroup = memo( function ReleaseGroup( {
  release, viewStart, totalDays, timelineWidth, onItemClick, onNavigate, isSidebarOpen, features, subitems, bugs, feedback, today
}: ReleaseGroupProps ) {
  const [isExpanded, setIsExpanded] = useState( true );
  const isOver = release.totalTimeEstimate > release.capacity;
  const sidebarW = isSidebarOpen ? 320 : 48;

  const linkedFeatures = useMemo( () => {
    const ids = new Set( [ ...release.linkedFeatureIds, ...features.filter( f => f.releaseId === release.id ).map( f => f.id ) ] );
    return Array.from( ids ).map( id => features.find( f => f.id === id ) ).filter( Boolean ) as Feature[];
  }, [ release.linkedFeatureIds, release.id, features ] );

  const linkedBugs = useMemo( () => {
    const ids = new Set( [ ...release.linkedBugIds, ...bugs.filter( b => b.releaseId === release.id ).map( b => b.id ) ] );
    return Array.from( ids ).map( id => bugs.find( b => b.id === id ) ).filter( Boolean ) as Bug[];
  }, [ release.linkedBugIds, release.id, bugs ] );

  const linkedFeedback = useMemo( () => {
    const ids = new Set( [ ...release.linkedFeedbackIds, ...feedback.filter( f => f.releaseId === release.id ).map( f => f.id ) ] );
    return Array.from( ids ).map( id => feedback.find( f => f.id === id ) ).filter( Boolean ) as Feedback[];
  }, [ release.linkedFeedbackIds, release.id, feedback ] );

  // Only features host nested bug/feedback rows in the Gantt's feature-grouped
  // layout. Bugs/feedback linked to a sub-item fall through to release level
  // (see topLevelBugs/Feedback) — matching the spec's "else sit at release level".
  const inReleaseFeatureIds = useMemo( () => {
    const ids = new Set<string>();
    linkedFeatures.forEach( f => ids.add( f.id ) );
    return ids;
  }, [ linkedFeatures ] );

  const nestedChildren = useMemo( () => {
    const map: Record<string, ( Bug | Feedback )[]> = {};
    [ ...linkedBugs, ...linkedFeedback ].forEach( item => {
      const pid = getNestingParentId( item );
      if ( pid && inReleaseFeatureIds.has( pid ) ) {
        ( map[pid] ||= [] ).push( item );
      }
    } );
    return map;
  }, [ linkedBugs, linkedFeedback, inReleaseFeatureIds ] );

  const topLevelBugs     = useMemo( () => linkedBugs.filter(     b => { const p = getNestingParentId( b ); return ! ( p && inReleaseFeatureIds.has( p ) ); } ), [ linkedBugs, inReleaseFeatureIds ] );
  const topLevelFeedback = useMemo( () => linkedFeedback.filter( f => { const p = getNestingParentId( f ); return ! ( p && inReleaseFeatureIds.has( p ) ); } ), [ linkedFeedback, inReleaseFeatureIds ] );

  const { leftPct, widthPct, timeProgress, rStart, rEnd } = useMemo( () => {
    const rs = resolveReleaseDate( release.startWeek, today );
    const re = resolveReleaseDate( release.endWeek,   today );
    const lp = Math.max( 0, pct( rs, viewStart, totalDays ) );
    const rp = Math.min( 100, pct( re, viewStart, totalDays ) );
    const tp = re > rs
      ? Math.min( 100, Math.max( 0, ( ( today.getTime() - rs.getTime() ) / ( re.getTime() - rs.getTime() ) ) * 100 ) )
      : 0;
    return { leftPct: lp, widthPct: Math.max( 0, rp - lp ), timeProgress: tp, rStart: rs, rEnd: re };
  }, [ release.startWeek, release.endWeek, viewStart, totalDays, today ] );

  const sidebarCls  = isSidebarOpen
    ? 'border-r flex items-center gap-2 px-4 sticky left-0 z-10 transition-all overflow-hidden'
    : 'border-r flex items-center justify-center px-1 sticky left-0 z-10';
  // zIndex keeps the sticky left column pinned above the scrolled-past timeline
  // markers and bars so it never appears to detach while scrolling. (#18)
  const sidebarStyle: React.CSSProperties = {
    width: sidebarW, minWidth: sidebarW, flexShrink: 0,
    borderColor: C.border, backgroundColor: C.sidebarBg,
    paddingRight: isSidebarOpen ? 10 : undefined,
    zIndex: 20,
  };
  const indentStyle: React.CSSProperties = {
    width: sidebarW, minWidth: sidebarW, flexShrink: 0,
    borderColor: C.border, backgroundColor: C.sidebarBg,
    paddingRight: isSidebarOpen ? 10 : undefined,
    zIndex: 20,
  };
  const rowStyle: React.CSSProperties       = { display: 'flex', borderBottom: `1px solid ${ C.border }`, minHeight: 56 };
  const childRowStyle: React.CSSProperties  = { display: 'flex', borderBottom: `1px solid ${ C.border }`, minHeight: 48 };
  // Explicit width ensures bars are correctly positioned on mobile (no flex:1 collapse)
  const barArea: React.CSSProperties = { width: timelineWidth, flexShrink: 0, position: 'relative' };

  return (
    <>
      {/* Release row */}
      <div style={ rowStyle }>
        <div className={ sidebarCls } style={ sidebarStyle }>
          <button onClick={ () => setIsExpanded( !isExpanded ) } style={{ padding: 4, borderRadius: 4, cursor: 'pointer', display: 'flex', flexShrink: 0, background: 'none', border: 'none', color: C.mutedFg }}>
            { isExpanded ? <ChevronDown size={ 16 } /> : <ChevronRight size={ 16 } /> }
          </button>
          { isSidebarOpen && (
            <>
              <button onClick={ () => onNavigate( leftPct ) } title="Jump to this item on the timeline" style={{ flex: 1, display: 'flex', alignItems: 'center', gap: 8, textAlign: 'left', justifyContent: 'flex-start', minWidth: 0, cursor: 'pointer', background: 'none', border: 'none' }}>
                <span style={{ padding: '2px 8px', fontSize: 11, fontWeight: 700, borderRadius: 4, backgroundColor: C.release.bg, color: C.release.text, border: `1px solid ${ C.release.border }`, flexShrink: 0 }}>release</span>
                <span style={{ fontSize: 13, fontWeight: 600, color: C.fg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', textAlign: 'left' }}>{ release.name }</span>
              </button>
              { release.isBigWedgeCampaign && <span title="Big Wedge Campaign" style={{ display: 'flex', flexShrink: 0 }}><Star size={ 14 } style={{ color: '#7c3aed' }} /></span> }
              { isOver && <span title="Over capacity" style={{ display: 'flex', flexShrink: 0 }}><AlertCircle size={ 14 } style={{ color: '#ea580c' }} /></span> }
            </>
          ) }
        </div>
        <div style={ barArea }>
          <div style={{ position: 'absolute', top: 0, bottom: 0, left: `${ leftPct }%`, width: `${ widthPct }%`, display: 'flex', alignItems: 'center', pointerEvents: 'none' }}>
            <button
              onClick={ () => onItemClick( release ) }
              style={{
                width: '100%', height: 28, position: 'relative', overflow: 'hidden',
                background: timeProgress > 0
                  ? `linear-gradient(to right, ${ C.release.barElapsed } ${ timeProgress }%, ${ C.release.bar } ${ timeProgress }%)`
                  : C.release.bar,
                borderRadius: 6, border: '1px solid rgba(255,255,255,0.2)',
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '0 10px', cursor: 'pointer', boxShadow: '0 1px 3px rgba(0,0,0,0.1)',
                pointerEvents: 'auto',
              }}
            >
              <span style={{ fontSize: 11, fontWeight: 700, color: '#fff', textShadow: '0 1px 2px rgba(0,0,0,0.3)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '30%', position: 'relative' }}>
                { `${ format( rStart, 'MMM d' ) } – ${ format( rEnd, 'MMM d' ) }` }
              </span>
              { timeProgress > 0 && (
                <span style={{ fontSize: 11, fontWeight: 700, color: '#fff', textShadow: '0 1px 2px rgba(0,0,0,0.3)', position: 'absolute', left: '50%', transform: 'translateX(-50%)', whiteSpace: 'nowrap', maxWidth: '30%', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  { `${ Math.round( timeProgress ) }%` }
                </span>
              ) }
              <span style={{ fontSize: 11, fontWeight: 700, color: '#fff', textShadow: '0 1px 2px rgba(0,0,0,0.3)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '30%', position: 'relative', textAlign: 'right' }}>
                { `${ release.capacity }h cap` }
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
                      <button onClick={ () => onNavigate( leftPct ) } title="Jump to this item on the timeline" style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                        <span style={{ fontSize: 13, color: C.fg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ feature.name }</span>
                      </button>
                      <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ feature.timeEstimate }h</span>
                    </>
                  ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: C.feature.bar }} title={ feature.name } /> }
                </div>
                <div style={{ ...barArea, backgroundColor: '#eff6ff1a' }}>
                  <InlineBar label={ feature.name } hours={ feature.timeEstimate } color={ C.feature } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( feature ) } />
                </div>
              </div>
              { ( feature.subItemIds || [] ).map( subItemId => {
                const si = subitems.find( s => s.id === subItemId ) as SubItem | undefined;
                if ( !si || si.releaseId !== release.id ) return null;
                return (
                  <div key={ si.id } style={ childRowStyle }>
                    <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden relative' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                      style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 80 : undefined }}>
                      { isSidebarOpen ? (
                        <>
                          <CornerDownRight size={ 12 } style={{ color: C.mutedFg, opacity: 0.5, flexShrink: 0 }} />
                          <button onClick={ () => onNavigate( leftPct ) } title="Jump to this item on the timeline" style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                            <span style={{ fontSize: 13, color: C.mutedFg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ si.name }</span>
                          </button>
                          <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ si.timeEstimate }h</span>
                        </>
                      ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: C.subitem.bar }} title={ si.name } /> }
                    </div>
                    <div style={{ ...barArea, backgroundColor: '#ecfeff1a' }}>
                      <InlineBar label={ si.name } hours={ si.timeEstimate } color={ C.subitem } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( si ) } isNested />
                    </div>
                  </div>
                );
              } ) }
              { ( nestedChildren[feature.id] || [] ).map( child => (
                <div key={ child.id } style={ childRowStyle }>
                  <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden relative' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                    style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 80 : undefined }}>
                    { isSidebarOpen ? (
                      <>
                        <CornerDownRight size={ 12 } style={{ color: C.mutedFg, opacity: 0.5, flexShrink: 0 }} />
                        <button onClick={ () => onNavigate( leftPct ) } title="Jump to this item on the timeline" style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                          <span style={{ fontSize: 13, color: C.mutedFg, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ ( child as Bug ).title }</span>
                        </button>
                        <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ child.timeEstimate }h</span>
                      </>
                    ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: child.type === 'bug' ? C.bug.bar : C.feedback.bar }} title={ ( child as Bug ).title } /> }
                  </div>
                  <div style={{ ...barArea, backgroundColor: child.type === 'bug' ? '#fff1f21a' : '#faf5ff1a' }}>
                    <InlineBar label={ ( child as Bug ).title } hours={ child.timeEstimate } color={ child.type === 'bug' ? C.bug : C.feedback } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( child ) } isNested />
                  </div>
                </div>
              ) ) }
            </Fragment>
          ) ) }

          { topLevelBugs.map( bug => (
            <div key={ bug.id } style={ childRowStyle }>
              <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 56 : undefined }}>
                { isSidebarOpen ? (
                  <>
                    <button onClick={ () => onNavigate( leftPct ) } title="Jump to this item on the timeline" style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                      <span style={{ fontSize: 13, fontWeight: 500, color: C.bug.bar, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ bug.title }</span>
                    </button>
                    <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ bug.timeEstimate }h</span>
                  </>
                ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: C.bug.bar }} title={ bug.title } /> }
              </div>
              <div style={{ ...barArea, backgroundColor: '#fff1f21a' }}>
                <InlineBar label={ bug.title } hours={ bug.timeEstimate } color={ C.bug } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( bug ) } />
              </div>
            </div>
          ) ) }

          { topLevelFeedback.map( fb => (
            <div key={ fb.id } style={ childRowStyle }>
              <div className={ isSidebarOpen ? 'border-r flex items-center gap-2 sticky left-0 z-10 overflow-hidden' : 'border-r flex items-center justify-center px-1 sticky left-0 z-10' }
                style={{ ...indentStyle, paddingLeft: isSidebarOpen ? 56 : undefined }}>
                { isSidebarOpen ? (
                  <>
                    <button onClick={ () => onNavigate( leftPct ) } title="Jump to this item on the timeline" style={{ flex: 1, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', minWidth: 0 }}>
                      <span style={{ fontSize: 13, fontWeight: 500, color: C.feedback.bar, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{ fb.title }</span>
                    </button>
                    <span style={{ fontSize: 11, color: C.mutedFg, flexShrink: 0 }}>{ fb.timeEstimate }h</span>
                  </>
                ) : <div style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: C.feedback.bar }} title={ fb.title } /> }
              </div>
              <div style={{ ...barArea, backgroundColor: '#faf5ff1a' }}>
                <InlineBar label={ fb.title } hours={ fb.timeEstimate } color={ C.feedback } leftPct={ leftPct } widthPct={ widthPct } onClick={ () => onItemClick( fb ) } />
              </div>
            </div>
          ) ) }
        </>
      ) }
    </>
  );
} );

// ── Week popup ───────────────────────────────────────────────────────────────
function WeekPopup( { week, releases, companyDates, onClose }: { week: Date; releases: Release[]; companyDates: CompanyDate[]; onClose: () => void } ) {
  const navVisible = useIsMobile();
  const weekEnd = addDays( week, 6 );
  const weekNum = getISOWeek( week );
  const year    = week.getFullYear();

  const releasesThisWeek = releases.filter( r => {
    if ( !r.startWeek || !r.endWeek ) return false;
    const rs = resolveReleaseDate( r.startWeek, week );
    const re = resolveReleaseDate( r.endWeek,   week );
    return rs <= weekEnd && re >= week;
  } );

  const companyDatesThisWeek = companyDates.filter( cd => {
    if ( !cd.date ) return false;
    const d = parseISO( cd.date );
    return d >= week && d <= weekEnd;
  } );

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 55, backgroundColor: 'rgba(0,0,0,0.45)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16, bottom: navVisible ? `calc(${ BOTTOM_BAR_HEIGHT }px + env(safe-area-inset-bottom))` : 0 }}>
      <div style={{ backgroundColor: C.white, borderRadius: 12, boxShadow: '0 20px 60px rgba(0,0,0,0.2)', width: '100%', maxWidth: 420, maxHeight: '90vh', overflowY: 'auto', border: `1px solid ${ C.border }` }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: `1px solid ${ C.border }` }}>
          <div>
            <h3 style={{ fontSize: 15, fontWeight: 600, color: C.fg, margin: 0 }}>Week { weekNum }, { year }</h3>
            <p style={{ fontSize: 12, color: C.mutedFg, margin: '2px 0 0' }}>{ format( week, 'MMM d' ) } – { format( weekEnd, 'MMM d, yyyy' ) }</p>
          </div>
          <button onClick={ onClose } style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.mutedFg, display: 'flex' }}><X size={ 18 } /></button>
        </div>
        <div style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 14 }}>
          { releasesThisWeek.length > 0 && (
            <div>
              <p style={{ fontSize: 11, fontWeight: 600, color: C.mutedFg, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 8px' }}>Releases</p>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                { releasesThisWeek.map( r => (
                  <div key={ r.id } style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderRadius: 6, background: C.release.bg, border: `1px solid ${ C.release.border }` }}>
                    <span style={{ fontSize: 11, fontWeight: 700, padding: '1px 6px', borderRadius: 4, background: '#a7f3d0', color: C.release.text }}>release</span>
                    <span style={{ fontSize: 13, fontWeight: 500, color: C.fg, flex: 1 }}>{ r.name }</span>
                    <span style={{ fontSize: 11, color: C.mutedFg }}>{ r.startWeek === r.endWeek ? 'single week' : `${ format( resolveReleaseDate( r.startWeek, week ), 'MMM d' ) }–${ format( resolveReleaseDate( r.endWeek, week ), 'MMM d' ) }` }</span>
                  </div>
                ) ) }
              </div>
            </div>
          ) }
          { companyDatesThisWeek.length > 0 && (
            <div>
              <p style={{ fontSize: 11, fontWeight: 600, color: C.mutedFg, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 8px' }}>Events</p>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                { companyDatesThisWeek.map( cd => (
                  <div key={ cd.id } style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderRadius: 6, background: '#fffbeb', border: '1px solid #fde68a' }}>
                    <Target size={ 12 } style={{ color: '#d97706', flexShrink: 0 }} />
                    <span style={{ fontSize: 13, fontWeight: 500, color: C.fg, flex: 1 }}>{ cd.title }</span>
                    <span style={{ fontSize: 11, color: C.mutedFg }}>{ format( parseISO( cd.date ), 'MMM d' ) }</span>
                  </div>
                ) ) }
              </div>
            </div>
          ) }
          { releasesThisWeek.length === 0 && companyDatesThisWeek.length === 0 && (
            <p style={{ fontSize: 13, color: C.mutedFg, margin: 0, textAlign: 'center' }}>Nothing scheduled this week.</p>
          ) }
        </div>
        <div style={{ padding: '0 20px 20px', display: 'flex', justifyContent: 'flex-end' }}>
          <button onClick={ onClose } style={{ padding: '7px 16px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: `1px solid ${ C.border }`, background: C.white, color: C.fg, cursor: 'pointer' }}>Close</button>
        </div>
      </div>
    </div>
  );
}

// ── Main component ───────────────────────────────────────────────────────────
interface GanttTimelineProps {
  settings?: AppSettings;
}

export function GanttTimeline( { settings }: GanttTimelineProps ) {
  const features      = useDataStore( s => s.features );
  const subitems      = useDataStore( s => s.subitems );
  const bugs          = useDataStore( s => s.bugs );
  const feedback      = useDataStore( s => s.feedback );
  const companyDates  = useDataStore( s => s.companyDates );
  const storeReleases = useDataStore( s => s.releases );
  const triggerRefresh = useDataStore( s => s.triggerRefresh );
  const openModal     = useUIStore( s => s.openModal );
  const adminMode     = isAdmin();
  const isMobile = useIsMobile( 768 );
  const navVisible = useIsMobile(); // bottom mobile menu is shown below 640px
  const [density,       setDensity]       = useState<'normal' | 'compact'>( () => window.innerWidth < 768 ? 'compact' : 'normal' );
  const [isSidebarOpen, setIsSidebarOpen] = useState( () => window.innerWidth >= 768 );
  const [addItemOpen,   setAddItemOpen]   = useState( false );
  const [datePopup,     setDatePopup]     = useState<CompanyDate | null>( null );
  const [dateEditing,   setDateEditing]   = useState( false );
  const [dateForm,      setDateForm]      = useState<CompanyDate | null>( null );
  const [dateSaving,    setDateSaving]    = useState( false );
  const [weekPopup,     setWeekPopup]     = useState<Date | null>( null );

  // bodyRef drives drag-scroll; headerRef is synced on scroll for fixed column headers
  const bodyRef   = useDragScroll<HTMLDivElement>();
  const headerRef = useRef<HTMLDivElement>( null );

  const today     = useMemo( () => new Date(), [] );
  const sidebarW  = isSidebarOpen ? 320 : 48;
  const weekW     = density === 'compact' ? 64 : 128;

  const releases = useMemo( () =>
    [ ...storeReleases ].sort( ( a, b ) =>
      resolveReleaseDate( a.startWeek, today ).getTime() - resolveReleaseDate( b.startWeek, today ).getTime()
    ), [ storeReleases, today ] );

  const { viewStart, totalDays, weeks } = useMemo( () => {
    const resolvedTimes = releases
      .map( r => ( {
        start: resolveReleaseDate( r.startWeek, today ).getTime(),
        end:   resolveReleaseDate( r.endWeek,   today ).getTime(),
      } ) )
      .filter( t => !isNaN( t.start ) && !isNaN( t.end ) );

    const minTime = resolvedTimes.length > 0 ? Math.min( ...resolvedTimes.map( t => t.start ) ) : today.getTime();
    const maxTime = resolvedTimes.length > 0 ? Math.max( ...resolvedTimes.map( t => t.end ) )   : today.getTime();

    const vs  = startOfWeek( addWeeks( new Date( minTime ), -5 ) );
    const ve  = startOfWeek( addWeeks( new Date( Math.max( maxTime, minTime ) ), 6 ) );
    const wks = eachWeekOfInterval( { start: vs, end: ve } );
    const td  = Math.ceil( ( ve.getTime() - vs.getTime() ) / 86400000 );

    return { viewStart: vs, totalDays: td, weeks: wks };
  }, [ releases, today ] );

  const quarterGroups = useMemo( () => {
    const groups: { quarter: string; weeks: Date[] }[] = [];
    weeks.forEach( week => {
      const qs    = startOfQuarter( week );
      const label = `Q${ Math.floor( qs.getMonth() / 3 ) + 1 } ${ qs.getFullYear() }`;
      const last  = groups[groups.length - 1];
      if ( last && last.quarter === label ) last.weeks.push( week );
      else groups.push( { quarter: label, weeks: [week] } );
    } );
    return groups;
  }, [ weeks ] );

  const todayPct     = useMemo( () => pct( today, viewStart, totalDays ), [ today, viewStart, totalDays ] );
  const timelineWidth = useMemo( () => weeks.length * weekW, [ weeks.length, weekW ] );

  // Keep header columns in sync with body horizontal scroll
  const syncHeader = useCallback( () => {
    if ( headerRef.current && bodyRef.current ) {
      headerRef.current.scrollLeft = bodyRef.current.scrollLeft;
    }
  }, [] ); // eslint-disable-line react-hooks/exhaustive-deps

  // Re-sync when sidebar or density changes (layout shift without a scroll event)
  useEffect( () => { syncHeader(); }, [ sidebarW, weekW, syncHeader ] );

  const scrollToToday = useCallback( () => {
    const body = bodyRef.current;
    if ( !body ) return;
    const todayPx  = timelineWidth * ( todayPct / 100 );
    const available = body.clientWidth - sidebarW;
    const target   = Math.max( 0, Math.min( todayPx - available / 2, body.scrollWidth - body.clientWidth ) );
    body.scrollLeft = target;
    if ( headerRef.current ) headerRef.current.scrollLeft = target;
  }, [ sidebarW, todayPct, timelineWidth ] ); // eslint-disable-line react-hooks/exhaustive-deps

  // Bring a release's bar into view (left-column items navigate instead of opening the modal)
  const scrollToPct = useCallback( ( leftPct: number ) => {
    const body = bodyRef.current;
    if ( !body ) return;
    const barPx     = timelineWidth * ( leftPct / 100 );
    const available = body.clientWidth - sidebarW;
    // Place the bar's start ~20% in from the left edge so it sits comfortably in view
    const target    = Math.max( 0, Math.min( barPx - available * 0.2, body.scrollWidth - body.clientWidth ) );
    // onScroll keeps the column header in sync during the smooth animation
    body.scrollTo( { left: target, behavior: 'smooth' } );
  }, [ sidebarW, timelineWidth ] ); // eslint-disable-line react-hooks/exhaustive-deps

  const scrollToTodayRef = useRef( scrollToToday );
  scrollToTodayRef.current = scrollToToday;

  useEffect( () => {
    let id2: number;
    const id1 = requestAnimationFrame( () => {
      id2 = requestAnimationFrame( () => scrollToTodayRef.current() );
    } );
    return () => { cancelAnimationFrame( id1 ); cancelAnimationFrame( id2 ); };
  }, [] );

  const pillBtn: React.CSSProperties = {
    display: 'inline-flex', alignItems: 'center', gap: 4,
    padding: '3px 8px', fontSize: 11, fontWeight: 600,
    backgroundColor: C.muted, color: C.fg,
    border: `1px solid ${ C.border }`, borderRadius: 6,
    cursor: 'pointer', whiteSpace: 'nowrap',
  };
  const todayBtn: React.CSSProperties = { ...pillBtn, backgroundColor: '#dbeafe', color: '#1d4ed8', border: '1px solid #bfdbfe' };

  return (
    <>
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>

      {/* ── Sub-header — always visible, never scrolls (shared spec, issue #16) ── */}
      <div style={{
        minHeight: 56,
        padding: isMobile ? '0 16px' : '0 24px',
        borderBottom: `1px solid ${ C.border }`,
        backgroundColor: C.white,
        display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8,
        flexShrink: 0,
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: isMobile ? 8 : 12, minWidth: 0 }}>
          <div style={{ padding: 8, backgroundColor: '#dbeafe', borderRadius: 8, color: C.primary, display: 'flex', flexShrink: 0 }}>
            <BarChart3 size={ isMobile ? 16 : 20 } />
          </div>
          <div style={{ minWidth: 0 }}>
            <h2 style={{ fontSize: isMobile ? 15 : 18, fontWeight: 600, color: C.fg, margin: 0, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>Release Timeline</h2>
            { !isMobile && <p style={{ fontSize: 13, color: C.mutedFg, margin: 0 }}>Track releases and linked items by week</p> }
          </div>
        </div>
        { adminMode && (
          <button onClick={ () => setAddItemOpen( true ) }
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: isMobile ? '7px 10px' : '7px 14px', fontSize: 13, fontWeight: 500, backgroundColor: '#f0fdf4', color: '#16a34a', border: '1px solid #bbf7d0', borderRadius: 8, cursor: 'pointer', flexShrink: 0 }}>
            <Plus size={ 15 } />{ !isMobile && <span>Add Item</span> }
          </button>
        ) }
      </div>

      {/* ── Timeline column headers — always visible, synced horizontally ── */}
      <div style={{ display: 'flex', flexShrink: 0, borderBottom: `2px solid ${ C.border }`, backgroundColor: C.headerBg }}>

        {/* Sidebar control cell */}
        <div style={{
          width: sidebarW, minWidth: sidebarW, flexShrink: 0,
          borderRight: `1px solid ${ C.border }`,
          backgroundColor: C.sidebarBg,
          transition: 'width 0.2s',
          overflow: 'hidden',
          zIndex: 2,
        }}>
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
                  <button onClick={ () => scrollToTodayRef.current() } style={ todayBtn }>
                    <Target size={ 12 } />Today
                  </button>
                </>
              ) }
            </div>
          </div>
        </div>

        {/* Quarter + week columns — hidden overflow, scrolled in sync with body */}
        <div ref={ headerRef } style={{ flex: 1, overflow: 'hidden' }}>
          <div style={{ minWidth: 'max-content', display: 'flex', flexDirection: 'column' }}>

            {/* Quarter row */}
            <div style={{ display: 'flex', borderBottom: `1px solid ${ C.border }` }}>
              { quarterGroups.map( ( group, idx ) => (
                <div key={ idx } style={{ width: group.weeks.length * weekW, flexShrink: 0, borderRight: `1px solid ${ C.border }`, backgroundColor: C.headerBg }}>
                  <div style={{ padding: '6px 16px', fontSize: 13, fontWeight: 700, color: C.fg, textAlign: 'center' }}>{ group.quarter }</div>
                </div>
              ) ) }
            </div>

            {/* Week row */}
            <div style={{ display: 'flex' }}>
              { weeks.map( ( week, idx ) => (
                <div key={ idx }
                  onClick={ () => setWeekPopup( week ) }
                  style={{ width: weekW, flexShrink: 0, borderRight: `1px solid ${ C.border }`, padding: '6px 4px', backgroundColor: C.headerBg, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}
                  onMouseEnter={ e => { ( e.currentTarget as HTMLDivElement ).style.backgroundColor = '#e2e8f0'; } }
                  onMouseLeave={ e => { ( e.currentTarget as HTMLDivElement ).style.backgroundColor = C.headerBg; } }
                >
                  <span style={{ fontSize: 11, fontWeight: 500, color: C.mutedFg, textAlign: 'center' }}>{ density === 'compact' ? format( week, 'd' ) : format( week, 'MMM d' ) }</span>
                </div>
              ) ) }
            </div>

          </div>
        </div>
      </div>

      {/* ── Scrollable timeline body ─────────────────────────────── */}
      <div
        ref={ bodyRef }
        data-scroll
        className="scrollbar-hide"
        onScroll={ syncHeader }
        style={{ flex: 1, overflow: 'auto', backgroundColor: '#f8fafc', cursor: 'grab' }}
      >
        <div style={{ minWidth: 'max-content', position: 'relative', minHeight: '100%' }}>

          {/* Company date markers */}
          { companyDates.filter( cd => cd.tracked ).map( cd => {
            const dateOffset = Math.ceil( ( parseISO( cd.date ).getTime() - viewStart.getTime() ) / 86400000 );
            const datePct    = ( dateOffset / totalDays ) * 100;
            if ( datePct < 0 || datePct > 100 ) return null;
            const markerLeft = sidebarW + ( datePct / 100 ) * timelineWidth;
            return (
              <div key={ cd.id } style={{ position: 'absolute', top: 0, bottom: 0, left: markerLeft, width: 2, backgroundColor: '#f59e0b', zIndex: 10, pointerEvents: 'none' }}>
                <div onClick={ () => { setDatePopup( cd ); setDateEditing( false ); setDateForm( { ...cd } ); } }
                  style={{ position: 'absolute', top: -2, left: -9, width: 20, height: 20, backgroundColor: '#f59e0b', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 2px 4px rgba(0,0,0,0.2)', pointerEvents: 'auto', cursor: 'pointer' }}
                  title={ cd.title }
                >
                  <Target size={ 11 } style={{ color: '#fff' }} />
                </div>
              </div>
            );
          } ) }

          {/* Today marker */}
          <div style={{ position: 'absolute', top: 0, bottom: 0, left: sidebarW + ( todayPct / 100 ) * timelineWidth, width: 2, backgroundColor: '#2563eb', zIndex: 10, pointerEvents: 'none' }}>
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
              <ReleaseGroup
                key={ release.id }
                release={ release }
                viewStart={ viewStart }
                totalDays={ totalDays }
                timelineWidth={ timelineWidth }
                onItemClick={ openModal }
                onNavigate={ scrollToPct }
                isSidebarOpen={ isSidebarOpen }
                features={ features }
                subitems={ subitems }
                bugs={ bugs }
                feedback={ feedback }
                today={ today }
              />
            ) )
          ) }
        </div>
      </div>
    </div>

    { adminMode && settings && (
      <AddItemModal isOpen={ addItemOpen } onClose={ () => setAddItemOpen( false ) } onSuccess={ () => { setAddItemOpen( false ); triggerRefresh(); } } settings={ settings } />
    ) }

    { weekPopup && (
      <WeekPopup week={ weekPopup } releases={ storeReleases } companyDates={ companyDates } onClose={ () => setWeekPopup( null ) } />
    ) }

    { datePopup && dateForm && (
      <div style={{ position: 'fixed', inset: 0, zIndex: 55, backgroundColor: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16, bottom: navVisible ? `calc(${ BOTTOM_BAR_HEIGHT }px + env(safe-area-inset-bottom))` : 0 }}>
        <div style={{ backgroundColor: C.white, borderRadius: 12, boxShadow: '0 20px 60px rgba(0,0,0,0.2)', width: '100%', maxWidth: 440, maxHeight: '90vh', overflowY: 'auto', border: `1px solid ${ C.border }` }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: `1px solid ${ C.border }` }}>
            <h3 style={{ fontSize: 16, fontWeight: 600, color: C.fg, margin: 0 }}>{ dateEditing ? 'Edit Date' : datePopup.title }</h3>
            <button onClick={ () => { setDatePopup( null ); setDateEditing( false ); } } style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.mutedFg, display: 'flex' }}><X size={ 20 } /></button>
          </div>
          <div style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 14 }}>
            { dateEditing ? (
              <>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 4 }}>Title
                  <input autoFocus type="text" value={ dateForm.title } onChange={ e => setDateForm( f => f ? { ...f, title: e.target.value } : f ) }
                    style={{ display: 'block', marginTop: 5, width: '100%', fontSize: 13, padding: '8px 10px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', boxSizing: 'border-box' as const }} />
                </label>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 4 }}>Date
                  <input type="date" value={ dateForm.date } onChange={ e => setDateForm( f => f ? { ...f, date: e.target.value } : f ) }
                    style={{ display: 'block', marginTop: 5, width: '100%', fontSize: 13, padding: '8px 10px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', boxSizing: 'border-box' as const }} />
                </label>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: C.fg, marginBottom: 4 }}>Description
                  <textarea value={ dateForm.description ?? '' } onChange={ e => setDateForm( f => f ? { ...f, description: e.target.value } : f ) }
                    style={{ display: 'block', marginTop: 5, width: '100%', fontSize: 13, padding: '8px 10px', border: `1px solid ${ C.border }`, borderRadius: 6, outline: 'none', minHeight: 72, resize: 'vertical', boxSizing: 'border-box' as const }} />
                </label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                  <input type="checkbox" checked={ !! dateForm.tracked } onChange={ e => setDateForm( f => f ? { ...f, tracked: e.target.checked } : f ) }
                    style={{ width: 15, height: 15, accentColor: C.primary }} />
                  <span style={{ fontSize: 13, fontWeight: 500, color: C.fg }}>Track on Timeline</span>
                </label>
              </>
            ) : (
              <>
                <div style={{ fontSize: 13, color: C.mutedFg }}>{ datePopup.date }</div>
                { datePopup.description && <p style={{ fontSize: 14, color: C.fg, margin: 0, lineHeight: 1.6 }}>{ datePopup.description }</p> }
                <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
                  <span style={{ padding: '2px 8px', borderRadius: 99, fontSize: 11, fontWeight: 600, background: datePopup.tracked ? '#f0fdf4' : '#f1f5f9', color: datePopup.tracked ? '#16a34a' : '#64748b' }}>
                    { datePopup.tracked ? 'Tracked on timeline' : 'Not tracked' }
                  </span>
                </div>
              </>
            ) }
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, padding: '0 20px 20px' }}>
            { dateEditing ? (
              <>
                <button onClick={ () => setDateEditing( false ) }
                  style={{ padding: '7px 14px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: `1px solid ${ C.border }`, background: C.white, color: C.fg, cursor: 'pointer' }}>Cancel</button>
                <button disabled={ dateSaving }
                  onClick={ async () => {
                    if ( !dateForm ) return;
                    setDateSaving( true );
                    try {
                      if ( window.forgePMData ) await updateCompanyDate( dateForm.id, dateForm );
                      triggerRefresh();
                      setDatePopup( { ...dateForm } );
                      setDateEditing( false );
                    } finally { setDateSaving( false ); }
                  } }
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: 'none', background: C.primary, color: '#fff', cursor: dateSaving ? 'not-allowed' : 'pointer', opacity: dateSaving ? 0.7 : 1 }}>
                  { dateSaving && <Loader2 size={ 13 } style={{ animation: 'spin 1s linear infinite' }} /> }
                  Save Changes
                </button>
              </>
            ) : (
              <>
                <button onClick={ () => setDatePopup( null ) }
                  style={{ padding: '7px 14px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: `1px solid ${ C.border }`, background: C.white, color: C.fg, cursor: 'pointer' }}>Close</button>
                { adminMode && (
                  <button onClick={ () => setDateEditing( true ) }
                    style={{ padding: '7px 14px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: 'none', background: C.primary, color: '#fff', cursor: 'pointer' }}>Edit</button>
                ) }
              </>
            ) }
          </div>
        </div>
      </div>
    ) }
    <style>{ `@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }` }</style>
    </>
  );
}
