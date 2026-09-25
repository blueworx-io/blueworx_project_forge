import { useEffect, useState } from 'react';
import { Bell } from 'lucide-react';
import type { ClientSite, Person, Reminder, Stage } from '../types';
import { api, ApiError, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Aside, DataView, EmptyState, RichText } from '../kit';
import type { Column } from '../kit';
import { everybody, ItemPanel } from './ItemPanel';
import { Screen } from './States';

/**
 * Reminders (2026-09-25): a task on a fixed day or over a few, for one or
 * more people, on a client's site. Saving one makes each person their own
 * task straight away, which they see in My tasks and tick off; the calendar
 * shows it on every day it covers. Anyone adds one; its author or an
 * administrator changes it, and a change reaches only the copies nobody has
 * ticked.
 */

type Site = ClientSite & { client_name: string };

interface Draft {
  title: string;
  description: string;
  client_site_id: string;
  assignees: string[];
  starts_on: string;
  ends_on: string;
}

function today(): string {
  const now = new Date();

  return `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( now.getDate() ).padStart( 2, '0' ) }`;
}

function blank(): Draft {
  return { title: '', description: '', client_site_id: '', assignees: [], starts_on: today(), ends_on: '' };
}

function fromReminder( reminder: Reminder ): Draft {
  return {
    title: reminder.title,
    description: reminder.description,
    client_site_id: reminder.client_site_id,
    assignees: reminder.assignees,
    starts_on: reminder.starts_on,
    ends_on: reminder.ends_on,
  };
}

function complete( draft: Draft ): boolean {
  return '' !== draft.title.trim() && '' !== draft.client_site_id && 0 < draft.assignees.length && '' !== draft.starts_on && ( '' === draft.ends_on || draft.ends_on >= draft.starts_on );
}

/** "3 Oct", or "1 Oct – 5 Oct" for a period. */
function when( reminder: Reminder ): string {
  const day = ( date: string ) => new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' } );

  return '' === reminder.ends_on || reminder.ends_on === reminder.starts_on ? day( reminder.starts_on ) : `${ day( reminder.starts_on ) } – ${ day( reminder.ends_on ) }`;
}

function siteLabel( site: Site ): string {
  return site.client_name && site.client_name !== site.name ? `${ site.client_name } · ${ site.name }` : site.client_name || site.name;
}

export function RemindersScreen() {
  const [ reminders, setReminders ] = useState< Reminder[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );
  const [ people, setPeople ] = useState< Person[] >( [] );
  const [ sites, setSites ] = useState< Site[] >( [] );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ editing, setEditing ] = useState< Reminder | 'new' | null >( null );
  const [ opened, setOpened ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function load() {
    try {
      const answer = await api< { denied: boolean; reminders: Reminder[] } >( '/reminders' );

      setReminders( answer.reminders );
      setState( answer.denied ? 'denied' : 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Reminders could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    void everybody().then( setPeople );
    void api< { sites: Site[] } >( '/client-sites' ).then( ( answer ) => setSites( answer.sites ) ).catch( () => undefined );
    void api< { stages: Stage[] } >( '/stages' ).then( ( answer ) => setStages( answer.stages ) ).catch( () => undefined );
  }, [] );

  useLiveReload( load );

  const name = ( id: string ) => people.find( ( one ) => one.id === id )?.display_name ?? '?';
  const client = ( id: string ) => {
    const site = sites.find( ( one ) => one.id === id );

    return site ? siteLabel( site ) : '—';
  };

  async function remove( reminder: Reminder ) {
    if ( ! window.confirm( `Delete "${ reminder.title }"? Anyone who has ticked it keeps their record of it.` ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      await api( `/reminders/${ reminder.id }`, { method: 'DELETE' } );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That did not work.' ) );
    } finally {
      setBusy( false );
    }
  }

  const columns: Column< Reminder >[] = [
    {
      key: 'title',
      label: 'Reminder',
      wrap: true,
      sortBy: ( r ) => r.title,
      // Opens the first copy, as Recurring tasks' "Last made" does.
      render: ( r ) =>
        r.copies[ 0 ] ? (
          <button type="button" className="bwx-row-open" data-testid="bwx-reminder-open" onClick={ () => setOpened( r.copies[ 0 ].item_id ) }>
            { r.title }
          </button>
        ) : (
          r.title
        ),
    },
    { key: 'client', label: 'Client', width: 200, sortBy: ( r ) => client( r.client_site_id ), render: ( r ) => client( r.client_site_id ) },
    { key: 'who', label: 'Who', width: 200, wrap: true, render: ( r ) => r.assignees.map( name ).join( ', ' ) },
    { key: 'when', label: 'When', width: 150, sortBy: ( r ) => r.starts_on, render: ( r ) => when( r ) },
    {
      key: 'done',
      label: 'Done',
      width: 110,
      render: ( r ) => `${ r.copies.filter( ( copy ) => copy.done ).length } of ${ r.copies.length } done`,
    },
    {
      key: 'actions',
      label: '',
      width: 170,
      render: ( r ) =>
        r.can_edit ? (
          <span className="bwx-recurring-actions">
            <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-reminder-edit" disabled={ busy } onClick={ () => setEditing( r ) }>
              Edit
            </button>
            <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-reminder-delete" disabled={ busy } onClick={ () => void remove( r ) }>
              Delete
            </button>
          </span>
        ) : null,
    },
  ];

  return (
    <>
      { 'loading' === state && <Screen state="loading" testId="bwx-reminders-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-reminders-state" detail="You are signed in, but do not reach any client's site." /> }
      { 'error' === state && <Screen state="error" testId="bwx-reminders-state" detail={ notice } /> }

      { 'ready' === state && (
        <div className="bwx-recurring" data-testid="bwx-reminders">
          { '' !== notice && (
            <p className="bwx-notice" role="status">
              { notice }
            </p>
          ) }
          <DataView< Reminder >
            title="Reminders"
            titleRight={
              <button type="button" className="bwx-button" data-testid="bwx-reminders-add" disabled={ busy } onClick={ () => setEditing( 'new' ) }>
                Add reminder
              </button>
            }
            columns={ columns }
            rows={ reminders }
            sortable
            empty={ <EmptyState icon={ Bell } dense title="No reminders yet" body="Add one for a day or a few, and each person gets their own task to tick off." /> }
            footer={ `${ reminders.length } reminders · each person gets their own task as soon as a reminder is saved` }
            testId="bwx-reminders-table"
          />
        </div>
      ) }

      { null !== editing && (
        <ReminderForm
          reminder={ 'new' === editing ? null : editing }
          people={ people }
          sites={ sites }
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

function ReminderForm( {
  reminder,
  people,
  sites,
  onClose,
  onSaved,
}: {
  reminder: Reminder | null;
  people: Person[];
  sites: Site[];
  onClose: () => void;
  onSaved: () => void;
} ) {
  const [ draft, setDraft ] = useState< Draft >( () => ( reminder ? fromReminder( reminder ) : blank() ) );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  const set = < K extends keyof Draft >( key: K, value: Draft[ K ] ) => setDraft( { ...draft, [ key ]: value } );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = {
      title: draft.title,
      description: draft.description,
      assignees: draft.assignees,
      starts_on: draft.starts_on,
      ends_on: draft.ends_on,
    };

    try {
      if ( reminder ) {
        await api( `/reminders/${ reminder.id }`, { method: 'PATCH', body: { ...body, record_version: reminder.record_version } } );
      } else {
        await api( '/reminders', { method: 'POST', body: { ...body, client_site_id: draft.client_site_id } } );
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
      label={ reminder ? 'Edit reminder' : 'Add reminder' }
      testId="bwx-reminder-form"
      width={ 560 }
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <button type="button" className="bwx-button" data-testid="bwx-reminder-save" disabled={ busy || ! complete( draft ) } onClick={ () => void save() }>
            { reminder ? 'Save' : 'Add' }
          </button>
          <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
            Cancel
          </button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-reminder-form-notice" role="status">
          { notice }
        </p>
      ) }

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-title">Title</label>
        <input id="bwx-reminder-title" className="bwx-input" data-testid="bwx-reminder-title" autoFocus value={ draft.title } onChange={ ( event ) => set( 'title', event.target.value ) } />
      </div>

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-client">Client</label>
        { /* A reminder stays with the client it was made for; edits change the rest. */ }
        <select id="bwx-reminder-client" className="bwx-select" data-testid="bwx-reminder-client" value={ draft.client_site_id } disabled={ null !== reminder } onChange={ ( event ) => set( 'client_site_id', event.target.value ) }>
          <option value="">Choose a client</option>
          { sites.map( ( site ) => (
            <option key={ site.id } value={ site.id }>
              { siteLabel( site ) }
            </option>
          ) ) }
        </select>
      </div>

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-description">Notes (optional)</label>
        <RichText id="bwx-reminder-description" testId="bwx-reminder-description" label="Notes" value={ draft.description } onChange={ ( html ) => set( 'description', html ) } />
      </div>

      <div className="bwx-field bwx-recurring-dates">
        <span>
          <label htmlFor="bwx-reminder-starts">On, or from</label>
          <input id="bwx-reminder-starts" className="bwx-input" type="date" data-testid="bwx-reminder-starts" value={ draft.starts_on } onChange={ ( event ) => set( 'starts_on', event.target.value ) } />
        </span>
        <span>
          <label htmlFor="bwx-reminder-ends">Until (optional)</label>
          <input id="bwx-reminder-ends" className="bwx-input" type="date" data-testid="bwx-reminder-ends" min={ draft.starts_on } value={ draft.ends_on } onChange={ ( event ) => set( 'ends_on', event.target.value ) } />
        </span>
      </div>

      <fieldset className="bwx-field bwx-recurring-people" data-testid="bwx-reminder-people">
        <legend>Who</legend>
        { people.map( ( person ) => (
          <label key={ person.id } className="bwx-recurring-person">
            <input
              type="checkbox"
              data-testid={ `bwx-reminder-person-${ person.id }` }
              checked={ draft.assignees.includes( person.id ) }
              onChange={ ( event ) => set( 'assignees', event.target.checked ? [ ...draft.assignees, person.id ] : draft.assignees.filter( ( one ) => one !== person.id ) ) }
            />
            { person.display_name }
          </label>
        ) ) }
        { 0 === people.length && <span className="bwx-hint">Nobody on People yet.</span> }
      </fieldset>
    </Aside>
  );
}
