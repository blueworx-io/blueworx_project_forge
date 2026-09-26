import { useCallback, useEffect, useState } from 'react';
import type {
  CapacityAllocation,
  CapacityBand,
  CapacityBy,
  CapacityCell,
  CapacityDrilldown,
  CapacityPosition,
  CapacityResponse,
  Stage,
} from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Aside } from '../kit';
import { durationLabel } from '../duration.mjs';
import { ItemPanel } from './ItemPanel';
import { Screen } from './States';

/**
 * The studio's picture of who has room (#139).
 *
 * People down the side, days or weeks across the top. It draws what the server says and
 * works nothing out for itself — the moment a screen recalculates a total it is
 * showing a figure no gate ever refused on, and the two disagree in front of
 * whoever is trying to plan.
 *
 * The cell is the one thing here that had to be designed rather than reused. A
 * quarter is eight or ten columns wide, and three numbers per cell is a wall of
 * digits nobody reads. So each cell is a bar filled to the share of the week
 * that is spoken for, with the figures under it: a row can be read as the shape
 * of somebody's quarter at a glance, and the exact numbers are still there when
 * the shape raises a question. The band is in the fill length and in the words
 * as well as the colour, because this is the screen people decide staffing on.
 *
 * Every cell opens. A capacity figure nobody can take apart is a figure people
 * work around rather than with, so the panel names the work behind it and the
 * days somebody is away.
 */

/**
 * The range a cut opens on. By the day, the next fourteen days from today:
 * the question is who has room now. By the week, eight weeks from the Monday
 * of this one: the shape of the quarter.
 */
function defaultRange( by: CapacityBy ): { from: string; to: string } {
  const today = new Date();

  if ( 'days' === by ) {
    const end = new Date( today );

    end.setDate( today.getDate() + 13 );

    return { from: iso( today ), to: iso( end ) };
  }

  const monday = new Date( today );

  monday.setDate( today.getDate() - ( ( today.getDay() + 6 ) % 7 ) );

  const end = new Date( monday );

  end.setDate( monday.getDate() + 55 );

  return { from: iso( monday ), to: iso( end ) };
}

/** The date as the person sees it, not as UTC has it — just after midnight they differ. */
function iso( date: Date ): string {
  const pad = ( value: number ) => String( value ).padStart( 2, '0' );

  return `${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad( date.getDate() ) }`;
}

/**
 * A column header: a day says which day it is, a week says the date it starts.
 * No year on either — it would repeat across every column.
 */
function columnLabel( from: string, by: CapacityBy ): string {
  const [ year, month, day ] = from.split( '-' );
  const date = `${ Number( day ) } ${ MONTHS[ Number( month ) - 1 ] ?? '' }`.trim();

  if ( 'weeks' === by ) {
    return date;
  }

  const weekday = new Date( Date.UTC( Number( year ), Number( month ) - 1, Number( day ) ) ).getUTCDay();

  return `${ WEEKDAYS[ weekday ] } ${ date }`;
}

const MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
const WEEKDAYS = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];

/**
 * What a band means, in the words somebody would use about it.
 *
 * Not a colour key. "Unrecorded" is the one that matters: a person nobody has
 * set up is not a person with no room, and the two need different things doing
 * about them.
 */
const BAND_WORD: Record< CapacityBand, string > = {
  clear: 'Room',
  tight: 'Tight',
  over: 'Over',
  unrecorded: 'Hours not set',
};

const ROLE_WORD: Record< string, string > = {
  primary: 'Doing the work',
  review: 'Reviewing',
  delivery: 'Delivering',
  assignee: 'Assigned',
  meeting: 'Meeting',
};

/** Why a day is worth nothing, said plainly. */
function reasonWord( reason: string ): string {
  if ( 'no-pattern' === reason ) {
    return 'hours not set';
  }

  if ( 'non-working-day' === reason ) {
    return 'not a working day';
  }

  return reason;
}

/**
 * How full a cell is, as two shares for the bar: finished work first, then
 * what is still to do (#384). Together they run full and no further; where
 * there is more than fits, each keeps its proportion of the whole.
 */
function fill( position: CapacityPosition ): { done: number; todo: number } {
  const used = position.committed + position.completed;

  if ( used <= 0 ) {
    return { done: 0, todo: 0 };
  }

  const whole = position.available <= 0 ? 1 : Math.min( 1, used / position.available );

  return { done: whole * ( position.completed / used ), todo: whole * ( position.committed / used ) };
}

/**
 * The hours of one allocation that land in the window the panel is about.
 * An allocation can run for a fortnight; the day panel answers for its day.
 */
function hoursIn( allocation: CapacityAllocation ): number {
  return Object.values( allocation.by_day ?? {} ).reduce( ( total, hours ) => total + hours, 0 );
}

/**
 * Whether an allocation is a work item that can be opened. A meeting is not,
 * and neither is a schedule's day ahead, whose task does not exist yet.
 */
function opens( allocation: CapacityAllocation ): boolean {
  return 'meeting' !== allocation.role && ! allocation.item_id.includes( ':' ) && '' !== allocation.item_id;
}

export function CapacityScreen() {
  const [ by, setBy ] = useState< CapacityBy >( 'days' );
  const [ range, setRange ] = useState( () => defaultRange( 'days' ) );
  const [ data, setData ] = useState< CapacityResponse | undefined >();
  const [ open, setOpen ] = useState< CapacityDrilldown | undefined >();
  const [ opened, setOpened ] = useState( '' );
  const [ stages, setStages ] = useState< Stage[] | undefined >();
  const [ notice, setNotice ] = useState( '' );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );

  const load = useCallback( async () => {
    try {
      setData( await api< CapacityResponse >( `/capacity?from=${ range.from }&to=${ range.to }&by=${ by }` ) );
      setState( 'ready' );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'The capacity picture could not be read.' ) );
      setState( isDenied( failure ) ? 'denied' : 'error' );
    }
  }, [ range.from, range.to, by ] );

  // Reading on mount, the same way the other screens do — and again when the
  // dates change, which is the one difference: the range is a control here, so
  // load() is a dependency rather than an empty list.
  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [ load ] );

  useLiveReload( load );

  /*
   * Changing the dates is what starts a read, so it is what says the screen is
   * loading. Saying so from inside the effect instead would set state during a
   * render pass the effect is already reacting to, which is a cascade React
   * asks you not to write.
   */
  function ask( next: { from: string; to: string } ) {
    setState( 'loading' );
    setRange( next );
  }

  /** Switching the cut also resets the range to the one that cut opens on. */
  function cut( next: CapacityBy ) {
    if ( next === by ) {
      return;
    }

    setState( 'loading' );
    setBy( next );
    setRange( defaultRange( next ) );
  }

  async function openCell( userId: string, from: string, to: string ) {
    try {
      setOpen( await api< CapacityDrilldown >( `/capacity/person/${ userId }?from=${ from }&to=${ to }` ) );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'That could not be opened.' ) );
    }
  }

  /*
   * A task in the day panel opens in the same item panel every other screen
   * uses (#385). The stages it needs are read the first time, not on every
   * visit to this screen, so the grid itself costs nothing more to show.
   */
  async function openItem( itemId: string ) {
    try {
      if ( undefined === stages ) {
        setStages( ( await api< { stages: Stage[] } >( '/stages' ) ).stages );
      }

      setOpen( undefined );
      setOpened( itemId );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'That could not be opened.' ) );
    }
  }

  return (
    <>
      <header className="bwx-header" data-testid="bwx-capacity-header">
        <span className="bwx-eyebrow">Capacity</span>

        <div className="bwx-views" role="group" aria-label="Cut">
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'days' === by ? undefined : 'quiet' }
            data-testid="bwx-capacity-by-days"
            aria-pressed={ 'days' === by }
            onClick={ () => cut( 'days' ) }
          >
            Days
          </button>
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'weeks' === by ? undefined : 'quiet' }
            data-testid="bwx-capacity-by-weeks"
            aria-pressed={ 'weeks' === by }
            onClick={ () => cut( 'weeks' ) }
          >
            Weeks
          </button>
        </div>

        <label className="bwx-field-inline">
          <span>From</span>
          <input
            type="date"
            className="bwx-input"
            data-testid="bwx-capacity-from"
            value={ range.from }
            onChange={ ( event ) => ask( { ...range, from: event.target.value } ) }
          />
        </label>

        <label className="bwx-field-inline">
          <span>To</span>
          <input
            type="date"
            className="bwx-input"
            data-testid="bwx-capacity-to"
            value={ range.to }
            onChange={ ( event ) => ask( { ...range, to: event.target.value } ) }
          />
        </label>

        <span className="bwx-header-spacer" />

        <span className="bwx-mono" data-testid="bwx-capacity-count">
          { data?.people.length ?? 0 } { 1 === data?.people.length ? 'person' : 'people' }
        </span>
      </header>

      { '' !== notice && 'error' !== state && 'denied' !== state && (
        <p className="bwx-notice" role="status" style={ { margin: '12px 20px 0' } }>
          { notice }
        </p>
      ) }

      { 'loading' === state && <Screen state="loading" detail="Working out who has room." /> }

      { 'denied' === state && (
        <Screen
          state="denied"
          testId="bwx-capacity-state-screen"
          detail="You are signed in, but not allowed to see staff against capacity. Ask for that on one of your memberships."
        />
      ) }

      { 'error' === state && (
        <Screen
          state="error"
          testId="bwx-capacity-state-screen"
          detail={ notice }
          action={
            <button
              type="button"
              className="bwx-button"
              onClick={ () => {
                setState( 'loading' );
                void load();
              } }
            >
              Try again
            </button>
          }
        />
      ) }

      { 'ready' === state && 0 === ( data?.people.length ?? 0 ) && (
        <Screen
          state="empty"
          testId="bwx-capacity-state-screen"
          title="Nobody to show"
          detail="Add people, and set their working hours, before capacity means anything."
        />
      ) }

      { 'ready' === state && undefined !== data && 0 < data.people.length && (
        <div className="bwx-capacity">
          <p className="bwx-capacity-legend" data-testid="bwx-capacity-legend">
            <span className="bwx-capacity-key" data-kind="todo" aria-hidden="true" />
            Still to do (coloured by how full the day is)
            <span className="bwx-capacity-key" data-kind="done" aria-hidden="true" />
            Done
          </p>
          <table className="bwx-capacity-grid" data-testid="bwx-capacity-grid">
            <caption className="bwx-visually-hidden">
              Available, still to do and done hours per person, { 'days' === by ? 'day by day' : 'week by week' }
            </caption>
            <thead>
              <tr>
                <th scope="col" className="bwx-capacity-person">
                  Person
                </th>
                { data.periods.map( ( period ) => (
                  <th key={ period.from } scope="col" data-from={ period.from }>
                    { columnLabel( period.from, data.by ) }
                  </th>
                ) ) }
                <th scope="col" className="bwx-capacity-total">
                  Whole period
                </th>
              </tr>
            </thead>
            <tbody>
              { data.people.map( ( person ) => (
                <tr key={ person.user_id }>
                  <th scope="row" className="bwx-capacity-person">
                    { person.display_name }
                  </th>

                  { person.periods.map( ( cell ) => (
                    <td key={ cell.from } data-band={ cell.band }>
                      <button
                        type="button"
                        className="bwx-capacity-cell"
                        data-testid={ `bwx-capacity-cell-${ person.user_id }-${ cell.from }` }
                        aria-label={ `${ person.display_name }, ${ 'days' === data.by ? '' : 'week of ' }${ cell.from }: ${ BAND_WORD[ cell.band ] }` }
                        onClick={ () => void openCell( person.user_id, cell.from, cell.to ) }
                      >
                        <Cell cell={ cell } />
                      </button>
                    </td>
                  ) ) }

                  <td data-band={ person.total.band } className="bwx-capacity-total">
                    <Figures position={ person.total } />
                  </td>
                </tr>
              ) ) }
            </tbody>
          </table>
        </div>
      ) }

      { undefined !== open && <Drilldown drilldown={ open } onClose={ () => setOpen( undefined ) } onOpenItem={ ( id ) => void openItem( id ) } /> }

      { '' !== opened && undefined !== stages && (
        <ItemPanel itemId={ opened } stages={ stages } onClose={ () => setOpened( '' ) } onChanged={ () => void load() } />
      ) }
    </>
  );
}

/** One column for one person: the bar, then the figures. */
function Cell( { cell }: { cell: CapacityCell } ) {
  if ( 'unrecorded' === cell.band ) {
    return <span className="bwx-capacity-unset">{ BAND_WORD.unrecorded }</span>;
  }

  const share = fill( cell );

  // Done first, then still to do, so the bar reads left to right the way the
  // day went. Each segment is drawn only when there is something in it.
  return (
    <>
      <span className="bwx-capacity-bar" aria-hidden="true">
        { 0 < share.done && (
          <span className="bwx-capacity-bar-done" data-testid="bwx-capacity-bar-done" style={ { inlineSize: `${ share.done * 100 }%` } } />
        ) }
        { 0 < share.todo && (
          <span className="bwx-capacity-bar-fill" data-testid="bwx-capacity-bar-todo" style={ { inlineSize: `${ share.todo * 100 }%` } } />
        ) }
      </span>
      <Figures position={ cell } />
    </>
  );
}

/** Time used of time available — still to do and done together (#384). */
function Figures( { position }: { position: CapacityPosition } ) {
  if ( 'unrecorded' === position.band ) {
    return <span className="bwx-capacity-unset">{ BAND_WORD.unrecorded }</span>;
  }

  return (
    <span
      className="bwx-capacity-figures"
      title={ `${ durationLabel( position.committed ) } still to do, ${ durationLabel( position.completed ) } done, of ${ durationLabel( position.available ) }` }
    >
      <span className="bwx-capacity-committed">{ durationLabel( position.committed + position.completed ) }</span>
      <span className="bwx-capacity-of">/ { durationLabel( position.available ) }</span>
    </span>
  );
}

/** What is behind one person's week. */
function Drilldown( {
  drilldown,
  onClose,
  onOpenItem,
}: {
  drilldown: CapacityDrilldown;
  onClose: () => void;
  onOpenItem: ( itemId: string ) => void;
} ) {
  const todo = drilldown.allocations.filter( ( allocation ) => 'completed' !== allocation.status );
  const done = drilldown.allocations.filter( ( allocation ) => 'completed' === allocation.status );

  /*
   * Only real absences. A day off in somebody's normal week is not time off,
   * and a day nobody has set hours for is not time off either — listing those
   * under "Time off" tells whoever reads it the wrong thing about why the
   * number is what it is. The summary above already says when hours are unset.
   */
  const away = drilldown.days.filter(
    ( day ) => '' !== day.reason && 'non-working-day' !== day.reason && 'no-pattern' !== day.reason
  );

  return (
    <Aside
      label={
        <>
          <span className="bwx-eyebrow">
            { drilldown.from } to { drilldown.to }
          </span>
          <span className="bwx-panel-title-text">{ drilldown.display_name }</span>
        </>
      }
      testId="bwx-capacity-drilldown"
      width={ 520 }
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
            Close
          </button>
        </div>
      }
    >

        <p className="bwx-capacity-summary" data-band={ drilldown.position.band }>
          { 'unrecorded' === drilldown.position.band
            ? 'Nobody has set this person’s working hours, so there is no capacity to report.'
            : `${ durationLabel( drilldown.position.committed ) } still to do and ${ durationLabel( drilldown.position.completed ) } done, of ${ durationLabel( drilldown.position.available ) }. ${
                drilldown.position.remaining < 0
                  ? `${ durationLabel( -drilldown.position.remaining ) } over.`
                  : `${ durationLabel( drilldown.position.remaining ) } left.`
              }` }
        </p>

        <WorkList title="Still to do" testId="bwx-capacity-todo" allocations={ todo } onOpenItem={ onOpenItem } />
        <WorkList title="Done" testId="bwx-capacity-done" allocations={ done } onOpenItem={ onOpenItem } />

        { 0 < away.length && (
          <div className="bwx-field">
            <label>Time off in this period</label>
            <ul className="bwx-history" data-testid="bwx-capacity-away">
              { away.map( ( day ) => (
                <li key={ day.date }>
                  { day.date } — { reasonWord( day.reason ) }
                </li>
              ) ) }
            </ul>
          </div>
        ) }
    </Aside>
  );
}

/**
 * One half of the day panel (#385): still to do, or done. Each with its total,
 * and each task a button that opens it — a list of work nobody can open is a
 * list people copy titles out of to go and search for.
 */
function WorkList( {
  title,
  testId,
  allocations,
  onOpenItem,
}: {
  title: string;
  testId: string;
  allocations: CapacityAllocation[];
  onOpenItem: ( itemId: string ) => void;
} ) {
  const total = allocations.reduce( ( sum, allocation ) => sum + hoursIn( allocation ), 0 );

  return (
    <section className="bwx-field" data-testid={ testId }>
      <h3 className="bwx-capacity-section">
        { title } <span className="bwx-capacity-of">{ durationLabel( total ) }</span>
      </h3>
      { 0 === allocations.length ? (
        <p className="bwx-list-empty">Nothing in this period.</p>
      ) : (
        <ul className="bwx-history">
          { allocations.map( ( allocation, index ) => {
            const detail = `${ ROLE_WORD[ allocation.role ] ?? allocation.role }, ${ durationLabel( hoursIn( allocation ) ) }${ '' !== allocation.covering ? ', covering for somebody' : '' }`;

            return (
              <li key={ `${ allocation.item_id }-${ allocation.role }-${ index }` }>
                { opens( allocation ) ? (
                  <button type="button" className="bwx-capacity-task" onClick={ () => onOpenItem( allocation.item_id ) }>
                    <strong>{ allocation.title }</strong>
                    <span>{ detail }</span>
                  </button>
                ) : (
                  <>
                    <strong>{ allocation.title }</strong>
                    <br />
                    { detail }
                  </>
                ) }
              </li>
            );
          } ) }
        </ul>
      ) }
    </section>
  );
}
