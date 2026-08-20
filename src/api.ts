import type { ForgeData, GateCheck, Requirement } from './types';

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
 */
export async function api< T >(
  path: string,
  options: { method?: string; body?: unknown } = {}
): Promise< T > {
  const data = forgeData();

  if ( ! data?.restUrl ) {
    throw new ApiError( 0, 'not_connected', 'This app is not connected to WordPress.' );
  }

  const response = await fetch( `${ data.restUrl.replace( /\/$/, '' ) }${ path }`, {
    method: options.method ?? 'GET',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': data.nonce,
    },
    credentials: 'same-origin',
    body: undefined === options.body ? undefined : JSON.stringify( options.body ),
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
