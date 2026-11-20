import { useEffect, useMemo, useState } from 'react';
import { Bot } from 'lucide-react';
import type { Person, Stage, WorkItem } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { DataView, EmptyState, StageChip, Tag } from '../kit';
import type { Column, SavedView } from '../kit';
import { useClientChoice } from '../ClientChoice';
import { ItemPanel } from './ItemPanel';
import { Screen } from './States';
import { ALL_SITES } from '../sites';
import { agentWork, isUrgent, listed, viewOf } from '../mytasks';
import { PRIORITY_TONE, priorityRank } from '../priority';
import type { Mine, Site, View } from '../mytasks';

/*
 * #693. The agent's work, for the whole studio: every task where it is the
 * agent's turn, with who looks after it. My tasks' tabs and order, read the
 * same way, so the two screens never disagree on what is due when. Nothing
 * here is anybody's own day, so no diary and no hours.
 */

function dueText( one: Mine ): string {
  if ( null === one.due ) return '—';
  if ( one.due < 0 ) return `${ one.due }d`;
  if ( 0 === one.due ) return 'today';
  return new Date( `${ one.item.planned_due || one.item.derived_due }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', timeZone: 'UTC' } );
}

export function AgentsScreen() {
  const [ state, setState ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );
  const [ notice, setNotice ] = useState( '' );
  const [ rowsAll, setRowsAll ] = useState< Mine[] >( [] );
  const [ names, setNames ] = useState< Record< string, string > >( {} );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ view, setView ] = useState< View >( 'today' );
  const [ search, setSearch ] = useState( '' );
  const [ keeper, setKeeper ] = useState< string | null >( null );
  const [ opened, setOpened ] = useState( '' );
  const { siteId, label } = useClientChoice();

  async function load() {
    try {
      const [ siteList, stageList, loaded, people ] = await Promise.all( [
        api< { sites: Site[] } >( '/client-sites' ),
        api< { stages: Stage[] } >( '/stages' ),
        api< { items: WorkItem[] } >( '/work-items-all' ),
        api< { people: Person[] } >( '/people' ),
      ] );
      setStages( stageList.stages );
      setNames( Object.fromEntries( people.people.map( ( person ) => [ person.id, person.display_name ] ) ) );
      setRowsAll( agentWork( siteList.sites, loaded.items ) );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'The agent\'s work could not be loaded.' ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( load );

  const keeperOf = ( one: Mine ) => names[ one.item.deliverer_id ] ?? 'Somebody';

  const ours = useMemo( () => listed( rowsAll, siteId ), [ rowsAll, siteId ] );

  const counts = useMemo( () => {
    const c: Record< View | 'done', number > = { today: 0, tomorrow: 0, week: 0, later: 0, nodate: 0, done: 0, all: ours.length };
    for ( const one of ours ) c[ viewOf( one ) ] += 1;
    return c;
  }, [ ours ] );

  const keepers = useMemo( () => [ ...new Set( ours.map( keeperOf ) ) ].sort(), [ ours, names ] ); // eslint-disable-line react-hooks/exhaustive-deps

  const rows = useMemo( () => {
    const needle = search.trim().toLowerCase();
    return ours
      .filter( ( one ) => 'all' === view || viewOf( one ) === view )
      .filter( ( one ) => ! keeper || keeperOf( one ) === keeper )
      .filter( ( one ) => ! needle || `${ one.item.id } ${ one.item.title } ${ one.site.client_name }`.toLowerCase().includes( needle ) )
      .sort( ( a, b ) => Number( isUrgent( b ) ) - Number( isUrgent( a ) ) || ( a.due ?? 9999 ) - ( b.due ?? 9999 ) || priorityRank( a.item.priority ) - priorityRank( b.item.priority ) );
  }, [ ours, view, keeper, search, names ] ); // eslint-disable-line react-hooks/exhaustive-deps

  const views: SavedView[] = [
    { id: 'today', label: 'Today', count: counts.today },
    { id: 'tomorrow', label: 'Tomorrow', count: counts.tomorrow },
    { id: 'week', label: 'Next seven days', count: counts.week },
    { id: 'later', label: 'Further out', count: counts.later },
    { id: 'nodate', label: 'No date', count: counts.nodate },
    { id: 'all', label: 'Everything with the agent', count: counts.all },
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
            <button type="button" className="bwx-row-open" data-testid="bwx-agents-open" onClick={ () => setOpened( r.item.id ) }>
              { r.item.title }
            </button>
            { 'blocked' === r.item.stage && <Tag tone="danger">Blocked</Tag> }
          </span>
        </span>
      ),
    },
    { key: 'client', label: 'Client', width: 140, sortBy: ( r ) => r.site.client_name, render: ( r ) => r.site.client_name || r.site.name },
    { key: 'stage', label: 'Stage', width: 170, sortBy: ( r ) => r.item.stage_label, render: ( r ) => <StageChip stage={ r.item.stage } dense /> },
    { key: 'agent', label: 'Who', width: 90, render: () => <Tag tone="neutral">Agent</Tag> },
    { key: 'keeper', label: 'Looked after by', width: 150, sortBy: keeperOf, render: keeperOf },
    {
      key: 'priority',
      label: 'Priority',
      width: 100,
      sortBy: ( r ) => priorityRank( r.item.priority ),
      render: ( r ) => ( r.item.priority ? <Tag tone={ PRIORITY_TONE[ r.item.priority ] ?? 'neutral' }>{ r.item.priority }</Tag> : '—' ),
    },
    {
      key: 'due',
      label: 'Due',
      mono: true,
      align: 'right',
      width: 90,
      sortBy: ( r ) => r.due ?? 9999,
      render: ( r ) => (
        <span className="bwx-mytasks-due" data-due={ null === r.due ? 'none' : r.due < -1 ? 'late' : r.due < 0 ? 'yesterday' : 0 === r.due ? 'today' : 'ahead' }>
          { dueText( r ) }
        </span>
      ),
    },
  ];

  const EMPTY: Record< View, string > = {
    today: 'Nothing for the agent today',
    tomorrow: 'Nothing for the agent tomorrow',
    week: 'Nothing due in the next seven days',
    later: 'Nothing further out',
    nodate: 'Nothing without a date',
    all: 'The agent has no work right now',
  };

  return (
    <>
      { 'loading' === state && <Screen state="loading" detail="Reading the agent's work, on every site." /> }

      { 'denied' === state && <Screen state="denied" testId="bwx-agents-state" detail="You are signed in, but not on any client whose work you may read." /> }

      { 'error' === state && <Screen state="error" testId="bwx-agents-state" detail={ notice } /> }

      { 'ready' === state && (
        <div className="bwx-list bwx-mytasks" data-testid="bwx-agents">
          <DataView
            title="The agent's work"
            titleRight={ <span className="bwx-mono">{ counts.all } in all</span> }
            views={ views }
            activeView={ view }
            onViewChange={ ( id ) => setView( id as View ) }
            search={ search }
            onSearch={ setSearch }
            searchPlaceholder="Search the agent's work"
            filters={ [ { id: 'keeper', label: 'Looked after by', value: keeper, options: [ 'Anyone', ...keepers ] } ] }
            onFilter={ ( _id, value ) => setKeeper( null === value || 'Anyone' === value ? null : value ) }
            onClearFilters={ () => setKeeper( null ) }
            columns={ columns }
            rows={ rows }
            sortable
            empty={ <EmptyState icon={ Bot } dense title={ ALL_SITES === siteId ? EMPTY[ view ] : `Nothing for ${ label() } here.` } /> }
            footer={ `${ rows.length } of ${ counts.all }` }
            testId="bwx-agents-table"
          />
        </div>
      ) }

      { '' !== opened && <ItemPanel itemId={ opened } stages={ stages } onClose={ () => setOpened( '' ) } onChanged={ () => void load() } /> }
    </>
  );
}
