import { useEffect, useRef } from 'react';
import { onRefreshed } from './api';

/**
 * Reloads a screen from what the cache now holds whenever a re-check finds
 * something new.
 *
 * Every screen already has a load() it runs on mount. This runs the same one
 * again when a background re-check (see api.ts) has changed an answer, so the
 * screen updates in place — no loading state, no flash — from a cache that is
 * already fresh. A screen that never mounted a load() has nothing to reload
 * and does not use this.
 *
 * The latest load() is kept in a ref, so subscribing once on mount is enough
 * and a screen whose load() closes over changing state still reloads with the
 * current one.
 */
export function useLiveReload( load: () => void | Promise< void > ): void {
  const latest = useRef( load );

  // Kept current after each render rather than during it, which is the
  // only time a ref may be written.
  useEffect( () => {
    latest.current = load;
  } );

  useEffect(
    () =>
      onRefreshed( ( _path, changed ) => {
        if ( changed ) {
          void latest.current();
        }
      } ),
    []
  );
}
