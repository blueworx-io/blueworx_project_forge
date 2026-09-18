import { useEffect, useState } from 'react';
import { CalendarClock, CalendarX2 } from 'lucide-react';
import type { Meeting, MeetingFrequency, MeetingLedgerState, MeetingSeries, MeetingStatus, MeetingsAnswer } from '../types';
import { ApiError, api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, Card, DataView, EmptyState, Field, Modal, Panel, Select, Tag, TextInput } from '../kit';
import type { Column } from '../kit';
import { hoursLabel } from './PackagesScreen';
import { SitePicker } from './SitePicker';
import { Screen } from './States';

/**
 * A site's meetings (PR 6 of spec 2026-09-17), in the app: the standing
 * arrangements it has, the next twelve weeks they imply, and the four things
 * that can be done to them — start a series, end one, move one meeting, say
 * what became of one. Reads and writes `/client-sites/<id>/meetings` and
 * nothing else; the admin page stays beside it until every screen has moved.
 *
 * The server settles the hours before every answer (MEET-4), so what the list
 * shows as set aside is what the site's balance has committed. Every write
 * answers the whole picture, and the picture replaces what is on screen.
 */

/**
 * The four patterns and how they read, as `Meetings\Recurrence::FREQUENCIES`
 * and `Recurrence::label()` have them. Hardcoded here because the form has to
 * offer them before any series exists to carry a label; the server is the
 * authority, and `frequency_label` on each series is what the cards show.
 */
const FREQUENCIES: Array< { value: MeetingFrequency; label: string } > = [
  { value: 'weekly', label: 'Every week' },
  { value: 'fortnightly', label: 'Every fortnight' },
  { value: 'four-weekly', label: 'Every four weeks' },
  { value: 'monthly', label: 'Every month, on the same date' },
];

/** What can be said of a meeting, as the settle form offers it. */
const OUTCOMES: Array< { value: MeetingStatus; label: string } > = [
  { value: 'held', label: 'Held' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'no-show', label: 'Nobody came' },
  { value: 'scheduled', label: 'Scheduled again' },
];

/** What the ledger holds against a meeting, in the admin page's words. */
const LEDGER_LABELS: Record< MeetingLedgerState, string > = {
  forecast: 'Forecast only',
  reserved: 'Set aside',
  used: 'Spent',
  released: 'Given back',
};

/** What each settle says once it has landed, as the admin page says it. */
const SETTLED: Record< MeetingStatus, string > = {
  held: 'Marked held. The hours have been drawn.',
  cancelled: 'Cancelled. No hours were charged.',
  'no-show': 'Recorded. No hours were charged.',
  scheduled: 'Scheduled again. Its hours are set aside.',
};

/** Where the client's timezone is not known, the studio's own. */
const DEFAULT_TIMEZONE = 'Europe/London';

/**
 * `Recurrence::planned_hours()`, for the form's hint: a meeting of this length
 * plans for the next half hour up, so an hour and ten minutes is an hour and
 * a half. Nought sent to the server means "work it out from the length", and
 * this is what it will work out.
 */
function plannedHours( durationMins: number ): number {
  return durationMins <= 0 ? 0 : Math.ceil( durationMins / 30 ) / 2;
}

/** The Monday of the week a YYYY-MM-DD falls in, as YYYY-MM-DD. */
function mondayOf( on: string ): string {
  const day = new Date( `${ on }T00:00:00Z` );

  day.setUTCDate( day.getUTCDate() - ( ( day.getUTCDay() + 6 ) % 7 ) );

  return day.toISOString().slice( 0, 10 );
}

/** "2026-10-05" → "5 October 2026", for a week's title. */
function longDate( on: string ): string {
  return new Date( `${ on }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' } );
}

function today(): string {
  return new Date().toISOString().slice( 0, 10 );
}

/** What a form shows when the server refuses by field, or otherwise. */
function refusal( error: unknown, fallback: string ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, fallback );
}

/**
 * A meeting as a row: every row needs a key of its own, and a forecast has no
 * id yet, so the series and the slot stand in — together they are what a move
 * or a settle names it by.
 */
interface MeetingRow extends Omit< Meeting, 'id' > {
  id: string;
  stored: string | null;
}

/** The meetings by the Monday of their week, in the order the server gave them. */
function byWeek( meetings: Meeting[] ): Array< { monday: string; rows: MeetingRow[] } > {
  const weeks: Array< { monday: string; rows: MeetingRow[] } > = [];

  for ( const meeting of meetings ) {
    const monday = mondayOf( meeting.on );
    const row: MeetingRow = { ...meeting, id: `${ meeting.series_id }@${ meeting.slot }`, stored: meeting.id };
    const week = weeks.find( ( one ) => one.monday === monday );

    if ( week ) {
      week.rows.push( row );
    } else {
      weeks.push( { monday, rows: [ row ] } );
    }
  }

  return weeks.sort( ( a, b ) => a.monday.localeCompare( b.monday ) );
}

type Opened = { kind: 'add' } | { kind: 'move'; meeting: Meeting } | { kind: 'settle'; meeting: Meeting } | null;

export function MeetingsScreen( { site }: { site: string } ) {
  const [ siteId, setSiteId ] = useState( site );
  const [ answer, setAnswer ] = useState< MeetingsAnswer | null >( null );
  const [ state, setState ] = useState< 'idle' | 'loading' | 'ready' | 'denied' | 'error' >( site ? 'loading' : 'idle' );
  const [ notice, setNotice ] = useState( '' );
  const [ opened, setOpened ] = useState< Opened >( null );
  const [ busy, setBusy ] = useState( false );

  /** A write's answer is the whole picture, so it is shown rather than re-read. */
  function landed( fresh: MeetingsAnswer, said = '' ) {
    setAnswer( fresh );
    setOpened( null );
    setNotice( said );
  }

  async function load( id: string = siteId ) {
    setNotice( '' );

    if ( '' === id ) {
      setAnswer( null );
      setState( 'idle' );

      return;
    }

    try {
      const fresh = await api< MeetingsAnswer >( `/client-sites/${ id }/meetings` );

      setAnswer( fresh );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'The site\'s meetings could not be read.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load( site );
    // The site prop is a landing, read once; picking is the picker's job.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( () => load() );

  function pick( id: string ) {
    if ( id === siteId ) {
      return;
    }

    setSiteId( id );
    setOpened( null );
    setState( id ? 'loading' : 'idle' );
    void load( id );
  }

  /** Ending a series, after a question: the hours its meetings hold go back. */
  async function end( series: MeetingSeries ) {
    if ( ! window.confirm( `End ${ series.title }? Past meetings are untouched; the hours its coming meetings are holding go back to the site.` ) ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      landed(
        await api< MeetingsAnswer >( `/client-sites/${ siteId }/meetings/series/${ series.id }/end`, { method: 'POST', body: { record_version: series.record_version } } ),
        'Series ended. Past meetings are untouched and any held hours have been given back.'
      );
    } catch ( error ) {
      setNotice( messageFor( error, 'That series could not be ended.' ) );
    } finally {
      setBusy( false );
    }
  }

  const columns: Column< MeetingRow >[] = [
    {
      key: 'when',
      label: 'When',
      width: 200,
      render: ( m ) => (
        <span className="bwx-meetings-when" data-testid="bwx-meetings-meeting" data-slot={ m.slot } data-on={ m.on } data-status={ m.status } data-ledger={ m.ledger_state }>
          <span className="fk-mono">{ `${ m.on } ${ m.time }` }</span>
          { /*
             * The line under the date rather than beside it: a meeting on an
             * odd day explains itself, and the explanation is secondary to
             * the date it is explaining.
             */ }
          { null !== m.excepted_from && <span className="bwx-meetings-moved">{ `(moved from ${ m.excepted_from })` }</span> }
        </span>
      ),
    },
    { key: 'what', label: 'What', wrap: true, render: ( m ) => m.series_title },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 80, render: ( m ) => hoursLabel( m.hours ) },
    { key: 'status', label: 'What happened', width: 130, render: ( m ) => m.status_label },
    {
      key: 'ledger',
      label: 'Hours held',
      width: 130,
      render: ( m ) => <Tag tone={ 'reserved' === m.ledger_state ? 'info' : 'used' === m.ledger_state ? 'ok' : 'neutral' }>{ LEDGER_LABELS[ m.ledger_state ] }</Tag>,
    },
    {
      key: 'actions',
      width: 150,
      render: ( m ) => (
        <span className="bwx-moves">
          <Button size="sm" variant="ghost" data-testid="bwx-meetings-move" aria-label={ `Move the meeting on ${ m.on }` } disabled={ busy } onClick={ () => setOpened( { kind: 'move', meeting: { ...m, id: m.stored } } ) }>
            Move
          </Button>
          <Button size="sm" variant="ghost" data-testid="bwx-meetings-settle" aria-label={ `Settle the meeting on ${ m.on }` } disabled={ busy } onClick={ () => setOpened( { kind: 'settle', meeting: { ...m, id: m.stored } } ) }>
            Settle
          </Button>
        </span>
      ),
    },
  ];

  const weeks = answer ? byWeek( answer.meetings ) : [];

  return (
    <div className="bwx-meetings" data-testid="bwx-meetings">
      <SitePicker value={ siteId } onChange={ pick } testId="bwx-meetings-site" />

      { 'idle' === state && <EmptyState icon={ CalendarClock } title="No site chosen" body="Choose a site to see its meetings." /> }
      { 'loading' === state && <Screen state="loading" testId="bwx-meetings-state-screen" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-meetings-state-screen" detail="A site's standing meetings are configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-meetings-state-screen" detail={ notice } /> }

      { 'ready' === state && answer && (
        <>
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-meetings-notice" role="status">
              { notice }
            </p>
          ) }

          <Panel
            title="Standing meetings"
            right={
              <Button size="sm" data-testid="bwx-meetings-add" disabled={ busy } onClick={ () => setOpened( { kind: 'add' } ) }>
                Add a standing meeting
              </Button>
            }
          >
            <div data-testid="bwx-meetings-standing">
              { 0 === answer.series.length ? (
                <EmptyState icon={ CalendarClock } dense title="No standing meetings" body="This site has no standing meetings. Add one to see the twelve weeks it implies." />
              ) : (
                <div className="bwx-meetings-cards">
                  { answer.series.map( ( one ) => (
                    <SeriesCard key={ one.id } series={ one } busy={ busy } onEnd={ () => void end( one ) } />
                  ) ) }
                </div>
              ) }
            </div>
          </Panel>

          <Panel title="The next twelve weeks">
            <div className="bwx-meetings-weeks" data-testid="bwx-meetings-list" data-from={ answer.horizon.from } data-to={ answer.horizon.to }>
              { 0 === weeks.length ? (
                <EmptyState icon={ CalendarX2 } dense title="Nothing is coming up" body="No meeting falls inside the next twelve weeks." />
              ) : (
                // The kit has no grouping, so each week is a table of its own
                // under the Monday it starts on.
                weeks.map( ( week ) => (
                  <div key={ week.monday } data-week={ week.monday }>
                    <DataView< MeetingRow >
                      title={ `Week of ${ longDate( week.monday ) }` }
                      columns={ columns }
                      rows={ week.rows }
                      sortable={ false }
                      testId="bwx-meetings-week"
                    />
                  </div>
                ) )
              ) }
            </div>
          </Panel>

          { opened && 'add' === opened.kind && <SeriesForm siteId={ siteId } people={ answer.people } onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'move' === opened.kind && <MoveForm siteId={ siteId } meeting={ opened.meeting } onClose={ () => setOpened( null ) } onSaved={ landed } /> }
          { opened && 'settle' === opened.kind && <SettleForm siteId={ siteId } meeting={ opened.meeting } onClose={ () => setOpened( null ) } onSaved={ landed } /> }
        </>
      ) }
    </div>
  );
}

/** One standing meeting: what, how often, when, who hosts, what each costs, and whether it still runs. */
function SeriesCard( { series, busy, onEnd }: { series: MeetingSeries; busy: boolean; onEnd: () => void } ) {
  const running = 'active' === series.state;
  const span = '' === series.ends_on ? `from ${ series.starts_on }` : `${ series.starts_on } to ${ series.ends_on }`;

  return (
    <div data-testid="bwx-meetings-series" data-series={ series.id } data-state={ series.state }>
      <Card pad={ 16 }>
        <div className="bwx-meetings-card-head">
          <h4 className="bwx-meetings-card-title">
            { series.title } { running ? <Tag tone="ok">Running</Tag> : <Tag tone="neutral">Ended</Tag> }
          </h4>
          { running && (
            <Button size="sm" variant="ghost" data-testid="bwx-meetings-series-end" aria-label={ `End ${ series.title }` } disabled={ busy } onClick={ onEnd }>
              End
            </Button>
          ) }
        </div>
        <dl className="bwx-meetings-facts">
          <dt>How often</dt>
          <dd>{ series.frequency_label }</dd>
          <dt>When</dt>
          <dd>{ `${ series.time_of_day } ${ series.timezone }, ${ series.duration_mins } min, ${ span }` }</dd>
          <dt>Host</dt>
          <dd>{ series.host_name || '—' }</dd>
          <dt>Hours each</dt>
          <dd className="fk-mono">{ hoursLabel( series.hours_each ) }</dd>
          { '' !== series.attendees && (
            <>
              <dt>Who else comes</dt>
              <dd>{ series.attendees }</dd>
            </>
          ) }
        </dl>
      </Card>
    </div>
  );
}

type Saved = ( answer: MeetingsAnswer, said?: string ) => void;

/**
 * Starting a series. The ten inputs `Meetings\Validate::series()` reads, less
 * the site, which is the path. Nought hours means "work it out from the
 * length" (MEET-3), and the hint says what that would be.
 */
function SeriesForm( { siteId, people, onClose, onSaved }: { siteId: string; people: MeetingsAnswer[ 'people' ]; onClose: () => void; onSaved: Saved } ) {
  const [ title, setTitle ] = useState( '' );
  const [ frequency, setFrequency ] = useState< MeetingFrequency >( 'weekly' );
  const [ starts, setStarts ] = useState( today() );
  const [ ends, setEnds ] = useState( '' );
  const [ time, setTime ] = useState( '10:00' );
  const [ duration, setDuration ] = useState( '60' );
  const [ timezone, setTimezone ] = useState( DEFAULT_TIMEZONE );
  const [ host, setHost ] = useState( '' );
  const [ attendees, setAttendees ] = useState( '' );
  const [ hours, setHours ] = useState( '0' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  const derived = plannedHours( Number( duration ) || 0 );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< MeetingsAnswer >( `/client-sites/${ siteId }/meetings/series`, {
          method: 'POST',
          body: {
            title,
            frequency,
            starts_on: starts,
            ends_on: ends,
            time_of_day: time,
            duration_mins: Number( duration ) || 0,
            timezone,
            host_user_id: host,
            attendees,
            planned_hours: Number( hours ) || 0,
          },
        } ),
        'Series added. Its meetings are below.'
      );
    } catch ( error ) {
      setNotice( refusal( error, 'That series could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title="Add a standing meeting"
      width={ 560 }
      testId="bwx-meetings-series-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-meetings-series-save" disabled={ busy } onClick={ () => void save() }>
            Add
          </Button>
          <Button variant="ghost" data-testid="bwx-meetings-series-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-meetings-series-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="What it is called" required>
        { ( id ) => <TextInput id={ id } maxLength={ 191 } data-testid="bwx-meetings-series-title" value={ title } onChange={ ( event ) => setTitle( event.target.value ) } /> }
      </Field>
      <Field label="How often" required>
        { ( id ) => <Select id={ id } data-testid="bwx-meetings-series-frequency" value={ frequency } options={ FREQUENCIES } onChange={ ( event ) => setFrequency( event.target.value as MeetingFrequency ) } /> }
      </Field>
      <div className="bwx-meetings-pair">
        <Field label="First one" required>
          { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-meetings-series-starts" value={ starts } onChange={ ( event ) => setStarts( event.target.value ) } /> }
        </Field>
        <Field label="Last one" help="Leave empty for a series with no end.">
          { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-meetings-series-ends" value={ ends } onChange={ ( event ) => setEnds( event.target.value ) } /> }
        </Field>
      </div>
      <div className="bwx-meetings-pair">
        <Field label="At" required>
          { ( id ) => <TextInput id={ id } type="time" data-testid="bwx-meetings-series-time" value={ time } onChange={ ( event ) => setTime( event.target.value ) } /> }
        </Field>
        <Field label="For how long, in minutes" required>
          { ( id ) => <TextInput id={ id } type="number" min="1" max="480" step="1" inputMode="numeric" data-testid="bwx-meetings-series-duration" value={ duration } onChange={ ( event ) => setDuration( event.target.value ) } /> }
        </Field>
      </div>
      <Field label="Timezone" required help="The zone the meeting is held in: a ten o'clock call stays at ten when the clocks change.">
        { ( id ) => <TextInput id={ id } maxLength={ 64 } data-testid="bwx-meetings-series-timezone" value={ timezone } onChange={ ( event ) => setTimezone( event.target.value ) } /> }
      </Field>
      <Field label="Host" required help="Only the host can mark a meeting held, and that is what draws the hours.">
        { ( id ) => (
          <Select
            id={ id }
            data-testid="bwx-meetings-series-host"
            value={ host }
            options={ [ { value: '', label: 'Choose a host' }, ...people.map( ( one ) => ( { value: one.id, label: one.display_name } ) ) ] }
            onChange={ ( event ) => setHost( event.target.value ) }
          />
        ) }
      </Field>
      <Field label="Who else comes" help="A note, not accounts.">
        { ( id ) => <TextInput id={ id } maxLength={ 500 } data-testid="bwx-meetings-series-attendees" value={ attendees } onChange={ ( event ) => setAttendees( event.target.value ) } /> }
      </Field>
      <Field label="Hours each" help={ `Nought works it out from the length: ${ hoursLabel( derived ) } for ${ Number( duration ) || 0 } minutes, rounded up to the half hour.` }>
        { ( id ) => <TextInput id={ id } type="number" min="0" step="0.25" inputMode="decimal" data-testid="bwx-meetings-series-hours" value={ hours } onChange={ ( event ) => setHours( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}

/** Moving one meeting to another day. Only that meeting changes; the rule is untouched. */
function MoveForm( { siteId, meeting, onClose, onSaved }: { siteId: string; meeting: Meeting; onClose: () => void; onSaved: Saved } ) {
  const [ on, setOn ] = useState( meeting.on );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< MeetingsAnswer >( `/client-sites/${ siteId }/meetings/${ meeting.series_id }/${ meeting.slot }/move`, { method: 'POST', body: { on } } ),
        'Moved. Only that meeting changed.'
      );
    } catch ( error ) {
      setNotice( messageFor( error, 'That meeting could not be moved.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title="Move this meeting"
      description={ `${ meeting.series_title }, ${ meeting.on } at ${ meeting.time }. The rest of the series stays where it is.` }
      testId="bwx-meetings-move-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-meetings-move-save" disabled={ busy } onClick={ () => void save() }>
            Move
          </Button>
          <Button variant="ghost" data-testid="bwx-meetings-move-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-meetings-move-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="New date" required>
        { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-meetings-move-on" value={ on } onChange={ ( event ) => setOn( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}

/**
 * Saying what became of one meeting. Held is the only outcome that costs the
 * client anything (MEET-5); the others give the hours back, and scheduling it
 * again sets them aside once more.
 */
function SettleForm( { siteId, meeting, onClose, onSaved }: { siteId: string; meeting: Meeting; onClose: () => void; onSaved: Saved } ) {
  // What it already is cannot be chosen again: the form is for a change.
  const outcomes = OUTCOMES.filter( ( one ) => one.value !== meeting.status );
  const [ status, setStatus ] = useState< MeetingStatus >( outcomes[ 0 ].value );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< MeetingsAnswer >( `/client-sites/${ siteId }/meetings/${ meeting.series_id }/${ meeting.slot }/settle`, { method: 'POST', body: { status } } ),
        SETTLED[ status ]
      );
    } catch ( error ) {
      setNotice( messageFor( error, 'That meeting could not be settled.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title="What became of it"
      description={ `${ meeting.series_title }, ${ meeting.on } at ${ meeting.time }, ${ hoursLabel( meeting.hours ) }. Held draws the hours; anything else gives them back.` }
      testId="bwx-meetings-settle-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-meetings-settle-save" disabled={ busy } onClick={ () => void save() }>
            Save
          </Button>
          <Button variant="ghost" data-testid="bwx-meetings-settle-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-meetings-settle-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="What happened" required>
        { ( id ) => <Select id={ id } data-testid="bwx-meetings-settle-status" value={ status } options={ outcomes } onChange={ ( event ) => setStatus( event.target.value as MeetingStatus ) } /> }
      </Field>
    </Modal>
  );
}
