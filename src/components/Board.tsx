import { useState } from 'react';
import type { Stage, WorkItem } from '../types';
import { PHASE_LABEL, phaseBands } from '../phases';
import { Column } from './Column';

/**
 * The board: one column per linear stage, grouped under the phase each one
 * belongs to.
 *
 * The phase rail is the point. Twelve columns is more than anyone reads at a
 * glance, but four groups is not, and "stuck in approvals" is a different
 * problem from "stuck in delivery" — so the grouping is drawn rather than left
 * for the reader to infer from twelve labels.
 */
export function Board( {
  stages,
  columns,
  items,
  onOpen,
  onMove,
}: {
  stages: Stage[];
  columns: string[];
  items: WorkItem[];
  onOpen: ( item: WorkItem ) => void;
  onMove: ( itemId: string, stage: string ) => void;
} ) {
  const [ draggingId, setDraggingId ] = useState( '' );
  const [ overStage, setOverStage ] = useState< string | null >( null );

  const byId = new Map( stages.map( ( stage ) => [ stage.id, stage ] ) );
  const shown = columns.map( ( id ) => byId.get( id ) ).filter( Boolean ) as Stage[];
  const bands = phaseBands( shown.map( ( stage ) => stage.id ) );

  // The rail and the columns are two grids over the same track list, so a band
  // spanning three stages lines up with those three columns exactly.
  const tracks = { gridTemplateColumns: `repeat(${ shown.length }, 268px)` } as React.CSSProperties;

  return (
    <div className="bwx-board" data-testid="bwx-board">
      <div className="bwx-phases" style={ tracks }>
        { bands.map( ( band, index ) => (
          <div
            key={ `${ band.phase }-${ index }` }
            className="bwx-phase"
            style={
              {
                gridColumn: `span ${ band.span }`,
                '--phase-colour': `var(--phase-${ band.phase })`,
              } as React.CSSProperties
            }
          >
            <span className="bwx-eyebrow" style={ { color: 'inherit' } }>
              { PHASE_LABEL[ band.phase ] }
            </span>
          </div>
        ) ) }
      </div>

      <div className="bwx-columns" style={ tracks }>
        { shown.map( ( stage ) => (
          <Column
            key={ stage.id }
            stage={ stage }
            items={ items.filter( ( item ) => item.stage === stage.id ) }
            over={ overStage === stage.id }
            draggingId={ draggingId }
            onOpen={ onOpen }
            onDragStart={ ( item ) => setDraggingId( item.id ) }
            onDragEnd={ () => {
              setDraggingId( '' );
              setOverStage( null );
            } }
            onOver={ setOverStage }
            onDropItem={ ( itemId, to ) => {
              setDraggingId( '' );

              const item = items.find( ( candidate ) => candidate.id === itemId );

              // A drop back where it started is not a move, and asking the
              // server to make it would only produce a refusal to explain.
              if ( item && item.stage !== to ) {
                onMove( itemId, to );
              }
            } }
          />
        ) ) }
      </div>
    </div>
  );
}
