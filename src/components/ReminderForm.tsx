import { useEffect, useState } from 'react';
import type { ClientSite, Person, Reminder } from '../types';
import { api, ApiError, messageFor } from '../api';
import { Aside, RichText } from '../kit';
import { HoursSelect } from '../hours';

/**
 * Adding or changing a reminder (2026-09-25). It is added from the Reminders
 * page, and changed in the panel of any copy, wherever it opens (#662). Its
 * author or an administrator changes it, and a change reaches only the
 * copies nobody has ticked.
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

export interface ReminderDraft {
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

function blank( site = '' ): ReminderDraft {
  return { title: '', description: '', category: 'general', client_site_id: site, assignees: [], starts_on: today(), ends_on: '', hours_each: '' };
}

/** A title without its type in front: "General: Catch up" reads "Catch up". */
function bare( title: string ): string {
  const prefix = CATEGORIES.find( ( option ) => title.startsWith( `${ option.label }: ` ) );

  return prefix ? title.slice( prefix.label.length + 2 ) : title;
}

/** A reminder's title is saved with its type in front (2026-09-27). */
function titled( draft: ReminderDraft ): string {
  const label = CATEGORIES.find( ( option ) => option.id === draft.category )?.label;
  const title = bare( draft.title.trim() );

  return label ? `${ label }: ${ title }` : title;
}

export function fromReminder( reminder: Reminder ): ReminderDraft {
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
export function complete( draft: ReminderDraft ): boolean {
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

/** A save's refusal, said the way the fields that caused it say it. */
function problem( error: unknown ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, 'That could not be saved.' );
}

function body( draft: ReminderDraft ) {
  return {
    title: titled( draft ),
    description: draft.description,
    category: draft.category,
    assignees: draft.assignees,
    starts_on: draft.starts_on,
    ends_on: draft.ends_on,
    hours_each: draft.hours_each,
  };
}

/**
 * Saves a changed reminder (#662), and with it the copies nobody has ticked.
 * '' once saved, or why it was not.
 */
export async function saveReminder( reminder: Reminder, draft: ReminderDraft ): Promise< string > {
  try {
    await api( `/reminders/${ reminder.id }`, { method: 'PATCH', body: { ...body( draft ), record_version: reminder.record_version } } );

    return '';
  } catch ( error ) {
    return problem( error );
  }
}

/** A reminder, read by id; null until it arrives, and for '' or one that cannot be read. */
export function useReminder( reminderId: string ): { reminder: Reminder | null; reload: () => Promise< void > } {
  const [ reminder, setReminder ] = useState< Reminder | null >( null );

  async function reload() {
    if ( '' === reminderId ) {
      setReminder( null );

      return;
    }

    try {
      setReminder( ( await api< { reminder: Reminder } >( `/reminders/${ reminderId }`, { cache: 'fresh' } ) ).reminder );
    } catch {
      setReminder( null );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ reminderId ] );

  return { reminder, reload };
}

/**
 * A reminder's fields, the same wherever it is added or changed (#662). Its
 * client is chosen only when it is added: a reminder stays with the client it
 * was made for.
 */
export function ReminderFields( {
  draft,
  onChange,
  people,
  sites = null,
  readOnly = false,
  autoFocus = false,
}: {
  draft: ReminderDraft;
  onChange: ( draft: ReminderDraft ) => void;
  people: Person[];
  /** The clients to choose from, for a new reminder; null for one already made. */
  sites?: Site[] | null;
  readOnly?: boolean;
  autoFocus?: boolean;
} ) {
  const set = < K extends keyof ReminderDraft >( key: K, value: ReminderDraft[ K ] ) => onChange( { ...draft, [ key ]: value } );

  return (
    <>
      <div className="bwx-field">
        <label htmlFor="bwx-reminder-title">Title</label>
        <input id="bwx-reminder-title" className="bwx-input" data-testid="bwx-reminder-title" autoFocus={ autoFocus } disabled={ readOnly } value={ draft.title } onChange={ ( event ) => set( 'title', event.target.value ) } />
        { '' !== draft.title.trim() && (
          <span className="bwx-hint" data-testid="bwx-reminder-titled">
            Saved as { titled( draft ) }
          </span>
        ) }
      </div>

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-category">Type</label>
        <select id="bwx-reminder-category" className="bwx-select" data-testid="bwx-reminder-category" disabled={ readOnly } value={ draft.category } onChange={ ( event ) => set( 'category', event.target.value as Reminder[ 'category' ] ) }>
          { CATEGORIES.map( ( option ) => (
            <option key={ option.id } value={ option.id }>
              { option.label }
            </option>
          ) ) }
        </select>
      </div>

      { null !== sites && (
        <div className="bwx-field">
          <label htmlFor="bwx-reminder-client">Client</label>
          <select id="bwx-reminder-client" className="bwx-select" data-testid="bwx-reminder-client" disabled={ readOnly } value={ draft.client_site_id } onChange={ ( event ) => set( 'client_site_id', event.target.value ) }>
            <option value="">Choose a client</option>
            { sites.map( ( site ) => (
              <option key={ site.id } value={ site.id }>
                { siteLabel( site ) }
              </option>
            ) ) }
          </select>
        </div>
      ) }

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-description">Notes (optional)</label>
        <RichText id="bwx-reminder-description" testId="bwx-reminder-description" label="Notes" readOnly={ readOnly } value={ draft.description } onChange={ ( html ) => set( 'description', html ) } />
      </div>

      <div className="bwx-field bwx-recurring-dates">
        <span>
          <label htmlFor="bwx-reminder-starts">On, or from (optional)</label>
          <input id="bwx-reminder-starts" className="bwx-input" type="date" data-testid="bwx-reminder-starts" disabled={ readOnly } value={ draft.starts_on } onChange={ ( event ) => set( 'starts_on', event.target.value ) } />
        </span>
        <span>
          <label htmlFor="bwx-reminder-ends">Until (optional)</label>
          <input id="bwx-reminder-ends" className="bwx-input" type="date" data-testid="bwx-reminder-ends" disabled={ readOnly } min={ draft.starts_on || undefined } value={ draft.ends_on } onChange={ ( event ) => set( 'ends_on', event.target.value ) } />
        </span>
      </div>

      <div className="bwx-field">
        <label htmlFor="bwx-reminder-hours">Time each (optional)</label>
        <HoursSelect id="bwx-reminder-hours" testId="bwx-reminder-hours" disabled={ readOnly } value={ draft.hours_each } onChange={ ( value ) => set( 'hours_each', value ) } />
        <span className="bwx-hint">Everyone on it gets the full time, on its first day, in Capacity. With no dates it counts on no day.</span>
      </div>

      <fieldset className="bwx-field bwx-recurring-people" data-testid="bwx-reminder-people" disabled={ readOnly }>
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
    </>
  );
}

/** Adding a reminder, from the Reminders page. Changing one happens wherever it is opened (#662). */
export function ReminderForm( {
  people,
  sites,
  site,
  onClose,
  onSaved,
}: {
  people: Person[];
  sites: Site[];
  /** The client a new reminder starts on. */
  site: string;
  onClose: () => void;
  onSaved: () => void;
} ) {
  const [ draft, setDraft ] = useState< ReminderDraft >( () => blank( site ) );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      await api( '/reminders', { method: 'POST', body: { ...body( draft ), client_site_id: draft.client_site_id } } );
      onSaved();
    } catch ( error ) {
      setNotice( problem( error ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Aside
      label="Add reminder"
      testId="bwx-reminder-form"
      width={ 560 }
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <button type="button" className="bwx-button" data-testid="bwx-reminder-save" disabled={ busy || ! complete( draft ) } onClick={ () => void save() }>
            Add
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

      <ReminderFields draft={ draft } onChange={ setDraft } people={ people } sites={ sites } autoFocus />
    </Aside>
  );
}

/**
 * #610. On the task panel a copy opens in, for anyone who cannot change the
 * reminder: whose it is to change. Its author or an administrator changes its
 * fields in the panel itself (#662), and turns it into a task from the foot.
 */
export function ReminderNote( { reminder }: { reminder: Reminder } ) {
  if ( reminder.can_edit ) {
    return null;
  }

  return (
    <div className="bwx-reminder-actions" data-testid="bwx-reminder-note">
      <p className="bwx-eyebrow">Reminder</p>
      <p className="bwx-hint">Only whoever added this reminder, or an administrator, can change it.</p>
    </div>
  );
}
