// Shared date helpers for releases, week selectors, and human-readable display.
// Extracted from Settings.tsx so DetailModal and the Gantt can reuse them.
import { format, parseISO, isValid } from 'date-fns';

// Format a local Date as YYYY-MM-DD without going through toISOString(), which
// converts to UTC and shifts the day in negative-UTC zones (a cause of the
// week-jumping bug, #48). We read the local Y/M/D components directly.
function fmtLocal( d: Date ): string {
  const y = d.getFullYear();
  const m = String( d.getMonth() + 1 ).padStart( 2, '0' );
  const day = String( d.getDate() ).padStart( 2, '0' );
  return `${ y }-${ m }-${ day }`;
}

// ── ISO week helpers ─────────────────────────────────────────────────────────
export function isoWeekToDate( weekStr: string ): string {
  if ( ! weekStr ) return '';
  const [ yearStr, wStr ] = weekStr.split( '-W' );
  const year = parseInt( yearStr, 10 );
  const week = parseInt( wStr, 10 );
  const jan4  = new Date( year, 0, 4 );
  const dow   = jan4.getDay() || 7;
  const mon1  = new Date( jan4 );
  mon1.setDate( jan4.getDate() - dow + 1 );
  const d = new Date( mon1 );
  d.setDate( mon1.getDate() + ( week - 1 ) * 7 );
  return fmtLocal( d );
}

export function dateToIsoWeek( dateStr: string ): string {
  if ( ! dateStr ) return '';
  const d   = new Date( dateStr + 'T00:00:00' );
  const thu = new Date( d );
  const dow = d.getDay() || 7;
  thu.setDate( d.getDate() - dow + 4 );
  const year  = thu.getFullYear();
  const jan1  = new Date( year, 0, 1 );
  const week  = Math.ceil( ( ( thu.getTime() - jan1.getTime() ) / 86400000 + 1 ) / 7 );
  return `${ year }-W${ String( week ).padStart( 2, '0' ) }`;
}

// Convert a picked ISO week to a date landing on the configured release day,
// so release ranges run release-day → release-day (e.g. Tuesday → Tuesday). (#20)
export function isoWeekToReleaseDate( weekStr: string, releaseDay: number ): string {
  const monday = isoWeekToDate( weekStr );
  if ( ! monday ) return '';
  const offset = releaseDay === 0 ? 6 : releaseDay - 1; // days from Monday to release day
  const d = new Date( monday + 'T00:00:00' );
  d.setDate( d.getDate() + offset );
  return fmtLocal( d );
}

// ISO week number (1–53) for a date string, used in the auto release name.
export function isoWeekNumber( dateStr: string ): number | null {
  const iso = dateToIsoWeek( dateStr );
  if ( ! iso ) return null;
  return parseInt( iso.split( '-W' )[1], 10 );
}

export function autoQuarter( dateStr: string ): string {
  if ( ! dateStr ) return '';
  const d = new Date( dateStr + 'T00:00:00' );
  return `Q${ Math.floor( d.getMonth() / 3 ) + 1 } ${ d.getFullYear() }`;
}

export function autoCapacity( start: string, end: string, monthlyHours: number ): number {
  if ( ! start || ! end ) return 0;
  const days = Math.max( 1, Math.ceil( ( new Date( end ).getTime() - new Date( start ).getTime() ) / 86400000 ) + 1 );
  return Math.round( monthlyHours * days / 30.44 );
}

// Compose the release display name from its parts. (#19)
// Example: "v10.9.3 | Q2: User Management System (Weeks 28 -> 29)"
export function composeReleaseName( f: { versionNumber: string; quarter: string; releaseName: string; startWeek: string; endWeek: string } ): string {
  const version = f.versionNumber ? `v${ f.versionNumber } | ` : '';
  const quarter = ( f.quarter.match( /Q[1-4]/ )?.[0] ) ?? f.quarter;
  const sw = isoWeekNumber( f.startWeek );
  const ew = isoWeekNumber( f.endWeek );
  const weeks = sw && ew ? ` (Weeks ${ sw } -> ${ ew })` : '';
  const head = [ quarter && `${ quarter }:`, f.releaseName ].filter( Boolean ).join( ' ' );
  return `${ version }${ head }${ weeks }`.trim();
}

// ── Human-readable display formatter (#47) ───────────────────────────────────
// Renders a stored date as "20 Nov 2026" (day-month-year, always with year).
// Accepts a "YYYY-MM-DD" string or a Date; returns '' for empty/invalid input.
export function formatDate( value: string | Date | null | undefined ): string {
  if ( ! value ) return '';
  const d = typeof value === 'string' ? parseISO( value ) : value;
  if ( ! isValid( d ) ) return '';
  return format( d, 'd MMM yyyy' );
}
