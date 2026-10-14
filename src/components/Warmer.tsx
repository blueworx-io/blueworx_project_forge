import { useEffect, useRef } from 'react';
import { api, onWritten } from '../api';
import { useClientChoice } from '../ClientChoice';
import { ALL_SITES } from '../sites';
import { defaultRange } from './CapacityScreen';

/**
 * Keeps the session warm (#489).
 *
 * api() keeps every answer for the session, so the second visit to a screen is
 * instant; the first is a real wait, and so is the first look at each client
 * on the board. This asks for those answers quietly, one at a time, once the
 * screen somebody actually opened has had its turn — so by the time they move
 * on, what they move on to is already here. A change forgets every kept
 * answer, so after one it starts again.
 *
 * One request at a time, on purpose: a single-threaded server given ten at
 * once answers the one somebody is waiting for last.
 */

/** How long after the app opens, or after a change, before warming starts. */
const AFTER_OPEN = 1500;
const AFTER_WRITE = 3000;

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

/** The board's read for one site. */
function boardFor( siteId: string ): string {
  return `/work-items?client_site_id=${ encodeURIComponent( siteId ) }`;
}

export function Warmer( { admin }: { admin: boolean } ) {
  const { siteId, sites } = useClientChoice();
  const latest = useRef( { admin, siteId, sites } );

  useEffect( () => {
    latest.current = { admin, siteId, sites };
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

      // The board: the client somebody has picked first, then the rest, so
      // every switch between clients is instant once this has been round.
      const picked = latest.current.siteId;
      const others = latest.current.sites.map( ( site ) => site.id ).filter( ( id ) => id !== picked && ALL_SITES !== id );

      for ( const id of [ ...( '' !== picked && ALL_SITES !== picked ? [ picked ] : [] ), ...others ] ) {
        await ask( boardFor( id ) );
      }

      if ( mine === run ) mark( 'done' );
    }

    const later = ( delay: number ) => {
      window.clearTimeout( timer );
      run += 1;
      const mine = run;
      timer = window.setTimeout( () => void warm( mine ), delay );
    };

    later( AFTER_OPEN );
    const stop = onWritten( () => later( AFTER_WRITE ) );

    return () => {
      window.clearTimeout( timer );
      run += 1;
      stop();
    };
  }, [] );

  return null;
}
