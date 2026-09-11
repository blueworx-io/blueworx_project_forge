import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { CloudOff, Inbox, RefreshCw } from 'lucide-react';
import { Button, Card, DataView, EmptyState, Field, StageChip, Tag, TextArea, TextInput, useToast } from '../../kit';
import type { Column, SavedView, Tone } from '../../kit';
import { api, dayOf } from '../api';
import type { Submission, SubmissionsView } from '../api';
import { useView } from '../useView';

/*
 * Bugs, requests, ideas and suggestions (#301): the form that sends one, and
 * the list of what this organisation has sent, with the studio's reply and
 * the work it became. Both are the client plugin's own reads and writes — the
 * same route the wp-admin Requests screen posts to, with the same fields.
 *
 * The studio decides what a submission becomes. Nothing here can change a
 * state, and the list shows the five states the studio has, in its words.
 */

const TYPES: Array< { id: string; label: string; hint: string } > = [
  { id: 'bug', label: 'Bug', hint: 'Something that used to work, or does not do what it should.' },
  { id: 'request', label: 'Request', hint: 'Something you would like us to do.' },
  { id: 'idea', label: 'Idea', hint: 'A thought worth considering, with no expectation attached.' },
  { id: 'suggestion', label: 'Suggestion', hint: 'Something you think could be better than it is.' },
];

const STATE_TONE: Record< string, Tone > = {
  received: 'info',
  'in-review': 'info',
  accepted: 'ok',
  converted: 'ok',
  declined: 'neutral',
};

function typeLabel( type: string ): string {
  return TYPES.find( ( t ) => t.id === type )?.label ?? type;
}

function NewSubmission( { onSent }: { onSent: () => void } ) {
  const [ type, setType ] = useState( 'bug' );
  const [ title, setTitle ] = useState( '' );
  const [ description, setDescription ] = useState( '' );
  const [ outcome, setOutcome ] = useState( '' );
  const [ evidence, setEvidence ] = useState( '' );
  const [ screenshot, setScreenshot ] = useState< File | null >( null );
  const [ sending, setSending ] = useState( false );
  const [ problem, setProblem ] = useState< string | null >( null );
  const [ fields, setFields ] = useState< Record< string, string > >( {} );
  const toast = useToast();

  const isBug = 'bug' === type;
  const ready = title.trim() && description.trim() && ( isBug || outcome.trim() );

  const reset = () => {
    setTitle( '' );
    setDescription( '' );
    setOutcome( '' );
    setEvidence( '' );
    setScreenshot( null );
    setFields( {} );
    setProblem( null );
  };

  const send = async ( event: FormEvent ) => {
    event.preventDefault();
    if ( ! ready ) return;

    const form = new FormData();
    form.set( 'type', type );
    form.set( 'title', title.trim() );
    form.set( 'description', description.trim() );
    form.set( 'desired_outcome', outcome.trim() );
    form.set( 'evidence', evidence.trim() );
    if ( screenshot ) form.set( 'screenshot', screenshot );

    setSending( true );
    setProblem( null );
    try {
      const answer = await api.submit( form );
      if ( ! answer.ok ) {
        setFields( answer.fields ?? {} );
        setProblem( 'invalid' === answer.result ? 'Something is missing — see the fields marked below.' : 'That could not be sent. The studio did not take it.' );
        return;
      }
      reset();
      toast( `${ typeLabel( type ) } sent — the studio has it` );
      onSent();
    } catch {
      setProblem( 'That could not be sent. The studio did not answer.' );
    } finally {
      setSending( false );
    }
  };

  return (
    <Card testId="bwx-new-submission">
      <form className="fc-say" onSubmit={ send }>
        <h3 className="fc-h3">New submission</h3>

        <fieldset className="fc-types">
          <legend className="fk-field-label">What is this?</legend>
          <div className="fc-type-pills" role="radiogroup" aria-label="What is this?">
            { TYPES.map( ( t ) => (
              <button
                key={ t.id }
                type="button"
                role="radio"
                aria-checked={ t.id === type }
                className="fc-type-pill"
                title={ t.hint }
                onClick={ () => {
                  setType( t.id );
                  setFields( {} );
                } }
              >
                { t.label }
              </button>
            ) ) }
          </div>
          <span className="fk-field-help">{ TYPES.find( ( t ) => t.id === type )?.hint }</span>
        </fieldset>

        <Field label={ isBug ? 'What is going wrong' : 'Title' } required help={ fields.title }>
          { ( id ) => (
            <TextInput id={ id } value={ title } onChange={ ( e ) => setTitle( e.target.value ) } placeholder={ isBug ? 'One line — the visible symptom' : 'A short summary' } aria-invalid={ fields.title ? true : undefined } />
          ) }
        </Field>

        <Field
          label={ isBug ? 'What happens, and what should happen' : 'Problem or need' }
          required
          help={ fields.description ?? ( isBug ? 'Numbered steps to reproduce it let us confirm it without coming back to you.' : undefined ) }
        >
          { ( id ) => (
            <TextArea
              id={ id }
              rows={ isBug ? 5 : 3 }
              value={ description }
              onChange={ ( e ) => setDescription( e.target.value ) }
              placeholder={ isBug ? '1. Sign in as a member\n2. Open the downloads page\n3. …' : 'What is not working, or what is missing' }
              aria-invalid={ fields.description ? true : undefined }
            />
          ) }
        </Field>

        <Field label={ isBug ? 'Expected result' : 'Desired outcome' } required={ ! isBug } help={ fields.desired_outcome }>
          { ( id ) => (
            <TextArea id={ id } rows={ 2 } value={ outcome } onChange={ ( e ) => setOutcome( e.target.value ) } placeholder={ isBug ? 'What should happen instead' : 'What good looks like for you' } aria-invalid={ fields.desired_outcome ? true : undefined } />
          ) }
        </Field>

        <Field label="Supporting evidence" help="Links, error text, anything that helps. Never passwords or API secrets.">
          { ( id ) => <TextArea id={ id } rows={ 2 } value={ evidence } onChange={ ( e ) => setEvidence( e.target.value ) } /> }
        </Field>

        <Field label="Screenshot" help={ screenshot ? screenshot.name : 'One image, optional.' }>
          { ( id ) => (
            <input
              id={ id }
              className="fk-input"
              type="file"
              accept="image/*"
              onChange={ ( e ) => setScreenshot( e.target.files?.[ 0 ] ?? null ) }
            />
          ) }
        </Field>

        { problem && (
          <p className="fc-problem" role="alert">
            { problem }
          </p>
        ) }

        <div className="fc-submit-row">
          <Button type="submit" disabled={ sending || ! ready }>
            { sending ? 'Sending…' : isBug ? 'Report bug' : `Submit ${ typeLabel( type ).toLowerCase() }` }
          </Button>
          { ! ready && <span className="fc-muted">{ isBug ? 'Symptom and what happens are required.' : 'Title, problem and desired outcome are required.' }</span> }
        </div>
      </form>
    </Card>
  );
}

interface Row extends Record< string, unknown > {
  id: string;
  submission: Submission;
}

function Submissions( { view, onRefresh }: { view: SubmissionsView; onRefresh: () => void } ) {
  const [ active, setActive ] = useState( 'all' );
  const [ search, setSearch ] = useState( '' );

  const views: SavedView[] = useMemo(
    () => [
      { id: 'all', label: 'All', count: view.submissions.length },
      ...view.states.map( ( state ) => ( {
        id: state.slug,
        label: state.label,
        count: view.submissions.filter( ( s ) => s.intake_state === state.slug ).length,
      } ) ),
    ],
    [ view ]
  );

  const rows: Row[] = useMemo( () => {
    const needle = search.trim().toLowerCase();
    return view.submissions
      .filter( ( s ) => 'all' === active || s.intake_state === active )
      .filter( ( s ) => ! needle || `${ s.id } ${ s.title } ${ s.description } ${ s.response }`.toLowerCase().includes( needle ) )
      .map( ( s ) => ( { id: s.id, submission: s } ) );
  }, [ view, active, search ] );

  const columns: Column< Row >[] = [
    {
      key: 'what',
      label: 'Submission',
      wrap: true,
      sortBy: ( row ) => row.submission.title,
      render: ( row ) => {
        const s = row.submission;
        const converted = 'id' in s.converted ? s.converted : null;
        return (
          <div className="fc-submission" data-testid="bwx-submission" data-state={ s.intake_state }>
            <div className="fc-submission-head">
              <span className="fk-mono">{ s.id }</span>
              <Tag>{ typeLabel( s.type ) }</Tag>
              <strong>{ s.title }</strong>
            </div>
            { s.response && <p className="fc-submission-reply">{ s.response }</p> }
            <div className="fc-submission-meta">
              { s.submitted_by && <span>Submitted by { s.submitted_by }</span> }
              { converted && (
                <span>
                  Became{ ' ' }
                  <a href={ `#board/${ converted.id }` } className="fc-link" data-testid="bwx-submission-converted">
                    { converted.title }
                  </a>{ ' ' }
                  <StageChip stage={ converted.stage } dense short />
                </span>
              ) }
            </div>
          </div>
        );
      },
    },
    {
      key: 'state',
      label: 'Status',
      width: 140,
      sortBy: ( row ) => row.submission.intake_label,
      render: ( row ) => (
        <Tag tone={ STATE_TONE[ row.submission.intake_state ] ?? 'neutral' } dot>
          { row.submission.intake_label }
        </Tag>
      ),
    },
    {
      key: 'when',
      label: 'Sent',
      mono: true,
      width: 130,
      sortBy: ( row ) => row.submission.created_at,
      render: ( row ) => dayOf( row.submission.created_at ),
    },
  ];

  return (
    <Card pad={ 0 } testId="bwx-submissions">
      <DataView
        title="Your submissions"
        titleRight={
          <>
            <span className="fc-muted">Only your organisation’s submissions are visible</span>
            <Button variant="ghost" size="sm" onClick={ onRefresh }>
              <RefreshCw size={ 14 } strokeWidth={ 1.5 } aria-hidden="true" /> Check again
            </Button>
          </>
        }
        views={ views }
        activeView={ active }
        onViewChange={ setActive }
        search={ search }
        onSearch={ setSearch }
        searchPlaceholder="Search submissions"
        columns={ columns }
        rows={ rows }
        defaultSort={ { key: 'when', dir: 'desc' } }
        empty={
          <EmptyState icon={ Inbox } dense title="Nothing sent yet" body="What you send from the form appears here, with the studio's reply." />
        }
        footer={ `${ rows.length } of ${ view.submissions.length } shown` }
      />
    </Card>
  );
}

export function Requests() {
  const submissions = useView( api.submissions );

  return (
    <div className="fc-stack" data-testid="bwx-requests">
      <div className="fc-two-up fc-two-up-form">
        <NewSubmission onSent={ () => void submissions.reload( true ) } />

        <div className="fc-stack">
          { submissions.loading ? (
            <Card>
              <p className="fc-muted" role="status" aria-busy="true">
                Reading your submissions…
              </p>
            </Card>
          ) : submissions.view?.ok ? (
            <Submissions view={ submissions.view } onRefresh={ () => void submissions.reload( true ) } />
          ) : (
            <Card>
              <EmptyState
                icon={ CloudOff }
                hue="amber"
                dense
                title="Your submissions could not be read"
                body="The studio did not answer. What you last saw is still on your WordPress dashboard."
                action={
                  <Button variant="secondary" size="sm" onClick={ () => submissions.reload( true ) }>
                    Try again
                  </Button>
                }
              />
            </Card>
          ) }

          <Card tone="sunken">
            <h3 className="fc-h3">What happens next</h3>
            <p className="fc-body">
              We read it, then either accept it, decline it, or come back with a question. Anything we accept becomes a
              task under a project, feature or milestone — and what you sent stays exactly as you wrote it. If your hours
              have run out, accepted work can still start but cannot be scheduled until there are hours to cover it.
              Confirmed bugs go to Bug Tracking first, where we pin down how to repeat them before anything is built.
            </p>
          </Card>
        </div>
      </div>
    </div>
  );
}
