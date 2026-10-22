import { useEffect, useState } from 'react';
import type { ClientSite, Person, Reminder } from '../types';
import { api, ApiError, messageFor } from '../api';
import { Aside, RichText } from '../kit';
import { HoursSelect } from '../hours';

/**
 * Adding or changing a reminder (2026-09-25), from the Reminders page or from
 * a copy opened anywhere else (#610). Its author or an administrator changes
 * it, and a change reaches only the copies nobody has ticked.
 */

export type Site = ClientSite & { client_name: string };

export const CATEGORIES: Array< { id: Reminder[ 'category' ]; label: string } > = [
  { id: 'campaign', label: 'Campaign' },
  { id: 'deadline', label: 'Deadline' },
  { id: 'finance', label: 'Finance' },
  { id: 'general', label: 'General' },
  { id: 'marketing', label: 'Marketing' },
  { id: 'other', label: 'Other' },
  { id: 'sales', label: 'Sales' },
];

interface Draft {
  title: string;
  description: string;
  category: Reminder[ 'category' ];
  client_site_id: string;
  assignees: string[];
  starts_on: string;
  ends_on: string;
  /** Each person's time (#573), as the hours list stores it; '' for none. */
  hours_each: string;
}

function today(): string {
  const now = new Date();

  return `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( now.getDate() ).padStart( 2, '0' ) }`;
}

function blank( site = '' ): Draft {
  return { title: '', description: '', category: 'general', client_site_id: site, assignees: [], starts_on: today(), ends_on: '', hours_each: '' };
}

/** A title without its type in front: "General: Catch up" reads "Catch up". */
function bare( title: string ): string {
  const prefix = CATEGORIES.find( ( option ) => title.startsWith( `${ option.label }: ` ) );

  return prefix ? title.slice( prefix.label.length + 2 ) : title;
}

/** A reminder's title is saved with its type in front (2026-09-27). */
function titled( draft: Draft ): string {
  const label = CATEGORIES.find( ( option ) => option.id === draft.category )?.label;
  const title = bare( draft.title.trim() );

  return label ? `${ label }: ${ title }` : title;
}

function fromReminder( reminder: Reminder ): Draft {
  return {
    title: bare( reminder.title ),
    description: reminder.description,
    category: reminder.category,
    client_site_id: reminder.client_site_id,
    assignees: reminder.assignees,
    starts_on: reminder.starts_on,
    ends_on: reminder.ends_on,
    hours_each: 0 < reminder.hours_each ? String( reminder.hours_each ) : '',
  };
}

/** Both dates are optional (#611); given both, the end is not before the start. */
function complete( draft: Draft ): boolean {
  return '' !== draft.title.trim() && '' !== draft.client_site_id && 0 < draft.assignees.length && ( '' === draft.starts_on || '' === draft.ends_on || draft.ends_on >= draft.starts_on );
}

/** Somebody still has it to do, so there is something to turn into a task (#574). */
export function hasOpen( reminder: Reminder ): boolean {
  return reminder.copies.some( ( copy ) => ! copy.done && '' === copy.converted_to );
}

/**
 * Turns a reminder into one task (#574), once asked: everyone who has not
 * ticked it moves onto the task, and the reminder leaves reminders (#612).
 * The task's id, or '' when the person said no.
 */
export async function convertReminder( reminder: Reminder ): Promise< string > {
  if ( ! window.confirm( `Turn "${ reminder.title }" into a task? Everyone who hasn't ticked it yet moves onto one task with the same title, client and time, and the reminder is removed.` ) ) {
    return '';
  }

  const answer = await api< { item: { id: string } } >( `/reminders/${ reminder.id }/convert`, { method: 'POST' } );

  return answer.item.id;
}

export function siteLabel( site: Site ): string {
  return site.client_name && site.client_name !== site.name ? `${ site.client_name } · ${ site.name }` : site.client_name || site.name;
}

export function ReminderForm( {
  reminder,
  people,
  sites,
  site,
  onClose,
  onSaved,
}: {
  reminder: Reminder | null;
  people: Person[];
  sites: Site[];
  /** The client a new reminder starts on. */
  site: string;
  onClose: () => void;
  onSaved: () => void;
} ) {
  const [ draft, setDraft ] = useState< Draft >( () => ( reminder ? fromReminder( reminder ) : blank( site ) ) );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  const set = < K extends keyof Draft >( key: K, value: Draft[ K ] ) => setDraft( { ...draft, [ key ]: value } );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body = {
      title: titled( draft ),
      description: draft.description,
      category: draft.category,
      assignees: draft.assignees,
      starts_on: draft.starts_on,
      ends_on: draft.ends_on,
      hours_each: draft.hours_each,
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
        { '' !== draft.title.trim() && (
          <span className="bwx-hint" data-testid="bwx-reminder-titled">
            Saved as { titled( draft ) }
          </span>
        ) }
      </div>

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-category">Type</label>
        <select id="bwx-reminder-category" className="bwx-select" data-testid="bwx-reminder-category" value={ draft.category } onChange={ ( event ) => set( 'category', event.target.value as Reminder[ 'category' ] ) }>
          { CATEGORIES.map( ( option ) => (
            <option key={ option.id } value={ option.id }>
              { option.label }
            </option>
          ) ) }
        </select>
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
          <label htmlFor="bwx-reminder-starts">On, or from (optional)</label>
          <input id="bwx-reminder-starts" className="bwx-input" type="date" data-testid="bwx-reminder-starts" value={ draft.starts_on } onChange={ ( event ) => set( 'starts_on', event.target.value ) } />
        </span>
        <span>
          <label htmlFor="bwx-reminder-ends">Until (optional)</label>
          <input id="bwx-reminder-ends" className="bwx-input" type="date" data-testid="bwx-reminder-ends" min={ draft.starts_on || undefined } value={ draft.ends_on } onChange={ ( event ) => set( 'ends_on', event.target.value ) } />
        </span>
      </div>

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-hours">Time each (optional)</label>
        <HoursSelect id="bwx-reminder-hours" testId="bwx-reminder-hours" value={ draft.hours_each } onChange={ ( value ) => set( 'hours_each', value ) } />
        <span className="bwx-hint">Everyone on it gets the full time, on its first day, in Capacity. With no dates it counts on no day.</span>
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

/**
 * #610. The reminder a copy belongs to, on the task panel the copy opens in:
 * its author or an administrator changes it or turns it into a task there,
 * without going to the Reminders page. Anyone else is told whose it is to
 * change.
 */
export function ReminderActions( {
  reminderId,
  people,
  onChanged,
  onConverted,
}: {
  reminderId: string;
  people: Person[];
  onChanged: () => void;
  onConverted: ( taskId: string ) => void;
} ) {
  const [ reminder, setReminder ] = useState< Reminder | null >( null );
  const [ sites, setSites ] = useState< Site[] >( [] );
  const [ editing, setEditing ] = useState( false );
  const [ busy, setBusy ] = useState( false );
  const [ notice, setNotice ] = useState( '' );

  async function load() {
    try {
      setReminder( ( await api< { reminder: Reminder } >( `/reminders/${ reminderId }` ) ).reminder );
    } catch {
      setReminder( null );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    void api< { sites: Site[] } >( '/client-sites' ).then( ( answer ) => setSites( answer.sites ) ).catch( () => undefined );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ reminderId ] );

  if ( null === reminder ) {
    return null;
  }

  const open = hasOpen( reminder );

  async function convert( one: Reminder ) {
    setBusy( true );
    setNotice( '' );

    try {
      const task = await convertReminder( one );

      if ( '' !== task ) {
        onConverted( task );
      }
    } catch ( error ) {
      setNotice( messageFor( error, 'That did not work.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <div className="bwx-reminder-actions" data-testid="bwx-reminder-actions">
      <p className="bwx-eyebrow">Reminder</p>
      { '' !== notice && (
        <p className="bwx-notice" role="status">
          { notice }
        </p>
      ) }
      { reminder.can_edit ? (
        <div className="bwx-moves">
          <button type="button" className="bwx-button" data-testid="bwx-reminder-edit" disabled={ busy } onClick={ () => setEditing( true ) }>
            Edit reminder
          </button>
          { open && (
            <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-reminder-convert" disabled={ busy } onClick={ () => void convert( reminder ) }>
              Convert to task
            </button>
          ) }
        </div>
      ) : (
        <p className="bwx-hint">Only whoever added this reminder, or an administrator, can change it.</p>
      ) }
      { editing && (
        <ReminderForm
          reminder={ reminder }
          people={ people }
          sites={ sites }
          site={ reminder.client_site_id }
          onClose={ () => setEditing( false ) }
          onSaved={ () => {
            setEditing( false );
            void load();
            onChanged();
          } }
        />
      ) }
    </div>
  );
}
