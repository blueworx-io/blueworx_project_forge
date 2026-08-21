/**
 * The five phase groups the twelve stages fall into.
 *
 * The stages are a flat list in the state machine, but they are not a flat
 * thing: work is captured, then defined and approved through four gates, then
 * delivered, then finished. That shape is the most useful thing a board can
 * tell somebody at a glance — "this is stuck in approvals" is a different
 * problem from "this is stuck in development" — so it is encoded rather than
 * decorated, as a rail across the columns of each phase.
 *
 * The colours are the design system's own phase tokens (tokens/workflow.css),
 * which exist for exactly this and carry the rule that a stage colour is a
 * marker, never a fill.
 */
export type Phase = 'idea' | 'gate' | 'pipeline' | 'done' | 'exception';

const PHASE_OF: Record< string, Phase > = {
  'future-idea': 'idea',
  triage: 'gate',
  'bug-tracking': 'gate',
  'documentation-period': 'gate',
  'technical-audit': 'gate',
  'design-process': 'gate',
  blocked: 'exception',
  'up-next': 'pipeline',
  'in-development': 'pipeline',
  'in-review': 'pipeline',
  completed: 'done',
  released: 'done',
};

export const PHASE_LABEL: Record< Phase, string > = {
  idea: 'Captured',
  gate: 'Defined and approved',
  pipeline: 'In delivery',
  done: 'Finished',
  exception: 'Paused',
};

export function phaseOf( stage: string ): Phase {
  return PHASE_OF[ stage ] ?? 'idea';
}

/**
 * The phase bands across a set of columns, in order, each with how many
 * columns it spans. The board draws one rail per band rather than one per
 * column, which is what makes the grouping visible.
 */
export function phaseBands( stages: string[] ): Array< { phase: Phase; span: number } > {
  const bands: Array< { phase: Phase; span: number } > = [];

  for ( const stage of stages ) {
    const phase = phaseOf( stage );
    const last = bands[ bands.length - 1 ];

    if ( last && last.phase === phase ) {
      last.span += 1;
      continue;
    }

    bands.push( { phase, span: 1 } );
  }

  return bands;
}
