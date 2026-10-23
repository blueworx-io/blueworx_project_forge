import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { api, forgeData, messageFor } from '../api';
import { Button, Field, Panel, TextInput } from '../kit';
import { RULES } from '../rules';

interface UpdateStatus {
  installed: string;
  latest: string;
  state: string;
  message: string;
  update_available: boolean;
}

/**
 * #603. Checks for and installs a newer Forge, for an administrator, without
 * going into WordPress admin. The install is WordPress's own plugin updater.
 */
function Updates() {
  const [ status, setStatus ] = useState< UpdateStatus | null >( null );
  const [ busy, setBusy ] = useState< '' | 'check' | 'install' >( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ updated, setUpdated ] = useState( false );

  useEffect( () => {
    void api< UpdateStatus >( '/updates', { cache: 'fresh' } )
      .then( setStatus )
      .catch( ( error ) => setNotice( messageFor( error, 'Updates could not be checked.' ) ) );
  }, [] );

  async function run( what: 'check' | 'install' ) {
    if ( 'install' === what && ! window.confirm( `Install Forge ${ status?.latest }? The page reloads once it is done.` ) ) {
      return;
    }

    setBusy( what );
    setNotice( '' );

    try {
      const answer = await api< UpdateStatus >( `/updates/${ what }`, { method: 'POST' } );

      setStatus( answer );

      if ( 'install' === what ) {
        setUpdated( true );
        setNotice( `Forge ${ answer.installed } is installed.` );
      }
    } catch ( error ) {
      setNotice( messageFor( error, 'install' === what ? 'The update did not install.' : 'Updates could not be checked.' ) );
    } finally {
      setBusy( '' );
    }
  }

  return (
    <Panel title="Updates">
      <div className="bwx-settings-updates" data-testid="bwx-updates">
        { status && (
          <p data-testid="bwx-updates-summary">
            { `Forge ${ status.installed } is installed. ` }
            { '' === status.latest ? status.message : status.update_available ? `Forge ${ status.latest } is available.` : 'This is the latest release.' }
          </p>
        ) }
        { '' !== notice && (
          <p className="bwx-notice" role="status" data-testid="bwx-updates-notice">
            { notice }
          </p>
        ) }
        <div className="bwx-moves">
          { updated ? (
            <Button size="sm" data-testid="bwx-updates-reload" onClick={ () => window.location.reload() }>
              Reload to use it
            </Button>
          ) : (
            <>
              { status?.update_available && (
                <Button size="sm" data-testid="bwx-updates-install" disabled={ '' !== busy } onClick={ () => void run( 'install' ) }>
                  { 'install' === busy ? 'Installing…' : `Install ${ status.latest }` }
                </Button>
              ) }
              <Button size="sm" variant="secondary" data-testid="bwx-updates-check" disabled={ '' !== busy } onClick={ () => void run( 'check' ) }>
                { 'check' === busy ? 'Checking…' : 'Check for updates' }
              </Button>
            </>
          ) }
        </div>
      </div>
    </Panel>
  );
}

interface GoogleSetup {
  configured: boolean;
  client_id: string;
  secret_hint: string;
  redirect_uri: string;
  people: Array< { user_id: string; display_name: string; email: string; synced_at: number; last_error: string } >;
}

/**
 * #606. The studio's Google OAuth client, entered once, and who has connected
 * their calendar. The secret is never shown back beyond its last characters.
 */
function GoogleCalendar() {
  const [ setup, setSetup ] = useState< GoogleSetup | null >( null );
  const [ clientId, setClientId ] = useState( '' );
  const [ secret, setSecret ] = useState( '' );
  const [ busy, setBusy ] = useState( false );
  const [ notice, setNotice ] = useState( '' );

  function landed( fresh: GoogleSetup ) {
    setSetup( fresh );
    setClientId( fresh.client_id );
    setSecret( '' );
  }

  useEffect( () => {
    void api< GoogleSetup >( '/google/settings', { cache: 'fresh' } )
      .then( landed )
      .catch( ( error ) => setNotice( messageFor( error, 'Google Calendar settings could not be read.' ) ) );
  }, [] );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      landed( await api< GoogleSetup >( '/google/settings', { method: 'POST', body: { client_id: clientId, client_secret: secret } } ) );
      setNotice( 'Saved. Staff can now connect their calendars from their own page.' );
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be saved.' ) );
    } finally {
      setBusy( false );
    }
  }

  async function cut( person: GoogleSetup[ 'people' ][ number ] ) {
    if ( ! window.confirm( `Disconnect ${ person.display_name }'s Google Calendar? Their meetings nobody has settled yet are taken out of Forge.` ) ) {
      return;
    }

    setBusy( true );

    try {
      landed( await api< GoogleSetup >( `/google/connections/${ person.user_id }`, { method: 'DELETE' } ) );
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be done.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Panel title="Google Calendar">
      <div className="bwx-settings-google" data-testid="bwx-google-settings">
        <p className="bwx-hint">
          The studio&apos;s Google Cloud OAuth client. Add this exact address to the client&apos;s authorised redirect URIs:
        </p>
        <p className="fk-mono" data-testid="bwx-google-redirect">{ setup?.redirect_uri ?? '' }</p>

        <Field label="Client id">
          { ( id ) => <TextInput id={ id } data-testid="bwx-google-client-id" autoComplete="off" value={ clientId } onChange={ ( event ) => setClientId( event.target.value ) } /> }
        </Field>
        <Field label="Client secret" help={ setup?.secret_hint ? `Saved (${ setup.secret_hint }). Leave empty to keep it.` : 'Kept sealed, and never shown again.' }>
          { ( id ) => <TextInput id={ id } type="password" data-testid="bwx-google-secret" autoComplete="off" value={ secret } onChange={ ( event ) => setSecret( event.target.value ) } /> }
        </Field>

        { '' !== notice && (
          <p className="bwx-notice" role="status" data-testid="bwx-google-settings-notice">
            { notice }
          </p>
        ) }

        <div className="bwx-moves bwx-form-foot">
          <Button size="sm" data-testid="bwx-google-save" disabled={ busy } onClick={ () => void save() }>
            Save Google client
          </Button>
        </div>

        { setup && 0 < setup.people.length && (
          <ul className="bwx-rules" data-testid="bwx-google-people">
            { setup.people.map( ( person ) => (
              <li key={ person.user_id } className="bwx-moves">
                <span>
                  { `${ person.display_name } · ${ person.email }` }
                  { '' !== person.last_error && ` · ${ person.last_error }` }
                </span>
                <Button variant="ghost" size="sm" disabled={ busy } onClick={ () => void cut( person ) }>
                  Disconnect
                </Button>
              </li>
            ) ) }
          </ul>
        ) }
      </div>
    </Panel>
  );
}

/**
 * Every rule Forge follows, in plain English (#467): what happens, and why.
 * One topic at a time (#528): the topics are tabs, and only the chosen one's
 * rules are on the page.
 *
 * Read-only by design. The rules are written in src/rules.ts, and a change to
 * how Forge behaves changes that file in the same pull request.
 */
export function SettingsScreen( { section = '' }: { section?: string } ) {
  const known = RULES.some( ( one ) => one.id === section );
  const [ active, setActive ] = useState( known ? section : RULES[ 0 ].id );
  const tabs = useRef< Map< string, HTMLButtonElement > >( new Map() );

  const shown = RULES.find( ( one ) => one.id === active ) ?? RULES[ 0 ];

  /** Arrow keys move between the tabs, as a tab list is expected to. */
  const onKey = ( event: KeyboardEvent< HTMLButtonElement >, index: number ) => {
    const step = { ArrowRight: 1, ArrowLeft: -1, Home: -index, End: RULES.length - 1 - index }[ event.key ];

    if ( undefined === step ) {
      return;
    }

    event.preventDefault();
    const next = RULES[ ( index + step + RULES.length ) % RULES.length ];

    setActive( next.id );
    tabs.current.get( next.id )?.focus();
  };

  return (
    <div className="bwx-settings" data-testid="bwx-settings">
      { forgeData()?.canManage && <Updates /> }
      { forgeData()?.canManage && <GoogleCalendar /> }

      <p className="bwx-hint">How Forge behaves, and why. The rules cannot be changed from this page.</p>

      <div className="fk-tabs bwx-rules-tabs" role="tablist" aria-label="Rule topics" data-testid="bwx-rules-tabs">
        { RULES.map( ( one, index ) => (
          <button
            key={ one.id }
            ref={ ( element ) => {
              if ( element ) {
                tabs.current.set( one.id, element );
              } else {
                tabs.current.delete( one.id );
              }
            } }
            type="button"
            role="tab"
            id={ `rules-tab-${ one.id }` }
            className="fk-tab"
            aria-selected={ one.id === active }
            aria-controls={ `rules-${ one.id }` }
            tabIndex={ one.id === active ? 0 : -1 }
            data-testid={ `bwx-rules-tab-${ one.id }` }
            onClick={ () => setActive( one.id ) }
            onKeyDown={ ( event ) => onKey( event, index ) }
          >
            { one.title }
          </button>
        ) ) }
      </div>

      <div
        key={ shown.id }
        id={ `rules-${ shown.id }` }
        role="tabpanel"
        aria-labelledby={ `rules-tab-${ shown.id }` }
        className="bwx-rules-section"
        data-testid={ `bwx-rules-${ shown.id }` }
      >
        <Panel title={ shown.title }>
          <ul className="bwx-rules">
            { shown.rules.map( ( rule ) => (
              <li key={ rule.what }>
                <p className="bwx-rule-what">{ rule.what }</p>
                <p className="bwx-hint">{ rule.why }</p>
              </li>
            ) ) }
          </ul>
        </Panel>
      </div>
    </div>
  );
}
