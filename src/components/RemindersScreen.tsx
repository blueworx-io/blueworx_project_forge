import { useEffect, useState } from 'react';
import { Bell } from 'lucide-react';
import type { Person, Reminder, Stage } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState } from '../kit';
import type { Column } from '../kit';
import { everybody, ItemPanel } from './ItemPanel';
import { hourLabel } from '../hours';
import { Screen } from './States';
import { useClientChoice } from '../ClientChoice';
import { ALL_SITES } from '../sites';
import { CATEGORIES, convertReminder, hasOpen, ReminderForm, siteLabel } from './ReminderForm';
import type { Site } from './ReminderForm';

/**
 * Reminders (2026-09-25): a task on a fixed day or over a few, for one or
 * more people, on a client's site. Saving one makes each person their own
 * task straight away, which they see in My tasks and tick off; the calendar
 * shows it on every day it covers. Anyone adds one; its author or an
 * administrator changes it, and a change reaches only the copies nobody has
 * ticked.
 */

/** "3 Oct", "1 Oct – 5 Oct" for a period, "Until 5 Oct", or "No date" (#611). */
function when( reminder: Reminder ): string {
  const day = ( date: string ) => new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' } );

  if ( '' === reminder.starts_on ) {
    return '' === reminder.ends_on ? 'No date' : `Until ${ day( reminder.ends_on ) }`;
  }

  return '' === reminder.ends_on || reminder.ends_on === reminder.starts_on ? day( reminder.starts_on ) : `${ day( reminder.starts_on ) } – ${ day( reminder.ends_on ) }`;
}

/** Finished once every copy is ticked. A reminder with no copies left is nobody's to do, and counts too. A converted one is not listed at all (#612). */
function isCompleted( reminder: Reminder ): boolean {
  return reminder.copies.every( ( copy ) => copy.done );
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
  // A reminder everybody has ticked is finished, and leaves the list unless asked for (2026-10-05).
  const [ showCompleted, setShowCompleted ] = useState( false );
  // The top bar's client (#402): the list narrows, and a new one starts on it.
  const { siteId, label } = useClientChoice();
  const forClient = reminders.filter( ( one ) => ALL_SITES === siteId || one.client_site_id === siteId );
  const completed = forClient.filter( isCompleted ).length;
  const shown = showCompleted ? forClient : forClient.filter( ( one ) => ! isCompleted( one ) );

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

  // #574. One task for everyone still to do it, which opens; the reminder goes (#612).
  async function convert( reminder: Reminder ) {
    setBusy( true );
    setNotice( '' );

    try {
      const task = await convertReminder( reminder );

      if ( '' !== task ) {
        await load();
        setOpened( task );
      }
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
    { key: 'category', label: 'Type', width: 120, sortBy: ( r ) => r.category, render: ( r ) => CATEGORIES.find( ( one ) => one.id === r.category )?.label ?? 'General' },
    { key: 'client', label: 'Client', width: 200, sortBy: ( r ) => client( r.client_site_id ), render: ( r ) => client( r.client_site_id ) },
    { key: 'who', label: 'Who', width: 200, wrap: true, render: ( r ) => r.assignees.map( name ).join( ', ' ) },
    { key: 'when', label: 'When', width: 150, sortBy: ( r ) => r.starts_on, render: ( r ) => when( r ) },
    { key: 'time', label: 'Time each', width: 110, sortBy: ( r ) => r.hours_each, render: ( r ) => ( 0 < r.hours_each ? hourLabel( r.hours_each ) : '—' ) },
    { key: 'done', label: 'Done', width: 170, render: ( r ) => `${ r.copies.filter( ( copy ) => copy.done ).length } of ${ r.copies.length } done` },
    {
      key: 'actions',
      label: '',
      width: 260,
      render: ( r ) =>
        r.can_edit ? (
          <span className="bwx-recurring-actions">
            { hasOpen( r ) && (
              <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-reminder-convert" disabled={ busy } onClick={ () => void convert( r ) }>
                Convert to task
              </button>
            ) }
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
              <>
                <button
                  type="button"
                  className="bwx-button"
                  data-variant="quiet"
                  data-testid="bwx-reminders-completed"
                  aria-pressed={ showCompleted }
                  onClick={ () => setShowCompleted( ! showCompleted ) }
                >
                  { showCompleted ? 'Hide completed' : `Show completed${ 0 < completed ? ` (${ completed })` : '' }` }
                </button>
                <button type="button" className="bwx-button" data-testid="bwx-reminders-add" disabled={ busy } onClick={ () => setEditing( 'new' ) }>
                  Add reminder
                </button>
              </>
            }
            columns={ columns }
            rows={ shown }
            sortable
            empty={
              <EmptyState
                icon={ Bell }
                dense
                title={ 0 < completed ? 'Everything here is done' : ALL_SITES === siteId ? 'No reminders yet' : `Nothing for ${ label() } here.` }
                body={ 0 < completed ? 'Show completed to see them.' : 'Add one for a day or a few, and each person gets their own task to tick off.' }
              />
            }
            footer={ `${ shown.length } reminders${ showCompleted ? '' : ` · ${ completed } completed hidden` } · each person gets their own task as soon as a reminder is saved` }
            testId="bwx-reminders-table"
          />
        </div>
      ) }

      { null !== editing && (
        <ReminderForm
          reminder={ 'new' === editing ? null : editing }
          people={ people }
          sites={ sites }
          site={ ALL_SITES === siteId ? '' : siteId }
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
