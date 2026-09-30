import type { ClientSite, WorkItem } from './types';
import { ALL_SITES } from './sites';
// A task is on somebody's list only while it is theirs to act on, and only once.
import { responsible } from './turn';

/*
 * What is on one person's My tasks list, and which tab each row sits under.
 * Here rather than in the screen so the sidebar's count reads the very same
 * rows the Today tab does (#450).
 */

export type Site = ClientSite & { client_name: string };
export type Role = 'primary' | 'designer' | 'reviewer' | 'deliverer' | 'assignee';

const DAY = 86400000;

export interface Mine extends Record< string, unknown > {
  id: string;
  item: WorkItem;
  role: Role;
  site: Site;
  hours: number;
  /** Days until due; null when undated. */
  due: number | null;
  /** A reminder's copy (2026-09-25): sorted by when it starts, not when it is due. */
  reminder: boolean;
  /** Days until a reminder starts; null otherwise. */
  starts: number | null;
  /** A chore or reminder this person has already ticked. */
  ticked: boolean;
}

export type View = 'today' | 'tomorrow' | 'week' | 'later' | 'all';

/** Where a row sorts: one of the dated views, or done (under none of them). */
export type Slot = Exclude< View, 'all' > | 'done';

export function daysUntil( date: string ): number | null {
  if ( ! date ) return null;
  const at = new Date( `${ date }T00:00:00Z` ).getTime();
  if ( isNaN( at ) ) return null;
  const today = new Date( new Date().toISOString().slice( 0, 10 ) + 'T00:00:00Z' ).getTime();
  return Math.round( ( at - today ) / DAY );
}

/**
 * By date alone: Today is due today or late and nothing else (Luke,
 * 2026-09-30, #481). Blocked or in review no longer puts a row in Today on
 * its own, and undated work is Further out.
 */
export function viewOf( one: Mine ): Slot {
  // Released or ticked off is done, and not listed. Completed but late
  // is still to ship, so it sorts by date like the rest (Luke, 2026-09-26).
  if ( 'released' === one.item.stage || one.ticked ) return 'done';
  // A reminder is Today from its first day until it is ticked (2026-09-25).
  const days = one.reminder && null !== one.starts ? one.starts : one.due;
  if ( null === days ) return 'later';
  if ( days <= 0 ) return 'today';
  if ( 1 === days ) return 'tomorrow';
  return days <= 8 ? 'week' : 'later';
}

/** Every row that is this person's, from the sites and the work read in one go. */
export function mineFor( person: { id: string }, siteList: Site[], items: WorkItem[] ): Mine[] {
  const sites = new Map( siteList.map( ( site ) => [ site.id, site ] ) );
  const found: Mine[] = [];
  for ( const item of items ) {
    const site = sites.get( item.client_site_id );
    if ( ! site ) continue;
    const due = daysUntil( item.planned_due || item.derived_due || '' );

    // A recurring chore names its people rather than seats (2026-09-18):
    // one row for you, with your own tick on it, and nothing else.
    if ( 0 < ( item.assignees?.length ?? 0 ) ) {
      if ( item.assignees.includes( person.id ) ) {
        const reminder = ( item.recurring_id ?? '' ).startsWith( 'rem_' );
        found.push( { id: `${ item.id }:assignee`, item, role: 'assignee', site, hours: item.hours_each, due, reminder, starts: reminder ? daysUntil( item.planned_start || '' ) : null, ticked: undefined !== ( item.ticks ?? {} )[ person.id ] } );
      }
      continue;
    }

    // Otherwise one row, for whoever's stage it is (2026-09-24).
    const seat = responsible( item );
    const seats: Record< Exclude< Role, 'assignee' >, [ string, number ] > = {
      primary: [ item.primary_user_id, item.hours_primary ],
      designer: [ item.designer_id ?? '', item.hours_designer ?? 0 ],
      reviewer: [ item.reviewer_id, item.hours_review ],
      deliverer: [ item.deliverer_id, item.hours_delivery ],
    };

    if ( null !== seat && 'assignee' !== seat && seats[ seat ][ 0 ] === person.id ) {
      found.push( { id: item.id, item, role: seat, site, hours: seats[ seat ][ 1 ], due, reminder: false, starts: null, ticked: false } );
    }
  }
  return found;
}

/** The rows the screen lists: not done, and for the picked client (done work is under no view). */
export function listed( mine: Mine[], siteId: string ): Mine[] {
  return mine.filter( ( one ) => 'done' !== viewOf( one ) && ( ALL_SITES === siteId || one.item.client_site_id === siteId ) );
}

/** The number on the Today tab. */
export function todayCount( mine: Mine[], siteId: string ): number {
  return listed( mine, siteId ).filter( ( one ) => 'today' === viewOf( one ) ).length;
}
