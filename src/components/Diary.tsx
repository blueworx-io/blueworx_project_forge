import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { CalendarDays, Cake, Handshake, Plane, Repeat, Receipt, Sparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CalendarDate, DiaryEntry, Person } from '../types';
import { api, ApiError, forgeData, messageFor } from '../api';
import { Aside, Button, EmptyState, Field, Panel, Select, TextInput } from '../kit';
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
/** The picture beside each kind of entry (2026-09-19). */
const DIARY_ICONS: Record< string, LucideIcon > = {
  recurring: Repeat,
  meeting: Handshake,
  subscription: Receipt,
  leave: Plane,
  'date:birthday': Cake,
  'date:campaign': Sparkles,
  date: CalendarDays,
};

/**
 * A meeting's detail starts with its time; pulled out so it reads as a
 * time rather than as the first word of a sentence.
 */
function timeOf( entry: DiaryEntry ): { time: string; rest: string } {
  const match = /^(\d{1,2}:\d{2})(?:\s*·\s*)?(.*)$/.exec( entry.detail );

  return match ? { time: match[ 1 ], rest: match[ 2 ] } : { time: '', rest: entry.detail };
}

export function DiaryLine( { entry, onOpen, action }: { entry: DiaryEntry; onOpen?: ( itemId: string ) => void; action?: ReactNode } ) {
  const kind = DIARY_KINDS[ entry.kind ];
  const opens = '' !== entry.item_id && onOpen;
  // A calendar date's detail starts with what kind of date it is: that
  // becomes its chip, and only the note is left to say beneath.
  const [ flavour, ...noted ] = 'date' === entry.kind ? entry.detail.split( ' · ' ) : [ '' ];
  const Icon = DIARY_ICONS[ `${ entry.kind }:${ flavour.toLowerCase() }` ] ?? DIARY_ICONS[ entry.kind ] ?? CalendarDays;
  const { time, rest } = 'date' === entry.kind ? { time: '', rest: noted.join( ' · ' ) } : timeOf( entry );
  const chip = 'date' === entry.kind && '' !== flavour ? flavour : kind.label;

  return (
    <li className="bwx-diary-line" data-testid="bwx-diary-entry" data-kind={ entry.kind } data-entry={ entry.id } data-tone={ kind.tone }>
      <span className="bwx-diary-icon" aria-hidden="true">
        <Icon size={ 16 } strokeWidth={ 1.75 } />
      </span>
      <span className="bwx-diary-body">
        <span className="bwx-diary-top">
          { opens ? (
            <button type="button" className="bwx-row-open bwx-diary-title" onClick={ () => onOpen( entry.item_id ) }>
              { entry.title }
            </button>
          ) : (
            <span className="bwx-diary-title">{ entry.title }</span>
          ) }
          <span className="bwx-diary-kind" data-tone={ kind.tone }>{ chip }</span>
        </span>
        { ( '' !== rest || '' !== time ) && (
          <span className="bwx-diary-detail">
            { '' !== time && <span className="bwx-diary-time fk-mono">{ time }</span> }
            { rest }
          </span>
        ) }
      </span>
      { action }
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
 * else reads. A side panel (2026-09-19), like every other form that adds
 * something.
 */
export function AddDate( { onSaved, onClose }: { onSaved: () => void; onClose: () => void } ) {
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
    <Aside
      label="Add a date"
      testId="bwx-diary-add"
      width={ 520 }
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-date-save" disabled={ busy || '' === title.trim() } onClick={ () => void save() }>
            Add date
          </Button>
          <Button variant="ghost" onClick={ onClose }>
            Close
          </Button>
        </div>
      }
    >
      <Notice said={ notice } testId="bwx-diary-add-notice" onClose={ () => setNotice( NOTHING_SAID ) } />
      <Field label="What" required>
        { ( id ) => <TextInput id={ id } data-testid="bwx-date-title" autoFocus maxLength={ 191 } value={ title } onChange={ ( event ) => setTitle( event.target.value ) } /> }
      </Field>
      <Field label="Kind">
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-date-kind"
            value={ kind }
            options={ [
              { value: 'company-day', label: 'Company day' },
              { value: 'birthday', label: 'Birthday' },
              { value: 'campaign', label: 'Campaign' },
              { value: 'other', label: 'Other' },
            ] }
            onChange={ ( event ) => setKind( event.target.value ) }
          />
        ) }
      </Field>
      <div className="bwx-pair">
        <Field label="Day" required>
          { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-date-on" value={ on } onChange={ ( event ) => setOn( event.target.value ) } /> }
        </Field>
        <Field label="Until" help="Leave empty for one day.">
          { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-date-ends" min={ on } value={ ends } onChange={ ( event ) => setEnds( event.target.value ) } /> }
        </Field>
      </div>
      <Field label="Who">
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-date-who"
            value={ everyone ? 'all' : 'some' }
            options={ [ { value: 'all', label: 'All staff' }, { value: 'some', label: 'Some people' } ] }
            onChange={ ( event ) => setEveryone( 'all' === event.target.value ) }
          />
        ) }
      </Field>
      { ! everyone && (
        <fieldset className="bwx-field bwx-recurring-people" data-testid="bwx-date-people">
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
      <Field label="Note">
        { ( id ) => <TextInput id={ id } data-testid="bwx-date-note" maxLength={ 191 } value={ note } onChange={ ( event ) => setNote( event.target.value ) } /> }
      </Field>
    </Aside>
  );
}

/**
 * The next thirty days as a list, day by day, with the way in for dates.
 */
export function DiaryList( { entries, onOpen, onChanged }: { entries: DiaryEntry[]; onOpen: ( itemId: string ) => void; onChanged: () => void } ) {
  const canManage = forgeData()?.canManage ?? false;
  const byDay = diaryByDay( entries );
  const days = Object.keys( byDay ).sort();
  const [ adding, setAdding ] = useState( false );

  async function remove( entry: DiaryEntry ) {
    if ( ! window.confirm( `Remove ${ entry.title }?` ) ) {
      return;
    }

    await api( `/calendar-dates/${ entry.id.replace( /^date:/, '' ) }`, { method: 'DELETE' } ).catch( () => undefined );
    onChanged();
  }

  return (
    <div className="bwx-diary" data-testid="bwx-diary">
      { canManage && (
        <div className="bwx-diary-tools">
          <Button size="sm" data-testid="bwx-date-add" onClick={ () => setAdding( true ) }>
            Add a date
          </Button>
        </div>
      ) }
      { adding && <AddDate onSaved={ onChanged } onClose={ () => setAdding( false ) } /> }

      { 0 === days.length && (
        <div data-testid="bwx-diary-empty">
          <EmptyState icon={ CalendarDays } title="Nothing on the diary" body="Nothing for the next thirty days: no chores, dates, meetings, renewals or time off." />
        </div>
      ) }

      { days.map( ( day ) => (
        <section key={ day } className="bwx-diary-day" data-testid="bwx-diary-day" data-date={ day }>
          <Panel
            title={ new Date( `${ day }T00:00:00Z` ).toLocaleDateString( 'en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' } ) }
            right={ <span className="fk-mono">{ `${ byDay[ day ].length } ${ 1 === byDay[ day ].length ? 'thing' : 'things' }` }</span> }
            pad={ 0 }
          >
            <ul className="bwx-diary-lines bwx-diary-lines--panel">
              { byDay[ day ].map( ( entry ) => (
                <li key={ entry.id } className="bwx-diary-row">
                  <DiaryLine entry={ entry } onOpen={ onOpen } />
                  { canManage && 'date' === entry.kind && (
                    <Button variant="ghost" size="sm" data-testid="bwx-date-remove" onClick={ () => void remove( entry ) }>
                      Remove
                    </Button>
                  ) }
                </li>
              ) ) }
            </ul>
          </Panel>
        </section>
      ) ) }
    </div>
  );
}
