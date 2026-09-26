import { useEffect, useState } from 'react';
import { Repeat } from 'lucide-react';
import type { ClientSite, Person, RecurringRule, RecurringSource, Stage } from '../types';
import { api, ApiError, forgeData, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { HoursSelect } from '../hours';
import { Aside, DataView, EmptyState, RichText, Tag } from '../kit';
import type { Column } from '../kit';
import { everybody, ItemPanel } from './ItemPanel';
import { Screen } from './States';

/**
 * The studio's recurring tasks: what repeats, how often, who does it, and
 * what each last made.
 *
 * A recurring task is an arrangement, never a task itself. The tasks are
 * ordinary work items the engine makes on each due day, in Up Next on the
 * client's site it names, and from then on they are the board's — this
 * screen only points at them. Editing an arrangement changes what is made
 * next time; it never rewrites a task already on the board.
 */

const WEEKDAYS = [
  [ 1, 'Mon' ],
  [ 2, 'Tue' ],
  [ 3, 'Wed' ],
  [ 4, 'Thu' ],
  [ 5, 'Fri' ],
  [ 6, 'Sat' ],
  [ 7, 'Sun' ],
] as const;

const TYPES = [
  { id: 'task', label: 'Task' },
  { id: 'bug', label: 'Bug' },
  { id: 'feedback', label: 'Feedback' },
];

interface Listing {
  denied: boolean;
  studio_site_id?: string;
  sources: RecurringSource[];
}

/** What the form holds while somebody is filling it in. */
interface Draft {
  title: string;
  client_site_id: string;
  description: string;
  work_type: string;
  every: 'day' | 'weekday' | 'week' | 'month';
  days: number[];
  day: number;
  starts_on: string;
  ends_on: string;
  assignees: string[];
  hours_each: string;
}

/** Today, as the date box wants it. */
function today(): string {
  const now = new Date();

  return `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( now.getDate() ).padStart( 2, '0' ) }`;
}

/** Monday to Friday, as the weekly rule holds them: what "Every weekday" means. */
const WEEKDAY_DAYS = [ 1, 2, 3, 4, 5 ];

function isWeekdays( days: number[] ): boolean {
  return JSON.stringify( [ ...days ].sort() ) === JSON.stringify( WEEKDAY_DAYS );
}

function blank( studio: string ): Draft {
  return {
    title: '',
    client_site_id: studio,
    description: '',
    work_type: 'task',
    every: 'week',
    days: [ new Date().getDay() || 7 ],
    day: new Date().getDate(),
    // A start is chosen, not assumed (2026-09-19): today to begin with.
    starts_on: today(),
    ends_on: '',
    assignees: [],
    hours_each: '',
  };
}

function fromSource( source: RecurringSource ): Draft {
  const rule = source.rule;

  return {
    title: source.title,
    client_site_id: source.client_site_id,
    description: source.description,
    work_type: source.work_type,
    every: 'week' === rule.every && isWeekdays( rule.days ) ? 'weekday' : rule.every,
    days: 'week' === rule.every ? rule.days : [ 1 ],
    day: 'month' === rule.every ? rule.day : 1,
    starts_on: source.starts_on,
    ends_on: source.ends_on,
    assignees: source.assignees ?? [],
    hours_each: source.hours_each ? String( source.hours_each ) : '',
  };
}

/** Whether every required part of a schedule is there (2026-09-19). */
function complete( draft: Draft ): boolean {
  return (
    '' !== draft.title.trim() &&
    '' !== draft.client_site_id &&
    '' !== draft.description.replace( /<[^>]+>/g, '' ).trim() &&
    '' !== draft.starts_on &&
    0 < draft.assignees.length &&
    0 < Number( draft.hours_each || 0 ) &&
    ( 'week' !== draft.every || 0 < draft.days.length )
  );
}

function toRule( draft: Draft ): RecurringRule {
  if ( 'day' === draft.every ) {
    return { every: 'day' };
  }

  if ( 'weekday' === draft.every ) {
    return { every: 'week', days: WEEKDAY_DAYS };
  }

  if ( 'week' === draft.every ) {
    return { every: 'week', days: draft.days };
  }

  return { every: 'month', day: draft.day };
}

export function RecurringScreen() {
  const [ listing, setListing ] = useState< Listing | null >( null );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );
  const [ people, setPeople ] = useState< Person[] >( [] );
  const [ sites, setSites ] = useState< Array< ClientSite & { client_name: string } > >( [] );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ editing, setEditing ] = useState< RecurringSource | 'new' | null >( null );
  const [ opened, setOpened ] = useState( '' );
  const [ busy, setBusy ] = useState( false );
  const canManage = forgeData()?.canManage ?? false;

  async function load() {
    try {
      const answer = await api< Listing >( '/recurring' );

      setListing( answer );
      setState( answer.denied ? 'denied' : 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Recurring tasks could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    void everybody().then( setPeople );
    void api< { stages: Stage[] } >( '/stages' ).then( ( answer ) => setStages( answer.stages ) ).catch( () => undefined );
    void api< { sites: Array< ClientSite & { client_name: string } > } >( '/client-sites' ).then( ( answer ) => setSites( answer.sites ) ).catch( () => undefined );
  }, [] );

  useLiveReload( load );

  const name = ( id: string ) => people.find( ( one ) => one.id === id )?.display_name ?? ( id ? '?' : '—' );
  const siteName = ( id: string ) => {
    const site = sites.find( ( one ) => one.id === id );

    return site ? site.client_name || site.name : '—';
  };

  async function act( path: string, method: string, body?: unknown ) {
    setBusy( true );
    setNotice( '' );

    try {
      await api( path, { method, body } );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That did not work.' ) );
    } finally {
      setBusy( false );
    }
  }

  const columns: Column< RecurringSource >[] = [
    {
      key: 'title',
      label: 'Recurring task',
      wrap: true,
      sortBy: ( r ) => r.title,
      render: ( r ) => (
        <span className="bwx-recurring-title">
          <span>{ r.title }</span>
          { 'paused' === r.status && <Tag tone="warn">Paused</Tag> }
          { 'subscription' === r.kind && <Tag>Subscription</Tag> }
        </span>
      ),
    },
    { key: 'client', label: 'Client', width: 180, sortBy: ( r ) => siteName( r.client_site_id ), render: ( r ) => siteName( r.client_site_id ) },
    { key: 'cadence', label: 'Repeats', width: 200, sortBy: ( r ) => r.cadence, render: ( r ) => r.cadence },
    {
      key: 'seats',
      label: 'Who',
      width: 220,
      wrap: true,
      render: ( r ) =>
        [ ...( r.assignees ?? [] ), r.primary_user_id, r.reviewer_id, r.deliverer_id ].filter( Boolean ).map( name ).join( ', ' ) || '—',
    },
    {
      key: 'hours',
      label: 'Hours',
      mono: true,
      align: 'right',
      width: 100,
      sortBy: ( r ) => r.hours_each * ( r.assignees?.length ?? 0 ) + r.hours_primary + r.hours_review + r.hours_delivery,
      render: ( r ) => {
        const people = r.assignees?.length ?? 0;

        if ( r.hours_each && people ) {
          return 1 === people ? `${ r.hours_each.toFixed( 1 ) }h` : `${ r.hours_each.toFixed( 1 ) }h × ${ people }`;
        }

        const total = r.hours_primary + r.hours_review + r.hours_delivery;

        return total ? `${ total.toFixed( 1 ) }h` : '—';
      },
    },
    { key: 'next', label: 'Next due', mono: true, width: 110, sortBy: ( r ) => r.next_due, render: ( r ) => r.next_due || '—' },
    {
      key: 'last',
      label: 'Last made',
      mono: true,
      width: 120,
      sortBy: ( r ) => r.last?.due_on ?? '',
      render: ( r ) =>
        r.last?.work_item_id ? (
          <button type="button" className="bwx-row-open" data-testid="bwx-recurring-last" onClick={ () => setOpened( r.last?.work_item_id ?? '' ) }>
            { r.last.due_on }
          </button>
        ) : (
          '—'
        ),
    },
    {
      key: 'actions',
      label: '',
      width: 230,
      render: ( r ) =>
        canManage && 'schedule' === r.kind ? (
          <span className="bwx-recurring-actions">
            <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-recurring-edit" disabled={ busy } onClick={ () => setEditing( r ) }>
              Edit
            </button>
            <button
              type="button"
              className="bwx-button"
              data-variant="quiet"
              data-testid="bwx-recurring-pause"
              disabled={ busy }
              onClick={ () => void act( `/recurring/${ r.id }`, 'PATCH', { status: 'paused' === r.status ? 'active' : 'paused', record_version: r.record_version } ) }
            >
              { 'paused' === r.status ? 'Resume' : 'Pause' }
            </button>
            <button
              type="button"
              className="bwx-button"
              data-variant="quiet"
              data-testid="bwx-recurring-end"
              disabled={ busy }
              onClick={ () => {
                if ( window.confirm( `Stop "${ r.title }" recurring? Tasks already made stay on the board.` ) ) {
                  void act( `/recurring/${ r.id }`, 'DELETE' );
                }
              } }
            >
              End
            </button>
          </span>
        ) : null,
    },
  ];

  return (
    <>
      { 'loading' === state && <Screen state="loading" testId="bwx-recurring-state" /> }
      { 'denied' === state && (
        <Screen state="denied" testId="bwx-recurring-state" detail="You are signed in, but do not reach any client's site." />
      ) }
      { 'error' === state && <Screen state="error" testId="bwx-recurring-state" detail={ notice } /> }

      { 'ready' === state && listing && (
        <div className="bwx-recurring" data-testid="bwx-recurring">
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-recurring-notice" role="status">
              { notice }
            </p>
          ) }
          <DataView< RecurringSource >
            title="Recurring tasks"
            titleRight={
              canManage ? (
                <span className="bwx-recurring-toolbar">
                  <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-recurring-run" disabled={ busy } onClick={ () => void act( '/recurring/run', 'POST' ) }>
                    Create today&apos;s now
                  </button>
                  <button type="button" className="bwx-button" data-testid="bwx-recurring-add" disabled={ busy } onClick={ () => setEditing( 'new' ) }>
                    Add recurring task
                  </button>
                </span>
              ) : undefined
            }
            columns={ columns }
            rows={ listing.sources }
            sortable
            empty={ <EmptyState icon={ Repeat } dense title="Nothing repeats yet" body="Add something that happens every day, week or month, and each due day becomes a task in Up Next." /> }
            footer={ `${ listing.sources.length } recurring · each due day becomes a task on its client's site the first time anyone opens Forge` }
            testId="bwx-recurring-table"
          />
        </div>
      ) }

      { null !== editing && (
        <SourceForm
          source={ 'new' === editing ? null : editing }
          people={ people }
          sites={ sites }
          studio={ listing?.studio_site_id ?? '' }
          onClose={ () => setEditing( null ) }
          onSaved={ () => {
            setEditing( null );
            void load();
          } }
        />
      ) }

      { '' !== opened && <ItemPanel itemId={ opened } stages={ stages } onClose={ () => setOpened( '' ) } onChanged={ () => void load() } /> }
    </>
  );
}

/** Adding or editing one arrangement. */
function SourceForm( {
  source,
  people,
  sites,
  studio,
  onClose,
  onSaved,
}: {
  source: RecurringSource | null;
  people: Person[];
  sites: Array< ClientSite & { client_name: string } >;
  studio: string;
  onClose: () => void;
  onSaved: () => void;
} ) {
  const [ draft, setDraft ] = useState< Draft >( () => ( source ? fromSource( source ) : blank( studio ) ) );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  const set = < K extends keyof Draft >( key: K, value: Draft[ K ] ) => setDraft( { ...draft, [ key ]: value } );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = {
      title: draft.title,
      client_site_id: draft.client_site_id,
      description: draft.description,
      work_type: draft.work_type,
      rule: toRule( draft ),
      starts_on: draft.starts_on,
      ends_on: draft.ends_on,
      assignees: draft.assignees,
      hours_each: draft.hours_each,
    };

    try {
      if ( source ) {
        await api( `/recurring/${ source.id }`, { method: 'PATCH', body: { ...body, record_version: source.record_version } } );
      } else {
        await api( '/recurring', { method: 'POST', body } );
      }

      onSaved();
    } catch ( error ) {
      const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

      setNotice( fields ? Object.values( fields ).join( ' ' ) : messageFor( error, 'That could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Aside
      label={ source ? 'Edit recurring task' : 'Add recurring task' }
      testId="bwx-recurring-form"
      width={ 560 }
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <button type="button" className="bwx-button" data-testid="bwx-recurring-save" disabled={ busy || ! complete( draft ) } onClick={ () => void save() }>
            { source ? 'Save' : 'Add' }
          </button>
          <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
            Cancel
          </button>
        </div>
      }
    >

        { '' !== notice && (
          <p className="bwx-notice" data-testid="bwx-recurring-form-notice" role="status">
            { notice }
          </p>
        ) }

        <div className="bwx-field">
          <label htmlFor="bwx-recurring-title">Title</label>
          <input id="bwx-recurring-title" className="bwx-input" data-testid="bwx-recurring-title" autoFocus value={ draft.title } onChange={ ( event ) => set( 'title', event.target.value ) } />
        </div>

        <div className="bwx-field">
          <label htmlFor="bwx-recurring-client">Client</label>
          <select id="bwx-recurring-client" className="bwx-select" data-testid="bwx-recurring-client" value={ draft.client_site_id } onChange={ ( event ) => set( 'client_site_id', event.target.value ) }>
            <option value="">Choose a client</option>
            { sites.map( ( site ) => (
              <option key={ site.id } value={ site.id }>
                { site.client_name && site.client_name !== site.name ? `${ site.client_name } · ${ site.name }` : site.name }
              </option>
            ) ) }
          </select>
        </div>

        <div className="bwx-field">
          <label htmlFor="bwx-recurring-description">What to do</label>
          <RichText
            id="bwx-recurring-description"
            testId="bwx-recurring-description"
            label="What to do"
            value={ draft.description }
            onChange={ ( html ) => set( 'description', html ) }
          />
        </div>

        <div className="bwx-field">
          <label htmlFor="bwx-recurring-type">Type</label>
          <select id="bwx-recurring-type" className="bwx-select" value={ draft.work_type } onChange={ ( event ) => set( 'work_type', event.target.value ) }>
            { TYPES.map( ( option ) => (
              <option key={ option.id } value={ option.id }>
                { option.label }
              </option>
            ) ) }
          </select>
        </div>

        <div className="bwx-field">
          <label htmlFor="bwx-recurring-every">Repeats</label>
          <select id="bwx-recurring-every" className="bwx-select" data-testid="bwx-recurring-every" value={ draft.every } onChange={ ( event ) => set( 'every', event.target.value as Draft[ 'every' ] ) }>
            <option value="day">Every day</option>
            <option value="weekday">Every weekday</option>
            <option value="week">Every week</option>
            <option value="month">Every month</option>
          </select>
        </div>

        { 'week' === draft.every && (
          <fieldset className="bwx-field bwx-recurring-days">
            <legend>On</legend>
            { WEEKDAYS.map( ( [ number, label ] ) => (
              <label key={ number } className="bwx-recurring-day">
                <input
                  type="checkbox"
                  data-testid={ `bwx-recurring-day-${ number }` }
                  checked={ draft.days.includes( number ) }
                  onChange={ ( event ) =>
                    set( 'days', event.target.checked ? [ ...draft.days, number ].sort() : draft.days.filter( ( one ) => one !== number ) )
                  }
                />
                { label }
              </label>
            ) ) }
          </fieldset>
        ) }

        { 'month' === draft.every && (
          <div className="bwx-field">
            <label htmlFor="bwx-recurring-day">Day of the month</label>
            <input id="bwx-recurring-day" className="bwx-input" type="number" min="1" max="31" value={ draft.day } onChange={ ( event ) => set( 'day', Number( event.target.value ) ) } />
            <span className="bwx-hint">31 means the last day of every month.</span>
          </div>
        ) }

        <div className="bwx-field bwx-recurring-dates">
          <span>
            <label htmlFor="bwx-recurring-starts">Starts</label>
            <input id="bwx-recurring-starts" className="bwx-input" type="date" data-testid="bwx-recurring-starts" value={ draft.starts_on } onChange={ ( event ) => set( 'starts_on', event.target.value ) } />
          </span>
          <span>
            <label htmlFor="bwx-recurring-ends">Ends (optional)</label>
            <input id="bwx-recurring-ends" className="bwx-input" type="date" value={ draft.ends_on } onChange={ ( event ) => set( 'ends_on', event.target.value ) } />
          </span>
        </div>

        { /*
            Who does it (2026-09-18): one or more people, each ticking their
            own copy of the day's task, and the hours each of them spends —
            no reviewer, no deliverer. A chore is done when everyone did it.
         */ }
        <fieldset className="bwx-field bwx-recurring-people" data-testid="bwx-recurring-assignees">
          <legend>Who does it</legend>
          { people.map( ( person ) => (
            <label key={ person.id } className="bwx-recurring-person">
              <input
                type="checkbox"
                data-testid={ `bwx-recurring-assignee-${ person.id }` }
                checked={ draft.assignees.includes( person.id ) }
                onChange={ ( event ) =>
                  set( 'assignees', event.target.checked ? [ ...draft.assignees, person.id ] : draft.assignees.filter( ( one ) => one !== person.id ) )
                }
              />
              { person.display_name }
            </label>
          ) ) }
          { 0 === people.length && <span className="bwx-hint">Nobody on People yet.</span> }
        </fieldset>

        <div className="bwx-field">
          <label htmlFor="bwx-recurring-hours_each">Hours each</label>
          <HoursSelect
            id="bwx-recurring-hours_each"
            className="bwx-select"
            testId="bwx-recurring-hours_each"
            value={ draft.hours_each }
            onChange={ ( value ) => set( 'hours_each', value ) }
          />
          <span className="bwx-hint">Counted against each person&apos;s capacity on the day.</span>
        </div>

    </Aside>
  );
}
