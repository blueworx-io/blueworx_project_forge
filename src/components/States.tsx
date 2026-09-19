import { useEffect } from 'react';
import type { ReactNode } from 'react';

/**
 * #125. The four things a view can be when it is not showing work: loading,
 * empty, broken, or not yours.
 *
 * They are one component rather than four ad-hoc paragraphs because the rule is
 * about the set, not each one: **no view may render an ambiguous blank**, and
 * permission-denied has to read differently from empty. A blank board is the
 * worst possible answer to "why is there nothing here" — it is the same picture
 * for "still loading", "nothing yet", "the server fell over" and "this is not
 * yours to see", and those need four different things done about them.
 *
 * Each state says what it is, and where it can, what to do next.
 */
export type ViewState = 'loading' | 'empty' | 'error' | 'denied';

const TONE: Record< ViewState, string > = {
  loading: 'quiet',
  empty: 'quiet',
  error: 'alert',
  denied: 'alert',
};

const TITLE: Record< ViewState, string > = {
  loading: 'Loading…',
  empty: 'Nothing here yet',
  error: 'That did not load',
  denied: 'Not yours to see',
};

export function Screen( {
  state,
  title,
  detail,
  action,
  testId = 'bwx-state',
}: {
  state: ViewState;
  /** Overrides the default heading where the view can say something specific. */
  title?: string;
  /** What it means, and what to do about it. */
  detail?: ReactNode;
  /** A way out: retry, sign in, go and add a site. */
  action?: ReactNode;
  testId?: string;
} ) {
  return (
    <div
      className="bwx-state"
      data-testid={ testId }
      data-state={ state }
      data-tone={ TONE[ state ] }
      role={ 'error' === state || 'denied' === state ? 'alert' : 'status' }
      aria-busy={ 'loading' === state ? 'true' : undefined }
    >
      <p className="bwx-state-title">{ title ?? TITLE[ state ] }</p>
      { undefined !== detail && <p className="bwx-state-detail">{ detail }</p> }
      { undefined !== action && <div className="bwx-moves">{ action }</div> }
    </div>
  );
}

/**
 * The same four states, sized for a panel or a column rather than a page.
 */
export function Inline( {
  state,
  children,
  testId = 'bwx-inline-state',
}: {
  state: ViewState;
  children: ReactNode;
  testId?: string;
} ) {
  return (
    <p
      className="bwx-empty"
      data-testid={ testId }
      data-state={ state }
      data-tone={ TONE[ state ] }
      role={ 'error' === state || 'denied' === state ? 'alert' : 'status' }
    >
      { children }
    </p>
  );
}

/**
 * What a screen says after something happened, and how it went. Green is
 * "that worked", yellow is "mind this", red is "that did not work" — the
 * colour repeats what the words say, so a message is never red by default.
 */
export type SaidTone = 'ok' | 'warn' | 'danger';

export interface Said {
  text: string;
  tone: SaidTone;
}

export const NOTHING_SAID: Said = { text: '', tone: 'ok' };

export const ok = ( text: string ): Said => ( { text, tone: 'ok' } );
export const warn = ( text: string ): Said => ( { text, tone: 'warn' } );
export const failed = ( text: string ): Said => ( { text, tone: 'danger' } );

/** How long a banner that only confirms something stays, in milliseconds. */
const CONFIRMATION_STAYS = 5000;

/**
 * The banner itself; nothing when there is nothing to say.
 *
 * Green confirms, yellow warns, red says something failed (2026-09-19). With
 * `onClose` it carries a close button, and a green one — which asks nothing
 * of the reader — closes itself after five seconds. Yellow and red stay until
 * they are closed: they are telling somebody what to do next.
 */
export function Notice( {
  said,
  testId,
  onClose,
  children,
}: {
  said: Said;
  testId?: string;
  onClose?: () => void;
  children?: ReactNode;
} ) {
  const confirms = 'ok' === said.tone && '' !== said.text && Boolean( onClose );

  useEffect( () => {
    if ( ! confirms ) {
      return undefined;
    }

    const timer = window.setTimeout( () => onClose?.(), CONFIRMATION_STAYS );

    return () => window.clearTimeout( timer );
    // The text is what makes it a new banner worth its own five seconds.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ said.text, confirms ] );

  if ( '' === said.text ) {
    return null;
  }

  return (
    <div className="bwx-notice" data-tone={ said.tone } data-testid={ testId } role="status">
      <span className="bwx-notice-text">{ said.text }</span>
      { onClose && (
        <button type="button" className="bwx-notice-close" aria-label="Close" onClick={ onClose } />
      ) }
      { children && <div className="bwx-notice-body">{ children }</div> }
    </div>
  );
}
