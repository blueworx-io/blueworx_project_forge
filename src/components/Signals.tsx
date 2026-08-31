import { useCallback, useEffect, useRef, useState } from 'react';
import type { Signal, SignalList } from '../types';
import { api, messageFor } from '../api';
import { signalTone, signalWord, whenOf } from '../signals';

/**
 * What has happened lately, in the shell rather than on a screen (#175).
 *
 * It lives next to the wordmark because it is the one thing in the product that
 * is true whichever screen is open: a request arriving or somebody sending your
 * work back matters the same amount while you are looking at capacity as it
 * does on the board. A sixth tab would have made it somewhere to go, and
 * nobody goes anywhere to check whether something happened.
 *
 * **The count is the feature; the list is how you act on it.** Everything here
 * is arranged so the number can be trusted — it is counted over the same answer
 * the rows are drawn from, so the two cannot disagree, and it is worked out on
 * the server from records this person may read right now rather than from
 * anything fanned out to them earlier.
 *
 * Reading is one moment, not a hundred ticks. Opening the list marks everything
 * in it read as of the moment that list was worked out, which is why the answer
 * carries that moment: anything that arrives while somebody has the panel open
 * is still new to them, and marking it with the clock at closing time would
 * quietly swallow it.
 */
export function Signals() {
  const [ list, setList ] = useState< SignalList | undefined >();
  const [ open, setOpen ] = useState( false );
  const [ notice, setNotice ] = useState( '' );
  const panel = useRef< HTMLDivElement | null >( null );

  const load = useCallback( async () => {
    try {
      setList( await api< SignalList >( '/signals' ) );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'What has happened lately could not be read.' ) );
    }
  }, [] );

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [ load ] );

  // Closing on Escape and on a click elsewhere, because this is a panel over
  // the page rather than a screen: somewhere to glance and leave.
  useEffect( () => {
    if ( ! open ) {
      return;
    }

    const away = ( event: MouseEvent ) => {
      if ( panel.current && ! panel.current.contains( event.target as Node ) ) {
        setOpen( false );
      }
    };

    const escape = ( event: KeyboardEvent ) => {
      if ( 'Escape' === event.key ) {
        setOpen( false );
      }
    };

    document.addEventListener( 'mousedown', away );
    document.addEventListener( 'keydown', escape );

    return () => {
      document.removeEventListener( 'mousedown', away );
      document.removeEventListener( 'keydown', escape );
    };
  }, [ open ] );

  const signals = list?.signals ?? [];
  const unread = list?.unread ?? 0;

  async function show() {
    setOpen( true );

    if ( 0 === unread || undefined === list ) {
      return;
    }

    try {
      /*
       * The moment the list was worked out, handed back rather than left to the
       * server to take now. Everything in front of somebody is now read;
       * anything that has arrived since is not, and the server can only know
       * which is which if it is told where the reader got to.
       */
      await api( '/signals/seen', { method: 'POST', body: { at: list.generated } } );
      await load();
    } catch ( failure ) {
      setNotice( messageFor( failure, 'That could not be recorded.' ) );
    }
  }

  return (
    <div className="bwx-signals" ref={ panel }>
      <button
        type="button"
        className="bwx-button"
        data-variant="quiet"
        data-testid="bwx-signals-open"
        aria-expanded={ open }
        aria-label={
          0 === unread
            ? 'What has happened lately'
            : `What has happened lately — ${ unread } new`
        }
        onClick={ () => ( open ? setOpen( false ) : void show() ) }
      >
        Lately
        { 0 < unread && (
          <span className="bwx-signals-count" data-testid="bwx-signals-count">
            { unread }
          </span>
        ) }
      </button>

      { open && (
        <div className="bwx-signals-panel" data-testid="bwx-signals-panel" role="region" aria-label="What has happened lately">
          { '' !== notice && (
            <p className="bwx-notice" role="status" data-testid="bwx-signals-notice">
              { notice }
            </p>
          ) }

          { list?.denied && (
            <p className="bwx-signals-empty">
              You are signed in, but not on any client whose work you may read. Ask for a
              membership, or for cross-client access.
            </p>
          ) }

          { ! list?.denied && 0 === signals.length && (
            <p className="bwx-signals-empty">
              Nothing has happened lately on anything you hold. This is worked out fresh each
              time, so it is genuinely quiet rather than cleared.
            </p>
          ) }

          <ul className="bwx-signals-list">
            { signals.map( ( signal ) => (
              <Row key={ signal.id } signal={ signal } />
            ) ) }
          </ul>
        </div>
      ) }
    </div>
  );
}

/** One thing that happened. */
function Row( { signal }: { signal: Signal } ) {
  return (
    <li
      className="bwx-signals-row"
      data-testid="bwx-signal"
      data-kind={ signal.kind }
      data-action={ signal.action }
      data-subject={ signal.subject_id }
      data-unread={ signal.unread ? 'true' : 'false' }
      data-tone={ signalTone( signal ) }
    >
      <span className="bwx-chip">{ signalWord( signal ) }</span>
      <strong className="bwx-signals-title">{ signal.title }</strong>

      { '' !== signal.detail && (
        <span className="bwx-signals-detail">{ signal.detail }</span>
      ) }

      <span className="bwx-mono bwx-signals-when">{ whenOf( signal.at ) }</span>
    </li>
  );
}
