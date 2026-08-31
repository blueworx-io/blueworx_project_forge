import { useState } from 'react';
import type { OnboardingSite, OnboardingStep } from '../types';
import { api, messageFor } from '../api';
import { sideWord, statusWord } from '../onboarding';

/**
 * What is behind one client's row on the onboarding board (#165), and where a
 * step is decided (#163).
 *
 * The decisions themselves shipped with #163 and have had nowhere to be made
 * from until now: the rules, the history entry and the client's feedback were
 * all in place, and no screen in the studio could reach them. This is that
 * screen. It sends the decision and re-reads the board rather than working out
 * what changed — a decision moves completion, launch readiness and three counts
 * at once, and a panel that guessed at any of them would start disagreeing with
 * the row behind it.
 *
 * **Sending back and waiving both ask for a reason, and the field says which
 * kind.** The client reads the first one, and nobody reads the second until a
 * year later when they are trying to work out why a step was skipped. Both are
 * refused without one by the server; asking here as well means somebody finds
 * out before they have written anything else.
 *
 * A person without the review capability sees the steps and no buttons. The
 * route asks the same question again before it saves, so this hides a control
 * rather than being the check.
 */

/** The three things somebody can decide about a step. */
type Decision = 'approve' | 'return' | 'not-applicable';

const DECIDING: Record< Decision, string > = {
  approve: 'Approving…',
  return: 'Sending back…',
  'not-applicable': 'Saving…',
};

export function OnboardingPanel( {
  site,
  onClose,
  onDecided,
}: {
  site: OnboardingSite;
  onClose: () => void;
  onDecided: ( message: string ) => void;
} ) {
  const [ decidingId, setDecidingId ] = useState( '' );
  const [ busy, setBusy ] = useState( '' );
  const [ failure, setFailure ] = useState( '' );

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
        aria-label="Onboarding"
        data-testid="bwx-onboarding-panel"
        onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
      >
        <div className="bwx-panel-head">
          <div>
            <span className="bwx-eyebrow">{ site.site_name }</span>
            <h2 className="bwx-wordmark">{ site.client_name }</h2>
          </div>

          <span className="bwx-header-spacer" />

          <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
            Close
          </button>
        </div>

        <p className="bwx-onboarding-position" data-launch={ site.launch_ready ? 'ready' : 'not-ready' }>
          { site.approved } of { site.required } steps approved, { site.completion }% through.
          { site.launch_ready
            ? ' Everything that has to be done before launch is done.'
            : ` ${ blockingSentence( site ) }` }
        </p>

        { 0 < site.blocking.length && (
          <div className="bwx-field">
            <label>Standing between this site and going live</label>
            <ul className="bwx-history" data-testid="bwx-onboarding-blocking">
              { site.blocking.map( ( one ) => (
                <li key={ one.id }>{ one.title }</li>
              ) ) }
            </ul>
          </div>
        ) }

        { '' !== failure && (
          <p className="bwx-notice" data-tone="alert" role="alert" data-testid="bwx-onboarding-failure">
            { failure }
          </p>
        ) }

        <div className="bwx-field">
          <label>The checklist</label>

          { /*
             Two different nothings. A checklist with no steps at all is a
             checklist somebody published empty, and telling that person to
             clear their filters would send them looking for a problem that is
             not there.
           */ }
          { 0 === site.steps.length ? (
            <p className="bwx-list-empty" data-testid="bwx-onboarding-no-steps">
              { 0 === site.total
                ? 'This checklist has no steps on it. Nothing was ever added to the version this site was given.'
                : 'No steps match the filters. Clear them to see the whole checklist.' }
            </p>
          ) : (
            <ul className="bwx-onboarding-steps" data-testid="bwx-onboarding-steps">
              { site.steps.map( ( step ) => (
                <li key={ step.id } data-testid="bwx-onboarding-step" data-step={ step.id } data-status={ step.status }>
                  <div className="bwx-onboarding-step-head">
                    <strong>{ step.title }</strong>
                    <span className="bwx-chip" data-status={ step.status }>
                      { statusWord( step.status ) }
                    </span>
                  </div>

                  <span className="bwx-card-meta">
                    <span className="bwx-eyebrow">{ step.section }</span>
                    <span className="bwx-mono">{ sideWord( step.owner_side ) }</span>
                    { '' !== step.due_on && (
                      <span className="bwx-mono" data-overdue={ step.overdue ? 'yes' : undefined }>
                        due { step.due_on }
                        { step.overdue ? ' — overdue' : '' }
                      </span>
                    ) }
                    { 0 < step.launch_critical && <span className="bwx-chip" data-critical="yes">Before launch</span> }
                  </span>

                  { '' !== step.response && <p className="bwx-onboarding-answer">{ step.response }</p> }

                  { site.may_review && (
                    <Decide
                      step={ step }
                      busy={ busy }
                      open={ decidingId === step.id }
                      onOpen={ ( wanted ) => {
                        setFailure( '' );
                        setDecidingId( wanted ? step.id : '' );
                      } }
                      onDecide={ async ( decision, reason ) => {
                        setBusy( DECIDING[ decision ] );
                        setFailure( '' );

                        try {
                          await api( `/onboarding/steps/${ step.id }/review`, {
                            method: 'POST',
                            body: { decision, reason },
                          } );

                          setDecidingId( '' );
                          onDecided( decided( decision, step, site ) );
                        } catch ( error ) {
                          setFailure( messageFor( error, 'That could not be saved.' ) );
                        } finally {
                          setBusy( '' );
                        }
                      } }
                    />
                  ) }
                </li>
              ) ) }
            </ul>
          ) }
        </div>
      </aside>
    </div>
  );
}

/** What is left, said as a sentence rather than as a number. */
function blockingSentence( site: OnboardingSite ): string {
  if ( 0 === site.blocking.length ) {
    return 'Nothing on this checklist is marked as needed before launch, so nobody has said what ready would mean.';
  }

  return 1 === site.blocking.length
    ? 'One thing has to happen before it can go live.'
    : `${ site.blocking.length } things have to happen before it can go live.`;
}

/** What to tell somebody they just did. */
function decided( decision: Decision, step: OnboardingStep, site: OnboardingSite ): string {
  if ( 'approve' === decision ) {
    return `Approved “${ step.title }”.`;
  }

  if ( 'return' === decision ) {
    return `Sent “${ step.title }” back. ${ site.client_name } can see why on their own site.`;
  }

  return `Marked “${ step.title }” as not applicable.`;
}

/**
 * The decisions available on one step.
 *
 * Approve is a button because it needs nothing else. The other two open a
 * reason first, because both are refused without one and finding that out after
 * clicking is a worse way to be told.
 */
function Decide( {
  step,
  busy,
  open,
  onOpen,
  onDecide,
}: {
  step: OnboardingStep;
  busy: string;
  open: boolean;
  onOpen: ( wanted: boolean ) => void;
  onDecide: ( decision: Decision, reason: string ) => void;
} ) {
  const [ reason, setReason ] = useState( '' );
  const [ intent, setIntent ] = useState< Decision >( 'return' );

  const submitted = 'submitted' === step.status;
  const settled = 'approved' === step.status || 'not-applicable' === step.status;
  const waivable = 0 < step.allows_not_applicable && ! settled;

  if ( ! submitted && ! waivable ) {
    return null;
  }

  if ( ! open ) {
    return (
      <div className="bwx-moves">
        { submitted && (
          <button
            type="button"
            className="bwx-button"
            data-testid="bwx-onboarding-approve"
            disabled={ '' !== busy }
            onClick={ () => onDecide( 'approve', '' ) }
          >
            { '' === busy ? 'Approve' : busy }
          </button>
        ) }

        { submitted && (
          <button
            type="button"
            className="bwx-button"
            data-variant="quiet"
            data-testid="bwx-onboarding-return"
            disabled={ '' !== busy }
            onClick={ () => {
              setIntent( 'return' );
              onOpen( true );
            } }
          >
            Send back
          </button>
        ) }

        { waivable && (
          <button
            type="button"
            className="bwx-button"
            data-variant="quiet"
            data-testid="bwx-onboarding-waive"
            disabled={ '' !== busy }
            onClick={ () => {
              setIntent( 'not-applicable' );
              onOpen( true );
            } }
          >
            Does not apply
          </button>
        ) }
      </div>
    );
  }

  return (
    <div className="bwx-field">
      <label htmlFor={ `reason-${ step.id }` }>
        { 'return' === intent
          ? 'What needs changing? The client reads this.'
          : 'Why does this one not apply? Nobody will remember a year from now.' }
      </label>

      <textarea
        id={ `reason-${ step.id }` }
        className="bwx-textarea"
        data-testid="bwx-onboarding-reason"
        rows={ 3 }
        value={ reason }
        onChange={ ( event ) => setReason( event.target.value ) }
      />

      <div className="bwx-moves">
        <button
          type="button"
          className="bwx-button"
          data-testid="bwx-onboarding-confirm"
          disabled={ '' === reason.trim() || '' !== busy }
          onClick={ () => onDecide( intent, reason ) }
        >
          { '' === busy ? ( 'return' === intent ? 'Send it back' : 'Mark not applicable' ) : busy }
        </button>

        <button
          type="button"
          className="bwx-button"
          data-variant="quiet"
          onClick={ () => {
            setReason( '' );
            onOpen( false );
          } }
        >
          Cancel
        </button>
      </div>
    </div>
  );
}
