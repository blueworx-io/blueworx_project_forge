import { useEffect, useMemo, useState } from 'react';
import { ListChecks } from 'lucide-react';
import type { ClientSite, Stage, WorkItem } from '../types';
import { api, forgeData, isDenied, messageFor } from '../api';
import { DataView, EmptyState, StageChip, Tag } from '../kit';
import type { Column, SavedView } from '../kit';
import { ItemPanel } from './ItemPanel';
import { Screen } from './States';

/*
 * My Tasks (#309): one person's day and week, from the same records as the
 * board. Every item on every site where the signed-in person holds one of the
 * three seats, split into four saved views over one table — Today, the next
 * seven days, further out, and everything — so the counts always add up.
 *
 * There is no cross-site route for this yet, so the screen reads each site's
 * work and keeps what names this person. The split is worked out here from
 * the due dates, the same way the standup does.
 */

type Site = ClientSite & { client_name: string };
type Role = 'primary' | 'reviewer' | 'deliverer';

const ROLE_LABEL: Record< Role, string > = { primary: 'Owner', reviewer: 'Checker', deliverer: 'Builder' };
const ROLE_TONE: Record< Role, 'brand' | 'info' | 'neutral' > = { primary: 'brand', reviewer: 'info', deliverer: 'neutral' };
const FINISHED = [ 'completed', 'released' ];
const DAY = 86400000;

interface Mine extends Record< string, unknown > {
  id: string;
  item: WorkItem;
  role: Role;
  site: Site;
  hours: number;
  /** Days until due; null when undated. */
  due: number | null;
}

type View = 'today' | 'week' | 'later' | 'all';

function daysUntil( date: string ): number | null {
  if ( ! date ) return null;
  const at = new Date( `${ date }T00:00:00Z` ).getTime();
  if ( isNaN( at ) ) return null;
  const today = new Date( new Date().toISOString().slice( 0, 10 ) + 'T00:00:00Z' ).getTime();
  return Math.round( ( at - today ) / DAY );
}

/** Today: late, blocked, in delivery or in review with you; the rest by date. */
function viewOf( one: Mine ): View {
  const finished = FINISHED.includes( one.item.stage );
  if ( finished ) return 'later';
  if ( 'blocked' === one.item.stage ) return 'today';
  if ( null !== one.due && one.due <= 1 ) return 'today';
  if ( 'primary' === one.role && 'in-development' === one.item.stage ) return 'today';
  if ( 'reviewer' === one.role && 'in-review' === one.item.stage ) return 'today';
  if ( null !== one.due && one.due <= 8 ) return 'week';
  return 'later';
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
  const [ client, setClient ] = useState< string | null >( null );
  const [ opened, setOpened ] = useState( '' );

  async function load() {
    try {
      if ( ! me ) {
        setState( 'nobody' );
        return;
      }
      const person = me;

      const [ siteList, stageList ] = await Promise.all( [
        api< { sites: Site[] } >( '/client-sites' ),
        api< { stages: Stage[] } >( '/stages' ),
      ] );
      setStages( stageList.stages );

      const found: Mine[] = [];
      for ( const site of siteList.sites ) {
        const loaded = await api< { items: WorkItem[] } >( `/work-items?client_site_id=${ encodeURIComponent( site.id ) }` ).catch( () => ( { items: [] } ) );
        for ( const item of loaded.items ) {
          const seats: Array< [ Role, string, number ] > = [
            [ 'primary', item.primary_user_id, item.hours_primary ],
            [ 'reviewer', item.reviewer_id, item.hours_review ],
            [ 'deliverer', item.deliverer_id, item.hours_delivery ],
          ];
          for ( const [ seat, holder, hours ] of seats ) {
            if ( holder === person.id ) {
              found.push( { id: `${ item.id }:${ seat }`, item, role: seat, site, hours, due: daysUntil( item.planned_due || item.derived_due || '' ) } );
            }
          }
        }
      }
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

  const counts = useMemo( () => {
    const c: Record< View, number > = { today: 0, week: 0, later: 0, all: mine.length };
    for ( const one of mine ) c[ viewOf( one ) ] += 1;
    return c;
  }, [ mine ] );

  const clients = useMemo( () => [ ...new Set( mine.map( ( one ) => one.site.client_name || one.site.name ) ) ].sort(), [ mine ] );

  const rows = useMemo( () => {
    const needle = search.trim().toLowerCase();
    return mine
      .filter( ( one ) => 'all' === view || viewOf( one ) === view )
      .filter( ( one ) => ! role || ROLE_LABEL[ one.role ] === role )
      .filter( ( one ) => ! client || ( one.site.client_name || one.site.name ) === client )
      .filter( ( one ) => ! needle || `${ one.item.id } ${ one.item.title } ${ one.site.client_name }`.toLowerCase().includes( needle ) )
      .sort( ( a, b ) => ( a.due ?? 9999 ) - ( b.due ?? 9999 ) );
  }, [ mine, view, role, client, search ] );

  const views: SavedView[] = [
    { id: 'today', label: 'Today', count: counts.today },
    { id: 'week', label: 'Next seven days', count: counts.week },
    { id: 'later', label: 'Further out', count: counts.later },
    { id: 'all', label: 'Everything assigned to you', count: counts.all },
  ];

  const columns: Column< Mine >[] = [
    { key: 'ref', label: 'Item', mono: true, width: 110, sortBy: ( r ) => r.item.id, render: ( r ) => r.item.id.replace( 'wrk_', '' ).slice( 0, 8 ) },
    {
      key: 'title',
      label: 'Task',
      wrap: true,
      sortBy: ( r ) => r.item.title,
      render: ( r ) => (
        <span className="bwx-mytasks-title">
          <button type="button" className="bwx-row-open" data-testid="bwx-mytasks-open" onClick={ () => setOpened( r.item.id ) }>
            { r.item.title }
          </button>
          { 'blocked' === r.item.stage && <Tag tone="danger">Blocked</Tag> }
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
      render: ( r ) => (
        <span data-late={ null !== r.due && r.due < 0 ? 'true' : undefined } data-soon={ null !== r.due && r.due >= 0 && r.due <= 1 ? 'true' : undefined }>
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
      { 'loading' === state && <Screen state="loading" detail="Reading every site for what names you." /> }

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
            filters={ [
              { id: 'client', label: 'Client', value: client, options: [ 'All clients', ...clients ] },
              { id: 'role', label: 'Your role', value: role, options: [ 'Any role', 'Owner', 'Checker', 'Builder' ] },
            ] }
            onFilter={ ( id, value ) => {
              const cleared = null === value || 'All clients' === value || 'Any role' === value;
              ( 'client' === id ? setClient : setRole )( cleared ? null : value );
            } }
            onClearFilters={ () => {
              setClient( null );
              setRole( null );
            } }
            columns={ columns }
            rows={ rows }
            sortable
            empty={ <EmptyState icon={ ListChecks } dense title={ EMPTY[ view ] } body="Counts here are the same records the board and Capacity read, so the four views always add up." /> }
            footer={ `${ rows.length } of ${ counts.all } · Today ${ counts.today } + next seven days ${ counts.week } + further out ${ counts.later } = ${ counts.all }` }
            testId="bwx-mytasks-table"
          />
        </div>
      ) }

      { '' !== opened && <ItemPanel itemId={ opened } stages={ stages } onClose={ () => setOpened( '' ) } onChanged={ () => void load() } /> }
    </>
  );
}
