import { useState } from 'react';
import type { DiaryEntry, WorkItem } from '../types';
import { phaseOf } from '../phases';
import {
  KIND_LABEL,
  byDay,
  daysFor,
  entriesFor,
  inMonthOf,
  step,
  titleFor,
  todayIso,
} from '../calendar';
import type { Mode } from '../calendar';
import { DIARY_KINDS, DiaryList, diaryByDay, useDiary } from './Diary';

/** How many entries a month cell shows before it says there are more. */
const CELL_LIMIT = 3;

const WEEKDAYS = [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ];

/**
 * The same work, on dates.
 *
 * A work item carries four of them — when it starts, when it is due, when it is
 * meant to be reviewed and when it is meant to ship — and each is a different
 * day with a different meaning. So an item appears once per date it carries and
 * each appearance says which date it is. Collapsing them to one entry on the
 * due date would answer "when is this due" and quietly stop answering "what is
 * shipping this week", which is most of why anybody opens a calendar.
 *
 * Work with no dates makes no entries. That is the honest answer rather than a
 * loss — but it is also why this view cannot be reconciled against the board by
 * counting, and the test that checks the two agree compares which items appear,
 * not how many.
 */
export function CalendarView( {
  items,
  onOpen,
  onOpenId,
}: {
  items: WorkItem[];
  onOpen: ( item: WorkItem ) => void;
  /** Opening a chore from the diary, which is not among the site's items. */
  onOpenId: ( itemId: string ) => void;
} ) {
  const today = todayIso();

  const [ mode, setMode ] = useState< Mode >( 'month' );
  const [ anchor, setAnchor ] = useState( today );
  const [ expanded, setExpanded ] = useState( '' );

  const days = daysFor( mode, anchor );
  const entries = byDay( entriesFor( items ) );

  // The diary for the days drawn (2026-09-18): chores, dates, meetings,
  // renewals and who is away, beside the work.
  const diary = useDiary( days[ 0 ], days[ days.length - 1 ] );
  const diaryDays = diaryByDay( diary.entries );

  return (
    <div className="bwx-calendar" data-testid="bwx-calendar" data-mode={ mode }>
      <div className="bwx-calendar-head">
        <div className="bwx-views" role="group" aria-label="Calendar range">
          { ( [ 'month', 'week', 'day', 'list' ] as Mode[] ).map( ( each ) => (
            <button
              key={ each }
              type="button"
              className="bwx-button"
              data-variant={ each === mode ? undefined : 'quiet' }
              data-testid={ `bwx-calendar-mode-${ each }` }
              aria-pressed={ each === mode }
              onClick={ () => setMode( each ) }
            >
              { each.charAt( 0 ).toUpperCase() + each.slice( 1 ) }
            </button>
          ) ) }
        </div>

        <button
          type="button"
          className="bwx-button"
          data-variant="quiet"
          data-testid="bwx-calendar-back"
          aria-label="Show the range before this one"
          onClick={ () => setAnchor( step( mode, anchor, -1 ) ) }
        >
          ‹
        </button>

        <span className="bwx-calendar-title" data-testid="bwx-calendar-title">
          { titleFor( mode, anchor ) }
        </span>

        <button
          type="button"
          className="bwx-button"
          data-variant="quiet"
          data-testid="bwx-calendar-forward"
          aria-label="Show the range after this one"
          onClick={ () => setAnchor( step( mode, anchor, 1 ) ) }
        >
          ›
        </button>

        <button
          type="button"
          className="bwx-button"
          data-variant="quiet"
          data-testid="bwx-calendar-today"
          onClick={ () => setAnchor( today ) }
        >
          Today
        </button>

        { /* A date, typed. Paging to a month eight steps away is not a way to
             get to a date somebody already knows. */ }
        <label className="bwx-calendar-goto">
          <span className="bwx-eyebrow">Go to</span>
          <input
            type="date"
            className="bwx-input"
            data-testid="bwx-calendar-goto"
            value={ anchor }
            onChange={ ( event ) => '' !== event.target.value && setAnchor( event.target.value ) }
          />
        </label>
      </div>

      { 'list' === mode && (
        <DiaryList entries={ diary.entries } onOpen={ onOpenId } onChanged={ diary.reload } />
      ) }

      { 'day' !== mode && 'list' !== mode && (
        <div className="bwx-calendar-weekdays" aria-hidden="true">
          { WEEKDAYS.map( ( name ) => (
            <span key={ name }>{ name }</span>
          ) ) }
        </div>
      ) }

      { 'list' !== mode && (
      <div className="bwx-calendar-grid">
        { days.map( ( date ) => {
          const held = entries[ date ] ?? [];
          const onDiary = diaryDays[ date ] ?? [];
          // The diary and the work share one cap (Luke, 2026-09-20): a day
          // shows its first few of either, and "+N more" opens the rest.
          const total = onDiary.length + held.length;
          const capped = 'month' === mode && date !== expanded && CELL_LIMIT < total;
          const diaryShown = capped ? onDiary.slice( 0, CELL_LIMIT ) : onDiary;
          const shown = capped ? held.slice( 0, Math.max( 0, CELL_LIMIT - onDiary.length ) ) : held;

          return (
            <div
              key={ date }
              className="bwx-calendar-day"
              data-testid="bwx-calendar-day"
              data-date={ date }
              data-today={ date === today ? 'true' : undefined }
              data-outside={ 'month' === mode && ! inMonthOf( date, anchor ) ? 'true' : undefined }
            >
              <span className="bwx-calendar-date">
                { 'day' === mode || 'week' === mode
                  ? new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', {
                    weekday: 'short',
                    day: 'numeric',
                    timeZone: 'UTC',
                  } )
                  : Number( date.slice( 8 ) ) }
              </span>

              <ul className="bwx-calendar-entries">
                { diaryShown.map( ( entry: DiaryEntry ) => (
                  <li key={ entry.id }>
                    <button
                      type="button"
                      className="bwx-calendar-entry bwx-calendar-diary"
                      data-testid="bwx-calendar-diary"
                      data-kind={ entry.kind }
                      data-entry={ entry.id }
                      data-tone={ DIARY_KINDS[ entry.kind ].tone }
                      title={ entry.detail }
                      onClick={ () => '' !== entry.item_id && onOpenId( entry.item_id ) }
                    >
                      <span className="bwx-calendar-kind">{ DIARY_KINDS[ entry.kind ].label }</span>
                      <span className="bwx-calendar-entry-title">{ entry.title }</span>
                    </button>
                  </li>
                ) ) }
                { shown.map( ( entry ) => (
                  <li key={ `${ entry.item.id }-${ entry.kind }` }>
                    <button
                      type="button"
                      className="bwx-calendar-entry"
                      data-phase={ phaseOf( entry.item.stage ) }
                      data-testid="bwx-calendar-entry"
                      data-item={ entry.item.id }
                      data-kind={ entry.kind }
                      style={
                        {
                          '--entry-phase': `var(--phase-${ phaseOf( entry.item.stage ) })`,
                        } as React.CSSProperties
                      }
                      onClick={ () => onOpen( entry.item ) }
                    >
                      <span className="bwx-calendar-kind">{ KIND_LABEL[ entry.kind ] }</span>
                      <span className="bwx-calendar-entry-title">{ entry.item.title }</span>
                    </button>
                  </li>
                ) ) }
              </ul>

              { /* Said rather than silently dropped: a day that quietly shows
                   three of seven is a calendar that lies about a busy day. */ }
              { capped && (
                <button
                  type="button"
                  className="bwx-calendar-more"
                  data-testid="bwx-calendar-more"
                  aria-expanded={ false }
                  onClick={ () => setExpanded( date ) }
                >
                  +{ total - CELL_LIMIT } more
                </button>
              ) }
              { 'month' === mode && date === expanded && CELL_LIMIT < total && (
                <button
                  type="button"
                  className="bwx-calendar-more"
                  data-testid="bwx-calendar-less"
                  aria-expanded={ true }
                  onClick={ () => setExpanded( '' ) }
                >
                  Show less
                </button>
              ) }
            </div>
          );
        } ) }
      </div>
      ) }

      { 'list' !== mode && 0 === Object.keys( entries ).length && 0 === diary.entries.length && (
        <p className="bwx-calendar-empty" data-testid="bwx-calendar-empty">
          Nothing here has dates yet, so there is nothing to put on a calendar.
        </p>
      ) }
    </div>
  );
}
