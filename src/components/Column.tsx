import type { Stage, WorkItem } from '../types';
import { phaseOf } from '../phases';
import { Card } from './Card';

/**
 * One stage, and the work sitting in it.
 *
 * A column is a drop target, but dropping is never the only way to move work:
 * the same moves are buttons in the detail panel, so the board is usable
 * without a mouse.
 */
export function Column( {
  stage,
  items,
  over,
  onOpen,
  onDragStart,
  onDragEnd,
  onDropItem,
  onOver,
  draggingId,
}: {
  stage: Stage;
  items: WorkItem[];
  over: boolean;
  onOpen: ( item: WorkItem ) => void;
  onDragStart: ( item: WorkItem ) => void;
  onDragEnd: () => void;
  onDropItem: ( itemId: string, stage: string ) => void;
  onOver: ( stage: string | null ) => void;
  draggingId: string;
} ) {
  return (
    <section
      className="bwx-column"
      data-testid="bwx-column"
      data-stage={ stage.id }
      data-over={ over ? 'true' : 'false' }
      aria-label={ stage.label }
      onDragOver={ ( event ) => {
        // Without this the browser refuses the drop outright.
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        onOver( stage.id );
      } }
      onDragLeave={ () => onOver( null ) }
      onDrop={ ( event ) => {
        event.preventDefault();
        onOver( null );

        const id = event.dataTransfer.getData( 'text/plain' );

        if ( '' !== id ) {
          onDropItem( id, stage.id );
        }
      } }
    >
      <header className="bwx-column-head">
        <span
          className="bwx-dot"
          style={ { '--phase-colour': `var(--phase-${ phaseOf( stage.id ) })` } as React.CSSProperties }
        />
        <span className="bwx-eyebrow">{ stage.label }</span>
        <span className="bwx-count" data-testid="bwx-column-count">
          { items.length }
        </span>
      </header>

      <div className="bwx-cards">
        { items.map( ( item ) => (
          <Card
            key={ item.id }
            item={ item }
            dragging={ draggingId === item.id }
            onOpen={ () => onOpen( item ) }
            onDragStart={ () => onDragStart( item ) }
            onDragEnd={ onDragEnd }
          />
        ) ) }
      </div>

      { 0 === items.length && <p className="bwx-empty">Nothing here.</p> }
    </section>
  );
}
