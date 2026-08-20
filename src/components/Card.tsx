import type { WorkItem } from '../types';
import { phaseOf } from '../phases';

const TYPE_CHIP: Record< string, { bg: string; border: string; ink: string } > = {
  bug: { bg: 'var(--phase-exception-bg)', border: 'var(--phase-exception-border)', ink: 'var(--color-coral)' },
  feature: { bg: 'var(--area-delivery-bg)', border: 'var(--area-delivery-border)', ink: 'var(--area-delivery)' },
  feedback: { bg: 'var(--area-requests-bg)', border: 'var(--area-requests-border)', ink: 'var(--area-requests)' },
  task: { bg: 'var(--area-admin-bg)', border: 'var(--area-admin-border)', ink: 'var(--area-admin)' },
};

/**
 * One piece of work.
 *
 * A button rather than a div: it opens the panel, so it has to be reachable and
 * usable from the keyboard. Dragging is the quick way to move a card, never the
 * only way — the panel carries the same moves as buttons.
 */
export function Card( {
  item,
  onOpen,
  onDragStart,
  onDragEnd,
  dragging,
}: {
  item: WorkItem;
  onOpen: () => void;
  onDragStart: () => void;
  onDragEnd: () => void;
  dragging: boolean;
} ) {
  const chip = TYPE_CHIP[ item.work_type ] ?? TYPE_CHIP.task;
  const blocked = 'blocked' === item.stage;

  return (
    <button
      type="button"
      className="bwx-card"
      data-testid="bwx-card"
      data-item={ item.id }
      data-stage={ item.stage }
      data-dragging={ dragging ? 'true' : 'false' }
      draggable
      onClick={ onOpen }
      onDragStart={ ( event ) => {
        // The id travels on the drag itself, so a drop knows what was dropped
        // even though React state has moved on.
        event.dataTransfer.setData( 'text/plain', item.id );
        event.dataTransfer.effectAllowed = 'move';
        onDragStart();
      } }
      onDragEnd={ onDragEnd }
      style={ {
        // A rail rather than a fill: the phase colour marks the card without
        // colouring it, which is the rule the token file sets.
        '--card-rail': blocked
          ? 'var(--blocked-accent)'
          : `var(--phase-${ phaseOf( item.stage ) })`,
      } as React.CSSProperties }
    >
      <p className="bwx-card-title">{ item.title }</p>

      <span className="bwx-card-meta">
        { /* Only work that is not an ordinary feature is chipped. Feature is the
             common case, and marking every card with it would put a badge on
             the board that carries no information — the chips that remain then
             mean something: a bug, a piece of feedback, a task. */ }
        { 'feature' !== item.work_type && (
          <span
            className="bwx-chip"
            style={ {
              '--chip-bg': chip.bg,
              '--chip-border': chip.border,
              '--chip-ink': chip.ink,
            } as React.CSSProperties }
          >
            { item.work_type_label }
          </span>
        ) }
        <span className="bwx-eyebrow">{ item.level_label }</span>
        { '' !== item.priority && <span className="bwx-eyebrow">{ item.priority }</span> }
        <span className="bwx-mono">{ item.id.replace( 'wrk_', '' ).slice( 0, 6 ) }</span>
      </span>
    </button>
  );
}
