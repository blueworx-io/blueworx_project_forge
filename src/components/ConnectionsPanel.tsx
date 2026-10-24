import { useEffect, useState } from 'react';
import { api, messageFor } from '../api';
import { Button, Field, Panel, Tag, TextInput } from '../kit';

export interface Service {
  id: string;
  name: string;
  connected?: boolean;
  email?: string;
  hint?: string;
  connected_at?: number;
  last_checked?: number;
  link?: string;
}

interface Connections {
  services: Service[];
}

/** "3 minutes ago", "Never" — when a service last checked in. */
export function since( at: number | undefined ): string {
  if ( ! at ) return 'Never';

  const seconds = Math.max( 0, Math.round( Date.now() / 1000 - at ) );

  if ( seconds < 60 ) return 'Just now';
  if ( seconds < 3600 ) return `${ Math.round( seconds / 60 ) } min ago`;
  if ( seconds < 86400 ) return `${ Math.round( seconds / 3600 ) } h ago`;

  return new Date( at * 1000 ).toLocaleDateString( undefined, { day: 'numeric', month: 'short' } );
}

/** Cloudways: the account email and an API key, checked with Cloudways before it is kept. */
function CloudwaysForm( { service, onDone, onCancel }: { service: Service; onDone: ( next: Connections ) => void; onCancel: () => void } ) {
  const [ email, setEmail ] = useState( service.email ?? '' );
  const [ key, setKey ] = useState( '' );
  const [ busy, setBusy ] = useState( false );
  const [ notice, setNotice ] = useState( '' );

  async function save() {
    setBusy( true );
    setNotice( '' );
    try {
      onDone( await api< Connections >( '/connections/cloudways', { method: 'POST', body: { email, api_key: key } } ) );
    } catch ( error ) {
      setNotice( messageFor( error, 'Cloudways could not be connected.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <form
      className="bwx-connection-form"
      data-testid="bwx-cloudways-form"
      onSubmit={ ( event ) => {
        event.preventDefault();
        void save();
      } }
    >
      <Field label="Cloudways account email">
        { ( id ) => <TextInput id={ id } type="email" autoComplete="off" data-testid="bwx-cloudways-email" value={ email } onChange={ ( event ) => setEmail( event.target.value ) } /> }
      </Field>
      <Field label="API key" help="In Cloudways under Account, then API Integration. Kept sealed and never shown again.">
        { ( id ) => <TextInput id={ id } type="password" autoComplete="off" data-testid="bwx-cloudways-key" value={ key } onChange={ ( event ) => setKey( event.target.value ) } /> }
      </Field>
      { '' !== notice && (
        <p className="bwx-notice" role="alert" data-testid="bwx-cloudways-notice">
          { notice }
        </p>
      ) }
      <div className="bwx-moves">
        <Button size="sm" type="submit" disabled={ busy } data-testid="bwx-cloudways-save">
          { busy ? 'Checking with Cloudways…' : 'Connect Cloudways' }
        </Button>
        <Button size="sm" variant="ghost" disabled={ busy } onClick={ onCancel }>
          Cancel
        </Button>
      </div>
    </form>
  );
}

/**
 * #621. Every outside service Forge talks to, in one place: whether it is
 * connected and when it last checked in. Cloudways is connected from here;
 * SureCart and Slack still live on the WordPress admin Connections screen,
 * linked from their rows.
 */
export function ConnectionsPanel( { onOpenServers }: { onOpenServers?: () => void } ) {
  const [ services, setServices ] = useState< Service[] | null >( null );
  const [ editing, setEditing ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );

  useEffect( () => {
    void api< Connections >( '/connections', { cache: 'fresh' } )
      .then( ( answer ) => setServices( answer.services ) )
      .catch( ( error ) => setNotice( messageFor( error, 'Connections could not be read.' ) ) );
  }, [] );

  const landed = ( next: Connections ) => {
    setServices( next.services );
    setEditing( '' );
  };

  async function disconnect( service: Service ) {
    if ( ! window.confirm( `Disconnect ${ service.name }? Forge forgets the key and what it last saw.` ) ) return;

    try {
      landed( await api< Connections >( `/connections/${ service.id }`, { method: 'DELETE' } ) );
    } catch ( error ) {
      setNotice( messageFor( error, `${ service.name } could not be disconnected.` ) );
    }
  }

  return (
    <Panel title="Connections">
      <ul className="bwx-connections" data-testid="bwx-connections">
        { ( services ?? [] ).map( ( service ) => (
          <li key={ service.id } className="bwx-connection" data-testid={ `bwx-connection-${ service.id }` } data-connected={ service.connected ? 'yes' : 'no' }>
            <div className="bwx-connection-head">
              <strong className="bwx-connection-name">{ service.name }</strong>
              { undefined !== service.link ? (
                <a className="fk-btn" data-variant="ghost" data-size="sm" href={ service.link } data-testid={ `bwx-connection-${ service.id }-link` }>
                  Set up in WordPress admin
                </a>
              ) : (
                <>
                  <Tag tone={ service.connected ? 'ok' : 'neutral' }>{ service.connected ? 'Connected' : 'Not connected' }</Tag>
                  <span className="bwx-hint" data-testid={ `bwx-connection-${ service.id }-checked` }>
                    { `Last checked in: ${ since( service.last_checked ) }` }
                  </span>
                  <span className="bwx-connection-moves">
                    { service.connected && 'cloudways' === service.id && onOpenServers && (
                      <Button size="sm" variant="ghost" data-testid="bwx-open-servers" onClick={ onOpenServers }>
                        See servers
                      </Button>
                    ) }
                    { service.connected ? (
                      <Button size="sm" variant="ghost" data-testid={ `bwx-connection-${ service.id }-disconnect` } onClick={ () => void disconnect( service ) }>
                        Disconnect
                      </Button>
                    ) : (
                      editing !== service.id && (
                        <Button size="sm" variant="secondary" data-testid={ `bwx-connection-${ service.id }-connect` } onClick={ () => setEditing( service.id ) }>
                          Connect
                        </Button>
                      )
                    ) }
                  </span>
                </>
              ) }
            </div>
            { service.connected && '' !== ( service.email ?? '' ) && (
              <p className="bwx-hint">{ `${ service.email }, key ${ service.hint ?? '' }` }</p>
            ) }
            { undefined !== service.link && <p className="bwx-hint">Stores and the Slack app are still set up on the WordPress admin Connections screen.</p> }
            { editing === service.id && 'cloudways' === service.id && <CloudwaysForm service={ service } onDone={ landed } onCancel={ () => setEditing( '' ) } /> }
          </li>
        ) ) }
      </ul>
      { '' !== notice && (
        <p className="bwx-notice" role="status" data-testid="bwx-connections-notice">
          { notice }
        </p>
      ) }
    </Panel>
  );
}
