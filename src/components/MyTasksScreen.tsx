import { useEffect, useMemo, useState } from 'react';
import { ListChecks } from 'lucide-react';
import type { Stage, WorkItem } from '../types';
import { CLIENT_REVIEWER } from '../types';
import { api, forgeData, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState, StageChip, Tag } from '../kit';
import type { Column, SavedView } from '../kit';
import { DiaryLine, useDiary } from './Diary';
import { useClientChoice } from '../ClientChoice';
import { ItemPanel } from './ItemPanel';
import { Screen } from './States';
import { ALL_SITES } from '../sites';
import { listed, mineFor, viewOf } from '../mytasks';
import type { Mine, Role, Site, View } from '../mytasks';

/*
 * My Tasks (#309): one person's day and week, from the same records as the
 * board. Every item on every site that is the signed-in person's to act on at
 * the stage it is at (2026-09-24), once, split into four saved views over one
 * table — Today, the next seven days, further out, and everything — so the
 * counts always add up.
 *
 * Every site's work comes in one read (2026-09-19) — reading each site in
 * turn took a request per site, and a studio with two hundred sites waited
 * minutes. The screen keeps what names this person; the split is worked out
 * here from the due dates, the same way the standup does.
 */

const ROLE_LABEL: Record< Role, string > = { primary: 'Owner', designer: 'Designer', reviewer: 'Checker', deliverer: 'Builder', assignee: 'Yours to tick' };
const ROLE_TONE: Record< Role, 'brand' | 'info' | 'neutral' | 'ok' > = { primary: 'brand', designer: 'info', reviewer: 'info', deliverer: 'neutral', assignee: 'ok' };

/** Today, in the browser's own zone — the same today the due dates below use. */
function todayISO(): string {
  return new Date().toISOString().slice( 0, 10 );
}

function dueText( one: Mine ): string {
  if ( null === one.due ) return '—';
  if ( one.due < 0 ) return `${ one.due }d`;
  if ( 0 === one.due ) return 'today';
  return new Date( `${ one.item.planned_due || one.item.derived_due }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', timeZone: 'UTC' } );
}

export function MyTasksScreen() {
  const data = forgeData();
  const [ state, setState ] = useState< 'loading' | 'ready' | 'error' | 'denied' | 'nobody' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );
  const me = data?.person ?? null;
  const [ mine, setMine ] = useState< Mine[] >( [] );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ view, setView ] = useState< View >( 'today' );
  const [ search, setSearch ] = useState( '' );
  const [ role, setRole ] = useState< string | null >( null );
  const [ opened, setOpened ] = useState( '' );
  // The top bar's client (#402) replaces the screen's own Client filter.
  const { siteId, label } = useClientChoice();

  /*
   * Today's diary (#386): moved here from the standup, and narrowed to what
   * is the signed-in person's — an entry for 'all' (a company day) or one
   * that names them. The same feed the calendar draws, read for one day
   * rather than the standup's heavier board.
   */
  const day = todayISO();
  const diary = useDiary( day, day );
  const myDiary = useMemo(
    () => diary.entries.filter( ( entry ) => 'all' === entry.people || ( null !== me && entry.people.includes( me.id ) ) ),
    [ diary.entries, me ]
  );
  // A finished chore or reminder leaves Today's diary; a meeting, date or
  // renewal stays regardless (#412). The calendar screen still shows a done
  // one — it is a record — so this filter is Today's diary alone.
  const openDiary = useMemo(
    () => myDiary.filter( ( entry ) => ! ( ( 'recurring' === entry.kind || 'reminder' === entry.kind ) && entry.done ) ),
    [ myDiary ]
  );

  async function load() {
    try {
      if ( ! me ) {
        setState( 'nobody' );
        return;
      }
      const person = me;

      const [ siteList, stageList, loaded ] = await Promise.all( [
        api< { sites: Site[] } >( '/client-sites' ),
        api< { stages: Stage[] } >( '/stages' ),
        api< { items: WorkItem[] } >( '/work-items-all' ),
      ] );
      setStages( stageList.stages );

      const found = mineFor( person, siteList.sites, loaded.items );
      setMine( found );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Your tasks could not be loaded.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( load );

  // Narrowed first, so the counts on the tabs are for the picked client too.
  // Done work is under no view, Everything included (Luke, 2026-09-27).
  const ours = useMemo( () => listed( mine, siteId ), [ mine, siteId ] );

  const counts = useMemo( () => {
    const c: Record< View | 'done', number > = { today: 0, week: 0, later: 0, done: 0, all: ours.length };
    for ( const one of ours ) c[ viewOf( one ) ] += 1;
    return c;
  }, [ ours ] );

  const rows = useMemo( () => {
    const needle = search.trim().toLowerCase();
    return ours
      .filter( ( one ) => 'all' === view || viewOf( one ) === view )
      .filter( ( one ) => ! role || ROLE_LABEL[ one.role ] === role )
      .filter( ( one ) => ! needle || `${ one.item.id } ${ one.item.title } ${ one.site.client_name }`.toLowerCase().includes( needle ) )
      .sort( ( a, b ) => ( a.due ?? 9999 ) - ( b.due ?? 9999 ) );
  }, [ ours, view, role, search ] );

  const views: SavedView[] = [
    { id: 'today', label: 'Today', count: counts.today },
    { id: 'week', label: 'Next seven days', count: counts.week },
    { id: 'later', label: 'Further out', count: counts.later },
    { id: 'all', label: 'Everything assigned to you', count: counts.all },
  ];

  const columns: Column< Mine >[] = [
    {
      key: 'title',
      label: 'Task',
      wrap: true,
      sortBy: ( r ) => r.item.title,
      render: ( r ) => (
        <span className="bwx-mytasks-title">
          <span className="bwx-mytasks-title-row">
            <button type="button" className="bwx-row-open" data-testid="bwx-mytasks-open" onClick={ () => setOpened( r.item.id ) }>
              { r.item.title }
            </button>
            { 'blocked' === r.item.stage && <Tag tone="danger">Blocked</Tag> }
            { 'in-review' === r.item.stage && CLIENT_REVIEWER === r.item.reviewer_id && (
              <span data-testid="bwx-mytasks-client-reviewing">
                <Tag tone="info">Waiting on the client</Tag>
              </span>
            ) }
            { /* How many have done a chore, beside its title. It is ticked off
                  from inside the task, never from the list (2026-09-27). */ }
            { 'assignee' === r.role && (
              <span className="bwx-mytasks-done" data-testid="bwx-mytasks-done">
                Done <span className="bwx-mono">{ `${ Object.keys( r.item.ticks ?? {} ).length } of ${ r.item.assignees.length }` }</span>
              </span>
            ) }
          </span>
        </span>
      ),
    },
    { key: 'client', label: 'Client', width: 140, sortBy: ( r ) => r.site.client_name, render: ( r ) => r.site.client_name || r.site.name },
    { key: 'stage', label: 'Stage', width: 170, sortBy: ( r ) => r.item.stage_label, render: ( r ) => <StageChip stage={ r.item.stage } dense /> },
    { key: 'role', label: 'Your role', width: 110, sortBy: ( r ) => r.role, render: ( r ) => <Tag tone={ ROLE_TONE[ r.role ] }>{ ROLE_LABEL[ r.role ] }</Tag> },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 80, sortBy: ( r ) => r.hours, render: ( r ) => ( r.hours ? `${ r.hours.toFixed( 1 ) }h` : '—' ) },
    {
      key: 'due',
      label: 'Due',
      mono: true,
      align: 'right',
      width: 90,
      sortBy: ( r ) => r.due ?? 9999,
      // Red more than a day late, yellow a day late, plain today, green ahead (2026-09-19).
      render: ( r ) => (
        <span className="bwx-mytasks-due" data-due={ null === r.due ? 'none' : r.due < -1 ? 'late' : r.due < 0 ? 'yesterday' : 0 === r.due ? 'today' : 'ahead' }>
          { dueText( r ) }
        </span>
      ),
    },
  ];

  const EMPTY: Record< View, string > = {
    today: 'Nothing needs you today',
    week: 'Nothing due in the next seven days',
    later: 'Nothing further out',
    all: 'No task names you in any role',
  };

  return (
    <>
      { 'loading' === state && <Screen state="loading" detail="Reading what names you, on every site." /> }

      { 'denied' === state && <Screen state="denied" testId="bwx-mytasks-state" detail="You are signed in, but not on any client whose work you may read." /> }

      { 'error' === state && <Screen state="error" testId="bwx-mytasks-state" detail={ notice } /> }

      { 'nobody' === state && (
        <Screen
          state="empty"
          testId="bwx-mytasks-state"
          title="Your account is not a person in Forge yet"
          detail="Tasks are held by the studio's people. Somebody with access to the People screen can link your WordPress account to one."
        />
      ) }

      { /*
          Today's diary (#386): the chores, dates, meetings, renewals and
          absences that are yours today. Shown whenever there is one.
       */ }
      { 'ready' === state && 0 < myDiary.length && (
        <section className="bwx-standup-diary" data-testid="bwx-mytasks-diary">
          <p className="bwx-eyebrow">Today&apos;s diary</p>
          { 0 < openDiary.length ? (
            <ul className="bwx-diary-lines">
              { openDiary.map( ( entry ) => (
                <DiaryLine key={ entry.id } entry={ entry } onOpen={ setOpened } />
              ) ) }
            </ul>
          ) : (
            <p className="bwx-muted" data-testid="bwx-mytasks-diary-clear">All done for today.</p>
          ) }
        </section>
      ) }

      { 'ready' === state && (
        <div className="bwx-list bwx-mytasks" data-testid="bwx-mytasks" data-person={ me?.id }>
          <DataView
            title={ `${ me?.display_name ?? 'Your' } — tasks` }
            titleRight={ <span className="bwx-mono">{ counts.all } in all</span> }
            views={ views }
            activeView={ view }
            onViewChange={ ( id ) => setView( id as View ) }
            search={ search }
            onSearch={ setSearch }
            searchPlaceholder="Search your tasks"
            filters={ [ { id: 'role', label: 'Your role', value: role, options: [ 'Any role', 'Owner', 'Designer', 'Checker', 'Builder' ] } ] }
            onFilter={ ( _id, value ) => setRole( null === value || 'Any role' === value ? null : value ) }
            onClearFilters={ () => setRole( null ) }
            columns={ columns }
            rows={ rows }
            sortable
            empty={ <EmptyState icon={ ListChecks } dense title={ ALL_SITES === siteId ? EMPTY[ view ] : `Nothing for ${ label() } here.` } body="Counts here are the same records the board and Capacity read, so the four views always add up." /> }
            footer={ `${ rows.length } of ${ counts.all } · Today ${ counts.today } + next seven days ${ counts.week } + further out ${ counts.later } = ${ counts.all }` }
            testId="bwx-mytasks-table"
          />
        </div>
      ) }

      { '' !== opened && <ItemPanel itemId={ opened } stages={ stages } onClose={ () => setOpened( '' ) } onChanged={ () => void load() } /> }
    </>
  );
}
