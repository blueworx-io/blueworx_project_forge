import { Item, CompanyDate, AppSettings, ArchivedItem } from '../types';

declare global {
  interface Window {
    forgePMData?: {
      apiUrl: string;
      nonce: string;
      isAdmin: boolean;
      siteUrl: string;
      settings?: AppSettings;
    };
  }
}

function getConfig() {
  return window.forgePMData ?? {
    apiUrl: '/wp-json/forge/v1',
    nonce: '',
    isAdmin: false,
    siteUrl: '',
  };
}

export function isAdmin(): boolean {
  return getConfig().isAdmin;
}

async function apiFetch<T>( path: string, options: RequestInit = {} ): Promise<T> {
  const { apiUrl, nonce } = getConfig();
  const res = await fetch( `${ apiUrl }${ path }`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
      ...( options.headers ?? {} ),
    },
  } );
  if ( ! res.ok ) {
    const err = await res.json().catch( () => ( {} ) );
    throw new Error( ( err as any ).message ?? `HTTP ${ res.status }` );
  }
  return res.json();
}

export interface AllItems {
  features: any[];
  subitems: any[];
  bugs: any[];
  feedback: any[];
  releases: any[];
  companyDates: CompanyDate[];
}

export function fetchAllItems(): Promise<AllItems> {
  return apiFetch<AllItems>( '/items' );
}

export function updateItem( type: string, id: string, data: Partial<Item> ): Promise<{ success: boolean }> {
  return apiFetch( `/items/${ type }/${ id }`, {
    method: 'PUT',
    body: JSON.stringify( data ),
  } );
}

export function updateStage( type: string, id: string, workflowStage: string ): Promise<{ success: boolean }> {
  return apiFetch( `/items/${ type }/${ id }/stage`, {
    method: 'PATCH',
    body: JSON.stringify( { workflowStage } ),
  } );
}

export function createCompanyDate( data: { title: string; date: string; description: string; tracked: boolean } ): Promise<CompanyDate> {
  return apiFetch<CompanyDate>( '/company-dates', {
    method: 'POST',
    body: JSON.stringify( data ),
  } );
}

// ── Settings ────────────────────────────────────────────────────────────────

export function fetchSettings(): Promise<AppSettings> {
  return apiFetch<AppSettings>( '/settings' );
}

export function saveSettings( data: Partial<AppSettings> ): Promise<AppSettings> {
  return apiFetch<AppSettings>( '/settings', {
    method: 'PUT',
    body: JSON.stringify( data ),
  } );
}

// ── Archive ─────────────────────────────────────────────────────────────────

export function fetchArchived(): Promise<ArchivedItem[]> {
  return apiFetch<ArchivedItem[]>( '/archive' );
}

export function archiveItem( type: string, id: string ): Promise<{ success: boolean }> {
  return apiFetch( `/items/${ type }/${ id }/archive`, { method: 'POST' } );
}

export function restoreItem( type: string, id: string ): Promise<{ success: boolean }> {
  return apiFetch( `/items/${ type }/${ id }/restore`, { method: 'POST' } );
}

// Seed settings in dev mode (no WordPress)
export function getInitialSettings(): AppSettings {
  return window.forgePMData?.settings ?? {
    parentBrand: '',
    teamMonthlyHours: 160,
    brands: [ 'SwingU', '18Birdies', 'TheGrint', 'Hole19', 'Golf Pad' ],
    categories: [
      'GPS & Shot Tracking', 'Training & Coaching', 'Scoring & Stats',
      'Games & Leaderboards', 'Gamification', 'Handicap Options',
      'Premium Perks', 'External Hardware', 'App Tools',
    ],
  };
}
