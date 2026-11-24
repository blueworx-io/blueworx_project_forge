import type { WorkItem } from './types';

/**
 * The arithmetic behind the schedule, kept out of the component.
 *
 * All of it is pure and all of it works in whole days on ISO date strings,
 * which is what the records hold. Nothing here reads a clock except through
 * `today`, passed in — a chart that asked the clock itself could not be tested,
 * and "is this overdue" is the one question on this screen that depends on when
 * you ask it.
 */

/** A day, in milliseconds. */
const DAY = 86400000;

/** What a bar covers, and where the dates came from. */
export interface Span {
  start: string;
  due: string;

  /*
   * True when the dates came from the children beneath this rather than from
   * somebody choosing them. The chart draws the two differently, because
   * "this is when its parts happen" is a different claim from "this is when we
   * said it would happen", and a chart that drew them the same would invite
   * somebody to hold the first to the second.
   */
  derived: boolean;
}

/** How closely the chart looks: a column a week, or a column a day. */
export type Zoom = 'week' | 'day';

/** The time the chart covers, in whole weeks, drawn a column per week or per day. */
export interface Axis {
  from: string;
  to: string;
  zoom: Zoom;
  columns: Array< { start: string; label: string } >;
}

/** ISO date to a UTC timestamp, so arithmetic never meets a timezone. */
function stamp( date: string ): number {
  return Date.parse( `${ date }T00:00:00Z` );
}

/** A UTC timestamp back to an ISO date. */
function iso( at: number ): string {
  return new Date( at ).toISOString().slice( 0, 10 );
}

/** Whole days from one date to another, which may be negative. */
export function daysBetween( from: string, to: string ): number {
  return Math.round( ( stamp( to ) - stamp( from ) ) / DAY );
}

/**
 * What one item covers, or nothing at all.
 *
 * Its own planned dates win. A parent that has none of its own falls back to
 * what its children make it, which the API sends as derived_start and
 * derived_due (#101) — that is what stops every parent being unscheduled while
 * all the work beneath it has dates.
 *
 * Half a span is not a span. An item with a start and no due date has not been
 * scheduled, it has been started, and drawing it as a bar to nowhere would be
 * inventing an end date nobody set.
 */
export function spanOf( item: WorkItem ): Span | null {
  if ( '' !== item.planned_start && '' !== item.planned_due ) {
    return { start: item.planned_start, due: item.planned_due, derived: false };
  }

  const start = item.derived_start ?? '';
  const due = item.derived_due ?? '';

  if ( '' !== start && '' !== due ) {
    return { start, due, derived: true };
  }

  return null;
}

/**
 * Whether work is still in play. Released work is finished and shipped, so the
 * schedule leaves it off: a chart of what is still moving should not carry
 * everything that ever was.
 */
export function isLive( item: WorkItem ): boolean {
  return 'released' !== item.stage;
}

/** Whether a span has already run out, for work that is not finished. */
export function isOverdue( item: WorkItem, span: Span | null, today: string ): boolean {
  if ( null === span || 'completed' === item.stage || 'released' === item.stage ) {
    return false;
  }

  return span.due < today;
}

/** The Monday on or before a date, so every week starts in the same place. */
function weekStart( date: string ): string {
  const at = stamp( date );
  const weekday = ( new Date( at ).getUTCDay() + 6 ) % 7;

  return iso( at - weekday * DAY );
}

/**
 * The weeks the chart draws, wide enough to hold everything dated and today.
 *
 * Today is included even when nothing is scheduled near it, because a schedule
 * that does not show where you are standing is a picture of somebody else's
 * year.
 */
export function axisFor( spans: Span[], today: string, zoom: Zoom = 'week' ): Axis {
  const dates = spans.flatMap( ( span ) => [ span.start, span.due ] ).concat( today );
  const from = weekStart( dates.reduce( ( a, b ) => ( a < b ? a : b ) ) );
  const last = dates.reduce( ( a, b ) => ( a > b ? a : b ) );

  // The same whole weeks at either zoom: zooming in cuts the same stretch of
  // time more finely, it does not change which stretch it is.
  const weeks = Math.floor( daysBetween( from, last ) / 7 ) + 1;
  const to = iso( stamp( from ) + weeks * 7 * DAY - DAY );
  const columns: Axis[ 'columns' ] = [];

  if ( 'day' === zoom ) {
    for ( let at = stamp( from ); at <= stamp( to ); at += DAY ) {
      columns.push( { start: iso( at ), label: dayLabel( iso( at ), 0 === columns.length ) } );
    }
  } else {
    for ( let at = stamp( from ); at <= stamp( to ); at += 7 * DAY ) {
      columns.push( { start: iso( at ), label: labelFor( iso( at ), 0 === columns.length ) } );
    }
  }

  return { from, to, zoom, columns };
}

/**
 * A week's label: the month when it changes, the day of the month otherwise.
 * The first week always names its month (2026-09-20), so a short axis is
 * never a bare run of numbers.
 */
function labelFor( start: string, first = false ): string {
  const at = new Date( stamp( start ) );
  const day = at.getUTCDate();
  const month = at.toLocaleDateString( 'en-GB', { month: 'short', timeZone: 'UTC' } );

  if ( first ) {
    return `${ day } ${ month }`;
  }

  // A month name only where the week actually opens one, so the axis reads as
  // a run of months rather than as the same word repeated four times.
  if ( 7 >= day ) {
    return month;
  }

  return String( day );
}

/** A day's label: the day of the month, with the month on the first column and on the 1st. */
function dayLabel( date: string, first: boolean ): string {
  const at = new Date( stamp( date ) );
  const day = at.getUTCDate();

  if ( first || 1 === day ) {
    return `${ day } ${ at.toLocaleDateString( 'en-GB', { month: 'short', timeZone: 'UTC' } ) }`;
  }

  return String( day );
}

/** Where a span sits on the axis, as percentages of the whole width. */
export function placeOn( span: Span, axis: Axis ): { left: number; width: number } {
  const total = daysBetween( axis.from, axis.to ) + 1;
  const left = daysBetween( axis.from, span.start );

  // Inclusive of both ends: a piece of work that starts and ends on the same
  // day lasts a day, not nothing.
  const width = daysBetween( span.start, span.due ) + 1;

  return {
    left: ( left / total ) * 100,
    width: ( Math.max( width, 1 ) / total ) * 100,
  };
}

/**
 * Today's position on the axis, or null when today is off the chart. At the day
 * zoom it is the middle of today's column, so the line sits in the column it
 * marks rather than on its edge.
 */
export function placeToday( axis: Axis, today: string ): number | null {
  if ( today < axis.from || today > axis.to ) {
    return null;
  }

  const total = daysBetween( axis.from, axis.to ) + 1;
  const offset = 'day' === axis.zoom ? 0.5 : 0;

  return ( ( daysBetween( axis.from, today ) + offset ) / total ) * 100;
}

/**
 * What the selected item's chain looks like from each row's point of view.
 *
 * One step in each direction rather than the whole transitive chain: the
 * question a schedule answers by highlighting is "what is immediately either
 * side of this", and lighting up everything reachable would light up most of
 * the chart on a site with any real sequencing in it.
 */
export type ChainRole = 'selected' | 'upstream' | 'downstream' | null;

export function chainFor( items: WorkItem[], selected: string ): Record< string, ChainRole > {
  const roles: Record< string, ChainRole > = {};

  if ( '' === selected ) {
    return roles;
  }

  roles[ selected ] = 'selected';

  for ( const id of items.find( ( item ) => item.id === selected )?.waits_on ?? [] ) {
    roles[ id ] = 'upstream';
  }

  for ( const item of items ) {
    if ( ( item.waits_on ?? [] ).includes( selected ) ) {
      roles[ item.id ] = 'downstream';
    }
  }

  return roles;
}

/** Today, as the chart means it: an ISO date in the browser's own timezone. */
export function todayIso(): string {
  const now = new Date();

  return iso( Date.UTC( now.getFullYear(), now.getMonth(), now.getDate() ) );
}
