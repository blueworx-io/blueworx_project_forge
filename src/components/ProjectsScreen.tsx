import { useEffect, useState } from 'react';
import { FolderKanban } from 'lucide-react';
import type { WorkItem } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useClientChoice } from '../ClientChoice';
import { useLiveReload } from '../live';
import { ALL_SITES } from '../sites';
import { summary } from '../tree.mjs';
import { Button, DataView, EmptyState, Panel, StageChip, Tag } from '../kit';
import type { Column } from '../kit';
import { DeleteArchived, Progress, setArchived } from './ProjectScreen';
import { Screen } from './States';

/** 3 Nov 2026, from a Y-m-d date, without the timezone moving the day. */
const day = ( date: string ) => new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' } );

/** The numbers a project row shows, from the item alone: its releases carry them already. */
const figures = ( project: WorkItem ) => summary( { item: project, parts: [], attached: [] } );

/** Released, or closed without being released (cancelled and the like); a deferred project is still to come. */
const finished = ( one: WorkItem ) => 'released' === one.stage || ( '' !== one.terminal_outcome && 'deferred' !== one.terminal_outcome );

/** Every project across the studio's clients (#563): where each is, and how far along. */
export function ProjectsScreen( { onOpen }: { onOpen: ( projectId: string ) => void } ) {
  const { siteId, label } = useClientChoice();
  const [ projects, setProjects ] = useState< WorkItem[] >( [] );
  // How many releases sit directly under each project, by its id: a bug or task
  // under a project is work in it, not a release.
  const [ releases, setReleases ] = useState< Map< string, number > >( () => new Map() );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ view, setView ] = useState< 'active' | 'completed' | 'archived' >( 'active' );
  // #590. Archived projects and releases, found here to restore or delete.
  const [ shelved, setShelved ] = useState< WorkItem[] >( [] );
  const [ deleting, setDeleting ] = useState< WorkItem | null >( null );
  const [ notice, setNotice ] = useState( '' );
  const completed = 'completed' === view;

  async function load() {
    try {
      const answer = await api< { items: WorkItem[] } >( '/work-items-all?level[]=project&level[]=milestone&include_archived=1' );
      const kept = answer.items.filter( ( one ) => ! one.archived );
      const counts = new Map< string, number >();

      for ( const one of kept ) {
        if ( 'milestone' === one.level && '' !== one.parent_id ) counts.set( one.parent_id, ( counts.get( one.parent_id ) ?? 0 ) + 1 );
      }

      setShelved( answer.items.filter( ( one ) => one.archived ) );
      setProjects( kept.filter( ( one ) => 'project' === one.level ) );
      setReleases( counts );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [] );

  useLiveReload( () => load() );

  const ofSite = projects.filter( ( one ) => ALL_SITES === siteId || one.client_site_id === siteId );
  const shown = ofSite.filter( ( one ) => completed === finished( one ) );
  const archivedShown = shelved.filter( ( one ) => ALL_SITES === siteId || one.client_site_id === siteId );

  async function bringBack( one: WorkItem ) {
    setNotice( '' );

    try {
      await setArchived( one, false );
      await load();
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be restored.' ) );
    }
  }

  const columns: Column< WorkItem >[] = [
    {
      key: 'title',
      label: 'Project',
      wrap: true,
      width: 320,
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
    { key: 'releases', label: 'Releases', width: 100, align: 'right', mono: true, sortBy: ( project ) => releases.get( project.id ) ?? 0, render: ( project ) => releases.get( project.id ) ?? 0 },
    {
      key: 'progress',
      label: 'Progress',
      width: 220,
      sortBy: ( project ) => project.progress ?? 0,
      render: ( project ) => {
        return <Progress node={ { item: project, parts: [], attached: [] } } />;
      },
    },
  ];

  const archivedColumns: Column< WorkItem >[] = [
    {
      key: 'title',
      label: 'Name',
      wrap: true,
      render: ( one ) => (
        <>
          <span data-testid="bwx-projects-archived-title">{ one.title }</span>
          { ' ' }
          <Tag tone="neutral">{ 'milestone' === one.level ? 'Release' : 'Project' }</Tag>
        </>
      ),
    },
    { key: 'client', label: 'Client', wrap: true, sortBy: ( one ) => label( one.client_site_id ), render: ( one ) => label( one.client_site_id ) },
    {
      key: 'actions',
      label: '',
      width: 200,
      sortable: false,
      align: 'right',
      render: ( one ) => (
        <span className="bwx-moves" onClick={ ( event ) => event.stopPropagation() } onKeyDown={ ( event ) => event.stopPropagation() }>
          <Button size="sm" variant="secondary" data-testid="bwx-projects-restore" onClick={ () => void bringBack( one ) }>
            Restore
          </Button>
          <Button size="sm" variant="danger" data-testid="bwx-projects-delete" onClick={ () => setDeleting( one ) }>
            Delete
          </Button>
        </span>
      ),
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
              <Button size="sm" variant={ 'active' === view ? 'secondary' : 'ghost' } data-testid="bwx-projects-active" aria-pressed={ 'active' === view } onClick={ () => setView( 'active' ) }>
                Active
              </Button>
              <Button size="sm" variant={ completed ? 'secondary' : 'ghost' } data-testid="bwx-projects-completed" aria-pressed={ completed } onClick={ () => setView( 'completed' ) }>
                Completed
              </Button>
              <Button size="sm" variant={ 'archived' === view ? 'secondary' : 'ghost' } data-testid="bwx-projects-archived" aria-pressed={ 'archived' === view } onClick={ () => setView( 'archived' ) }>
                Archived
              </Button>
            </div>
          }
        >
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-projects-notice" role="status">
              { notice }
            </p>
          ) }
          { 'archived' === view ? (
            <DataView< WorkItem >
              bare
              columns={ archivedColumns }
              rows={ archivedShown }
              fixed
              onRowClick={ ( one ) => {
                const project = 'project' === one.level ? one.id : one.parent_id;

                if ( '' !== project ) onOpen( project );
              } }
              empty={ <EmptyState icon={ FolderKanban } dense title="Nothing archived" body="Archive a project or release from its own page and it appears here." /> }
              footer={ `${ archivedShown.length } archived` }
              testId="bwx-projects-archived-list"
            />
          ) : (
          <DataView< WorkItem >
            bare
            columns={ columns }
            rows={ shown }
            fixed
            onRowClick={ ( project ) => onOpen( project.id ) }
            empty={ <EmptyState icon={ FolderKanban } dense title={ completed ? 'No completed projects' : 'No projects yet' } body={ completed ? 'A project appears here once it is released or closed.' : "Add one from a client's page." } /> }
            footer={ `${ shown.length } ${ 1 === shown.length ? 'project' : 'projects' }` }
            testId="bwx-projects-list"
          />
          ) }
        </Panel>
      ) }

      { null !== deleting && (
        <DeleteArchived
          item={ deleting }
          onClose={ () => setDeleting( null ) }
          onDeleted={ () => {
            setDeleting( null );
            void load();
          } }
        />
      ) }
    </div>
  );
}
