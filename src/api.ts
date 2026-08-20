import type { ForgeData } from './types';

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
