import { useSyncExternalStore } from 'react';

/**
 * Phones and small tablets (2026-09-27, #428). Below this the rail becomes a
 * side menu and the four daily screens sit in a bar along the bottom. Kept in
 * step with the `max-width: 900px` rules in shell.css — a media query cannot
 * read a custom property, so the number lives in both places.
 */
export const COMPACT_QUERY = '(max-width: 900px)';

function query(): MediaQueryList | null {
  return 'function' === typeof window.matchMedia ? window.matchMedia( COMPACT_QUERY ) : null;
}

/** Read once, for a default that should not follow a later resize. */
export function isCompact(): boolean {
  return query()?.matches ?? false;
}

/**
 * One function for the life of the page, so React subscribes once rather than
 * on every render (#444).
 */
function subscribe( changed: () => void ): () => void {
  const list = query();
  list?.addEventListener( 'change', changed );
  return () => list?.removeEventListener( 'change', changed );
}

/** Runs `changed` whenever the window crosses between a phone and a desktop; returns how to stop. */
export function onCompactChange( changed: () => void ): () => void {
  return subscribe( changed );
}

/** Follows the window: a phone turned sideways, or a desktop window narrowed. */
export function useCompact(): boolean {
  return useSyncExternalStore( subscribe, isCompact );
}
