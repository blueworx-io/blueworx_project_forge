import { useState } from 'react';
import type { WorkItem } from '../types';
import { phaseOf } from '../phases';
import { axisFor, chainFor, isOverdue, placeOn, placeToday, spanOf, todayIso } from '../gantt';
import type { Span } from '../gantt';

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

  const axis = axisFor( dated.map( ( each ) => each.span ), today );
  const now = placeToday( axis, today );
  const chain = chainFor( items, selected );

  return (
    <div className="bwx-gantt" data-testid="bwx-gantt">
      <div className="bwx-gantt-chart">
        <div className="bwx-gantt-axis" data-testid="bwx-gantt-axis">
          <span className="bwx-gantt-axis-gutter" />
          <div className="bwx-gantt-axis-weeks">
            { axis.weeks.map( ( week ) => (
              <span key={ week.start } className="bwx-gantt-week" data-week={ week.start }>
                { week.label }
              </span>
            ) ) }
          </div>
        </div>

        <div className="bwx-gantt-rows">
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
                  { item.title }
                </span>

                <span className="bwx-gantt-track">
                  <button
                    type="button"
                    className="bwx-gantt-bar"
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
                      '--bar-phase': `var(--phase-${ phaseOf( item.stage ) })`,
                    } as React.CSSProperties }
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
                      { span.start } – { span.due }
                      { overdue && ' · overdue' }
                    </span>
                  </button>
                </span>
              </div>
            );
          } ) }
        </div>

        { 0 === dated.length && (
          <p className="bwx-gantt-empty" data-testid="bwx-gantt-empty">
            Nothing here has dates yet. Everything on this site is in the tray below.
          </p>
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
          <span className="bwx-eyebrow">No dates</span>
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
