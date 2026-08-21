import type { WorkItem } from './types';

/**
 * The arithmetic behind the calendar, kept out of the component.
 *
 * Pure, and working in whole days on the ISO date strings the records hold.
 * Weeks start on Monday, which is what the studio's week starts on and what
 * every date in this product is written against.
 */

const DAY = 86400000;

/** Which of an item's dates an entry is. */
export type DateKind = 'starts' | 'due' | 'review' | 'release';

export const KIND_LABEL: Record< DateKind, string > = {
  starts: 'Starts',
  due: 'Due',
  review: 'Review',
  release: 'Release',
};

/** One appearance of one item, on one day, for one reason. */
export interface Entry {
  item: WorkItem;
  kind: DateKind;
  date: string;
}

export type Mode = 'month' | 'week' | 'day';

function stamp( date: string ): number {
  return Date.parse( `${ date }T00:00:00Z` );
}

function iso( at: number ): string {
  return new Date( at ).toISOString().slice( 0, 10 );
}

/** Today, in the browser's own timezone rather than in UTC. */
export function todayIso(): string {
  const now = new Date();

  return iso( Date.UTC( now.getFullYear(), now.getMonth(), now.getDate() ) );
}

/** The Monday on or before a date. */
export function weekStart( date: string ): string {
  const at = stamp( date );

  return iso( at - ( ( new Date( at ).getUTCDay() + 6 ) % 7 ) * DAY );
}

/**
 * Every appearance every item makes.
 *
 * An item is on the calendar once for each date it carries, not once for the
 * item: "when does this start" and "when is this meant to ship" are different
 * questions on different days, and collapsing them to one entry answers
 * neither. An item carrying no dates makes no entries, which is why this view
 * can never be reconciled against the others by counting.
 */
export function entriesFor( items: WorkItem[] ): Entry[] {
  const entries: Entry[] = [];

  for ( const item of items ) {
    const dates: Array< [ DateKind, string ] > = [
      [ 'starts', item.planned_start ],
      [ 'due', item.planned_due ],
      [ 'review', item.review_target ?? '' ],
      [ 'release', item.release_target ?? '' ],
    ];

    for ( const [ kind, date ] of dates ) {
      if ( '' !== date ) {
        entries.push( { item, kind, date } );
      }
    }
  }

  return entries;
}

/** The entries of one day, in a fixed order so a day never reshuffles itself. */
export function byDay( entries: Entry[] ): Record< string, Entry[] > {
  const order: DateKind[] = [ 'starts', 'review', 'release', 'due' ];
  const days: Record< string, Entry[] > = {};

  for ( const entry of entries ) {
    ( days[ entry.date ] ??= [] ).push( entry );
  }

  for ( const date of Object.keys( days ) ) {
    days[ date ].sort( ( a, b ) => {
      const kind = order.indexOf( a.kind ) - order.indexOf( b.kind );

      return 0 !== kind ? kind : a.item.title.localeCompare( b.item.title );
    } );
  }

  return days;
}

/**
 * The days a mode draws, around an anchor date.
 *
 * A month is drawn as whole weeks, so it carries a few days of the months
 * either side. Those are real days with real work on them rather than padding,
 * and blanking them would hide work for no better reason than that a grid has
 * corners.
 */
export function daysFor( mode: Mode, anchor: string ): string[] {
  if ( 'day' === mode ) {
    return [ anchor ];
  }

  if ( 'week' === mode ) {
    const from = stamp( weekStart( anchor ) );

    return Array.from( { length: 7 }, ( _unused, index ) => iso( from + index * DAY ) );
  }

  const at = new Date( stamp( anchor ) );
  const firstOfMonth = iso( Date.UTC( at.getUTCFullYear(), at.getUTCMonth(), 1 ) );
  const lastOfMonth = iso( Date.UTC( at.getUTCFullYear(), at.getUTCMonth() + 1, 0 ) );

  const from = stamp( weekStart( firstOfMonth ) );
  const to = stamp( weekStart( lastOfMonth ) ) + 6 * DAY;
  const days: string[] = [];

  for ( let each = from; each <= to; each += DAY ) {
    days.push( iso( each ) );
  }

  return days;
}

/** Whether a day belongs to the month the anchor is in. */
export function inMonthOf( date: string, anchor: string ): boolean {
  return date.slice( 0, 7 ) === anchor.slice( 0, 7 );
}

/** What the header says it is showing. */
export function titleFor( mode: Mode, anchor: string ): string {
  const at = new Date( stamp( anchor ) );

  if ( 'month' === mode ) {
    return at.toLocaleDateString( 'en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' } );
  }

  if ( 'day' === mode ) {
    return at.toLocaleDateString( 'en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' } );
  }

  const start = weekStart( anchor );
  const end = iso( stamp( start ) + 6 * DAY );

  return `${ start } to ${ end }`;
}

/** The anchor a step forward or back lands on. */
export function step( mode: Mode, anchor: string, by: number ): string {
  if ( 'day' === mode ) {
    return iso( stamp( anchor ) + by * DAY );
  }

  if ( 'week' === mode ) {
    return iso( stamp( anchor ) + by * 7 * DAY );
  }

  const at = new Date( stamp( anchor ) );

  // Clamped to the first, so stepping from the 31st does not skip a month the
  // way adding thirty days would.
  return iso( Date.UTC( at.getUTCFullYear(), at.getUTCMonth() + by, 1 ) );
}
