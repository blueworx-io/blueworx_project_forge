import { useEffect, useState } from 'react';
import { api, messageFor } from '../api';

/**
 * A person's own Slack connection, on My tasks.
 *
 * Paste an incoming webhook, get a test message, then choose what to hear
 * about. The webhook is sent once and never shown again; what comes back is
 * only whether it is connected and when it last worked.
 */

interface SlackState {
  connected: boolean;
  prefs: Record< string, boolean >;
  last_ok_at: number;
  last_error: string;
  morning_at: string;
}

const PREFS: Array< [ string, string ] > = [
  [ 'assigned', 'Work assigned to me, or arriving for me' ],
  [ 'ready', 'Something ready for my review or delivery' ],
  [ 'comment', 'Comments on tasks I hold a seat on' ],
  [ 'morning', 'A morning message of what is due today' ],
];

export function SlackPanel() {
  const [ state, setState ] = useState< SlackState | null >( null );
  const [ url, setUrl ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );
  const [ open, setOpen ] = useState( false );

  async function load() {
    try {
      setState( await api< SlackState >( '/me/slack' ) );
    } catch {
      // Not a Forge person, or not signed in: the panel simply is not shown.
      setState( null );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [] );

  async function connect() {
    setBusy( true );
    setNotice( '' );

    try {
      setState( await api< SlackState >( '/me/slack', { method: 'POST', body: { url } } ) );
      setUrl( '' );
      setNotice( 'Connected — a test message has been sent.' );
    } catch ( error ) {
      setNotice( messageFor( error, 'Slack could not be connected.' ) );
    } finally {
      setBusy( false );
    }
  }

  async function toggle( key: string, value: boolean ) {
    // The box moves at once; the server's answer settles it, and a refusal
    // puts it back.
    const before = state;
    setState( before ? { ...before, prefs: { ...before.prefs, [ key ]: value } } : before );

    try {
      setState( await api< SlackState >( '/me/slack', { method: 'PATCH', body: { prefs: { [ key ]: value } } } ) );
    } catch ( error ) {
      setState( before );
      setNotice( messageFor( error, 'That could not be saved.' ) );
    }
  }

  async function disconnect() {
    if ( ! window.confirm( 'Disconnect Slack? Forge forgets your webhook.' ) ) {
      return;
    }

    try {
      setState( await api< SlackState >( '/me/slack', { method: 'DELETE' } ) );
      setNotice( '' );
    } catch ( error ) {
      setNotice( messageFor( error, 'That could not be done.' ) );
    }
  }

  if ( ! state ) {
    return null;
  }

  return (
    <section className="bwx-slack" data-testid="bwx-slack" data-connected={ state.connected ? 'true' : 'false' } data-open={ open ? 'true' : 'false' }>
      <button type="button" className="bwx-slack-toggle" data-testid="bwx-slack-toggle" aria-expanded={ open } onClick={ () => setOpen( ! open ) }>
        <span className="bwx-slack-caret" aria-hidden="true">{ open ? '▾' : '▸' }</span>
        Slack
        <span className="bwx-mono bwx-slack-status">
          { state.connected ? ( state.last_error ? `connected · last message failed: ${ state.last_error }` : 'connected' ) : 'not connected' }
        </span>
      </button>

      { open && (
        <div className="bwx-slack-body">
          { '' !== notice && (
            <p className="bwx-notice" data-testid="bwx-slack-notice" role="status">
              { notice }
            </p>
          ) }

          { ! state.connected && (
            <>
              <p className="bwx-muted">
                Create an{ ' ' }
                <a href="https://api.slack.com/messaging/webhooks" target="_blank" rel="noreferrer">
                  incoming webhook
                </a>{ ' ' }
                in Slack for the channel or DM you want Forge to post to, and paste it here. Forge keeps it sealed and never shows it again.
              </p>
              <div className="bwx-field">
                <label htmlFor="bwx-slack-url">Webhook URL</label>
                <input
                  id="bwx-slack-url"
                  className="bwx-input"
                  data-testid="bwx-slack-url"
                  placeholder="https://hooks.slack.com/services/…"
                  value={ url }
                  onChange={ ( event ) => setUrl( event.target.value ) }
                />
              </div>
              <button type="button" className="bwx-button" data-testid="bwx-slack-connect" disabled={ busy || '' === url.trim() } onClick={ () => void connect() }>
                { busy ? 'Connecting…' : 'Connect and send a test' }
              </button>
            </>
          ) }

          { state.connected && (
            <>
              <ul className="bwx-slack-prefs">
                { PREFS.map( ( [ key, label ] ) => (
                  <li key={ key }>
                    <label>
                      <input type="checkbox" data-testid={ `bwx-slack-pref-${ key }` } checked={ state.prefs[ key ] ?? true } onChange={ ( event ) => void toggle( key, event.target.checked ) } />
                      { label }
                      { 'morning' === key && <span className="bwx-mono bwx-slack-time"> · { state.morning_at }</span> }
                    </label>
                  </li>
                ) ) }
              </ul>
              <div className="bwx-slack-actions">
                <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-slack-change" onClick={ () => setState( { ...state, connected: false } ) }>
                  Change webhook
                </button>
                <button type="button" className="bwx-button" data-variant="quiet" data-testid="bwx-slack-disconnect" onClick={ () => void disconnect() }>
                  Disconnect
                </button>
              </div>
            </>
          ) }
        </div>
      ) }
    </section>
  );
}
