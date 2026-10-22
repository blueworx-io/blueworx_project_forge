import { useState } from 'react';
import type { Stage, WorkItem } from '../types';
import { phaseOf } from '../phases';
import { STAGE_ORDER } from '../kit';
import { Card } from './Card';

/** #616. How long released work stays in view, and how many cards any other column shows before the rest wait behind a button. */
const RECENT_DAYS = 14;
const SHOWN = 30;

/** When released work went live, falling back to its last change. */
const releasedAt = ( item: WorkItem ) => item.released_at || item.updated_at || 0;

/**
 * The cards a column draws until asked for more (#616): released work from
 * the last two weeks, newest first, or the first thirty of anything else.
 * Long columns were hard to scan and slowed the whole board down.
 */
export function inView( stage: string, items: WorkItem[], now: number ): { shown: WorkItem[]; all: WorkItem[]; hidden: number } {
  if ( 'released' === stage ) {
    const all = [ ...items ].sort( ( a, b ) => releasedAt( b ) - releasedAt( a ) );
    const shown = all.filter( ( item ) => now - releasedAt( item ) * 1000 <= RECENT_DAYS * 86400000 );

    return { shown, all, hidden: all.length - shown.length };
  }

  return { shown: items.slice( 0, SHOWN ), all: items, hidden: Math.max( 0, items.length - SHOWN ) };
}

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
  const [ everything, setEverything ] = useState( false );
  const view = inView( stage.id, items, Date.now() );
  const cards = everything ? view.all : view.shown;

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
        { cards.map( ( item ) => (
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

      { 0 < view.hidden && (
        <button type="button" className="bwx-button bwx-column-more" data-variant="quiet" data-testid="bwx-column-more" aria-expanded={ everything } onClick={ () => setEverything( ! everything ) }>
          { everything ? 'Show fewer' : 'released' === stage.id ? `Show older (${ view.hidden })` : `Show ${ view.hidden } more` }
        </button>
      ) }

      { 0 === items.length && <p className="bwx-empty">{ 'blocked' === stage.id ? 'No blocked work' : 'No work at this stage' }</p> }
      { 0 < items.length && 0 === view.shown.length && ! everything && <p className="bwx-empty">Nothing released in the last two weeks</p> }
    </section>
  );
}
