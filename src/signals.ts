import type { Signal } from './types';

/**
 * The words for what has happened lately (#175).
 *
 * Here rather than in the component for the reason the day's list keeps its
 * words apart too: how an event reads is a fact about the event, not about the
 * markup, and the next thing that wants to print one should not have to
 * re-derive it.
 *
 * These are a second copy of the server's own wording, and that is deliberate
 * rather than overlooked. The server needs them for anything it renders itself;
 * the app needs them without a round trip. What must never differ is *which*
 * events are signals at all, and that single list lives on the server and comes
 * down with the answer.
 */
const WORD: Record< string, string > = {
  moved: 'Moved on',
  returned: 'Sent back',
  blocked: 'Blocked',
  unblocked: 'Unblocked',
  ended: 'Ended',
  archived: 'Archived',
  reopened: 'Reopened',
  overridden: 'A gate was overridden',
  'over-allocated': 'Committed past their hours',
  converted: 'Turned into work',
  'dependency-added': 'Now waits on something else',
  requested: 'A client asked for something',
};

export function signalWord( signal: Signal ): string {
  return WORD[ signal.action ] ?? signal.action;
}

/**
 * How loud the row is.
 *
 * The same three tones the day's list uses, so somebody reading both does not
 * have to learn two colour schemes. Governance comes off the server rather than
 * being inferred from the action here — which events the studio agreed would be
 * visible is a decision, not a styling rule.
 */
export function signalTone( signal: Signal ): string {
  if ( signal.governance ) {
    return 'stopped';
  }

  return [ 'returned', 'blocked' ].includes( signal.action ) ? 'late' : 'waiting';
}

/**
 * When it happened, in the roughest unit that still says something.
 *
 * Rough on purpose. "3 hours ago" is what somebody wants from a list they are
 * scanning; the exact minute is on the item's own history, where somebody
 * reconstructing what happened would go looking for it.
 */
export function whenOf( at: number ): string {
  const seconds = Math.max( 0, Math.floor( Date.now() / 1000 ) - at );

  if ( seconds < 3600 ) {
    const minutes = Math.floor( seconds / 60 );

    return 1 > minutes ? 'just now' : `${ minutes }m ago`;
  }

  if ( seconds < 86400 ) {
    return `${ Math.floor( seconds / 3600 ) }h ago`;
  }

  return `${ Math.floor( seconds / 86400 ) }d ago`;
}
