import test from 'node:test';
import assert from 'node:assert/strict';

// #703. The Gantt looks at most two weeks back and six weeks ahead of today,
// whatever is dated, and always has today in it.

import { axisFor, daysBetween, placeOn, placeToday } from '../../src/gantt.ts';

// A Thursday, so the weeks do not happen to line up with the limits.
const TODAY = '2026-11-26';
const span = ( start, due ) => ( { start, due, derived: false } );

test( 'the day view runs from two weeks back to six weeks ahead when work is dated beyond both', () => {
  const axis = axisFor( [ span( '2026-01-05', '2026-01-09' ), span( '2028-03-01', '2028-03-05' ) ], TODAY, 'day' );

  assert.equal( axis.from, '2026-11-12' );
  assert.equal( axis.to, '2027-01-07' );
  assert.equal( axis.columns.length, daysBetween( axis.from, axis.to ) + 1 );
  assert.equal( axis.columns.length, 57 );
} );

test( 'the week view keeps to whole weeks inside the same limits', () => {
  const axis = axisFor( [ span( '2026-01-05', '2026-01-09' ), span( '2028-03-01', '2028-03-05' ) ], TODAY, 'week' );

  // Monday on or after 12 Nov, Sunday on or before 7 Jan.
  assert.equal( axis.from, '2026-11-16' );
  assert.equal( axis.to, '2027-01-03' );
  assert.equal( axis.columns.length, 7 );
  assert.ok( daysBetween( axis.from, TODAY ) <= 14 );
  assert.ok( daysBetween( TODAY, axis.to ) <= 42 );
} );

test( 'today is always on the chart, and is the default view', () => {
  const axis = axisFor( [], TODAY );

  assert.equal( axis.zoom, 'day' );
  assert.notEqual( placeToday( axis, TODAY ), null );
  assert.ok( axis.columns.some( ( column ) => column.start === TODAY ) );
} );

test( 'a short plan does not widen the chart to the limits', () => {
  const axis = axisFor( [ span( '2026-11-24', '2026-11-30' ) ], TODAY, 'day' );

  assert.equal( axis.from, '2026-11-23' );
  assert.equal( axis.to, '2026-12-06' );
} );

test( 'work wholly outside the limits is not placed, and work crossing an edge is cut at it', () => {
  const axis = axisFor( [ span( '2026-01-05', '2028-03-05' ) ], TODAY, 'day' );

  assert.equal( placeOn( span( '2026-06-01', '2026-06-05' ), axis ), null );
  assert.equal( placeOn( span( '2027-06-01', '2027-06-05' ), axis ), null );

  const cut = placeOn( span( '2026-10-01', '2026-11-14' ), axis );

  assert.equal( cut.left, 0 );
  assert.ok( cut.width > 0 && cut.width < 10 );
} );
