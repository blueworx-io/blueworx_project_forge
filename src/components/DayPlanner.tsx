import { useEffect, useMemo, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { ArrowDown, ArrowUp, ArrowUpToLine, ArrowDownToLine, CalendarClock, Handshake, TriangleAlert } from 'lucide-react';
import type { DiaryEntry } from '../types';
import { api } from '../api';
import { Button, Card, StageChip, Tag } from '../kit';
import { DiaryLine, daysOf, useDiary } from './Diary';
import { todayIso } from '../calendar';
import { PRIORITY_TONE } from '../priority';
import { clockText, DAY_END, DAY_START, drawDay, dropSlot, hoursText, layOut, minuteOfDay, placeSlot, planDay, slotOrder, slotsOf, swapSlots } from '../dayplan.mjs';
import type { Mine, Role } from '../mytasks';

/*
 * The day planner (#620): the Today tab of My tasks as a day, 7am to 7pm.
 * Meetings sit at their times (in the viewer's own zone), tasks fill the open
 * time by priority, and what does not fit, or has no hours, sits beneath.
 * It is a view: it never changes a task. The placing rules live in
 * src/dayplan.mjs. #649: the times each task was placed at are kept, and the
 * day is drawn from them, so nothing moves until it is edited or scheduled.
 */

const ROLE_LABEL: Record< Role, string > = { primary: 'Owner', designer: 'Designer', reviewer: 'Checker', deliverer: 'Builder', assignee: 'Yours to tick' };
const ROLE_TONE: Record< Role, 'brand' | 'info' | 'neutral' | 'ok' > = { primary: 'brand', designer: 'info', reviewer: 'info', deliverer: 'neutral', assignee: 'ok' };

/** Pixels an hour of the day is drawn at. */
const HOUR_PX = 80;
/** The least a piece of the day is drawn at: a few minutes is a slim line (#656). */
const MIN_PX = 12;
/** Below this, a task shows on one line (#639). */
const SHORT_PX = 56;

interface Seg {
  type: string;
  id: string;
  start: number;
  end: number;
  part: number;
  parts: number;
}

type Box = Seg & { top: number; height: number; lane: number; lanes: number };

/** Where a piece is drawn: at its own time, and meetings that overlap side by side (#654). */
function placeOf( seg: Box ): CSSProperties {
  const at = { top: seg.top, height: seg.height };

  return 1 < seg.lanes ? { ...at, left: `${ ( seg.lane / seg.lanes ) * 100 }%`, right: 'auto', width: `${ 100 / seg.lanes }%` } : at;
}

interface Saved {
  order: string[];
  down: string[];
  /** When the day was planned, in minutes from midnight; null until it is (#635). */
  start: number | null;
  /** Where each task was placed (#649); null for a plan kept before then. */
  slots: Slot[] | null;
}

interface Slot {
  id: string;
  start: number;
  end: number;
}

interface Meeting {
  id: string;
  start: number;
  end: number;
  entry: DiaryEntry;
}

function dueText( one: Mine ): string {
  if ( null === one.due ) return '—';
  if ( one.due < 0 ) return `${ one.due }d`;
  if ( 0 === one.due ) return 'today';
  return new Date( `${ one.item.planned_due || one.item.derived_due }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', timeZone: 'UTC' } );
}

function shiftDay( day: string, by: number ): string {
  return new Date( Date.parse( `${ day }T00:00:00Z` ) + by * 86400000 ).toISOString().slice( 0, 10 );
}

function hourLabel( hour: number ): string {
  return `${ hour % 12 || 12 }${ hour < 12 ? 'am' : 'pm' }`;
}

/** The same details as the table row has, one task. */
function TaskBlock( {
  one,
  part,
  parts,
  clash,
  editing,
  actions,
  onOpen,
  where,
}: {
  one: Mine;
  part?: number;
  parts?: number;
  /** What the clash is, if the block has one. */
  clash?: string;
  editing?: boolean;
  actions?: ReactNode;
  onOpen: ( itemId: string ) => void;
  where: 'day' | 'attention' | 'overflow' | 'unset';
} ) {
  return (
    <div
      className="bwx-day-block"
      data-testid="bwx-day-block"
      data-task={ one.id }
      data-where={ where }
      data-part={ part && parts && 1 < parts ? part : undefined }
      data-priority={ one.item.priority }
      data-clash={ clash ? 'true' : undefined }
      data-editing={ editing ? 'true' : undefined }
    >
      <span className="bwx-day-block-main">
        <span className="bwx-mytasks-title-row">
          <button type="button" className="bwx-row-open" data-testid="bwx-mytasks-open" onClick={ () => onOpen( one.item.id ) }>
            { one.item.title }
          </button>
          { 'blocked' === one.item.stage && <Tag tone="danger">Blocked</Tag> }
          { 'assignee' === one.role && (
            <span className="bwx-mytasks-done" data-testid="bwx-mytasks-done">
              Done <span className="bwx-mono">{ `${ Object.keys( one.item.ticks ?? {} ).length } of ${ one.item.assignees.length }` }</span>
            </span>
          ) }
          { parts && 1 < parts && <span className="bwx-day-part">{ `Part ${ part } of ${ parts }` }</span> }
          { clash && (
            <span className="bwx-day-clash" data-testid="bwx-day-clash">
              <TriangleAlert size={ 14 } strokeWidth={ 1.75 } aria-hidden="true" /> { clash }
            </span>
          ) }
        </span>
      </span>
      <span className="bwx-day-block-facts">
        <span className="bwx-day-fact">{ one.site.client_name || one.site.name }</span>
        <StageChip stage={ one.item.stage } dense />
        <Tag tone={ ROLE_TONE[ one.role ] }>{ ROLE_LABEL[ one.role ] }</Tag>
        <span data-testid="bwx-mytasks-priority" data-priority={ one.item.priority }>
          { one.item.priority ? <Tag tone={ PRIORITY_TONE[ one.item.priority ] ?? 'neutral' }>{ one.item.priority }</Tag> : '—' }
        </span>
        <span className="bwx-mono bwx-day-hours">{ one.hours ? `${ one.hours.toFixed( 1 ) }h` : '—' }</span>
        <span className="bwx-mytasks-due bwx-mono" data-due={ null === one.due ? 'none' : one.due < -1 ? 'late' : one.due < 0 ? 'yesterday' : 0 === one.due ? 'today' : 'ahead' }>
          { `Due ${ dueText( one ) }` }
        </span>
      </span>
      { actions && <span className="bwx-day-actions">{ actions }</span> }
    </div>
  );
}

export function DayPlanner( {
  rows,
  onToday,
  me,
  onOpen,
  filtered,
  below,
}: {
  /** The Today tab's rows, already narrowed by the search, role and client. */
  rows: Mine[];
  /** Every row on Today, filters aside (#649): a kept slot whose task is not here has finished. */
  onToday: Set< string >;
  me: { id: string } | null;
  onOpen: ( itemId: string ) => void;
  /** Whether a filter is narrowing the day, so an empty day can say so. */
  filtered: boolean;
  /** Where the lists beneath the day go: their own box, not the day's (#635). */
  below: HTMLElement | null;
} ) {
  const day = todayIso();
  const [ now, setNow ] = useState( () => new Date() );
  const [ saved, setSaved ] = useState< Saved >( { order: [], down: [], start: null, slots: null } );
  const [ editing, setEditing ] = useState( false );
  const [ said, setSaid ] = useState( '' );
  // Whether the saved order has been read. The day draws at once in the
  // default order and settles into the person's own when it arrives.
  const [ loaded, setLoaded ] = useState( false );

  useEffect( () => {
    const tick = window.setInterval( () => setNow( new Date() ), 60000 );
    return () => window.clearInterval( tick );
  }, [] );

  // The order this person set for today, kept on their account.
  useEffect( () => {
    let live = true;
    api< Saved >( `/day-plan?day=${ day }`, { cache: 'fresh' } )
      .then( ( answer ) => {
        if ( live ) setSaved( { order: answer.order ?? [], down: answer.down ?? [], start: answer.start ?? null, slots: answer.slots ?? null } );
      } )
      .catch( () => undefined )
      .finally( () => {
        if ( live ) setLoaded( true );
      } );
    return () => {
      live = false;
    };
  }, [ day ] );

  // A three-day window, so a meeting is found by when it happens in this
  // viewer's own day whichever date it was set on.
  const diary = useDiary( shiftDay( day, -1 ), shiftDay( day, 1 ) );

  const [ y, m, d ] = day.split( '-' ).map( Number );
  const dayStartMs = new Date( y, m - 1, d ).getTime();
  const nowMins = Math.floor( ( now.getTime() - dayStartMs ) / 60000 );

  const { meetings, strip, away } = useMemo( () => {
    const meetings: Meeting[] = [];
    const strip: DiaryEntry[] = [];
    let away = false;
    const seen = new Set< string >();

    for ( const entry of diary.entries ) {
      if ( seen.has( entry.id ) ) continue;
      seen.add( entry.id );
      const mineAlone = 'all' === entry.people || ( null !== me && entry.people.includes( me.id ) );

      if ( 'meeting' === entry.kind ) {
        // A meeting that has been held leaves the day, as it leaves Today's diary (#601).
        if ( entry.done ) continue;
        const going = 'google' === entry.source ? ( entry.attendee_ids ?? [] ).includes( me?.id ?? '' ) : mineAlone || ( entry.attendee_ids ?? [] ).includes( me?.id ?? '' );
        if ( ! going ) continue;

        if ( entry.starts_at && entry.ends_at && ! entry.all_day ) {
          const start = minuteOfDay( entry.starts_at, dayStartMs );
          const end = minuteOfDay( entry.ends_at, dayStartMs );
          if ( end > 0 && start < 1440 ) meetings.push( { id: entry.id, start, end, entry } );
        } else if ( daysOf( entry ).includes( day ) ) {
          strip.push( entry );
        }
        continue;
      }

      // Chores and reminders are drawn once, as their task; a finished one is gone.
      if ( 'recurring' === entry.kind || 'reminder' === entry.kind ) continue;
      if ( ! mineAlone || ! daysOf( entry ).includes( day ) ) continue;

      // A whole day away: leave in Forge is always whole days. Dates, leave
      // and renewals are listed in Today's diary above, not again here (#635).
      if ( 'leave' === entry.kind && null !== me && 'all' !== entry.people && entry.people.includes( me.id ) ) away = true;
    }

    return { meetings, strip, away };
  }, [ diary.entries, me, day, dayStartMs ] );

  const byId = useMemo( () => new Map( rows.map( ( one ) => [ one.id, one ] ) ), [ rows ] );
  const tasks = useMemo(
    () => rows.map( ( one, index ) => ( { id: one.id, hours: one.hours, priority: one.item.priority, due: one.due, index } ) ),
    [ rows ]
  );

  // The day is planned once (#635), and #649 keeps the time each task was
  // placed at: the day is drawn from those times, so a new meeting or task, a
  // finished one or changed hours moves nothing. An edit changes only what it
  // touches, Schedule plans the day again from now, and the next day starts afresh.
  const planStart = saved.start ?? nowMins;
  const times = useMemo( () => meetings.map( ( one ) => ( { id: one.id, start: one.start, end: one.end } ) ), [ meetings ] );
  const fresh = ( from: number, order: string[] = [], down: string[] = [] ) => planDay( { now: from, meetings: times, tasks, order, down, away } );
  const plan = useMemo(
    () => ( null !== saved.slots ? drawDay( { now: planStart, meetings: times, tasks, slots: saved.slots, away } ) : fresh( planStart, saved.order, saved.down ) ),
    [ times, tasks, saved, planStart, away ] // eslint-disable-line react-hooks/exhaustive-deps
  );
  // What an edit works from: a finished task's time is open again, while a
  // task a filter is hiding keeps its own.
  const slots: Slot[] = ( saved.slots ?? slotsOf( plan ) ).filter( ( one ) => onToday.has( one.id ) || byId.has( one.id ) );

  async function keep( next: Slot[], down: string[], note = '', start = planStart ) {
    const order = slotOrder( next );
    setSaved( { order, down, start, slots: next } );
    setSaid( note );
    try {
      await api( '/day-plan', { method: 'PUT', body: { day, order, down, start, slots: next } } );
    } catch {
      setSaid( 'Your change could not be saved.' );
    }
  }

  // The first look at a day plans it, and that plan is kept. A plan kept
  // before #649 has its order but no times: it is laid out once more from
  // when it was planned, and kept from then on.
  useEffect( () => {
    if ( ! loaded || ! diary.ready || null !== saved.slots ) return;
    const from = saved.start ?? nowMins;
    void keep( slotsOf( fresh( from, saved.order, saved.down ) ), saved.down, '', from );
  }, [ loaded, diary.ready, saved.slots ] ); // eslint-disable-line react-hooks/exhaustive-deps

  // #649. Plans the day again from now, by priority, and replaces every edit.
  function schedule() {
    void keep( slotsOf( fresh( nowMins ) ), [], 'Your day has been planned again from now.', nowMins );
  }

  // Into the first open time from now; nothing else moves (#649).
  function bring( id: string, hasHours: boolean ) {
    if ( ! hasHours ) {
      onOpen( byId.get( id )?.item.id ?? '' );
      return;
    }
    const next = placeSlot( { slots, meetings: times, now: nowMins }, id, byId.get( id )?.hours ?? 0 );
    if ( null === next ) setSaid( 'That one is longer than the open time left today.' );
    else void keep( next, saved.down.filter( ( one ) => one !== id ) );
  }

  // Swaps a task with the one before or after it in the day (#649).
  function move( id: string, delta: number ) {
    const at = plan.placedOrder.indexOf( id );
    const other = plan.placedOrder[ at + delta ];
    if ( at < 0 || undefined === other ) return;
    const [ first, second ] = delta < 0 ? [ other, id ] : [ id, other ];
    void keep( swapSlots( { slots, meetings: times, now: nowMins }, first, second ), saved.down );
  }

  const meetingById = new Map( meetings.map( ( one ) => [ one.id, one ] ) );
  const clashing = new Set( [ ...plan.clashes.meetings, ...plan.clashes.tasks ] );
  // #649. A meeting that arrived on top of a kept task: both stay put, flagged.
  const onTasks = new Set< string >( plan.clashes.overlaps.meetings );
  const onMeetings = new Set< string >( plan.clashes.overlaps.tasks );

  // The whole day, always (#637).
  const hours: number[] = [];
  for ( let hour = DAY_START / 60; hour < DAY_END / 60; hour += 1 ) hours.push( hour );

  // Urgent or overdue work with no room needs attention first, and so is not
  // listed again under Doesn't fit today or No hours set (#635).
  const attention: string[] = plan.attention;
  const needed = new Set( attention );
  const overflow = plan.overflow.filter( ( id: string ) => ! needed.has( id ) );
  const unset = plan.unset.filter( ( id: string ) => ! needed.has( id ) );
  const hasHours = ( id: string ) => 0 < ( byId.get( id )?.hours ?? 0 );

  const bringButton = ( id: string, title: string ) =>
    hasHours( id ) ? (
      <Button variant="ghost" size="sm" data-testid="bwx-day-bringup" aria-label={ `Bring ${ title } up into the day` } onClick={ () => bring( id, true ) }>
        <ArrowUpToLine size={ 14 } aria-hidden="true" /> Bring up
      </Button>
    ) : (
      <Button variant="ghost" size="sm" data-testid="bwx-day-bringup" aria-label={ `Set hours for ${ title } to bring it up` } onClick={ () => bring( id, false ) }>
        <ArrowUpToLine size={ 14 } aria-hidden="true" /> Set hours
      </Button>
    );

  // Beneath the day, each in a box of its own and in this order (#637):
  // what needs attention, what doesn't fit, and what has no hours.
  const box = ( kind: 'attention' | 'overflow' | 'unset', heading: ReactNode, note: string, ids: string[] ) =>
    0 < ids.length && (
      <Card className={ `bwx-day-box bwx-day-box-${ kind }` } pad={ 16 } testId={ `bwx-day-${ kind }` }>
        <section className="bwx-day-list" aria-labelledby={ `bwx-day-${ kind }-h` }>
          <h3 id={ `bwx-day-${ kind }-h` }>{ heading }</h3>
          { '' !== note && <p className="bwx-muted">{ note }</p> }
          { ids.map( ( id: string ) => {
            const one = byId.get( id );
            if ( ! one ) return null;
            return <TaskBlock key={ id } one={ one } where={ kind } clash={ 'overflow' === kind && plan.clashes.tasks.includes( id ) ? 'Due, and no room today' : undefined } editing={ editing } onOpen={ onOpen } actions={ editing ? bringButton( id, one.item.title ) : undefined } />;
          } ) }
        </section>
      </Card>
    );
  const lists = (
    <>
      { box(
        'attention',
        <>
          <TriangleAlert size={ 14 } strokeWidth={ 1.75 } aria-hidden="true" />
          { ` Needs attention (${ attention.length })` }
        </>,
        'Urgent or overdue, and not in the day: no hours set, or too long for the time left.',
        attention
      ) }
      { box( 'overflow', `Doesn't fit today (${ overflow.length })`, '', overflow ) }
      { box( 'unset', `No hours set (${ unset.length })`, 'These are not placed in the day until they have hours.', unset ) }
    </>
  );

  // Every hour the same height, and each piece of the day across the hours
  // it takes (#639).
  const boxes: Box[] = layOut( [ ...plan.segments ].sort( ( x: Seg, y: Seg ) => x.start - y.start ), { hourPx: HOUR_PX, minPx: MIN_PX } );
  const dayHeight = Math.max( hours.length * HOUR_PX, ...boxes.map( ( one ) => one.top + one.height ) );

  /** One piece of the day: open time, a meeting, or a task. */
  const piece = ( seg: Box ): ReactNode => {
    if ( 'gap' === seg.type ) {
      return (
        <div className="bwx-day-gap" data-testid="bwx-day-gap">
          { `Open · ${ hoursText( seg.end - seg.start ) }` }
        </div>
      );
    }

    if ( 'meeting' === seg.type ) {
      const one = meetingById.get( seg.id );
      if ( ! one ) return null;
      const rest = one.entry.detail.replace( /^\d{1,2}:\d{2}\s*·?\s*/, '' );
      return (
        <div className="bwx-day-meeting" data-testid="bwx-day-meeting" data-meeting={ one.entry.id } data-clash={ clashing.has( seg.id ) || onTasks.has( seg.id ) ? 'true' : undefined }>
          <Handshake size={ 16 } strokeWidth={ 1.75 } aria-hidden="true" />
          <span className="bwx-day-meeting-body">
            <span className="bwx-day-meeting-title">{ one.entry.title }</span>
            <span className="bwx-day-meeting-when bwx-mono">{ `${ clockText( one.start ) }–${ clockText( one.end ) }` }</span>
            { '' !== rest && <span className="bwx-muted">{ rest }</span> }
          </span>
          { ( clashing.has( seg.id ) || onTasks.has( seg.id ) ) && (
            <span className="bwx-day-clash" data-testid="bwx-day-clash">
              <TriangleAlert size={ 14 } strokeWidth={ 1.75 } aria-hidden="true" /> { clashing.has( seg.id ) ? 'Overlaps another' : 'Overlaps a task' }
            </span>
          ) }
        </div>
      );
    }

    const one = byId.get( seg.id );
    if ( ! one ) return null;
    const at = plan.placedOrder.indexOf( seg.id );
    const label = one.item.title;
    return (
      <TaskBlock
        one={ one }
        part={ seg.part }
        parts={ seg.parts }
        clash={ onMeetings.has( seg.id ) ? 'Overlaps a meeting' : undefined }
        editing={ editing }
        where="day"
        onOpen={ onOpen }
        actions={
          editing && 1 === seg.part ? (
            <>
              <Button variant="ghost" size="sm" data-testid="bwx-day-up" aria-label={ `Move ${ label } up` } disabled={ 0 === at } onClick={ () => move( seg.id, -1 ) }>
                <ArrowUp size={ 14 } aria-hidden="true" /> Up
              </Button>
              <Button variant="ghost" size="sm" data-testid="bwx-day-down" aria-label={ `Move ${ label } down` } disabled={ at === plan.placedOrder.length - 1 } onClick={ () => move( seg.id, 1 ) }>
                <ArrowDown size={ 14 } aria-hidden="true" /> Down
              </Button>
              <Button variant="ghost" size="sm" data-testid="bwx-day-senddown" aria-label={ `Send ${ label } down to Doesn't fit today` } onClick={ () => void keep( dropSlot( slots, seg.id ), [ ...saved.down.filter( ( one ) => one !== seg.id ), seg.id ] ) }>
                <ArrowDownToLine size={ 14 } aria-hidden="true" /> Send down
              </Button>
            </>
          ) : undefined
        }
      />
    );
  };

  const empty = 0 === rows.length && 0 === meetings.length;
  const taskCount = plan.placedOrder.length;
  const clashes = plan.clashes.count;

  return (
    <div className="bwx-day" data-testid="bwx-day" data-ready={ loaded && diary.ready ? 'true' : 'false' } data-editing={ editing ? 'true' : undefined }>
      <div className="bwx-day-bar">
        <p className="bwx-day-summary" data-testid="bwx-day-summary">
          <span>{ `${ taskCount } ${ 1 === taskCount ? 'task' : 'tasks' } planned` }</span>
          <span>{ `${ meetings.length } ${ 1 === meetings.length ? 'meeting' : 'meetings' }` }</span>
          <span data-testid="bwx-day-planned">
            <strong>{ hoursText( plan.plannedMins ) }</strong> planned
          </span>
          <span data-testid="bwx-day-open">
            <strong>{ hoursText( plan.openMins ) }</strong> open
          </span>
          { 0 < clashes && (
            <span className="bwx-day-clash" data-testid="bwx-day-clashes" role="status">
              <TriangleAlert size={ 14 } strokeWidth={ 1.75 } aria-hidden="true" />
              { ` ${ clashes } ${ 1 === clashes ? 'clash' : 'clashes' }` }
            </span>
          ) }
        </p>
        <span className="bwx-day-tools">
          <Button variant="secondary" size="sm" data-testid="bwx-day-schedule" title="Plan the day again from now. This replaces your changes." disabled={ ! loaded || ! diary.ready } onClick={ schedule }>
            <CalendarClock size={ 14 } aria-hidden="true" /> Schedule
          </Button>
          <Button variant="secondary" size="sm" data-testid="bwx-day-edit" aria-pressed={ editing } onClick={ () => setEditing( ! editing ) }>
            { editing ? 'Done editing' : 'Edit plan' }
          </Button>
        </span>
      </div>

      { '' !== said && (
        <p className="bwx-muted" role="status" data-testid="bwx-day-said">
          { said }
        </p>
      ) }

      { away && (
        <p className="bwx-day-away" data-testid="bwx-day-away" role="status">
          You are away today, so there is no open time. Everything with hours is under Doesn&apos;t fit today.
        </p>
      ) }

      { 0 < strip.length && (
        <ul className="bwx-diary-lines bwx-day-strip" data-testid="bwx-day-strip" aria-label="All day">
          { strip.map( ( entry ) => (
            <DiaryLine key={ entry.id } entry={ entry } onOpen={ onOpen } />
          ) ) }
        </ul>
      ) }

      { empty && ! away && (
        <p className="bwx-muted" data-testid="bwx-day-empty">
          { filtered ? 'Nothing matches the filters for today.' : 'Nothing needs you today.' }
        </p>
      ) }

      <div className="bwx-day-grid" data-testid="bwx-day-grid" style={ { minHeight: dayHeight } }>
        { hours.map( ( hour ) => (
          <div key={ hour } className="bwx-day-hour" data-hour={ hour } data-past={ hour * 60 + 60 <= nowMins ? 'true' : undefined } style={ { height: HOUR_PX } }>
            <span className="bwx-day-label bwx-mono">{ hourLabel( hour ) }</span>
          </div>
        ) ) }
        <div className="bwx-day-layer">
          { boxes.map( ( seg: Box ) => {
            const inner = piece( seg );
            return (
              inner && (
                <div key={ `${ seg.type }${ seg.id ?? '' }:${ seg.start }` } className="bwx-day-item" data-hour={ Math.floor( seg.start / 60 ) } data-short={ seg.height < SHORT_PX ? 'true' : undefined } style={ placeOf( seg ) }>
                  { inner }
                </div>
              )
            );
          } ) }
        </div>
      </div>

      { below && 0 < attention.length + overflow.length + unset.length && createPortal( lists, below ) }
    </div>
  );
}
