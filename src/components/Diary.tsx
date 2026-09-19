import { useEffect, useState } from 'react';
import type { CalendarDate, DiaryEntry, Person } from '../types';
import { api, ApiError, forgeData, messageFor } from '../api';
import { everybody } from './ItemPanel';
import { failed, NOTHING_SAID, Notice, ok } from './States';
import type { Said } from './States';

/**
 * The studio's diary (2026-09-18): everything that is not a piece of work but
 * still has a day — a recurring chore, a company day or birthday, a meeting,
 * a subscription renewing, somebody away. One shape, drawn the same way on
 * the calendar and the standup.
 */

/** What each kind of entry is called on a screen, and its colour. */
export const DIARY_KINDS: Record< DiaryEntry[ 'kind' ], { label: string; tone: string } > = {
  recurring: { label: 'Chore', tone: 'teal' },
  date: { label: 'Date', tone: 'violet' },
  meeting: { label: 'Meeting', tone: 'rose' },
  subscription: { label: 'Renewal', tone: 'emerald' },
  leave: { label: 'Away', tone: 'amber' },
};

const DAY = 86400000;

/** Every day an entry covers: one, or a span up to its last day. */
export function daysOf( entry: DiaryEntry ): string[] {
  const from = Date.parse( `${ entry.date }T00:00:00Z` );
  const to = '' === entry.ends_on ? from : Date.parse( `${ entry.ends_on }T00:00:00Z` );
  const days: string[] = [];

  for ( let at = from; at <= to && days.length < 366; at += DAY ) {
    days.push( new Date( at ).toISOString().slice( 0, 10 ) );
  }

  return days;
}

/** The diary's entries, by day, a spanning entry on every day it covers. */
export function diaryByDay( entries: DiaryEntry[] ): Record< string, DiaryEntry[] > {
  const days: Record< string, DiaryEntry[] > = {};

  for ( const entry of entries ) {
    for ( const day of daysOf( entry ) ) {
      ( days[ day ] ??= [] ).push( entry );
    }
  }

  return days;
}

/** Reads the diary for a window. */
export function useDiary( from: string, to: string ): { entries: DiaryEntry[]; reload: () => void } {
  const [ entries, setEntries ] = useState< DiaryEntry[] >( [] );
  const [ generation, setGeneration ] = useState( 0 );

  useEffect( () => {
    let live = true;

    api< { entries: DiaryEntry[] } >( `/calendar?from=${ from }&to=${ to }` )
      .then( ( answer ) => {
        if ( live ) setEntries( answer.entries );
      } )
      .catch( () => {
        if ( live ) setEntries( [] );
      } );

    return () => {
      live = false;
    };
  }, [ from, to, generation ] );

  return { entries, reload: () => setGeneration( ( n ) => n + 1 ) };
}

/** One diary entry, as a line. */
export function DiaryLine( { entry, onOpen }: { entry: DiaryEntry; onOpen?: ( itemId: string ) => void } ) {
  const kind = DIARY_KINDS[ entry.kind ];
  const opens = '' !== entry.item_id && onOpen;

  return (
    <li className="bwx-diary-line" data-testid="bwx-diary-entry" data-kind={ entry.kind } data-entry={ entry.id }>
      <span className="bwx-diary-kind" data-tone={ kind.tone }>{ kind.label }</span>
      { opens ? (
        <button type="button" className="bwx-row-open" onClick={ () => onOpen( entry.item_id ) }>
          { entry.title }
        </button>
      ) : (
        <span className="bwx-diary-title">{ entry.title }</span>
      ) }
      { '' !== entry.detail && <span className="bwx-diary-detail">{ entry.detail }</span> }
    </li>
  );
}

/** What a form shows when the server refuses by field, or otherwise. */
function refusal( error: unknown, fallback: string ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, fallback );
}

function today(): string {
  return new Date().toISOString().slice( 0, 10 );
}

/**
 * Adding a date: what, when, and who for. Administrators only; everyone
 * else reads.
 */
export function AddDate( { onSaved }: { onSaved: () => void } ) {
  const [ people, setPeople ] = useState< Person[] >( [] );
  const [ title, setTitle ] = useState( '' );
  const [ kind, setKind ] = useState< string >( 'company-day' );
  const [ on, setOn ] = useState( today() );
  const [ ends, setEnds ] = useState( '' );
  const [ everyone, setEveryone ] = useState( true );
  const [ who, setWho ] = useState< string[] >( [] );
  const [ note, setNote ] = useState( '' );
  const [ notice, setNotice ] = useState< Said >( NOTHING_SAID );
  const [ busy, setBusy ] = useState( false );

  useEffect( () => {
    void everybody().then( setPeople );
  }, [] );

  async function save() {
    setBusy( true );
    setNotice( NOTHING_SAID );

    try {
      await api< { date: CalendarDate } >( '/calendar-dates', {
        method: 'POST',
        body: { title, kind, on_date: on, ends_on: ends, people: everyone ? 'all' : who, note },
      } );
      setTitle( '' );
      setEnds( '' );
      setNote( '' );
      setNotice( ok( 'Added.' ) );
      onSaved();
    } catch ( error ) {
      setNotice( failed( refusal( error, 'That date could not be saved.' ) ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <div className="bwx-diary-add" data-testid="bwx-diary-add">
      <p className="bwx-eyebrow">Add a date</p>
      <Notice said={ notice } testId="bwx-diary-add-notice" />
      <div className="bwx-diary-add-row">
        <div className="bwx-field">
          <label htmlFor="bwx-date-title">What</label>
          <input id="bwx-date-title" className="bwx-input" data-testid="bwx-date-title" value={ title } onChange={ ( event ) => setTitle( event.target.value ) } />
        </div>
        <div className="bwx-field">
          <label htmlFor="bwx-date-kind">Kind</label>
          <select id="bwx-date-kind" className="bwx-select" data-testid="bwx-date-kind" value={ kind } onChange={ ( event ) => setKind( event.target.value ) }>
            <option value="company-day">Company day</option>
            <option value="birthday">Birthday</option>
            <option value="campaign">Campaign</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div className="bwx-field">
          <label htmlFor="bwx-date-on">Day</label>
          <input id="bwx-date-on" className="bwx-input" type="date" data-testid="bwx-date-on" value={ on } onChange={ ( event ) => setOn( event.target.value ) } />
        </div>
        <div className="bwx-field">
          <label htmlFor="bwx-date-ends">Until (optional)</label>
          <input id="bwx-date-ends" className="bwx-input" type="date" data-testid="bwx-date-ends" min={ on } value={ ends } onChange={ ( event ) => setEnds( event.target.value ) } />
        </div>
      </div>
      <div className="bwx-diary-add-row">
        <div className="bwx-field">
          <label htmlFor="bwx-date-who">Who</label>
          <select id="bwx-date-who" className="bwx-select" data-testid="bwx-date-who" value={ everyone ? 'all' : 'some' } onChange={ ( event ) => setEveryone( 'all' === event.target.value ) }>
            <option value="all">All staff</option>
            <option value="some">Some people</option>
          </select>
        </div>
        <div className="bwx-field">
          <label htmlFor="bwx-date-note">Note (optional)</label>
          <input id="bwx-date-note" className="bwx-input" data-testid="bwx-date-note" maxLength={ 191 } value={ note } onChange={ ( event ) => setNote( event.target.value ) } />
        </div>
      </div>
      { ! everyone && (
        <fieldset className="bwx-recurring-people" data-testid="bwx-date-people">
          <legend>Which people</legend>
          { people.map( ( person ) => (
            <label key={ person.id } className="bwx-recurring-person">
              <input
                type="checkbox"
                data-testid={ `bwx-date-person-${ person.id }` }
                checked={ who.includes( person.id ) }
                onChange={ ( event ) => setWho( event.target.checked ? [ ...who, person.id ] : who.filter( ( one ) => one !== person.id ) ) }
              />
              { person.display_name }
            </label>
          ) ) }
        </fieldset>
      ) }
      <div className="bwx-moves bwx-form-foot">
        <button type="button" className="bwx-button" data-testid="bwx-date-save" disabled={ busy || '' === title.trim() } onClick={ () => void save() }>
          Add date
        </button>
      </div>
    </div>
  );
}

/**
 * The next thirty days as a list, day by day, with the way in for dates.
 */
export function DiaryList( { entries, onOpen, onChanged }: { entries: DiaryEntry[]; onOpen: ( itemId: string ) => void; onChanged: () => void } ) {
  const canManage = forgeData()?.canManage ?? false;
  const byDay = diaryByDay( entries );
  const days = Object.keys( byDay ).sort();

  async function remove( entry: DiaryEntry ) {
    if ( ! window.confirm( `Remove ${ entry.title }?` ) ) {
      return;
    }

    await api( `/calendar-dates/${ entry.id.replace( /^date:/, '' ) }`, { method: 'DELETE' } ).catch( () => undefined );
    onChanged();
  }

  return (
    <div className="bwx-diary" data-testid="bwx-diary">
      { canManage && <AddDate onSaved={ onChanged } /> }

      { 0 === days.length && (
        <p className="bwx-calendar-empty" data-testid="bwx-diary-empty">
          Nothing on the diary for the next thirty days.
        </p>
      ) }

      { days.map( ( day ) => (
        <section key={ day } className="bwx-diary-day" data-testid="bwx-diary-day" data-date={ day }>
          <h3 className="bwx-diary-date">
            { new Date( `${ day }T00:00:00Z` ).toLocaleDateString( 'en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' } ) }
          </h3>
          <ul className="bwx-diary-lines">
            { byDay[ day ].map( ( entry ) => (
              <li key={ entry.id } className="bwx-diary-row">
                <DiaryLine entry={ entry } onOpen={ onOpen } />
                { canManage && 'date' === entry.kind && (
                  <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-date-remove" onClick={ () => void remove( entry ) }>
                    Remove
                  </button>
                ) }
              </li>
            ) ) }
          </ul>
        </section>
      ) ) }
    </div>
  );
}
