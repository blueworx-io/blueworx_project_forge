import { useEffect, useState } from 'react';
import { api, messageFor } from '../api';
import { Button, Panel, Tag } from '../kit';
import type { Tone } from '../kit';
import { Screen } from './States';
import { since } from './ConnectionsPanel';

interface App {
  label: string;
  domains: string[];
  client_site_id: string;
  site_name: string;
}

interface Server {
  id: string;
  label: string;
  state: string;
  last_state: string;
  apps: App[];
}

interface Servers {
  connected: boolean;
  checked_at: number;
  error: string;
  servers: Server[];
}

/** How a state reads: Cloudways' word, with a tone a glance can take in. */
function stateTone( state: string ): Tone {
  if ( 'running' === state ) return 'ok';
  if ( 'unreachable' === state ) return 'warn';

  return 'danger';
}

function stateWord( state: string ): string {
  if ( '' === state ) return 'Unknown';

  return state.charAt( 0 ).toUpperCase() + state.slice( 1 );
}

/**
 * #621. Every server on the studio's Cloudways account and what it is doing,
 * with the applications on each matched to their client. Cloudways is asked
 * when this page opens (unless it was asked a few minutes ago) and when Check
 * now is pressed — nothing checks in the background.
 */
export function ServersScreen() {
  const [ status, setStatus ] = useState< Servers | null >( null );
  const [ busy, setBusy ] = useState( true );
  const [ notice, setNotice ] = useState( '' );

  /** Reads the servers; `now` is the Check now button. Busy is set by whoever starts it. */
  function read( now: boolean ) {
    return api< Servers >( now ? '/servers?check=now' : '/servers', { cache: 'fresh' } )
      .then( setStatus )
      .catch( ( error ) => setNotice( messageFor( error, 'The servers could not be read.' ) ) )
      .finally( () => setBusy( false ) );
  }

  function check() {
    setBusy( true );
    setNotice( '' );
    void read( true );
  }

  // Opening the page is a check (busy starts true), unless one was made a few minutes ago.
  useEffect( () => {
    void read( false );
  }, [] );

  if ( null === status && busy ) {
    return <Screen state="loading" detail="Asking Cloudways about the servers." />;
  }

  if ( null !== status && ! status.connected ) {
    return <Screen state="empty" title="Cloudways is not connected" detail="Connect it in Settings, under Connections, to see the servers here." />;
  }

  return (
    <div className="bwx-settings bwx-servers" data-testid="bwx-servers">
      <div className="bwx-moves bwx-servers-head">
        <p className="bwx-hint" data-testid="bwx-servers-checked" aria-live="polite">
          { busy ? 'Checking…' : `Checked ${ since( status?.checked_at ).toLowerCase() }` }
        </p>
        <Button size="sm" variant="secondary" disabled={ busy } data-testid="bwx-servers-check" onClick={ check }>
          Check now
        </Button>
      </div>

      { '' !== ( status?.error ?? '' ) && (
        <p className="bwx-notice" role="alert" data-testid="bwx-servers-error">
          { `Cloudways could not be asked: ${ status?.error ?? '' } The standup still shows what Cloudways last reported.` }
        </p>
      ) }
      { '' !== notice && (
        <p className="bwx-notice" role="alert">
          { notice }
        </p>
      ) }

      { 0 === ( status?.servers.length ?? 0 ) && <Screen state="empty" title="No servers" detail="Cloudways lists no servers on this account." /> }

      { ( status?.servers ?? [] ).map( ( server ) => (
        <section key={ server.id } data-testid="bwx-server" data-state={ server.state } aria-labelledby={ `bwx-server-${ server.id }` }>
          <Panel
            title={ <span id={ `bwx-server-${ server.id }` }>{ server.label }</span> }
            right={
              <span data-testid="bwx-server-state">
                <Tag tone={ stateTone( server.state ) }>{ stateWord( server.state ) }</Tag>
                { 'unreachable' === server.state && '' !== server.last_state && (
                  <span className="bwx-hint">{ ` Last reported ${ server.last_state }` }</span>
                ) }
              </span>
            }
          >
            { 0 === server.apps.length ? (
              <p className="bwx-hint">No applications on this server.</p>
            ) : (
              <ul className="bwx-rules" data-testid="bwx-server-apps">
                { server.apps.map( ( app ) => (
                  <li key={ app.label + app.domains.join() } className="bwx-moves" data-testid="bwx-server-app">
                    <span>
                      <strong>{ app.label }</strong>
                      { 0 < app.domains.length && <span className="bwx-hint">{ ` ${ app.domains[ 0 ] }` }</span> }
                    </span>
                    { '' === app.client_site_id ? (
                      <Tag tone="neutral">Not matched to a client</Tag>
                    ) : (
                      <Tag tone="info">{ app.site_name }</Tag>
                    ) }
                  </li>
                ) ) }
              </ul>
            ) }
          </Panel>
        </section>
      ) ) }
    </div>
  );
}
