import type { WorkItem } from '../types';
import { phaseOf } from '../phases';

/**
 * The same work, as rows.
 *
 * The board answers "where is everything" and hides the columns that will not
 * fit on a card: when work starts, when it is due, who is on it, and how far a
 * parent's children have got. This view exists for those, and for nothing else
 * — it is not a second board with different styling.
 *
 * It borrows the board's vocabulary rather than inventing one: the same phase
 * rail on the left of each row, the same chips, the same short id. Two views of
 * one thing should look related, and the rail is what carries "which part of
 * the process is this in" without a column of its own.
 */
export function ListView( {
  items,
  onOpen,
}: {
  items: WorkItem[];
  onOpen: ( item: WorkItem ) => void;
} ) {
  return (
    <div className="bwx-list" data-testid="bwx-list">
      <table className="bwx-table">
        <thead>
          <tr>
            <th scope="col">Work</th>
            <th scope="col">Stage</th>
            <th scope="col">Starts</th>
            <th scope="col">Due</th>
            <th scope="col">Progress</th>
          </tr>
        </thead>
        <tbody>
          { items.map( ( item ) => (
            <tr
              key={ item.id }
              className="bwx-row"
              data-testid="bwx-row"
              data-item={ item.id }
              data-stage={ item.stage }
              style={
                {
                  '--row-rail': 'blocked' === item.stage
                    ? 'var(--blocked-accent)'
                    : `var(--phase-${ phaseOf( item.stage ) })`,
                } as React.CSSProperties
              }
            >
              <td>
                { /* A button, not a row click: opening the panel is an action,
                     and an action has to be reachable from the keyboard. */ }
                <button type="button" className="bwx-row-open" onClick={ () => onOpen( item ) }>
                  { item.title }
                </button>
                <span className="bwx-card-meta">
                  { 'feature' !== item.work_type && (
                    <span className="bwx-eyebrow">{ item.work_type_label }</span>
                  ) }
                  <span className="bwx-eyebrow">{ item.level_label }</span>
                  <span className="bwx-mono">{ item.id.replace( 'wrk_', '' ).slice( 0, 6 ) }</span>
                </span>
              </td>
              <td>{ item.stage_label }</td>

              { /* An em dash rather than an empty cell: "not set" and "the
                   column failed to render" look the same when both are blank. */ }
              <td className="bwx-mono">{ '' === item.planned_start ? '—' : item.planned_start }</td>
              <td className="bwx-mono">{ '' === item.planned_due ? '—' : item.planned_due }</td>
              <td>{ progressOf( item ) }</td>
            </tr>
          ) ) }
        </tbody>
      </table>

      { 0 === items.length && (
        <p className="bwx-list-empty" data-testid="bwx-list-empty">
          No work matches these filters.
        </p>
      ) }
    </div>
  );
}

/**
 * How far a parent's children have got, or nothing at all.
 *
 * Work with no children beneath it has no progress to report, and showing 0%
 * would say it had not started when it may be nearly done — the board's stage
 * is the honest answer for those.
 */
function progressOf( item: WorkItem ): string {
  if ( 'empty' === item.derived_state || undefined === item.derived_state ) {
    return '—';
  }

  return `${ item.progress ?? 0 }%`;
}
