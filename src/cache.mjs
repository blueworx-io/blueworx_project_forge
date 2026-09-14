/**
 * The session cache behind api().
 *
 * Every screen used to fetch everything from scratch each time it opened,
 * so moving between the board and the standup and back meant watching the
 * board load twice. This keeps each answer the app has fetched, for the tab,
 * so a screen can show what it already has at once and re-check quietly.
 *
 * Plain JavaScript with no DOM, so it can be tested with node alone. The
 * fetching, the events and the React side live in api.ts and live.ts; this
 * only remembers.
 */

/** How long an answer is trusted before it is treated as absent. */
const TTL = 30 * 60 * 1000;

/** How soon the same path may be re-checked again. */
const REVALIDATE_EVERY = 5 * 1000;

/** @type {Map<string, { value: unknown, at: number }>} */
const answers = new Map();

/** @type {Map<string, number>} When each path was last sent for. */
const started = new Map();

/** @type {Set<(path: string, changed: boolean) => void>} */
const listeners = new Set();

/**
 * The answer kept for a path, or undefined when there is none worth using.
 *
 * @param {string} path
 * @param {number} [now]
 * @returns {{ value: unknown, at: number } | undefined}
 */
export function read( path, now = Date.now() ) {
  const kept = answers.get( path );

  if ( ! kept || now - kept.at > TTL ) {
    return undefined;
  }

  return kept;
}

/**
 * Keeps an answer.
 *
 * @param {string} path
 * @param {unknown} value
 * @param {number} [now]
 */
export function write( path, value, now = Date.now() ) {
  answers.set( path, { value, at: now } );
}

/**
 * Moves an answer's time to now without changing it — a re-check that found
 * nothing new is still a refresh.
 *
 * @param {string} path
 * @param {number} [now]
 */
export function touch( path, now = Date.now() ) {
  const kept = answers.get( path );

  if ( kept ) {
    answers.set( path, { value: kept.value, at: now } );
  }
}

/** Forgets every answer. What is listening stays. */
export function clear() {
  answers.clear();
  started.clear();
}

/**
 * When the most recent answer arrived, or 0 when nothing is kept.
 *
 * @returns {number}
 */
export function newestAt() {
  let newest = 0;

  for ( const { at } of answers.values() ) {
    newest = Math.max( newest, at );
  }

  return newest;
}

/**
 * Whether a path is due a re-check, and marks it as started when it is.
 *
 * A screen that reads three paths on mount and again on every update must
 * not send for the same path every few hundred milliseconds; once per five
 * seconds is plenty for a re-check nobody asked for.
 *
 * @param {string} path
 * @param {number} [now]
 * @returns {boolean}
 */
export function shouldRevalidate( path, now = Date.now() ) {
  const last = started.get( path );

  if ( undefined !== last && now - last < REVALIDATE_EVERY ) {
    return false;
  }

  started.set( path, now );

  return true;
}

/**
 * Hears every announcement until the returned function is called.
 *
 * @param {(path: string, changed: boolean) => void} listener
 * @returns {() => void}
 */
export function subscribe( listener ) {
  listeners.add( listener );

  return () => {
    listeners.delete( listener );
  };
}

/**
 * Tells everyone listening that a path has been answered again — with
 * something new, or with the same thing (a re-check that found nothing is
 * still a refresh, and the header says so).
 *
 * @param {string} path
 * @param {boolean} [changed]
 */
export function announce( path, changed = true ) {
  for ( const listener of listeners ) {
    listener( path, changed );
  }
}
