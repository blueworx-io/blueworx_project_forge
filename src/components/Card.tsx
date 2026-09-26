import type { WorkItem } from '../types';
import { phaseOf } from '../phases';
import { Avatar, Tag } from '../kit';

const TYPE_CHIP: Record< string, { bg: string; border: string; ink: string } > = {
  bug: { bg: 'var(--phase-exception-bg)', border: 'var(--phase-exception-border)', ink: 'var(--phase-exception-ink)' },
  feature: { bg: 'var(--area-delivery-bg)', border: 'var(--area-delivery-border)', ink: 'var(--area-delivery)' },
  feedback: { bg: 'var(--area-requests-bg)', border: 'var(--area-requests-border)', ink: 'var(--area-requests)' },
  task: { bg: 'var(--area-admin-bg)', border: 'var(--area-admin-border)', ink: 'var(--area-admin)' },
};

const DAY = 86400000;

/** `3d` — how long since the record last changed. */
function age( item: WorkItem ): string {
  if ( ! item.updated_at ) return '';
  const days = Math.max( 0, Math.floor( ( Date.now() - item.updated_at * 1000 ) / DAY ) );
  return `${ days }d`;
}

/** `12 Aug`, or `-3d` when the date has passed and the work is not finished. */
function due( item: WorkItem ): { text: string; late: boolean } {
  const date = item.planned_due || item.derived_due || '';
  if ( ! date ) return { text: '—', late: false };
  const finished = 'completed' === item.stage || 'released' === item.stage;
  const at = new Date( `${ date }T00:00:00Z` );
  const days = Math.floor( ( at.getTime() - Date.now() ) / DAY );
  if ( ! finished && days < 0 ) return { text: `${ days }d`, late: true };
  return { text: at.toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', timeZone: 'UTC' } ), late: false };
}

/**
 * One piece of work, the way the design draws it (#305): the id and the
 * priority, the title, what it belongs to, what marks it, and who holds its
 * three seats with when it is due.
 *
 * A button rather than a div: it opens the panel, so it has to be reachable and
 * usable from the keyboard. Dragging is the quick way to move a card, never the
 * only way — the panel carries the same moves as buttons.
 */
/** Urgent reads red, high amber, normal blue, low grey. */
const PRIORITY_TONE: Record< string, 'danger' | 'warn' | 'info' | 'neutral' > = {
  urgent: 'danger',
  high: 'warn',
  normal: 'info',
  low: 'neutral',
};

export function Card( {
  item,
  parent,
  names,
  onOpen,
  onDragStart,
  onDragEnd,
  dragging,
}: {
  item: WorkItem;
  /** What it sits under, where that is on the board too. */
  parent?: WorkItem;
  /** Display names by person id, where the roster has been read. */
  names?: Map< string, string >;
  onOpen: () => void;
  onDragStart: () => void;
  onDragEnd: () => void;
  dragging: boolean;
} ) {
  const chip = TYPE_CHIP[ item.work_type ] ?? TYPE_CHIP.task;
  const blocked = 'blocked' === item.stage;
  const when = due( item );
  const seats = [ item.primary_user_id, item.reviewer_id, item.deliverer_id ];

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
        '--card-rail': blocked ? 'var(--blocked-hatch)' : `var(--phase-${ phaseOf( item.stage ) })`,
      } as React.CSSProperties }
    >
      <span className="bwx-card-rail" aria-hidden="true" />
      { /* The priority, as a coloured tag in the top right corner (2026-09-19). */ }
      { '' !== item.priority && (
        <span className="bwx-card-priority" data-testid="bwx-card-priority" data-priority={ item.priority }>
          <Tag tone={ PRIORITY_TONE[ item.priority ] ?? 'neutral' }>{ item.priority }</Tag>
        </span>
      ) }
      { item.client_name && (
        <span className="bwx-card-client" data-testid="bwx-card-client">
          { item.client_name }
        </span>
      ) }
      <span className="bwx-card-row">
        <span className="bwx-card-level">{ item.level_label }</span>
      </span>

      <p className="bwx-card-title">{ item.title }</p>

      { parent && <span className="bwx-card-parent">↳ { parent.level_label } · { parent.title }</span> }

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
        { blocked && <Tag tone="danger">Blocked</Tag> }
        { /* A new idea waits for its client to be confirmed (#390). */ }
        { 'future-idea' === item.stage && ! item.client_confirmed_at && (
          <span data-testid="bwx-card-unconfirmed">
            <Tag tone="warn">Client not confirmed</Tag>
          </span>
        ) }
        { 0 < ( item.assignees?.length ?? 0 ) && (
          <span data-testid="bwx-card-ticks">
            <Tag tone={ Object.keys( item.ticks ?? {} ).length >= item.assignees.length ? 'ok' : 'neutral' }>
              { `${ Object.keys( item.ticks ?? {} ).length } of ${ item.assignees.length } done` }
            </Tag>
          </span>
        ) }
        { 0 < ( item.checklist?.length ?? 0 ) && (
          <span data-testid="bwx-card-checklist">
            <Tag tone={ item.checklist.every( ( row ) => row.done ) ? 'ok' : 'neutral' }>
              { `${ item.checklist.filter( ( row ) => row.done ).length }/${ item.checklist.length }` }
            </Tag>
          </span>
        ) }
      </span>

      <span className="bwx-card-foot">
        <span className="bwx-card-seats" aria-hidden="true">
          { seats.map( ( id, i ) => (
            <Avatar key={ i } name={ id ? names?.get( id ) ?? '?' : null } />
          ) ) }
        </span>
        <span className="bwx-mono bwx-card-when" data-late={ when.late ? 'true' : undefined }>
          { age( item ) } · { when.text }
        </span>
      </span>
    </button>
  );
}
