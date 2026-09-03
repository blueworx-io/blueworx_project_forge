import { useEffect, useRef, useState } from 'react';
import type {
  Comment,
  GateCheck,
  GateRecord,
  Person,
  Readiness,
  Requirement,
  Stage,
  WorkEvent,
  WorkItem,
} from '../types';
import { api, ApiError, GateError, isDenied, messageFor } from '../api';
import { phaseOf } from '../phases';
import { Inline, Screen } from './States';

interface Detail {
  item: WorkItem;
  history: WorkEvent[];
  available: string[];
  readiness: Record< string, Readiness >;
  returns: string[];
  outcomes: string[];
  can_archive: boolean;
  can_override: boolean;
  records: Record< string, GateRecord >;
  comments: Comment[];
  scope: string;
}

const EDITABLE = [
  { field: 'title', label: 'Title', lines: 1 },
  { field: 'problem', label: 'Problem it solves', lines: 3 },
  { field: 'scope', label: 'Scope', lines: 3 },
  { field: 'requirements', label: 'Requirements', lines: 3 },
  { field: 'acceptance_criteria', label: 'Done when', lines: 3 },
] as const;

const OUTCOME_LABEL: Record< string, string > = {
  rejected: 'Reject',
  duplicate: 'Mark duplicate',
  cancelled: 'Cancel',
  deferred: 'Defer',
};

const BLOCKER_FIELDS = [
  { field: 'reason', label: 'What is blocking it' },
  { field: 'owner', label: 'Who owns the blocker' },
  { field: 'dependency', label: 'What it is waiting on' },
  { field: 'target_date', label: 'Target resolution date' },
  { field: 'next_action', label: 'Next action' },
] as const;

/**
 * The three seats, named for what the person does.
 *
 * "Primary User" is what the field is called; "doing the work" is the question
 * being answered, and the second is what belongs on a screen. Each carries the
 * hours planned against it, because who is reviewing this and how long we said
 * the review would take are one conversation (#98).
 */
const SEATS = [
  { field: 'primary_user_id', hours: 'hours_primary', label: 'Doing the work' },
  { field: 'reviewer_id', hours: 'hours_review', label: 'Reviewing it' },
  { field: 'deliverer_id', hours: 'hours_delivery', label: 'Delivering it' },
] as const;

/** How work is classified commercially (COMM-5). */
const CLASSES = [
  { value: 'unclassified', label: 'Nobody has decided yet' },
  { value: 'chargeable', label: 'Chargeable' },
  { value: 'free-bug', label: 'Free bug — we delivered the thing that broke' },
] as const;

const PRIORITIES = [ 'low', 'normal', 'high', 'urgent' ] as const;

const RELEASE_METHODS = [
  { value: 'software', label: 'Software' },
  { value: 'content', label: 'Content' },
  { value: 'design', label: 'Design' },
  { value: 'infrastructure', label: 'Infrastructure' },
  { value: 'non-deployment', label: 'Nothing was deployed' },
] as const;

const DATES = [
  { field: 'planned_start', label: 'Starts', needed: 'up-next' },
  { field: 'planned_due', label: 'Due', needed: 'up-next' },
  { field: 'review_target', label: 'Review by', needed: '' },
  { field: 'release_target', label: 'Release by', needed: '' },
] as const;

/**
 * Everything this section writes, and every field the draft therefore holds.
 *
 * Kept as one list so the draft, the save and the reset cannot disagree about
 * what the section owns.
 */
const ASSIGNMENT = [
  'commercial_class',
  'delivered_by_forge',
  'priority',
  'remaining_estimate',
  'release_method',
  'release_destination',
  ...SEATS.flatMap( ( seat ) => [ seat.field, seat.hours ] ),
  ...DATES.map( ( date ) => date.field ),
] as const;

/**
 * Which stage a field has to be filled in by, from Work\Fields::REQUIRED_FROM.
 *
 * Repeated here rather than sent, because the server enforces it and this only
 * says so out loud — a copy that drifted would mislabel a field, never let one
 * through. The gates remain the thing that refuses.
 */
const NEEDED_FROM: Record< string, string > = {
  commercial_class: 'triage',
  priority: 'triage',
  primary_user_id: 'up-next',
  reviewer_id: 'up-next',
  deliverer_id: 'up-next',
  planned_start: 'up-next',
  planned_due: 'up-next',
  remaining_estimate: 'in-development',
  release_method: 'completed',
  release_destination: 'completed',
};

/**
 * The studio's people, fetched once and shared by every panel.
 *
 * The seat pickers want the same list every time an item is opened, and it
 * changes about as often as somebody is hired — so one request, held here,
 * rather than one per panel. A failed read is not cached, so the next panel
 * tries again.
 */
let roster: Promise< Person[] > | null = null;

function everybody(): Promise< Person[] > {
  if ( null === roster ) {
    roster = api< { users: Person[] } >( '/users?status=active' )
      .then( ( answer ) => answer.users )
      .catch( () => {
        roster = null;

        return [];
      } );
  }

  return roster;
}

/**
 * The item's editable values, as the strings its inputs hold.
 *
 * Zero becomes empty on purpose. Every item starts with no planned hours, and a
 * box reading "0" says somebody decided the review would take no time; empty
 * says what is actually true, which is that nobody has said yet.
 */
function asDraft( item: WorkItem ): Record< string, string > {
  const draft: Record< string, string > = Object.fromEntries(
    EDITABLE.map( ( { field } ) => [ field, String( item[ field ] ?? '' ) ] )
  );

  for ( const field of ASSIGNMENT ) {
    const value = ( item as unknown as Record< string, unknown > )[ field ];

    if ( 'number' === typeof value ) {
      draft[ field ] = 0 === value ? '' : String( value );
    } else if ( 'boolean' === typeof value ) {
      draft[ field ] = value ? '1' : '';
    } else {
      draft[ field ] = String( value ?? '' );
    }
  }

  return draft;
}

/**
 * Why a save was refused, in the words the server used.
 *
 * The API answers a bad value with a message for each field it rejected — "a
 * reviewer has to be somebody other than the person who did the work" — and
 * losing those behind "that change could not be saved" is how somebody clicks
 * Save twice and then goes and asks somebody.
 */
function refusal( error: unknown ): string {
  const fields = error instanceof ApiError ? error.data.fields : null;

  const said =
    'object' === typeof fields && null !== fields
      ? Object.values( fields as Record< string, string > )
      : [];

  return 0 < said.length ? said.join( ' ' ) : messageFor( error, 'That change could not be saved.' );
}

function when( seconds: number ): string {
  return new Date( seconds * 1000 ).toLocaleString();
}

/** A week, said the way somebody planning a week would say it. */
function weekOf( date: string ): string {
  return new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
  } );
}

/** Hours, without a trailing decimal nobody needs. */
function hours( value: number ): string {
  return `${ Math.round( value * 10 ) / 10 }`;
}

/** Blocked time, said the way a person would say it. */
function forHowLong( seconds: number ): string {
  if ( seconds < 3600 ) {
    return `${ Math.max( 1, Math.round( seconds / 60 ) ) } minutes`;
  }

  if ( seconds < 86400 ) {
    return `${ Math.round( seconds / 3600 ) } hours`;
  }

  return `${ Math.round( seconds / 86400 ) } days`;
}

/**
 * One item, opened.
 *
 * This panel carries every way work moves, as buttons rather than as gestures.
 * Drag is the quick way to move a card forward; this is the way that always
 * works — with a keyboard, on a phone, when the next stage is off the side of a
 * twelve-column board, and for every move that is not a drag at all: sending
 * work back, blocking it, ending it.
 *
 * It also shows the gate **before** it refuses anybody. A person who can see
 * the four things still outstanding does those four things; a person who finds
 * out one at a time by being refused does something else instead.
 */
export function ItemPanel( {
  itemId,
  stages,
  onClose,
  onChanged,
}: {
  itemId: string;
  stages: Stage[];
  onClose: () => void;
  onChanged: () => void;
} ) {
  const [ detail, setDetail ] = useState< Detail | null >( null );
  const [ loadState, setLoadState ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );
  const [ draft, setDraft ] = useState< Record< string, string > >( {} );
  const [ notice, setNotice ] = useState( '' );
  const [ unmet, setUnmet ] = useState< Requirement[] >( [] );
  const [ checks, setChecks ] = useState< GateCheck[] >( [] );
  const [ busy, setBusy ] = useState( false );
  const [ showing, setShowing ] = useState( '' );
  const [ back, setBack ] = useState( { to: '', reason: '', feedback: '' } );

  /**
   * The move the capacity check refused, and the reason offered for going ahead
   * anyway. Held against the stage that was attempted, because the reason
   * belongs to that one crossing — it is not a setting on the item.
   */
  const [ overrun, setOverrun ] = useState( { to: '', reason: '' } );

  /** Whether the refusal we are showing includes somebody having no room. */
  const overBooked = unmet.some( ( requirement ) => 0 < ( requirement.over?.length ?? 0 ) );
  const [ blocker, setBlocker ] = useState< Record< string, string > >( {} );
  const [ resolution, setResolution ] = useState( '' );
  const [ ending, setEnding ] = useState( { outcome: '', reason: '', duplicate_of: '' } );
  const [ comment, setComment ] = useState( {
    body: '',
    url: '',
    visibility: 'internal',

    /*
     * Whether this is an information request rather than a remark (#133).
     * A question is always client-visible — one they cannot see is a note to
     * ourselves — so turning this on takes the visibility choice away rather
     * than leaving a control that can only be set one way.
     */
    asking: false,
  } );
  const closer = useRef< HTMLButtonElement >( null );

  /** Who can hold a seat. Empty until the list arrives, and harmless if it never does. */
  const [ staffList, setStaffList ] = useState< Person[] >( [] );

  const label = ( id: string ) => stages.find( ( stage ) => stage.id === id )?.label ?? id;
  const staff = 'staff' === detail?.scope;

  async function load() {
    try {
      const loaded = await api< Detail >( `/work-items/${ itemId }` );
      setDetail( loaded );
      setLoadState( 'ready' );
      setDraft( asDraft( loaded.item ) );
    } catch ( error ) {
      // Told apart deliberately: "we could not load this" and "this is not
      // yours to read" are different problems with different next steps.
      setLoadState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'That item could not be loaded.' ) );
    }
  }

  useEffect( () => {
    // Every state change inside load() happens after an await, which the rule
    // cannot see. Reading the item when the panel opens is what an effect is
    // for.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();

    // The people who could hold a seat, so the pickers have names in them.
    void everybody().then( setStaffList );

    // Focus lands in the panel when it opens, so a keyboard user is not left
    // behind on the board underneath it.
    closer.current?.focus();

    // load() is rebuilt every render, so naming it as a dependency would reload
    // the panel forever. The item's id is the only thing that should reopen it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ itemId ] );

  /**
   * Every write goes through here, so a gate failure is handled once. A refusal
   * lists what is missing rather than saying no, which is the difference #107
   * exists to make.
   */
  async function act( path: string, body: Record< string, unknown >, done: string ) {
    if ( ! detail ) {
      return;
    }

    setBusy( true );
    setNotice( '' );
    setUnmet( [] );
    setChecks( [] );

    try {
      await api( `/work-items/${ itemId }${ path }`, {
        method: 'POST',
        body: { ...body, record_version: detail.item.record_version },
      } );
      await load();
      onChanged();
      setShowing( '' );
      setOverrun( { to: '', reason: '' } );
      setNotice( done );
    } catch ( error ) {
      if ( error instanceof GateError ) {
        setUnmet( error.unmet );
        setChecks( error.checks );

        // Which crossing was refused, so a reason given now is given about the
        // move that was actually attempted.
        setOverrun( { to: error.attempted, reason: '' } );
        setNotice( error.message );
      } else {
        setNotice( messageFor( error, 'That did not work.' ) );
      }
    } finally {
      setBusy( false );
    }
  }

  async function save() {
    if ( ! detail ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      await api( `/work-items/${ itemId }`, {
        method: 'PATCH',
        body: { ...draft, record_version: detail.item.record_version },
      } );
      await load();
      onChanged();
      setNotice( 'Saved.' );
    } catch ( error ) {
      setNotice( refusal( error ) );
    } finally {
      setBusy( false );
    }
  }

  /** Marks one gate requirement done, with the signed-in person's name on it. */
  async function complete( requirement: Requirement, value: string, evidence: string ) {
    setBusy( true );

    try {
      await api( `/work-items/${ itemId }/gate`, {
        method: 'POST',
        body: { requirement: requirement.id, value, evidence },
      } );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be recorded.' ) );
    } finally {
      setBusy( false );
    }
  }

  async function addComment() {
    if ( '' === comment.body.trim() && '' === comment.url.trim() ) {
      return;
    }

    setBusy( true );

    try {
      await api( `/work-items/${ itemId }/comments`, {
        method: 'POST',
        body: {
          body: comment.body,
          url: comment.url,
          kind: kindOf( comment ),
          visibility: comment.visibility,
        },
      } );
      setComment( { body: '', url: '', visibility: comment.visibility, asking: false } );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That comment could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  const item = detail?.item;
  const blocked = 'blocked' === item?.stage;
  const ended = undefined !== item && '' !== item.terminal_outcome && 'deferred' !== item.terminal_outcome;

  /*
   * The stages work passes through, in order, with Blocked left out — it is an
   * exception state rather than a step, so an item sitting in it is measured
   * from the stage it came out of.
   */
  const order = stages.filter( ( stage ) => 'exception' !== stage.kind ).map( ( stage ) => stage.id );
  const standing = ( blocked ? item?.prior_stage : item?.stage ) ?? '';

  /** Whether the item has got as far as a given stage. */
  const reached = ( stage: string ) =>
    -1 !== order.indexOf( standing ) && order.indexOf( standing ) >= order.indexOf( stage );

  /** Whether a field is still waiting to be filled in, as its own rules count empty. */
  const blank = ( field: string ) => {
    const value = draft[ field ] ?? '';

    if ( 'commercial_class' === field ) {
      // The column's default means nobody has classified it, which is the
      // opposite of an answer — Gates counts it as empty and so does this.
      return '' === value || 'unclassified' === value;
    }

    if ( 'remaining_estimate' === field ) {
      return 0 >= Number( value || 0 );
    }

    return '' === value;
  };

  /**
   * The stage a field is holding up, if it is holding one up now.
   *
   * Only once the item has reached the stage that wants it. Warning somebody at
   * Triage that Up Next will want three seats and two dates would put a marker
   * on almost every field of a brand-new item, and a screen where everything is
   * flagged flags nothing.
   */
  const holdingUp = ( field: string ) => {
    const by = NEEDED_FROM[ field ] ?? '';

    return '' !== by && blank( field ) && reached( by ) ? label( by ) : '';
  };

  /** A field's label, plus the stage it is holding up. */
  const naming = ( field: string, name: string ) => (
    <label htmlFor={ `bwx-${ field }` }>
      { name }
      { '' !== holdingUp( field ) && (
        <span className="bwx-needed" data-testid="bwx-needed" data-field={ field }>
          { `(needed to leave ${ holdingUp( field ) })` }
        </span>
      ) }
    </label>
  );

  /** One seat's picker, with the people who could sit in it. */
  const pick = ( field: string, name: string ) => (
    <div className="bwx-field">
      { naming( field, name ) }
      <select
        id={ `bwx-${ field }` }
        className="bwx-select"
        value={ draft[ field ] ?? '' }
        onChange={ ( event ) => setDraft( { ...draft, [ field ]: event.target.value } ) }
      >
        <option value="">Nobody yet</option>
        { staffList.map( ( person ) => (
          <option key={ person.id } value={ person.id }>
            { person.display_name }
          </option>
        ) ) }
      </select>
    </div>
  );

  /** An hours box. Half hours, because that is how the work is planned. */
  const measure = ( field: string, name: string ) => (
    <div className="bwx-field">
      { naming( field, name ) }
      <input
        id={ `bwx-${ field }` }
        className="bwx-input"
        type="number"
        min="0"
        step="0.5"
        value={ draft[ field ] ?? '' }
        onChange={ ( event ) => setDraft( { ...draft, [ field ]: event.target.value } ) }
      />
    </div>
  );

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
        aria-label="Work item"
        data-testid="bwx-panel"
        onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
      >
        <header className="bwx-panel-head">
          <div style={ { flex: 1 } }>
            <p className="bwx-eyebrow" data-testid="bwx-panel-stage">
              { item ? label( item.stage ) : 'Loading' }
              { ended && ` · ${ item.terminal_label }` }
              { item?.archived && ' · archived' }
            </p>
            <h2 style={ { margin: '4px 0 0', fontSize: 'var(--text-subheading)', fontWeight: 500 } }>
              { item?.title ?? '' }
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

        { 'loading' === loadState && <Screen state="loading" testId="bwx-panel-state" /> }

        { 'denied' === loadState && (
          <Screen
            state="denied"
            testId="bwx-panel-state"
            detail="This work belongs to a client you do not have access to. Ask for a membership on it, or open something on a site you work with."
          />
        ) }

        { 'error' === loadState && (
          <Screen
            state="error"
            testId="bwx-panel-state"
            detail={ notice }
            action={
              <button type="button" className="bwx-button" onClick={ () => void load() }>
                Try again
              </button>
            }
          />
        ) }

        { '' !== notice && 'ready' === loadState && (
          <p
            className="bwx-notice"
            data-tone={ 'Saved.' === notice ? 'quiet' : undefined }
            data-testid="bwx-panel-notice"
            role="status"
          >
            { notice }
          </p>
        ) }

        { 0 < unmet.length && (
          <div>
            <p className="bwx-eyebrow">Still needed</p>
            <ul className="bwx-unmet" data-testid="bwx-unmet">
              { unmet.map( ( requirement ) => (
                <li key={ requirement.id } data-requirement={ requirement.id }>
                  <span className="bwx-unmet-label">{ requirement.label }</span>
                  <span className="bwx-unmet-how">{ requirement.satisfied_by }</span>
                  { 0 < ( requirement.over?.length ?? 0 ) && (
                    <ul className="bwx-over" data-testid="bwx-over">
                      { requirement.over?.map( ( person ) => (
                        <li key={ `${ person.user_id }-${ person.week_from }` } data-user={ person.user_id }>
                          <span className="bwx-over-who">{ person.display_name }</span>
                          <span className="bwx-over-when">week of { weekOf( person.week_from ) }</span>
                          <span className="bwx-mono bwx-over-much">
                            { hours( person.committed ) } of { hours( person.available ) } hours
                          </span>
                        </li>
                      ) ) }
                    </ul>
                  ) }
                  { requirement.hours && ! requirement.hours.sufficient && (
                    <p className="bwx-short" data-testid="bwx-short" data-because={ requirement.hours.because }>
                      { 'no_package' === requirement.hours.because
                        ? 'This site cannot draw on support hours yet.'
                        : `${ hours( requirement.hours.shortfall ) } hours short of the ${ hours(
                            requirement.hours.needed
                          ) } this work needs.` }
                    </p>
                  ) }
                </li>
              ) ) }
            </ul>
            { checks.map( ( check ) => (
              <p className="bwx-check" key={ check.id } data-testid="bwx-check" data-result={ check.result }>
                <span>{ check.label }</span>
                <span className="bwx-mono">{ check.result }</span>
              </p>
            ) ) }

            { /*
                CAP-4: over-booking somebody does not block, it costs a reason.
                Shown only where there is an over-allocation to explain and the
                person may explain it — being offered a way through and then
                refused is worse than never being offered one.
             */ }
            { overBooked && detail?.can_override && (
              <div className="bwx-overrun" data-testid="bwx-overrun">
                <div className="bwx-field">
                  <label htmlFor="bwx-overrun-reason">Why this week will take it</label>
                  <input
                    id="bwx-overrun-reason"
                    className="bwx-input"
                    data-testid="bwx-overrun-reason"
                    value={ overrun.reason }
                    onChange={ ( event ) => setOverrun( { ...overrun, reason: event.target.value } ) }
                  />
                </div>
                <button
                  type="button"
                  className="bwx-button"
                  data-testid="bwx-overrun-go"
                  disabled={ busy || '' === overrun.reason.trim() }
                  onClick={ () =>
                    void act(
                      '/transition',
                      { to: overrun.to, capacity_reason: overrun.reason.trim() },
                      `Moved to ${ label( overrun.to ) }, over-booked on purpose.`
                    )
                  }
                >
                  Go ahead anyway
                </button>
              </div>
            ) }
          </div>
        ) }

        { detail && item && (
          <>
            { blocked && (
              <div>
                <p className="bwx-eyebrow">Blocked</p>
                <Inline state="empty" testId="bwx-blocked-note">
                  Waiting since it left { label( item.prior_stage ) }. It goes back there and
                  nowhere else.
                </Inline>
                <div className="bwx-field">
                  <label htmlFor="bwx-resolution">How was it resolved</label>
                  <input
                    id="bwx-resolution"
                    className="bwx-input"
                    data-testid="bwx-resolution"
                    value={ resolution }
                    onChange={ ( event ) => setResolution( event.target.value ) }
                  />
                </div>
                <div className="bwx-moves">
                  <button
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-unblock"
                    disabled={ busy }
                    onClick={ () =>
                      void act( '/unblock', { resolution }, `Back in ${ label( item.prior_stage ) }.` )
                    }
                  >
                    Unblock
                  </button>
                </div>
              </div>
            ) }

            { ended && (
              <Inline state="empty" testId="bwx-ended-note">
                This work ended as { item.terminal_label.toLowerCase() }. It stays in the reports and
                does not move again.
              </Inline>
            ) }

            { ! blocked && ! ended && (
              <div>
                <p className="bwx-eyebrow">Move to</p>
                <div className="bwx-moves">
                  { detail.available.map( ( to ) => (
                    <button
                      key={ to }
                      type="button"
                      className="bwx-button"
                      data-testid="bwx-move"
                      data-to={ to }
                      data-ready={ 0 === ( detail.readiness[ to ]?.unmet.length ?? 0 ) ? 'true' : 'false' }
                      disabled={ busy }
                      style={
                        { borderColor: `var(--phase-${ phaseOf( to ) })` } as React.CSSProperties
                      }
                      onClick={ () => void act( '/transition', { to }, `Moved to ${ label( to ) }.` ) }
                    >
                      { label( to ) }
                      { 0 < ( detail.readiness[ to ]?.unmet.length ?? 0 ) && (
                        <span className="bwx-mono"> · { detail.readiness[ to ].unmet.length } to do</span>
                      ) }
                    </button>
                  ) ) }
                  { 0 === detail.available.length && (
                    <Inline state="empty">This is the end of the road.</Inline>
                  ) }
                </div>
              </div>
            ) }

            { ! blocked && ! ended && detail.available.map( ( to ) => (
              <GateList
                key={ to }
                heading={ `Before ${ label( to ) }` }
                readiness={ detail.readiness[ to ] }
                records={ detail.records }
                busy={ busy }
                onComplete={ complete }
              />
            ) ) }

            { ! ended && (
              <div className="bwx-moves">
                { ! blocked && 0 < detail.returns.length && (
                  <button
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-show-return"
                    onClick={ () => setShowing( 'return' === showing ? '' : 'return' ) }
                  >
                    Send back
                  </button>
                ) }
                { ! blocked && (
                  <button
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-show-block"
                    onClick={ () => setShowing( 'block' === showing ? '' : 'block' ) }
                  >
                    Block
                  </button>
                ) }
                { 0 < detail.outcomes.length && (
                  <button
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-show-end"
                    onClick={ () => setShowing( 'end' === showing ? '' : 'end' ) }
                  >
                    End it
                  </button>
                ) }
              </div>
            ) }

            { detail.can_archive && (
              <div className="bwx-moves">
                <button
                  type="button"
                  className="bwx-button"
                  data-testid="bwx-archive"
                  disabled={ busy }
                  onClick={ () => void act( '/archive', {}, 'Archived. It stays in the reports.' ) }
                >
                  Archive
                </button>
              </div>
            ) }

            { 'return' === showing && (
              <div data-testid="bwx-return">
                <p className="bwx-eyebrow">Send back</p>
                <div className="bwx-field">
                  <label htmlFor="bwx-return-to">Back to</label>
                  <select
                    id="bwx-return-to"
                    className="bwx-select"
                    value={ back.to }
                    onChange={ ( event ) => setBack( { ...back, to: event.target.value } ) }
                  >
                    <option value="">Choose a stage</option>
                    { detail.returns.map( ( stage ) => (
                      <option key={ stage } value={ stage }>
                        { label( stage ) }
                      </option>
                    ) ) }
                  </select>
                </div>
                <div className="bwx-field">
                  <label htmlFor="bwx-return-reason">Why</label>
                  <input
                    id="bwx-return-reason"
                    className="bwx-input"
                    data-testid="bwx-return-reason"
                    value={ back.reason }
                    onChange={ ( event ) => setBack( { ...back, reason: event.target.value } ) }
                  />
                </div>
                { 'in-review' === item.stage && 'in-development' === back.to && (
                  <div className="bwx-field">
                    <label htmlFor="bwx-return-feedback">Review feedback</label>
                    <textarea
                      id="bwx-return-feedback"
                      className="bwx-textarea"
                      data-testid="bwx-return-feedback"
                      value={ back.feedback }
                      onChange={ ( event ) => setBack( { ...back, feedback: event.target.value } ) }
                    />
                  </div>
                ) }
                <div className="bwx-moves">
                  <button
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-return"
                    disabled={ busy || '' === back.to }
                    onClick={ () => void act( '/return', back, `Sent back to ${ label( back.to ) }.` ) }
                  >
                    Send back
                  </button>
                </div>
              </div>
            ) }

            { 'block' === showing && (
              <div data-testid="bwx-block">
                <p className="bwx-eyebrow">Block</p>
                { BLOCKER_FIELDS.map( ( { field, label: name } ) => (
                  <div className="bwx-field" key={ field }>
                    <label htmlFor={ `bwx-blocker-${ field }` }>{ name }</label>
                    <input
                      id={ `bwx-blocker-${ field }` }
                      className="bwx-input"
                      data-testid={ `bwx-blocker-${ field }` }
                      type={ 'target_date' === field ? 'date' : 'text' }
                      value={ blocker[ field ] ?? '' }
                      onChange={ ( event ) =>
                        setBlocker( { ...blocker, [ field ]: event.target.value } )
                      }
                    />
                  </div>
                ) ) }
                <div className="bwx-moves">
                  <button
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-block"
                    disabled={ busy }
                    onClick={ () => void act( '/block', blocker, 'Blocked. Its place is kept.' ) }
                  >
                    Block it
                  </button>
                </div>
              </div>
            ) }

            { 'end' === showing && (
              <div data-testid="bwx-end">
                <p className="bwx-eyebrow">End it</p>
                <div className="bwx-field">
                  <label htmlFor="bwx-outcome">Outcome</label>
                  <select
                    id="bwx-outcome"
                    className="bwx-select"
                    value={ ending.outcome }
                    onChange={ ( event ) => setEnding( { ...ending, outcome: event.target.value } ) }
                  >
                    <option value="">Choose an outcome</option>
                    { detail.outcomes.map( ( outcome ) => (
                      <option key={ outcome } value={ outcome }>
                        { OUTCOME_LABEL[ outcome ] ?? outcome }
                      </option>
                    ) ) }
                  </select>
                </div>
                { 'duplicate' === ending.outcome ? (
                  <div className="bwx-field">
                    <label htmlFor="bwx-duplicate">Which item survives</label>
                    <input
                      id="bwx-duplicate"
                      className="bwx-input"
                      data-testid="bwx-duplicate"
                      value={ ending.duplicate_of }
                      onChange={ ( event ) =>
                        setEnding( { ...ending, duplicate_of: event.target.value } )
                      }
                    />
                  </div>
                ) : (
                  <div className="bwx-field">
                    <label htmlFor="bwx-end-reason">Why</label>
                    <input
                      id="bwx-end-reason"
                      className="bwx-input"
                      data-testid="bwx-end-reason"
                      value={ ending.reason }
                      onChange={ ( event ) => setEnding( { ...ending, reason: event.target.value } ) }
                    />
                  </div>
                ) }
                { 'deferred' === ending.outcome && (
                  <Inline state="empty">
                    Deferring puts this back to Future Idea. It stays open, and stays in the
                    reports as deferred.
                  </Inline>
                ) }
                <div className="bwx-moves">
                  <button
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-end"
                    disabled={ busy || '' === ending.outcome }
                    onClick={ () => void act( '/outcome', ending, 'Recorded.' ) }
                  >
                    Record it
                  </button>
                </div>
              </div>
            ) }

            { EDITABLE.map( ( { field, label: name, lines } ) => (
              <div className="bwx-field" key={ field }>
                <label htmlFor={ `bwx-${ field }` }>{ name }</label>
                { 1 === lines ? (
                  <input
                    id={ `bwx-${ field }` }
                    className="bwx-input"
                    value={ draft[ field ] ?? '' }
                    onChange={ ( event ) => setDraft( { ...draft, [ field ]: event.target.value } ) }
                  />
                ) : (
                  <textarea
                    id={ `bwx-${ field }` }
                    className="bwx-textarea"
                    value={ draft[ field ] ?? '' }
                    onChange={ ( event ) => setDraft( { ...draft, [ field ]: event.target.value } ) }
                  />
                ) }
              </div>
            ) ) }

            { /*
                Who does the work, what it costs and when it happens.

                Every field here has been in the record and in the API since
                M3, and until now none of them had a control anywhere: the
                panel listed what a stage was waiting for and gave nobody a
                place to put it, so no item could be moved past Triage without
                calling the API by hand.

                All of it is shown from the start, rather than only the fields
                the current stage wants, because planning happens before it is
                demanded — you set a due date when you know it, not when a gate
                asks. What changes with the stage is only the marker saying
                which field is holding the work up now.

                Staff only. The seats are who answers for the work, and ARCH-7
                keeps that the studio's own answer.
             */ }
            { staff && ! ended && (
              <div className="bwx-assign" data-testid="bwx-assign">
                <p className="bwx-eyebrow">Who and when</p>

                <div className="bwx-pair">
                  <div className="bwx-field">
                    { naming( 'commercial_class', 'Who pays for it' ) }
                    <select
                      id="bwx-commercial_class"
                      className="bwx-select"
                      value={ draft.commercial_class ?? '' }
                      onChange={ ( event ) =>
                        setDraft( { ...draft, commercial_class: event.target.value } )
                      }
                    >
                      { CLASSES.map( ( option ) => (
                        <option key={ option.value } value={ option.value }>
                          { option.label }
                        </option>
                      ) ) }
                    </select>
                  </div>

                  <div className="bwx-field">
                    { naming( 'priority', 'Priority' ) }
                    <select
                      id="bwx-priority"
                      className="bwx-select"
                      value={ draft.priority ?? '' }
                      onChange={ ( event ) => setDraft( { ...draft, priority: event.target.value } ) }
                    >
                      <option value="">Not set</option>
                      { PRIORITIES.map( ( value ) => (
                        <option key={ value } value={ value }>
                          { value.charAt( 0 ).toUpperCase() + value.slice( 1 ) }
                        </option>
                      ) ) }
                    </select>
                  </div>
                </div>

                { /* COMM-5: a bug is free when we delivered the thing that broke. */ }
                <label className="bwx-tick" htmlFor="bwx-delivered_by_forge">
                  <input
                    id="bwx-delivered_by_forge"
                    type="checkbox"
                    checked={ '' !== ( draft.delivered_by_forge ?? '' ) }
                    onChange={ ( event ) =>
                      setDraft( { ...draft, delivered_by_forge: event.target.checked ? '1' : '' } )
                    }
                  />
                  <span>We delivered the thing that broke</span>
                </label>

                { SEATS.map( ( seat ) => (
                  <div className="bwx-seat" key={ seat.field }>
                    { pick( seat.field, seat.label ) }
                    { measure( seat.hours, 'Hours' ) }
                  </div>
                ) ) }

                <div className="bwx-pair">
                  { DATES.map( ( date ) => (
                    <div className="bwx-field" key={ date.field }>
                      { naming( date.field, date.label ) }
                      <input
                        id={ `bwx-${ date.field }` }
                        className="bwx-input"
                        type="date"
                        value={ draft[ date.field ] ?? '' }
                        onChange={ ( event ) =>
                          setDraft( { ...draft, [ date.field ]: event.target.value } )
                        }
                      />
                    </div>
                  ) ) }
                </div>

                { /* Only once there is work under way to have any left. */ }
                { reached( 'in-development' ) && measure( 'remaining_estimate', 'Hours still to do' ) }

                { /* WF-6, and only once something has actually been delivered. */ }
                { reached( 'completed' ) && (
                  <div className="bwx-pair">
                    <div className="bwx-field">
                      { naming( 'release_method', 'How it was released' ) }
                      <select
                        id="bwx-release_method"
                        className="bwx-select"
                        value={ draft.release_method ?? '' }
                        onChange={ ( event ) =>
                          setDraft( { ...draft, release_method: event.target.value } )
                        }
                      >
                        <option value="">Not set</option>
                        { RELEASE_METHODS.map( ( option ) => (
                          <option key={ option.value } value={ option.value }>
                            { option.label }
                          </option>
                        ) ) }
                      </select>
                    </div>

                    <div className="bwx-field">
                      { naming( 'release_destination', 'Where it went' ) }
                      <input
                        id="bwx-release_destination"
                        className="bwx-input"
                        value={ draft.release_destination ?? '' }
                        onChange={ ( event ) =>
                          setDraft( { ...draft, release_destination: event.target.value } )
                        }
                      />
                    </div>
                  </div>
                ) }
              </div>
            ) }

            <div className="bwx-moves">
              <button
                type="button"
                className="bwx-button"
                data-testid="bwx-save"
                disabled={ busy }
                onClick={ () => void save() }
              >
                Save changes
              </button>
            </div>

            <div>
              <p className="bwx-eyebrow">
                Comments and evidence
                { staff && <span className="bwx-mono"> · you see internal notes</span> }
              </p>

              { 0 === detail.comments.length ? (
                <Inline state="empty" testId="bwx-comments-empty">
                  Nothing said about this yet.
                </Inline>
              ) : (
                <ul className="bwx-comments" data-testid="bwx-comments">
                  { detail.comments.map( ( each ) => (
                    <li key={ each.id } className="bwx-comment" data-visibility={ each.visibility }>
                      { '' !== each.body && <span>{ each.body }</span> }
                      { '' !== each.url && (
                        <span>
                          <a href={ each.url } rel="noreferrer noopener" target="_blank">
                            { each.url }
                          </a>
                        </span>
                      ) }
                      <span className="bwx-comment-meta bwx-mono">
                        { said( each ) } · { each.author_name || 'the client' } ·{ ' ' }
                        { when( each.created_at ) }
                      </span>
                    </li>
                  ) ) }
                </ul>
              ) }

              <div className="bwx-field">
                <label htmlFor="bwx-comment">Add a comment</label>
                <textarea
                  id="bwx-comment"
                  className="bwx-textarea"
                  data-testid="bwx-comment"
                  value={ comment.body }
                  onChange={ ( event ) => setComment( { ...comment, body: event.target.value } ) }
                />
              </div>
              <div className="bwx-field">
                <label htmlFor="bwx-comment-url">Link to evidence, if there is any</label>
                <input
                  id="bwx-comment-url"
                  className="bwx-input"
                  data-testid="bwx-comment-url"
                  value={ comment.url }
                  onChange={ ( event ) => setComment( { ...comment, url: event.target.value } ) }
                />
              </div>
              { staff && (
                <div className="bwx-field">
                  <label>
                    <input
                      type="checkbox"
                      data-testid="bwx-comment-asking"
                      checked={ comment.asking }
                      onChange={ ( event ) =>
                        setComment( { ...comment, asking: event.target.checked } )
                      }
                    />{ ' ' }
                    I am asking the client for something
                  </label>
                  <p className="bwx-hint">
                    It shows on their own site as waiting for an answer, until they give one.
                  </p>
                </div>
              ) }

              { /*
                 The visibility choice disappears once this is a question,
                 because a question the client cannot see is not a question. A
                 select that can only be set one way is the dead control #134
                 exists to get rid of.
               */ }
              { staff && ! comment.asking && (
                <div className="bwx-field">
                  <label htmlFor="bwx-comment-visibility">Who can read it</label>
                  <select
                    id="bwx-comment-visibility"
                    className="bwx-select"
                    data-testid="bwx-comment-visibility"
                    value={ comment.visibility }
                    onChange={ ( event ) =>
                      setComment( { ...comment, visibility: event.target.value } )
                    }
                  >
                    <option value="internal">Internal only</option>
                    <option value="client">The client too</option>
                  </select>
                </div>
              ) }
              <div className="bwx-moves">
                <button
                  type="button"
                  className="bwx-button"
                  data-testid="bwx-add-comment"
                  disabled={ busy }
                  onClick={ () => void addComment() }
                >
                  Add
                </button>
              </div>
            </div>

            <div>
              <p className="bwx-eyebrow">History</p>
              <ul className="bwx-history" data-testid="bwx-history">
                { detail.history.map( ( event ) => (
                  <li key={ event.id }>
                    { describe( event, label ) }
                    { '' !== event.reason && <span> — { event.reason }</span> }
                    <span className="bwx-mono"> { when( event.occurred_at ) }</span>
                  </li>
                ) ) }
              </ul>
              { 0 < item.blocked_elapsed && (
                <p className="bwx-mono" data-testid="bwx-blocked-elapsed">
                  Blocked for { forHowLong( item.blocked_elapsed ) } in total.
                </p>
              ) }
            </div>
          </>
        ) }
      </aside>
    </div>
  );
}

/** One line of history, in words rather than in field names. */
function describe( event: WorkEvent, label: ( id: string ) => string ): string {
  switch ( event.action ) {
    case 'created':
      return `Created in ${ label( event.to_stage ) }`;
    case 'returned':
      return `Sent back to ${ label( event.to_stage ) }`;
    case 'blocked':
      return `Blocked, out of ${ label( event.from_stage ) }`;
    case 'unblocked':
      return `Unblocked, back to ${ label( event.to_stage ) }`;
    case 'ended':
      return `Ended as ${ event.outcome }`;
    case 'archived':
      return 'Archived';
    case 'over-allocated':
      // CAP-4. The reason sits beside it in the entry, so the line says what
      // was done and the reason says why.
      return 'Somebody was over-booked on purpose';
    default:
      return `${ label( event.from_stage ) } → ${ label( event.to_stage ) }`;
  }
}

/**
 * A gate, shown before it refuses anybody.
 *
 * Every requirement is listed, met and unmet, because the useful question is
 * "what does this stage want" rather than "what is left" — and a person who can
 * see the two things already recorded knows the list is being kept.
 */
export function GateList( {
  heading,
  readiness,
  records,
  busy,
  onComplete,
}: {
  heading: string;
  readiness?: Readiness;
  records: Record< string, GateRecord >;
  busy: boolean;
  onComplete: ( requirement: Requirement, value: string, evidence: string ) => Promise< void >;
} ) {
  const [ open, setOpen ] = useState( '' );
  const [ value, setValue ] = useState( '' );
  const [ evidence, setEvidence ] = useState( '' );

  if ( ! readiness || 0 === readiness.unmet.length ) {
    return null;
  }

  return (
    <div data-testid="bwx-gate">
      <p className="bwx-eyebrow">{ heading }</p>
      <ul className="bwx-unmet">
        { readiness.unmet.map( ( requirement ) => (
          <li key={ requirement.id } data-requirement={ requirement.id } data-met="false">
            <span className="bwx-unmet-label">{ requirement.label }</span>
            <span className="bwx-unmet-how">{ requirement.satisfied_by }</span>

            { /* Field requirements are satisfied by filling the field in above,
                 not by ticking them here — so only the recorded ones get a
                 control, and the rest read as instructions. */ }
            { 'record' === requirement.by && undefined === records[ requirement.id ] && (
              open === requirement.id ? (
                <>
                  <input
                    className="bwx-input"
                    aria-label={ `${ requirement.label } — what was done` }
                    value={ value }
                    onChange={ ( event ) => setValue( event.target.value ) }
                  />
                  { requirement.evidence && (
                    <input
                      className="bwx-input"
                      aria-label={ `${ requirement.label } — link to the evidence` }
                      placeholder="Link to the evidence"
                      value={ evidence }
                      onChange={ ( event ) => setEvidence( event.target.value ) }
                    />
                  ) }
                  <div className="bwx-moves">
                    <button
                      type="button"
                      className="bwx-button"
                      data-testid="bwx-record"
                      disabled={ busy }
                      onClick={ () => {
                        void onComplete( requirement, value, evidence ).then( () => {
                          setOpen( '' );
                          setValue( '' );
                          setEvidence( '' );
                        } );
                      } }
                    >
                      Record it
                    </button>
                  </div>
                </>
              ) : (
                <button
                  type="button"
                  className="bwx-button"
                  data-testid="bwx-open-record"
                  onClick={ () => setOpen( requirement.id ) }
                >
                  Record
                </button>
              )
            ) }
          </li>
        ) ) }
      </ul>

      { readiness.checks.map( ( check ) => (
        <p className="bwx-check" key={ check.id } data-result={ check.result }>
          <span>{ check.label }</span>
          <span className="bwx-mono">{ check.result }</span>
        </p>
      ) ) }
    </div>
  );
}

/**
 * What one entry on an item is, in a few words.
 *
 * Four states rather than the two visibility gave us, because since #133 an
 * entry can also be a question we asked or an answer a client sent back, and
 * "client can see this" says nothing useful about either. Whether the studio is
 * waiting on somebody is the thing worth reading at a glance.
 */
function said( entry: Comment ): string {
  if ( 'question' === entry.kind ) {
    return 'asked the client';
  }

  if ( entry.from_client ) {
    return '' === ( entry.answers ?? '' ) ? 'from the client' : 'the client answered';
  }

  return 'internal' === entry.visibility ? 'internal' : 'client can see this';
}

/** Which kind of entry the comment form is about to write. */
function kindOf( draft: { url: string; asking: boolean } ): string {
  if ( draft.asking ) {
    return 'question';
  }

  return '' === draft.url.trim() ? 'comment' : 'evidence';
}
