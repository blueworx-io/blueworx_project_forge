import { useEffect, useState } from 'react';
import { ArrowLeft } from 'lucide-react';
import type { ClientRecord, ClientSiteRecord } from '../types';
import { api, isDenied } from '../api';
import { useLiveReload } from '../live';
import { Button, Panel, Tag } from '../kit';
import { contactLabel } from './ClientsScreen';
import { Screen } from './States';

/**
 * One client's page (#561): who they are, who looks after them, their sites,
 * and the projects under them. The projects fill in with the project pages;
 * until then the section says so.
 */
export function ClientProfileScreen( { clientId, onBack }: { clientId: string; onBack: () => void } ) {
  const [ client, setClient ] = useState< ClientRecord | null >( null );
  const [ sites, setSites ] = useState< ClientSiteRecord[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'missing' | 'denied' | 'error' >( 'loading' );

  async function load() {
    try {
      // The list carries who looks after each client; the single read does not.
      const [ all, theirs ] = await Promise.all( [
        api< { ok: true; clients: ClientRecord[] } >( '/clients?status=all' ),
        api< { ok: true; sites: ClientSiteRecord[] } >( `/clients/${ clientId }/sites?status=all` ),
      ] );
      const found = all.clients.find( ( one ) => one.id === clientId ) ?? null;

      setClient( found );
      setSites( theirs.sites );
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
                  <li key={ site.id }>{ site.name }{ 'active' !== site.status && ' (deactivated)' }</li>
                ) ) }
              </ul>
            ) }
          </Panel>

          <Panel title="Projects">
            <div data-testid="bwx-client-projects">
              <p className="bwx-hint">No projects yet.</p>
            </div>
          </Panel>
        </>
      ) }
    </div>
  );
}
