import { useEffect, useRef, useState } from 'react';
import type { ConversionRequest, IntakeState, Submission, WorkItem } from '../types';
import { api, messageFor } from '../api';

/**
 * One request, the studio's answer to it, and the work it becomes (#131, #132).
 *
 * The panel is split down the middle by who wrote what, and it is drawn that
 * way on purpose. Everything above the rule is the client's, shown as they
 * typed it and with no control anywhere near it — what a client asked for is
 * fixed at submission (REQ-1), and a screen with an editable-looking box around
 * their words invites somebody to try. Everything below is the studio's.
 *
 * Conversion is the third block and is deliberately last, because it is the
 * irreversible one. Answering a request can be revised all day; making work out
 * of it happens once, and the panel is ordered so that nobody reaches it
 * without having read what was asked.
 *
 * **Nothing here names a client or a site**, and that is not an omission this
 * component is free to correct. The pipeline converted work lands in is read
 * from the submission on the server, so there is no field in this form — and no
 * field that could be added to it — through which one client's request becomes
 * another client's work (D-40).
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

        <Conversion
          submission={ submission }
          onConverted={ ( converted ) => {
            onAnswered( converted );
            onClose();
          } }
        />
      </aside>
    </div>
  );
}

/**
 * Turning one request into work (#132).
 *
 * Two ways, because two things genuinely happen. Most requests are new work and
 * need a card made; some are the third client to ask for the thing already
 * sitting in Up Next, and linking is the honest answer there rather than a
 * duplicate card somebody closes as a duplicate later.
 *
 * A converted request shows what it became and offers nothing else. That is
 * #134's rule applied to our own screen: a control that would be refused is not
 * drawn at all, because a disabled button that never explains itself is worse
 * than no button.
 *
 * The candidates come from the submission's own site, asked for by the id on
 * the record rather than by anything on screen. The server answers that read
 * through the tenant boundary like any other, so a panel that somehow held the
 * wrong site id would get an empty list rather than somebody else's work.
 */
function Conversion( {
  submission,
  onConverted,
}: {
  submission: Submission;
  onConverted: ( converted: Submission ) => void;
} ) {
  const [ items, setItems ] = useState< WorkItem[] >( [] );
  const [ how, setHow ] = useState< 'new' | 'link' >( 'new' );
  const [ entry, setEntry ] = useState( 'future-idea' );
  const [ title, setTitle ] = useState( submission.title );
  const [ workType, setWorkType ] = useState( 'task' );
  const [ parent, setParent ] = useState( '' );
  const [ parentTitle, setParentTitle ] = useState( '' );
  const [ parentLevel, setParentLevel ] = useState( 'feature' );
  const [ linkTo, setLinkTo ] = useState( '' );
  const [ working, setWorking ] = useState( false );
  const [ notice, setNotice ] = useState( '' );

  const done = '' !== submission.converted_item_id;

  useEffect( () => {
    if ( done ) {
      return;
    }

    void ( async () => {
      try {
        const answer = await api< { items: WorkItem[] } >(
          `/work-items?client_site_id=${ encodeURIComponent( submission.client_site_id ) }`
        );

        setItems( answer.items );
      } catch {
        /*
         * A candidate list that failed to load is not a reason to refuse the
         * conversion. Making work under no parent is ordinary, and the two
         * fields that need this list say so themselves when it is empty.
         */
        setItems( [] );
      }
    } )();
  }, [ done, submission.client_site_id ] );

  if ( done ) {
    return (
      <section className="bwx-answer" data-testid="bwx-request-converted">
        <p className="bwx-eyebrow">This became work</p>
        <p className="bwx-hint">
          { submission.client_name } can see it on their own site, under “What you asked for”.
        </p>
      </section>
    );
  }

  const parents = items.filter( ( one ) => 'sub-feature' !== one.level );
  const asked: ConversionRequest =
    'link' === how
      ? { entry_stage: entry, item_id: linkTo }
      : {
          entry_stage: entry,
          title,
          work_type: workType,
          ...( 'new' === parent
            ? { parent_title: parentTitle, parent_level: parentLevel }
            : { parent_id: parent } ),
        };

  const ready = 'link' === how ? '' !== linkTo : '' !== title.trim() && ( 'new' !== parent || '' !== parentTitle.trim() );

  async function convert() {
    setWorking( true );
    setNotice( '' );

    try {
      const answer = await api< { submission: Submission } >(
        `/submissions/${ submission.id }/conversion`,
        { method: 'POST', body: asked }
      );

      onConverted( answer.submission );
    } catch ( error ) {
      /*
       * The server's own sentence. Every refusal this route makes has one
       * written for a person — "a parent has to be a higher level than the
       * work beneath it" — and the panel stays open with the form as it was so
       * whoever read it can act on it.
       */
      setNotice( messageFor( error, 'That could not be turned into work.' ) );
      setWorking( false );
    }
  }

  return (
    <section className="bwx-answer" data-testid="bwx-request-convert">
      <p className="bwx-eyebrow">Make this work</p>

      <div className="bwx-field">
        <label htmlFor="bwx-convert-how">What to do</label>
        <select
          id="bwx-convert-how"
          className="bwx-select"
          data-testid="bwx-convert-how"
          value={ how }
          onChange={ ( event ) => setHow( 'link' === event.target.value ? 'link' : 'new' ) }
        >
          <option value="new">Make new work for this</option>
          <option value="link">Link it to work that already exists</option>
        </select>
      </div>

      { 'link' === how && (
        <div className="bwx-field">
          <label htmlFor="bwx-convert-link">The work this answers</label>
          <select
            id="bwx-convert-link"
            className="bwx-select"
            data-testid="bwx-convert-link"
            value={ linkTo }
            onChange={ ( event ) => setLinkTo( event.target.value ) }
          >
            <option value="">Choose…</option>
            { items.map( ( one ) => (
              <option key={ one.id } value={ one.id }>
                { one.title } — { one.stage_label }
              </option>
            ) ) }
          </select>
          <p className="bwx-hint">
            { 0 === items.length
              ? `${ submission.client_name } has no work on this site to link to yet.`
              : 'The work keeps the stage it is already at. Nothing moves.' }
          </p>
        </div>
      ) }

      { 'new' === how && (
        <>
          <div className="bwx-field">
            <label htmlFor="bwx-convert-title">Title</label>
            <input
              id="bwx-convert-title"
              className="bwx-input"
              data-testid="bwx-convert-title"
              value={ title }
              onChange={ ( event ) => setTitle( event.target.value ) }
            />
            <p className="bwx-hint">
              What was asked stays as it was written. This is what the card is called.
            </p>
          </div>

          <div className="bwx-field">
            <label htmlFor="bwx-convert-type">Kind of work</label>
            <select
              id="bwx-convert-type"
              className="bwx-select"
              data-testid="bwx-convert-type"
              value={ workType }
              onChange={ ( event ) => setWorkType( event.target.value ) }
            >
              <option value="task">Task</option>
              <option value="feature">Feature</option>
              <option value="bug">Bug</option>
              <option value="feedback">Feedback</option>
            </select>
          </div>

          <div className="bwx-field">
            <label htmlFor="bwx-convert-parent">Sits under</label>
            <select
              id="bwx-convert-parent"
              className="bwx-select"
              data-testid="bwx-convert-parent"
              value={ parent }
              onChange={ ( event ) => setParent( event.target.value ) }
            >
              <option value="">Nothing — it stands alone</option>
              { parents.map( ( one ) => (
                <option key={ one.id } value={ one.id }>
                  { one.title } ({ one.level_label })
                </option>
              ) ) }
              <option value="new">A new parent…</option>
            </select>
          </div>

          { 'new' === parent && (
            <>
              <div className="bwx-field">
                <label htmlFor="bwx-convert-parent-title">New parent&rsquo;s title</label>
                <input
                  id="bwx-convert-parent-title"
                  className="bwx-input"
                  data-testid="bwx-convert-parent-title"
                  value={ parentTitle }
                  onChange={ ( event ) => setParentTitle( event.target.value ) }
                />
              </div>

              <div className="bwx-field">
                <label htmlFor="bwx-convert-parent-level">New parent&rsquo;s level</label>
                <select
                  id="bwx-convert-parent-level"
                  className="bwx-select"
                  data-testid="bwx-convert-parent-level"
                  value={ parentLevel }
                  onChange={ ( event ) => setParentLevel( event.target.value ) }
                >
                  <option value="project">Project</option>
                  <option value="milestone">Milestone</option>
                  <option value="feature">Feature</option>
                </select>
              </div>
            </>
          ) }

          <div className="bwx-field">
            <label htmlFor="bwx-convert-entry">Enters at</label>
            <select
              id="bwx-convert-entry"
              className="bwx-select"
              data-testid="bwx-convert-entry"
              value={ entry }
              onChange={ ( event ) => setEntry( event.target.value ) }
            >
              <option value="future-idea">Future Idea</option>
              <option value="triage">Triage</option>
            </select>
            <p className="bwx-hint">
              Triage records what this conversion already decided — the site, where it came
              from, and that it has been put forward — against the person doing it.
            </p>
          </div>
        </>
      ) }

      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-convert-notice" role="status">
          { notice }
        </p>
      ) }

      <div className="bwx-moves">
        <button
          type="button"
          className="bwx-button"
          data-testid="bwx-convert-save"
          disabled={ working || ! ready }
          onClick={ () => void convert() }
        >
          { working ? 'Making…' : 'Turn into work' }
        </button>
      </div>
    </section>
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
