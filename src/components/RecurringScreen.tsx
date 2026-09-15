import { useEffect, useState } from 'react';
import { Repeat } from 'lucide-react';
import type { Person, RecurringRule, RecurringSource, Stage } from '../types';
import { api, ApiError, forgeData, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState, Tag } from '../kit';
import type { Column } from '../kit';
import { everybody, ItemPanel } from './ItemPanel';
import { Screen } from './States';

/**
 * The studio's recurring tasks: what repeats, how often, who does it, and
 * what each last made.
 *
 * A recurring task is an arrangement, never a task itself. The tasks are
 * ordinary work items the engine makes on each due day, in Up Next on the
 * studio's own site, and from then on they are the board's — this screen
 * only points at them. Editing an arrangement changes what is made next
 * time; it never rewrites a task already on the board.
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
  { id: 'feature', label: 'Feature' },
  { id: 'bug', label: 'Bug' },
  { id: 'feedback', label: 'Feedback' },
];

interface Listing {
  denied: boolean;
  site?: { id: string; name: string };
  sources: RecurringSource[];
}

/** What the form holds while somebody is filling it in. */
interface Draft {
  title: string;
  description: string;
  work_type: string;
  every: 'day' | 'week' | 'month';
  days: number[];
  day: number;
  starts_on: string;
  ends_on: string;
  primary_user_id: string;
  reviewer_id: string;
  deliverer_id: string;
  hours_primary: string;
  hours_review: string;
  hours_delivery: string;
}

function blank(): Draft {
  return {
    title: '',
    description: '',
    work_type: 'task',
    every: 'week',
    days: [ new Date().getDay() || 7 ],
    day: new Date().getDate(),
    // Left blank, the server starts it today — its today, in the site's
    // timezone, which is the one the schedule runs on.
    starts_on: '',
    ends_on: '',
    primary_user_id: '',
    reviewer_id: '',
    deliverer_id: '',
    hours_primary: '',
    hours_review: '',
    hours_delivery: '',
  };
}

function fromSource( source: RecurringSource ): Draft {
  const rule = source.rule;

  return {
    title: source.title,
    description: source.description,
    work_type: source.work_type,
    every: rule.every,
    days: 'week' === rule.every ? rule.days : [ 1 ],
    day: 'month' === rule.every ? rule.day : 1,
    starts_on: source.starts_on,
    ends_on: source.ends_on,
    primary_user_id: source.primary_user_id,
    reviewer_id: source.reviewer_id,
    deliverer_id: source.deliverer_id,
    hours_primary: source.hours_primary ? String( source.hours_primary ) : '',
    hours_review: source.hours_review ? String( source.hours_review ) : '',
    hours_delivery: source.hours_delivery ? String( source.hours_delivery ) : '',
  };
}

function toRule( draft: Draft ): RecurringRule {
  if ( 'day' === draft.every ) {
    return { every: 'day' };
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
  }, [] );

  useLiveReload( load );

  const name = ( id: string ) => people.find( ( one ) => one.id === id )?.display_name ?? ( id ? '?' : '—' );

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
    { key: 'cadence', label: 'Repeats', width: 200, sortBy: ( r ) => r.cadence, render: ( r ) => r.cadence },
    {
      key: 'seats',
      label: 'Who',
      width: 220,
      wrap: true,
      render: ( r ) => [ r.primary_user_id, r.reviewer_id, r.deliverer_id ].filter( Boolean ).map( name ).join( ', ' ) || '—',
    },
    {
      key: 'hours',
      label: 'Hours',
      mono: true,
      align: 'right',
      width: 80,
      sortBy: ( r ) => r.hours_primary + r.hours_review + r.hours_delivery,
      render: ( r ) => {
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
        <Screen state="denied" testId="bwx-recurring-state" detail="Recurring tasks are the studio's own. You are signed in, but not on the studio's site." />
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
            title={ listing.site?.name ?? 'Recurring tasks' }
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
            footer={ `${ listing.sources.length } recurring · each due day becomes a task on the studio's site the first time anyone opens Forge` }
            testId="bwx-recurring-table"
          />
        </div>
      ) }

      { null !== editing && (
        <SourceForm
          source={ 'new' === editing ? null : editing }
          people={ people }
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
  onClose,
  onSaved,
}: {
  source: RecurringSource | null;
  people: Person[];
  onClose: () => void;
  onSaved: () => void;
} ) {
  const [ draft, setDraft ] = useState< Draft >( () => ( source ? fromSource( source ) : blank() ) );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  const set = < K extends keyof Draft >( key: K, value: Draft[ K ] ) => setDraft( { ...draft, [ key ]: value } );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = {
      title: draft.title,
      description: draft.description,
      work_type: draft.work_type,
      rule: toRule( draft ),
      starts_on: draft.starts_on,
      ends_on: draft.ends_on,
      primary_user_id: draft.primary_user_id,
      reviewer_id: draft.reviewer_id,
      deliverer_id: draft.deliverer_id,
      hours_primary: draft.hours_primary,
      hours_review: draft.hours_review,
      hours_delivery: draft.hours_delivery,
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

  const seat = ( key: 'primary_user_id' | 'reviewer_id' | 'deliverer_id', hours: 'hours_primary' | 'hours_review' | 'hours_delivery', label: string ) => (
    <div className="bwx-field bwx-recurring-seat">
      <label htmlFor={ `bwx-recurring-${ key }` }>{ label }</label>
      <span className="bwx-recurring-seat-row">
        <select id={ `bwx-recurring-${ key }` } className="bwx-select" data-testid={ `bwx-recurring-${ key }` } value={ draft[ key ] } onChange={ ( event ) => set( key, event.target.value ) }>
          <option value="">Nobody yet</option>
          { people.map( ( person ) => (
            <option key={ person.id } value={ person.id }>
              { person.display_name }
            </option>
          ) ) }
        </select>
        <input
          className="bwx-input bwx-recurring-hours"
          data-testid={ `bwx-recurring-${ hours }` }
          type="number"
          min="0"
          step="0.25"
          placeholder="hours"
          aria-label={ `${ label } hours` }
          value={ draft[ hours ] }
          onChange={ ( event ) => set( hours, event.target.value ) }
        />
      </span>
    </div>
  );

  return (
    <div className="bwx-panel-scrim" onClick={ ( event ) => event.target === event.currentTarget && onClose() }>
      <aside
        className="bwx-panel"
        role="dialog"
        aria-modal="true"
        aria-label={ source ? 'Edit recurring task' : 'Add recurring task' }
        data-testid="bwx-recurring-form"
        onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
      >
        <header className="bwx-panel-head">
          <h2 style={ { flex: 1, margin: 0, fontSize: 'var(--text-subheading)', fontWeight: 500 } }>{ source ? 'Edit recurring task' : 'Add recurring task' }</h2>
          <button type="button" className="bwx-icon-button" onClick={ onClose } aria-label="Close">
            ✕
          </button>
        </header>

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
          <label htmlFor="bwx-recurring-description">What to do</label>
          <textarea id="bwx-recurring-description" className="bwx-textarea" value={ draft.description } onChange={ ( event ) => set( 'description', event.target.value ) } />
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
            <label htmlFor="bwx-recurring-starts">Starts (blank for today)</label>
            <input id="bwx-recurring-starts" className="bwx-input" type="date" value={ draft.starts_on } onChange={ ( event ) => set( 'starts_on', event.target.value ) } />
          </span>
          <span>
            <label htmlFor="bwx-recurring-ends">Ends (optional)</label>
            <input id="bwx-recurring-ends" className="bwx-input" type="date" value={ draft.ends_on } onChange={ ( event ) => set( 'ends_on', event.target.value ) } />
          </span>
        </div>

        { seat( 'primary_user_id', 'hours_primary', 'Does it' ) }
        { seat( 'reviewer_id', 'hours_review', 'Checks it' ) }
        { seat( 'deliverer_id', 'hours_delivery', 'Ships it' ) }

        <div className="bwx-moves">
          <button type="button" className="bwx-button" data-testid="bwx-recurring-save" disabled={ busy || '' === draft.title.trim() } onClick={ () => void save() }>
            { source ? 'Save' : 'Add' }
          </button>
          <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
            Cancel
          </button>
        </div>
      </aside>
    </div>
  );
}
