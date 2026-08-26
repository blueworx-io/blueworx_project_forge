import { useEffect, useRef, useState } from 'react';
import type { IntakeState, Submission } from '../types';
import { api, messageFor } from '../api';

/**
 * One request, and the studio's answer to it (#131).
 *
 * The panel is split down the middle by who wrote what, and it is drawn that
 * way on purpose. Everything above the rule is the client's, shown as they
 * typed it and with no control anywhere near it — what a client asked for is
 * fixed at submission (REQ-1), and a screen with an editable-looking box around
 * their words invites somebody to try. Everything below is the studio's, and is
 * the only thing this panel can save.
 *
 * Turning a request into real work is #132 and is deliberately not here yet.
 * Accepting something is a decision; creating the work is a separate act with
 * its own rules about which client's pipeline it may enter.
 */
export function RequestPanel( {
  submission,
  states,
  onClose,
  onAnswered,
}: {
  submission: Submission;
  states: IntakeState[];
  onClose: () => void;
  onAnswered: ( answered: Submission ) => void;
} ) {
  const [ state, setState ] = useState( submission.intake_state );
  const [ response, setResponse ] = useState( submission.response );
  const [ saving, setSaving ] = useState( false );
  const [ notice, setNotice ] = useState( '' );
  const closer = useRef< HTMLButtonElement >( null );

  useEffect( () => {
    closer.current?.focus();
  }, [] );

  const changed = state !== submission.intake_state || response !== submission.response;

  async function save() {
    setSaving( true );
    setNotice( '' );

    try {
      const answer = await api< { submission: Submission } >( `/submissions/${ submission.id }`, {
        method: 'PATCH',
        body: { intake_state: state, response },
      } );

      onAnswered( answer.submission );
      onClose();
    } catch ( error ) {
      /*
       * The panel stays open and keeps what was typed. A studio reply is often
       * a considered paragraph, and losing it to a dropped connection is the
       * same mistake #129 already refused to make on the client's side.
       */
      setNotice( messageFor( error, 'That could not be saved.' ) );
      setSaving( false );
    }
  }

  return (
    <div
      className="bwx-panel-scrim"
      onClick={ ( event ) => {
        if ( event.target === event.currentTarget ) {
          onClose();
        }
      } }
    >
      <aside
        className="bwx-panel"
        role="dialog"
        aria-modal="true"
        aria-label="Request"
        data-testid="bwx-request-panel"
        onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
      >
        <header className="bwx-panel-head">
          <div style={ { flex: 1 } }>
            <p className="bwx-eyebrow" data-testid="bwx-request-client">
              { submission.client_name } · { submission.intake_label }
            </p>
            <h2 style={ { margin: '4px 0 0', fontSize: 'var(--text-subheading)', fontWeight: 500 } }>
              { submission.title }
            </h2>
          </div>
          <button
            type="button"
            className="bwx-icon-button"
            ref={ closer }
            onClick={ onClose }
            aria-label="Close"
          >
            ✕
          </button>
        </header>

        <section className="bwx-said" data-testid="bwx-request-said">
          <p className="bwx-eyebrow">What they asked for</p>
          <p className="bwx-said-body" data-testid="bwx-request-description">
            { submission.description }
          </p>

          { '' !== submission.desired_outcome && (
            <>
              <p className="bwx-eyebrow">What good would look like</p>
              <p className="bwx-said-body">{ submission.desired_outcome }</p>
            </>
          ) }

          { '' !== submission.evidence && (
            <>
              <p className="bwx-eyebrow">Anything that helps</p>
              <p className="bwx-said-body">{ submission.evidence }</p>
            </>
          ) }

          <p className="bwx-mono bwx-said-by">
            Sent by { submission.submitted_by } · { sent( submission.created_at ) }
          </p>
        </section>

        <section className="bwx-answer">
          <p className="bwx-eyebrow">Your answer</p>

          <div className="bwx-field">
            <label htmlFor="bwx-request-state">
              Where it has got to
            </label>
            <select
              id="bwx-request-state"
              className="bwx-select"
              data-testid="bwx-request-state"
              value={ state }
              onChange={ ( event ) => setState( event.target.value ) }
            >
              { states.map( ( one ) => (
                <option key={ one.slug } value={ one.slug }>
                  { one.label }
                </option>
              ) ) }
            </select>
          </div>

          <div className="bwx-field">
            <label htmlFor="bwx-request-response">
              Reply
            </label>
            <textarea
              id="bwx-request-response"
              className="bwx-input"
              data-testid="bwx-request-response"
              rows={ 5 }
              value={ response }
              onChange={ ( event ) => setResponse( event.target.value ) }
            />
            <p className="bwx-hint">
              { submission.client_name } reads this on their own site, under “What you asked for”.
            </p>
          </div>

          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-request-notice" role="status">
              { notice }
            </p>
          ) }

          <div className="bwx-moves">
            <button
              type="button"
              className="bwx-button"
              data-testid="bwx-request-save"
              disabled={ saving || ! changed }
              onClick={ () => void save() }
            >
              { saving ? 'Saving…' : 'Save answer' }
            </button>
            <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
              Cancel
            </button>
          </div>
        </section>
      </aside>
    </div>
  );
}

/** When it arrived, as a date rather than a timestamp. */
function sent( at: number ): string {
  return new Date( at * 1000 ).toLocaleDateString( undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  } );
}
