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

test( 'the week view draws the weeks that hold the limits whole, so nothing inside them is dropped', () => {
  const axis = axisFor( [ span( '2026-01-05', '2026-01-09' ), span( '2028-03-01', '2028-03-05' ) ], TODAY, 'week' );

  // Monday of the week holding 12 Nov, Sunday of the week holding 7 Jan.
  assert.equal( axis.from, '2026-11-09' );
  assert.equal( axis.to, '2027-01-10' );
  assert.equal( axis.columns.length, 9 );
  assert.ok( axis.from <= '2026-11-12' && axis.to >= '2027-01-07' );
  assert.ok( daysBetween( axis.from, TODAY ) < 14 + 7 );
  assert.ok( daysBetween( TODAY, axis.to ) < 42 + 7 );
} );

test( 'work inside the limits is drawn in both zooms', () => {
  // 5 Jan 2027 is five weeks and a bit ahead: inside the six, in a week the limit cuts.
  const early = span( '2027-01-05', '2027-01-06' );

  for ( const zoom of [ 'day', 'week' ] ) {
    const axis = axisFor( [ early ], TODAY, zoom );

    assert.notEqual( placeOn( early, axis ), null, `${ zoom } draws it` );
  }

  const before = span( '2026-11-12', '2026-11-13' );

  assert.notEqual( placeOn( before, axisFor( [ before ], TODAY, 'week' ) ), null );
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

test( 'a date at the far end of the calendar cannot push the chart past its limits', () => {
  for ( const zoom of [ 'day', 'week' ] ) {
    const axis = axisFor( [ span( '2026-12-01', '9999-12-31' ) ], TODAY, zoom );

    assert.ok( axis.to <= ( 'day' === zoom ? '2027-01-07' : '2027-01-10' ), `${ zoom } ends by six weeks ahead` );
    assert.ok( axis.columns.length <= ( 'day' === zoom ? 57 : 9 ) );
  }
} );

test( 'work just past a limit is not drawn in either zoom, even where the weeks view runs past the limit', () => {
  // 8 Jan is the day after six weeks ahead; 9 and 10 Jan are in the Sunday-ending week the weeks view draws.
  const late = span( '2027-01-08', '2027-01-10' );
  const early = span( '2026-11-09', '2026-11-11' );
  const edge = span( '2027-01-06', '2027-01-09' );

  for ( const zoom of [ 'day', 'week' ] ) {
    const axis = axisFor( [ late, early, edge ], TODAY, zoom );

    assert.equal( placeOn( late, axis ), null, `${ zoom }: after the limit` );
    assert.equal( placeOn( early, axis ), null, `${ zoom }: before the limit` );
    assert.notEqual( placeOn( edge, axis ), null, `${ zoom }: crossing the limit is cut, not dropped` );
  }

  const week = axisFor( [ edge ], TODAY, 'week' );
  const cut = placeOn( edge, week );
  const total = daysBetween( week.from, week.to ) + 1;

  // Cut at 7 Jan: it ends there, not at 9 Jan.
  assert.ok( Math.abs( cut.left + cut.width - ( ( daysBetween( week.from, '2027-01-07' ) + 1 ) / total ) * 100 ) < 1e-9 );
} );
