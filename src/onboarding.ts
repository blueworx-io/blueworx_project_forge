import type { OnboardingFilters } from './types';

/**
 * The words the onboarding board uses (#165).
 *
 * Here rather than in either component because both need them, and a shared
 * word imported from whichever screen happened to define it is how two files
 * end up importing each other.
 */

/**
 * What a status is called, in the words somebody would use about it.
 *
 * "Waiting on us" rather than "Submitted", because the studio's board is read
 * to find out what the studio has to do. The client's own page says the same
 * step is "Sent" — the record is one thing and what it means depends on which
 * side of it you are standing.
 */
const STATUS_WORD: Record< string, string > = {
  'not-started': 'Not started',
  'in-progress': 'In progress',
  submitted: 'Waiting on us',
  returned: 'Sent back',
  approved: 'Approved',
  'not-applicable': 'Not applicable',
  blocked: 'Blocked',
};

export function statusWord( status: string ): string {
  return STATUS_WORD[ status ] ?? status;
}

/**
 * Whose step it is.
 *
 * The template's word for our side is `internal`, which is a word about the
 * record rather than about anybody. On screen it is "Us".
 */
export function sideWord( side: string ): string {
  return 'internal' === side ? 'Us' : 'The client';
}

/** A filter set as a query string, leaving out everything nobody chose. */
export function query( filters: OnboardingFilters ): string {
  const parts = Object.entries( filters )
    .filter( ( [ , value ] ) => '' !== value && undefined !== value )
    .map( ( [ key, value ] ) => `${ key }=${ encodeURIComponent( String( value ) ) }` );

  return 0 === parts.length ? '' : `?${ parts.join( '&' ) }`;
}
