import { useEffect, useRef, useState } from 'react';
import { CalendarRange } from 'lucide-react';
import type { WorkItem } from '../types';
import { PHASE_LABEL, phaseOf } from '../phases';
import type { Phase } from '../phases';
import { EmptyState } from '../kit';
import { axisFor, chainFor, isLive, isOverdue, placeOn, placeToday, scrollToToday, spanOf, todayIso } from '../gantt';
import type { Axis, Span, Zoom } from '../gantt';

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
 *
 * Two zooms (#703): a column a day with today's column marked, which is how it
 * opens, or a column a week. Released work is left off at both — it is
 * finished, and a chart of what is still moving should not carry everything
 * that ever shipped. Neither looks further than two weeks back or six weeks
 * ahead, so there is never more to read than that.
 */
export function GanttView( {
  items: everything,
  onOpen,
  panelOpen = false,
}: {
  items: WorkItem[];
  onOpen: ( item: WorkItem ) => void;
  /** Whether an item's panel is open over the chart, so focus can come back when it closes. */
  panelOpen?: boolean;
} ) {
  const [ open, setOpen ] = useState( true );
  const [ selected, setSelected ] = useState( '' );
  const [ zoom, setZoom ] = useState< Zoom >( 'day' );
  const [ jump, setJump ] = useState( 0 );
  const chartRef = useRef< HTMLDivElement >( null );
  const opener = useRef< HTMLElement | null >( null );
  const wasOpen = useRef( false );
  // Where the opener lives, and the last thing focus was handed back to, so that
  // work deleted from its panel does not leave focus on a control that is gone.
  const origin = useRef< 'chart' | 'tray' >( 'chart' );
  const returned = useRef< HTMLElement | null >( null );
  // Whether the panel now open was opened from the chart or its tray, so focus
  // goes back here only for those, never for one opened from somewhere else.
  const mine = useRef( false );
  const trayToggle = useRef< HTMLButtonElement >( null );

  const items = everything.filter( isLive );

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

  const axis = axisFor( dated.map( ( each ) => each.span ), today, zoom );
  const now = placeToday( axis, today );

  // A selection only counts while its bar is still drawn. Work released since
  // it was picked drops off the chart, and so does work dated outside the
  // weeks shown; without this their rows would stay dimmed with nothing left
  // to click to put them back.
  const active = dated.some( ( each ) => each.item.id === selected && null !== placeOn( each.span, axis ) ) ? selected : '';
  const chain = chainFor( items, active );
  const months = monthsOf( axis );

  // Opening the day view, or asking for Today, brings today's column into view:
  // the chart starts up to two weeks behind, which on a phone is off screen.
  useEffect( () => {
    if ( 'day' !== zoom ) {
      return;
    }

    const chart = chartRef.current;
    const gutter = chart?.querySelector( '.bwx-gantt-axis-gutter' )?.getBoundingClientRect().width ?? 0;

    scrollToToday( chart, gutter );
    // Only when the zoom or the Today button changes, not on every redraw.
  }, [ zoom, jump ] );

  // When the panel closes, focus goes back to what opened it, so a keyboard
  // user keeps their place. If that is gone, it goes to the chart, or to the
  // tray when the work came from there, so the page does not jump.
  const landing = (): HTMLElement | null => {
    const from = opener.current;

    if ( from?.isConnected ) {
      return from;
    }

    return 'tray' === origin.current ? trayToggle.current : chartRef.current;
  };

  useEffect( () => {
    if ( wasOpen.current && ! panelOpen ) {
      if ( mine.current ) {
        const target = landing();

        target?.focus();
        returned.current = target;
      }

      mine.current = false;
    }

    wasOpen.current = panelOpen;
  }, [ panelOpen ] );

  // Work deleted from its panel leaves the chart a moment after the panel
  // closes: if focus was handed to its bar and the bar went, hand it on.
  useEffect( () => {
    const last = returned.current;

    if ( ! last ) {
      return;
    }

    const active = document.activeElement;

    if ( last.isConnected ) {
      if ( active !== last ) {
        returned.current = null;
      }

      return;
    }

    returned.current = null;

    if ( ! active || active === document.body ) {
      landing()?.focus();
    }
  }, [ everything ] );

  /**
   * Opens the full name upward from its row when downward would run out of the
   * chart, so the whole name can always be read.
   */
  const fitTip = ( label: HTMLElement ) => {
    const chart = chartRef.current;
    const tip = label.querySelector< HTMLElement >( '.bwx-gantt-name-tip' );

    if ( ! chart || ! tip ) {
      return;
    }

    tip.removeAttribute( 'data-flip' );
    tip.removeAttribute( 'style' );
    label.removeAttribute( 'data-dismissed' );

    // Hidden names take no room (a long one in the last row would otherwise add
    // blank scroll below it), so it is laid out only while it is measured.
    tip.style.display = 'block';

    // Only what the person can see counts: the chart may run on below the
    // bottom of the screen, or begin above the top of it.
    const box = chart.getBoundingClientRect();
    const seen = Math.min( box.top + chart.clientHeight, window.innerHeight );
    const top = Math.max( box.top, 0 );
    const own = tip.getBoundingClientRect();
    const row = label.getBoundingClientRect();

    if ( own.bottom > seen ) {
      if ( row.bottom - own.height >= top ) {
        tip.setAttribute( 'data-flip', 'true' );
      } else {
        // Taller than the room either way up (a few rows on a phone): drawn
        // over the screen instead, kept inside it, so the whole name still reads.
        tip.setAttribute( 'data-flip', 'screen' );
        tip.style.left = `${ row.left }px`;
        tip.style.width = `${ row.width }px`;
        tip.style.top = `${ Math.max( 8, Math.min( row.top, window.innerHeight - own.height - 8 ) ) }px`;
      }
    }

    tip.style.removeProperty( 'display' );
  };

  // Escape puts a full name away while the pointer or focus stays where it is.
  useEffect( () => {
    const dismiss = ( event: KeyboardEvent ) => {
      if ( 'Escape' !== event.key ) {
        return;
      }

      const shown = chartRef.current?.querySelectorAll< HTMLElement >( '.bwx-gantt-label:hover, .bwx-gantt-label:focus-within' );

      shown?.forEach( ( label ) => label.setAttribute( 'data-dismissed', 'true' ) );
    };

    document.addEventListener( 'keydown', dismiss );

    return () => document.removeEventListener( 'keydown', dismiss );
  }, [] );

  const phases = [ ...new Set( dated.map( ( each ) => phaseOf( each.item.stage ) ) ) ];

  return (
    <div className="bwx-gantt" data-testid="bwx-gantt">
      <div className="bwx-gantt-head">
        <span className="bwx-gantt-count bwx-mono">
          { `${ dated.length } dated · ${ undated.length } without dates` }
        </span>
        <div className="bwx-views" role="group" aria-label="Zoom" data-testid="bwx-gantt-zoom">
          { ZOOMS.map( ( [ level, label ] ) => (
            <button
              key={ level }
              type="button"
              className="bwx-button"
              data-variant={ level === zoom ? undefined : 'quiet' }
              data-testid={ `bwx-gantt-zoom-${ level }` }
              aria-pressed={ level === zoom }
              onClick={ () => setZoom( level ) }
            >
              { label }
            </button>
          ) ) }
        </div>
        { 'day' === zoom && (
          <div className="bwx-views" role="group" aria-label="Move along the days" data-testid="bwx-gantt-move">
            <button
              type="button"
              className="bwx-button"
              data-variant="quiet"
              data-testid="bwx-gantt-jump-today"
              onClick={ () => setJump( jump + 1 ) }
            >
              Today
            </button>
          </div>
        ) }
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

      <div
        ref={ chartRef }
        className="bwx-gantt-chart"
        data-testid="bwx-gantt-chart"
        // It scrolls sideways, so the keyboard needs a way in to move it.
        role="region"
        aria-label="Schedule, scrolls sideways"
        tabIndex={ 0 }
        data-zoom={ zoom }
        style={ { '--gantt-cols': axis.columns.length } as React.CSSProperties }
      >
        <div className="bwx-gantt-axis" data-testid="bwx-gantt-axis">
          <span className="bwx-gantt-axis-gutter" />
          <div className="bwx-gantt-axis-scale">
            <div className="bwx-gantt-months">
              { months.map( ( month ) => (
                <span key={ month.label } className="bwx-gantt-month" style={ { flex: month.columns } }>
                  { month.label }
                </span>
              ) ) }
            </div>
            <div className="bwx-gantt-axis-weeks">
              { axis.columns.map( ( column ) => 'day' === axis.zoom ? (
                <span
                  key={ column.start }
                  className="bwx-gantt-week bwx-gantt-day"
                  data-testid="bwx-gantt-day"
                  data-day={ column.start }
                  data-today={ column.start === today ? 'true' : undefined }
                  data-weekend={ isWeekend( column.start ) ? 'true' : undefined }
                >
                  { /* Slides to stay in the room beside the names, so a date is always readable. */ }
                  <span className="bwx-gantt-day-label">
                    <span className="bwx-gantt-day-name">{ weekdayOf( column.start ) }</span>
                    { column.label }
                  </span>
                </span>
              ) : (
                <span key={ column.start } className="bwx-gantt-week" data-week={ column.start }>
                  { dayOf( column.start ) }
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

        <div className="bwx-gantt-rows">
          { /* One grid behind every row, one line per column. */ }
          <span className="bwx-gantt-grid" aria-hidden="true" />

          { /* Drawn once behind every row rather than per row, so it reads as
               one line down the chart instead of a dash on each bar. */ }
          { null !== now && (
            <span
              className="bwx-gantt-today"
              data-testid="bwx-gantt-today"
              style={ { left: `calc(var(--gantt-gutter) + var(--gantt-track) * ${ now / 100 })` } }
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
                data-dimmed={ '' !== active && null === role ? 'true' : undefined }
              >
                <span
                  className="bwx-gantt-label"
                  onMouseEnter={ ( event ) => fitTip( event.currentTarget ) }
                  onFocus={ ( event ) => fitTip( event.currentTarget ) }
                  onMouseLeave={ ( event ) => event.currentTarget.removeAttribute( 'data-dismissed' ) }
                  onBlur={ ( event ) => event.currentTarget.removeAttribute( 'data-dismissed' ) }
                >
                  <span className="bwx-gantt-dot" data-phase={ phase } aria-hidden="true" />
                  { null === place ? (
                    // No bar to open it from, so the name opens it, as in the tray.
                    <button
                      type="button"
                      className="bwx-gantt-title bwx-gantt-title-open"
                      data-testid="bwx-gantt-title-open"
                      onClick={ ( event ) => {
                        opener.current = event.currentTarget;
                        origin.current = 'chart';
                        mine.current = true;
                        onOpen( item );
                      } }
                    >
                      { item.title }
                    </button>
                  ) : (
                    // Focusable, so the full name shows from the keyboard too.
                    <span className="bwx-gantt-title" tabIndex={ 0 }>{ item.title }</span>
                  ) }
                  { /* It sits over the name, so a click on it does what a click on the name does. */ }
                  <span
                    className="bwx-gantt-name-tip"
                    data-testid="bwx-gantt-name-tip"
                    aria-hidden="true"
                    data-opens={ null === place ? 'true' : undefined }
                    onClick={ null === place ? ( event ) => {
                      opener.current = event.currentTarget.parentElement?.querySelector< HTMLElement >( '.bwx-gantt-title-open' ) ?? null;
                      origin.current = 'chart';
                      mine.current = true;
                      onOpen( item );
                    } : undefined }
                  >
                    { item.title }
                  </span>
                </span>

                <span className="bwx-gantt-track">
                  { null === place ? (
                    <span className="bwx-gantt-outside">
                      { `${ rangeWithYear( span.start, span.due ) } is outside the weeks shown${ overdue ? ' · overdue' : '' }` }
                    </span>
                  ) : (
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
                      aria-pressed={ item.id === active }
                      onClick={ ( event ) => {
                        opener.current = event.currentTarget;
                        origin.current = 'chart';
                        mine.current = true;
                        // One click opens it, as the name does, and still marks
                        // what it waits on and what waits on it.
                        setSelected( item.id === active ? '' : item.id );
                        onOpen( item );
                      } }
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
                  ) }
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
          ref={ trayToggle }
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
                  onClick={ ( event ) => {
                    opener.current = event.currentTarget;
                    origin.current = 'tray';
                    mine.current = true;
                    onOpen( item );
                  } }
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

const ZOOMS: Array< [ Zoom, string ] > = [
  [ 'week', 'Weeks' ],
  [ 'day', 'Days' ],
];

/** The month band over the columns: each month, and how many columns open in it. */
function monthsOf( axis: Axis ): Array< { label: string; columns: number } > {
  const months: Array< { label: string; columns: number } > = [];

  for ( const column of axis.columns ) {
    const label = new Date( `${ column.start }T00:00:00Z` ).toLocaleDateString( 'en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' } );
    const last = months[ months.length - 1 ];

    if ( last && last.label === label ) {
      last.columns += 1;
    } else {
      months.push( { label, columns: 1 } );
    }
  }

  return months;
}

/** "Mon", "Tue"…, spelt here for the same reason as the months below. */
function weekdayOf( date: string ): string {
  return [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ][ new Date( `${ date }T00:00:00Z` ).getUTCDay() ];
}

function isWeekend( date: string ): boolean {
  const weekday = new Date( `${ date }T00:00:00Z` ).getUTCDay();

  return 0 === weekday || 6 === weekday;
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

/** The same with the year, for work the chart does not draw: "28 Nov 2027" is not "28 Nov" of this year. */
function rangeWithYear( start: string, due: string ): string {
  const full = ( date: string ) => `${ spoken( date ) } ${ date.slice( 0, 4 ) }`;

  return start === due ? full( start ) : `${ full( start ) } – ${ full( due ) }`;
}

export type { Phase };
