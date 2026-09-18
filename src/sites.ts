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
 * Which site the picker should open on.
 *
 * The last one chosen, if it is still offered; otherwise the studio's own
 * site, because the board is for our work first; otherwise whatever is
 * first. "All clients" is remembered like any site.
 */
export function recallSite( sites: SiteOption[] ): string {
  let remembered = '';

  try {
    remembered = window.localStorage.getItem( REMEMBERED ) ?? '';
  } catch {
    // Storage can be missing or refused; the default below is fine.
  }

  if ( ALL_SITES === remembered || sites.some( ( one ) => one.id === remembered ) ) {
    return remembered;
  }

  return sites.find( ( one ) => one.studio )?.id ?? sites[ 0 ]?.id ?? '';
}

/**
 * The last site chosen, and only that: the id if it is still offered,
 * otherwise nothing. For a screen that is about one site and should open on
 * nothing rather than guess — the board's "studio first, else whatever is
 * first" is right for work and wrong for a site's commercial record.
 */
export function rememberedSite( sites: SiteOption[] ): string {
  let remembered = '';

  try {
    remembered = window.localStorage.getItem( REMEMBERED ) ?? '';
  } catch {
    // Storage can be missing or refused; nothing remembered is fine.
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
