// Captures uncaught JS errors, unhandled promise rejections, and network failures,
// then POSTs them to the plugin so they surface on the WordPress Status page —
// no browser inspection required. Deduped and rate-capped so it can't flood or loop.

type ErrorKind = 'js' | 'promise' | 'network';

interface ClientError {
  kind:        ErrorKind;
  message:     string;
  source?:     string;
  line?:       number;
  col?:        number;
  stack?:      string;
  requestUrl?: string;
  status?:     number;
}

const MAX_PER_SESSION = 25;
let   sentCount       = 0;
const seen            = new Set<string>();

function send( err: ClientError ): void {
  const cfg = window.forgePMData;
  if ( ! cfg ) return;                       // no config → not running inside WP
  if ( sentCount >= MAX_PER_SESSION ) return;

  const dedupeKey = `${ err.kind }|${ err.message }|${ err.source ?? '' }|${ err.line ?? '' }|${ err.requestUrl ?? '' }`;
  if ( seen.has( dedupeKey ) ) return;
  seen.add( dedupeKey );
  sentCount++;

  const body = JSON.stringify( {
    ...err,
    url:       window.location.href,
    userAgent: navigator.userAgent,
  } );

  // keepalive lets the report still flush during page unload. This is a plain
  // fetch (not apiFetch) so a failure here never re-enters the network logger.
  try {
    fetch( `${ cfg.apiUrl }/client-errors`, {
      method:    'POST',
      keepalive: true,
      headers:   { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body,
    } ).catch( () => {} );
  } catch {
    /* never let logging throw */
  }
}

// Called by apiFetch when a request fails (network error or non-2xx response).
export function logNetworkError( requestUrl: string, status: number, message: string ): void {
  send( { kind: 'network', message, requestUrl, status } );
}

let installed = false;

export function initErrorLogger(): void {
  if ( installed ) return;
  installed = true;

  window.addEventListener( 'error', ( e: ErrorEvent ) => {
    if ( ! e.message ) return;               // skip resource-load errors with no message
    send( {
      kind:    'js',
      message: e.message,
      source:  e.filename,
      line:    e.lineno,
      col:     e.colno,
      stack:   e.error?.stack,
    } );
  } );

  window.addEventListener( 'unhandledrejection', ( e: PromiseRejectionEvent ) => {
    const reason = e.reason;
    send( {
      kind:    'promise',
      message: reason instanceof Error ? reason.message : String( reason ),
      stack:   reason instanceof Error ? reason.stack : undefined,
    } );
  } );
}
