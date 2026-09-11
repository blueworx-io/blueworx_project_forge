import type { Stage, WorkItem } from '../types';
import { phaseOf } from '../phases';
import { STAGE_ORDER } from '../kit';
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
  parents,
  names,
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
  parents?: Map< string, WorkItem >;
  names?: Map< string, string >;
} ) {
  const index = STAGE_ORDER.indexOf( stage.id );
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
      <header className="bwx-column-head" data-phase={ phaseOf( stage.id ) }>
        { index >= 0 && <span className="bwx-mono bwx-column-index">{ String( index + 1 ).padStart( 2, '0' ) }</span> }
        <span className="bwx-column-label">{ stage.label }</span>
        <span className="bwx-count" data-testid="bwx-column-count">
          { items.length }
        </span>
      </header>

      <div className="bwx-cards">
        { items.map( ( item ) => (
          <Card
            key={ item.id }
            item={ item }
            parent={ parents?.get( item.parent_id ) }
            names={ names }
            dragging={ draggingId === item.id }
            onOpen={ () => onOpen( item ) }
            onDragStart={ () => onDragStart( item ) }
            onDragEnd={ onDragEnd }
          />
        ) ) }
      </div>

      { 0 === items.length && <p className="bwx-empty">{ 'blocked' === stage.id ? 'No blocked work' : 'No work at this stage' }</p> }
    </section>
  );
}
