import { useEffect, useState } from 'react';
import { FolderKanban } from 'lucide-react';
import type { WorkItem } from '../types';
import { api, isDenied } from '../api';
import { useClientChoice } from '../ClientChoice';
import { useLiveReload } from '../live';
import { ALL_SITES } from '../sites';
import { summary } from '../tree.mjs';
import { Button, DataView, EmptyState, Panel, StageChip } from '../kit';
import type { Column } from '../kit';
import { Screen } from './States';

/** 3 Nov 2026, from a Y-m-d date, without the timezone moving the day. */
const day = ( date: string ) => new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' } );

/** The numbers a project row shows, from the item alone: its releases carry them already. */
const figures = ( project: WorkItem ) => summary( { item: project, parts: [], attached: [] } );

/** Every project across the studio's clients (#563): where each is, and how far along. */
export function ProjectsScreen( { onOpen }: { onOpen: ( projectId: string ) => void } ) {
  const { siteId, label } = useClientChoice();
  const [ projects, setProjects ] = useState< WorkItem[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ completed, setCompleted ] = useState( false );

  async function load() {
    try {
      const answer = await api< { items: WorkItem[] } >( '/work-items-all?level[]=project' );

      setProjects( answer.items.filter( ( one ) => 'project' === one.level && ! one.archived ) );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( () => load() );

  const ofSite = projects.filter( ( one ) => ALL_SITES === siteId || one.client_site_id === siteId );
  const shown = ofSite.filter( ( one ) => completed === ( 'released' === one.stage ) );

  const columns: Column< WorkItem >[] = [
    {
      key: 'title',
      label: 'Project',
      wrap: true,
      render: ( project ) => (
        <>
          <span data-testid="bwx-projects-title">{ project.title }</span>
          { ' ' }
          <StageChip stage={ project.stage } dense />
        </>
      ),
    },
    { key: 'client', label: 'Client', wrap: true, sortBy: ( project ) => label( project.client_site_id ), render: ( project ) => label( project.client_site_id ) },
    {
      key: 'dates',
      label: 'Dates',
      width: 220,
      sortable: false,
      render: ( project ) => {
        const { start, due } = figures( project );

        if ( ! due ) {
          return 'Ongoing';
        }

        return start ? `${ day( start ) } – ${ day( due ) }` : `Until ${ day( due ) }`;
      },
    },
    { key: 'releases', label: 'Releases', width: 100, align: 'right', mono: true, sortBy: ( project ) => project.children_total ?? 0, render: ( project ) => project.children_total ?? 0 },
    {
      key: 'progress',
      label: 'Progress',
      width: 220,
      sortBy: ( project ) => project.progress ?? 0,
      render: ( project ) => {
        const { total, finished } = figures( project );

        if ( 0 === total ) {
          return <span className="bwx-hint">No work yet</span>;
        }

        return (
          <span className="bwx-projects-progress">
            <span className="fk-meter-track" role="progressbar" aria-valuemin={ 0 } aria-valuemax={ total } aria-valuenow={ finished } aria-label={ `${ finished } of ${ total } done` }>
              <span className="fk-meter-used" style={ { width: `${ Math.round( ( finished / total ) * 100 ) }%` } } />
            </span>
            <span className="bwx-progress-figures">{ `${ finished } of ${ total } done` }</span>
          </span>
        );
      },
    },
  ];

  return (
    <div className="bwx-projects" data-testid="bwx-projects">
      { 'loading' === state && <Screen state="loading" testId="bwx-projects-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-projects-state" /> }
      { 'error' === state && <Screen state="error" testId="bwx-projects-state" detail="The projects could not be read." /> }

      { 'ready' === state && (
        <Panel
          title="Projects"
          flush
          right={
            <div className="bwx-moves">
              <Button size="sm" variant={ completed ? 'ghost' : 'secondary' } data-testid="bwx-projects-active" aria-pressed={ ! completed } onClick={ () => setCompleted( false ) }>
                Active
              </Button>
              <Button size="sm" variant={ completed ? 'secondary' : 'ghost' } data-testid="bwx-projects-completed" aria-pressed={ completed } onClick={ () => setCompleted( true ) }>
                Completed
              </Button>
            </div>
          }
        >
          <DataView< WorkItem >
            bare
            columns={ columns }
            rows={ shown }
            fixed
            onRowClick={ ( project ) => onOpen( project.id ) }
            empty={ <EmptyState icon={ FolderKanban } dense title={ completed ? 'No completed projects' : 'No projects yet' } body={ completed ? 'A project appears here once it is released.' : "Add one from a client's page." } /> }
            footer={ `${ shown.length } ${ 1 === shown.length ? 'project' : 'projects' }` }
            testId="bwx-projects-list"
          />
        </Panel>
      ) }
    </div>
  );
}
