import { useEffect, useRef } from 'react';
import { onRefreshed } from './api';
import { readsPath } from './cache.mjs';

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
 *
 * `reads` names the paths load() reads (#701). The pieces that are always on
 * screen — the bell and the rail's counts — say so, and reload only when one
 * of their own answers changes, not whenever anything anywhere does.
 */
export function useLiveReload( load: () => void | Promise< void >, reads?: string[] ): void {
  const latest = useRef( load );
  const paths = useRef( reads );

  // Kept current after each render rather than during it, which is the
  // only time a ref may be written.
  useEffect( () => {
    latest.current = load;
    paths.current = reads;
  } );

  useEffect(
    () =>
      onRefreshed( ( path, changed ) => {
        if ( changed && readsPath( paths.current, path ) ) {
          void latest.current();
        }
      } ),
    []
  );
}
