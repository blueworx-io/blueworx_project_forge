import { useEffect, useRef } from 'react';
import { api } from '../api';
import { defaultRange } from './CapacityScreen';

/**
 * Keeps the session warm (#489).
 *
 * api() keeps every answer for the session, so the second visit to a screen is
 * instant; the first is a real wait. This asks for those answers quietly, one
 * at a time, once the
 * screen somebody actually opened has had its turn — so by the time they move
 * on, what they move on to is already here. A change forgets every kept
 * answer, and this does not go round again after one (#701): sixteen reads
 * and every client's board after each save is what overloaded the server.
 * Each screen reads its own answers again when it is next opened.
 *
 * One request at a time, on purpose: a single-threaded server given ten at
 * once answers the one somebody is waiting for last.
 */

/** How long after the app opens before warming starts. */
const AFTER_OPEN = 1500;

/** Reads every screen shares, then each screen's own first read. */
function shared( admin: boolean ): string[] {
  const days = defaultRange( 'days' );
  const paths = [
    '/stages',
    '/client-sites',
    '/work-items-all',
    `/capacity?from=${ days.from }&to=${ days.to }&by=days`,
    '/clients?status=all',
    '/recurring',
    '/reminders',
    '/meetings',
    '/support-summary',
    '/onboarding/board',
  ];

  if ( admin ) {
    paths.push( '/people', '/users?status=all&with=memberships', '/grants', '/subscriptions', '/packages', '/leads?archived=0' );
  }

  return paths;
}

export function Warmer( { admin }: { admin: boolean } ) {
  const latest = useRef( { admin } );

  useEffect( () => {
    latest.current = { admin };
  } );

  useEffect( () => {
    let run = 0;
    let timer = 0;

    const mark = ( state: string ) => {
      document.documentElement.dataset.bwxWarm = state;
    };

    async function warm( mine: number ) {
      mark( 'warming' );

      const ask = async ( path: string ) => {
        if ( mine !== run ) return;

        try {
          await api( path );
        } catch {
          // A screen that cannot be read now will say so itself when opened.
        }
      };

      for ( const path of shared( latest.current.admin ) ) {
        await ask( path );
      }

      // Not every client's board (#709): with forty clients that was minutes
      // of server time each time somebody opened Forge. A board is read when
      // its client is picked.

      if ( mine === run ) mark( 'done' );
    }

    const later = ( delay: number ) => {
      window.clearTimeout( timer );
      run += 1;
      const mine = run;
      timer = window.setTimeout( () => void warm( mine ), delay );
    };

    later( AFTER_OPEN );

    return () => {
      window.clearTimeout( timer );
      run += 1;
    };
  }, [] );

  return null;
}
