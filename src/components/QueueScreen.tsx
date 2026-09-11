import { useEffect, useState } from 'react';
import type { IntakeState, QueueFilters, Submission } from '../types';
import { api, isDenied, messageFor } from '../api';
import { BulkButton, Check, Modal, ReasonAction, ViewPill } from '../kit';
import { RequestPanel } from './RequestPanel';
import { Screen } from './States';

/**
 * The studio's request review queue (#131).
 *
 * The one studio screen that spans clients on purpose. Everything else is
 * scoped to a site, because work is (ARCH-3) — but a request has not become
 * work yet, and triage is the job of looking across all of them at once and
 * deciding what matters next. That is why this is a screen of its own rather
 * than a fifth view of the board: it shares none of the board's site scope, its
 * filter set or its item panel, and folding it in would have put a site picker
 * and six inert work filters above a list they mean nothing to.
 *
 * Newest first, as the server returns them, and every row says how long it has
 * been waiting. The wait is the column that matters on a triage screen — a
 * request nobody has answered for three weeks needs to be legible as that, not
 * inferred by comparing dates.
 */
export function QueueScreen() {
  const [ submissions, setSubmissions ] = useState< Submission[] >( [] );
  const [ states, setStates ] = useState< IntakeState[] >( [] );
  const [ filters, setFilters ] = useState< QueueFilters >( {} );
  const [ openId, setOpenId ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ queue, setQueue ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );

  /*
   * Row selection and the bulk bar (#308). A bulk decline is the single
   * decline done several times: the same route, the same reason recorded on
   * each submission verbatim, one at a time — so a refusal on one leaves the
   * others as they were and says which one it was.
   */
  const [ picked, setPicked ] = useState< string[] >( [] );
  const [ deciding, setDeciding ] = useState< 'declined' | null >( null );
  const [ busy, setBusy ] = useState( false );

  async function decideAll( intake_state: 'declined' | 'in-review', response: string ) {
    setBusy( true );
    const done: Submission[] = [];
    let refused = '';
    for ( const id of picked ) {
      try {
        const answer = await api< { submission: Submission } >( `/submissions/${ id }`, {
          method: 'PATCH',
          body: { intake_state, response },
        } );
        done.push( answer.submission );
      } catch ( error ) {
        refused = messageFor( error, 'That could not be saved.' );
        break;
      }
    }
    setSubmissions( ( all ) => all.map( ( one ) => done.find( ( d ) => d.id === one.id ) ?? one ) );
    setPicked( picked.filter( ( id ) => ! done.some( ( d ) => d.id === id ) ) );
    setDeciding( null );
    setBusy( false );
    setNotice(
      '' === refused
        ? `${ done.length } ${ 1 === done.length ? 'request' : 'requests' } ${ 'declined' === intake_state ? 'declined' : 'marked as being looked at' }, each with the reason recorded.`
        : `${ done.length } saved, then stopped: ${ refused }`
    );
  }

  /*
   * The whole queue is read once and filtered here, unlike the board, which
   * sends its filters to the server.
   *
   * That is a second implementation of the same filter set, which this codebase
   * is otherwise strict about not having (#123) — so it is worth saying why it
   * is allowed here and what would make it wrong. #123's rule exists because
   * four views of one list, each filtering for itself, is how two views come to
   * show different totals for the same filters. There is one view here. The
   * duplication that matters is between views, not between a view and the API,
   * and the API keeps the filter set because callers other than this screen use
   * it. The day there is a second view of this queue, both go back to the
   * server, as the board's do.
   *
   * What is emphatically NOT duplicated is the scoping. The server filters by
   * reach before it filters by anything else, so what is in `submissions` is
   * already only what this person may see. Nothing here narrows for permission,
   * only for attention — and if this code were deleted the screen would show too
   * much, never somebody else's client.
   */
  const search = ( filters.search ?? '' ).toLowerCase();

  const shown = submissions.filter( ( one ) => {
    if ( filters.client_id?.length && ! filters.client_id.includes( one.client_id ) ) {
      return false;
    }

    if ( filters.intake_state?.length && ! filters.intake_state.includes( one.intake_state ) ) {
      return false;
    }

    if ( filters.type?.length && ! filters.type.includes( one.type ) ) {
      return false;
    }

    return (
      '' === search ||
      one.title.toLowerCase().includes( search ) ||
      one.description.toLowerCase().includes( search )
    );
  } );

  async function load() {
    try {
      const answer = await api< {
        denied?: boolean;
        submissions: Submission[];
        states: IntakeState[];
      } >( '/submissions' );

      if ( answer.denied ) {
        setQueue( 'denied' );
        return;
      }

      setSubmissions( answer.submissions );
      setStates( answer.states );
      setQueue( 'ready' );
    } catch ( error ) {
      setQueue( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'The queue could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [] );

  /** Sets one set-valued filter, or clears it when nothing is chosen. */
  const set = ( key: 'client_id' | 'intake_state' | 'type', value: string ) => {
    const next = { ...filters };

    if ( '' === value ) {
      delete next[ key ];
    } else {
      next[ key ] = [ value ];
    }

    setFilters( next );
  };

  const clients = [ ...new Map( submissions.map( ( one ) => [ one.client_id, one.client_name ] ) ) ];
  const open = submissions.find( ( one ) => one.id === openId );

  /*
   * The three saved views the design names, over the same status filter the
   * select sets — a view is a filter with a name, never a second list.
   */
  const AWAITING = [ 'received', 'in-review' ];
  const DECIDED = [ 'accepted', 'declined', 'converted' ];
  const VIEWS: Array< { id: string; label: string; states: string[] } > = [
    { id: 'review', label: 'Awaiting review', states: AWAITING },
    { id: 'decided', label: 'Decided', states: DECIDED },
    { id: 'all', label: 'All submissions', states: [] },
  ];
  const activeView = VIEWS.find( ( v ) => JSON.stringify( v.states ) === JSON.stringify( filters.intake_state ?? [] ) )?.id;

  return (
    <>
      { 'ready' === queue && (
        <div className="bwx-views-row" role="group" aria-label="Saved views" data-testid="bwx-queue-views">
          { VIEWS.map( ( v ) => (
            <ViewPill
              key={ v.id }
              label={ v.label }
              count={ 0 === v.states.length ? submissions.length : submissions.filter( ( one ) => v.states.includes( one.intake_state ) ).length }
              active={ activeView === v.id }
              onClick={ () => {
                const next = { ...filters };
                if ( 0 === v.states.length ) delete next.intake_state;
                else next.intake_state = v.states;
                setFilters( next );
                setPicked( [] );
              } }
            />
          ) ) }
          <span className="bwx-views-note">Submissions are immutable source records; a decision always records a reason.</span>
        </div>
      ) }

      <header className="bwx-header">
        <input
          type="search"
          className="bwx-input"
          data-testid="bwx-queue-search"
          placeholder="Search requests"
          aria-label="Search requests"
          value={ filters.search ?? '' }
          onChange={ ( event ) => setFilters( { ...filters, search: event.target.value } ) }
        />

        <select
          className="bwx-select"
          data-testid="bwx-queue-client"
          aria-label="Client"
          value={ ( filters.client_id ?? [] )[ 0 ] ?? '' }
          onChange={ ( event ) => set( 'client_id', event.target.value ) }
        >
          <option value="">All clients</option>
          { clients.map( ( [ id, name ] ) => (
            <option key={ id } value={ id }>
              { name }
            </option>
          ) ) }
        </select>

        <select
          className="bwx-select"
          data-testid="bwx-queue-state"
          aria-label="Status"
          value={ 1 === ( filters.intake_state ?? [] ).length ? filters.intake_state![ 0 ] : '' }
          onChange={ ( event ) => set( 'intake_state', event.target.value ) }
        >
          <option value="">Any status</option>
          { states.map( ( state ) => (
            <option key={ state.slug } value={ state.slug }>
              { state.label }
            </option>
          ) ) }
        </select>

        <select
          className="bwx-select"
          data-testid="bwx-queue-type"
          aria-label="Kind"
          value={ ( filters.type ?? [] )[ 0 ] ?? '' }
          onChange={ ( event ) => set( 'type', event.target.value ) }
        >
          <option value="">Anything</option>
          <option value="request">Requests</option>
          <option value="idea">Ideas</option>
          <option value="suggestion">Suggestions</option>
        </select>

        <span className="bwx-header-spacer" />

        <span className="bwx-mono" data-testid="bwx-queue-count">
          { shown.length } { 1 === shown.length ? 'request' : 'requests' }
        </span>
      </header>

      { '' !== notice && 'error' !== queue && (
        <p className="bwx-notice" data-testid="bwx-queue-notice" role="status" style={ { margin: '12px 20px 0' } }>
          { notice }
        </p>
      ) }

      { 'loading' === queue && <Screen state="loading" detail="Reading what clients have asked for." /> }

      { 'denied' === queue && (
        <Screen
          state="denied"
          testId="bwx-queue-state-screen"
          detail="You are signed in, but not on any client whose requests you may read. Ask for a membership, or for cross-client access."
        />
      ) }

      { 'error' === queue && (
        <Screen
          state="error"
          detail={ notice }
          action={
            <button
              type="button"
              className="bwx-button"
              onClick={ () => {
                setQueue( 'loading' );
                void load();
              } }
            >
              Try again
            </button>
          }
        />
      ) }

      { 'ready' === queue && 0 === submissions.length && (
        <Screen
          state="empty"
          title="Nobody has asked for anything yet"
          detail="When a client sends a request, an idea or a suggestion from their own site, it arrives here."
        />
      ) }

      { /*
         A filtered-to-nothing queue is not an empty queue, and saying so is the
         difference between "clear the filters" and "there is no work to do".
       */ }
      { 'ready' === queue && 0 < submissions.length && 0 === shown.length && (
        <Screen
          state="empty"
          title="Nothing matches those filters"
          detail={ `${ submissions.length } ${ 1 === submissions.length ? 'request is' : 'requests are' } hidden by them.` }
          action={
            <button type="button" className="bwx-button" onClick={ () => setFilters( {} ) }>
              Clear filters
            </button>
          }
        />
      ) }

      { 'ready' === queue && 0 < shown.length && (
        <div className="bwx-queue" data-testid="bwx-queue">
          <table className="bwx-table">
            <thead>
              <tr>
                <th scope="col" className="bwx-table-check">
                  <Check
                    label="Select every request shown"
                    checked={ 0 < shown.length && shown.every( ( one ) => picked.includes( one.id ) ) }
                    indeterminate={ shown.some( ( one ) => picked.includes( one.id ) ) && ! shown.every( ( one ) => picked.includes( one.id ) ) }
                    onChange={ () => setPicked( shown.every( ( one ) => picked.includes( one.id ) ) ? [] : shown.map( ( one ) => one.id ) ) }
                  />
                </th>
                <th scope="col">Request</th>
                <th scope="col">Client</th>
                <th scope="col">Status</th>
                <th scope="col">Waiting</th>
              </tr>
            </thead>
            <tbody>
              { shown.map( ( one ) => (
                <tr
                  key={ one.id }
                  className="bwx-row"
                  data-testid="bwx-queue-row"
                  data-submission={ one.id }
                  data-state={ one.intake_state }
                  data-picked={ picked.includes( one.id ) ? 'true' : undefined }
                  style={
                    {
                      '--row-rail': `var(--intake-${ one.intake_state })`,
                    } as React.CSSProperties
                  }
                >
                  <td className="bwx-table-check">
                    <Check
                      label={ `Select ${ one.title }` }
                      checked={ picked.includes( one.id ) }
                      onChange={ () => setPicked( picked.includes( one.id ) ? picked.filter( ( id ) => id !== one.id ) : [ ...picked, one.id ] ) }
                    />
                  </td>
                  <td>
                    <button
                      type="button"
                      className="bwx-row-open"
                      onClick={ () => setOpenId( one.id ) }
                    >
                      { one.title }
                    </button>
                    <span className="bwx-card-meta">
                      <span className="bwx-eyebrow">{ kindOf( one.type ) }</span>
                      <span className="bwx-mono">{ one.submitted_by }</span>
                    </span>
                  </td>
                  <td data-testid="bwx-queue-client-name">{ one.client_name }</td>
                  <td>
                    <span className="bwx-chip" data-state={ one.intake_state }>
                      { one.intake_label }
                    </span>
                  </td>
                  <td className="bwx-mono">{ waited( one ) }</td>
                </tr>
              ) ) }
            </tbody>
          </table>
        </div>
      ) }

      { 0 < picked.length && (
        <div className="fk-bulk-bar" role="status" data-testid="bwx-queue-bulk">
          <span>
            <span className="fk-mono">{ picked.length }</span>
            { ` ${ 1 === picked.length ? 'request' : 'requests' } selected` }
          </span>
          <span className="fk-bulk-actions">
            <BulkButton onClick={ () => void decideAll( 'in-review', '' ) }>Being looked at</BulkButton>
            <BulkButton onClick={ () => setDeciding( 'declined' ) }>Decline…</BulkButton>
          </span>
          <button type="button" className="fk-bulk-close" aria-label="Clear selection" onClick={ () => setPicked( [] ) }>
            ×
          </button>
        </div>
      ) }

      { 'declined' === deciding && (
        <Modal
          title={ `Decline ${ picked.length } ${ 1 === picked.length ? 'request' : 'requests' }` }
          description="The reason is recorded on every one of them, word for word, and each client can read it on their own site."
          onClose={ () => setDeciding( null ) }
        >
          <div data-testid="bwx-queue-decline">
            <ReasonAction label={ busy ? 'Declining…' : 'Decline them' } variant="danger" placeholder="Why these are not going ahead." onSubmit={ ( reason ) => void decideAll( 'declined', reason ) } />
          </div>
        </Modal>
      ) }

      { undefined !== open && (
        <RequestPanel
          submission={ open }
          states={ states }
          onClose={ () => setOpenId( '' ) }
          onAnswered={ ( answered ) => {
            setSubmissions(
              submissions.map( ( one ) => ( one.id === answered.id ? answered : one ) )
            );
            setNotice( `Saved. ${ answered.client_name } can see this on their own site.` );
          } }
        />
      ) }
    </>
  );
}

/** What a client chose to call it, in the studio's words. */
function kindOf( type: string ): string {
  switch ( type ) {
    // #151. Its own word, never the 'Request' fallback: a queue that shows a
    // broken site as a request has hidden the one thing about it that matters.
    case 'bug':
      return 'Something broken';
    case 'idea':
      return 'Idea';
    case 'suggestion':
      return 'Suggestion';
    default:
      return 'Request';
  }
}

/**
 * How long a request has been waiting, in the roughest unit that is still true.
 *
 * Shown for everything, answered or not. "Answered after eleven days" is as
 * much a fact about how the studio is doing as "waiting eleven days" is.
 */
function waited( one: Submission ): string {
  const days = Math.floor( ( Date.now() / 1000 - one.created_at ) / 86400 );

  if ( 0 >= days ) {
    return 'today';
  }

  if ( 1 === days ) {
    return '1 day';
  }

  if ( 28 > days ) {
    return `${ days } days`;
  }

  const months = Math.floor( days / 30 );

  return 1 >= months ? '1 month' : `${ months } months`;
}
