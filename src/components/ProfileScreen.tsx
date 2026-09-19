import { useEffect, useState } from 'react';
import { api, ApiError, isDenied, messageFor } from '../api';
import { Button, Field, Panel, Stat, TextInput } from '../kit';
import { AvailabilityScreen } from './AvailabilityScreen';
import { failed, NOTHING_SAID, Notice, ok, Screen, warn } from './States';
import type { Said } from './States';

/**
 * The signed-in person's own page (Luke, 2026-09-17): their details, their
 * Slack, their working week and their time off, and a way out. Reached from
 * the pill in the top-right corner, not from the rail.
 *
 * The details stay WordPress's — the card links to the WordPress profile
 * rather than copying its form. Hours and time off are the availability
 * screen with the person fixed to whoever is signed in, so what they set is
 * exactly what the administrator sees on Availability.
 */

interface Me {
  person: { id: string; display_name: string };
  account: { name: string; email: string; login: string };
  urls: { profile: string; logout: string };
  slack: {
    connected: boolean;
    prefs: Record< string, boolean >;
    last_ok_at: number;
    last_error: string;
    morning_time: string;
    labels: Record< string, string >;
  };
  warning?: string;
}

/** What a form shows when the server refuses by field, or otherwise. */
function refusal( error: unknown, fallback: string ): string {
  const fields = error instanceof ApiError ? ( error.data.fields as Record< string, string > | undefined ) : undefined;

  return fields ? Object.values( fields ).join( ' ' ) : messageFor( error, fallback );
}

export function ProfileScreen() {
  const [ me, setMe ] = useState< Me | null >( null );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'denied' | 'error' >( 'loading' );
  const [ notice, setNotice ] = useState< Said >( NOTHING_SAID );
  const [ url, setUrl ] = useState( '' );
  const [ prefs, setPrefs ] = useState< Record< string, boolean > >( {} );
  const [ busy, setBusy ] = useState( false );

  /** An answer from the server is the whole picture, so it is shown rather than re-read. */
  function landed( fresh: Me ) {
    setMe( fresh );
    setPrefs( fresh.slack.prefs );
    setUrl( '' );
  }

  async function load() {
    try {
      landed( await api< Me >( '/me' ) );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( failed( messageFor( error, 'Your profile could not be read.' ) ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    // Read once when the screen opens; load() is rebuilt every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  async function saveSlack() {
    setBusy( true );
    setNotice( NOTHING_SAID );

    try {
      const fresh = await api< Me >( '/me/slack', { method: 'POST', body: { url, prefs } } );

      landed( fresh );
      setNotice( '' !== ( fresh.warning ?? '' ) ? warn( fresh.warning ?? '' ) : ok( fresh.slack.connected ? 'Saved. Slack is connected.' : 'Saved.' ) );
    } catch ( error ) {
      setNotice( failed( refusal( error, 'That could not be saved.' ) ) );
    } finally {
      setBusy( false );
    }
  }

  async function disconnect() {
    if ( ! window.confirm( 'Forget your webhook and stop Slack messages?' ) ) {
      return;
    }

    setBusy( true );
    setNotice( NOTHING_SAID );

    try {
      landed( await api< Me >( '/me/slack', { method: 'DELETE' } ) );
      setNotice( ok( 'Slack is disconnected.' ) );
    } catch ( error ) {
      setNotice( failed( messageFor( error, 'That could not be done.' ) ) );
    } finally {
      setBusy( false );
    }
  }

  if ( 'loading' === state ) {
    return <Screen state="loading" testId="bwx-profile-state" />;
  }

  if ( 'denied' === state ) {
    return <Screen state="denied" testId="bwx-profile-state" detail="Your account is not a person in Forge yet. Ask an administrator to add you on People." />;
  }

  if ( 'error' === state || ! me ) {
    return <Screen state="error" testId="bwx-profile-state" detail={ notice.text } />;
  }

  const status = me.slack.connected
    ? '' !== me.slack.last_error
      ? `Connected, but the last message failed: ${ me.slack.last_error }`
      : 'Connected.'
    : 'Not connected.';

  return (
    <div className="bwx-profile" data-testid="bwx-profile-screen">
      <Notice said={ notice } testId="bwx-profile-notice" />

      <Panel
        title="Your details"
        right={
          <div className="bwx-moves">
            <a className="fk-btn" data-variant="secondary" data-size="sm" href={ me.urls.profile } data-testid="bwx-profile-edit">
              Edit your details
            </a>
            <a className="fk-btn" data-variant="ghost" data-size="sm" href={ me.urls.logout } data-testid="bwx-profile-logout">
              Log out
            </a>
          </div>
        }
      >
        <div className="bwx-profile-facts">
          <Stat label="Name" value={ <span data-testid="bwx-me-name">{ me.person.display_name }</span> } />
          <Stat label="Email" value={ me.account.email || '—' } />
          <Stat label="Signs in as" value={ me.account.login } />
        </div>
        <p className="bwx-hint">Your name, email and password are your WordPress account&apos;s. Edit your details opens it.</p>
      </Panel>

      <Panel
        title="Slack"
        right={
          me.slack.connected ? (
            <Button variant="ghost" size="sm" data-testid="bwx-profile-slack-disconnect" disabled={ busy } onClick={ () => void disconnect() }>
              Disconnect
            </Button>
          ) : undefined
        }
      >
        <p className="bwx-hint" data-testid="bwx-profile-slack-status" data-connected={ me.slack.connected ? 'yes' : 'no' }>
          { status }
        </p>

        <Field label="Webhook URL" help="Create an incoming webhook in Slack for the channel or DM you want Forge to post to, and paste it here. Forge keeps it sealed and never shows it again; saving sends a test message.">
          { ( id ) => (
            <TextInput
              id={ id }
              type="url"
              placeholder="https://hooks.slack.com/services/…"
              autoComplete="off"
              data-testid="bwx-profile-slack-url"
              value={ url }
              onChange={ ( event ) => setUrl( event.target.value ) }
            />
          ) }
        </Field>

        <fieldset className="bwx-profile-prefs" data-testid="bwx-profile-slack-prefs">
          <legend>Tell me about</legend>
          { Object.entries( me.slack.labels ).map( ( [ key, label ] ) => (
            <label key={ key } className="bwx-profile-pref">
              <input
                type="checkbox"
                data-testid={ `bwx-profile-slack-pref-${ key }` }
                checked={ prefs[ key ] ?? true }
                onChange={ ( event ) => setPrefs( { ...prefs, [ key ]: event.target.checked } ) }
              />
              <span>
                { label }
                { 'morning' === key && <span className="bwx-mono"> ({ me.slack.morning_time })</span> }
              </span>
            </label>
          ) ) }
        </fieldset>

        <div className="bwx-moves bwx-form-foot">
          <Button data-testid="bwx-profile-slack-save" disabled={ busy } onClick={ () => void saveSlack() }>
            Save Slack
          </Button>
        </div>
      </Panel>

      <AvailabilityScreen person={ me.person.id } fixed />
    </div>
  );
}
