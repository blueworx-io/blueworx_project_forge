import { useState } from 'react';
import { CalendarRange } from 'lucide-react';
import type { WorkItem } from '../types';
import { PHASE_LABEL, phaseOf } from '../phases';
import type { Phase } from '../phases';
import { EmptyState } from '../kit';
import { axisFor, chainFor, isOverdue, placeOn, placeToday, spanOf, todayIso } from '../gantt';
import type { Axis, Span } from '../gantt';

/**
 * The same work, against time.
 *
 * The board answers "where is everything" and the list answers "what are the
 * details". This answers "when", and — the part a Gantt usually gets wrong —
 * "what has no when at all". Work with no dates cannot be drawn on a time axis,
 * so the usual thing is that it silently stops being on the screen, and nobody
 * notices that a third of the site was never planned. Here it goes in a tray
 * under the chart that is open by default and keeps its count when closed.
 *
 * It borrows the board's vocabulary rather than inventing one: the same phase
 * colour on each bar that the board puts on a column rail and the list puts
 * down the side of a row.
 *
 * Dependencies are marked, not drawn. Arrows across a chart of any size become
 * a thicket, and the question people actually ask is "what is either side of
 * this one" — which selecting a bar answers, by marking the step in each
 * direction and dimming the rest.
 *
 * Redrawn on the kit (2026-09-20): a month band over the weeks, a grid behind
 * the rows, today marked and named, a legend for the colours, and dates on a
 * bar written the way a person says them.
 */
export function GanttView( {
  items,
  onOpen,
}: {
  items: WorkItem[];
  onOpen: ( item: WorkItem ) => void;
} ) {
  const [ open, setOpen ] = useState( true );
  const [ selected, setSelected ] = useState( '' );

  const today = todayIso();

  const dated: Array< { item: WorkItem; span: Span } > = [];
  const undated: WorkItem[] = [];

  for ( const item of items ) {
    const span = spanOf( item );

    if ( null === span ) {
      undated.push( item );
      continue;
    }

    dated.push( { item, span } );
  }

  // Earliest first, so the chart reads top to bottom as the weeks run left to right.
  dated.sort( ( a, b ) => a.span.start.localeCompare( b.span.start ) || a.span.due.localeCompare( b.span.due ) );

  const axis = axisFor( dated.map( ( each ) => each.span ), today );
  const now = placeToday( axis, today );
  const chain = chainFor( items, selected );
  const months = monthsOf( axis );
  const phases = [ ...new Set( dated.map( ( each ) => phaseOf( each.item.stage ) ) ) ];

  return (
    <div className="bwx-gantt" data-testid="bwx-gantt">
      <div className="bwx-gantt-head">
        <span className="bwx-gantt-count bwx-mono">
          { `${ dated.length } dated · ${ undated.length } without dates` }
        </span>
        { 0 < phases.length && (
          <ul className="bwx-gantt-legend" aria-label="What the colours mean">
            { phases.map( ( phase ) => (
              <li key={ phase } data-phase={ phase }>
                <span className="bwx-gantt-swatch" aria-hidden="true" />
                { PHASE_LABEL[ phase ] }
              </li>
            ) ) }
          </ul>
        ) }
      </div>

      <div className="bwx-gantt-chart">
        <div className="bwx-gantt-axis" data-testid="bwx-gantt-axis">
          <span className="bwx-gantt-axis-gutter" />
          <div className="bwx-gantt-axis-scale">
            <div className="bwx-gantt-months">
              { months.map( ( month ) => (
                <span key={ month.label } className="bwx-gantt-month" style={ { flex: month.weeks } }>
                  { month.label }
                </span>
              ) ) }
            </div>
            <div className="bwx-gantt-axis-weeks">
              { axis.weeks.map( ( week ) => (
                <span key={ week.start } className="bwx-gantt-week" data-week={ week.start }>
                  { dayOf( week.start ) }
                </span>
              ) ) }
            </div>
            { null !== now && (
              <span className="bwx-gantt-today-tag" style={ { left: `${ now }%` } }>
                Today
              </span>
            ) }
          </div>
        </div>

        <div className="bwx-gantt-rows" style={ { '--gantt-weeks': axis.weeks.length } as React.CSSProperties }>
          { /* One grid behind every row, one line per week. */ }
          <span className="bwx-gantt-grid" aria-hidden="true" />

          { /* Drawn once behind every row rather than per row, so it reads as
               one line down the chart instead of a dash on each bar. */ }
          { null !== now && (
            <span
              className="bwx-gantt-today"
              data-testid="bwx-gantt-today"
              style={ { left: `calc(var(--gantt-gutter) + ${ now }% * var(--gantt-track))` } }
            />
          ) }

          { dated.map( ( { item, span } ) => {
            const place = placeOn( span, axis );
            const overdue = isOverdue( item, span, today );
            const role = chain[ item.id ] ?? null;
            const phase = phaseOf( item.stage );

            return (
              <div
                key={ item.id }
                className="bwx-gantt-row"
                data-testid="bwx-gantt-row"
                data-item={ item.id }
                data-level={ item.level }
                data-waiting={ 0 < ( item.waits_on?.length ?? 0 ) ? 'true' : undefined }
                data-chain={ role ?? undefined }
                data-dimmed={ '' !== selected && null === role ? 'true' : undefined }
              >
                <span className="bwx-gantt-label" title={ item.title }>
                  <span className="bwx-gantt-dot" data-phase={ phase } aria-hidden="true" />
                  <span className="bwx-gantt-title">{ item.title }</span>
                </span>

                <span className="bwx-gantt-track">
                  <button
                    type="button"
                    className="bwx-gantt-bar"
                    data-phase={ phase }
                    data-testid="bwx-gantt-bar"
                    data-item={ item.id }
                    data-start={ span.start }
                    data-due={ span.due }
                    data-derived={ span.derived ? 'true' : undefined }
                    data-overdue={ overdue ? 'true' : undefined }
                    data-milestone={ 'milestone' === item.level ? 'true' : undefined }
                    style={ {
                      left: `${ place.left }%`,
                      width: `${ place.width }%`,
                      '--bar-phase': `var(--phase-${ phase })`,
                    } as React.CSSProperties }
                    title={ `${ item.title }: ${ spoken( span.start ) } to ${ spoken( span.due ) }${ span.derived ? ' (from the work beneath it)' : '' }${ overdue ? ' · overdue' : '' }` }
                    aria-pressed={ item.id === selected }
                    onClick={ () => setSelected( item.id === selected ? '' : item.id ) }
                    onDoubleClick={ () => onOpen( item ) }
                  >
                    { /* The fill is how far its children have got, so a bar says
                         both when and how far in one mark. Work with nothing
                         beneath it has no progress to report and gets none. */ }
                    { 'empty' !== item.derived_state && undefined !== item.derived_state && (
                      <span
                        className="bwx-gantt-progress"
                        style={ { width: `${ item.progress ?? 0 }%` } }
                      />
                    ) }
                    <span className="bwx-gantt-bar-text">
                      { range( span.start, span.due ) }
                      { overdue && ' · overdue' }
                    </span>
                  </button>
                </span>
              </div>
            );
          } ) }
        </div>

        { 0 === dated.length && (
          <div className="bwx-gantt-empty" data-testid="bwx-gantt-empty">
            <EmptyState
              icon={ CalendarRange }
              dense
              title="Nothing here has dates yet"
              body="Give a task a start and a due date and it appears on the schedule. Everything on this site is in the tray below."
            />
          </div>
        ) }
      </div>

      { /*
         The tray, with the same weight as the chart above it. Work nobody has
         scheduled is not lesser work — it is the work most likely to be
         forgotten — so it is open unless somebody closes it, and says how much
         it holds either way.
       */ }
      <section className="bwx-gantt-tray" data-testid="bwx-gantt-tray" data-open={ open ? 'true' : 'false' }>
        <button
          type="button"
          className="bwx-gantt-tray-toggle"
          data-testid="bwx-gantt-tray-toggle"
          aria-expanded={ open }
          onClick={ () => setOpen( ! open ) }
        >
          <span className="bwx-eyebrow">Without dates</span>
          <span className="bwx-mono" data-testid="bwx-gantt-tray-count">
            { undated.length }
          </span>
        </button>

        { open && 0 < undated.length && (
          <ul className="bwx-gantt-tray-list">
            { undated.map( ( item ) => (
              <li key={ item.id }>
                <button
                  type="button"
                  className="bwx-gantt-tray-item"
                  data-testid="bwx-gantt-tray-item"
                  data-item={ item.id }
                  style={
                    { '--bar-phase': `var(--phase-${ phaseOf( item.stage ) })` } as React.CSSProperties
                  }
                  onClick={ () => onOpen( item ) }
                >
                  { item.title }
                </button>
              </li>
            ) ) }
          </ul>
        ) }

        { open && 0 === undated.length && (
          <p className="bwx-gantt-tray-empty">Everything here has dates.</p>
        ) }
      </section>
    </div>
  );
}

/** The month band over the weeks: each month, and how many weeks open in it. */
function monthsOf( axis: Axis ): Array< { label: string; weeks: number } > {
  const months: Array< { label: string; weeks: number } > = [];

  for ( const week of axis.weeks ) {
    const label = new Date( `${ week.start }T00:00:00Z` ).toLocaleDateString( 'en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' } );
    const last = months[ months.length - 1 ];

    if ( last && last.label === label ) {
      last.weeks += 1;
    } else {
      months.push( { label, weeks: 1 } );
    }
  }

  return months;
}

/** The day of the month a week opens on. */
function dayOf( date: string ): string {
  return String( new Date( `${ date }T00:00:00Z` ).getUTCDate() );
}

const MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];

/** "19 Sep", the way a person says a date. Spelt here: the browser's short month is "Sept" in some locales. */
function spoken( date: string ): string {
  const at = new Date( `${ date }T00:00:00Z` );

  return `${ at.getUTCDate() } ${ MONTHS[ at.getUTCMonth() ] }`;
}

/** "19 – 24 Sep" inside one month, "29 Sep – 3 Oct" across two. */
function range( start: string, due: string ): string {
  if ( start === due ) {
    return spoken( start );
  }

  return start.slice( 0, 7 ) === due.slice( 0, 7 )
    ? `${ dayOf( start ) } – ${ spoken( due ) }`
    : `${ spoken( start ) } – ${ spoken( due ) }`;
}

export type { Phase };
