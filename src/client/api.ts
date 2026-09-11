import { clientData } from './data';

/*
 * The client app's side of the client plugin's REST routes (#298). Every
 * shape here is what the PHP view for that screen already returns — the app
 * reads the same record the wp-admin screen renders, and works nothing out
 * that the plugin has not.
 */

export interface Sync {
  state: 'live' | 'stale' | 'unreachable' | 'never' | string;
  stale: boolean;
  fetched_at: number;
  age: number;
  reason: string;
}

export interface Contact {
  display_name?: string;
  role?: string;
  email?: string;
}

export interface Support {
  state: string;
  label: string;
  allowed: string[];
  refused: string[];
}

export interface WorkspaceView {
  ok: boolean;
  record: { site_id: string; name: string; url: string; status: string; connected_since: number } | null;
  contact: Contact | [];
  support: Support | [];
  sync: Sync;
}

export interface Person {
  id?: string;
  name?: string;
  display_name?: string;
}

export interface WorkItem {
  id: string;
  parent_id: string;
  title: string;
  stage: string;
  stage_label: string;
  level: string;
  level_label: string;
  work_type: string;
  work_type_label: string;
  planned_start: string;
  planned_due: string;
  review_target: string;
  release_target: string;
  people: { primary: Person[]; reviewer: Person[]; deliverer: Person[] };
}

export interface BoardView {
  ok: boolean;
  items: WorkItem[];
  stages: Array< { slug: string; label: string } >;
  attention?: Array< { reason: 'blocked' | 'overdue'; item: WorkItem } >;
  upcoming?: WorkItem[];
  sync: Sync;
}

export interface Entitlement {
  state: string;
  label: string;
  may_use_hours: boolean;
  hours_granted: number;
  starts_on: string;
  ends_on: string;
  term_ends_on: string;
}

export interface Purchase {
  kind?: string;
  reason?: string;
  name?: string;
  hours?: number;
  bought_at?: number;
  expires_at?: number;
}

export interface Package {
  name: string;
  hours: number;
  price: number;
  currency: string;
  validity_months: number;
}

export interface SalesView {
  ok: boolean;
  entitlement: Entitlement | [];
  balance: number | null;
  support: Support | [];
  purchases: Purchase[];
  packages: Package[];
  sync: Sync;
}

export interface Step {
  id: string;
  section: string;
  title: string;
  status: string;
  owner_side: string;
  launch_critical: number;
  optional: number;
  due_on: string;
  response: string;
  overdue: boolean;
  feedback: string;
  evidence: Array< { id: string; original_name: string; mime_type: string; size_bytes: number; uploaded_at: number } >;
}

export interface ChecklistView {
  ok: boolean;
  steps: Step[];
  sections: Record< string, Step[] >;
  /** The one step the wp-admin screen asks about; empty when none. */
  next: Step | [];
  /** Every step that is the client's to do now. */
  yours: Step[];
  progress: { required: number; approved: number; completion: number; launch_ready: boolean; blocking: Step[] };
  sync: Sync;
}

export interface Submission {
  id: string;
  type: string;
  title: string;
  status: string;
  status_label?: string;
  submitted_at?: number;
  converted_to?: string;
  history?: Array< { status: string; at: number; note?: string } >;
}

export interface SubmissionsView {
  ok: boolean;
  submissions: Submission[];
  states: Array< { slug: string; label: string } >;
  sync: Sync;
}

export class ApiError extends Error {
  readonly status: number;

  constructor( status: number, message: string ) {
    super( message );
    this.name = 'ApiError';
    this.status = status;
  }
}

async function call< T >( method: 'GET' | 'POST', path: string, body?: FormData | Record< string, unknown > ): Promise< T > {
  const data = clientData();
  if ( ! data ) throw new ApiError( 0, 'Running outside WordPress' );

  const headers: Record< string, string > = { 'X-WP-Nonce': data.nonce };
  let payload: BodyInit | undefined;
  if ( body instanceof FormData ) {
    payload = body;
  } else if ( body ) {
    headers[ 'Content-Type' ] = 'application/json';
    payload = JSON.stringify( body );
  }

  const response = await fetch( `${ data.restUrl }${ path }`, { method, headers, body: payload, credentials: 'same-origin' } );
  if ( ! response.ok ) {
    throw new ApiError( response.status, `${ method } ${ path } answered ${ response.status }` );
  }
  return ( await response.json() ) as T;
}

export const api = {
  workspace: ( refresh = false ) => call< WorkspaceView >( 'GET', `/workspace${ refresh ? '?refresh=true' : '' }` ),
  board: ( refresh = false ) => call< BoardView >( 'GET', `/board${ refresh ? '?refresh=true' : '' }` ),
  sales: ( refresh = false ) => call< SalesView >( 'GET', `/sales${ refresh ? '?refresh=true' : '' }` ),
  checklist: ( refresh = false ) => call< ChecklistView >( 'GET', `/checklist${ refresh ? '?refresh=true' : '' }` ),
  submissions: ( refresh = false ) => call< SubmissionsView >( 'GET', `/submissions${ refresh ? '?refresh=true' : '' }` ),

  discussion: ( item: string ) => call< { ok: boolean; comments: Array< Record< string, unknown > > } >( 'GET', `/items/${ item }/discussion` ),
  say: ( item: string, values: { body: string; url?: string; answers?: string } ) =>
    call< { ok: boolean; result: string; message: string } >( 'POST', `/items/${ item }/discussion`, values ),
  submit: ( form: FormData ) => call< { ok: boolean; result: string; fields?: Record< string, string > } >( 'POST', '/submissions', form ),
  answerStep: ( step: string, values: { response: string; intent: 'save' | 'submit' } ) =>
    call< { ok: boolean; result: string } >( 'POST', `/checklist/${ step }/answer`, values ),
  attachToStep: ( step: string, form: FormData ) => call< { ok: boolean; result: string } >( 'POST', `/checklist/${ step }/evidence`, form ),
};

/** `12 Aug 2026`, the way the design writes a date; '' stays ''. */
export function day( iso: string ): string {
  if ( ! iso ) return '';
  const date = new Date( `${ iso }T00:00:00Z` );
  if ( isNaN( date.getTime() ) ) return iso;
  return date.toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' } );
}

/** The same, from a unix timestamp. */
export function dayOf( stamp: number ): string {
  if ( ! stamp ) return '';
  return new Date( stamp * 1000 ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } );
}

/** `12.5h` — hours to one decimal with the unit, always. */
export function hours( n: number | null | undefined ): string {
  return `${ ( n ?? 0 ).toFixed( 1 ) }h`;
}
