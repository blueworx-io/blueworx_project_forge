import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import type {
  ChecklistRow,
  Comment,
  GateCheck,
  GateRecord,
  LinkRow,
  Person,
  Readiness,
  Requirement,
  Stage,
  WorkEvent,
  WorkItem,
} from '../types';
import { api, ApiError, forgeData, GateError, isDenied, messageFor } from '../api';
import { phaseOf } from '../phases';
import { useLiveReload } from '../live';
import { HoursSelect } from '../hours';
import { RichText } from '../kit';
import { Inline, NOTHING_SAID, Notice, Screen } from './States';
import type { Said, SaidTone } from './States';

interface Detail {
  item: WorkItem;
  children: WorkItem[];
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
  dependencies: {
    upstream: DependencyRow[];
    downstream: DependencyRow[];
  };
}

/** One item this one waits on, or one waiting on it. */
interface DependencyRow {
  dependency_id: string;
  id: string;
  title: string;
  stage: string;
}

const EDITABLE = [
  { field: 'title', label: 'Title', lines: 1 },
  { field: 'problem', label: 'Item description', lines: 3 },
  { field: 'acceptance_criteria', label: 'Completed when', lines: 3 },
] as const;

/** How many lines a checklist holds, as the server bounds it. */
const CHECKLIST_ROWS = 10;

/**
 * The definition boxes, shown once the item has reached the stage that
 * wants them or when they hold something. Since 2026-09-19 reference
 * material is optional and the design link arrives at Design Process.
 */
const DEFINITION_BOXES = [
  { field: 'non_goals', label: 'Not covered', shown: 'documentation-period', lines: 3 },
  { field: 'references', label: 'Reference material', shown: 'documentation-period', lines: 3 },
  { field: 'design_url', label: 'Design link', shown: 'design-process', lines: 1 },
] as const;

/** The plain text fields the draft holds beside the editable ones. */
const TEXT_FIELDS = [ 'test_description' ] as const;

/** The pick whose item choice is what End it → duplicate uses. */
const DUPLICATE_PICK = 'G-TRIAGE-6';

/** The pick whose other answers hand over to End it. */
const TRIAGE_OUTCOME_PICK = 'G-TRIAGE-7';

/** Whose a requirement is, when it is not anybody's. */
const FOR_WHOM: Record< string, string > = {
  PU: 'For the person doing the work',
  REV: 'For the reviewer',
  DEL: 'For the deliverer',
};

const OUTCOME_LABEL: Record< string, string > = {
  rejected: 'Reject',
  duplicate: 'Mark duplicate',
  cancelled: 'Cancel',
  deferred: 'Defer',
};


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

/**
 * Who pays (COMM-5). A site bug is one we delivered the cause of, so choosing
 * it is the "delivered by Forge" answer and the server records that itself.
 */
const CLASSES = [
  { value: 'unclassified', label: 'To be confirmed' },
  { value: 'free-bug', label: 'Site bug — no charge to client' },
  { value: 'chargeable', label: 'Client — charge to client' },
  { value: 'free-general', label: 'No charge — general item' },
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
  'priority',
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
  primary_user_id: 'future-idea',
  reviewer_id: 'future-idea',
  deliverer_id: 'up-next',
  planned_start: 'up-next',
  planned_due: 'up-next',
  release_method: 'completed',
  release_destination: 'completed',
  non_goals: 'documentation-period',
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

/**
 * Every requirement there is, by id, fetched once. A box answered at an
 * earlier stage is still shown with what it holds, and only the definition
 * knows what kind of box it was.
 */
let rulebook: Promise< Record< string, Requirement > > | null = null;

function requirements(): Promise< Record< string, Requirement > > {
  if ( null === rulebook ) {
    rulebook = api< { gates: Record< string, Requirement[] > } >( '/gates' )
      .then( ( answer ) => Object.fromEntries( Object.values( answer.gates ).flat().map( ( row ) => [ row.id, row ] ) ) )
      .catch( () => {
        rulebook = null;

        return {};
      } );
  }

  return rulebook;
}

/**
 * Our people, once, for every picker (2026-09-20): the seats, who does a
 * chore, who a date is about. Read from /people, which any staff member may
 * read and which leaves out a client's own people — /users is the
 * administrator's and lists everyone, and a staff member opening a task was
 * getting an empty list from it.
 */
export function everybody(): Promise< Person[] > {
  if ( null === roster ) {
    roster = api< { people: Person[] } >( '/people' )
      .then( ( answer ) => answer.people )
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
    [ ...EDITABLE.map( ( { field } ) => field ), ...DEFINITION_BOXES.map( ( { field } ) => field ), ...TEXT_FIELDS ].map( ( field ) => [ field, String( item[ field ] ?? '' ) ] )
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
  const [ banner, setSaid ] = useState< Said >( NOTHING_SAID );
  const [ unmet, setUnmet ] = useState< Requirement[] >( [] );

  /** Red unless said otherwise: a confirmation is green, a refusal yellow. */
  const setNotice = ( text: string, tone: SaidTone = 'danger' ) => setSaid( '' === text ? NOTHING_SAID : { text, tone } );
  const notice = banner.text;
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
  const [ checklist, setChecklist ] = useState< ChecklistRow[] >( [] );

  /** Review and testing (2026-09-19): the steps beside how to test. */
  const [ testSteps, setTestSteps ] = useState< ChecklistRow[] >( [] );

  /** The links on the task, drafted with everything else. */
  const [ links, setLinks ] = useState< LinkRow[] >( [] );

  /** Whether the dependencies card is open; on when there are any. */
  const [ waiting, setWaiting ] = useState( false );

  /*
   * Every section folds (2026-09-19). Comments, the comment form and the
   * history start folded: they are for reading back, not for doing the work.
   */
  const [ folded, setFolded ] = useState< Record< string, boolean > >( { comments: true, evidence: true } );
  const fold = ( id: string ) => setFolded( { ...folded, [ id ]: ! ( folded[ id ] ?? false ) } );
  const isFolded = ( id: string ) => folded[ id ] ?? false;
  const [ dropping, setDropping ] = useState( false );

  /*
   * The before-items answered here and saved with Save changes (2026-09-18):
   * a pick is a dropdown on its row, a box is a box on the Task card. Both
   * are keyed by requirement id and become gate records when saved.
   */
  const [ picks, setPicks ] = useState< Record< string, string > >( {} );
  const [ boxes, setBoxes ] = useState< Record< string, string > >( {} );

  /** The site's other open items, for the picks that choose one. */
  const [ siteItems, setSiteItems ] = useState< WorkItem[] >( [] );

  /** Every requirement by id, for the boxes answered at an earlier stage. */
  const [ rules, setRules ] = useState< Record< string, Requirement > >( {} );
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
      setChecklist( loaded.item.checklist ?? [] );
      setTestSteps( loaded.item.test_steps ?? [] );
      setLinks( loaded.item.links ?? [] );
      setWaiting( 0 < ( loaded.dependencies?.upstream.length ?? 0 ) );
      setPicks( {} );
      setBoxes( {} );

      // The site's other open items, for the picks that choose one. Read
      // after the item so a slow list never holds the panel up.
      void api< { items: WorkItem[] } >( `/work-items?client_site_id=${ loaded.item.client_site_id }` )
        .then( ( answer ) => setSiteItems( answer.items.filter( ( one ) => one.id !== loaded.item.id && ! one.archived && ( '' === one.terminal_outcome || 'deferred' === one.terminal_outcome ) ) ) )
        .catch( () => setSiteItems( [] ) );
    } catch ( error ) {
      // Told apart deliberately: "we could not load this" and "this is not
      // yours to read" are different problems with different next steps.
      setLoadState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'That item could not be loaded.' ) );
    }
  }

  /**
   * Deleting is the one thing here that leaves no history, which is why only
   * the site's administrator is offered it and the server refuses everyone
   * else regardless. It is for clearing out what should never have existed;
   * work that happened is cancelled or archived instead, and keeps its record.
   */
  async function remove() {
    if ( ! detail ) {
      return;
    }

    const below = detail.children.length;
    const question = 0 < below
      ? `Delete this and the ${ below } item${ 1 === below ? '' : 's' } under it? This cannot be undone.`
      : 'Delete this item? This cannot be undone.';

    if ( ! window.confirm( question ) ) {
      return;
    }

    setBusy( true );

    try {
      await api( `/work-items/${ itemId }`, { method: 'DELETE' } );
      onChanged();
      onClose();
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be deleted.' ) );
    } finally {
      setBusy( false );
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
    void requirements().then( setRules );

    // Focus lands in the panel when it opens, so a keyboard user is not left
    // behind on the board underneath it.
    closer.current?.focus();

    // load() is rebuilt every render, so naming it as a dependency would reload
    // the panel forever. The item's id is the only thing that should reopen it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ itemId ] );

  // A re-check that found something new reloads the panel — unless somebody
  // is mid-edit, in which case their draft wins and the reload waits for the
  // next change. load() replaces the draft, and a field emptying under a
  // person's cursor is worse than a panel a minute behind.
  useLiveReload( () => {
    if ( ! detail || JSON.stringify( draft ) === JSON.stringify( asDraft( detail.item ) ) ) {
      void load();
    }
  } );

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
      setNotice( done, 'ok' );
    } catch ( error ) {
      if ( error instanceof GateError ) {
        setUnmet( error.unmet );
        setChecks( error.checks );

        // Which crossing was refused, so a reason given now is given about the
        // move that was actually attempted.
        setOverrun( { to: error.attempted, reason: '' } );
        setNotice( error.message, 'warn' );
      } else {
        setNotice( messageFor( error, 'That did not work.' ) );
      }
    } finally {
      setBusy( false );
    }
  }

  /** A list of lines, only when it differs from what was read — an unchanged list is not an edit. */
  function linesChange( field: 'checklist' | 'test_steps', rows: ChecklistRow[] ): Record< string, ChecklistRow[] > {
    const kept = rows.filter( ( row ) => '' !== row.text.trim() ).map( ( row ) => ( { text: row.text.trim(), done: row.done } ) );

    return JSON.stringify( kept ) === JSON.stringify( detail?.item[ field ] ?? [] ) ? {} : { [ field ]: kept };
  }

  /** The links, only when they changed. */
  function linksChange(): { links?: LinkRow[] } {
    const kept = links.filter( ( row ) => '' !== row.url.trim() || '' !== row.label.trim() ).map( ( row ) => ( { label: row.label.trim(), url: row.url.trim() } ) );

    return JSON.stringify( kept ) === JSON.stringify( detail?.item.links ?? [] ) ? {} : { links: kept };
  }

  /** What a pick or box already holds on the item, as recorded. */
  const recorded = ( id: string ) => detail?.records[ id ]?.value ?? '';

  /**
   * The picks and boxes that changed, each as a gate record with the answer
   * as its value.
   */
  async function saveAnswers() {
    for ( const [ id, value ] of Object.entries( picks ) ) {
      if ( '' === value || value === recorded( id ) ) {
        continue;
      }

      await api( `/work-items/${ itemId }/gate`, { method: 'POST', body: { requirement: id, value, evidence: '' } } );
    }

    for ( const [ id, value ] of Object.entries( boxes ) ) {
      if ( '' === value.trim() || value === recorded( id ) ) {
        continue;
      }

      await api( `/work-items/${ itemId }/gate`, { method: 'POST', body: { requirement: id, value, evidence: '' } } );
    }
  }

  async function save() {
    if ( ! detail ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    const edits = {
      ...draft,
      ...linesChange( 'checklist', checklist ),
      ...linesChange( 'test_steps', testSteps ),
      ...linksChange(),
    };

    // Only an edit is written as an edit. A save that only answered a pick
    // writes the answer and nothing else, so there is no empty edit in the
    // history and no refusal about a change nobody made.
    const edited = JSON.stringify( edits ) !== JSON.stringify( asDraft( detail.item ) );

    try {
      if ( edited ) {
        await api( `/work-items/${ itemId }`, {
          method: 'PATCH',
          body: { ...edits, record_version: detail.item.record_version },
        } );
      }

      await saveAnswers();
      await load();
      onChanged();
      setNotice( 'Saved.', 'ok' );
    } catch ( error ) {
      setNotice( refusal( error ) );
    } finally {
      setBusy( false );
    }
  }

  /**
   * A pick changing. Most are held until Save changes; two hand over: a
   * triage outcome other than Proceed opens End it with that outcome, and a
   * duplicate found is what End it → duplicate will use.
   */
  function choose( id: string, value: string ) {
    if ( TRIAGE_OUTCOME_PICK === id && '' !== value && 'proceed' !== value ) {
      setShowing( 'end' );
      setEnding( { ...ending, outcome: value } );

      return;
    }

    if ( DUPLICATE_PICK === id && value.startsWith( 'wrk_' ) ) {
      setEnding( { ...ending, duplicate_of: value } );
    }

    setPicks( { ...picks, [ id ]: value } );
  }

  /** Every row the next stages want, once each. */
  function stageRows(): Requirement[] {
    const seen = new Set< string >();
    const rows: Requirement[] = [];

    for ( const to of detail?.available ?? [] ) {
      for ( const row of detail?.readiness[ to ]?.all ?? [] ) {
        if ( ! seen.has( row.id ) ) {
          seen.add( row.id );
          rows.push( row );
        }
      }
    }

    return rows;
  }

  /**
   * The boxes to show on the task: what a next stage wants, and what was
   * answered at an earlier stage and is still worth reading.
   */
  function boxRows(): Requirement[] {
    const wanted = stageRows().filter( ( row ) => 'box' === row.control && ( ! row.met || '' !== recorded( row.id ) ) );
    const shown = new Set( wanted.map( ( row ) => row.id ) );
    const held = Object.keys( detail?.records ?? {} )
      .filter( ( id ) => ! shown.has( id ) && '' !== recorded( id ) && 'box' === rules[ id ]?.control )
      .map( ( id ) => rules[ id ] );

    return [ ...wanted, ...held ];
  }

  /**
   * Whether the signed-in person may answer a requirement that belongs to a
   * seat. Somebody with no Forge person behind them (an administrator) is
   * offered everything and the server decides.
   */
  function allowed( requirement: Requirement ): boolean {
    const me = forgeData()?.person?.id ?? '';
    const it = detail?.item;

    // An administrator acts for anyone (2026-09-19); the server agrees.
    if ( '' === me || ! it || ( forgeData()?.canManage ?? false ) ) {
      return true;
    }

    switch ( requirement.who ) {
      case 'PU':
        return it.primary_user_id === me;
      case 'REV':
        return it.reviewer_id === me || it.reviewer_substitute_id === me;
      case 'DEL':
        return it.deliverer_id === me || it.deliverer_substitute_id === me;
      default:
        return true;
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

  /** Connects this item to one it waits on; written at once, not drafted. */
  async function waitOn( id: string ) {
    if ( '' === id ) {
      return;
    }

    setBusy( true );

    try {
      await api( `/work-items/${ itemId }/dependencies`, { method: 'POST', body: { depends_on_id: id } } );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be connected.' ) );
    } finally {
      setBusy( false );
    }
  }

  /** Disconnects one dependency, or all of them when the card is switched off. */
  async function stopWaiting( rows: DependencyRow[] ) {
    setBusy( true );

    try {
      for ( const row of rows ) {
        await api( `/work-items/${ itemId }/dependencies/${ row.dependency_id }`, { method: 'DELETE' } );
      }

      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be disconnected.' ) );
    } finally {
      setBusy( false );
    }
  }

  /** Adds dropped or chosen images, one upload each. */
  async function addImages( files: FileList | File[] ) {
    const chosen = Array.from( files ).filter( ( file ) => file.type.startsWith( 'image/' ) );

    if ( 0 === chosen.length ) {
      return;
    }

    setBusy( true );

    try {
      for ( const file of chosen ) {
        const form = new FormData();
        form.append( 'image', file, file.name );
        await api( `/work-items/${ itemId }/images`, { method: 'POST', body: form } );
      }

      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That image could not be added.' ) );
    } finally {
      setBusy( false );
    }
  }

  async function removeImage( id: number ) {
    setBusy( true );

    try {
      await api( `/work-items/${ itemId }/images/${ id }`, { method: 'DELETE' } );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That image could not be removed.' ) );
    } finally {
      setBusy( false );
    }
  }

  /** One person's tick on a chore. */
  async function tickFor( who: string, done: boolean ) {
    setBusy( true );

    try {
      await api( `/work-items/${ itemId }/tick`, { method: 'POST', body: { user_id: who, done } } );
      await load();
      onChanged();
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be ticked.' ) );
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

  // A recurring chore moves by its ticks (2026-09-18): no gate to read, no
  // stage to choose. Up Next until everyone has done it, then Completed.
  const chore = 0 < ( item?.assignees?.length ?? 0 );
  const ended = undefined !== item && '' !== item.terminal_outcome && 'deferred' !== item.terminal_outcome;
  const lines = detail ? historyLines( detail.history, label ) : [];

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

    if ( 'test_description' === field ) {
      return '' === value.replace( /<[^>]+>/g, '' ).trim();
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

  /**
   * Takes the person to a field's control (2026-09-19): a before-row says
   * what is missing, and this unfolds the section holding it and lands on
   * it, so nobody has to know which card the answer lives on.
   */
  const reveal = ( field: string ) => {
    const section = ( ASSIGNMENT as readonly string[] ).includes( field )
      ? 'assign'
      : [ 'test_description', 'test_steps' ].includes( field )
        ? 'testing'
        : 'comment-url' === field ? 'evidence' : 'task';

    setFolded( { ...folded, [ section ]: false } );
    window.setTimeout( () => {
      const target = document.getElementById( `bwx-${ field }` );
      target?.scrollIntoView( { block: 'center', behavior: 'smooth' } );
      ( target as HTMLElement | null )?.focus?.( { preventScroll: true } );
    }, 50 );
  };

  /** A section's head: its name, and the fold. */
  const head = ( id: string, title: ReactNode ) => (
    <button
      type="button"
      className="bwx-eyebrow bwx-section-head"
      aria-expanded={ ! isFolded( id ) }
      data-testid={ `bwx-section-${ id }` }
      onClick={ () => fold( id ) }
    >
      <span className="bwx-section-caret" aria-hidden="true" />
      { title }
    </button>
  );

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

  /**
   * The earliest a date may be: the latest of the dates before it that are
   * set. The server refuses dates out of order; this stops the picker
   * offering them.
   */
  const earliestFor = ( field: string ): string | undefined => {
    const before = DATES.slice( 0, DATES.findIndex( ( date ) => date.field === field ) );
    const set = before.map( ( date ) => draft[ date.field ] ?? '' ).filter( ( value ) => '' !== value );

    return 0 < set.length ? set.sort().at( -1 ) : undefined;
  };

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
        className="bwx-panel bwx-panel--framed"
        role="dialog"
        aria-modal="true"
        aria-label="Work item"
        data-testid="bwx-panel"
        onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
      >
        <header className="bwx-panel-head">
          <div style={ { flex: 1, minWidth: 0 } }>
            <h2 className="bwx-panel-title">{ item?.title ?? '' }</h2>
            <div className="bwx-panel-marks">
              <p className="fk-chip bwx-panel-stage" data-phase={ item ? phaseOf( item.stage ) : undefined } data-testid="bwx-panel-stage">
                <span className="fk-dot" aria-hidden="true" />
                { item ? label( item.stage ) : 'Loading' }
                { ended && ` · ${ item.terminal_label }` }
                { item?.archived && ' · archived' }
              </p>
              { item && (
                <span className="bwx-mono bwx-panel-meta">
                  { item.id.replace( 'wrk_', '' ).slice( 0, 8 ) }
                  { item.planned_due && ` · due ${ item.planned_due }` }
                  { 0 < item.blocked_elapsed && ` · blocked ${ forHowLong( item.blocked_elapsed ) }` }
                </span>
              ) }
            </div>
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

        <div className="bwx-panel-body">

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
          <Notice
            said={ banner }
            testId="bwx-panel-notice"
            onClose={ () => {
              setSaid( NOTHING_SAID );
              setUnmet( [] );
              setChecks( [] );
            } }
          />
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
                <div className="bwx-moves bwx-form-foot">
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

            { /*
                A recurring chore's people (2026-09-18): each with their own
                tick. The signed-in person ticks their own; an administrator
                may tick for anyone. When everyone has, the task is done.
             */ }
            { 0 < ( item.assignees?.length ?? 0 ) && (
              <div className="bwx-chore" data-testid="bwx-chore">
                <p className="bwx-eyebrow">{ forgeData()?.canManage ? 'Who does it' : 'Your tick' }</p>
                <ul className="bwx-chore-people">
                  { item.assignees.filter( ( who ) => ( forgeData()?.canManage ?? false ) || who === ( forgeData()?.person?.id ?? '' ) ).map( ( who ) => {
                    const done = undefined !== ( item.ticks ?? {} )[ who ];
                    const me = forgeData()?.person?.id ?? '';
                    const may = ! ended && ( who === me || ( forgeData()?.canManage ?? false ) );

                    return (
                      <li key={ who } data-testid="bwx-chore-person" data-done={ done ? 'true' : 'false' }>
                        <span>{ staffList.find( ( one ) => one.id === who )?.display_name ?? who }</span>
                        { may ? (
                          <label className="bwx-chore-tick">
                            <input
                              type="checkbox"
                              data-testid="bwx-chore-tick"
                              aria-label={ `Done by ${ staffList.find( ( one ) => one.id === who )?.display_name ?? who }` }
                              checked={ done }
                              disabled={ busy }
                              onChange={ ( event ) => void tickFor( who, event.target.checked ) }
                            />
                            { done ? 'Done' : 'Not yet' }
                          </label>
                        ) : (
                          <span className="bwx-mono">{ done ? '✓ done' : '○ not yet' }</span>
                        ) }
                      </li>
                    );
                  } ) }
                </ul>
                { 0 < item.hours_each && <p className="bwx-hint">{ `${ item.hours_each } hours each.` }</p> }
              </div>
            ) }

            { ! blocked && ! ended && ! chore && detail.available.map( ( to ) => (
              <GateList
                key={ to }
                heading={ `Before ${ label( to ) }` }
                readiness={ detail.readiness[ to ] }
                records={ detail.records }
                busy={ busy }
                onComplete={ complete }
                picks={ picks }
                onPick={ choose }
                items={ siteItems }
                people={ staffList }
                allowed={ allowed }
                onReveal={ reveal }
              />
            ) ) }

            { /*
                One place for everything that moves the work (2026-09-19):
                forward on the right, the other directions on the left. Each
                button appears only when it applies.
             */ }
            { ( ! ended || detail.can_archive ) && (
              <div className="bwx-actions" data-testid="bwx-actions" data-collapsed={ isFolded( 'actions' ) ? 'true' : 'false' }>
                { head( 'actions', 'Actions' ) }
                <div className="bwx-action-row">
                <div className="bwx-moves bwx-toggles bwx-action-left">
                  { ! ended && ! blocked && 0 < detail.returns.length && (
                    <button
                      type="button"
                      className="bwx-button bwx-toggle"
                      data-tone="return"
                      data-testid="bwx-show-return"
                      aria-pressed={ 'return' === showing }
                      onClick={ () => setShowing( 'return' === showing ? '' : 'return' ) }
                    >
                      <span aria-hidden="true">←</span> Send back
                    </button>
                  ) }
                  { ! ended && ! blocked && (
                    <button
                      type="button"
                      className="bwx-button bwx-toggle"
                      data-tone="block"
                      data-testid="bwx-show-block"
                      aria-pressed={ 'block' === showing }
                      onClick={ () => setShowing( 'block' === showing ? '' : 'block' ) }
                    >
                      <span aria-hidden="true">⏸</span> Block
                    </button>
                  ) }
                  { ! ended && 0 < detail.outcomes.length && (
                    <button
                      type="button"
                      className="bwx-button bwx-toggle"
                      data-tone="end"
                      data-testid="bwx-show-end"
                      aria-pressed={ 'end' === showing }
                      onClick={ () => setShowing( 'end' === showing ? '' : 'end' ) }
                    >
                      End it <span aria-hidden="true">→</span>
                    </button>
                  ) }
                  { detail.can_archive && (
                    <button
                      type="button"
                      className="bwx-button"
                      data-variant="quiet"
                      data-testid="bwx-archive"
                      disabled={ busy }
                      onClick={ () => void act( '/archive', {}, 'Archived. It stays in the reports.' ) }
                    >
                      Archive
                    </button>
                  ) }
                </div>
                { ! blocked && ! ended && ! chore && (
                  <div className="bwx-moves bwx-action-right">
                    { detail.available.map( ( to ) => (
                      <button
                        key={ to }
                        type="button"
                        className="bwx-button bwx-move"
                        data-testid="bwx-move"
                        data-to={ to }
                        data-ready={ 0 === ( detail.readiness[ to ]?.unmet.length ?? 0 ) ? 'true' : 'false' }
                        disabled={ busy }
                        style={
                          { borderColor: `var(--phase-${ phaseOf( to ) })` } as React.CSSProperties
                        }
                        onClick={ () => void act( '/transition', { to }, `Moved to ${ label( to ) }.` ) }
                      >
                        { label( to ) } <span aria-hidden="true">→</span>
                        { 0 < ( detail.readiness[ to ]?.unmet.length ?? 0 ) && (
                          <span className="bwx-mono"> · { detail.readiness[ to ].unmet.length } to do</span>
                        ) }
                      </button>
                    ) ) }
                    { 0 === detail.available.length && (
                      <Inline state="empty">This is the end of the road.</Inline>
                    ) }
                  </div>
                ) }
                </div>

            { 'return' === showing && (
              <div className="bwx-actions-form" data-testid="bwx-return">
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
                <div className="bwx-moves bwx-form-foot">
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
              <div className="bwx-actions-form" data-testid="bwx-block">
                { /*
                    The Block form picks (2026-09-18): the blocker and what it
                    waits on are one of the site's items or something else in
                    words; the owner is one of our people or the client. The
                    server stores the label either way. The owner comes first and
                    is the only answer needed; there is no next action
                    (2026-09-24).
                 */ }
                <div className="bwx-field">
                  <label htmlFor="bwx-blocker-owner">Who owns the blocker</label>
                  <select
                    id="bwx-blocker-owner"
                    className="bwx-select"
                    data-testid="bwx-blocker-owner"
                    required
                    value={ blocker.owner ?? '' }
                    onChange={ ( event ) => setBlocker( { ...blocker, owner: event.target.value } ) }
                  >
                    <option value="">Choose</option>
                    <option value="client">The client</option>
                    { staffList.map( ( person ) => (
                      <option key={ person.id } value={ person.id }>
                        { person.display_name }
                      </option>
                    ) ) }
                  </select>
                </div>
                { [
                  { field: 'reason', name: 'What is blocking it' },
                  { field: 'dependency', name: 'What it is waiting on' },
                ].map( ( { field, name } ) => (
                  <div className="bwx-field" key={ field }>
                    <label htmlFor={ `bwx-blocker-${ field }` }>{ name } (optional)</label>
                    <select
                      id={ `bwx-blocker-${ field }` }
                      className="bwx-select"
                      data-testid={ `bwx-blocker-${ field }` }
                      value={ blocker[ field ] ?? '' }
                      onChange={ ( event ) => setBlocker( { ...blocker, [ field ]: event.target.value } ) }
                    >
                      <option value="">Choose</option>
                      <option value="other">Something else</option>
                      { siteItems.map( ( one ) => (
                        <option key={ one.id } value={ one.id }>
                          { one.title }
                        </option>
                      ) ) }
                    </select>
                    { 'other' === blocker[ field ] && (
                      <input
                        className="bwx-input"
                        data-testid={ `bwx-blocker-${ field }-text` }
                        aria-label={ `${ name } — what` }
                        placeholder="Say what"
                        value={ blocker[ `${ field }_text` ] ?? '' }
                        onChange={ ( event ) => setBlocker( { ...blocker, [ `${ field }_text` ]: event.target.value } ) }
                      />
                    ) }
                  </div>
                ) ) }
                <div className="bwx-field">
                  <label htmlFor="bwx-blocker-target_date">Target resolution date (optional)</label>
                  <input
                    id="bwx-blocker-target_date"
                    className="bwx-input"
                    data-testid="bwx-blocker-target_date"
                    type="date"
                    value={ blocker.target_date ?? '' }
                    onChange={ ( event ) => setBlocker( { ...blocker, target_date: event.target.value } ) }
                  />
                </div>
                <div className="bwx-moves bwx-form-foot">
                  <button
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-block"
                    disabled={ busy || '' === ( blocker.owner ?? '' ) }
                    onClick={ () =>
                      void act(
                        '/block',
                        {
                          reason: 'other' === blocker.reason ? blocker.reason_text ?? '' : blocker.reason ?? '',
                          owner: blocker.owner ?? '',
                          dependency: 'other' === blocker.dependency ? blocker.dependency_text ?? '' : blocker.dependency ?? '',
                          target_date: blocker.target_date ?? '',
                        },
                        'Blocked. Its place is kept.'
                      )
                    }
                  >
                    Block it
                  </button>
                </div>
              </div>
            ) }

            { 'end' === showing && (
              <div className="bwx-actions-form" data-testid="bwx-end">
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
                    <select
                      id="bwx-duplicate"
                      className="bwx-select"
                      data-testid="bwx-duplicate"
                      value={ ending.duplicate_of }
                      onChange={ ( event ) =>
                        setEnding( { ...ending, duplicate_of: event.target.value } )
                      }
                    >
                      <option value="">Choose the item</option>
                      { siteItems.map( ( one ) => (
                        <option key={ one.id } value={ one.id }>
                          { one.title }
                        </option>
                      ) ) }
                    </select>
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
                <div className="bwx-moves bwx-form-foot">
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
              </div>
            ) }

            <div className="bwx-task" data-testid="bwx-task" data-collapsed={ isFolded( 'task' ) ? 'true' : 'false' }>
            { head( 'task', 'Task' ) }
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
                  <RichText
                    id={ `bwx-${ field }` }
                    testId={ `bwx-${ field }` }
                    label={ name }
                    value={ draft[ field ] ?? '' }
                    onChange={ ( html ) => setDraft( { ...draft, [ field ]: html } ) }
                  />
                ) }
              </div>
            ) ) }

            { /*
                The checklist: up to ten one-line items, ticked here and saved
                with everything else. Enter on a line starts the next; the
                count says how many of the ten are used.
             */ }
            <LineList name="Checklist" testId="bwx-checklist" rows={ checklist } onChange={ setChecklist } />

            { /*
                The definition boxes, once the item has got as far as the
                stage that wants them or when they hold something already.
             */ }
            { DEFINITION_BOXES.filter( ( box ) => ( ! chore && reached( box.shown ) ) || '' !== ( draft[ box.field ] ?? '' ) ).map( ( { field, label: name, lines } ) => (
              <div className="bwx-field" key={ field }>
                { naming( field, name ) }
                { 1 === lines ? (
                  <input
                    id={ `bwx-${ field }` }
                    className="bwx-input"
                    data-testid={ `bwx-${ field }` }
                    type="url"
                    placeholder="https://"
                    value={ draft[ field ] ?? '' }
                    onChange={ ( event ) => setDraft( { ...draft, [ field ]: event.target.value } ) }
                  />
                ) : (
                  <textarea
                    id={ `bwx-${ field }` }
                    className="bwx-textarea"
                    data-testid={ `bwx-${ field }` }
                    value={ draft[ field ] ?? '' }
                    onChange={ ( event ) => setDraft( { ...draft, [ field ]: event.target.value } ) }
                  />
                ) }
              </div>
            ) ) }

            { /*
                Links and images (2026-09-19), from the documentation period
                on. Both optional: a link is a label and an address, saved
                with everything else; an image goes up as soon as it is
                dropped, because a file is not a draft.
             */ }
            { staff && ! chore && ( reached( 'documentation-period' ) || 0 < links.length ) && (
              <div className="bwx-field bwx-links" data-testid="bwx-links">
                <span className="bwx-checklist-head">
                  <span>Links</span>
                  { 0 < links.length && <span className="bwx-mono">{ `${ links.length } of ${ CHECKLIST_ROWS }` }</span> }
                </span>
                { links.map( ( row, at ) => (
                  <div className="bwx-link-row" data-testid="bwx-link-row" key={ at }>
                    <input
                      className="bwx-input"
                      data-testid="bwx-link-label"
                      aria-label={ `Link ${ at + 1 } label` }
                      placeholder="What it is"
                      maxLength={ 191 }
                      value={ row.label }
                      onChange={ ( event ) => setLinks( links.map( ( one, i ) => ( i === at ? { ...one, label: event.target.value } : one ) ) ) }
                    />
                    <input
                      className="bwx-input"
                      data-testid="bwx-link-url"
                      aria-label={ `Link ${ at + 1 } address` }
                      type="url"
                      placeholder="https://"
                      value={ row.url }
                      onChange={ ( event ) => setLinks( links.map( ( one, i ) => ( i === at ? { ...one, url: event.target.value } : one ) ) ) }
                    />
                    <button
                      type="button"
                      className="bwx-icon-button"
                      aria-label={ `Remove link ${ at + 1 }` }
                      onClick={ () => setLinks( links.filter( ( _, i ) => i !== at ) ) }
                    >
                      ✕
                    </button>
                  </div>
                ) ) }
                { links.length < CHECKLIST_ROWS && (
                  <div className="bwx-moves">
                    <button
                      type="button"
                      className="bwx-button"
                      data-variant="quiet"
                      data-testid="bwx-link-add"
                      onClick={ () => setLinks( [ ...links, { label: '', url: '' } ] ) }
                    >
                      Add a link
                    </button>
                  </div>
                ) }
              </div>
            ) }

            { staff && ! chore && ( reached( 'documentation-period' ) || 0 < item.images.length ) && (
              <div className="bwx-field" data-testid="bwx-images">
                <span className="bwx-checklist-head">
                  <span>Images</span>
                  { 0 < item.images.length && <span className="bwx-mono">{ `${ item.images.length } of ${ CHECKLIST_ROWS }` }</span> }
                </span>
                { 0 < item.images.length && (
                  <ul className="bwx-image-list">
                    { item.images.map( ( image ) => (
                      <li key={ image.id } data-testid="bwx-image">
                        <a href={ image.url } target="_blank" rel="noreferrer noopener">
                          <img src={ image.url } alt={ image.name } />
                        </a>
                        <button
                          type="button"
                          className="bwx-icon-button"
                          aria-label={ `Remove ${ image.name || 'image' }` }
                          disabled={ busy }
                          onClick={ () => void removeImage( image.id ) }
                        >
                          ✕
                        </button>
                      </li>
                    ) ) }
                  </ul>
                ) }
                { item.images.length < CHECKLIST_ROWS && (
                  <label
                    className="bwx-drop"
                    data-testid="bwx-image-drop"
                    data-over={ dropping ? 'true' : 'false' }
                    onDragOver={ ( event ) => {
                      event.preventDefault();
                      setDropping( true );
                    } }
                    onDragLeave={ () => setDropping( false ) }
                    onDrop={ ( event ) => {
                      event.preventDefault();
                      setDropping( false );
                      void addImages( event.dataTransfer.files );
                    } }
                  >
                    <input
                      type="file"
                      accept="image/*"
                      multiple
                      data-testid="bwx-image-input"
                      disabled={ busy }
                      onChange={ ( event ) => {
                        if ( event.target.files ) {
                          void addImages( event.target.files );
                        }

                        event.target.value = '';
                      } }
                    />
                    <span>Drop images or screenshots here, or choose some.</span>
                  </label>
                ) }
              </div>
            ) }

            { /*
                The stage boxes (2026-09-18): every before-item that wants
                words, a number or a date, shown only while a next stage asks
                for it or once it holds an answer. Saved with Save changes as
                a gate record, so who answered and when is kept.
             */ }
            { ! chore && boxRows().map( ( row ) => (
              <StageBox
                key={ row.id }
                row={ row }
                value={ boxes[ row.id ] ?? recorded( row.id ) }
                allowed={ allowed( row ) }
                onChange={ ( value ) => setBoxes( { ...boxes, [ row.id ]: value } ) }
              />
            ) ) }
            </div>

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
            { /*
                Review and testing (2026-09-19): how the reviewer tests it,
                written during development and read at review. A card of its
                own so the reviewer finds it without reading the task.
             */ }
            { staff && ! chore && ( reached( 'in-development' ) || '' !== ( draft.test_description ?? '' ) ) && (
              <div className="bwx-testing" data-testid="bwx-testing" data-collapsed={ isFolded( 'testing' ) ? 'true' : 'false' }>
                { head(
                  'testing',
                  <>
                    Review and testing
                    { 'in-review' === item.stage && <span className="bwx-mono"> · for the reviewer</span> }
                  </>
                ) }
                <div className="bwx-field">
                  { naming( 'test_description', 'How to test it' ) }
                  <RichText
                    id="bwx-test_description"
                    testId="bwx-test_description"
                    label="How to test it"
                    value={ draft.test_description ?? '' }
                    onChange={ ( html ) => setDraft( { ...draft, test_description: html } ) }
                  />
                </div>
                <LineList name="Test steps" testId="bwx-test-steps" rows={ testSteps } onChange={ setTestSteps } />
              </div>
            ) }

            { /*
                Dependencies (2026-09-19): a switch, off until this waits on
                something. On, it lists what this waits on and offers the
                site's other items; off again, it lets go of them all.
             */ }
            { staff && ! chore && ! ended && (
              <div className="bwx-waiting" data-testid="bwx-dependencies">
                <div className="bwx-switch-row">
                  <p className="bwx-eyebrow">Dependencies</p>
                  <label className="bwx-switch">
                    <input
                      type="checkbox"
                      role="switch"
                      data-testid="bwx-dependencies-toggle"
                      checked={ waiting }
                      disabled={ busy }
                      onChange={ ( event ) => {
                        setWaiting( event.target.checked );

                        if ( ! event.target.checked && 0 < detail.dependencies.upstream.length ) {
                          void stopWaiting( detail.dependencies.upstream );
                        }
                      } }
                    />
                    <span className="bwx-switch-track" aria-hidden="true" />
                    <span className="bwx-switch-text">{ waiting ? 'Waits on other work' : 'Nothing to wait on' }</span>
                  </label>
                </div>
                { waiting && (
                  <div className="bwx-waiting-body">
                    { 0 < detail.dependencies.upstream.length && (
                      <ul className="bwx-waiting-list" data-testid="bwx-dependency-list">
                        { detail.dependencies.upstream.map( ( row ) => (
                          <li key={ row.dependency_id } data-testid="bwx-dependency" data-item={ row.id }>
                            <span className="fk-chip" data-phase={ phaseOf( row.stage ) }>
                              <span className="fk-dot" aria-hidden="true" />
                              { label( row.stage ) }
                            </span>
                            <span className="bwx-waiting-title">{ row.title }</span>
                            <button
                              type="button"
                              className="bwx-icon-button"
                              aria-label={ `Stop waiting on ${ row.title }` }
                              disabled={ busy }
                              onClick={ () => void stopWaiting( [ row ] ) }
                            >
                              ✕
                            </button>
                          </li>
                        ) ) }
                      </ul>
                    ) }
                    <div className="bwx-field">
                      <label htmlFor="bwx-dependency-add">Waits on</label>
                      <select
                        id="bwx-dependency-add"
                        className="bwx-select"
                        data-testid="bwx-dependency-add"
                        value=""
                        disabled={ busy }
                        onChange={ ( event ) => void waitOn( event.target.value ) }
                      >
                        <option value="">Choose an item on this site</option>
                        { siteItems
                          .filter( ( one ) => ! detail.dependencies.upstream.some( ( row ) => row.id === one.id ) )
                          .map( ( one ) => (
                            <option key={ one.id } value={ one.id }>
                              { one.title }
                            </option>
                          ) ) }
                      </select>
                    </div>
                    { 0 < detail.dependencies.downstream.length && (
                      <p className="bwx-hint">
                        { `Waited on by ${ detail.dependencies.downstream.map( ( row ) => row.title ).join( ', ' ) }.` }
                      </p>
                    ) }
                  </div>
                ) }
              </div>
            ) }

            { staff && ! ended && (
              <div className="bwx-assign" data-testid="bwx-assign" data-collapsed={ isFolded( 'assign' ) ? 'true' : 'false' }>
                { head( 'assign', 'Who and when' ) }

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

                { 0 === ( item.assignees?.length ?? 0 ) && SEATS.map( ( seat ) => (
                  <div className="bwx-seat" key={ seat.field }>
                    { pick( seat.field, seat.label ) }
                    <div className="bwx-field">
                      { naming( seat.hours, 'Hours' ) }
                      <HoursSelect
                        id={ `bwx-${ seat.hours }` }
                        className="bwx-select"
                        value={ draft[ seat.hours ] ?? '' }
                        onChange={ ( value ) => setDraft( { ...draft, [ seat.hours ]: value } ) }
                      />
                    </div>
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
                        min={ earliestFor( date.field ) }
                        value={ draft[ date.field ] ?? '' }
                        onChange={ ( event ) =>
                          setDraft( { ...draft, [ date.field ]: event.target.value } )
                        }
                      />
                    </div>
                  ) ) }
                </div>

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

            <div className="bwx-comments-card" data-testid="bwx-comments-card" data-collapsed={ isFolded( 'comments' ) ? 'true' : 'false' }>
              { head(
                'comments',
                <>
                  Comments
                  <span className="bwx-mono"> · { detail.comments.length }</span>
                  { staff && <span className="bwx-mono"> · you see internal notes</span> }
                </>
              ) }

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
            </div>

            <div className="bwx-evidence-card" data-testid="bwx-comment-form" data-collapsed={ isFolded( 'evidence' ) ? 'true' : 'false' }>
              { head( 'evidence', 'Comments and evidence' ) }

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
              <div className="bwx-moves bwx-form-foot">
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

            <details className="bwx-history-wrap" data-testid="bwx-history-wrap">
              <summary className="bwx-eyebrow">
                History
                <span className="bwx-mono"> · { lines.length }</span>
              </summary>
              <ul className="bwx-history" data-testid="bwx-history">
                { lines.map( ( line ) => (
                  <li key={ line.id }>
                    <span className="bwx-history-what">
                      { line.text }
                      { '' !== line.reason && <span> — { line.reason }</span> }
                      <span className="bwx-mono"> { when( line.at ) }</span>
                    </span>
                    <span className="bwx-history-who" data-testid="bwx-history-who">
                      { line.who }
                    </span>
                  </li>
                ) ) }
              </ul>
              { 0 < item.blocked_elapsed && (
                <p className="bwx-mono" data-testid="bwx-blocked-elapsed">
                  Blocked for { forHowLong( item.blocked_elapsed ) } in total.
                </p>
              ) }
            </details>
          </>
        ) }
        </div>

        <footer className="bwx-panel-foot" data-testid="bwx-panel-foot">
          { detail && forgeData()?.canManage ? (
            <button
              type="button"
              className="bwx-button"
              data-variant="danger"
              data-testid="bwx-item-delete"
              disabled={ busy }
              onClick={ () => void remove() }
            >
              Delete
            </button>
          ) : (
            <span />
          ) }
          { detail && item && staff && ! ended && (
            <button
              type="button"
              className="bwx-button"
              data-testid="bwx-save"
              disabled={ busy }
              onClick={ () => void save() }
            >
              Save changes
            </button>
          ) }
        </footer>
      </aside>
    </div>
  );
}

/** What the panel calls a field when history says it was edited. */
const FIELD_LABELS: Record< string, string > = {
  title: 'title',
  problem: 'description',
  acceptance_criteria: 'completed when',
  commercial_class: 'who pays',
  delivered_by_forge: 'who pays',
  priority: 'priority',
  primary_user_id: 'who does it',
  reviewer_id: 'who reviews it',
  deliverer_id: 'who delivers it',
  hours_primary: 'hours',
  hours_review: 'review hours',
  hours_delivery: 'delivery hours',
  planned_start: 'start',
  planned_due: 'due',
  review_target: 'review by',
  release_target: 'release by',
  design_url: 'design link',
  test_description: 'how to test it',
  test_steps: 'test steps',
  links: 'links',
  release_method: 'how it was released',
  release_destination: 'where it went',
  dependencies: 'dependencies',
  checklist: 'checklist',
};

interface HistoryLine {
  id: string;
  text: string;
  reason: string;
  at: number;
  who: string;
}

/**
 * History as lines to read: every real event on its own, and the field edits
 * of one save folded into one line — a save that touched five fields is one
 * thing that happened, not five, and none of them was a move.
 */
function historyLines( events: WorkEvent[], label: ( id: string ) => string ): HistoryLine[] {
  const lines: HistoryLine[] = [];
  let open: { line: HistoryLine; fields: string[] } | null = null;

  const who = ( event: WorkEvent ) => event.actor_name || 'Forge';
  const wording = ( fields: string[] ) => {
    const names = Array.from( new Set( fields.map( ( field ) => FIELD_LABELS[ field ] ?? field.replace( /_/g, ' ' ) ) ) );
    const shown = names.slice( 0, 4 );
    const more = names.length - shown.length;

    return `Edited ${ shown.join( ', ' ) }${ 0 < more ? ` and ${ more } more` : '' }`;
  };

  for ( const event of events ) {
    if ( 'edited' === event.action ) {
      if ( open && open.line.who === who( event ) && 2 >= Math.abs( event.occurred_at - open.line.at ) ) {
        open.fields.push( event.field );
        open.line.text = wording( open.fields );
        continue;
      }

      open = { line: { id: event.id, text: wording( [ event.field ] ), reason: '', at: event.occurred_at, who: who( event ) }, fields: [ event.field ] };
      lines.push( open.line );
      continue;
    }

    open = null;
    lines.push( { id: event.id, text: describe( event, label ), reason: event.reason, at: event.occurred_at, who: who( event ) } );
  }

  return lines;
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
    case 'placed':
      // A recurring task or a renewal reminder, put straight where it is
      // worked from. The reason beside it says which and for what day.
      return `Placed in ${ label( event.to_stage ) } by the schedule`;
    case 'over-allocated':
      // CAP-4. The reason sits beside it in the entry, so the line says what
      // was done and the reason says why.
      return 'Somebody was over-booked on purpose';
    default:
      return `${ label( event.from_stage ) } → ${ label( event.to_stage ) }`;
  }
}

/**
 * Where a before-row's "Go to" lands: the first field it names, the comment
 * form for evidence, the hours boxes for the planned hours. Empty when there
 * is nowhere to go — a pick answers on its row, a system check answers itself.
 */
function goesTo( requirement: Requirement ): string {
  if ( requirement.evidence ) {
    return 'comment-url';
  }

  if ( 'hours' === requirement.check ) {
    return 'hours_primary';
  }

  return 'field' === requirement.by && 0 < requirement.fields.length ? requirement.fields[ 0 ] : '';
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
  picks = {},
  onPick,
  items = [],
  people = [],
  allowed = () => true,
  onReveal,
}: {
  heading: string;
  readiness?: Readiness;
  records: Record< string, GateRecord >;
  busy: boolean;
  onComplete: ( requirement: Requirement, value: string, evidence: string ) => Promise< void >;
  /** Picks drafted and not yet saved; with onPick, a pick waits for Save changes. */
  picks?: Record< string, string >;
  /** Where a pick goes. Absent, it is recorded the moment it is made. */
  onPick?: ( id: string, value: string ) => void;
  /** The site's other items, for the picks that choose one. */
  items?: WorkItem[];
  /** Our people, for the picks that choose one. */
  people?: Person[];
  /** Whether the signed-in person may answer a requirement that belongs to a seat. */
  allowed?: ( requirement: Requirement ) => boolean;
  /** Where a field-answered row takes the person, when the panel can. */
  onReveal?: ( field: string ) => void;
} ) {
  if ( ! readiness || 0 === readiness.unmet.length ) {
    return null;
  }

  // Every row when the gate came whole, otherwise only what is left of it.
  const rows = readiness.all ?? readiness.unmet;

  return (
    <div data-testid="bwx-gate">
      <p className="bwx-eyebrow">{ heading }</p>
      <ul className="bwx-unmet">
        { rows.map( ( requirement ) => {
          const met = requirement.met ?? false;
          const isPick = 'record' === requirement.by && 'pick' === requirement.control;
          const value = picks[ requirement.id ] ?? records[ requirement.id ]?.value ?? '';

          return (
            <li key={ requirement.id } data-requirement={ requirement.id } data-met={ met ? 'true' : 'false' }>
              <span className="bwx-unmet-label">{ requirement.label }</span>
              { ! met && <span className="bwx-unmet-how">{ requirement.satisfied_by }</span> }

              { /*
                  A pick is answered on its row. A box is answered on the
                  Task card and a field further up the form; an auto item is
                  answered by the task itself — none of those gets a control
                  here, and their sentence says what would meet them.
               */ }
              { ! met && isPick && ! allowed( requirement ) && (
                <span className="bwx-unmet-who">{ FOR_WHOM[ requirement.who ] ?? '' }</span>
              ) }
              { ! met && onReveal && '' !== goesTo( requirement ) && (
                <button
                  type="button"
                  className="bwx-button"
                  data-variant="quiet"
                  data-testid="bwx-unmet-go"
                  aria-label={ `Go to ${ requirement.label }` }
                  onClick={ () => onReveal( goesTo( requirement ) ) }
                >
                  Go to
                </button>
              ) }
              { ! met && isPick && allowed( requirement ) && (
                <select
                  className="bwx-select"
                  data-testid="bwx-pick"
                  aria-label={ requirement.label }
                  disabled={ busy }
                  value={ value }
                  onChange={ ( event ) => {
                    if ( onPick ) {
                      onPick( requirement.id, event.target.value );
                    } else if ( '' !== event.target.value ) {
                      void onComplete( requirement, event.target.value, '' );
                    }
                  } }
                >
                  <option value="">{ 1 === ( requirement.options?.length ?? 0 ) ? 'Not yet' : 'Choose' }</option>
                  { ( requirement.options ?? [] ).map( ( option ) => (
                    <option key={ option.value } value={ option.value }>
                      { option.label }
                    </option>
                  ) ) }
                  { 'items' === requirement.source && items.map( ( one ) => (
                    <option key={ one.id } value={ one.id }>
                      { one.title }
                    </option>
                  ) ) }
                  { 'people' === requirement.source && people.map( ( person ) => (
                    <option key={ person.id } value={ person.id }>
                      { person.display_name }
                    </option>
                  ) ) }
                </select>
              ) }
            </li>
          );
        } ) }
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
 * One stage box: a before-item answered in words, a number, a date, a date and
 * time, or a low and a high estimate.
 */
function StageBox( {
  row,
  value,
  allowed,
  onChange,
}: {
  row: Requirement;
  value: string;
  allowed: boolean;
  onChange: ( value: string ) => void;
} ) {
  const id = `bwx-box-${ row.id }`;
  const kind = row.input ?? 'text';

  if ( 'range' === kind ) {
    const [ low = '', high = '' ] = value.split( '-' );

    return (
      <div className="bwx-field">
        <label htmlFor={ id }>{ row.label }</label>
        <div className="bwx-pair">
          <input
            id={ id }
            className="bwx-input"
            data-testid={ id }
            type="number"
            min="0"
            step="0.5"
            aria-label={ `${ row.label } — low` }
            placeholder="Low"
            disabled={ ! allowed }
            value={ low }
            onChange={ ( event ) => onChange( `${ event.target.value }-${ high }` ) }
          />
          <input
            className="bwx-input"
            data-testid={ `${ id }-high` }
            type="number"
            min="0"
            step="0.5"
            aria-label={ `${ row.label } — high` }
            placeholder="High"
            disabled={ ! allowed }
            value={ high }
            onChange={ ( event ) => onChange( `${ low }-${ event.target.value }` ) }
          />
        </div>
      </div>
    );
  }

  const type = { number: 'number', date: 'date', datetime: 'datetime-local' }[ kind ];

  return (
    <div className="bwx-field">
      <label htmlFor={ id }>
        { row.label }
        { ! allowed && <span className="bwx-needed">{ `(${ ( FOR_WHOM[ row.who ] ?? '' ).toLowerCase() })` }</span> }
      </label>
      { type ? (
        <input
          id={ id }
          className="bwx-input"
          data-testid={ id }
          type={ type }
          step={ 'number' === type ? '0.5' : undefined }
          disabled={ ! allowed }
          value={ value }
          onChange={ ( event ) => onChange( event.target.value ) }
        />
      ) : (
        <textarea
          id={ id }
          className="bwx-textarea"
          data-testid={ id }
          disabled={ ! allowed }
          value={ value }
          onChange={ ( event ) => onChange( event.target.value ) }
        />
      ) }
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

/**
 * A list of up to ten one-line items, each with a tick: the task's checklist,
 * or the steps that test it. Enter on a line starts the next; Backspace on an
 * empty one removes it; the count says how many lines are ticked.
 */
export function LineList( {
  name,
  testId,
  rows,
  onChange,
}: {
  name: string;
  testId: string;
  rows: ChecklistRow[];
  onChange: ( rows: ChecklistRow[] ) => void;
} ) {
  return (
    <div className="bwx-field bwx-checklist" data-testid={ testId }>
      <span className="bwx-checklist-head">
        <span>{ name }</span>
        { 0 < rows.length && (
          <span className="bwx-mono" data-testid={ `${ testId }-count` }>
            { `${ rows.filter( ( row ) => row.done ).length } of ${ rows.length } done` }
          </span>
        ) }
      </span>
      { rows.map( ( row, at ) => (
        <div className="bwx-checklist-row" data-testid={ `${ testId }-row` } key={ at }>
          <input
            type="checkbox"
            data-testid={ `${ testId }-done` }
            aria-label={ `Done: ${ row.text || 'line ' + ( at + 1 ) }` }
            checked={ row.done }
            onChange={ ( event ) => onChange( rows.map( ( one, i ) => ( i === at ? { ...one, done: event.target.checked } : one ) ) ) }
          />
          <input
            className="bwx-input"
            data-testid={ `${ testId }-text` }
            aria-label={ `${ name } line ${ at + 1 }` }
            maxLength={ 191 }
            value={ row.text }
            data-done={ row.done ? 'true' : undefined }
            onChange={ ( event ) => onChange( rows.map( ( one, i ) => ( i === at ? { ...one, text: event.target.value } : one ) ) ) }
            onKeyDown={ ( event ) => {
              if ( 'Enter' === event.key && rows.length < CHECKLIST_ROWS ) {
                event.preventDefault();
                onChange( [ ...rows.slice( 0, at + 1 ), { text: '', done: false }, ...rows.slice( at + 1 ) ] );
              } else if ( 'Backspace' === event.key && '' === row.text && 1 < rows.length ) {
                event.preventDefault();
                onChange( rows.filter( ( _, i ) => i !== at ) );
              }
            } }
          />
          <button
            type="button"
            className="bwx-icon-button"
            aria-label={ `Remove line ${ at + 1 }` }
            data-testid={ `${ testId }-remove` }
            onClick={ () => onChange( rows.filter( ( _, i ) => i !== at ) ) }
          >
            ✕
          </button>
        </div>
      ) ) }
      { rows.length < CHECKLIST_ROWS && (
        <div className="bwx-moves">
          <button
            type="button"
            className="bwx-button"
            data-variant="quiet"
            data-testid={ `${ testId }-add` }
            onClick={ () => onChange( [ ...rows, { text: '', done: false } ] ) }
          >
            Add a line
          </button>
        </div>
      ) }
    </div>
  );
}
