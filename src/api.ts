import type { ForgeData, GateCheck, Requirement } from './types';
import { announce, clear, newestAt, read, shouldRevalidate, subscribe, touch, write } from './cache.mjs';

/**
 * Everything this app knows about the server it is running on.
 *
 * Read once rather than at each call: the values are printed into the page by
 * PHP before the bundle loads, and if they are missing the app is running
 * outside WordPress — `npm run dev` — where every call will fail and should
 * say so plainly rather than throwing something opaque.
 */
export function forgeData(): ForgeData | undefined {
  return window.bwxForgeData;
}

/** Whether the app can talk to a server at all. */
export function isConnected(): boolean {
  return Boolean( forgeData()?.restUrl );
}

export class ApiError extends Error {
  readonly status: number;

  readonly code: string;

  readonly data: Record< string, unknown >;

  constructor( status: number, code: string, message: string, data: Record< string, unknown > = {} ) {
    super( message );
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.data = data;
  }
}

/**
 * A refused transition.
 *
 * Not an error in the ordinary sense: the request was well-formed and the
 * caller was allowed to make it, the item simply is not ready. It carries every
 * unmet requirement rather than the first, which is the whole contract of the
 * gate-failure response — and the panel renders them as a list to work through
 * rather than as a sentence to be annoyed by.
 */
export class GateError extends Error {
  readonly unmet: Requirement[];

  readonly checks: GateCheck[];

  readonly stage: string;

  readonly attempted: string;

  constructor( body: {
    stage?: string;
    attempted?: string;
    unmet?: Requirement[];
    checks?: GateCheck[];
  } ) {
    super( 'That work is not ready to move yet.' );
    this.name = 'GateError';
    this.unmet = body.unmet ?? [];
    this.checks = body.checks ?? [];
    this.stage = body.stage ?? '';
    this.attempted = body.attempted ?? '';
  }
}

/** Whether a failure was "you may not", as against "that did not work". */
export function isDenied( error: unknown ): boolean {
  return error instanceof ApiError && ( 401 === error.status || 403 === error.status );
}

/** The sentence to show a person for any failure, without leaking an object. */
export function messageFor( error: unknown, fallback: string ): string {
  if ( error instanceof GateError ) {
    return error.message;
  }

  return error instanceof ApiError ? error.message : fallback;
}

/**
 * One call to the Forge API.
 *
 * The nonce goes on every request including reads: WordPress uses it to
 * recognise the logged-in user, so a read without it comes back as a stranger's
 * read rather than as an error, which is a far more confusing failure.
 *
 * Reads are kept for the session. A read the app has an answer for comes back
 * at once with that answer, and the server is asked again in the background;
 * when it says something different the kept answer is replaced and every
 * screen listening (see live.ts) reloads itself from the new one. A write
 * forgets everything, so the next read after a change is a real one. Pass
 * `cache: 'fresh'` to skip what is kept — the header's refresh button does.
 */
export async function api< T >(
  path: string,
  options: { method?: string; body?: unknown; cache?: 'stale-ok' | 'fresh' } = {}
): Promise< T > {
  const method = options.method ?? 'GET';

  if ( 'GET' === method && 'fresh' !== options.cache ) {
    const kept = read( path );

    if ( kept ) {
      if ( shouldRevalidate( path ) ) {
        void fetchJson< T >( path, options )
          .then( ( fresh ) => {
            const changed = JSON.stringify( fresh ) !== JSON.stringify( kept.value );

            if ( changed ) {
              write( path, fresh );
            } else {
              touch( path );
            }

            announce( path, changed );
          } )
          // The screen still has what it had; a re-check nobody asked for
          // failing is not something to put in front of anyone.
          .catch( () => undefined );
      }

      return kept.value as T;
    }
  }

  if ( 'GET' !== method ) {
    const payload = await fetchJson< T >( path, options );

    clear();

    return payload;
  }

  /*
   * One request per path at a time. The rail's count badge and the screen
   * it counts for ask for the same list in the same moment, and on a
   * single-threaded server the second copy only makes the first slower.
   * Whoever asks while it is in flight gets the same answer.
   */
  const sharing = inFlight.get( path ) as Promise< T > | undefined;

  if ( sharing ) {
    return sharing;
  }

  const request = fetchJson< T >( path, options )
    .then( ( payload ) => {
      // Kept, and the time announced for the header. Not as a change: the
      // screen that asked is about to render this answer itself.
      write( path, payload );
      announce( path, false );

      return payload;
    } )
    .finally( () => {
      inFlight.delete( path );
    } );

  inFlight.set( path, request );

  return request;
}

/** Reads on their way, by path, so a second ask joins the first. */
const inFlight = new Map< string, Promise< unknown > >();

/** When the most recent answer arrived from the server, or 0 before any has. */
export function refreshedAt(): number {
  return newestAt();
}

/** Hears every answer that arrives, until the returned function is called. */
export function onRefreshed( listener: ( path: string, changed: boolean ) => void ): () => void {
  return subscribe( listener );
}

/** Forgets every kept answer, so the next reads are real ones. */
export function forgetAll(): void {
  clear();
}

/** One request to the server, as it was before anything was kept. */
async function fetchJson< T >(
  path: string,
  options: { method?: string; body?: unknown } = {}
): Promise< T > {
  const data = forgeData();

  if ( ! data?.restUrl ) {
    throw new ApiError( 0, 'not_connected', 'This app is not connected to WordPress.' );
  }

  // A file goes as a form, and the browser sets the boundary itself.
  const form = options.body instanceof FormData;

  const response = await fetch( `${ data.restUrl.replace( /\/$/, '' ) }${ path }`, {
    method: options.method ?? 'GET',
    headers: {
      ...( form ? {} : { 'Content-Type': 'application/json' } ),
      'X-WP-Nonce': data.nonce,
    },
    credentials: 'same-origin',
    body: undefined === options.body ? undefined : form ? options.body : JSON.stringify( options.body ),
  } );

  const payload = await response.json().catch( () => ( {} ) );

  if ( ! response.ok ) {
    /*
     * A gate failure arrives as a 409 with its own documented body rather than
     * as an error envelope, so it is recognised by its shape and thrown as
     * itself. Flattening it into "That did not work" would throw away the list
     * of things that would make it work.
     */
    if ( false === payload?.ok && Array.isArray( payload?.unmet ) ) {
      throw new GateError( payload );
    }

    /*
     * The server's own message, not a generic one. Forge's REST layer answers
     * with a sentence written for a person — "Work cannot move there from
     * where it is" — and replacing that with "Request failed" throws away the
     * only part of the response worth reading.
     */
    throw new ApiError(
      response.status,
      String( payload?.code ?? 'unknown' ),
      String( payload?.message ?? 'That did not work.' ),
      ( payload?.data ?? {} ) as Record< string, unknown >
    );
  }

  return payload as T;
}
