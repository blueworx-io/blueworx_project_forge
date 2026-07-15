// In-memory backend for standalone local dev (`npm run dev`, no WordPress).
//
// When `window.forgePMData` is absent there is no REST API to talk to, so every
// write would otherwise no-op or throw. This module makes the whole app testable
// by operating directly on the Zustand data store (the in-memory "DB"). All state
// lives in memory and resets on page reload — by design, every reload restores the
// clean expanded sample set.
//
// `wordpress.ts` delegates each API call here when running standalone; the live
// WordPress code path is never affected.

import { Item, CompanyDate, ArchivedItem, Connection, ConnectionDelivery, ConnectionTestResult } from '../types';
import { useDataStore } from '../store/useDataStore';
import { sampleArchived } from '../data/sampleData';

// ── Helpers ───────────────────────────────────────────────────────────────────

type StoreKey = 'features' | 'subitems' | 'bugs' | 'feedback' | 'releases' | 'companyDates';

const TYPE_KEY: Record<string, StoreKey> = {
  feature:      'features',
  subitem:      'subitems',
  bug:          'bugs',
  feedback:     'feedback',
  release:      'releases',
  company_date: 'companyDates',
};

const ID_PREFIX: Record<string, string> = {
  feature: 'f', subitem: 's', bug: 'b', feedback: 'fb', release: 'r', company_date: 'cd',
};

let idCounter = 1000;
function nextId( type: string ): string {
  return `${ ID_PREFIX[type] ?? 'x' }${ idCounter++ }`;
}

function today(): string {
  return new Date().toISOString().slice( 0, 10 );
}

type Row = Record<string, unknown> & { id: string };

function getArr( type: string ): Row[] {
  const key = TYPE_KEY[type];
  return ( useDataStore.getState()[key] as unknown as Row[] ) ?? [];
}

function setArr( type: string, rows: Row[] ): void {
  const key = TYPE_KEY[type];
  useDataStore.setState( { [key]: rows } as unknown as Partial<ReturnType<typeof useDataStore.getState>> );
}

function patchArr( type: string, id: string, patch: Record<string, unknown> ): Row | undefined {
  let updated: Row | undefined;
  const next = getArr( type ).map( row => {
    if ( row.id !== id ) return row;
    updated = { ...row, ...patch };
    return updated;
  } );
  setArr( type, next );
  return updated;
}

// ── Archive (module-level, seeded from sample data) ──────────────────────────

interface ArchivedEntry { type: string; archivedAt: string; item: Row; }

const archived: ArchivedEntry[] = sampleArchived.map( e => ( {
  type:       e.type,
  archivedAt: e.archivedAt,
  item:       { ...( e.item as unknown as Row ) },
} ) );

// ── Entity defaults for created items ────────────────────────────────────────

function buildItem( type: string, id: string, data: Record<string, unknown> ): Row {
  const stage = ( data.workflowStage as string ) ?? 'future-idea';
  const base: Record<string, Record<string, unknown>> = {
    feature: {
      type: 'feature', name: '', description: '', workflowStage: stage,
      category: '', featurePrice: 'scoping', timeEstimate: 0,
      isEnabled: false, isTrackedAsStat: false, createdDate: today(),
      brands: [], stageDates: { [stage]: today() },
    },
    subitem: {
      type: 'subitem', name: '', description: '', parentFeatureId: '',
      workflowStage: stage, category: '', featurePrice: 'scoping',
      timeEstimate: 0, brands: [],
    },
    bug: {
      type: 'bug', title: '', description: '', bugStatus: 'open',
      workflowStage: 'bug-tracking', priority: 'medium', timeEstimate: 0,
      reportedDate: today(),
    },
    feedback: {
      type: 'feedback', title: '', description: '', status: 'open',
      workflowStage: 'up-next', priority: 'medium', timeEstimate: 0,
      reportedDate: today(),
    },
    release: {
      type: 'release', name: '', quarter: '', startWeek: '', endWeek: '',
      status: 'planned', totalTimeEstimate: 0, capacity: 0,
      isBigWedgeCampaign: false, linkedFeatureIds: [], linkedBugIds: [], linkedFeedbackIds: [],
    },
  };
  return { ...( base[type] ?? {} ), ...data, id, type } as Row;
}

// ── API surface (mirrors wordpress.ts) ───────────────────────────────────────

export async function createItem( type: string, data: Record<string, unknown> ): Promise<{ success: boolean; id: string }> {
  const id   = nextId( type );
  const item = buildItem( type, id, data );
  setArr( type, [ ...getArr( type ), item ] );

  // Keep the parent feature's subItemIds in sync when adding a sub-item.
  if ( type === 'subitem' && item.parentFeatureId ) {
    const parent = getArr( 'feature' ).find( f => f.id === item.parentFeatureId );
    if ( parent ) {
      const ids = Array.isArray( parent.subItemIds ) ? parent.subItemIds as string[] : [];
      patchArr( 'feature', parent.id, { subItemIds: [ ...ids, id ] } );
    }
  }
  return { success: true, id };
}

export async function updateItem( type: string, id: string, data: Partial<Item> ): Promise<{ success: boolean; id: string; item?: Item }> {
  const updated = patchArr( type, id, data as Record<string, unknown> );
  return { success: true, id, item: updated as unknown as Item | undefined };
}

export async function fetchItem( type: string, id: string ): Promise<Item> {
  const found = getArr( type ).find( row => row.id === id );
  return found as unknown as Item;
}

export async function deleteItem( type: string, id: string ): Promise<{ success: boolean }> {
  setArr( type, getArr( type ).filter( row => row.id !== id ) );
  return { success: true };
}

export async function updateStage( type: string, id: string, workflowStage: string ): Promise<{ success: boolean }> {
  patchArr( type, id, { workflowStage } );
  return { success: true };
}

export async function archiveItem( type: string, id: string ): Promise<{ success: boolean }> {
  const item = getArr( type ).find( row => row.id === id );
  if ( item ) {
    setArr( type, getArr( type ).filter( row => row.id !== id ) );
    archived.unshift( { type, archivedAt: today(), item } );
  }
  return { success: true };
}

export async function restoreItem( type: string, id: string ): Promise<{ success: boolean }> {
  const idx = archived.findIndex( e => e.item.id === id );
  if ( idx >= 0 ) {
    const [ entry ] = archived.splice( idx, 1 );
    setArr( entry.type, [ ...getArr( entry.type ), entry.item ] );
  }
  return { success: true };
}

export async function fetchArchived(): Promise<ArchivedItem[]> {
  return archived.map( e => ( {
    id:         e.item.id,
    itemType:   e.type as ArchivedItem['itemType'],
    name:       ( e.item.name ?? e.item.title ?? '(untitled)' ) as string,
    archivedAt: e.archivedAt,
  } ) );
}

export async function createCompanyDate( data: { title: string; date: string; description: string; tracked: boolean } ): Promise<CompanyDate> {
  const cd: CompanyDate = { id: nextId( 'company_date' ), ...data };
  setArr( 'company_date', [ ...getArr( 'company_date' ), cd as unknown as Row ] );
  return cd;
}

export async function updateCompanyDate( id: string, data: Partial<CompanyDate> ): Promise<{ success: boolean }> {
  patchArr( 'company_date', id, data as Record<string, unknown> );
  return { success: true };
}

// ── Connections (standalone dev only) ────────────────────────────────────────

let mockConnections: Connection[] = [];
// Never written to: standalone dev simulates delivery rather than performing it,
// so there are no real attempts to record. An empty Activity list is correct here.
const mockConnectionLog: ConnectionDelivery[] = [];

function uuid(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    return ( c === 'x' ? r : ( r & 0x3 | 0x8 ) ).toString( 16 );
  } );
}

/** Strips authToken exactly as the real API does, so the UI is exercised faithfully. */
function toPublicConnection( c: Connection ): Connection {
  const { authToken, ...rest } = c;
  return authToken ? { ...rest, authTokenHint: '••••' + authToken.slice( -4 ) } : rest;
}

export async function fetchConnections(): Promise<Connection[]> {
  return mockConnections.map( toPublicConnection );
}

export async function createConnection( data: Partial<Connection> ): Promise<Connection> {
  const row: Connection = {
    id:        uuid(),
    name:      data.name ?? '',
    url:       data.url ?? '',
    authToken: data.authToken ?? '',
    itemTypes: data.itemTypes ?? [],
    enabled:   data.enabled ?? false,
    createdAt: new Date().toISOString(),
  };
  mockConnections = [ ...mockConnections, row ];
  return toPublicConnection( row );
}

export async function updateConnection( id: string, data: Partial<Connection> ): Promise<Connection> {
  mockConnections = mockConnections.map( c => {
    if ( c.id !== id ) return c;
    const next = { ...c, ...data };
    // Blank token means "leave unchanged", matching the PHP behaviour.
    if ( ! data.authToken ) next.authToken = c.authToken;
    return next;
  } );
  const found = mockConnections.find( c => c.id === id );
  if ( ! found ) throw new Error( 'Connection not found' );
  return toPublicConnection( found );
}

export async function deleteConnection( id: string ): Promise<{ success: boolean }> {
  mockConnections = mockConnections.filter( c => c.id !== id );
  return { success: true };
}

/**
 * Simulated — standalone dev never makes a real outbound call. The id is still
 * resolved so a missing connection fails here exactly as it 404s on the real API.
 */
export async function testConnection( id: string ): Promise<ConnectionTestResult> {
  if ( ! mockConnections.some( c => c.id === id ) ) {
    throw new Error( 'Connection not found' );
  }
  return { success: true, httpCode: 200 };
}

export async function fetchConnectionLog(): Promise<ConnectionDelivery[]> {
  return mockConnectionLog;
}
