import type { StandupCard, StandupList } from './types';
import { ALL_SITES } from './sites';
import { priorityRank } from './priority';

/**
 * The words and the shape of the day's list (#170).
 *
 * Here rather than in the screen because the sections are a fact about the
 * rules, not about the markup: which rules belong together, and in what order,
 * is the same question whoever is drawing them.
 */

/** The four sections, in the order somebody reads them. */
export const SECTIONS = [
  {
    id: 'work',
    title: 'Work needing attention',
    blurb: 'Late, due today, stopped, waiting on a requirement, or untouched for 30 days.',
    rules: [ 'overdue', 'due-today', 'blocked', 'gate-unmet', 'stale' ],
  },
  {
    id: 'turn',
    title: 'Somebody’s turn',
    blurb: 'Handed over and waiting on a person.',
    rules: [ 'awaiting-review', 'awaiting-release', 'returned' ],
  },
  {
    id: 'clients',
    title: 'Clients waiting on us',
    blurb: 'Things a client has sent us and not heard back about.',
    rules: [ 'request-waiting', 'onboarding-waiting', 'onboarding-overdue' ],
  },
  {
    id: 'studio',
    title: 'The studio itself',
    blurb: 'Problems nobody would otherwise find out about.',
    rules: [ 'over-committed', 'needs-intervention' ],
  },
] as const;

/**
 * What each rule means, said the way somebody would say it.
 *
 * Not the rule's name. "gate-unmet" is what the engine calls it; "Waiting on a
 * requirement" is what a person is looking at.
 */
const RULE_WORD: Record< string, string > = {
  overdue: 'Overdue',
  'due-today': 'Due today',
  blocked: 'Blocked',
  'gate-unmet': 'Pending',
  stale: 'Untouched 30 days',
  'awaiting-review': 'To review',
  'awaiting-release': 'Ready to ship',
  returned: 'Sent back',
  'request-waiting': 'Unanswered',
  'onboarding-waiting': 'Step waiting',
  'onboarding-overdue': 'Step overdue',
  'over-committed': 'Over hours',
  'needs-intervention': 'Needs review',
};

export function ruleWord( rule: string ): string {
  return RULE_WORD[ rule ] ?? rule;
}

/**
 * How urgent a rule is, for the rail down the side of a card.
 *
 * Three levels rather than one per rule. A board where every card shouts is a
 * board where none of them does.
 */
const RULE_TONE: Record< string, string > = {
  overdue: 'late',
  'onboarding-overdue': 'late',
  blocked: 'stopped',
  'needs-intervention': 'stopped',
  'over-committed': 'stopped',
};

export function ruleTone( rule: string ): string {
  return RULE_TONE[ rule ] ?? 'waiting';
}

/**
 * A section's cards by due date, then priority (#483). Undated cards go last,
 * and cards that tie keep the server's order, so a card that is not about
 * dated work — over hours, a quiet site — stays where it was.
 */
export function inOrder( cards: StandupCard[] ): StandupCard[] {
  const dueOf = ( card: StandupCard ): string => String( card.detail?.planned_due ?? '' ) || '9999-99-99';

  return [ ...cards ].sort(
    ( a, b ) => dueOf( a ).localeCompare( dueOf( b ) ) || priorityRank( a.detail?.priority ) - priorityRank( b.detail?.priority )
  );
}

/** A card's own identity, so one can be told from another in a list. */
export function keyOf( card: StandupCard ): string {
  return `${ card.rule }:${ card.subject_id }`;
}

/**
 * What one card says beyond its heading.
 *
 * Read off the detail the rule chose to carry, and nothing worked out here — a
 * screen that recalculated whether something was late would be a second answer
 * to a question the server has already answered.
 */
export function cardDetail( card: StandupCard ): string {
  const detail = card.detail ?? {};
  const said = ( key: string ): string => {
    const value = detail[ key ];

    return undefined === value || null === value ? '' : String( value );
  };

  switch ( card.rule ) {
    case 'overdue':
    case 'due-today':
      return '' === said( 'due' ) ? '' : `Due ${ said( 'due' ) }`;
    case 'onboarding-overdue':
      return '' === said( 'due' ) ? '' : `Wanted by ${ said( 'due' ) }`;
    case 'stale':
      return '' === said( 'last_touched' ) ? '' : `Last touched ${ said( 'last_touched' ) }`;
    case 'gate-unmet': {
      const unmet = Array.isArray( detail.unmet ) ? detail.unmet.length : 0;

      return 1 === unmet ? 'One thing outstanding' : `${ unmet } things outstanding`;
    }
    case 'returned':
      return said( 'reason' );
    case 'awaiting-review':
      // #391. "The client" when the client reviews; a person is not named here.
      return '' === said( 'waiting_on_name' ) ? '' : `Waiting on ${ said( 'waiting_on_name' ).toLowerCase() }`;
    case 'over-committed':
      return `${ said( 'committed' ) } hours committed of ${ said( 'available' ) }`;
    case 'needs-intervention':
      // A site says what is wrong in words; the code is for machines (2026-09-27).
      if ( 'client_site' === said( 'subject_type' ) ) return said( 'detail' );

      return 'notification' === said( 'subject_type' ) || '' !== said( 'kind' )
        ? said( 'kind' )
        : '';
    default:
      return '';
  }
}

/** What a card is about, in a word, so the heading can name it. */
export function cardTitle( card: StandupCard ): string {
  const detail = card.detail ?? {};
  const title = detail.title ?? detail.display_name ?? detail.about ?? '';

  return '' === String( title ) ? card.subject_id : String( title );
}

/** The site a card is about, or '' when it belongs to no one client (#402). */
function siteOf( card: StandupCard ): string {
  const detail = card.detail ?? {};

  return String( detail.client_site_id ?? ( 'client_site' === card.subject_type ? detail.about ?? '' : '' ) );
}

/** Whether something for this site shows under the top bar's client; no client always shows. */
function forSite( id: string, siteId: string ): boolean {
  return ALL_SITES === siteId || '' === id || id === siteId;
}

/** The cards the standup lists for the picked client. */
export function cardsFor( list: StandupList | undefined, siteId: string ): StandupCard[] {
  return ( list?.cards ?? [] ).filter( ( card ) => forSite( siteOf( card ), siteId ) );
}

/** The meetings the standup asks to be settled, for the picked client. */
export function toSettleFor( list: StandupList | undefined, siteId: string ): NonNullable< StandupList[ 'to_settle' ] > {
  return ( list?.to_settle ?? [] ).filter( ( entry ) => forSite( entry.site_id, siteId ) );
}

/** The number in the sidebar: what needs attention, and meetings waiting to be settled. */
export function standupCount( list: StandupList | undefined, siteId: string ): number {
  return cardsFor( list, siteId ).length + toSettleFor( list, siteId ).length;
}
