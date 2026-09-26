import type { ClientSite } from './types';

/**
 * A site as the picker sees it: the site, its client's name, and whether it
 * is the studio's own.
 */
export interface SiteOption extends ClientSite {
  client_name: string;
  studio?: boolean;
}

/** The picker's value for "every site at once". */
export const ALL_SITES = 'all';

/** Where the last chosen site is remembered, per browser. */
const REMEMBERED = 'bwx-forge-site';

/**
 * How a site reads in a picker.
 *
 * A client with one site is named once — "The Light", not "The Light — The
 * Light". Only when a client has several sites does the site's own name
 * earn its place beside the client's, because that is when it tells them
 * apart.
 */
export function siteLabel( site: SiteOption, sites: SiteOption[] ): string {
  if ( '' === site.client_name ) {
    return site.name;
  }

  const siblings = sites.filter( ( one ) => one.client_id === site.client_id ).length;

  return 1 < siblings ? `${ site.client_name } — ${ site.name }` : site.client_name;
}

/**
 * Which client the top bar opens on (#402).
 *
 * A site a link named, if the person reaches it; otherwise the last one
 * chosen, if still reachable; otherwise All clients. A site that has gone,
 * or been taken away, falls back to All rather than to a blank screen.
 */
export function openingSite( sites: SiteOption[], asked = '' ): string {
  if ( '' !== asked && sites.some( ( one ) => one.id === asked ) ) {
    return asked;
  }

  return rememberedSite( sites, true ) || ALL_SITES;
}

/**
 * The last site chosen, if it is still offered; otherwise nothing.
 * `allowAll` lets a remembered All clients count too, as the top bar's
 * picker offers it (#402).
 */
export function rememberedSite( sites: SiteOption[], allowAll = false ): string {
  let remembered = '';

  try {
    remembered = window.localStorage.getItem( REMEMBERED ) ?? '';
  } catch {
    // Storage can be missing or refused; nothing remembered is fine.
  }

  if ( allowAll && ALL_SITES === remembered ) {
    return remembered;
  }

  return sites.some( ( one ) => one.id === remembered ) ? remembered : '';
}

/** Remembers a choice for next time. */
export function rememberSite( id: string ): void {
  try {
    window.localStorage.setItem( REMEMBERED, id );
  } catch {
    // Nothing to do: the choice still applies for this visit.
  }
}
