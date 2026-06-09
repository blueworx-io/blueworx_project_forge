import { Item, Feature, SubItem, Bug, Feedback, Release, CompanyDate, AppSettings, ArchivedItem, WorkflowStatus, BrandConfig } from '../types';
import { logNetworkError } from './errorLogger';

declare global {
  interface Window {
    forgePMData?: {
      apiUrl: string;
      nonce: string;
      isAdmin: boolean;
      canEdit?: boolean;
      userRole?: string;
      adminUrl?: string;
      isLoggedIn?: boolean;
      siteUrl: string;
      loginUrl?: string;
      logoutUrl?: string;
      settings?: AppSettings;
    };
  }
}

function getConfig() {
  return window.forgePMData ?? {
    apiUrl: '/wp-json/forge/v1',
    nonce: '',
    // Standalone dev only (no WordPress): grant admin so editing UI is testable.
    // import.meta.env.DEV is false in any production build, and live WordPress
    // always injects window.forgePMData, so this never affects live data.
    isAdmin: import.meta.env.DEV,
    canEdit: import.meta.env.DEV,
    userRole: import.meta.env.DEV ? 'admin' : 'visitor',
    adminUrl: '',
    siteUrl: '',
  };
}

/** True only for WP administrators (manage_options). */
export function isAdmin(): boolean {
  return getConfig().isAdmin;
}

/** True for admins and Forge Managers — anyone who can create/edit/delete items. */
export function canEdit(): boolean {
  return getConfig().canEdit ?? getConfig().isAdmin;
}

/** True for Forge Managers (edit_posts but not manage_options). */
export function isManager(): boolean {
  return getConfig().userRole === 'manager';
}

export function getUserRole(): string {
  return getConfig().userRole ?? 'visitor';
}

export function isLoggedIn(): boolean {
  return getConfig().isLoggedIn ?? false;
}

export function getLoginUrl(): string {
  return `${ getConfig().siteUrl }/admin_login`;
}

export function getLogoutUrl(): string {
  return getConfig().logoutUrl || '/wp-login.php?action=logout';
}

export function getAdminUrl(): string {
  return getConfig().adminUrl || '/wp-admin/';
}

async function apiFetch<T>( path: string, options: RequestInit = {} ): Promise<T> {
  const { apiUrl, nonce } = getConfig();
  const url = `${ apiUrl }${ path }`;

  let res: Response;
  try {
    res = await fetch( url, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
        ...( options.headers ?? {} ),
      },
    } );
  } catch ( e ) {
    // Network-level failure (offline, DNS, CORS, aborted, etc.)
    logNetworkError( url, 0, e instanceof Error ? e.message : 'Network request failed' );
    throw e;
  }

  if ( ! res.ok ) {
    const err = await res.json().catch( () => ( {} ) );
    const message = ( err as { message?: string } ).message ?? `HTTP ${ res.status }`;
    logNetworkError( url, res.status, message );
    throw new Error( message );
  }
  return res.json();
}

export interface AllItems {
  features: Feature[];
  subitems: SubItem[];
  bugs: Bug[];
  feedback: Feedback[];
  releases: Release[];
  companyDates: CompanyDate[];
}

export function fetchAllItems(): Promise<AllItems> {
  return apiFetch<AllItems>( '/items' );
}

export function updateItem( type: string, id: string, data: Partial<Item> ): Promise<{ success: boolean; id: string; item?: Item }> {
  return apiFetch( `/items/${ type }/${ id }`, {
    method: 'PUT',
    body: JSON.stringify( data ),
  } );
}

export function fetchItem( type: string, id: string ): Promise<Item> {
  return apiFetch( `/items/${ type }/${ id }` );
}

export function createItem( type: string, data: Record<string, unknown> ): Promise<{ success: boolean; id: string }> {
  return apiFetch( `/items/${ type }`, {
    method: 'POST',
    body: JSON.stringify( data ),
  } );
}

export function deleteItem( type: string, id: string ): Promise<{ success: boolean }> {
  return apiFetch( `/items/${ type }/${ id }`, { method: 'DELETE' } );
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

export function updateCompanyDate( id: string, data: Partial<CompanyDate> ): Promise<{ success: boolean }> {
  return apiFetch( `/items/company_date/${ id }`, {
    method: 'PUT',
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

// ── Default statuses ────────────────────────────────────────────────────────

export const DEFAULT_STATUSES: WorkflowStatus[] = [
  { id: 'bug-tracking',        label: 'Bug Tracking' },
  { id: 'future-idea',         label: 'Future Idea' },
  { id: 'triage',              label: 'Triage' },
  { id: 'documentation-period',label: 'Documentation Period' },
  { id: 'technical-audit',     label: 'Technical Audit' },
  { id: 'design-period',       label: 'Design Period' },
  { id: 'up-next',             label: 'Up Next (Assign Hours)' },
  { id: 'in-development',      label: 'In Development' },
  { id: 'in-review',           label: 'In Review' },
  { id: 'deployed',            label: 'Deployed' },
];

function normalizeBrands( brands: (string | BrandConfig)[] ): BrandConfig[] {
  return brands.map( b => typeof b === 'string' ? { name: b, logo: '' } : b );
}

export function getInitialSettings(): AppSettings {
  const raw = window.forgePMData?.settings;
  if ( raw ) {
    if ( Array.isArray( raw.brands ) ) raw.brands = normalizeBrands( raw.brands as (string | BrandConfig)[] );
    return raw;
  }
  return {
    parentBrand: '',
    teamMonthlyHours: 160,
    releaseDay: 1,
    brands: [
      { name: 'SwingU',    logo: '' },
      { name: '18Birdies', logo: '' },
      { name: 'TheGrint',  logo: '' },
      { name: 'Hole19',    logo: '' },
      { name: 'Golf Pad',  logo: '' },
    ],
    categories: [
      'GPS & Shot Tracking', 'Training & Coaching', 'Scoring & Stats',
      'Games & Leaderboards', 'Gamification', 'Handicap Options',
      'Premium Perks', 'External Hardware', 'App Tools',
    ],
    statuses: DEFAULT_STATUSES,
  };
}
