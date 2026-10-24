import { useEffect, useState } from 'react';
import { ArrowLeft } from 'lucide-react';
import type { ClientRecord, ClientSiteRecord, WorkItem } from '../types';
import { api, forgeData, isDenied } from '../api';
import { useLiveReload } from '../live';
import { buildTree, summary, topLevel } from '../tree.mjs';
import { Button, Panel, Select, StageChip, Tag } from '../kit';
import { NewWork } from './NewWork';
import { Ended } from './ProjectScreen';
import { contactLabel } from './ClientsScreen';
import { Screen } from './States';
import { CreateSite } from './CreateSite';

/** 3 Nov 2026, from a Y-m-d date, without the timezone moving the day. */
const day = ( date: string ) => new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' } );

/**
 * One client's page (#561): who they are, who looks after them, their sites,
 * and the projects under them (#562), each opening its own page.
 */
export function ClientProfileScreen( {
  clientId,
  onBack,
  onOpenProject = () => undefined,
}: {
  clientId: string;
  onBack: () => void;
  onOpenProject?: ( projectId: string ) => void;
} ) {
  const admin = forgeData()?.canManage ?? false;
  const [ client, setClient ] = useState< ClientRecord | null >( null );
  const [ sites, setSites ] = useState< ClientSiteRecord[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'missing' | 'denied' | 'error' >( 'loading' );
  // #562. Every item on the client's sites, for the projects and their progress.
  const [ work, setWork ] = useState< WorkItem[] >( [] );
  const [ projectSite, setProjectSite ] = useState( '' );
  const [ adding, setAdding ] = useState( '' );

  async function load() {
    try {
      // The list carries who looks after each client; the single read does not.
      const [ all, theirs ] = await Promise.all( [
        api< { ok: true; clients: ClientRecord[] } >( '/clients?status=all' ),
        api< { ok: true; sites: ClientSiteRecord[] } >( `/clients/${ clientId }/sites?status=all` ),
      ] );
      const found = all.clients.find( ( one ) => one.id === clientId ) ?? null;

      const lists = await Promise.all(
        theirs.sites.map( ( site ) => api< { items: WorkItem[] } >( `/work-items?client_site_id=${ site.id }` ).then( ( answer ) => answer.items ).catch( () => [] as WorkItem[] ) )
      );

      setClient( found );
      setSites( theirs.sites );
      setWork( lists.flat().filter( ( one ) => ! one.archived ) );
      setState( found ? 'ready' : 'missing' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ clientId ] );

  useLiveReload( () => load() );

  const active = sites.filter( ( site ) => 'active' === site.status );
  const chosenSite = active.some( ( site ) => site.id === projectSite ) ? projectSite : active[ 0 ]?.id ?? '';
  const projects = topLevel( work, 'project' );

  return (
    <div className="bwx-client-profile" data-testid="bwx-client-profile">
      <div className="bwx-moves">
        <Button size="sm" variant="ghost" data-testid="bwx-client-back" onClick={ onBack }>
          <ArrowLeft size={ 14 } aria-hidden="true" /> All clients
        </Button>
      </div>

      { 'loading' === state && <Screen state="loading" testId="bwx-client-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-client-state" /> }
      { 'missing' === state && <Screen state="empty" testId="bwx-client-state" title="No such client" detail="They may have been deleted." /> }
      { 'error' === state && <Screen state="error" testId="bwx-client-state" detail="That client could not be read." /> }

      { 'ready' === state && client && (
        <>
          <Panel title={ client.display_name } right={ 'active' === client.status ? <Tag tone="ok">Active</Tag> : <Tag tone="neutral">Deactivated</Tag> }>
            <p className="bwx-hint" data-testid="bwx-client-contact">{ `Contact: ${ contactLabel( client.contact ) }` }</p>
          </Panel>

          <Panel title="Sites" flush>
            { 0 === sites.length ? (
              <p className="bwx-hint bwx-panel-lead" data-testid="bwx-client-sites-empty">No sites yet.</p>
            ) : (
              <ul className="bwx-hint bwx-panel-lead" data-testid="bwx-client-sites">
                { sites.map( ( site ) => (
                  <li key={ site.id }>
                    { site.name }{ 'active' !== site.status && ' (deactivated)' }
                    { /* #621. An administrator can create its WordPress site on the network. */ }
                    { admin && 'active' === site.status && <CreateSite siteId={ site.id } siteName={ site.name } /> }
                  </li>
                ) ) }
              </ul>
            ) }
          </Panel>

          <Panel
            title="Projects"
            right={
              0 < active.length && (
                <span className="bwx-moves">
                  { 1 < active.length && (
                    <Select
                      aria-label="Site for the new project"
                      data-testid="bwx-client-project-site"
                      value={ chosenSite }
                      options={ active.map( ( site ) => ( { value: site.id, label: site.name } ) ) }
                      onChange={ ( event ) => setProjectSite( event.target.value ) }
                    />
                  ) }
                  <Button size="sm" data-testid="bwx-client-add-project" onClick={ () => setAdding( chosenSite ) }>
                    + Add project
                  </Button>
                </span>
              )
            }
          >
            <div data-testid="bwx-client-projects">
              { 0 === projects.length ? (
                <p className="bwx-hint">No projects yet.</p>
              ) : (
                <ul className="bwx-client-project-list">
                  { projects.map( ( project ) => {
                    const node = buildTree( work, project.id );
                    const { total, finished, start, due, ongoing } = summary( node );
                    const where = 1 < sites.length ? sites.find( ( site ) => site.id === project.client_site_id )?.name ?? '' : '';

                    return (
                      <li key={ project.id }>
                        <button type="button" className="bwx-client-project bwx-project-open" data-testid="bwx-client-project" data-item={ project.id } onClick={ () => onOpenProject( project.id ) }>
                          <span style={ { flex: '1 1 12rem' } }>{ project.title }</span>
                          <span className="bwx-progress-figures">
                            { [ where, ongoing ? 'Ongoing' : start ? `${ day( start ) } – ${ day( due ) }` : `Until ${ day( due ) }`, 0 === total ? 'No work yet' : `${ finished } of ${ total } done` ].filter( Boolean ).join( ' · ' ) }
                          </span>
                          <StageChip stage={ project.stage } dense />
                          <Ended item={ project } />
                        </button>
                      </li>
                    );
                  } ) }
                </ul>
              ) }
            </div>
          </Panel>
        </>
      ) }

      { '' !== adding && (
        <NewWork clientSiteId={ adding } preset={ { level: 'project', workType: 'feature' } } onClose={ () => setAdding( '' ) } onCreated={ () => void load() } />
      ) }
    </div>
  );
}
