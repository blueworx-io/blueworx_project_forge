import { useEffect, useState } from 'react';
import { CalendarCheck } from 'lucide-react';
import type { AvailabilityAnswer, AvailabilityPattern, LeaveRecord, Person } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState, Panel, Select, Stat } from '../kit';
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

  const leaveColumns: Column< LeaveRecord >[] = [
    { key: 'starts', label: 'From', mono: true, width: 120, sortBy: ( l ) => l.starts_on, render: ( l ) => l.starts_on },
    { key: 'ends', label: 'To', mono: true, width: 120, sortBy: ( l ) => l.ends_on, render: ( l ) => l.ends_on },
    { key: 'kind', label: 'Kind', width: 140, render: ( l ) => kindLabel( l.kind ) },
    { key: 'note', label: 'Note', wrap: true, render: ( l ) => l.note || '—' },
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
              </div>
            ) : (
              <p className="bwx-notice" data-testid="bwx-availability-recorded" data-recorded="no" role="status">
                Nobody has said what this person&apos;s hours are, so nothing can be planned against them yet. That is different from having no time.
              </p>
            ) }
          </Panel>

          <Panel title="Working week">
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

          <Panel title="Time off">
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
        </>
      ) }
    </div>
  );
}
