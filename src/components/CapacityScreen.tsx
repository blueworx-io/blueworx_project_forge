import { useCallback, useEffect, useState } from 'react';
import type {
  CapacityBand,
  CapacityCell,
  CapacityDrilldown,
  CapacityPosition,
  CapacityResponse,
} from '../types';
import { api, isDenied, messageFor } from '../api';
import { Screen } from './States';

/**
 * The studio's picture of who has room (#139).
 *
 * People down the side, weeks across the top. It draws what the server says and
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

/** Eight weeks from the Monday of this week: the question people actually ask. */
function defaultRange(): { from: string; to: string } {
  const today = new Date();
  const monday = new Date( today );

  monday.setDate( today.getDate() - ( ( today.getDay() + 6 ) % 7 ) );

  const end = new Date( monday );

  end.setDate( monday.getDate() + 55 );

  return { from: iso( monday ), to: iso( end ) };
}

function iso( date: Date ): string {
  return date.toISOString().slice( 0, 10 );
}

/** A week header: the date it starts, with no year to repeat eight times. */
function weekLabel( from: string ): string {
  const [ , month, day ] = from.split( '-' );

  return `${ day } ${ MONTHS[ Number( month ) - 1 ] ?? '' }`.trim();
}

const MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];

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

/** How full a cell is, as a share, for the bar. Over runs full and no further. */
function fill( position: CapacityPosition ): number {
  if ( position.available <= 0 ) {
    return position.committed > 0 ? 1 : 0;
  }

  return Math.min( 1, position.committed / position.available );
}

export function CapacityScreen() {
  const [ range, setRange ] = useState( defaultRange );
  const [ data, setData ] = useState< CapacityResponse | undefined >();
  const [ open, setOpen ] = useState< CapacityDrilldown | undefined >();
  const [ notice, setNotice ] = useState( '' );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );

  const load = useCallback( async () => {
    try {
      setData( await api< CapacityResponse >( `/capacity?from=${ range.from }&to=${ range.to }` ) );
      setState( 'ready' );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'The capacity picture could not be read.' ) );
      setState( isDenied( failure ) ? 'denied' : 'error' );
    }
  }, [ range.from, range.to ] );

  // Reading on mount, the same way the other screens do — and again when the
  // dates change, which is the one difference: the range is a control here, so
  // load() is a dependency rather than an empty list.
  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [ load ] );

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

  async function openCell( userId: string, from: string, to: string ) {
    try {
      setOpen( await api< CapacityDrilldown >( `/capacity/person/${ userId }?from=${ from }&to=${ to }` ) );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'That could not be opened.' ) );
    }
  }

  return (
    <>
      <header className="bwx-header" data-testid="bwx-capacity-header">
        <span className="bwx-eyebrow">Capacity</span>

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
          <table className="bwx-capacity-grid" data-testid="bwx-capacity-grid">
            <caption className="bwx-visually-hidden">
              Available and committed hours per person, week by week
            </caption>
            <thead>
              <tr>
                <th scope="col" className="bwx-capacity-person">
                  Person
                </th>
                { data.weeks.map( ( week ) => (
                  <th key={ week.from } scope="col">
                    { weekLabel( week.from ) }
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

                  { person.weeks.map( ( cell ) => (
                    <td key={ cell.from } data-band={ cell.band }>
                      <button
                        type="button"
                        className="bwx-capacity-cell"
                        data-testid={ `bwx-capacity-cell-${ person.user_id }-${ cell.from }` }
                        aria-label={ `${ person.display_name }, week of ${ cell.from }: ${ BAND_WORD[ cell.band ] }` }
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

      { undefined !== open && <Drilldown drilldown={ open } onClose={ () => setOpen( undefined ) } /> }
    </>
  );
}

/** One week for one person: the bar, then the figures. */
function Cell( { cell }: { cell: CapacityCell } ) {
  if ( 'unrecorded' === cell.band ) {
    return <span className="bwx-capacity-unset">{ BAND_WORD.unrecorded }</span>;
  }

  return (
    <>
      <span className="bwx-capacity-bar" aria-hidden="true">
        <span className="bwx-capacity-bar-fill" style={ { inlineSize: `${ fill( cell ) * 100 }%` } } />
      </span>
      <Figures position={ cell } />
    </>
  );
}

/** Committed of available, and what that leaves. */
function Figures( { position }: { position: CapacityPosition } ) {
  if ( 'unrecorded' === position.band ) {
    return <span className="bwx-capacity-unset">{ BAND_WORD.unrecorded }</span>;
  }

  return (
    <span className="bwx-capacity-figures">
      <span className="bwx-capacity-committed">{ position.committed }</span>
      <span className="bwx-capacity-of">/ { position.available }h</span>
    </span>
  );
}

/** What is behind one person's week. */
function Drilldown( { drilldown, onClose }: { drilldown: CapacityDrilldown; onClose: () => void } ) {
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
    <div
      className="bwx-panel-scrim"
      onClick={ ( event ) => {
        if ( event.target === event.currentTarget ) {
          onClose();
        }
      } }
    >
      <aside
        className="bwx-panel"
        role="dialog"
        aria-modal="true"
        aria-label="Capacity"
        data-testid="bwx-capacity-drilldown"
        onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
      >
        <div className="bwx-panel-head">
          <div>
            <span className="bwx-eyebrow">
              { drilldown.from } to { drilldown.to }
            </span>
            <h2 className="bwx-wordmark">{ drilldown.display_name }</h2>
          </div>

          <span className="bwx-header-spacer" />

          <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
            Close
          </button>
        </div>

        <p className="bwx-capacity-summary" data-band={ drilldown.position.band }>
          { 'unrecorded' === drilldown.position.band
            ? 'Nobody has set this person’s working hours, so there is no capacity to report.'
            : `${ drilldown.position.committed } of ${ drilldown.position.available } hours committed, ${ drilldown.position.remaining } left.` }
        </p>

        <div className="bwx-field">
          <label>What is committed</label>
          { 0 === drilldown.allocations.length ? (
            <p className="bwx-list-empty">Nothing in this period.</p>
          ) : (
            <ul className="bwx-history" data-testid="bwx-capacity-work">
              { drilldown.allocations.map( ( allocation ) => (
                <li key={ `${ allocation.item_id }-${ allocation.role }` }>
                  <strong>{ allocation.title }</strong>
                  <br />
                  { ROLE_WORD[ allocation.role ] ?? allocation.role }, { allocation.hours }h
                  { '' !== allocation.covering ? ', covering for somebody' : '' }
                </li>
              ) ) }
            </ul>
          ) }
        </div>

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
      </aside>
    </div>
  );
}
