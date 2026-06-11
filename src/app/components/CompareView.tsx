import { Fragment, useMemo, useState } from 'react';
import { ChevronRight, ChevronDown, Check, Minus } from 'lucide-react';
import { useShallow } from 'zustand/react/shallow';
import { AppSettings, Feature, SubItem } from '../types';
import { useDataStore } from '../store/useDataStore';
import { useUIStore } from '../store/useUIStore';
import { matchesFilters } from '../utils/filters';
import { sortItemsByName } from '../utils/sortItems';
import { useDragScroll } from '../hooks/useDragScroll';
import { FilterButton, ShareButton, SearchBox } from './ViewActions';

interface CompareViewProps {
  settings: AppSettings;
}

const C = {
  white:   '#ffffff',
  border:  '#e2e8f0',
  fg:      '#1a1f36',
  mutedFg: '#64748b',
  rowAlt:  '#f8fafc',
  high:    '#dc2626',
  medium:  '#d97706',
  low:     '#16a34a',
};

type Severity = 'High' | 'Medium' | 'Low';

interface Smart {
  severity: Severity;
  rank:     number; // for sorting (higher = build sooner)
  color:    string;
  price:    string;
  reason:   string; // plain-English logic behind the suggested price point
}

/**
 * Heuristic triage for the "Smart" columns (#14). The more competitor brands
 * already ship a feature, the more urgent it is to build (you're behind) and
 * the harder it is to charge for — it becomes table-stakes. Rare features are
 * differentiators you can charge a premium for.
 */
function computeSmart( brands: string[] | undefined, totalBrands: number ): Smart {
  const have = brands?.length ?? 0;
  const adoption = totalBrands > 0 ? have / totalBrands : 0;
  if ( adoption >= 0.66 ) return { severity: 'High',   rank: 3, color: C.high,   price: 'Free',    reason: 'Most competitors ship this — table stakes, hard to charge for.' };
  if ( adoption >= 0.33 ) return { severity: 'Medium', rank: 2, color: C.medium, price: 'Teaser',  reason: 'Some competitors have it — catching up, tease to upsell.' };
  return { severity: 'Low', rank: 1, color: C.low, price: 'Premium', reason: 'Rare — a differentiator you can charge a premium for.' };
}

const PRICE_LABEL: Record<string, string> = {
  scoping: 'Scoping', premium: 'Premium', teaser: 'Teaser', free: 'Free',
};

export function CompareView( { settings }: CompareViewProps ) {
  const features  = useDataStore( useShallow( s => s.features ) );
  const subitems  = useDataStore( useShallow( s => s.subitems ) );
  const filters   = useUIStore( s => s.filters );
  const openModal = useUIStore( s => s.openModal );

  const [expanded, setExpanded]   = useState<Set<string>>( () => new Set() );
  const [showLive, setShowLive]   = useState( true );
  const [showOther, setShowOther] = useState( true );

  // Click-and-drag scrolling (both axes), matching the Gantt view (#14).
  const scrollRef = useDragScroll<HTMLDivElement>( { axis: 'both' } );

  const brandCols   = settings.brands ?? [];
  const totalBrands = brandCols.length;
  const parentLabel = settings.parentBrand || 'Your Brand';

  // Sub-items grouped by their parent feature id, looked up once.
  const subsByFeature = useMemo( () => {
    const map: Record<string, SubItem[]> = {};
    subitems.forEach( si => {
      if ( ! si.parentFeatureId ) return;
      ( map[si.parentFeatureId] ||= [] ).push( si );
    } );
    Object.keys( map ).forEach( id => { map[id] = sortItemsByName( map[id] ) as SubItem[]; } );
    return map;
  }, [subitems] );

  // Filtered features split into Live (enabled) and All Other (disabled). The
  // "other" group is ordered by triage severity so what to build next is first.
  const { live, other } = useMemo( () => {
    const visible = features.filter( f => matchesFilters( f, filters ) );
    const live  = sortItemsByName( visible.filter( f => f.isEnabled ) ) as Feature[];
    const other = ( visible.filter( f => ! f.isEnabled ) as Feature[] ).sort( ( a, b ) => {
      const d = computeSmart( b.brands, totalBrands ).rank - computeSmart( a.brands, totalBrands ).rank;
      return d !== 0 ? d : a.name.localeCompare( b.name );
    } );
    return { live, other };
  }, [features, filters, totalBrands] );

  const toggle = ( id: string ) =>
    setExpanded( prev => {
      const next = new Set( prev );
      if ( next.has( id ) ) next.delete( id ); else next.add( id );
      return next;
    } );

  // ── Cell renderers ────────────────────────────────────────────────────────
  const hasCell = ( has: boolean ) => (
    <td style={{ ...tdBase, textAlign: 'center' }}>
      { has
        ? <Check size={ 16 } style={{ color: C.low }} />
        : <Minus size={ 14 } style={{ color: '#cbd5e1' }} /> }
    </td>
  );

  const renderRow = (
    item: Feature | SubItem,
    opts: { isSub?: boolean; subCount?: number; isOpen?: boolean; onToggle?: () => void } = {},
  ) => {
    const { isSub = false, subCount = 0, isOpen = false, onToggle } = opts;
    const smart = computeSmart( item.brands, totalBrands );
    return (
      <tr key={ item.id } style={{ borderTop: `1px solid ${ C.border }`, backgroundColor: isSub ? C.rowAlt : C.white }}>
        <td style={{ ...tdBase, ...stickyCol, backgroundColor: isSub ? C.rowAlt : C.white, paddingLeft: isSub ? 36 : 12 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, width: '100%', minWidth: 0 }}>
            { ! isSub && subCount > 0 ? (
              <button onClick={ onToggle } aria-label={ isOpen ? 'Collapse' : 'Expand' } aria-expanded={ isOpen }
                style={{ flexShrink: 0, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 20, height: 20, border: 'none', background: 'transparent', cursor: 'pointer', color: C.mutedFg, padding: 0 }}>
                { isOpen ? <ChevronDown size={ 16 } /> : <ChevronRight size={ 16 } /> }
              </button>
            ) : <span style={{ width: isSub ? 0 : 20, flexShrink: 0 }} /> }
            <button onClick={ () => openModal( item ) }
              style={{ flex: 1, minWidth: 0, textAlign: 'left', border: 'none', background: 'transparent', cursor: 'pointer', padding: 0, color: C.fg, fontSize: 13, fontWeight: isSub ? 400 : 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              { item.name }
            </button>
            { ! isSub && subCount > 0 && (
              <span style={{ flexShrink: 0, fontSize: 11, fontWeight: 600, color: C.mutedFg, backgroundColor: '#f1f5f9', borderRadius: 10, padding: '1px 7px' }}>{ subCount }</span>
            ) }
          </div>
        </td>

        {/* Parent brand price tier */}
        <td style={{ ...tdBase, textAlign: 'center' }}>
          <span style={{ fontSize: 12, fontWeight: 600, color: item.featurePrice === 'scoping' ? C.mutedFg : C.fg }}>
            { PRICE_LABEL[item.featurePrice] ?? item.featurePrice }
          </span>
        </td>

        {/* Competitor "has it" columns */}
        { brandCols.map( b => hasCell( ( item.brands ?? [] ).includes( b.name ) ) ) }

        {/* Smart triage — Severity · Price Suggestion · Logic */}
        <td style={{ ...tdBase, textAlign: 'left', whiteSpace: 'nowrap' }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 600 }}>
            <span style={{ width: 8, height: 8, borderRadius: '50%', backgroundColor: smart.color, flexShrink: 0 }} />
            <span style={{ color: smart.color }}>{ smart.severity }</span>
          </span>
        </td>
        <td style={{ ...tdBase, textAlign: 'left', whiteSpace: 'nowrap' }}>
          <span style={{ fontSize: 12, fontWeight: 600, color: C.fg }}>{ smart.price }</span>
        </td>
        <td style={{ ...tdBase, textAlign: 'left', minWidth: 220 }}>
          <span style={{ fontSize: 12, color: C.mutedFg }}>{ smart.reason }</span>
        </td>
      </tr>
    );
  };

  const colCount = 2 + brandCols.length + 3;

  const sectionHeader = ( label: string, count: number, open: boolean, onToggle: () => void, sub: string ) => (
    <tr>
      <td colSpan={ colCount } style={{ padding: 0, position: 'sticky', left: 0, zIndex: 1 }}>
        <button onClick={ onToggle }
          style={{ width: '100%', display: 'block', padding: 0, border: 'none', borderTop: `1px solid ${ C.border }`, backgroundColor: '#f1f5f9', cursor: 'pointer', textAlign: 'left' }}>
          {/* Inner label pins to the left so it stays in view while the bar scrolls */}
          <span style={{ position: 'sticky', left: 0, display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 12px', whiteSpace: 'nowrap' }}>
            { open ? <ChevronDown size={ 16 } style={{ color: C.mutedFg }} /> : <ChevronRight size={ 16 } style={{ color: C.mutedFg }} /> }
            <span style={{ fontSize: 12, fontWeight: 700, letterSpacing: '0.04em', textTransform: 'uppercase', color: C.fg }}>{ label }</span>
            <span style={{ fontSize: 11, fontWeight: 600, color: C.mutedFg }}>({ count })</span>
            <span style={{ fontSize: 11, color: C.mutedFg, fontWeight: 500 }}>· { sub }</span>
          </span>
        </button>
      </td>
    </tr>
  );

  const renderFeatureBlock = ( list: Feature[] ) =>
    list.map( f => {
      const subs   = subsByFeature[f.id] ?? [];
      const isOpen = expanded.has( f.id );
      return (
        <Fragment key={ f.id }>
          { renderRow( f, { subCount: subs.length, isOpen, onToggle: () => toggle( f.id ) } ) }
          { isOpen && subs.map( si => renderRow( si, { isSub: true } ) ) }
        </Fragment>
      );
    } );

  return (
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
      {/* Top bar — shared toolbar spec, matching the other views (Filters · Share · Search) */}
      <div style={{ flexShrink: 0, minHeight: 56, padding: '0 16px', borderBottom: `1px solid ${ C.border }`, backgroundColor: C.white, display: 'flex', alignItems: 'center', gap: 8 }}>
        <FilterButton />
        <ShareButton />
        <SearchBox />
      </div>

      {/* Scrollable table — click-and-drag to pan, like the Gantt */}
      <div ref={ scrollRef } style={{ flex: 1, overflow: 'auto', minHeight: 0, cursor: 'grab' }}>
        <table style={{ borderCollapse: 'separate', borderSpacing: 0, width: '100%', minWidth: 640 }}>
          <thead>
            <tr>
              <th style={{ ...thBase, ...stickyCol, ...stickyHead, zIndex: 3, textAlign: 'left' }}>Feature</th>
              <th style={{ ...thBase, ...stickyHead, textAlign: 'center' }}>{ parentLabel }</th>
              { brandCols.map( b => (
                <th key={ b.name } style={{ ...thBase, ...stickyHead, textAlign: 'center', whiteSpace: 'nowrap' }}>{ b.name }</th>
              ) ) }
              <th style={{ ...thBase, ...stickyHead, textAlign: 'left' }}>Severity</th>
              <th style={{ ...thBase, ...stickyHead, textAlign: 'left' }}>Price Suggestion</th>
              <th style={{ ...thBase, ...stickyHead, textAlign: 'left' }}>Logic for Price Point</th>
            </tr>
          </thead>
          <tbody>
            { sectionHeader( 'Live Features', live.length, showLive, () => setShowLive( v => ! v ), 'Status = Enabled' ) }
            { showLive && ( live.length > 0
              ? renderFeatureBlock( live )
              : <tr><td colSpan={ colCount } style={{ ...tdBase, color: C.mutedFg, fontSize: 13 }}>No live features match the current filters.</td></tr> ) }

            { sectionHeader( 'Scoping List', other.length, showOther, () => setShowOther( v => ! v ), 'sorted by build-next severity' ) }
            { showOther && ( other.length > 0
              ? renderFeatureBlock( other )
              : <tr><td colSpan={ colCount } style={{ ...tdBase, color: C.mutedFg, fontSize: 13 }}>No other features match the current filters.</td></tr> ) }
          </tbody>
        </table>
      </div>
    </div>
  );
}

const thBase: React.CSSProperties = {
  padding: '10px 12px', fontSize: 11, fontWeight: 700, letterSpacing: '0.03em',
  textTransform: 'uppercase', color: C.mutedFg, backgroundColor: C.white,
  borderBottom: `1px solid ${ C.border }`,
};

const stickyHead: React.CSSProperties = { position: 'sticky', top: 0, zIndex: 2 };

const tdBase: React.CSSProperties = { padding: '8px 12px', fontSize: 13, color: C.fg, verticalAlign: 'middle' };

// Fixed width so long parent names truncate inside the column instead of
// widening it and spilling over the data columns (auto table-layout ignores
// max-width on a <td>, so a hard width + overflow:hidden is required).
const LEFT_COL_W = 260;

const stickyCol: React.CSSProperties = {
  position: 'sticky', left: 0, zIndex: 1,
  width: LEFT_COL_W, minWidth: LEFT_COL_W, maxWidth: LEFT_COL_W,
  boxSizing: 'border-box', overflow: 'hidden',
  borderRight: `1px solid ${ C.border }`,
};
