import { useState } from 'react';
import type { FormEvent } from 'react';
import { ChevronDown, ChevronUp, CloudOff, ListChecks } from 'lucide-react';
import { Button, Card, EmptyState, Field, Modal, Tag, TextArea, useToast } from '../../kit';
import type { Tone } from '../../kit';
import { api, day } from '../api';
import type { ChecklistView, Step } from '../api';
import { useView } from '../useView';

/*
 * Launch readiness (#303): the checklist as the client sees it, in the
 * sections the studio set, with every step's status, whose it is, what is
 * launch-critical, and what the reviewer said. A client fills a step in,
 * attaches what is asked for and sends it back; the studio approves, and
 * nothing here could do that in its place.
 *
 * Every read and write is the client plugin's own — the same routes the
 * wp-admin checklist posts to, with the same two intents: save, or send.
 */

const SECTION_LABEL: Record< string, string > = {
  foundations: 'Foundations',
  'build-reviews': 'Build reviews',
  launch: 'Launch',
};

const STATUS_LABEL: Record< string, string > = {
  'not-started': 'Not started',
  'in-progress': 'In progress',
  submitted: 'With us to check',
  returned: 'Needs another look',
  approved: 'Done',
  'not-applicable': 'Does not apply',
  blocked: 'Held up',
};

const STATUS_TONE: Record< string, Tone > = {
  approved: 'ok',
  returned: 'warn',
  blocked: 'danger',
  submitted: 'info',
};

const NOT_THEIRS_NOW = [ 'submitted', 'approved', 'not-applicable' ];
const FINISHED = [ 'approved', 'not-applicable' ];

function isTheirs( step: Step ): boolean {
  return 'client' === step.owner_side && ! NOT_THEIRS_NOW.includes( step.status );
}

function StepRow( { step }: { step: Step } ) {
  const theirs = isTheirs( step );

  return (
    <li className="fc-step" data-testid="bwx-step" data-status={ step.status } data-theirs={ theirs ? 'true' : undefined }>
      <div className="fc-step-head">
        <span className="fc-step-title">{ step.title }</span>
        { !! step.launch_critical && <Tag tone="brand">Launch-critical</Tag> }
        { !! step.optional && <Tag>Optional</Tag> }
        <span className="fc-step-right">
          <Tag tone={ STATUS_TONE[ step.status ] ?? 'neutral' } dot={ 'approved' === step.status }>
            { STATUS_LABEL[ step.status ] ?? step.status }
          </Tag>
          <span className="fk-mono" data-late={ step.overdue ? 'true' : undefined }>
            { 'client' === step.owner_side ? 'Yours' : 'Ours' }
            { step.due_on ? ` · due ${ day( step.due_on ) }` : '' }
          </span>
        </span>
      </div>

      { step.feedback && (
        <p className="fc-step-feedback" data-testid="bwx-step-feedback">
          { step.feedback }
        </p>
      ) }

      { step.response && (
        <p className="fc-step-response">
          <span className="fc-muted">You said: </span>
          { step.response }
        </p>
      ) }

      { step.evidence.length > 0 && (
        <ul className="fc-step-evidence">
          { step.evidence.map( ( file ) => (
            <li key={ file.id }>
              <Tag tone="ok" dot>
                Attached
              </Tag>
              <span className="fk-mono">{ file.original_name }</span>
            </li>
          ) ) }
        </ul>
      ) }

      { theirs ? (
        <div className="fc-step-actions">
          <a className="fk-btn" data-variant="primary" data-size="sm" href={ `#onboarding/${ step.id }` } data-testid="bwx-step-open">
            { 'returned' === step.status ? 'Have another look' : 'Fill this in' }
          </a>
        </div>
      ) : 'submitted' === step.status ? (
        <p className="fc-muted">Sent to us. We will come back to you — you cannot approve your own step.</p>
      ) : 'client' !== step.owner_side && ! FINISHED.includes( step.status ) ? (
        <p className="fc-muted">This one is ours to do.</p>
      ) : null }
    </li>
  );
}

function Section( { slug, steps, open, onToggle }: { slug: string; steps: Step[]; open: boolean; onToggle: () => void } ) {
  const done = steps.filter( ( s ) => FINISHED.includes( s.status ) ).length;
  const complete = done === steps.length;
  const yours = steps.filter( isTheirs ).length;

  return (
    <Card pad={ 0 } className="fc-section" testId="bwx-section">
      <button type="button" className="fc-section-head" aria-expanded={ open } onClick={ onToggle }>
        <span className="fc-section-name">{ SECTION_LABEL[ slug ] ?? slug }</span>
        { complete ? (
          <Tag tone="ok" dot>
            Complete
          </Tag>
        ) : yours > 0 ? (
          <Tag tone="warn">
            { yours } need{ 1 === yours ? 's' : '' } you
          </Tag>
        ) : (
          <Tag>{ steps.length - done } open</Tag>
        ) }
        <span className="fc-section-right">
          <span className="fc-progress-track fc-progress-track-sm" aria-hidden="true">
            <span style={ { width: `${ steps.length ? ( done / steps.length ) * 100 : 0 }%` } } />
          </span>
          <span className="fk-mono">
            { done }/{ steps.length }
          </span>
          { open ? <ChevronUp size={ 16 } strokeWidth={ 1.5 } aria-hidden="true" /> : <ChevronDown size={ 16 } strokeWidth={ 1.5 } aria-hidden="true" /> }
        </span>
      </button>
      { open && (
        <div className="fc-section-body">
          <ul className="fc-steps">
            { steps.map( ( step ) => (
              <StepRow key={ step.id } step={ step } />
            ) ) }
          </ul>
          <p className="fc-muted fc-section-foot">
            Never enter passwords, recovery codes or API secrets here. Invite our account through the provider instead,
            and tell us which account you invited.
          </p>
        </div>
      ) }
    </Card>
  );
}

function Summary( { checklist }: { checklist: ChecklistView } ) {
  const { progress, steps } = checklist;
  const yours = steps.filter( isTheirs ).length;
  const inReview = steps.filter( ( s ) => 'submitted' === s.status ).length;
  const blocked = steps.filter( ( s ) => 'blocked' === s.status ).length;
  const criticalOpen = steps.filter( ( s ) => s.launch_critical && ! FINISHED.includes( s.status ) ).length;

  return (
    <Card testId="bwx-launch-summary">
      <div className="fc-summary">
        <div className="fc-summary-progress">
          <div className="fc-summary-line">
            <span>Progress counts the important steps we have approved.</span>
            <span className="fk-mono" data-testid="bwx-launch-count">
              { progress.approved }/{ progress.required } approved
            </span>
          </div>
          <div className="fc-progress-track" role="progressbar" aria-valuemin={ 0 } aria-valuemax={ 100 } aria-valuenow={ Math.round( progress.completion ) } aria-label="Launch readiness">
            <span style={ { width: `${ Math.min( 100, Math.max( 0, progress.completion ) ) }%` } } />
          </div>
        </div>
        <dl className="fc-summary-stats">
          <div>
            <dt>Needs you</dt>
            <dd className="fk-mono" data-tone="warn">
              { yours }
            </dd>
          </div>
          <div>
            <dt>In review</dt>
            <dd className="fk-mono" data-tone="info">
              { inReview }
            </dd>
          </div>
          <div>
            <dt>Held up</dt>
            <dd className="fk-mono" data-tone="danger">
              { blocked }
            </dd>
          </div>
          <div>
            <dt>Launch-critical open</dt>
            <dd className="fk-mono" data-tone="danger">
              { criticalOpen }
            </dd>
          </div>
        </dl>
      </div>
      { progress.launch_ready ? (
        <p className="fc-notice" data-tone="ok">
          Every launch-critical step is approved. Going live is now the studio&rsquo;s call.
        </p>
      ) : (
        <p className="fc-notice">
          The site cannot be marked live until every launch-critical step is approved, or marked as not applying by
          the studio.
        </p>
      ) }
    </Card>
  );
}

function StepDialog( { step, onClose, onSaved }: { step: Step; onClose: () => void; onSaved: () => void } ) {
  const [ response, setResponse ] = useState( step.response );
  const [ file, setFile ] = useState< File | null >( null );
  const [ busy, setBusy ] = useState< 'save' | 'submit' | null >( null );
  const [ problem, setProblem ] = useState< string | null >( null );
  const toast = useToast();

  const send = async ( intent: 'save' | 'submit', event?: FormEvent ) => {
    event?.preventDefault();
    setBusy( intent );
    setProblem( null );
    try {
      if ( file ) {
        const form = new FormData();
        form.set( 'evidence', file );
        const attached = await api.attachToStep( step.id, form );
        if ( ! attached.ok ) {
          setProblem( 'That file could not be attached. Nothing was sent.' );
          return;
        }
      }
      const answered = await api.answerStep( step.id, { response: response.trim(), intent } );
      if ( ! answered.ok ) {
        setProblem( 'That could not be sent. The studio did not take it.' );
        return;
      }
      toast( 'submit' === intent ? `Sent to us — “${ step.title }” is with the studio to check` : `Saved — “${ step.title }” is still yours` );
      onSaved();
      onClose();
    } catch {
      setProblem( 'That could not be sent. The studio did not answer.' );
    } finally {
      setBusy( null );
    }
  };

  return (
    <Modal title={ step.title } description="Fill in what the step asks for, attach anything that shows it, and send it back to us. We do the approving." width={ 560 } onClose={ onClose }>
      <form className="fc-say" data-testid="bwx-step-form" onSubmit={ ( e ) => void send( 'submit', e ) }>
        { step.feedback && <p className="fc-step-feedback">{ step.feedback }</p> }

        <Field label="What have you done?">
          { ( id ) => <TextArea id={ id } rows={ 4 } value={ response } onChange={ ( e ) => setResponse( e.target.value ) } placeholder="What you did, or where we can find it" /> }
        </Field>

        <Field label="Attach something (optional)" help={ file ? file.name : 'One file. Never passwords, recovery codes or API keys — invite us through the provider instead.' }>
          { ( id ) => <input id={ id } className="fk-input" type="file" onChange={ ( e ) => setFile( e.target.files?.[ 0 ] ?? null ) } /> }
        </Field>

        { problem && (
          <p className="fc-problem" role="alert">
            { problem }
          </p>
        ) }

        <div className="fk-actions-end">
          <Button variant="secondary" disabled={ null !== busy } onClick={ () => void send( 'save' ) }>
            { 'save' === busy ? 'Saving…' : 'Save' }
          </Button>
          <Button type="submit" disabled={ null !== busy || ( ! response.trim() && ! file ) }>
            { 'submit' === busy ? 'Sending…' : 'Send to us' }
          </Button>
        </div>
      </form>
    </Modal>
  );
}

export function Onboarding( { step: stepId }: { step: string } ) {
  const checklist = useView( api.checklist );
  const [ open, setOpen ] = useState< string | null >( null );

  const sections = checklist.view?.ok ? Object.entries( checklist.view.sections ) : [];

  // Until somebody picks, the first section with something for the client is
  // the one open; '' is "none", chosen.
  const first = sections.find( ( [ , steps ] ) => steps.some( isTheirs ) ) ?? sections[ 0 ];
  const opened = open ?? first?.[ 0 ] ?? '';

  const step = stepId && checklist.view?.ok ? checklist.view.steps.find( ( s ) => s.id === stepId ) : undefined;
  const close = () => {
    window.location.hash = '#onboarding';
  };

  return (
    <div className="fc-stack" data-testid="bwx-onboarding">
      { checklist.loading ? (
        <Card>
          <p className="fc-muted" role="status" aria-busy="true">
            Reading your checklist…
          </p>
        </Card>
      ) : checklist.view?.ok ? (
        0 === checklist.view.steps.length ? (
          <Card>
            <EmptyState icon={ ListChecks } dense title="Nothing here yet" body="There is no checklist on this site yet. We will send one over when your build starts." />
          </Card>
        ) : (
          <>
            <Summary checklist={ checklist.view } />
            <div className="fc-sections">
              { sections.map( ( [ slug, steps ] ) => (
                <Section key={ slug } slug={ slug } steps={ steps } open={ opened === slug } onToggle={ () => setOpen( opened === slug ? '' : slug ) } />
              ) ) }
            </div>
          </>
        )
      ) : (
        <Card>
          <EmptyState
            icon={ CloudOff }
            hue="amber"
            dense
            title="Your checklist could not be read"
            body="The studio did not answer. What you last saw is still on your WordPress dashboard."
            action={
              <Button variant="secondary" size="sm" onClick={ () => checklist.reload( true ) }>
                Try again
              </Button>
            }
          />
        </Card>
      ) }

      { step && isTheirs( step ) && <StepDialog key={ step.id } step={ step } onClose={ close } onSaved={ () => void checklist.reload( true ) } /> }
    </div>
  );
}
