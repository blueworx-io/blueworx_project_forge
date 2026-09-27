import { CLIENT_REVIEWER } from './types';

/**
 * Whose turn a piece of work is (2026-09-24), in one place: My tasks lists it
 * for that person, and the standup and the task panel say who it waits on
 * (#420), so the three never disagree.
 */

export type Seat = 'primary' | 'designer' | 'reviewer' | 'deliverer';

/** The item fields each seat is held in. */
export const SEAT_FIELD = {
  primary: 'primary_user_id',
  designer: 'designer_id',
  reviewer: 'reviewer_id',
  deliverer: 'deliverer_id',
} as const;

/** As much of an item as the rule reads. */
export interface Seated {
  stage: string;
  prior_stage?: string | null;
  primary_user_id?: string | null;
  designer_id?: string | null;
  reviewer_id?: string | null;
  deliverer_id?: string | null;
}

/**
 * Whose move it is at a stage: the reviewer's in review, the deliverer's once
 * completed, nobody's once released, and the owner's before that. Blocked work
 * is whoever's stage it was blocked from.
 */
export function responsible( item: Seated ): Seat | null {
  const stage = 'blocked' === item.stage ? item.prior_stage ?? '' : item.stage;

  if ( 'released' === stage ) return null;
  // #391. While the client reviews, the owner keeps it, marked as waiting.
  if ( 'in-review' === stage && CLIENT_REVIEWER === item.reviewer_id ) return 'primary';
  if ( 'in-review' === stage ) return 'reviewer';
  if ( 'completed' === stage ) return 'deliverer';
  // #409. At Design it is the Designer's, when there is one.
  if ( 'design-process' === stage && item.designer_id ) return 'designer';

  return 'primary';
}

/**
 * Who the work waits on, by name (#420): "Pending" while that seat is empty,
 * the client while they review, and null once there is nobody to wait on.
 */
export function waitingOn( item: Seated, names: Record< string, string > ): string | null {
  const stage = 'blocked' === item.stage ? item.prior_stage ?? '' : item.stage;

  if ( 'in-review' === stage && CLIENT_REVIEWER === item.reviewer_id ) return 'The client';

  const seat = responsible( item );

  if ( null === seat ) return null;

  const id = item[ SEAT_FIELD[ seat ] ] ?? '';

  return '' === id ? 'Pending' : names[ id ] ?? 'Somebody';
}
