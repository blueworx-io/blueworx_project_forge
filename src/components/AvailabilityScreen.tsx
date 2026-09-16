import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { CalendarCheck } from 'lucide-react';
import type { AvailabilityAnswer, AvailabilityPattern, LeaveRecord, Person } from '../types';
import { api, ApiError, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, DataView, EmptyState, Field, Panel, Select, Stat, TextInput } from '../kit';
import type { Column } from '../kit';
import { everybody } from './ItemPanel';
import { Screen } from './States';

/**
 * A person's working week and time off (#136), in the app.
 *
 * The first of the configuration screens to leave WordPress admin. Reads and
 * writes `/users/<id>/availability` and nothing else; the admin page stays
 * beside it until every screen has moved.
 *
 * The distinction the whole screen turns on is kept from the admin page:
 * nothing recorded is not the same as no hours, and a zero would read as the
 * second. So an unrecorded person is told in words, not shown 0h.
 */

/** The seven columns, Monday first for reading, keyed as the server keys them. */
export const DAYS: Array< [ keyof AvailabilityPattern & `hours_${ string }`, string ] > = [
  [ 'hours_mon', 'Mon' ],
  [ 'hours_tue', 'Tue' ],
  [ 'hours_wed', 'Wed' ],
  [ 'hours_thu', 'Thu' ],
  [ 'hours_fri', 'Fri' ],
  [ 'hours_sat', 'Sat' ],
  [ 'hours_sun', 'Sun' ],
];

export const KINDS: Array< { value: LeaveRecord[ 'kind' ]; label: string } > = [
  { value: 'leave', label: 'Leave' },
  { value: 'public-holiday', label: 'Public holiday' },
  { value: 'training', label: 'Training' },
  { value: 'other', label: 'Other' },
];

function kindLabel( kind: string ): string {
  return KINDS.find( ( one ) => one.value === kind )?.label ?? kind;
}

/** 8 → "8", 7.5 → "7.5". */
function hours( value: number ): string {
  return String( Number( value.toFixed( 2 ) ) );
}

export function AvailabilityScreen( { person }: { person: string } ) {
  const [ people, setPeople ] = useState< Person[] >( [] );
  const [ personId, setPersonId ] = useState( person );
  const [ answer, setAnswer ] = useState< AvailabilityAnswer | null >( null );
  const [ state, setState ] = useState< 'idle' | 'loading' | 'ready' | 'denied' | 'error' >( person ? 'loading' : 'idle' );
  const [ notice, setNotice ] = useState( '' );
  const [ panel, setPanel ] = useState< 'hours' | 'leave' | null >( null );
  const [ busy, setBusy ] = useState( false );

  /** A write's answer is the whole picture, so it is shown rather than re-read. */
  function landed( fresh: AvailabilityAnswer ) {
    setAnswer( fresh );
    setPanel( null );
  }

  async function removeLeave( record: LeaveRecord ) {
    if ( ! window.confirm( `Remove ${ kindLabel( record.kind ).toLowerCase() } from ${ record.starts_on } to ${ record.ends_on }?` ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      landed( await api< AvailabilityAnswer >( `/users/${ personId }/leave/${ record.id }`, { method: 'DELETE' } ) );
    } catch ( error ) {
      setNotice( messageFor( error, 'That time off could not be removed.' ) );
    } finally {
      setBusy( false );
    }
  }

  async function load( id: string = personId ) {
    setNotice( '' );

    if ( '' === id ) {
      setAnswer( null );
      setState( 'idle' );

      return;
    }

    try {
      const fresh = await api< AvailabilityAnswer >( `/users/${ id }/availability` );

      setAnswer( fresh );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Availability could not be read.' ) );
    }
  }

  useEffect( () => {
    void everybody().then( setPeople );
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load( person );
    // The person prop is a landing, read once; picking is the select's job.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( () => load() );

  function pick( id: string ) {
    setPersonId( id );
    setState( id ? 'loading' : 'idle' );
    void load( id );
  }

  const historyColumns: Column< AvailabilityPattern >[] = [
    { key: 'from', label: 'From', mono: true, width: 120, sortBy: ( p ) => p.effective_from, render: ( p ) => p.effective_from },
    ...DAYS.map( ( [ key, label ] ): Column< AvailabilityPattern > => ( {
      key,
      label,
      mono: true,
      align: 'right',
      width: 56,
      render: ( p ) => hours( p[ key ] ),
    } ) ),
    { key: 'week', label: 'Week', mono: true, align: 'right', width: 72, sortBy: ( p ) => p.hours_week, render: ( p ) => `${ hours( p.hours_week ) }h` },
    { key: 'note', label: 'Note', wrap: true, render: ( p ) => p.note || '—' },
  ];

  const dayColumns: Column< AvailabilityAnswer[ 'week' ][ 'days' ][ number ] >[] = [
    { key: 'date', label: 'Date', mono: true, width: 120, render: ( d ) => d.date },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 80, render: ( d ) => `${ hours( d.hours ) }h` },
    { key: 'base_hours', label: 'Base', mono: true, align: 'right', width: 80, render: ( d ) => `${ hours( d.base_hours ) }h` },
    { key: 'reason', label: 'Why', wrap: true, render: ( d ) => d.reason || '—' },
  ];

  const leaveColumns: Column< LeaveRecord >[] = [
    { key: 'starts', label: 'From', mono: true, width: 120, sortBy: ( l ) => l.starts_on, render: ( l ) => l.starts_on },
    { key: 'ends', label: 'To', mono: true, width: 120, sortBy: ( l ) => l.ends_on, render: ( l ) => l.ends_on },
    { key: 'kind', label: 'Kind', width: 140, render: ( l ) => kindLabel( l.kind ) },
    { key: 'note', label: 'Note', wrap: true, render: ( l ) => l.note || '—' },
    {
      key: 'actions',
      label: '',
      width: 100,
      render: ( l ) => (
        <Button variant="ghost" size="sm" data-testid="bwx-availability-leave-remove" disabled={ busy } onClick={ () => void removeLeave( l ) }>
          Remove
        </Button>
      ),
    },
  ];

  return (
    <div className="bwx-availability" data-testid="bwx-availability">
      <div className="bwx-availability-picker">
        <label htmlFor="bwx-availability-person">Person</label>
        <Select
          id="bwx-availability-person"
          data-testid="bwx-availability-person"
          value={ personId }
          onChange={ ( event ) => pick( event.target.value ) }
          options={ [ { value: '', label: 'Pick a person' }, ...people.map( ( one ) => ( { value: one.id, label: one.display_name } ) ) ] }
        />
      </div>

      { 'idle' === state && (
        <EmptyState icon={ CalendarCheck } title="Pick a person" body="Their working week and time off show here, and can be changed here." />
      ) }
      { 'loading' === state && <Screen state="loading" testId="bwx-availability-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-availability-state" detail="Working hours are configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-availability-state" detail={ notice } /> }

      { 'ready' === state && answer && (
        <>
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-availability-notice" role="status">
              { notice }
            </p>
          ) }

          <Panel title="This week">
            { answer.recorded ? (
              <div data-testid="bwx-availability-recorded" data-recorded="yes">
                <Stat
                  label="Available hours"
                  value={ <span data-testid="bwx-availability-week-hours">{ `${ hours( answer.week.hours ) }h` }</span> }
                  sub={ `Across the next seven days, ${ answer.week.from } to ${ answer.week.to }.` }
                />
                <DataView< AvailabilityAnswer[ 'week' ][ 'days' ][ number ] >
                  columns={ dayColumns }
                  rows={ answer.week.days }
                  testId="bwx-availability-days"
                />
              </div>
            ) : (
              <p className="bwx-notice" data-testid="bwx-availability-recorded" data-recorded="no" role="status">
                Nobody has said what this person&apos;s hours are, so nothing can be planned against them yet. That is different from having no time.
              </p>
            ) }
          </Panel>

          <Panel
            title="Working week"
            right={
              <Button size="sm" data-testid="bwx-availability-set-hours" disabled={ busy } onClick={ () => setPanel( 'hours' ) }>
                Set hours
              </Button>
            }
          >
            { answer.current ? (
              <dl className="bwx-availability-week" data-testid="bwx-availability-current">
                { DAYS.map( ( [ key, label ] ) => (
                  <div key={ key }>
                    <dt>{ label }</dt>
                    <dd data-testid={ `bwx-availability-day-${ key }` }>{ hours( answer.current?.[ key ] ?? 0 ) }</dd>
                  </div>
                ) ) }
                <div>
                  <dt>Week</dt>
                  <dd>{ `${ hours( answer.current.hours_week ) }h` }</dd>
                </div>
              </dl>
            ) : (
              <p className="bwx-hint">No working week recorded yet.</p>
            ) }

            <DataView< AvailabilityPattern >
              title="History"
              columns={ historyColumns }
              rows={ answer.history }
              sortable
              defaultSort={ { key: 'from', dir: 'desc' } }
              empty={ <p className="bwx-hint">Nothing recorded yet.</p> }
              testId="bwx-availability-history"
            />
          </Panel>

          <Panel
            title="Time off"
            right={
              <Button size="sm" data-testid="bwx-availability-add-leave" disabled={ busy } onClick={ () => setPanel( 'leave' ) }>
                Add time off
              </Button>
            }
          >
            <DataView< LeaveRecord >
              columns={ leaveColumns }
              rows={ answer.leave }
              sortable
              defaultSort={ { key: 'starts', dir: 'desc' } }
              empty={ <EmptyState icon={ CalendarCheck } dense title="No time off recorded" body="Nothing recorded in the year either side of today." /> }
              footer={ `${ answer.leave.length } recorded · a year either side of today` }
              testId="bwx-availability-leave"
            />
            { 0 === answer.leave.length && (
              <span data-testid="bwx-availability-no-leave" className="bwx-visually-hidden">
                No time off recorded
              </span>
            ) }
          </Panel>

          { 'hours' === panel && <HoursForm personId={ personId } current={ answer.current } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
          { 'leave' === panel && <LeaveForm personId={ personId } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
        </>
      ) }
    </div>
  );
}

/** What a form shows when the server refuses by field, or otherwise. */
function refusal( error: unknown, fallback: string ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, fallback );
}

/** The side panel both forms sit in, shaped as the recurring form is. */
function Aside( { label, testId, onClose, children }: { label: string; testId: string; onClose: () => void; children: ReactNode } ) {
  return (
    <div className="bwx-panel-scrim" onClick={ ( event ) => event.target === event.currentTarget && onClose() }>
      <aside className="bwx-panel" role="dialog" aria-modal="true" aria-label={ label } data-testid={ testId } onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }>
        <header className="bwx-panel-head">
          <h2 className="bwx-panel-title">{ label }</h2>
          <button type="button" className="bwx-icon-button" data-testid="bwx-availability-panel-close" onClick={ onClose } aria-label="Close">
            ✕
          </button>
        </header>
        { children }
      </aside>
    </div>
  );
}

function today(): string {
  return new Date().toISOString().slice( 0, 10 );
}

/** Recording a working week from a date. Starts from the week in force, so a small change is a small edit. */
function HoursForm( {
  personId,
  current,
  onClose,
  onSaved,
}: {
  personId: string;
  current: AvailabilityPattern | null;
  onClose: () => void;
  onSaved: ( answer: AvailabilityAnswer ) => void;
} ) {
  const [ from, setFrom ] = useState( today() );
  const [ week, setWeek ] = useState< Record< string, string > >( () =>
    Object.fromEntries( DAYS.map( ( [ key ] ) => [ key, current ? hours( current[ key ] ) : '' ] ) )
  );
  const [ note, setNote ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    const body: Record< string, unknown > = { effective_from: from, note };

    for ( const [ key ] of DAYS ) {
      body[ key ] = Number( week[ key ] ) || 0;
    }

    try {
      onSaved( await api< AvailabilityAnswer >( `/users/${ personId }/availability/hours`, { method: 'POST', body } ) );
    } catch ( error ) {
      setNotice( refusal( error, 'Those hours could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Aside label="Set working hours" testId="bwx-availability-hours-form" onClose={ onClose }>
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-availability-hours-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="From" required help="A new week from this date. Earlier weeks keep the hours they had.">
        { ( id ) => <TextInput id={ id } type="date" autoFocus data-testid="bwx-availability-effective-from" value={ from } onChange={ ( event ) => setFrom( event.target.value ) } /> }
      </Field>

      <div className="bwx-availability-hours-grid">
        { DAYS.map( ( [ key, label ] ) => (
          <Field key={ key } label={ label }>
            { ( id ) => (
              <TextInput
                id={ id }
                type="number"
                min="0"
                step="0.25"
                inputMode="decimal"
                data-testid={ `bwx-availability-hours-${ key }` }
                value={ week[ key ] }
                onChange={ ( event ) => setWeek( { ...week, [ key ]: event.target.value } ) }
              />
            ) }
          </Field>
        ) ) }
      </div>

      <Field label="Note">
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-availability-hours-note" value={ note } onChange={ ( event ) => setNote( event.target.value ) } /> }
      </Field>

      <div className="bwx-moves">
        <Button data-testid="bwx-availability-hours-save" disabled={ busy } onClick={ () => void save() }>
          Save
        </Button>
        <Button variant="ghost" data-testid="bwx-availability-hours-cancel" onClick={ onClose }>
          Cancel
        </Button>
      </div>
    </Aside>
  );
}

/** Recording time somebody is away. */
function LeaveForm( { personId, onClose, onSaved }: { personId: string; onClose: () => void; onSaved: ( answer: AvailabilityAnswer ) => void } ) {
  const [ starts, setStarts ] = useState( today() );
  const [ ends, setEnds ] = useState( today() );
  const [ kind, setKind ] = useState< LeaveRecord[ 'kind' ] >( 'leave' );
  const [ note, setNote ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< AvailabilityAnswer >( `/users/${ personId }/leave`, {
          method: 'POST',
          body: { starts_on: starts, ends_on: ends, kind, note },
        } )
      );
    } catch ( error ) {
      setNotice( refusal( error, 'That time off could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Aside label="Add time off" testId="bwx-availability-leave-form" onClose={ onClose }>
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-availability-leave-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="First day" required>
        { ( id ) => <TextInput id={ id } type="date" autoFocus data-testid="bwx-availability-leave-starts" value={ starts } onChange={ ( event ) => setStarts( event.target.value ) } /> }
      </Field>
      <Field label="Last day" required help="Both days are included.">
        { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-availability-leave-ends" value={ ends } onChange={ ( event ) => setEnds( event.target.value ) } /> }
      </Field>
      <Field label="Kind">
        { ( id ) => <Select id={ id } data-testid="bwx-availability-leave-kind" value={ kind } onChange={ ( event ) => setKind( event.target.value as LeaveRecord[ 'kind' ] ) } options={ KINDS } /> }
      </Field>
      <Field label="Note">
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-availability-leave-note" value={ note } onChange={ ( event ) => setNote( event.target.value ) } /> }
      </Field>

      <div className="bwx-moves">
        <Button data-testid="bwx-availability-leave-save" disabled={ busy } onClick={ () => void save() }>
          Add
        </Button>
        <Button variant="ghost" data-testid="bwx-availability-leave-cancel" onClick={ onClose }>
          Cancel
        </Button>
      </div>
    </Aside>
  );
}
