import type { StandupCard } from './types';

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
    blurb: 'Late, due today, stopped, or waiting on a requirement.',
    rules: [ 'overdue', 'due-today', 'blocked', 'gate-unmet' ],
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
  'gate-unmet': 'Waiting on a requirement',
  'awaiting-review': 'Waiting to be reviewed',
  'awaiting-release': 'Ready, waiting to go live',
  returned: 'Sent back',
  'request-waiting': 'Request unanswered',
  'onboarding-waiting': 'Checklist step waiting on us',
  'onboarding-overdue': 'Checklist step overdue',
  'over-committed': 'Over their hours',
  'needs-intervention': 'Needs somebody to step in',
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
    case 'gate-unmet': {
      const unmet = Array.isArray( detail.unmet ) ? detail.unmet.length : 0;

      return 1 === unmet ? 'One thing outstanding' : `${ unmet } things outstanding`;
    }
    case 'returned':
      return said( 'reason' );
    case 'over-committed':
      return `${ said( 'committed' ) } hours committed of ${ said( 'available' ) }`;
    case 'needs-intervention':
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
